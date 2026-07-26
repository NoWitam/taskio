# ADR-0020 — Full-page file preview: overwrite vs save-as, and stateless AI image edits

**Date:** 2026-07-19 (created)
**Status:** Accepted
**Updated:** 2026-07-20 — F2 addendum below (async pipeline + inpainting mask; decision 4's
synchronous/no-mask shape is superseded in mechanics, not in outcome)
**Module:** `App\Modules\Disk` (+ `App\Modules\Comments` wiring, the FE Button split-button)
**Relates to:** ADR-0016 (disk), ADR-0018 (fileable container)

---

## Context

The Disk browser previewed items through two right-side drawers and a modal image editor whose
only save was "save a copy". The owner specced a **full-page preview surface** (annotated
screenshot): a top bar (type icon + name, shared actions, prev/next, close), a type-specific
editing toolbar, a large preview/editor stage, and sidebar tabs (Informacje / Historia /
Komentarze) — with REAL save semantics and WORKING AI image actions.

## Decisions

1. **The preview fully replaces the drawers** (no parallel implementations). It rides a
   `?preview=<id>` query on the disk route — deep-linkable, browser-Back closes, prev/next walk
   the grid-ordered level (folders first, then files). Everything the drawers did (facts,
   metadata + labels forms incl. locked/governance labels, history) moved into the sidebar
   panels; the modal editor became the image stage.

2. **"Zapisz" = OVERWRITE, gated to disk-native + editable.** `POST /disk/{file}/content` swaps
   the blob and the content-derived columns while the row keeps its identity (name, placement,
   labels, uploader, created_at). A **resource-owned** file (task attachment, report output) can
   NEVER be overwritten from the disk — its bytes are the owning module's record; the service
   422s and the preview offers only **"Zapisz jako"** (upload the edited content as a NEW disk
   file, name + folder picker). Mime/type are recomputed from the actual bytes; inline-serving
   safety stays a read-time concern (B0 allowlist + nosniff), so replace is exactly as safe as a
   fresh upload.

3. **Content replacement is audited** as a first-class changelog event:
   `ChangelogEvent::CONTENT_REPLACED` with `{ size, previous_size, mime_type }` details, written
   via `handleCustomEvent`. (The enum case must ship together with the first writer —
   `ChangelogManager::flush()` silently drops unknown event values.)

4. **AI image edits are STATELESS round-trips.** `POST /disk/ai/image` takes the CURRENT canvas
   (multipart) + a prompt, calls laravel/ai image generation with the canvas as a reference
   attachment (OpenAI images/edits, default `gpt-image-1.5`), and returns base64+mime. The result
   becomes the editor's new base — an edit like any other; NOTHING persists until the user saves.
   Presets are FE prompt templates (English instructions, localized labels) + a free prompt —
   one endpoint either way. Failures collapse to 502 with a localized message; spend is bounded
   by a dedicated `disk-ai` throttle (10/min) and `AI_IMAGE_TIMEOUT` (120s). Deliberately
   SYNCHRONOUS at R1 volumes; a queued flow is the follow-up if usage grows.

5. **Comments reuse the generic polymorphic module** — `HasComments` on File/Folder plus two
   `resolveCommentable` match arms (`'file'`/`'folder'`, the changelog's singular-morph-alias
   convention). The FE panel is the approvals comments component GENERALIZED into
   `ui/patterns/CommentsPanel.vue` (`commentsUrl` prop) — approvals now consumes the shared one.

6. **The save affordance is a reusable Button primitive feature** („przybornik"/split button):
   `menuItems` + `menu-select` render a chevron segment opening a DropdownMenu — here "Zapisz"
   with "Zapisz jako…" behind the toolbox. Without `menuItems` the Button renders byte-identically
   (regression-pinned; it has ~114 consumers).

## Consequences

- **Positive:** one preview surface owns viewing AND editing; overwrite finally exists without
  endangering other modules' records; the grid, filter and label systems are untouched (the
  fresh Resource merges into the level in place); AI edits compose with manual edits because they
  share the same canvas pipeline.
- **Trade-offs:** AI latency rides a synchronous request (deploy note: php-fpm/proxy timeouts
  must exceed `AI_IMAGE_TIMEOUT`); a crash between blob-write and row-commit leaks an orphan blob
  (the same tolerance forceDelete documents — never data loss); `updated_at` keys the file tiles
  so thumbnails remount after a replace.
- **Rejected:** overwriting resource-owned files (silent cross-module mutation); persisting AI
  results server-side (would bypass the manual save contract); a per-type save endpoint (one
  content endpoint + client-side encoding covers text and images alike).

---

## Addendum (2026-07-20) — F2: async pipeline + inpainting mask

Decision 4 above records the R1 shape (laravel/ai, synchronous, no mask) and explicitly named a
queued flow as "the follow-up if usage grows." That follow-up landed as the Disk image editor's F2
AI phase (F2-1/F2-2/F2-3). The OUTCOME decision 4 established is **unchanged**: nothing lands on
the disk from an AI edit — the result becomes the editor's new canvas base, and only a subsequent
`Zapisz` / `Zapisz jako` save writes a `File` row. What changed is the MECHANISM: a single
synchronous round trip became a dispatch into a `disk_ai_edits` status row, so "stateless" in
decision 4's title now describes the disk/file outcome, not the request itself — the pipeline
holds brief, self-cleaning server-side state (the status row + persisted inputs) between dispatch
and poll.

7. **A custom `images/edits` + mask client (`OpenAiImageEditClient`) replaces laravel/ai for this
   one call.** laravel/ai's image path (`OpenAiGateway::generateImage()` →
   `sendImageEditRequest()`, `vendor/laravel/ai/src/Gateway/OpenAi/OpenAiGateway.php`) attaches
   only `image[]` to `images/edits` — there is no `mask` parameter anywhere in the package
   (verified by inspection). Real inpainting / object removal / "replace this area" needs OpenAI's
   mask semantics (its transparent pixels are the only region the model may repaint); without one
   the whole image is regenerated from the prompt, which is a different, cruder operation. Rather
   than fork or extend the vendor gateway for one call site, `OpenAiImageEditClient` is a small,
   dedicated `Http::attach(...)->post('images/edits', ...)` wrapper — raw bytes in, base64 + mime
   out (see `docs/backend/disk-api.md`).

8. **The edit is QUEUED, not synchronous — a status table + poll + reaper, mirroring
   `ProcessAiApprovalJob` / the bot run pipeline.** A billed provider call running tens of seconds
   must not occupy a php-fpm worker for its duration (decision 4's Consequences already flagged
   this as a known trade-off of shipping synchronous at R1 volumes). `POST /disk/ai/image` now
   only validates, persists the image (+ mask) to storage, writes a `disk_ai_edits` row
   (`queued`), and dispatches `EditDiskImageJob` — returning **202** in milliseconds. The worker
   advances the row through `processing → done | failed`; the client polls
   `GET /disk/ai/image/{id}` until it settles. `disk:reap-stale-ai-edits` (scheduled
   `everyFiveMinutes()`) fails edits a dead worker abandoned — a SIGKILL/OOM never reaches the
   job's `failed()` hook, so nothing else would recover them — and prunes terminal rows after a
   short retention window, since each holds a multi-MB base64 result. This is the same
   claim-or-reap shape as the bot run manager and `workflows:reap-stale-runs`, not a new pattern.

9. **A per-workspace daily budget, enforced AND counted at dispatch.** The existing `disk-ai`
   throttle (10/min) bounds burst rate, not total daily spend on a billed-per-call provider.
   `config('ai.disk_image_max_per_day')` (default 50; 0 disables it) adds a soft daily cap,
   refusing further dispatches with **429** once reached. It is counted the moment an edit is
   QUEUED, not when it later succeeds — counting on success would let a burst of concurrent
   dispatches all pass the check before any of them completed, flooding the queue past the
   intended cap.

10. **Mask convention: transparent marks the region to repaint.** The brush exports an OPAQUE PNG
    with painted pixels erased to alpha 0, matching OpenAI's `images/edits` mask contract exactly
    (no server-side inversion). The client always exports the mask at the exact pixel size of the
    image it accompanies (both are read from the same canvas at submit time); the server does not
    separately re-validate that the two files' dimensions match.

See [`docs/backend/disk-api.md`](../backend/disk-api.md) for the full request/response contract
and [`docs/frontend/disk-image-editor.md`](../frontend/disk-image-editor.md) for the mask-brush
and async-apply UX.

---

## Addendum (2026-07-21) — AI text edit, image-edit tuning, and a realtime completion push

Three small extensions to the F2 pipeline above; none change its shape.

11. **A second, SYNCHRONOUS AI endpoint for text files.** `POST /disk/ai/text` mirrors decision
    4's original (pre-queue) shape rather than decision 8's async one: a gpt-4o text edit returns
    in a few seconds, so queuing would only add latency. Content + prompt in, edited text back,
    inline, on the same `disk-ai` throttle bucket as the image edit. Nothing is persisted
    server-side — exactly like the image edit, the result only lands on the disk through the
    normal save endpoints.
12. **`OpenAiImageEditClient` now also tunes `quality` and `background`**, not just
    `input_fidelity`. `background=opaque` stops an object-removal edit from cutting a transparent
    hole where the mask was (the provider's own `auto` default can leave the region transparent,
    which then shows through as a washed patch once composited); `quality=high` reconstructs real
    detail in the repainted region instead of the flatter patch the provider's own `medium`
    default produces. Both are `config/ai.php` keys, overridable per-deploy, and blankable (`''`)
    to omit the parameter entirely.
13. **The async image edit's terminal transition now also broadcasts.** `DiskAiEditUpdated`
    pushes a lightweight `{ id, status, error? }` (never the multi-MB image) on a private,
    per-workspace Reverb channel (`disk-ai.workspace.{workspaceId}`) when an edit reaches
    `done`/`failed`, so an open editor can react instantly instead of waiting out its next poll
    tick. This is additive to decision 8's poll contract, not a replacement: `GET /disk/ai/image/{id}`
    remains the source of truth and the only way to fetch the result, and the client falls back to
    it whenever Reverb is unconfigured or the socket drops. Channel auth runs on
    `/broadcasting/auth` (`auth:sanctum`, no tenant header — the channel name itself carries the
    workspace id) and is granted purely by workspace membership (`routes/channels.php`).

See [`docs/backend/disk-api.md`](../backend/disk-api.md) for the endpoint contracts.
