# Backend API: Generation Sessions

Module: `app/modules/Generator/` — R2 sub-stage 2 ("Sesje"), sub-phases 2a–2d, all shipped. A generation
**Session** is ONE execution of a Template **RECIPE** (see `docs/backend/generator-api.md`) into concrete
content: it SNAPSHOTS the recipe at creation, the user fills typed **slot values**, then an async run
renders every declared part — text through the real, budgeted `@[ai-text]` generator, images through a
server-side pixel/AI-edit chain — and the result is iteratively **refinable** (regenerate / instructed
refine / undo) before the user saves an image to Disk or lets the session age out.

This document covers the SESSION endpoints only. For the Template (definition) endpoints — CRUD, the
draft-friendly catalog, the per-part preview — see `docs/backend/generator-api.md`. For the Disk file
model / the Disk create path a session's "Save to Disk" writes through, see `docs/backend/disk-api.md`. For
the Bot ↔ session DELEGATION edge (R2 sub-stage 3, "Boty w generatorze") — a human handing an editable
session to a bot as its content author — see "Bot-author delegation overlay" below and
`docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`; the delegate/undo endpoints themselves live
in the Bot module (`docs/backend/bots-api.md`). For the $-first AI cost meter (R2 sub-stage 4) — the
per-workspace cap, the polymorphic actor attribution, and the pre-run 429 gate this document's "Cost meter
integration" and "Pre-run 429 budget gate" sections summarize — see
`docs/backend/workspace-ai-usage-api.md` and `docs/decisions/ADR-0037-ai-cost-limits.md`. For the
CONTENT-QUALITY rework on top of all of the above — the adaptive shot-count / degrading story contract (B1)
and the once-per-run CREATIVE DIRECTION every part of a run is now made to (B2) — see "Narrative contract
upgrades" and "Creative direction layer" below and
`docs/decisions/ADR-0038-creative-direction-layer.md`. For the WORKFLOW ↔ session AUTOMATION edge (R2
sub-stage 5) — a `generate_content` workflow step running a template end-to-end with no human in the loop —
see "Automation seam (R2 sub-stage 5)" below and
`docs/decisions/ADR-0039-workflow-suspend-resume-and-generate-content.md`; the step itself, the config/output
contract, and the generic suspend/resume engine it runs on live in `docs/backend/workflows-api.md`.

Auth: all endpoints require `auth:sanctum` + `X-Workspace-Id` (resolved by `ResolveWorkspace`
middleware). Tenant scope: `TenantAware` on `GenerationSession` — every query is scoped to the active
workspace (shared mode via `WorkspaceScope`, own mode via the tenant connection), so a foreign-workspace
`{session}` **404s at route-model binding**, never 403. Authorization beyond tenancy is creator-only for
every mutation and the generate/refine/undo/archive actions (`GenerationSessionPolicy` →
`ChecksRecordOwnership::ownsOrManagesSystemRecord` — read is any workspace member).

---

## Concepts

```
GenerationSession
  template_id       (provenance only — nullable, NO FK; the session outlives a deleted/edited template)
  name
  content_type       (snapshotted from the template)
  recipe_snapshot     ({content_type, slots, content} — captured at creation, NEVER re-read from the template)
  slot_values             (the user's filled inputs, {<slot name>: <value>})
  results                    (the per-part outcome map — see "The `results` map" below)
  status                        (draft → generating → ready | failed — see "Status machine")
  history                          (a bounded per-part undo stack — internal; not emitted, see part_history)
  last_op_status / last_op_error       (the outcome of the MOST RECENT per-part regenerate/refine)
  archived_at                              (set = exempt from the lifecycle reaper)
```

A session is created FROM a template (`POST /generator/sessions {template_id}`), which snapshots that
template's `{content_type, slots, content}` into `recipe_snapshot` — a later edit or delete of the source
template never changes an existing session (`tests/Feature/GenerationSessionGenerateTest::
test_run_reads_the_snapshot_not_the_live_template`). The snapshot itself is never emitted on the wire (see
"The `GenerationSessionResource` wire shape" below) — the FE re-reads the part shapes from
`GET /generator/content-types` and the slot descriptors from the source template.

---

## Cross-part context — the `parts.<key>` root (video_script rework Phase A)

A content-type part's authored body may reference an EARLIER part's GENERATED output as
`parts.<key>` — a general engine primitive (not specific to `video_script`), added to
`Variables\Services\VariableResolver::ROOTS` alongside `trigger`/`steps`/`globals`/`slots`. It resolves
EXACTLY like `globals.<key>` — a plain whitelisted dotted lookup over a stored, post-render STRING, riding
the same NUL-mask injection guard — because the executor populates it as a plain `{<partKey>: <rendered
text>}` map. See `docs/decisions/ADR-0035-video-script-cross-part-storyboard.md` for the full design record.

**Text-only, earlier-only, acyclic by construction.** Only a part whose `ok` result is a rendered STRING
(`text_body` / `script` / `shot_list`) contributes to the map — an `image_plan`/`scene_plan`/`storyboard`
result has no natural flattened text and contributes nothing
(`GenerationSessionExecutor::partContribution()`). A part may reference ONLY a part declared BEFORE it in
the content type's ordered parts (`ContentTypeRegistry::partKeysBefore()`), enforced independently at three
layers: the editor catalog offers only `parts.<earlierKey>` variables for the part being authored
(`POST /generator/catalog`'s optional `content_type`/`part_key` scoping — see `docs/backend/
generator-api.md`); the TEMPLATE write validator rejects a forward/self/unknown reference as `422`; and the
EXECUTOR independently re-derives the earlier-only scope from the snapshot at both a whole run (which
accumulates the `parts` map top-to-bottom starting empty) and an isolated per-part op (which seeds it from
the session's stored results, scoped to strictly-earlier keys) — so even a hypothetical validator bug could
never make a forward/self reference resolve against a later part's output; it resolves EMPTY instead, same
as in a full run.

**One scanner is the shared authority for what counts as a reference.** A flat `@[variable]` regex is blind
to a reference nested inside an `@[ai-text]` prompt or an if-block condition/body, and to the transitional
flat `{{…}}` token form. Both the write-time gate AND the runtime STALENESS scan (below) instead walk every
string leaf of the authored content through `VariableResolver::collectReferenceIds()` — a scanner that
mirrors the resolver's own parsing exactly, so neither can under- or over-detect a `parts.*` reference
relative to what actually resolves at runtime.

**Staleness — a passive FE hint, never an auto-cascaded re-run.** Refining or regenerating an UPSTREAM part
marks every already-produced DOWNSTREAM dependent `stale: true` in its `results` entry
(`GenerationSessionRefiner::markDownstreamStale()`) — a hint the chat renders as a "may be out of date"
badge. There is NO auto-regenerate of the dependent (cost control: an AI spend is never triggered silently).
A full `generate` clears every part's results (and so every stale flag); regenerating the stale part itself
replaces its result with a fresh, non-stale one. A `video_script` `storyboard` is also marked stale when its
sibling `shot_list` changes, even though that dependency is a direct structural read (not a `parts.*`
reference) — see "shot_list / storyboard" below.

**The template PREVIEW does not populate `parts`.** `POST /generator/preview` (`docs/backend/
generator-api.md`) renders each part in isolation against ONE shared context built once — it does not
accumulate a `parts` map across parts the way a real run does. A `parts.<key>` reference in a preview
therefore always resolves EMPTY, exactly like any other unpopulated whitelisted root — a real session
`generate` is the only place cross-part context actually resolves.

---

## Status machine

`Enums\GenerationSessionStatus`: `draft` → `generating` → `ready` | `failed`.

| Status | Meaning | Editable (`can_edit`) |
|---|---|---|
| `draft` | Created, slot values being filled; no run yet. | yes |
| `generating` | An async run (whole-session OR a per-part regenerate/refine) is claimed and in flight. | no |
| `ready` | The most recent run finished; inspect `results` per part — an individual part may still be `failed` while the session itself is `ready` (fail-soft per part). | yes |
| `failed` | The WHOLE run threw (an infra fault — not a per-part failure, which stays `ready`). | no — re-generate to retry |

**The claim is atomic and race-free.** `GenerationSessionRunManager::claimAndDispatch()` runs a guarded
`UPDATE … WHERE status IN (draft,ready,failed)` that only a `draft`/`ready`/`failed` session can win; the
affected-row count tells the caller whether IT won the claim. A concurrent second call — a double-click, a
second tab, a whole-session generate racing a per-part refine — sees 0 affected rows and the endpoint
returns **409**, never a double (billed) run. Exactly ONE async op runs per session at a time, whether it is
the whole-session generate or a single-part regenerate/refine.

A **whole-session** claim (`generate`) also clears `results`/`history`/`last_op_*` and the session's prior
produced-image blobs, so a re-run starts clean and every part restarts at version 1. A **part-op** claim
(`regenerate`/`refine`) only flips the status — it never touches other parts' results/history/blobs.

`RunGenerationSessionJob` (queued, `tries=1`, `timeout=300`, `WithoutOverlapping($sessionId)` with a 30s
release / 600s expiry) runs the claimed unit of work off the request. Its `failed()` hook marks the
session `failed` (terminal-safe — never overwrites an already-finished session). A worker SIGKILL/OOM that
never reaches `failed()` leaves a session stranded in `generating`; the lifecycle reaper's stale window
(below) is what recovers it — nothing else does.

---

## Endpoints

### GET /api/generator/sessions

List the workspace's sessions. Cursor-paginated, 20/page, ordered by `created_at` DESC (unlike the
Templates list, which orders by `name` ASC). Authorization: `viewAny` (any workspace member).

| Query | Notes |
|---|---|
| `search` | case-insensitive match on `name` |
| `status` | one of `draft\|generating\|ready\|failed` — an unrecognized value is ignored (no filter applied), not a 422 |
| `cursor` | pagination cursor |

**Response** `200 OK` — `{ data: [GenerationSessionResource], meta: { next_cursor } }`, each row eager-loading `creator`.

---

### POST /api/generator/sessions

Create a session from a template. Authorization: `create` (any authenticated user).

| Field | Required | Notes |
|---|---|---|
| `template_id` | yes | string. Resolved + view-authorized in `StoreGenerationSessionRequest::withValidator()` (workspace-scoped — a foreign/absent template is a `422` on `template_id`, never a cross-tenant read); the resolved `Template` is stashed for the DTO. |
| `name` | no | max 255. Defaults to the template's own `name` when omitted/blank. |
| `slot_values` | no | a lenient JSON map — NOT validated against the slot descriptors at creation (a draft may be filled incrementally); shape is only exercised at `generate` time by the resolver itself. |

**Response** `201 Created` — `GenerationSessionResource`, `status: 'draft'`, `results: null`. Verified
against `tests/Feature/GenerationSessionCrudTest::test_can_create_a_session_from_a_template()`:

```json
POST /api/generator/sessions
{ "template_id": "…", "name": "My launch draft", "slot_values": { "topic": "Widgets" } }

201:
{ "data": {
  "id": "…", "name": "My launch draft", "template_id": "…", "content_type": "post",
  "status": "draft", "slot_values": { "topic": "Widgets" }, "results": null,
  "part_history": {}, "last_op_status": null, "last_op_error": null,
  "creator": { "type": "user", "id": "…", "name": "…" },
  "is_owner": true, "can_generate": true, "can_edit": true, "can_be_deleted": true,
  "is_archived": false, "can_archive": true,
  "archived_at": null, "created_at": "…", "updated_at": "…"
} }
```

**Errors**: `401` unauthenticated; `422` `template_id` missing/not-found/not-viewable, `name` too long.

---

### GET /api/generator/sessions/{session}

Fetch one session. Authorization: `view` (any workspace member). **Errors**: `404` not found (including a
foreign-workspace/soft-deleted id).

---

### PATCH /api/generator/sessions/{session}

Edit the two user-mutable inputs — `name` and/or `slot_values`. The recipe snapshot is immutable;
results/status are engine-owned. Authorization: `update` (creator only) **plus** a state guard —
`session.status.isEditable()` (`draft` or `ready`) — enforced in `UpdateGenerationSessionRequest::
withValidator()`; a `generating`/`failed` session rejects the edit with a `422` on `status`
(`generator.sessions.not_editable`). A field the caller does not send stays unchanged.

**Response** `200 OK` — `GenerationSessionResource`. **Errors**: `403` not creator, `404` not found, `422`
not editable / bad shape.

---

### DELETE /api/generator/sessions/{session}

Soft-delete (trash) a session — `SoftDeletes`, not a hard delete (unlike a Template). The lifecycle reaper
purges it (force-delete + blob GC) after the purge window (below). Authorization: `delete` (creator only).

**Response** `200 OK` — `{ "message": "…" }`.

---

### POST /api/generator/sessions/{session}/generate

Kick off a **whole-session** run: atomically claim the session and queue `RunGenerationSessionJob` in
`full` mode. Authorization: `update` (creator only).

**Response** `202 Accepted` — `GenerationSessionResource` with `status: 'generating'`. **Errors**: `403` not
creator, `404` not found, `409` a run is already in flight (`generator.sessions.already_generating`), `429`
the workspace is already over its AI $ cap (`code: 'ai_budget_exceeded'` — see "Pre-run 429 budget gate"
below).

The FE does NOT poll: it WAITS for the `GenerationSessionUpdated` websocket push. On a terminal transition
(`ready`/`failed`) the manager broadcasts on the private per-workspace channel `generator.workspace.{workspaceId}`
(event `.generation-session.updated`, payload `{id, status, last_op_status?}` — never the produced content;
authorized by workspace membership in `routes/channels.php`). The FE's `useSessionSettle` composable subscribes,
filters by session id, and on the push re-fetches `GET /generator/sessions/{id}` for the authoritative results.
A single post-subscribe fetch covers the event-before-listener race and a safety timeout covers a missed push —
neither is a poll loop. See `docs/decisions/ADR-0034-generation-sessions.md` (D10).

---

### POST /api/generator/sessions/{session}/parts/{partKey}/regenerate

Re-render ONE part fresh from the SAME snapshot recipe + slot values — a new variation, not a revision.
Claims the whole session (one op at a time) but the claim does NOT clear other parts' results/history/blobs.
Authorization: `update` (creator only); the part key is checked against the snapshot's content-type parts
(`GenerationSessionRefiner::assertPart()`). `partKey` accepts a `storyboard.<i>` dotted sub-key (re-images
just that one shot) exactly like the pre-existing `scene_plan.<i>` — see "storyboard — one AI image per
shot" above.

**Response** `202 Accepted` — `GenerationSessionResource`, `status: 'generating'`. **Errors**: `404` unknown
`partKey` for this session's content type (including an out-of-range `storyboard.<i>` index); `409` already
generating; `429` the workspace is already over its AI $ cap (see "Pre-run 429 budget gate" below).

---

### POST /api/generator/sessions/{session}/parts/{partKey}/refine

Revise ONE part's **current** output guided by a free-text `instruction` — a text part gets an AI text
revision, an image part gets an AI edit of the current image, and a `shot_list` gets a directed structured
REVISION (the current list + the instruction fed back to the same structured renderer — see "shot_list —
the structured JSON contract" above). This one endpoint backs BOTH the per-part "Dopracuj" affordance and
the chat composer (the composer just targets whichever part it is pointed at). Authorization: `update`
(creator only); the part must additionally be **refinable**
(`GenerationSessionRefiner::assertRefinablePart()` — `text_body`/`script`/`image_plan`/`shot_list`, plus a
`storyboard.<i>` shot sub-key (an AI edit of that shot's current image); a BARE `scene_plan`/`storyboard`
composite has no single current output to revise).

| Field | Required | Notes |
|---|---|---|
| `instruction` | yes | string, max 2000, trimmed; a blank/whitespace-only instruction is `422`. NEVER logged (user data). |

**Response** `202 Accepted` — `GenerationSessionResource`, `status: 'generating'`. **Errors**: `404` unknown
`partKey`; `422` blank instruction OR a non-refinable part kind (`generator.sessions.refine_not_supported`);
`409` already generating; `429` the workspace is already over its AI $ cap (see "Pre-run 429 budget gate"
below).

---

### POST /api/generator/sessions/{session}/parts/{partKey}/undo

Revert ONE part to its previous version — **synchronous, no AI call, no async claim**. Pops the part's
bounded history stack, makes the popped entry current, and deletes the just-undone version's produced-image
blob(s) (there is no redo). Authorization: `update` (creator only); the whole operation runs inside a DB
transaction over a `lockForUpdate()`-locked row, re-checking `status === ready` AND a non-empty history
UNDER the lock, so a racing double-undo or an undo racing a completing refine cannot double-apply.

**Response** `200 OK` — `GenerationSessionResource` (no `202`/polling — the change is immediate).
**Errors**: `404` unknown `partKey`; `409` the session is `generating`, OR the part has no prior version
(`generator.sessions.nothing_to_undo`).

---

### GET /api/generator/sessions/{session}/parts/{partKey}/image

Stream a part's **current** produced image inline (always a PNG). Authorization: `view` (any workspace
member — read-gated, not creator-only). The current version is resolved from `results[partKey].version`
(or, for a scene image, `results.scene_plan.scenes[i].image.version`) — an old/undone version is not
publicly addressable. Response headers mirror the Disk serve endpoint: exact `Content-Type: image/png`,
`X-Content-Type-Options: nosniff`, `Content-Disposition: inline`.

**Errors**: `404` — no current produced image for this part (never run / failed / non-image part), or the
blob is missing from storage.

```
GET /api/generator/sessions/{id}/parts/image/image        → the top-level "image" part's current image
GET /api/generator/sessions/{id}/parts/scene_plan.1/image  → the 2nd scene's produced image (0-indexed)
GET /api/generator/sessions/{id}/parts/storyboard.0/image  → the 1st storyboard shot's produced image (0-indexed)
```

---

### POST /api/generator/sessions/{session}/parts/{partKey}/save-to-disk

Promote a part's current produced image onto the user's Disk ("Zapisz na Dysk") — the deliberate
Generator → Disk write edge (see "The image chain" below). Authorization is **double**:
`update` on the session (creator) AND `create` on `Disk\Models\File` (`SaveGeneratedImageRequest`).

| Field | Required | Notes |
|---|---|---|
| `name` | no | max 255. Defaults to a localized `<default name>.png`. |
| `folder_id` | no | uuid. Resolved through `FileService::storeDiskContent()`'s own tenant-scoped lookup — a foreign/absent folder id 404s there; `null`/omitted places the file at the Disk root. |

The bytes are re-read from `GeneratedImageStore` and written through `Disk\Services\FileService::
storeDiskContent()` — the same Disk-owned create path any other Disk write uses (no hand-rolled storage);
see `docs/backend/disk-api.md` for that model. The new `File`'s creator is the acting user.

**Response** `201 Created` — `FileResource` (see `docs/backend/disk-api.md`). **Errors**: `403` not session
creator or not authorized to create a Disk file; `404` no current produced image for this part, or an
unknown `folder_id`.

---

### POST /api/bots/{bot}/sessions/{session}/delegate

**Lives in the Bot module** (`app/modules/Bot/Http/Controllers/BotSessionDelegationController.php`,
`routes/../Bot/routes/api.php` — declared under `bots/…`, not `generator/…`) — the one new
`Bot → Generator + Variables` cross-module edge (see `docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`).
Documented here because it mutates a `GenerationSession`; see `docs/backend/bots-api.md` for the Bot-module
side. Both `{bot}` and `{session}` are workspace-scoped route bindings (a foreign id 404s at bind).

DELEGATE an editable session to a bot: compose the bot's opaque VOICE, run AT MOST ONE metered autonomous
slot-fill call, and stamp the whole delegation overlay. Authorization: the session's `update` ability
(creator/owner-only — delegating changes a session's author + inputs, an owner action, not a bot-owner one).

| Field | Required | Notes |
|---|---|---|
| `auto_generate` | no | boolean. DEFAULT `false` ("gate-przed-wydatkiem" — the session is left `ready`-to-review; a run is claimed + dispatched ONLY on this explicit opt-in, through the SAME `GenerationSessionRunManager` a manual generate uses). |
| `fill_mode` | no | `'gaps' \| 'fresh'` — the human's CLICK-TIME choice of how the bot treats inputs they already typed. DEFAULT `gaps` (the non-destructive reading, so a caller that omits the field can never overwrite a human value). An unknown value is a `422`. |

**`fill_mode` semantics** (`App\Modules\Bot\Enums\SlotFillMode`):

- **`gaps`** — only the in-scope slots that are currently EMPTY (`null` / `''` / `[]`) are offered to the
  model; the already-filled ones travel as a read-only AUTHOR CONTEXT block so the proposal stays coherent.
  A value the human typed is byte-preserved: it is not offered, and a proposal for it coming back anyway is
  REFUSED server-side (`skipped` reason `already_filled`) — the prompt is a request, the server is the
  authority. **When no in-scope slot is empty there is NO provider call and NOTHING is billed**
  (`nothing_to_fill: true`); the delegation overlay is still stamped (the bot becomes the author).
- **`fresh`** — every in-scope slot is offered, the current values are labelled the author's PREVIOUS take and
  the model is told its proposal must differ from them ("take it over and do it your way"). Destructive by
  design and safe because `DELETE …/delegate` restores `slot_values_before` in full.

Every fill request also carries a one-off VARIATION TOKEN line so two delegations of the same session are not
the same request twice (laravel/ai exposes no per-call sampling knob on the shared `generateWith` seam —
`#[Temperature]` is a compile-time class attribute — so the message is the only per-call knob). It is
meaningless data, never logged, and the agent is instructed to ignore it.

**Response** `200 OK` (or `202 Accepted` when `auto_generate` claimed a run — the body reflects `status:
'generating'`) — `GenerationSessionResource` **plus** `fill_report` (the additional key):

```json
POST /api/bots/{botId}/sessions/{sessionId}/delegate
{ "auto_generate": false, "fill_mode": "gaps" }

200:
{ "data": { "…": "…", "is_delegated": true, "bot_author": { "id": "…", "name": "Scribe", "icon": null },
            "can_delegate": false, "can_undo_delegation": true,
            "unfilled_required_slots": ["attachment"] },
  "fill_report": {
    "filled": ["topic", "tone"],
    "skipped": [{ "name": "unknown_field", "reason": "unknown_slot" }],
    "unfilled_required": ["attachment"],
    "mode": "gaps",
    "nothing_to_fill": false
  } }
```

`fill_report.mode` echoes the mode that ACTUALLY ran; `fill_report.nothing_to_fill` is `true` only when the
bot had nothing to do (and therefore spent nothing). `skipped` reasons: `unknown_slot`, `out_of_scope`,
`invalid`, and — new with `fill_mode` — `already_filled` (a `gaps`-mode proposal for a slot the human had
already filled).

**Errors**: `403` not session owner; `404` cross-workspace/unknown `{bot}`/`{session}`; `409` session is
`generating`; `422` session is otherwise not editable (`failed`) or `fill_mode` is not one of
`gaps`/`fresh`; `429` — ONLY when `auto_generate: true`
AND the workspace is already over its AI $ cap (see "Pre-run 429 budget gate" below; the plain delegate
without `auto_generate` never 429s — its own slot-fill call is a separate, ordinary metered spend that
fails closed, not gated by this 429).

---

### DELETE /api/bots/{bot}/sessions/{session}/delegate

**Lives in the Bot module** (same controller as above). UNDO the delegation: clear the WHOLE overlay
(→ the human's own voice) and **restore the pre-delegation `slot_values`** from the overlay's
`slot_values_before` snapshot — a `delegate → undo` round trip fully reverts the bot's autonomous fill
(and anything it overwrote) with zero data loss. Authorization: the session's `update` ability (owner-only).

**Response** `200 OK` — `GenerationSessionResource`, `is_delegated: false`, `bot_author: null`. **Errors**:
`403` not owner; `404` cross-workspace/unknown; `409` the session is `generating` (undoing mid-run would show
the session undelegated while content still renders in the frozen bot voice — mirrors the delegate guard).
Idempotent otherwise (an already-undelegated session is a no-op restore).

---

### POST /api/generator/sessions/{session}/archive

Set `archived_at` — a blanket **FREEZE** that exempts the session from every lifecycle-reaper step (stale
recovery still applies — see "Lifecycle" below; archive only disables trash/purge). Authorization: `update`
(creator only). Idempotent.

**Response** `200 OK` — `GenerationSessionResource`, `is_archived: true`.

### POST /api/generator/sessions/{session}/unarchive

Clear `archived_at`, re-enrolling the session in the retention windows. Authorization: `update` (creator
only).

**Response** `200 OK` — `GenerationSessionResource`, `is_archived: false`.

---

## The `GenerationSessionResource` wire shape

The recipe snapshot is **deliberately never emitted** — it is a large immutable blob the FE re-reads via
`GET /generator/content-types` (part shapes) + the source template (slot descriptors).

| Field | Type | Notes |
|---|---|---|
| `id`, `name`, `template_id`, `content_type` | | provenance + identity |
| `status` | `'draft'\|'generating'\|'ready'\|'failed'` | |
| `slot_values` | `object` | the user's filled inputs, always an object (`[]` → `{}` normalized) |
| `results` | `object \| null` | `null` until a run has produced anything — see "The `results` map" below |
| `part_history` | `object` | per-part undo state — see below |
| `last_op_status` | `'ok'\|'failed'\|null` | outcome of the most recent per-part regenerate/refine; `null` = none since the last claim |
| `last_op_error` | `string \| null` | localized, non-secret failure message when `last_op_status === 'failed'` |
| `creator` | `Creator \| null` | `whenLoaded` — polymorphic (`user` today; `workflow_run`/`bot` reserved) |
| `is_owner` | `bool` | presentational creator match |
| `can_generate` | `bool` | may kick off ANY run (whole-session OR per-part) — `can update` AND not already `generating` |
| `can_edit` | `bool` | may PATCH inputs — `can update` AND `status.isEditable()` |
| `can_be_deleted` | `bool` | `can delete` |
| `creative_direction` | `object \| null` | the run's DERIVED creative direction (the direction layer, B2 below) — `null` when the layer is disabled, derivation failed/found nothing, or the session has not run yet. **DETAIL-ONLY**: the list endpoint (`GET /generator/sessions`) uses a lean projection (`GenerationSessionResource::lean()`) that OMITS this key entirely (re-normalizing it costs real work per row); every single-resource response (show/store/update/generate/refine/undo/archive/delegate) carries it. See "Creative direction layer" below. |
| `is_archived` | `bool` | `archived_at !== null` |
| `can_archive` | `bool` | `can update` — gates the archive/unarchive toggle |
| `bot_author` | `{id,name,icon} \| null` | the SNAPSHOTTED bot-author overlay (R2 sub-stage 3) — read off `bot_delegation.author`, never the live bot; `null` when undelegated |
| `is_delegated` | `bool` | whether the delegation overlay is present |
| `can_delegate` | `bool` | `can update` AND `status.isEditable()` — gates the delegate affordance (delegating mutates inputs) |
| `can_undo_delegation` | `bool` | `can update` AND `is_delegated` AND `status !== 'generating'` — the UNDO gate; deliberately NOT the same as `can_delegate` (a delegated `failed` session stays revertible even though it is not "editable") |
| `unfilled_required_slots` | `string[]` | the SOFT delegation signal — required slots still without a usable value (incl. a required FILE slot, which can never be bot-filled); NOT a hard generate-gate |
| `archived_at`, `created_at`, `updated_at` | `string (ISO 8601) \| null` | |

### Bot-author delegation overlay (R2 sub-stage 3)

A human may DELEGATE an editable session (`draft`/`ready`) to a workspace bot: the bot (1) autonomously fills
the session's in-scope slots and (2) becomes the session's content AUTHOR, so every subsequently rendered
text part — and the `shot_list` voiceover/hook/cta — reads in the bot's voice. The human `creator` is
UNCHANGED and keeps full ownership (edit/refine/undo/archive/delete); this is a REVERSIBLE, SNAPSHOTTED
overlay, not an ownership transfer. See `docs/decisions/ADR-0036-bot-delegation-generation-sessions.md` for
the full design record (author-overlay-not-creator, the opaque-voice seam, the one-way `Bot → Generator`
edge, the reversible-undo mechanics).

```
GenerationSession (bot-author overlay columns)
  bot_author_id   (provenance only — nullable, indexed, NO cross-module FK, like template_id)
  bot_delegation  (json | null) = {
    author:              { id, name, icon }   // denormalized bot snapshot, for the "authored by" chip
    voice:               "<opaque directive>" // BotVoiceComposer::compose($bot) — persona/style/dictionary/
                                               // phrases/prohibitions folded into ONE string; never parsed
    snapshot_at:          "<ISO 8601>"
    slot_values_before:   { <slot name>: <value> }  // the human's slot_values AT delegation time — undo restores this
  }
```

Both columns are written / cleared TOGETHER (all-or-nothing) — there is no state where one is set and the
other is not.

**The voice replaces the persona line, never stacks with it.** `Variables\Support\AiVoiceContext` is a
request/run-scoped ambient holder — the exact twin of `MeterContext` — that
`GenerationSessionExecutor` sets to `session->botVoice()` around EVERY render scope (whole-run, per-part
regenerate, per-part refine), cleared in the SAME `finally` as the meter's session tag. `AiTextGenerationService::
generate()` reads it and, when present, SUBSTITUTES it for the resolved `AiPersona` tone line in
`AiTextAgent`'s system instruction (a delegated run never has both); a non-delegated run never sets the
directive, so behavior is byte-identical to before this feature existed. `ShotListAgent` (§ "shot_list — the
structured JSON contract") accepts the SAME directive as an additive tone clause — the strict `{hook, shots,
cta}` JSON contract is unchanged, only the wording tone shifts. The `storyboard` IMAGE prompt is untouched —
it stays the authored `style` only; the bot's voice colors TEXT output, not the image-generation prompt.

**Autonomous slot-fill is scoped to plain typed inputs.** `Bot\Services\BotSlotFillService::fill()` makes ONE
metered `ai_text` call (gate-before-spend on the workspace's monthly cap, session-tagged via `MeterContext` —
identical posture to every other AI spend, see "Cost meter integration" below) through `Bot\Agents\
BotSlotFillAgent`, a prompt-and-parse agent mirroring `ShotListAgent`'s defensive-parse posture. The
schema offered to the bot EXCLUDES `file`-based slots and "deferred composites" (an `array<object>`, or an
object nesting another object/file beyond one level) — `SessionDelegationService::introspectSlots()`/
`isOfferable()` — because a bot has no Disk access and must never be able to forge a file reference. Every
proposed value is RE-VALIDATED against the SAME `ConstantTypeValidator` descriptor authority a human write
uses (`applyBotSlotValues()`) before persisting; an invalid/unknown/out-of-scope value is DROPPED, never
stored, and reported. The accepted values are MERGED into the existing `slot_values` — but whether a human's
own prior fills survive that merge is a `fill_mode` question, not a blanket guarantee: `gaps` (see below)
never offers an already-filled slot to the model, so those keys are untouched; `fresh` deliberately offers
and overwrites every in-scope slot, prior fills included (undo is the safety net — see "Undo is a full,
reversible restore" below). Returns the `fill_report`:

```jsonc
{
  "filled": ["topic", "tone"],                                        // accepted, now in slot_values
  "skipped": [ { "name": "unknown_field", "reason": "unknown_slot" } ], // unknown_slot | out_of_scope | invalid | already_filled
  "unfilled_required": ["attachment"],                                 // still empty, incl. required FILE slots
  "mode": "gaps",                                                      // the fill_mode that ACTUALLY ran
  "nothing_to_fill": false                                             // true = the bot had nothing to do, nothing was spent
}
```

**WHICH slots are offered is the human's `fill_mode` choice** (`gaps` — only the empty ones, the default;
`fresh` — all of them, with an explicit demand for a different take), applied as a FILTER over
`introspectSlots()` — the scope authority (`SlotScopePolicy`) and the re-validating persist path are shared,
never forked. In `gaps` mode a proposal naming an already-filled slot is refused before persistence
(`already_filled`), so "never overwrite what the human typed" is enforced server-side rather than by prompt.

No AI call (no spend) is made when the mode has nothing to offer — the session has no bot-fillable slots at
all, or (`gaps`) none of them is empty. `fill()` short-circuits to an empty-values `applyBotSlotValues([])`
call, which still computes `unfilled_required`, and reports `nothing_to_fill: true`. The delegation overlay is
stamped regardless (authorship is not about slots).

**Undo is a full, reversible restore.** `applyDelegation()` snapshots `slot_values` AS THEY ARE at delegation
time into `bot_delegation.slot_values_before` — captured BEFORE the autonomous fill runs (the controller
stamps the overlay first, then fills) — so it holds exactly the human's own pre-delegation inputs.
`DELETE …/delegate` (`clearDelegation()`) restores `slot_values` from that snapshot, THEN nulls both overlay
columns, in one save. A `delegate → undo` round trip therefore fully reverts the bot's fill (and anything it
overwrote) with ZERO data loss. Re-delegating an already-delegated session OVERWRITES the whole overlay (no
stale bleed from a prior bot) and snapshots whatever `slot_values` are present at that moment — undo then
reverts to THAT snapshot, which is correct for the CURRENT delegation.

**Never logged.** `SessionDelegationService`/`BotSlotFillService` surface only structured facts (the fill
report's names + reasons) — never the voice directive, the slot values, or any bot-generated content.

### Automation seam (R2 sub-stage 5) — a Workflow consuming a Template IS modeled

**Superseding this document's earlier text**, which said "a Workflow consuming a Template/Session is not
modeled" — it now is. `App\Modules\Workflows\Steps\GenerateContentStep` (`docs/backend/workflows-api.md` →
"Steps: `generate_content`") runs a session end-to-end from inside a workflow run, entirely through this
module's ONE HTTP-free, primitives-only entry point: `Generator\Services\SessionAutomationService`. Full
design record: `docs/decisions/ADR-0039-workflow-suspend-resume-and-generate-content.md`.

`SessionAutomationService` deliberately owns only the two operations that had NO callable form for a
non-interactive caller before this sub-stage — everything else is the module's EXISTING interactive seam,
reused directly and unwrapped, so the automated path can never drift from the interactive one (there is
exactly one implementation of each step):

| Step | Seam | Notes |
|---|---|---|
| CREATE | `SessionAutomationService::createFromTemplate(Template, slotValues, ?name)` | Delegates to the SAME `GenerationSessionService::create()` the interactive `POST /generator/sessions` uses — snapshot-authoritative (ADR-0034 D1), no second creation path. Called with an EMPTY `slotValues` seed by `GenerateContentStep` (see below) rather than the raw resolved map — the fill happens through the typed FILL seam next, not by pre-seeding untrusted values the fill path would then have to re-check anyway. |
| FILL | `SessionDelegationService::applySlotValues(session, values, SlotScopePolicy::Automation)` | The SAME slot-fill authority `SlotScopePolicy::Bot` (R2 sub-stage 3, ADR-0036) already uses, parameterized by a SECOND policy case — see "`SlotScopePolicy` — two callers, one shared validator" below. Re-validates every value against its descriptor via the shared `ConstantTypeValidator` and reports what it filled/skipped/left unfilled, exactly like a bot delegation's fill report. |
| RUN | `GenerationSessionRunManager::claimAndDispatch(session, mode, partKey, instruction, ?connection)` | The IDENTICAL atomically-claimed, budget-gated choke point a manual "Generuj" click and a bot `auto_generate:true` both go through — the pre-run `429 ai_budget_exceeded` gate (ADR-0037) applies unchanged. The trailing `?connection` parameter is what `GenerateContentStep` passes `RealQueueConnection::current()` through, so the queued generation reaches the REAL queue instead of the workflow run loop's forced `sync` driver (see `docs/backend/workflows-api.md` → "Suspend/resume engine"). |
| WAIT | `SessionAutomationService::terminalStatusFor(sessionId)` | The ONLY question an outside module is allowed to ask about a session's progress — a plain wire status string (`draft`/`generating`/`ready`/`failed`, or `null` if the session no longer exists/is not in this workspace), so a waiting caller never reaches into a Generator model or enum. Workspace-scoped like every other read; a non-uuid id short-circuits to `null` rather than hitting the database. |
| COLLECT | `SessionContentProjector::project(session)` + `GeneratedImageExporter::saveToDiskIfPresent(...)` | See below. |

**`SessionContentProjector` — the server-side sibling of the FE's `FinalPostBody.vue`, not a second render
path.** Assembles a finished, READY session's per-part `results` into ONE text string, walking the content
type's parts in DECLARED SNAPSHOT ORDER (`ContentTypeRegistry::partsForSnapshot()` — so a legacy session's
own recomposed parts, not a later content-type recomposition, are what gets projected) and concatenating
every TEXT-bearing part's contribution (`text_body`/`script`'s rendered `text`, `shot_list`'s flattened
`text`, `scene_plan`'s scene narrations in order) with a blank line between blocks; an `image_plan`/
`storyboard` contributes nothing (image-only; a storyboard's readable script IS its sibling `shot_list`'s
flattening, so including it again would duplicate it). FAIL-SOFT throughout: not-ready, no results, a
missing/failed/empty part all simply contribute `''`, never a throw. It never queries or writes anything —
a pure read over an already-hydrated `GenerationSession`. A fixture test pins this projector and the FE
component's composition together — change one, update the other.

**`GeneratedImageExporter` — extracted verbatim from the interactive `saveToDisk` controller action, now
with a non-aborting entry point.** `saveToDisk()` (the pre-existing HTTP path, unchanged: 404s when the
part has no current produced image) and the NEW `saveToDiskIfPresent()` share ONE composition — resolve the
part's CURRENT image version, read the bytes from `GeneratedImageStore`, write through Disk's own
`FileService::storeDiskContent()` (no hand-rolled storage) — but `saveToDiskIfPresent()` returns `null`
instead of aborting when a part produced nothing, because a queued caller has no HTTP response to 404 into
and "this part produced nothing" is an ordinary outcome for it, not an error.
`producedImagePartKeys(session)` additionally enumerates EVERY part key (top-level `image`, or nested
per-item `storyboard.<i>`/`scene_plan.<i>`) that currently carries a produced image, in results order — the
list a server-side run needs before it can export the WHOLE run's images, delegating to the SAME
`GenerationSessionRefiner::blobRefsOf()` walk the history/blob GC already uses, so the enumeration knowledge
lives in exactly one place.

**`GeneratedImageExporter` performs NO authorization at all** — this is intentional and safe ONLY because
its one caller (`GenerateContentStep::resume()`) exports a session IT created moments earlier, from a
template the workflow author was already authorized to use; the exporter must never become reachable with a
session id supplied from outside that one call site. See ADR-0039 D7.

**`SlotScopePolicy` — two callers, one shared validator, two different trust boundaries.**
`Generator\Enums\SlotScopePolicy` gained a second case, `Automation`, alongside R2 sub-stage 3's `Bot`. Both
decide OFFERABILITY only (which declared slot descriptors are in scope for that caller) — every accepted
value is STILL re-validated against its own descriptor by the shared `ConstantTypeValidator` regardless of
policy, so this is a scope parameter on ONE fill path, never a second implementation. The ONE difference:
`Automation::allowsFileSlots()` is `true` (a SINGLE scalar `file` slot is offerable), `Bot::allowsFileSlots()`
stays `false`. A bot's proposed values come from a MODEL that could fabricate a plausible-looking Disk
reference it was never actually given, so `SlotScopePolicy::Bot`'s refusal is unconditional and unchanged by
this sub-stage. An automation mapping is authored by a TRUSTED workspace human (they wrote the
`generate_content` step's config), and the file id it carries is resolved through the tenant-scoped `File`
model before anything is persisted — a foreign/deleted/made-up id simply does not resolve and the value is
dropped like any other invalid one. At THIS layer (`SlotScopePolicy::accepts()`), the deep composites out
of scope for BOTH policies are narrower than "any object shape": an `array<object>`, an object nesting
another object/file, and an `array<file>` are refused — the catalog/resolver do not offer per-element
composite access yet (the "R2 loop" deferral), so no automatic filler may write one either way. A PLAIN
(non-array) `object` slot whose fields are all SCALAR leaves (no nested object/file) IS offerable at this
layer, for both `Bot` and `Automation` — `SlotScopePolicy::accepts()` returns `true` for it.

A blanket refusal of every `object`-base slot, ANY shape, does exist — but it is a SEPARATE, stricter rule
at the WORKFLOW-AUTHORING layer only (`StoreWorkflowRequest::isUnsuppliableSlot()`, see
`docs/backend/workflows-api.md` → "The composite-slot refusal" and ADR-0039 D15), for a different reason:
the workflow value-or-variable resolver has no OBJECT coercion arm, so a mapped object literal or pipeline
could never actually reach the session even though `SlotScopePolicy` would offer the slot. That rule
applies only to a `generate_content` step's `slots` mapping — it has no bearing on a bot's direct
delegation fill, which never goes through `StoreWorkflowRequest`. See ADR-0039 D14 and the amendment
recorded in `docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`.

**Automation-session lifecycle.** A session `GenerateContentStep` creates is a session in EVERY other
respect — same status machine, same lifecycle reaper, same everything — with two attribution differences
from a human-created one:

- **Creator.** Created INSIDE the run (`WorkflowRunContext` published for the whole step loop, ADR-0015), so
  `HasCreator` stamps `creator_type='workflow_run', creator_id=<run id>` — visible in the sessions LIST like
  any other session (`GET /generator/sessions`, `creator.type === 'workflow_run'`), and readable through
  `docs/backend/creator-attribution.md`'s general polymorphic-creator contract (a session's `creator` was
  already documented as `user | workflow_run | bot` — this sub-stage is the first to actually produce the
  `workflow_run` case).
- **Exported images.** Every produced image the step exports lands on the workspace's Disk with
  `uploader_type='workflow_run'` (see "The composition" above / ADR-0039 D7) — attributable the same way
  any other run-authored row already is.

An automation-created session is otherwise ORDINARY: it can be opened in the chat, refined, undone,
archived, or deleted by any workspace member with the usual creator-only mutation gate — `HasCreator`'s
"system record" ownership fallback (`ChecksRecordOwnership::ownsOrManagesSystemRecord`) applies to it exactly
as it does to a bot-authored task, so a workspace owner can still manage a run-created session nobody else
can touch.

### The `results` map

Keyed by the content type's part keys (`Support\ContentTypePart::key`). Shape depends on the part's `kind`:

```jsonc
{
  "body": { "kind": "text_body", "status": "ok", "text": "…generated text…", "version": 2 },
  "image": { "kind": "image_plan", "status": "ok",
             "image": { "mime": "image/png", "width": 1024, "height": 1024, "version": 1 },
             "version": 1 },
  "scene_plan": { "kind": "scene_plan", "status": "ok", "scenes": [
    { "narration": "Open on the sea.", "image_status": "none" },
    { "narration": "Wide shot.", "image_status": "ok",
      "image": { "mime": "image/png", "width": 1024, "height": 1024, "version": 1 },
      "part_key": "scene_plan.1" }
  ] },
  "shot_list": { "kind": "shot_list", "status": "ok", "version": 1, "parse_ok": true,
    "hook": "Stop scrolling — your desk is lying to you.",
    "shots": [ { "visual": "A cluttered desk, top-down.", "voiceover": "This is chaos.", "seconds": 3 } ],
    "cta": "Follow for more.",
    "text": "Stop scrolling…\nShot 1: A cluttered desk, top-down. / This is chaos. (3s)\nFollow for more." },
  "storyboard": { "kind": "storyboard", "status": "ok", "stale": true, "shots": [
    { "index": 0, "visual": "A cluttered desk, top-down.", "voiceover": "This is chaos.", "seconds": 3,
      "image_status": "ok",
      "image": { "mime": "image/png", "width": 1024, "height": 1024, "version": 1 },
      "part_key": "storyboard.0" }
  ] }
}
```

| Kind | `ok` shape | `failed` shape |
|---|---|---|
| `text_body` / `script` | `{ kind, status:'ok', text: string, version: int }` | `{ kind, status:'failed', error: string }` — no `text` key at all |
| `image_plan` | `{ kind, status:'ok', image: {mime,width,height,version}, version: int }` — NO bytes; fetch via the serve endpoint | `{ kind, status:'failed', error: string }` |
| `scene_plan` | `{ kind, status:'ok', scenes: SceneResult[] }` — the part itself is never `failed`; each scene fails independently | (not applicable — see per-scene below) |
| `shot_list` | `{ kind, status:'ok', version, hook, shots: ShotListShot[], cta, text, parse_ok }` — see "shot_list — the structured JSON contract" below | `{ kind, status:'failed', error: string }` — a blank model reply |
| `storyboard` | `{ kind, status:'ok', shots: StoryboardShot[] }` — the part itself is never `failed`; each shot fails independently | (not applicable — see per-shot below) |

A scene entry: `{ narration: string, image_status: 'ok'|'failed'|'none', image?, part_key?, image_error? }`
— `part_key` (e.g. `scene_plan.1`, 0-indexed) is present only on `image_status:'ok'` and is what the serve
endpoint + `part_history` key on for that scene's image.

A storyboard shot entry: `{ index: int, visual: string, voiceover: string, seconds: int, image_status:
'ok'|'failed', image?, part_key?, image_error? }` — mirrors a scene entry, but EVERY shot attempts an image
(no `'none'` arm: `image_status` is only `ok`/`failed`), and the descriptive fields (`visual`/`voiceover`/
`seconds` — copied from the sibling `shot_list`'s matching shot) ride the entry even when its image failed,
so the FE can still show the beat. `part_key` (`storyboard.<i>`, 0-indexed) is what the serve/save/refine
endpoints address — see "Per-shot addressing" below.

**Every `error`/`image_error` is a localized, non-secret string** (`generator.sessions.part_failed`,
`image_base_unavailable`, `ai_generate_unsupported`, `image_budget`, `image_generate_budget`,
`image_failed`, …) — never the
resolved prompt, the instruction, or a provider response body. A per-part failure is **fail-soft**: every
other part in the same run still executes, and the session as a whole still ends `ready`. Only a run-level
infra fault (e.g. a DB error) fails the whole session.

**`stale` — the cross-part coherence hint.** Any `ok` result may additionally carry `stale: true` (never
`false` — simply absent when not stale) when an UPSTREAM part it (textually or structurally) depends on was
refined/regenerated after it was produced. See "Cross-part context" above; the flag rides verbatim in every
result, not only `shot_list`/`storyboard`.

### `shot_list` — the structured JSON contract (video_script rework Phase B)

A `shot_list` part is authored as a creative BRIEF (`content.shot_list = {brief:{markdown}}`, a
resolver-resolvable body — see `docs/backend/generator-api.md`). At run time it makes ONE metered `ai_text`
call (`Services\ShotListRenderer` → the `ShotListAgent` instruction, counted against
`generator.ai_text_max_calls_per_session` like any other text part) and DEFENSIVELY parses the reply — see
`docs/decisions/ADR-0035-video-script-cross-part-storyboard.md` (D6) for why this is prompt-and-parse rather
than laravel/ai's native structured output. On a DELEGATED session (R2 sub-stage 3, see "Bot-author
delegation overlay" above) `ShotListAgent` also receives the ambient `AiVoiceContext` directive as an
ADDITIVE tone clause — the `{hook, shots, cta}` JSON contract and the defensive parse are unchanged; only
the voiceover/hook/cta wording tone shifts to the bot's voice.

| Model reply | Result |
|---|---|
| A well-formed JSON object `{hook, shots:[{visual,voiceover,seconds}], cta}` (optionally fenced in ` ```json … ``` `) | `{status:'ok', hook, shots, cta, text:<flattened>, parse_ok:true}` |
| A bare top-level JSON ARRAY of shots (a model deviating from the object contract) | Treated as the shots list: `{status:'ok', hook:'', shots, cta:'', text:<flattened>, parse_ok:true}` |
| Non-blank, non-decodable text (prose, a malformed/empty object or array) | `{status:'ok', hook:'', shots:[], cta:'', text:<raw reply>, parse_ok:false}` — the raw reply is kept so the author sees what came back |
| Blank (a failed / over-cap / empty provider reply) | The PART fails: `{status:'failed', error}` |

A malformed shot entry (missing string `visual`/`voiceover`) is dropped; `seconds` coerces to a
non-negative int (0 when absent/invalid); the shots list is CLAMPED to the run's EFFECTIVE shot cap
(`min(authored content.storyboard.max_shots, generator.storyboard_max_shots)` — see "Cost meter
integration" below for the config table, and "Creative direction layer" above for the full
`effectiveShotCap()` design) so a runaway model reply cannot inflate the storyboard's later image cost — the SAME cap
also bounds the agent's instructed shot count, so an author asking for fewer beats gets a shot list
WRITTEN for that count, not a longer one silently truncated. `text` is a readable
flattening (`hook` line, `Shot N: visual / voiceover (Ns)` per shot, `cta` line) — both the FE display body
AND this part's `parts.<key>` cross-part contribution.

`shot_list` is **refinable**: a free-text instruction (`POST …/parts/shot_list/refine`) feeds the CURRENT
shot list (as JSON data) + the instruction to the SAME renderer's `revise()` mode, which returns a full NEW
structured list. A blank OR unparseable revision is a FAILED no-op (the current good list is preserved, no
history churn) — symmetric with a text part's refine fail-closed posture.

### `storyboard` — one AI image per shot (video_script rework Phase B)

A `storyboard` part is authored as an OPTIONAL
`content.storyboard = {style?:{markdown}, filters?:[…], max_shots?:int}` — no `base` (unlike `image_plan`):
the base is always the AUTOMATIC per-shot `ai_generate`. The optional `max_shots` is the per-recipe
TIGHTENING of the platform ceiling (`TemplateContentValidator::validateMaxShots` — a whole number between 1
and `generator.storyboard_max_shots`; over-ceiling is a `422` at write, absent/null means "use the
ceiling"). At run time the executor reads the sibling `shot_list`'s STRUCTURED `shots[]` by direct
intra-composition (the first `shot_list`-kind part declared before it — NOT via `parts.*`, which is
text-only) and, for EACH shot (bounded by the run's EFFECTIVE cap
`min(authored max_shots ?? ceiling, generator.storyboard_max_shots)` — the same one value the shot list was
written and clamped against), generates ONE image: the prompt is the composed
`<continuity clause> + <direction anchor, when the run has one> + <resolved authored style> + <the shot's
visual>` (see "A `storyboard` shot's base is always an INTERNAL `ai_generate`" under "The image chain"
below) — the `visual` is resolved with an IDENTITY function (never re-run through the
directive resolver), because it is the shot-list AI's OWN output and must never be re-interpreted as a
directive (an injection safeguard, extending the same posture `globals`/`parts` values already have to a
nested AI-to-AI handoff). The optional authored `filters` chain then runs exactly like an `image_plan`'s
(same pixel-op set + `ai_edit`, both metered/budget-gated the same way — see "The image chain" below), on
EVERY shot, out of the run's ONE cumulative `ai_edit` budget.
Per-shot FAIL-SOFT: a broken shot never sinks the others; the `storyboard` part itself is never `failed`.
An empty/missing/failed sibling `shot_list` yields `{status:'ok', shots:[]}`.

**Per-shot addressing (`storyboard.<i>`) reuses the EXISTING per-part op contract verbatim** — no new
endpoints. The dotted sub-key is recognized wherever a bare part key is (mirrors `scene_plan.<i>`, R2
sub-stage 2c):

| Endpoint | `storyboard.<i>` behavior |
|---|---|
| `POST …/parts/storyboard.<i>/regenerate` | Re-images JUST shot `i` (the sibling shot list's CURRENT `visual` + the storyboard's authored style/filters) — a fresh variation, other shots untouched. |
| `POST …/parts/storyboard.<i>/refine` | An AI EDIT of shot `i`'s CURRENT image bytes (the same `ai_edit` seam an `image_plan` refine uses). |
| `POST …/parts/storyboard.<i>/undo` | Restores shot `i`'s previous image version; deletes the just-undone blob. Synchronous, like every other undo. |
| `GET …/parts/storyboard.<i>/image` | Streams shot `i`'s current produced image. |
| `POST …/parts/storyboard.<i>/save-to-disk` | Promotes shot `i`'s current image onto Disk. |

The BARE `storyboard` part (no index) is **not** free-text refinable — a multi-shot composite has no single
current output to revise (`422`, the same rule `scene_plan` already had); regenerate/undo on the bare key
still work (they re-render/restore ALL shots). Refining or regenerating `shot_list` marks the sibling
`storyboard` `stale: true` (a structural dependency, not a `parts.*` reference — see "Cross-part context"
above); regenerating `storyboard.<i>` itself does not un-stale the WHOLE storyboard (only a full `generate`,
or regenerating the bare `storyboard` part, clears it).

### `part_history` — the undo affordance

```json
{ "body": { "can_undo": true, "undo_depth": 3 }, "image": { "can_undo": false, "undo_depth": 1 } }
```

A part with no prior version is simply **absent** from the map (not `{undo_depth: 0}`). `can_undo` is
server-authoritative: `caller can update` **AND** `session.status === 'ready'` (undo is invalid mid-run —
gated the same way regardless of `undo_depth`). `undo_depth` is the count of prior versions on that part's
bounded history stack (capped at `generator.history_max_versions`, default 20). The full stack itself
(`GenerationSession::history`, one prior result snapshot per undo step) is internal and never emitted — only
this lean summary.

---

## The image chain (R2 sub-stage 2c)

An `image_plan` part (or a scene's optional nested one) EXECUTES server-side, synchronously, inside the
already-async `RunGenerationSessionJob` — no nested queue. `Services\ImageChainExecutor`:

1. **Resolve the base** to raw bytes — `Services\ImageBaseResolver`, the deliberate Generator → Disk READ
   edge (Fork 3 in `docs/decisions/ADR-0034-generation-sessions.md`):
   | `base.kind` | Resolution | Failure |
   |---|---|---|
   | `disk_file` | `Disk\Models\File::query()->find($id)` — tenant-scoped, so a foreign-workspace id is simply not found | missing / non-image / blob-less → `ImageBaseUnavailable` |
   | `from_slot` | the file id/descriptor held by the named FILE-typed slot's filled value | unfilled / non-file slot value → `ImageBaseUnavailable` |
   | `ai_generate` | **RUNNABLE (R2 sub-stage 6).** The prompt is resolved through the session resolver, then generated via a synchronous, METERED (channel `ai_image_generate`) `Disk\Services\ImageGenerateService::generate()` — `ImageChainExecutor` INTERCEPTS this kind BEFORE the resolver. | over the per-session generate budget → `ImageGenerateBudgetExceeded` (`image_generate_budget`); over the workspace's monthly **$** cap (ADR-0037; the old token cap gates nothing since R2 sub-stage 4) → `AiBudgetExceededException` → `image_budget`. (`ImageBaseResolver`'s own `ImageBaseUnsupported` throw is now only a DEFENSIVE fallback for a direct `resolve()` call.) |

2. **Cap to `generator.image_max_edge`** (default 2048px, longest edge, never upscaled) before running the
   chain — bounds pixel-op cost and the bytes sent to a provider.

3. **Run the ordered `filters` chain** — each entry is applied via `Imagick`, REPLACING the working image
   for an `ai_edit` step:
   - **`pixel`** — `Services\ImagePixelProcessor`, an exact `Imagick` re-expression of the browser editor's
     math (`resources/js/next/pages/disk/imageOps.ts` is the fidelity authority: Rec.601 luma, the same
     brightness/contrast/saturation formulas, `Math.round`-equivalent rounding). The build is HDRI, so every
     color op is followed by `clampImage()` to reproduce the editor's `[0,255]` clamp. Never throws on a bad
     op/param (write-validated already; a malformed-but-accepted plan must not 500 a run).
   - **`ai_edit`** — the prompt (and optional mask reference) resolved through the SAME session resolver as
     a body, then a synchronous `Disk\Services\ImageAiService::edit()` call — **metered** (channel
     `ai_image_edit`, session-tagged, see "Cost meter integration" below) and gated by a **per-session
     budget** (`generator.image_edit_max_calls_per_session`, default 8 — in lock-step with
     `storyboard_max_shots`, instance-counted, cumulative across every image part AND every storyboard shot in
     the run) — an over-budget `ai_edit` is refused BEFORE any provider call. **In a CHAIN that refusal
     DEGRADES GRACEFULLY**: the step is SKIPPED (logged, fact only) and the chain continues, so the part still
     yields `status:'ok'` with a real image, just without that filter. It is deliberately not a part failure —
     an authored storyboard filter chain runs on EVERY shot, so failing the part would destroy the tail of the
     shot list over one decorative step. Only the BUDGET case degrades; a provider/transport/mask failure
     still fails the part as before.

4. **Store the produced PNG as a new VERSION** — `Services\GeneratedImageStore`
   (`generator-sessions/<workspaceId>/<sessionId>/<partKey>/<version>.png`). The bytes never ride the JSON
   response; only `{mime, width, height, version}` does.

A **refine** of an image part (`editImage()`) skips the base/pixel steps entirely: the CURRENT produced
bytes are the base, and the instruction IS the one `ai_edit` prompt — still metered, still budget-gated,
still versioned into the store. A refine does NOT take the chain's skip-and-continue degradation: the edit
IS the whole operation there, so an exhausted budget fails the op soft as a NO-OP (the current image is
preserved unchanged, no version churn) with `generator.sessions.image_budget`.

**A `storyboard` shot's base is always an INTERNAL `ai_generate`, never authored.** Unlike a top-level
`image_plan`'s `base`, a `storyboard` part has no `base` field at all (see "storyboard — one AI image per
shot" above) — the executor builds the plan itself:
`{base:{kind:'ai_generate', prompt: <composed>}, filters: <authored filters>}`. The base prompt is composed
IN THIS ORDER by `produceStoryboardShotImage()`:

1. the per-frame **CONTINUITY clause** — "Frame i of N from the SAME film/production — consistent world,
   palette, medium, lighting and subject across all frames" (app-authored, trusted): what stops N
   independent text→image calls from reading as N different films;
2. the run's **direction anchor** (`CreativeDirection::forImage()` — art direction + recurring subject +
   continuity notes), when the run has a direction and the layer is enabled — see "Creative direction layer"
   above, whose table lists the storyboard shot as one of `forImage()`'s injection points;
3. the resolved authored **`style`** (LAST of the framing, so a template can override the derived anchor);
4. the shot's **`visual`** — what THIS frame shows.

The whole composed prompt is then passed through an IDENTITY resolver (never the real directive resolver)
so neither the shot's `visual` — the shot-list AI's own output — nor the model-derived anchor is
re-interpreted as a directive; only the authored `style` and the `filters`' own prompts resolve through the
normal session resolver.

**Failure is fail-soft per part.** A domain failure (`ImageBaseUnavailable`, `ImageBaseUnsupported`,
`ImageGenerateBudgetExceeded` — all `Exceptions\ImageChainException`) turns that ONE part (or ONE storyboard
shot) into `{status:'failed', error: <localized>}`; every other part still runs, and the session ends
`ready`. `ImageEditBudgetExceeded` is the ONE member that does not reach a part result from a chain — it
degrades to a skipped filter (above) and only surfaces on the refine path.

---

## Narrative contract upgrades (B1 — zero AI cost)

Two prompt-only fixes to `ShotListAgent` (§ "shot_list — the structured JSON contract" above), shipped
alongside the direction layer but independent of it (they cost nothing extra and apply even with
`generator.direction.enabled=false`):

**Adaptive shot count, not a fixed "3 to 5".** The old system rule ("3 to 5 SHOTS … Keep it tight")
OVERRODE a brief's stated duration — the diagnosed root cause of a 1–2-minute brief producing a 15-second
script. The instruction now asks for `{countClause}` shots — "between 3 and {effective cap}" (or "no more
than {cap}" when the cap itself is below 3, so a 1–2-shot recipe never sees a nonsensical "between 3 and
1") — each shot 5–30 seconds as a GUIDE, not a rule. **Precedence when they conflict:** a stated duration
(from the brief or the derived direction, see B2) AND the shot bound are BINDING; the 5–30s span is only a
guide — the model is told to LENGTHEN individual beats past 30s rather than shorten the piece to fit the
guide.

**A STORY block that degrades coherently at a low cap.** The instruction now asks for a THROUGH-LINE (one
protagonist/subject with something at stake, carried hook→cta), ESCALATION, a PAYOFF that resolves
something SET UP earlier, continuous voiceover (the hook + all voiceovers + the cta read as ONE narration,
never disconnected per-shot captions), SUBJECT CONSISTENCY (the same descriptor wording across shots), and
a CTA that follows from the payoff. `ShotListAgent::storyClause(int $maxShots)` has THREE variants because
most of those rules are stated ACROSS shots ("a payoff set up in an earlier shot") — impossible at a cap of
1 and only barely expressible at 2. An unsatisfiable MUST is worse than none (the model silently breaks
one, arbitrarily), so the block is REWRITTEN per cap rather than left as an impossible instruction: at
`maxShots >= 3` the full escalation/payoff/continuity/consistency block; at `maxShots === 2` the same rules
restated over exactly two shots; at `maxShots === 1` a single "setup and payoff in one shot" rule. The
cap-independent spine rules (through-line, cta-from-payoff) stay fixed around it.

A direction-aware clause (present only when a creative-direction block rides the prompt, see B2) tells the
model to CONDENSE a longer arc into the shot bound — merge adjacent beats so the whole arc, including its
payoff, still fits — never to drop the payoff.

## Creative direction layer (the shared creative frame, B2)

**The problem this closes.** Before this layer, every generation inside a session run was an INDEPENDENT
AI call — N text blocks and N storyboard images that had never seen each other, plus the contract flaw B1
fixes. A `post_with_image`'s body and its image could describe different things; five storyboard frames
could read as five different productions. The direction layer derives ONE small creative frame up front and
threads it into every later generation in the SAME run, so the finished piece reads as one work.

**What it is.** A FULL run makes exactly ONE extra metered `ai_text` call
(`Services\CreativeDirectionService::derive()` → `Agents\CreativeDirectionAgent`) that returns a structured
JSON object, defensively parsed and normalized by `Support\CreativeDirection::fromArray()`, and persisted to
a new nullable `creative_direction` json column on `generation_sessions` (additive migrations, central +
`database/migrations/tenant/`):

```
CreativeDirection (all fields nullable; an all-empty result normalizes to NULL, never an empty object)
  message                          one-line summary of the piece
  goal, audience, tone             one-line each
  through_line                     the spine — a protagonist/subject with something at stake
  arc_beats[]                      ordered setup → escalation → payoff, capped at 12 entries
  subject, setting                 one-line each — "subject" is a REUSABLE descriptor every later step repeats verbatim
  visual_style{medium,palette,lighting,camera}   how the piece LOOKS — keeps separate generated images in one world
  duration_target_seconds          whole seconds, only when the recipe states/implies a duration
  continuity_notes                 free text
```

**Derived from the AUTHORED recipe, not the resolved brief.** `CreativeDirectionService::input()` feeds the
model the SAME no-op-previewed rendering `TemplateRenderService::render()` produces for the template editor
(`@[ai-text]` shows as a labeled `[AI: <resolved prompt>]` placeholder, never actually calling a model) plus
a size-capped digest of the run's SCALAR slot values (file/composite slots excluded). Driven by
`ContentTypeRegistry::partsForSnapshot()` — the same snapshot-authoritative part list the executor itself
renders — so a legacy `[script, scene_plan]` snapshot is directed by the body it will actually render. This
choice is load-bearing on two counts: it costs ZERO extra spend and is deterministic (deriving from a
RESOLVED brief would re-run the nested `@[ai-text]` call, billing it twice), and it preserves FIDELITY (the
author's own hard constraints — "1–2 minutes", "must open on the product" — survive verbatim instead of
arriving as a model's paraphrase of them). **No recipe → no call at all** — a slot digest alone never
triggers a derivation (inventing a whole creative frame from `{topic: "Espresso"}` and injecting it as
BINDING would be worse than no direction), so an empty/near-empty recipe costs nothing extra.

**Injected as fenced USER-message DATA, never a system instruction.** The derived direction is
untrusted-laundered content (model-written from user-supplied slot values), so it may only ever ride the
same DATA channel the resolved prompt itself rides — never an agent's system instruction, whose
prompt-is-DATA hardening is what frames the block. Three PER-CONSUMER projections (`CreativeDirection::`
`forText()` / `forShotList()` / `forImage()`), each exposing only the fields that consumer needs:

| Consumer | Projection | Fields | Injection point |
|---|---|---|---|
| Every `@[ai-text]` block | `forText()` | message, goal, audience, tone, through_line | `GeneratorAiTextService::generate()` prefixes the resolved prompt with a fenced `CREATIVE DIRECTION (data)` block |
| `shot_list` generate/revise | `forShotList()` | through_line, arc_beats, subject, setting, duration_target_seconds, tone | `ShotListRenderer` prefixes the brief/revision prompt the same way; `ShotListAgent` additionally receives a TRUSTED, content-free `bool $directionAware` clause (never the block's own content) so its system instruction can tell the model how to treat the block without carrying any of the untrusted content itself |
| Every `ai_generate` image base — a plain `image_plan` part, a scene's image, AND every storyboard shot | `forImage()` | visual_style (medium/palette/lighting/camera), subject, continuity_notes — no goal/audience/message | Composed AHEAD of the authored prompt/style/visual as a compact, UNFENCED prose anchor (an image model has no system message and no fence to respect) — `GenerationSessionExecutor::directedImagePlan()` for a plain image/scene base, `produceStoryboardShotImage()` for a storyboard shot (prefixed by a per-frame CONTINUITY clause — "Frame i of N from the SAME film/production" — ahead of the anchor) |

Every prompt in the table above resolves through an IDENTITY function once composed with the anchor — the
anchor is model-derived content (data to draw/write from), never re-interpreted as a directive, exactly like
the shot-list's own `visual` output already is.

**Consistency caveat (character identity).** Prompt anchoring makes a storyboard's WORLD consistent — style,
medium, palette, lighting, camera, setting — but it does NOT guarantee the SAME character's face across
frames: each shot is an independent text→image call. Exact cross-frame character identity needs
image-to-image chaining (feed frame N's bytes as frame N+1's edit base), the named v2 path in ADR-0038
"Alternatives considered".

**Voice wins on tone.** When a session is delegated to a bot (ADR-0036), the bot's voice sits in the system
instruction; the direction's `tone` field is dropped from `forText()`/`forShotList()` (their `$withTone`
parameter) so the two never compete over the same wording decision.

**Lifecycle.** Derived ONCE per FULL run (`GenerationSessionExecutor::directionForFullRun()` — reuses an
already-stored direction on a redelivered run rather than deriving twice) and PERSISTED; every ISOLATED
per-part op (regenerate/refine/a `storyboard.<i>` op) READS the stored direction and never derives — zero
extra cost, and a refine stays inside the same creative frame the full run established.
`GenerationSessionRunManager::claimAndDispatch()` NULLS the column on a FULL-mode claim (each full run
derives fresh) and PRESERVES it on a part-op claim. Published for the whole render scope via the ambient
`Support\CreativeDirectionContext` — the Generator-owned twin of `Variables\Support\AiVoiceContext` — set
and cleared in the SAME `finally` as the meter/actor/voice tags at all three executor entry points
(`execute()`, `renderPartFromSnapshot()`, `renderRefinedPart()`). It exists for exactly ONE consumer that
has no parameter-passing seam (`GeneratorAiTextService`, called many frames below the executor through the
shared resolver); every OTHER consumer (`ShotListRenderer`, the storyboard image composer, the plain-image
composer) is executor-reachable and receives the direction as an EXPLICIT parameter instead.

**Security boundary — `CreativeDirection::fromArray()`.** The model's raw JSON reply is untrusted (laundered
from user-supplied slot values): every key is whitelisted (unknown keys dropped), every value type-checked
(strings only, `duration_target_seconds` the one ranged integer), control characters stripped, each field
length-capped (240 chars for a short field, 600 for a paragraph field), `arc_beats` bounded to 12 entries.
**The block's own fence markers are scrubbed with a NON-EMPTY `[removed]` sentinel** (never deleted) — a
review round found that deleting a marker occurrence lets a value engineered to contain a SPLIT marker
("`--- END CREATIVE <x>DIRECTION ---`") reassemble into a working one once `<x>` is removed; a non-empty
sentinel makes that reassembly impossible by construction. An all-empty result normalizes to `null`, never
an empty DATA block. The direction is NEVER logged (only the FACT of a derivation failure, on `Log::warning`).

**Fail-soft by construction.** `CreativeDirectionService::derive()` never throws — any failure (a blank/
unparseable/over-budget/erroring model call) returns `null`, and a `null` direction makes every injection
point above a no-op: the run renders EXACTLY as it did before this layer existed. The kill switch
`generator.direction.enabled=false` disables the whole layer — nothing is derived, read, or injected, and
every composed prompt is byte-identical to a direction-less run (pinned by a test).

**Budget: OUTSIDE the per-session ai-text call ceiling, on purpose.** The one derivation call is metered,
session-tagged, and actor-attributed exactly like any other spend (gated by the SAME workspace $ cap before
it runs — an over-cap workspace simply derives nothing and the run proceeds direction-less), but it is
deliberately NOT counted against `generator.ai_text_max_calls_per_session`: charging it to that budget would
let one fixed call starve a real content part (a 4-block recipe would then only resolve 3). It is bounded by
its own, tighter timeout so it cannot eat into the run job's fixed SIGALRM window (below).

| Config key | Default | Scope |
|---|---|---|
| `generator.direction.enabled` | `true` | The kill switch. `false` → nothing derived/read/injected anywhere; byte-identical to a pre-layer run. |
| `generator.direction.max_chars` | 4000 | Length cap on the model's raw JSON reply, BEFORE it is even parsed (the per-field caps in the normalizer are the real bound). |
| `generator.direction.max_input_chars` | 6000 | Hard cap on the derivation INPUT (the previewed recipe + the scalar slot digest), so a large recipe cannot inflate the one derivation call. |
| `ai.direction_timeout` | 30s | Provider timeout for the derivation call — deliberately TIGHTER than `ai.text_timeout` (60s): it runs inside `RunGenerationSessionJob`'s fixed 300s SIGALRM window on top of the ai-text fan-out, so `4 x 60 + 30 = 270s < 300s` is what keeps that window untouched. A hung derivation fails closed and the run proceeds direction-less. |

## Cost meter integration (R2 sub-stage 2a; the $ gate + budget UX is R2 sub-stage 4, ADR-0037)

Every AI spend a session drives — `@[ai-text]` for a text part, `ai_edit` for an image part or an image
refine, and `ai_generate` (a text→image base, channel `ai_image_generate`) — routes through the shared
`Variables\Contracts\MeteredAiCall` seam, bound to
`Support\LedgerMeteredAiCall` in `VariablesModuleServiceProvider` (see
`docs/decisions/ADR-0033-ai-cost-meter.md` for the seam's original design record and
`docs/decisions/ADR-0037-ai-cost-limits.md` for the R2 sub-stage 4 $ cutover this section now describes).
Per call:

1. **Gate before spend — DOLLAR-based since R2 sub-stage 4.** If the active workspace's effective monthly $
   cap (`Variables\Services\AiUsageService::cap()` — see `docs/backend/workspace-ai-usage-api.md`) is set
   (`> 0`) and this CALENDAR-MONTH's recorded `estimated_cost` sum already meets it,
   `AiBudgetExceededException` is thrown BEFORE the provider is ever called.
   `LedgerMeteredAiCall::assertWithinBudget()` fails OPEN on a ledger-read error (logged, treated as within
   budget) so a transient DB hiccup can never wedge every AI feature; an effective cap of `0` (the shipped
   default — no workspace override + a `0` env default) disables the gate entirely. **The pre-existing
   `monthly_token_cap` no longer gates anything** — it is retained as telemetry only (see "Pre-run 429
   budget gate" below for the SESSION-level refusal this powers).
2. The provider closure runs.
3. **Record** the spend — a text response's real `prompt_tokens`/`completion_tokens` (via laravel/ai's
   `Usage`), or the channel's configured `unit_cost` for an opaque result's TOKEN telemetry (an image edit /
   a generated image has no provider token count; `ai.meter.unit_cost.ai_image_edit` and
   `ai.meter.unit_cost.ai_image_generate` both default to 4000 — a display figure only, not a price) — plus
   the CHANNEL-AWARE `estimated_cost` ($) computed from `config('ai.meter.pricing.<channel>')` (`ai_text`
   prices per 1k real tokens; the two image channels price flat per call) — as an `AiUsageEvent` row, tagged
   with the ambient `Variables\Support\MeterContext::sessionId()` AND the resolved polymorphic
   `actor_type`/`actor_id` (below).

**Session tagging.** `GenerationSessionExecutor::execute()` / `renderPartFromSnapshot()` /
`renderRefinedPart()` all set `MeterContext::setSession($session->id)` for the WHOLE render scope (cleared
in a `finally`), so every `ai_text`, `ai_image_edit` AND `ai_image_generate` event recorded during that
run/part-op carries `session_id = <this session>` — the ledger `docs/backend/workspace-ai-usage-api.md`'s
per-channel/per-actor summary reads (a per-session spend readout itself is still deferred — see "Planned").

**Actor tagging (R2 sub-stage 4).** The SAME render scope also tags the ambient ACTOR via
`GenerationSession::meterActor()` — the session's human owner, or the delegated BOT when the session carries
a bot-author overlay (ADR-0036) — so every spend the run drives attributes to whoever/whatever actually
authored the content. This is the SAME explicit-tag mechanism `App\Support\Meter\MeterActorResolver`
resolves through (mirrors `HasCreator`'s precedence); see `docs/backend/workspace-ai-usage-api.md` →
"Polymorphic actor attribution" for the full resolution chain and the module-boundary note.

**Two independent budgets stack.** The workspace-wide MONTHLY $ cap (above) is a hard gate shared by every
spender app-wide (Workflows ai-text, Disk AI edits, Generator). ON TOP of it, a session run carries its OWN
PER-RUN call-count ceilings, purely to bound one recipe's fan-out — NOT a dollar/token budget:

| Config key | Default | Scope |
|---|---|---|
| `generator.ai_text_max_calls_per_session` | 4 | `@[ai-text]` provider calls in ONE run (`GeneratorAiTextService`, instance-counted, resets each run) — a `shot_list` generate/refine counts as ONE of these calls too |
| `generator.image_edit_max_calls_per_session` | 8 | `ai_edit` provider calls in ONE run (`ImageChainExecutor`, instance-counted, cumulative across every image part AND every storyboard shot). Also kept in LOCK-STEP with `storyboard_max_shots`: an authored storyboard filter chain runs on EVERY shot, so ONE `ai_edit` filter x a full 8-shot storyboard = 8 edits. Exhausted ⇒ the over-budget filter STEP is skipped (the image is still produced, unfiltered), not a failed part — see "The image chain" above. |
| `generator.image_generate_max_calls_per_session` | 8 | `ai_generate` text→image BASE provider calls in ONE run (`ImageChainExecutor`, instance-counted, cumulative across every image part). Kept in LOCK-STEP with `storyboard_max_shots` (below, also 8) so a FULL `video_script` storyboard's per-shot generates all fit inside one run's budget — if this is ever lowered below the ceiling, the last shots of a long list come back frameless. |
| `generator.storyboard_max_shots` | 8 | The PLATFORM CEILING on shots in one `video_script` run (video_script rework Phase B; adaptive since the narrative-contract upgrades, B1 above) — the REAL cost bound, not a metering ceiling. A template MAY tighten it per recipe (`content.storyboard.max_shots`, see "`storyboard` — one AI image per shot" below); the run's EFFECTIVE cap is `min(authored, ceiling)`, resolved ONCE by `GenerationSessionExecutor::effectiveShotCap()` and threaded explicitly into the shot-list agent's instructed bound, the parse clamp, AND the storyboard's per-shot image iteration — one value, so they cannot drift. BOTH image budgets' defaults are kept equal to the ceiling (the three-way coupling documented in `config/generator.php`). |

An exhausted per-run text budget resolves that `@[ai-text]` block to `''` (fail-closed, the SAME contract
as an over-cap monthly gate — the run still completes `ready`); an exhausted image-edit budget SKIPS the
over-budget filter step and the image part/shot still completes `ok` WITHOUT that filter (it only fails an
image REFINE, as a preserving no-op with `generator.sessions.image_budget`); and an exhausted image-generate
budget fails ONLY that image part/shot (`generator.sessions.image_generate_budget`) — a base cannot degrade
to anything. A `shot_list`'s ONE metered call shares the SAME
text-budget ceiling as any other `@[ai-text]` block — an exhausted budget resolves it blank too, which
`ShotListRenderer` treats identically to a blank provider reply: the `shot_list` part fails soft
(`generator.sessions.part_failed`), and any dependent `storyboard` simply has no shots to iterate
(`{status:'ok', shots:[]}`), never a whole-run failure. A `storyboard` shot's `ai_generate` counts against
`image_generate_max_calls_per_session` like any other base generate; an exhausted budget fails ONLY that
shot (per-shot fail-soft), never the whole storyboard. A regenerate/refine is its OWN claimed run, so it
gets a fresh per-run budget — a single instructed refine is one provider call, well inside either ceiling.

Over the workspace-wide MONTHLY $ cap, a metered image call (`ai_generate` OR `ai_edit`) is stopped BEFORE
spend by `AiBudgetExceededException`; the executor surfaces that as `generator.sessions.image_budget` (a
budget stop, not a bad base/filters) on both the full-run and refine paths. This is the MID-RUN fail-soft
path — see "Pre-run 429 budget gate" immediately below for the distinct, louder refusal that fires BEFORE a
run is even claimed.

`generator.ai_text_max_chars` (default 5000) length-caps a single generated/refined text part — higher than
a workflow field's cap (2000) because a post body is longer than a workflow field.

---

## Pre-run 429 budget gate (R2 sub-stage 4, ADR-0037) — distinct from the mid-run fail-soft above

Everything above (the per-call gate-before-spend, the per-run call-count ceilings) governs what happens
ONCE a run is already claimed and in flight — a budget crossed mid-run fails that ONE block/part
soft, the run still ends `ready`. **A workspace that is ALREADY at/over its effective monthly $ cap BEFORE
a run even starts** gets a separate, louder signal instead: `GenerationSessionRunManager::claimAndDispatch()`
calls `AiUsageService::blocked()` (`cap() > 0 && currentMonthCost() >= cap()` — the EXACT SAME predicate
`docs/backend/workspace-ai-usage-api.md`'s summary `blocked` field reads) and, if true, throws the
renderable `Exceptions\GenerationBudgetExceeded` **BEFORE the atomic claim** — so an already-over-cap
workspace's run is never claimed, dispatched, or partially billed.

```
HTTP/1.1 429 Too Many Requests
{ "code": "ai_budget_exceeded", "message": "<localized, non-secret>" }
```

`claimAndDispatch()` is the SINGLE choke point every session run entry routes through, so the gate lives
ONCE, never duplicated per controller, and covers all four run entry points identically:

| Endpoint | 429 fires when |
|---|---|
| `POST /generator/sessions/{session}/generate` | the workspace is already over cap when the whole-session generate is requested |
| `POST /generator/sessions/{session}/parts/{partKey}/regenerate` | same, for a per-part regenerate |
| `POST /generator/sessions/{session}/parts/{partKey}/refine` | same, for a per-part refine |
| `POST /bots/{bot}/sessions/{session}/delegate` with `auto_generate: true` | same, for the delegation's optional auto-run (the delegation's own slot-fill AI call is a SEPARATE, ordinary metered `ai_text` spend — see below, not this gate) |

**The bot delegation's autonomous slot-fill call is NOT gated by this 429** — it goes through the ordinary
metered `ai_text` seam like any other spend (`BotSlotFillService::fill()` → `AiTextGenerationService::
generateWith()`), so an already-over-cap workspace's delegation fill is FAIL-CLOSED EMPTY (the same
`catch (Throwable)` every `@[ai-text]` block already uses — the session stays `draft`/`ready` with nothing
filled) rather than a 429. Only the FOUR run entry points in the table above get the pre-run refusal.

**Byte-preserving when the cap is off.** `blocked()` is `false` whenever the effective cap is `<= 0` (the
shipped default) — the gate is a pure no-op for every workspace that has not opted into a $ budget, exactly
like the pre-existing mid-run gate.

See `docs/decisions/ADR-0037-ai-cost-limits.md` (D4/D5) for the full rationale — why BOTH mechanisms exist
and stay, and why the pre-run gate was scoped to Generator session runs specifically (not Workflows/Disk).

---

## Lifecycle: reaper + archive-as-freeze (R2 sub-stage 2d)

Housekeeping is separate from the run state machine — `Services\GenerationSessionLifecycleService`, swept
by the scheduled `generator:reap-sessions` command (`Console\ReapGenerationSessionsCommand`,
**every 5 minutes**, `withoutOverlapping()` — `routes/console.php`). One pass runs on the shared connection
(unscoped — covers every shared-mode workspace) and once per READY own-database workspace (one broken
tenant is logged and skipped, so the sweep still finishes for the rest).

Three windows, each with a config-driven cutoff and a hard 60s floor so a misconfiguration can never
reap/purge instantly:

| Step | Config key | Default | Effect |
|---|---|---|---|
| **Stale recovery** | `generator.session_stale_after` | 1800s (30 min) | A session stuck in `generating` past the cutoff → `failed` via the run manager's terminal-safe `fail()` (a run that completes in the reap race is never clobbered). Recovers a worker-SIGKILL/OOM that never reached the job's `failed()` hook — nothing else does. Must EXCEED the job's whole retry/lock budget (`tries=1`, `timeout=300s`, `WithoutOverlapping` up to 600s) so a slow-but-alive run is never reaped. |
| **Trash** | `generator.session_trash_after` | 604800s (~1 week) | A NON-archived session idle (`updated_at`) past the cutoff → soft-deleted. Any activity (edit/refine) bumps `updated_at`, so an actively-used session is never trashed. |
| **Purge** | `generator.session_purge_after` | 2592000s (~1 month) | A soft-deleted, NON-archived session whose trash (`deleted_at`) is past the cutoff → **force-deleted** AND its whole produced-image blob prefix is garbage-collected (`GeneratedImageStore::clearSessionForWorkspace()`, derived from the row's own `workspace_id` since the shared-DB pass runs with tenancy cleared). |

**Archive is a blanket freeze**, not merely a trash exemption: `POST …/archive` sets `archived_at`, which
excludes the session from BOTH the trash and purge scopes (`scopeTrashable`/`scopePurgable` both
`whereNull('archived_at')`). Stale-recovery is likewise skipped for an archived session
(`scopeStaleGenerating` also excludes it) — an archived session is never auto-failed either, matching the
"archive disables all cleanup" contract. `POST …/unarchive` re-enrolls it in every window.

---

## Related files

- `app/modules/Generator/Models/GenerationSession.php` — the model + the reaper's three query scopes
- `app/modules/Generator/Enums/GenerationSessionStatus.php`, `Enums/GenerationRunMode.php`
- `app/modules/Generator/Services/GenerationSessionService.php` — CRUD + archive/unarchive
- `app/modules/Generator/Services/GenerationSessionRunManager.php` — claim/dispatch/run/fail, parameterized by mode
- `app/modules/Generator/Services/GenerationSessionExecutor.php` — the whole-session + per-part-op renderer
- `app/modules/Generator/Services/GenerationSessionRefiner.php` — history push/pop, undo, blob GC bookkeeping
- `app/modules/Generator/Services/GeneratorAiTextService.php` — the live, budgeted `@[ai-text]` implementation
- `app/modules/Generator/Services/ImageChainExecutor.php`, `ImageBaseResolver.php`, `ImagePixelProcessor.php`, `GeneratedImageStore.php`
- `app/modules/Generator/Services/GenerationSessionLifecycleService.php`, `Console/ReapGenerationSessionsCommand.php`
- `app/modules/Generator/Jobs/RunGenerationSessionJob.php`
- `app/modules/Generator/Http/Controllers/GenerationSessionController.php`
- `app/modules/Generator/Http/Requests/StoreGenerationSessionRequest.php`, `UpdateGenerationSessionRequest.php`, `RefineSessionPartRequest.php`, `SaveGeneratedImageRequest.php`
- `app/modules/Generator/Http/Resources/GenerationSessionResource.php`
- `app/modules/Generator/Policies/GenerationSessionPolicy.php`
- `app/modules/Generator/DTOs/CreateGenerationSessionDTO.php`, `UpdateGenerationSessionDTO.php`
- `app/modules/Generator/Exceptions/GenerationBudgetExceeded.php` — the pre-run 429 gate refusal
- `app/modules/Variables/Support/LedgerMeteredAiCall.php`, `MeterContext.php`, `Contracts/MeteredAiCall.php`
- `app/modules/Variables/Services/AiTextGenerationService.php`, `AiUsageService.php`
- `app/modules/Variables/Models/AiUsageEvent.php`, `Exceptions/AiBudgetExceededException.php`
- `app/Support/Meter/MeterActorResolver.php` — the polymorphic actor attribution (mirrors `HasCreator`)
- `docs/backend/workspace-ai-usage-api.md` — the `/ai-usage` endpoint contract + the $ cap/pricing model
- `docs/decisions/ADR-0037-ai-cost-limits.md` — the R2 sub-stage 4 design record ($ gate cutover, per-workspace
  cap, actor attribution, pre-run 429)
- `tests/Feature/SessionAiBudgetGateTest.php` — the pre-run 429 across all four run entry points
- `app/modules/Variables/Agents/AiTextAgent.php`, `Enums/AiPersona.php`
- `config/generator.php` — all session/run/reaper knobs (incl. `image_edit_max_calls_per_session`,
  `image_generate_max_calls_per_session`); `config/ai.php` — the `meter` block (token-cap gate +
  `unit_cost.ai_image_generate`) and the text→image knobs `image_generate_provider` / `image_generate_quality`
- `app/modules/Disk/Services/ImageGenerateService.php` — the metered text→image generate seam (channel `ai_image_generate`)
- `app/modules/Generator/Services/ImageChainExecutor.php` — intercepts the `ai_generate` base; `Exceptions/ImageGenerateBudgetExceeded.php`
- `database/migrations/2026_07_30_000000_create_generation_sessions_table.php`,
  `2026_07_31_000000_add_last_op_columns_to_generation_sessions_table.php`,
  `2026_07_29_000000_create_ai_usage_events_table.php`,
  `2026_08_03_000000_add_creative_direction_to_generation_sessions_table.php` (+ `database/migrations/tenant/` mirrors)
- `tests/Feature/GenerationSessionCrudTest.php`, `GenerationSessionGenerateTest.php`,
  `GenerationSessionLifecycleTest.php`, `GeneratedImageServeAndSaveTest.php`, `AiCostMeterTest.php`
- `resources/js/next/pages/generator/sessionTypes.ts` — the wire contract, mirrored 1:1
- `resources/js/next/pages/generator/session/` — `SessionChatView.vue`, `SessionComposer.vue`,
  `SessionResultCard.vue`, `SessionTurn.vue`, `SessionSlotSetupCard.vue`, `SessionPartImage.vue`,
  `SessionSaveToDiskModal.vue`, `FinalPostPane.vue`, `FinalPostBody.vue`, `sessionGating.ts`,
  `sessionStatus.ts`, `sessionImages.ts`, `DelegateBotDialog.vue`, `BotAuthorChip.vue`,
  `SessionFillReportPanel.vue` (R2 sub-stage 3 delegation UI)
- `resources/js/next/pages/generator/SessionsView.vue`, `SessionRow.vue`, `TemplatePickerModal.vue`
- `resources/js/next/app/stores/sessions.ts` — CRUD + `fetchSession()`; the websocket-settle wait lives in
  `resources/js/next/pages/generator/session/useSessionSettle.ts` (no polling)
- `app/modules/Generator/Services/ShotListRenderer.php` — the structured shot_list generate/revise + defensive parse
- `app/modules/Generator/Agents/ShotListAgent.php` — the JSON-contract system instruction (prompt-and-parse, not native structured output); the adaptive shot count + degrading STORY block (B1)
- `app/modules/Generator/Services/CreativeDirectionService.php` — derives the ONE creative direction per full run, from the no-op-previewed authored recipe
- `app/modules/Generator/Agents/CreativeDirectionAgent.php` — the direction-derivation system instruction (extract-before-invent)
- `app/modules/Generator/Support/CreativeDirection.php` — the normalized VO + the security boundary (`fromArray()`) + the per-consumer projections (`forText`/`forShotList`/`forImage`)
- `app/modules/Generator/Support/CreativeDirectionContext.php` — the ambient holder (Generator-owned twin of `AiVoiceContext`), consumed by `GeneratorAiTextService`
- `app/modules/Generator/Support/JsonObjectExtractor.php` — the shared fence-strip + balanced-brace JSON object scan `ShotListRenderer` and `CreativeDirectionService` both use
- `app/modules/Variables/Agents/AiTextAgent.php` — `$lengthGuidance` (B1; null ⇒ every Workflows path byte-identical)
- `docs/decisions/ADR-0038-creative-direction-layer.md` — the design record for B1 (narrative contract upgrades) + B2 (the creative direction layer)
- `app/modules/Variables/Services/VariableResolver.php` — the `parts` root (`ROOTS`) + `collectReferenceIds()`, the shared scanner the write gate and the staleness scan both delegate to
- `app/modules/Generator/Services/TemplateSlotValidator.php` — `validateCrossPartReferences()`, the earlier-only write gate
- `app/modules/Generator/Services/TemplateVariableCatalog.php` — `partVariables()`, the `parts.<earlierKey>` catalog entries
- `app/modules/Generator/Services/ContentTypeRegistry.php` — `partKeysBefore()` (the earlier-only scope), `legacyParts()`/`partsForSnapshot()` (snapshot-authoritative back-compat for `script`/`scene_plan`)
- `docs/decisions/ADR-0033-ai-cost-meter.md` — the D7 ledger seam + ai-text down-move design record
- `docs/decisions/ADR-0034-generation-sessions.md` — the sessions engine design record (2b–2d)
- `docs/decisions/ADR-0035-video-script-cross-part-storyboard.md` — the video_script rework design record: cross-part `parts.*` context (Phase A) + structured `shot_list`/`storyboard` (Phase B)
- `docs/decisions/ADR-0036-bot-delegation-generation-sessions.md` — the bot-delegation design record: author overlay (not creator), opaque voice seam, autonomous slot-fill, the one-way Bot→Generator edge, reversible undo
- `app/modules/Generator/Services/SessionDelegationService.php` — the bot-agnostic Generator-side delegation seams (introspectSlots/applyBotSlotValues/applyDelegation/clearDelegation)
- `app/modules/Bot/Http/Controllers/BotSessionDelegationController.php`, `Http/Requests/DelegateBotSessionRequest.php`, `Enums/SlotFillMode.php` — the delegate/undo endpoints + the `gaps`/`fresh` fill choice (Bot module, see `docs/backend/bots-api.md`)
- `app/modules/Bot/Services/BotSlotFillService.php`, `BotVoiceComposer.php`, `Agents/BotSlotFillAgent.php` — the autonomous slot-fill call + voice composition
- `app/modules/Variables/Support/AiVoiceContext.php` — the ambient voice-directive holder (mirrors `MeterContext`); read by `AiTextGenerationService::generate()` and `ShotListAgent`
- `docs/backend/generator-api.md` — the Template (definition) contract sessions are cut from
- `docs/backend/disk-api.md` — the `File` model / create path "Save to Disk" writes through
- `docs/backend/bots-api.md` — the Bot-module side of the delegation edge
- `docs/product/plan-dzialania.md` — R2 roadmap (sub-stage sequencing)
- `tests/Feature/CrossPartContextTest.php` — earlier-only/acyclic write validation + nested-reference detection + staleness marking (directive / ai-text / if-block / flat token) + catalog scoping
- `tests/Feature/ShotListRendererTest.php` — the defensive JSON parse (fenced/bare-array/malformed/blank), the whole-run generate + refine, budget interaction
- `tests/Feature/StoryboardTest.php` — the per-shot fan-out + fail-soft, per-shot regenerate/refine/undo, storyboard staleness, the legacy script/scene_plan snapshot back-compat pin
- `tests/Feature/BotSessionDelegationTest.php` — delegate/undo happy paths, overlay snapshot/restore, re-delegation overwrite, ownership/state guards (403/404/409/422), `can_delegate`/`can_undo_delegation` matrix, auto_generate opt-in, the `fill_mode` matrix (gaps byte-preserves a human value + refuses an already-filled proposal; no-gap = zero AI calls; fresh replaces everything and asks for a different take; two fresh fills send different prompts; undo-after-fresh)
- `tests/Feature/ShotListVoiceTest.php` — the delegated voice reaching `ShotListAgent`'s tone clause without relaxing the JSON contract
- `tests/Feature/CreativeDirectionTest.php` — derive-once-per-run, the kill-switch byte-identical pin, fail-soft on a bad model reply, the security-boundary scrub (fence-marker forgery), lifecycle (null on full claim / preserved on part-op claim), voice-wins-on-tone
- `tests/Unit/Variables/AiVoiceContextTest.php` — the ambient directive holder's set/clear contract
- `tests/Feature/GeneratorModuleBoundaryTest.php`, `tests/Feature/BotModuleBoundaryTest.php` — pin the one-way `Bot → Generator/Variables` delegation edge in both directions
- `app/modules/Generator/Services/SessionAutomationService.php` — the R2 sub-stage 5 automation seam (createFromTemplate/terminalStatusFor)
- `app/modules/Generator/Services/SessionContentProjector.php` — assembles a ready session's results into one text string, the server-side sibling of `FinalPostBody.vue`
- `app/modules/Generator/Services/GeneratedImageExporter.php` — extracted from `saveToDisk`, `saveToDiskIfPresent`/`producedImagePartKeys` added for a non-HTTP caller
- `app/modules/Generator/Enums/SlotScopePolicy.php` — `Automation` case (allows a scalar `file` slot; `Bot` still refuses every file slot)
- `app/modules/Workflows/Steps/GenerateContentStep.php` — the one Workflows→Generator edge; see `docs/backend/workflows-api.md` → "Steps: `generate_content`"
- `docs/decisions/ADR-0039-workflow-suspend-resume-and-generate-content.md` — the R2 sub-stage 5 design record: the suspend/resume engine, the automation seam, the composite-slot refusal, the `SlotScopePolicy` divergence from ADR-0036
- `tests/Feature/WorkflowGenerateContentStepTest.php`, `tests/Feature/WorkflowSuspendResumeTest.php`, `tests/Feature/WorkflowSuspendResumeHardeningTest.php`, `tests/Feature/WorkflowsGeneratorBoundaryTest.php` — the step's run/resume/fill/export behavior, the suspend/resume engine's correlated-claim/fingerprint/timeout hardening, and the one-way module boundary

## Planned / deferred (not implemented)

- **Bot autonomy beyond slot-fill** (R2 sub-stage 3 follow-up) — a delegated bot fills in-scope slots once,
  at delegation time; it does not initiate its own regenerate/refine, and has no ongoing "check in on this
  session" behavior.
- **File / deep-composite slot bot-fill** (R2 sub-stage 3, deliberately deferred — see
  `docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`, D-E) — a `file`-based slot or an
  `array<object>`/deep composite is NEVER offered to the autonomous slot-fill (a bot must never forge a Disk
  reference); a required one always surfaces in `unfilled_required_slots` for the human to complete.
- **Delegation feeding Approvals or Publishing** — a delegated session's content is not yet wired into the
  approvals pipeline or a future publish step; delegation today only affects HOW a session's own content is
  authored, not what happens to it afterward.
- ~~**The `generate_content` workflow step** — a `Workflow` consuming a Template/Session to produce content
  is not modeled.~~ — **DONE (R2 sub-stage 5)**, see "Automation seam (R2 sub-stage 5)" above and
  `docs/backend/workflows-api.md` → "Steps: `generate_content`" / "Suspend/resume engine". Kept struck
  through so a reader of an older snapshot understands the change.
- **A bot delegating a workflow-driven generation** — `SlotScopePolicy::Bot` (R2 sub-stage 3) and
  `SlotScopePolicy::Automation` (R2 sub-stage 5) are sibling trust boundaries today, not composed; a
  `generate_content` step's session cannot be handed to a bot mid-run.
- **Per-part granular `generate_content` outputs** — the step publishes one assembled `content` string and
  one `image_file_ids` list; a later workflow step cannot address one specific part's text/image
  individually.
- **Redo** — undo is one-directional (the just-undone version's blob is deleted, not merely hidden); there
  is no "redo the undo".
- **A per-session spend cap / kill-switch** distinct from the per-run call-count ceilings above — today only
  the workspace-wide monthly $ cap (R2 sub-stage 4, `docs/backend/workspace-ai-usage-api.md`) is a real
  budget; the per-run ceilings bound fan-out, not cost. A per-session spend READOUT (the `session_id` tag
  already rides every ledger row) is likewise not surfaced anywhere yet.
- **Usage history / trend, and a pre-run cost estimate** (R2 sub-stage 4 follow-ups — see
  `docs/backend/workspace-ai-usage-api.md` → "Planned / deferred") — the usage summary is current-month
  only, and the pre-run 429 gate only refuses when ALREADY over cap, never forecasts a specific run's cost
  before it starts.
- **Extending the pre-run 429 gate to Workflows/Disk** — only Generator session runs get the pre-run 429
  today; those two spenders keep only the pre-existing mid-run fail-soft (see ADR-0037 consequences).
- **Native structured output for `shot_list`** — `laravel/ai` v0.4.3's `HasStructuredOutput` is available but
  deliberately not used (see `docs/decisions/ADR-0035-video-script-cross-part-storyboard.md`, D6); adopting
  it would need its own metered-call wiring alongside the existing `ai_text` seam and would not remove the
  need for a defensive parse either way.
- **A structured (non-text-only) `parts.*` cross-part root** — today only a rendered STRING contributes; a
  later part reading an earlier part's structured data (e.g. an `image_plan`'s metadata) would need a new,
  separate reference/type-flow design (see ADR-0035's "Alternatives considered").
