# Disk image editor

The image editor is the `image` stage of the Disk preview modal
(`resources/js/next/pages/disk/preview/DiskPreview.vue` → `stages/ImageStage.vue`). It replaced the
old crammed single-row toolbar with a three-zone layout over the canvas, backed by a small editing
engine with real undo/redo (Faza 1). AI editing — background/object removal, replace, style
presets, and a brushed inpainting mask — landed after as Faza 2 (F2); it runs through an async,
queued pipeline rather than a plain request/response. See [AI editing](#ai-editing) below, and
[ADR-0020](../decisions/ADR-0020-file-preview-editing.md) for why both are shaped the way they are.

## Layout — three zones

1. **Action bar** (`preview/editor/EditorActionBar.vue`) — global, mode-independent: undo / redo /
   reset, a view-zoom group (−, the %-readout doubles as "fit", +), and the **Save split button**
   on the right. Save mirrors the preview rule: disk-native + `can_be_updated` files get "Zapisz"
   (overwrite) with a "Zapisz jako…" menu; everything else gets a plain "Zapisz jako…".
2. **Mode selector** — a `Tabs` `variant="pills"` row (`Przekształć / Korekcja / Filtry / AI`) that
   shows only the active group's controls (progressive disclosure). `SegmentedControl` is used for
   the single-choice pickers inside a mode (crop aspect ratio), never for the mode row itself.
3. **Canvas stage** — the `<canvas>` plus the crop marquee overlay; wrapped in a `zoom`-scaled box
   inside an `overflow-auto` container so zooming pans via native scroll.

## Engine — `preview/useImageEditor.ts`

The committed image is a closure-local `base` canvas. Non-destructive edits (rotate, flips, filter,
brightness/contrast/saturation) are re-renders of `base` under the current `EditState`; destructive
edits (crop, resize, AI) replace `base` with a new one.

- **Pixel ops** live in `pages/disk/imageOps.ts` as pure functions on the raw RGBA byte array
  (never `ctx.filter` — Safari), so they are unit-tested without a canvas
  (`__tests__/imageOps.spec.ts`). `redraw()` runs a single `getImageData` pass:
  brightness → contrast → saturation → named filter.
- **History** is a bounded stack of `{ baseId, state }` snapshots (`HISTORY_CAP = 40`). Cheap edits
  share the SAME base and only record the tiny `state`; destructive edits register a new base. So
  the stack caps state entries, not 50 MB pixel buffers; unreferenced bases are GC'd on trim.
  `undo` / `redo` / `reset` walk it; `canUndo` / `canRedo` and `dirty` (distance to the last saved
  step, OR an uncommitted live slider edit) are derived. `markSaved()` rebaselines `dirty` after an
  overwrite. Past the cap the oldest steps are trimmed (including the loaded baseline once exceeded),
  so undo/reset rewind only within the window and a trimmed-away baseline keeps the canvas `dirty`.
- **Working cap** `MAX_EDGE = 3584` keeps the buffer under iOS Safari's ~16.78 Mpx canvas ceiling
  (4096² sits exactly on it, where `getImageData`/`toBlob` start failing).

## Operations (non-AI)

| Mode | Operations |
| --- | --- |
| Przekształć | crop (free or locked to 1:1 / 4:3 / 16:9 / 9:16), rotate left/right, flip H/V, resize (downscale-only presets 2048/1024/512 px on the longest edge) |
| Korekcja | brightness / contrast / saturation sliders (−100…100). Dragging previews live via `setBrightness/…` (no history); a debounced `commitAdjust` records ONE undo step on settle |
| Filtry | none / grayscale / sepia / negative / warm / cool / high contrast |
| AI | provided by the shell via the `#ai` slot (`ImageAiPanel`) — full-image + masked presets, async (queued/polled); see [AI editing](#ai-editing) below |

Zoom is view-only — a CSS `zoom` on the canvas wrapper (Chromium/Safari; Firefox ≥ 126), not part of
the edit history, reset to 100% on file change.

## AI editing

`ImageAiPanel.vue` is the shell's `#ai` slot content; it drives `useImageEditor`'s `applyAi` /
`cancelAi` and the mask-brush surface. The backend contract (dispatch/poll shape, budget, config)
is documented in [`docs/backend/disk-api.md`](../backend/disk-api.md) — this section covers the
editor-side UX.

### Two operation families (`aiPresets.ts`)

- **Full-image** (`FULL_IMAGE_PRESETS`) — remove background, enhance, sharpen, line-art,
  watercolor, product-on-white. One click, no mask: runs `applyAi(preset.prompt)` immediately
  against the whole canvas.
- **Masked** (`MASKED_PRESETS`) — remove-object (fixed instruction) and replace (takes the
  free-text prompt as its instruction, `usesPrompt: true`). Selecting one **arms** the mask brush;
  Apply stays disabled until at least one stroke is painted, and — for replace — until the prompt
  is non-empty too.
- A **free-form prompt** field runs the whole-image path with the user's own text; it is hidden
  while a masked preset is armed (its own prompt field takes over instead).

All presets are fixed **English** instruction templates with **localized** chip labels (image
models follow English instructions most reliably); the server only ever sees whichever prompt text
was actually sent, plus the image and an optional mask — presets are a client-side convenience,
not a distinct server capability.

### Mask brush

Arming a masked preset opens a paint surface (`maskOverlayRef`, a canvas absolutely positioned over
the main one) sized to the CURRENT canvas's pixel dimensions, and is mutually exclusive with crop
mode (arming one cancels the other). `exportMask()` turns the painted strokes into the mask the
server expects: a fully OPAQUE PNG with the painted pixels erased to **alpha 0** — transparent
means "repaint this" — at the exact pixel size of the image being submitted in the same request.

The mask is invalidated by **any redraw**: rotate/flip, a filter switch, a brightness/contrast/
saturation drag, undo/redo/reset, crop, resize, or a just-committed AI result all clear it
(`syncMaskAfterRedraw` runs from the engine's single `redraw()` entry point), so a stroke can never
be sent misaligned to pixels it wasn't painted on. Leaving the AI mode tab, or entering crop mode,
also turns mask mode off and discards any strokes.

### Async apply (realtime push, polling fallback)

`applyAi(prompt, mask?)` posts the current canvas (always encoded as **PNG** — gpt-image returns PNG
and a mislabeled JPEG/WebP part is rejected) + prompt + optional exported mask to
`POST /disk/ai/image`, which queues the edit and returns its id. Completion is then delivered **over a
Reverb websocket**, not by busy-polling: the editor subscribes to the per-workspace private channel
`disk-ai.workspace.{workspaceId}` and, on the `done` / `failed` push for THIS edit, fetches the result
with a single `GET /disk/ai/image/{id}` (the push carries only status — the multi-MB image never rides
the socket). It **falls back to HTTP polling** (~2s, 180s ceiling) only when Reverb is unconfigured
(`VITE_REVERB_APP_KEY` unset), the subscription/auth errors, or a connected socket goes silent past a
long safety window — so a normal edit is never pre-empted by a blind timer. `/broadcasting/auth` runs
on `auth:sanctum`; the SPA forwards its **Bearer token** there (see [disk-api.md](../backend/disk-api.md)).

The decoded image is committed as a new history base — an edit like any other. For a **masked** edit
the result is composited client-side: the original canvas + painted mask are snapshotted at apply-time
(immune to any change during the wait), and the AI result is pasted back ONLY inside the painted region
(feathered edge), so the unmasked pixels stay byte-for-byte the original regardless of what the model
regenerates globally. Only one edit may be in flight at a time (`aiBusy`); every trigger (`runFull` /
`runMasked` / `runPrompt`) is gated on it, and each shows its own button spinner via a local `running` key.

While an edit is in flight, the panel shows a status row (spinner + a "this can take a moment"
message) with a **Cancel** button. `cancelAi()` only stops the CLIENT from waiting — it flips an
abort flag the poll loop checks between ticks; the already-queued job keeps running server-side
regardless (its result, if any, is simply never collected by that session). A cancelled wait
resolves quietly with no error toast; a genuine failure or timeout does show one.

## Drafts (autosave & restore)

Edits are **autosaved to a per-user server draft** so a refresh/crash never loses work — the MAIN
file is still overwritten only on an explicit Save. This is shared by the image and text stages via
`preview/useDraftAutosave.ts`; the backend contract (endpoints, storage, 24h TTL/reaper) is in
[`disk-api.md`](../backend/disk-api.md).

- **What is saved.** The FULL edit history, not just the current pixels: `useImageEditor.serializeDraft()`
  produces a manifest (`history` entries + `historyIndex` / `savedIndex` + the referenced `baseIds`)
  plus one PNG blob per DISTINCT committed base. Most edits (rotate/filter/slider) add only a tiny state
  entry, so a debounced (~2.5s) autosave usually POSTs just the small manifest; a heavy base blob rides
  along only when a crop/AI/resize commits a new base, and each base is uploaded **once** (deduped by id;
  the server GCs bases the manifest no longer references). Text drafts persist `{ kind:'text', content }`.
- **Restore.** On reopen, `useDraftAutosave` probes for a draft and, if one exists, shows a
  `DraftRestoreBanner` (Restore / Discard) and **pauses autosave until the user decides** — so the current
  file state never clobbers the pending draft. Restore rebuilds the engine via
  `useImageEditor.hydrateDraft()` (decode every base, then restore history + cursors); if the file changed
  since the draft (`base_version` mismatch) the banner escalates to a stale warning.
- **Lifecycle.** Autosave runs only while `dirty`. On an explicit overwrite the stage's `markSaved()`
  also `clear()`s the draft (the file now equals the edit). Discard deletes it; the reaper prunes any
  abandoned draft after 24h. Because it's server-side per-user, a draft follows the user across devices.

## Shell contract (do not break)

`ImageStage` keeps a fixed contract with `DiskPreview`: it emits `update:dirty`, `save`, and
`save-as` (with the encoded canvas), and exposes `{ markSaved, reload, editor }`. The AI actions
arrive through the `#ai="{ editor, disabled }"` slot. `DiskPreview` owns the save calls
(`replaceFileContent` / upload) and the unsaved-changes guard. The engine refactor preserved
`applyAi` / `aiBusy` / `toBlob` / `load` verbatim — `applyAi` itself later grew a `mask` parameter
and an internal poll loop under F2 (see [AI editing](#ai-editing) above), but its place in this
contract (a method on `editor`, gated by `aiBusy`) did not change.

## Files

- `preview/stages/ImageStage.vue` — the stage shell (zones, mode tabs, canvas, zoom, crop-ratio).
- `preview/editor/EditorActionBar.vue` — undo/redo/reset + zoom + save split.
- `preview/editor/EditorAdjustPanel.vue` — the three adjustment sliders (debounced commit).
- `preview/useImageEditor.ts` — the engine (base, state, history, crop, resize, mask brush, async
  AI apply/cancel).
- `preview/ImageAiPanel.vue` — the AI actions panel (full-image + masked presets, free prompt,
  brush controls, in-flight/cancel).
- `preview/aiPresets.ts` — the preset instruction templates (full-image + masked families).
- `pages/disk/imageOps.ts` — pure pixel + geometry ops (filters, adjustments, crop/ratio math).
- `preview/useDraftAutosave.ts` — per-user server autosave: restore probe/pause, debounced dedup save,
  restore/discard/clear (shared by the image + text stages).
- `preview/DraftRestoreBanner.vue` — the Restore/Discard banner shown when a pending draft exists.
