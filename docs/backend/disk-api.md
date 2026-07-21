# Backend API: Disk (Files & Resources) module

Module: `app/modules/Disk/`
Auth: every endpoint requires `auth:sanctum` **and** the `X-Workspace-Id` header. Disk binaries
are workspace-owned, so the routes are additionally wrapped in `RequireWorkspace` — without an
active tenant the query would be unscoped and any authenticated user could read another
workspace's bytes by id (`app/modules/Disk/routes/api.php`).
Tenant scope: `File` and `Folder` use `TenantAware` — all queries are scoped to the active
workspace, and route-model binding resolves a foreign id to a 404 (see ADR-0016 §8).

Covers R1 B0–B8: hardened binary serving (B0), the folder tree + file domain (B1–B3), the
read-only "Zasoby" virtual tree (B3b), the FILE form element + workflow variable (B4/B4c), the
disk browser's write actions (B6), the file drawer + history (B7), the image editor (B7b), and
"pick from Disk" (B8); plus the post-R1 **unified folder browse** (`GET /disk/items/{folder?}`, path
URLs), the **full-page preview** (content overwrite, comments, an **async, mask-capable AI image
edit** — F2, now with a synchronous **AI text edit** sibling and a Reverb **realtime completion
push** alongside its poll), **PDF grid thumbnails** for the file browser, and **per-user autosave
drafts** so the preview editor survives a refresh/crash. The design rationale lives in
[`docs/decisions/ADR-0016-disk-resources.md`](../decisions/ADR-0016-disk-resources.md),
[`docs/decisions/ADR-0017-unified-folder-browse.md`](../decisions/ADR-0017-unified-folder-browse.md),
and [`docs/decisions/ADR-0020-file-preview-editing.md`](../decisions/ADR-0020-file-preview-editing.md).

---

## Concepts

### A file is in exactly one of three states

`files` is one table (`App\Modules\Disk\Models\File`); a file's **container** is its polymorphic
`fileable`, and `fileable_type` alone decides what a row *is* (see
[ADR-0018](../decisions/ADR-0018-file-container-is-fileable.md)):

| State           | `fileable_type`            | `fileable_id`               | Shows in the browser? | Lifecycle owner |
|-----------------|----------------------------|-----------------------------|-----------------------|-----------------|
| **Temp**        | `null`                     | `null`                      | No                    | Nobody yet — swept after 48 h if never claimed |
| **Disk file**   | `'folder'`                 | a folder, or `null` = root  | Yes (`source=disk`)   | The disk (its uploader) |
| **Resource file** | a resource morph (`task`, `form_report`, `form_submission`, …) | the owning record | Only under "Zasoby" | The owning module |

`File::scopeTemp` = `fileable_type IS NULL`; `File::scopeDiskNative` = `fileable_type = 'folder'`.
There is no `folder_id` column — `folder_id` in the API is a **virtual** read of `fileable_id`
(when the container is a folder), so the contract is unchanged while placement lives in `fileable`.

### Disk trash ≠ soft delete

Detaching a task attachment already soft-deletes its `File` (`FileService::detach`). The disk's
trash must show only what was thrown away **from the disk**, so trashing stamps a **separate**
marker, `disk_trashed_at`, in addition to the soft delete:

- `File::scopeDiskTrashed` = `disk_trashed_at IS NOT NULL`.
- The disk trash view (`?trashed=1`) is `withTrashed()->diskTrashed()` — a detached attachment
  (soft-deleted, `disk_trashed_at` null) never appears there.

### The "Zasoby" (Resources) virtual tree

Resource files (attachments, report outputs) do **not** appear in the folder browser
(`source=disk` filters them out). They are browsed only under a **read-only, synthetic** tree:
`Zasoby → resource type → created-at bucket → files`. Its nodes carry `sys:` ids that no folder
query can ever resolve, so they can never be mutated as folders (`ResourceFolderRegistry`).

### Folders are a materialized path (ancestors only)

`folders.path` encodes the ancestor chain as `'/{ancestorUuid}/…/'` — the folder's **own** id is
never in its path (it is already the row's primary key). Consequences:

- A subtree move is **one** anchored `UPDATE` (re-prefix every descendant's path).
- **Breadcrumbs are the ANCESTORS only** — the folder itself is not in them. A client wanting a
  trail that ends at the open folder must append the folder to the breadcrumbs (the frontend
  store and the restore dialog both do this; see ADR-0016 §4).
- The raw `path` is internal and **never** exposed; the resource returns `ancestor_ids` instead.
- Max depth is **10** (guarded on create and after a move, across the whole subtree).

### Label governance (enforced / recommended)

A folder can **govern** labels for the files beneath it (F3), via its own `folder_label` pivot
(`{ folder_id, label_id, mode }`, `mode ∈ enforced | recommended`) — deliberately **not** the shared
`labelables` pivot, because a folder declares labels for its contents rather than being tagged
itself. Two modes:

- **Enforced** — MATERIALIZED onto every file in the subtree as real `labelables` rows marked
  `enforced = true`, so the label filter / resource / changelog keep working unchanged; an enforced
  label is simply one the user cannot remove (`locked` in `FileResource.labels`). A file's target
  enforced set is the **union of every ancestor folder's** enforced labels — a **full recompute**
  (`FolderLabelEnforcer`), so "un-enforce here while a parent still enforces" resolves correctly.
  Recomputed on: a folder's governance edit, a subtree move, and file store / move / restore / copy.
- **Recommended** — a NEW uploaded file is seeded with its **immediate** folder's recommended labels
  as **manual** (`enforced = false`, removable) rows. A NEW **subfolder** copies its parent's
  recommended labels as its OWN (an adjustable starting point). Recommendations do not otherwise
  cascade from ancestors.

Enforced labels also apply to **subfolders** — but by DERIVATION, not materialization: a folder is
never label-filtered, so its inherited-enforced set (the enforced labels of any ancestor) is computed
at read time and returned as `locked` labels on the show/edit payload. So enforcing a label on a
folder stamps it on every descendant file (materialized) AND every descendant folder (derived,
locked) — including subfolders that already existed, with no propagation. A descendant can never
un-enforce what an ancestor enforces.

`FileService::syncLabels` never touches `enforced` rows — a metadata edit can neither strip an
enforced label nor add one. The rationale lives in
[ADR-0019](../decisions/ADR-0019-materialized-folder-labels.md).

### Capability flags are server-authoritative

Every resource carries `can_be_*` booleans derived from the Policy. The frontend hides what a
user may not do; the Policy still enforces it. **Trash actions gate on `can_be_restored` /
`can_be_force_deleted`, never on `can_be_deleted`** — the trash lists every member's items but a
non-owner may only restore/purge their own (ownership via `HasCreator`, see ADR-0015).

---

## Resource shapes

### FileResource

Shared **verbatim** with the tasks/report frontends (the `TaskAttachment` type, the report card).
`path` is the **download URL** (route `disk.show`), not the storage path; `created_at` is the
legacy display string. The disk's own fields ride under the `*_iso` / `disk_*` keys — strictly
additive.

```json
{
  "id": "uuid",
  "name": "string",
  "path": "https://…/api/disk/{id}",        // download/serve URL, NOT the storage path
  "type": "image | video | audio | document | spreadsheet | archive | another",
  "size": 12345,
  "size_human": "12.06 KB",
  "created_at": "Y-m-d H:i",                 // legacy display string

  "description": "string | null",
  "mime_type": "string | null",
  "folder_id": "uuid | null",                // null = workspace root
  "folder": { /* FolderResource, when loaded */ },
  "labels": [ /* { id, name, color, description, icon, locked } — when loaded */ ],

  "source": "disk | task | form_report | form_submission | …",  // 'disk' when the container is a folder (or none), else the morph
  "created_at_iso": "ISO 8601 | null",
  "updated_at_iso": "ISO 8601 | null",
  "disk_trashed_at": "ISO 8601 | null",

  "can_be_updated": true,
  "can_be_moved": true,
  "can_be_deleted": true,
  "can_be_restored": false,
  "can_be_force_deleted": false,

  "has_draft": false                         // index only — the current user has an autosave draft
}
```

- `source` is the browser's origin facet: `'disk'` for a disk-native file, else the owning
  resource's morph alias.
- A **resource file** (`source !== 'disk'`) may be renamed / described / tagged from the disk but
  never moved or trashed (`can_be_moved` / `can_be_deleted` are `false`) — its lifecycle belongs
  to the owning module.
- Each label's `locked` is `true` when it was **materialized by a folder's enforced governance**
  (F3): the UI shows a lock instead of a remove control and it cannot be detached by hand — see
  [Label governance](#label-governance-enforced--recommended).
- `has_draft` is populated **only by the files index** (the grid list): `true` when the CURRENT
  user has an autosave draft for the file (per-user, computed via a `withExists` subquery — the
  draft endpoints are documented below). It is `false` on every single-file / upload / replace /
  items response, so the grid is its sole consumer.

### FolderResource

```json
{
  "id": "uuid",
  "name": "string",
  "description": "string | null",            // F2 — free text
  "icon": "string | null",                   // F2 — a FE icon-name hint, rendered inside the folder glyph
  "parent_id": "uuid | null",
  "depth": 0,
  "ancestor_ids": ["uuid", "…"],            // root first; the raw path is never exposed
  "has_children": true,                      // whenCounted('children') only
  "children_count": 3,                       // whenCounted('children') only
  "files_count": 5,                          // whenCounted('files') only
  "labels": [ /* { id, name, color, icon, mode, locked } — when loaded (show/edit path) */ ],
  "created_at": "ISO 8601 | null",
  "updated_at": "ISO 8601 | null",
  "deleted_at": "ISO 8601 | null",
  "can_be_updated": true,
  "can_be_moved": true,
  "can_be_deleted": true,
  "can_be_restored": false
}
```

- `description` / `icon` are the folder's own metadata (F2). `icon` is a **FE icon-name string**
  (rendered by the client's icon set), not a server enum — an unknown name simply renders as no icon.
- `labels` (governance) appears **only** on the `show` / edit payload (eager-loaded), never on the
  lean grid rows. Each carries `mode` (enforced | recommended) and `locked`: the folder's OWN
  declarations (`locked: false`, editable) PLUS the enforced labels it INHERITS from ancestors
  (`locked: true`, `mode: enforced`) — so an enforced label applies to subfolders exactly as it does
  to files. See [Label governance](#label-governance-enforced--recommended).
- A folder implements `HasChangelog`, so its history is at `GET /folder/{id}/changelog` (the same
  generic endpoint as files) — name / description / icon / label edits are tracked.
- The `*_count` / `has_children` keys appear **only** when the caller loaded the counts
  (`loadCount`). The index and show endpoints do; the rename/move responses do **not** — a client
  merging a rename response must not overwrite an existing `files_count` (see ADR-0016 §7).
- "N items" in the browser = `children_count + files_count` (a folder holding only subfolders is
  not "0 items").

### Resource tree (`GET /disk/resources`)

```json
{
  "data": [
    {
      "id": "sys:res:task",
      "type": "task",
      "label_key": "disk.resources.task",
      "icon": "list-checks",
      "files_count": 12,
      "buckets": [
        { "id": "sys:res:task:2026-07", "key": "2026-07", "granularity": "month", "files_count": 5 }
      ]
    }
  ]
}
```

Only resource types that actually have files appear. Bucket keys are `YYYY` / `YYYY-MM` /
`YYYY-MM-DD` per the type's granularity, grouped in PHP over a **half-open UTC** range.

### Restore preview (`GET /disk/{id}/restore-preview`)

```json
{
  "data": {
    "file": { /* FileResource */ },
    "original_folder": { /* FolderResource | null */ },
    "breadcrumbs": [ /* FolderResource[] — ancestors of original_folder, root first */ ],
    "can_restore_in_place": false,
    "reason": "detached | folder_missing | folder_trashed | null"
  }
}
```

### File history (`GET /file/{id}/changelog`)

The **generic** changelog endpoint (`App\Modules\Changelog`), keyed on the `file` morph alias;
`File` implements `HasChangelog`. Cursor-paginated, wrapped in
`{ "data": [...], "meta": { "next_cursor": "string | null" } }`.

---

## Endpoints — files

All under `/api/disk`. Literal segments (`temp`, `folders`, `resources`, `ai/image`, `ai/text`) are
declared before the `{file}` wildcard and every id segment is `whereUuid`, so a literal is never
read as a file id.

### GET /api/disk/items · GET /api/disk/items/{folder}  *(the folder browse)*

The **one** endpoint the browser uses for a folder's contents: its subfolders **and** its
disk-native files as a SINGLE cursor-paginated list — folders first, then files — plus the folder's
breadcrumbs. The folder rides the **path** (`/items` = the workspace root, `/items/{folder}` = a
uuid). Replaces the old pair of `?parent_id=` (folders) + `?folder_id=&source=disk` (files) calls.

Backed by `App\Support\Pagination\StagedCursorPaginator` (folders = stage 0, files = stage 1), so
one `?cursor=` walks both tables as one list — see
[ADR-0017](../decisions/ADR-0017-unified-folder-browse.md).

```json
{
  "data": [
    { "kind": "folder", /* …FolderResource… */ },
    { "kind": "file",   /* …FileResource…   */ }
  ],
  "links": { "…": "…" },
  "meta": { "next_cursor": "string | null", "prev_cursor": null, "per_page": 24 },
  "folder": { /* FolderResource of the open folder, null at the root */ },
  "breadcrumbs": [ /* FolderResource[] — the folder's ANCESTORS, root first; append `folder` for a full trail */ ]
}
```

- Each item is a `DiskItemResource`: the existing `FolderResource`/`FileResource` shape verbatim
  with a `kind` (`folder` | `file`) prepended. The client splits the one list back by `kind`.
- Cursor page size **24**, folders-first ordering; `prev_cursor` is always `null` (forward-only).
- `authorize('viewAny', File::class)`; a foreign/trashed `{folder}` 404s at binding.

**Filters (F1)** — query params, all optional, parsed by `DiskItemFilters::fromRequest` (an invalid
value clamps to its default, never a 422):

| Param            | Values                                             | Effect |
|------------------|----------------------------------------------------|--------|
| `types[]`        | `FileType` values **+ `folder`**; empty = everything | Narrows the files stage (`whereIn type`); **omits the folders stage** unless `folder` is present. |
| `q`              | search term                                        | `Searchable` scope on both stages. |
| `search_in`      | `name` (default) · `name_description`              | Files also match `description` when `name_description` (folders always name-only). |
| `search_where`   | `folder` (default) · `subtree` · `everywhere`      | Scope: this folder · this folder + descendants (`path LIKE`) · the whole disk. Subtree/everywhere carry each item's `folder` so the UI shows where a hit lives. |
| `sort`           | `name` (default) · `created_at`                    | Each stage's `orderBy`, still ending in the primary key (the paginator's total-order contract). |
| `dir`            | `asc` · `desc`                                     | Defaults per sort: `name → asc`, `created_at → desc`. |

### GET /api/disk/{file}/info  *(preview metadata)*

The file's metadata as JSON (`FileResource` with `labels` + `folder` loaded) — the full-page
preview's deep-link/refresh source. Deliberately separate from the bare `GET /{file}`, which
serves the BINARY. `authorize('view')`; `disk-read` throttle bucket.

### GET /api/disk/{file}/thumbnail  *(PDF grid thumbnail)*

A small first-page PNG raster of a **PDF** — the file grid's real thumbnail instead of a type
glyph. `authorize('view')`; `disk-read` throttle bucket (a browsing grid lazy-loads one per PDF
tile).

- Rendered server-side via poppler's `pdftoppm`, invoked through Symfony `Process` with ARRAY
  args — never a shell — so the stored file is never interpolated into a command line
  (`PdfRasterizer` / `PopplerPdfRasterizer`). Cached on the tenant Storage disk at a stable,
  per-file path, `disk-thumbs/{workspaceId}/{fileId}.png` (`ThumbnailService`) — served straight
  from cache on every request after the first. Invalidated (`ThumbnailService::forget`) whenever
  the file's bytes change or it disappears: content-replace (`POST /{file}/content`),
  force-delete, and temp GC.
- **404** — never a 500 — when the file isn't a PDF, thumbnails are disabled
  (`config('disk.thumbnails.enabled')`), or rendering is unavailable/fails (poppler missing, a
  pathological PDF past the timeout, a row whose blob is missing); also 404 for a
  foreign-workspace `{file}` (route-model binding). **403** non-member; **400** missing
  `X-Workspace-Id` (refused before the binding resolves).
- Response headers: `Content-Type: image/png`, `Cache-Control: private, max-age=86400`,
  `X-Content-Type-Options: nosniff`.
- **Runtime dependency**: `pdftoppm` (poppler) must be installed on the server and reachable —
  on `PATH`, or pointed at via `DISK_PDFTOPPM_PATH`. When it's absent (or the config switch is
  off) the endpoint simply 404s and the UI falls back to the type glyph, exactly like any other
  unsupported format — there is no hard failure.
- PDF-only for now; the service is deliberately shaped to add a video branch later
  (`ThumbnailService::isSupported`).

**Config** (`config/disk.php`): `DISK_THUMBNAILS_ENABLED` (default `true` — master switch),
`DISK_PDFTOPPM_PATH` (default `pdftoppm`), `DISK_THUMBNAIL_SCALE` (`480`px, the rendered PNG's
longest edge, `pdftoppm -scale-to`), `DISK_THUMBNAIL_TIMEOUT` (`20`s per render — a pathological
PDF is abandoned rather than tying up the request).

### POST /api/disk/{file}/content  *(preview "Zapisz" — overwrite)*

Replace the file's CONTENT in place. Multipart `file` (same 100MB cap as upload; POST because PHP
only parses multipart on POST). The row keeps its IDENTITY — `name`, `description`, placement
(`fileable_*`), labels, uploader, `created_at` are untouched; only the blob and the
content-derived columns (`path` → a fresh uuid under the workspace prefix, `mime_type`, `size`,
`type`) change, recomputed from the ACTUAL bytes (never the client filename). The old blob is
deleted after the swap commits (orphan-tolerant, never inside the transaction).

- `authorize('update')` + **disk-native only**: a resource-owned file (task attachment, report
  output) 422s with `disk.validation.file_owned_by_resource` — its bytes belong to the owning
  module; the preview offers "Zapisz jako" (a new disk file via `POST /disk`) instead.
- Audited as a **`content_replaced`** changelog entry
  (`details.content = { size, previous_size, mime_type }`; description key
  `changelog.contentReplaced`).
- Response: the fresh `FileResource`. `disk-upload` throttle bucket.

### POST /api/disk/ai/image · GET /api/disk/ai/image/{id}  *(preview AI image edit — async)*

Edit an image with AI, optionally confined to a brushed **mask**. The provider call is slow (tens
of seconds) and billed, so the edit is **queued** rather than run inline: POST validates and
dispatches, returning immediately; the client polls GET until the result (or a failure) lands.
Nothing is written to the disk here — the edited image returns to the client's canvas and only
becomes a saved `File` through the normal save endpoints (`POST /disk/{file}/content` or
`POST /disk`). Rides a custom OpenAI `images/edits` client (`OpenAiImageEditClient`) rather than
laravel/ai, which cannot send a mask — see
[ADR-0020 §7](../decisions/ADR-0020-file-preview-editing.md).

**POST — dispatch.** Multipart `image` (required; jpeg/png/webp, ≤25MB — the CURRENT canvas,
local edits included), `mask` (optional, **PNG only**, ≤25MB — its TRANSPARENT pixels mark the
region to repaint; the client always exports it at the SAME pixel dimensions as `image`, though
this isn't separately re-validated server-side), `prompt` (required, ≤2000 chars).
`authorize('create', File::class)` — gated like uploading, since nothing here touches an existing
file row.

- Enforces a **per-workspace daily budget** (`config('ai.disk_image_max_per_day')`, default 50; 0
  disables it) — refused with **429** (`disk.ai.budget`) once reached. Counted **at dispatch, not
  on success**: a queued-but-not-yet-run edit already reserves the cap, so the queue cannot be
  flooded past it while jobs are still pending.
- Persists the image (+ mask) under the edit's own storage prefix — the worker runs after the
  request returns and can no longer read its temp files — creates a `disk_ai_edits` row
  (`status: queued`), queues `EditDiskImageJob`, and returns **202**
  `{ "data": { "id": "uuid", "status": "queued" } }`.
- Its own tight `disk-ai` throttle bucket (10/min) — long, provider-billed calls.

**GET /{id} — poll.** `{ "data": { "id", "status", "image"?, "mime"?, "error"? } }` — one resource
shape covers both the dispatch response and every subsequent poll. `status` is
`queued | processing | done | failed`; `image` (base64 PNG) + `mime` appear only once `done`,
`error` only once `failed`. Tenant-scoped like every disk id (`WorkspaceScope` + route-model
binding — a foreign id 404s; a missing `X-Workspace-Id` → `400`, refused before the binding even
resolves). `disk-read` throttle bucket (a poll loop hammers it like a thumbnail grid).

- **Realtime push, preferred over polling.** When a queued edit reaches a terminal state
  (`done`/`failed`) the worker also broadcasts a lightweight `{ id, status, error? }` payload —
  **never** the multi-MB image, which stays this endpoint's job — as `disk-ai-edit.updated` on a
  **private, per-workspace** Reverb channel `disk-ai.workspace.{workspaceId}`
  (`App\Modules\Disk\Events\DiskAiEditUpdated`). `routes/channels.php` authorizes the channel by
  plain workspace membership; `/broadcasting/auth` runs on the `auth:sanctum` guard (no
  `X-Workspace-Id` — the channel name itself carries the workspace id), so the SPA forwards its
  **Bearer token** on that auth request, the same one the API calls use. The client subscribes
  when Reverb is configured, and falls back to plain polling of this endpoint whenever it isn't
  (`VITE_REVERB_APP_KEY` unset) or the socket connection fails/drops — the poll contract above is
  never removed, only preempted.
- The worker (`EditDiskImageJob`) calls the provider through `ImageAiService::process()` /
  `OpenAiImageEditClient`, model `config('ai.disk_image_model')` (default `gpt-image-1` — verified
  to support masked `images/edits`). 3 tries, backoff `30s / 120s / 300s`; when retries are
  exhausted the job's `failed()` hook records a localized, non-secret `disk.ai.failed` message
  (never the raw provider body) and drops the persisted inputs.
- **Request tuning** — beyond the mask, `OpenAiImageEditClient` also sends `input_fidelity`,
  `quality`, and `background` to `images/edits` (any of the three may be blanked to `''` to omit
  it from the request). `background=opaque` stops an object-removal edit from cutting a
  transparent hole where the mask was (the provider's own `auto` default can leave the region
  transparent, which then shows through as a washed patch once composited over the original);
  `quality=high` reconstructs real detail in the repainted region instead of the flatter patch the
  provider's own `medium` default produces.
- **Reaper** — `disk:reap-stale-ai-edits`, scheduled `everyFiveMinutes()` + `withoutOverlapping()`
  (`routes/console.php`): fails edits stranded in `queued`/`processing` past
  `config('ai.disk_image_edit_timeout')` (900s — a worker killed mid-run, e.g. SIGKILL/OOM, never
  fires the job's `failed()` hook, so nothing else would recover them), and **prunes** terminal
  (`done`/`failed`) rows past `config('ai.disk_image_edit_retention')` (3600s — a row holds a
  multi-MB base64 result, so retention is deliberately short). Runs once on the shared connection
  and once per own-database workspace, mirroring the bot/workflow reapers.
- Presets are frontend prompt templates (`aiPresets.ts`) — the server only ever sees an image, a
  prompt, and an optional mask; see
  [`docs/frontend/disk-image-editor.md`](../frontend/disk-image-editor.md) for the mask-brush and
  async-apply UX.

**Config** (`config/ai.php`): `disk_image_model` (env `AI_DISK_IMAGE_MODEL`); the three
`images/edits` tuning parameters above — `disk_image_input_fidelity`
(`AI_DISK_IMAGE_INPUT_FIDELITY`, default `high`), `disk_image_quality` (`AI_DISK_IMAGE_QUALITY`,
default `high`), `disk_image_background` (`AI_DISK_IMAGE_BACKGROUND`, default `opaque`);
`disk_image_max_per_day` (`AI_DISK_IMAGE_MAX_PER_DAY`), `disk_image_edit_timeout`
(`AI_DISK_IMAGE_EDIT_TIMEOUT`), `disk_image_edit_retention` (`AI_DISK_IMAGE_EDIT_RETENTION`). The
provider HTTP call itself still bounds on `config('ai.image_timeout')` (`AI_IMAGE_TIMEOUT`,
default 120s), shared with the rest of the AI module.

**Deploy note**: a queue worker must run and share the filesystem disk with the web tier (it reads
back what the request persisted); `schedule:run` must be on cron/supervisor or the reaper never
fires — the same unconditional-infrastructure requirement as the workflow schedule sweep (see
`docs/backend/workflows-api.md`'s Ops notes). The php-fpm/proxy timeout ADR-0020 originally
flagged for the OLD synchronous call is now moot for the REQUEST itself (it returns in
milliseconds) — but the WORKER's own execution-time limit must still comfortably exceed
`image_timeout` (the per-attempt provider-call bound): a retried edit spans multiple worker
invocations (3 tries, backoff 30s / 120s between them) before reaching either `done` or a terminal
`failed`, which is exactly why the reaper's own `disk_image_edit_timeout` (900s) is set well past
the job's whole retry budget rather than just past one attempt.

### POST /api/disk/ai/text  *(preview AI text edit — sync)*

Apply an instruction to a text file's current content and return the edited text. Unlike the
image edit above, a gpt-4o text edit returns in a few seconds, so this is **NOT queued** — it
validates, calls the provider inline, and returns the result in the same request/response cycle.
`authorize('create', File::class)` — gated like uploading, since nothing here touches an existing
file row; nothing is persisted server-side, the edited text returns to the editor and only lands
on the disk through the normal save endpoints (`POST /disk/{file}/content` or `POST /disk`).
Shares the image edit's tight `disk-ai` throttle bucket (10/min — both are billed provider calls).
Auth: Bearer + `X-Workspace-Id`, and `Accept: application/json` like any FormRequest-validated
endpoint, so a validation failure returns a 422 JSON body rather than a redirect.

Body: `{ content: string (≤100000 chars), prompt: string (≤2000 chars) }`.

- **200** `{ "data": { "text": "string" } }`.
- **422** validation (`errors.content` / `errors.prompt`).
- **502** on a provider/transport failure — a localized, non-secret `disk.ai.failed` message; the
  raw provider body is never surfaced to the client, only `report()`-ed for ops.
- **400** missing `X-Workspace-Id`; **403** non-member.
- **429** once the `disk-ai` per-minute bucket is exhausted.

Runs through `TextAiService` → `DiskTextAiAgent` (laravel/ai), model `config('ai.model')` /
provider `config('ai.provider')` (the same chat-model config the rest of the AI module uses —
gpt-4o / openai by default), bounded by `config('ai.text_timeout')` (env `AI_TEXT_TIMEOUT`,
default 60s). Unlike the image edit there is no queue/poll/reaper here — the request simply waits
on the provider. Backs the text-file editor's AI actions (fix grammar / improve / shorten /
summarize, plus a free prompt).

### POST /api/disk/{file}/draft · GET /api/disk/{file}/draft · GET /api/disk/{file}/draft/base/{baseId} · DELETE /api/disk/{file}/draft  *(preview editor — autosave drafts)*

Per-user, server-side autosave so the preview editor's in-progress edits (image or text) survive a
refresh or crash. A draft is **personal** (scoped to the auth user — never shared with another
workspace member editing the same file) and **self-contained**: it stores full pixels (image) or
the full buffer (text), never a diff, so it never depends on the file's current bytes. The MAIN
file is overwritten only through the existing explicit-save endpoints (`POST /disk/{file}/content`
or `POST /disk`) — nothing here ever touches it. Every route is a literal `draft` suffix on `{file}`
(never read as a bare `{file}` id), `authorize('view', $file)` like `disk.info`, and scoped inside
the controller to `$request->user()` — one member reaches only their OWN draft of a file they can
see. Same tenancy contract as the rest of the module: a foreign-workspace `{file}` 404s at
route-model binding, a non-member 403s before it even resolves (`RequireWorkspace`).

**POST — autosave (upsert).** A plain multipart **POST, not PUT** — like `disk.replace-content`,
since PHP only parses a multipart body on POST. Body: `manifest` (a JSON string, required — see
the manifest shapes below), `base_version` (string, nullable — the file's `updated_at` captured
when the draft began, for later staleness detection), and, for an **image** draft, one file part
per NEW base blob named `base_{baseId}` (PNG) — the client sends only bases not yet stored, so a
normal autosave after the first is small. Capped at
`strlen(manifest) + Σ base sizes ≤ config('disk.drafts.max_bytes')` (64 MB default) → **422**
(`disk.drafts.too_large`) rather than silently filling the tenant disk. On every upsert the server
also GCs any base blob already on disk whose id is no longer in `manifest.baseIds` (history
truncated client-side). Always **200** — an idempotent upsert, never a "created" 201, so a first
autosave and a later re-save read identically to the client. `disk-upload` throttle bucket
(`60/min` — it writes blobs).

```json
{
  "data": {
    "kind": "image | text",
    "manifest": { /* decoded — see the manifest shapes below */ },
    "base_ids": [1, 2],                                    // image only; [] for text
    "base_version": "2026-07-20T10:00:00+00:00 | null",    // the FILE's updated_at pinned at draft start
    "updated_at": "2026-07-21T09:30:00+00:00"              // this DRAFT row's own last-autosave time
  }
}
```

**GET — fetch.** Same shape as above. **404** when this user has no draft of the file (never
started one, or already restored/discarded/saved it). `disk-read` throttle bucket.

**GET /base/{baseId} — one image base.** `{baseId}` is `whereNumber`. Raw `image/png` bytes
(`Content-Type: image/png`, `Cache-Control: private, max-age=86400`, `X-Content-Type-Options:
nosniff`), or **404** when the user has no draft or that base isn't stored. The path is derived
from the AUTH user's own id, so one member's request can never resolve another's base blob.
`disk-read` throttle bucket (reopening a large image draft can fetch several bases in a burst).

**DELETE — discard.** Deletes the row and the whole draft directory. **204**, and **idempotent** —
deleting twice, or after a Save already cleared it, still succeeds. `disk-upload` throttle bucket.

**Manifest** (`manifest`, FE-owned) — the backend treats it as opaque JSON except it reads
`baseIds` off an image manifest for the GC above, and lightly validates required shape per `kind`:

```json
// image — the editor's full undo/redo history + which base blobs are live
{ "kind": "image", "history": [{ "baseId": 1, "state": "…opaque…" }], "historyIndex": 0, "savedIndex": 0, "baseIds": [1, 2] }

// text — the whole buffer, inline (no base blobs)
{ "kind": "text", "content": "string" }
```

**Storage** — `disk-drafts/{workspaceId}/{userId}/{fileId}/manifest.json` +
`.../base-{baseId}.png` (image only, one per live history base), namespaced per workspace exactly
like the blob store and the PDF thumbnail cache. `UNIQUE(file_id, user_id)` on `disk_file_drafts` —
one draft per user per file, so POST always upserts.

- **Replacing the file's content does NOT clear other users' drafts.** A draft is self-contained
  (full bytes, never a diff against the file), so it survives the main file changing underneath
  it — staleness is surfaced to the client via a `base_version` mismatch, never by server-side
  deletion.
- **Reaper** — `disk:reap-stale-drafts`, scheduled `everyTenMinutes()` + `withoutOverlapping()`
  (`routes/console.php`): prunes drafts (row + storage dir) whose `updated_at` is past
  `config('disk.drafts.retention')` (24h default), so an abandoned autosave (a tab closed
  mid-edit) never lingers with its base blobs. Runs once on the shared connection and once per
  own-database workspace, mirroring the AI-edit / temp-file reapers (one broken tenant is logged
  and skipped, not fatal to the sweep).

**Config** (`config/disk.php` → `drafts`): `retention` (env `DISK_DRAFT_RETENTION`, default
`86400` seconds / 24h — floored at 60s so a misconfig can't prune everything instantly),
`max_bytes` (env `DISK_DRAFT_MAX_BYTES`, default `67108864` / 64 MB).

The frontend (`useDraftAutosave.ts`) debounces autosave ~2.5s after the last change and dedupes
image bases (a POST uploads only bases not yet confirmed on the server); on reopen, a pending
draft drives a Restore/Discard banner (`DraftRestoreBanner.vue`) and autosave stays paused until
the user picks one, so it can never clobber their draft with the current file state first.

### Comments — `/api/file/{id}/comments` · `/api/folder/{id}/comments`

Files and folders are commentable through the GENERIC comments module
(`app/modules/Comments`): `{module}` is the SINGULAR morph alias, the same convention as the
changelog endpoint. `GET` lists (cursor 8/page, DESC), `POST { content }` adds;
`PATCH/DELETE /api/comments/{comment}` edit/delete (author-only edit; delete = author OR the
item's human owner via `isOwnedBy` — `CommentPolicy`). Workspace-scoped exactly like tasks
comments (TenantAware + the `X-Workspace-Id` header).

### GET /api/disk

The files index — now used for **filtered / faceted** reads, not the plain folder browse:
Zasoby buckets (`?source=<type>&bucket=`), the disk **trash** (`?trashed=1`), and search/type/label
filters. Cursor-paginated, 24/page, newest first. Temp uploads are **always** excluded.

**Query**

| Param             | Meaning                                                                    |
|-------------------|-----------------------------------------------------------------------------|
| `folder_id`       | **Present-but-empty = the workspace root**; a uuid = that folder; absent = any folder. |
| `source`          | `disk` (disk-native only) or a morph alias (`task`, …). The browser sends `source=disk`. |
| `bucket`          | A "Zasoby" bucket key (`YYYY-MM`), paired with `source=<type>` — pins a precise half-open range. |
| `trashed`         | `1` → the disk trash (`withTrashed` + `diskTrashed`), flat.                  |
| `search`          | Case-insensitive over `name` + `description`.                               |
| `type`            | A `FileType` value.                                                          |
| `labels[]`, `label_operator` | Label ids + `AND`/`OR` (default `OR`).                            |
| `size_min`, `size_max`, date filters | Byte range and `filterByDate('created_at', …)`.           |
| `cursor`          | Cursor pagination token.                                                     |

### POST /api/disk

Upload a file onto the disk. `multipart/form-data`: `file` (required), `folder_id` (optional; a
missing/empty value = root). Sets `fileable` to the folder (root = `fileable_id` null). Returns
`201` + `FileResource`. Throttled `60/min` (`disk-upload` bucket).

### POST /api/disk/temp

Upload a **temp** file (the two-step upload). `multipart/form-data`: `file`. Returns the temp
`FileResource`; the id becomes a form field's answer, bound to the submission on submit. Throttled
`60/min` (`disk-upload`).

### POST /api/disk/{file}/copy-to-temp  *(B8)*

"Pick from Disk": copy an existing file into a **fresh temp the caller owns**, returned exactly
like `/disk/temp`. Any workspace member may read (`view`) and therefore copy a file; `{file}`
model-binding resolves live rows only, so a trashed/foreign source 404s. Throttled `60/min`
(`disk-upload`). See ADR-0016 §6 for why a pick copies rather than references.

### GET /api/disk/{file}

Stream the bytes. `?inline=1` asks the browser to render in place — honoured **only** for
`image/*` and `application/pdf`, and **never** for `image/svg+xml` (an SVG executes its own script
same-origin). Otherwise it downloads under its original name. `nosniff` always. Throttled
`300/min` (`disk-read` — a thumbnail grid is many reads). A row whose blob is missing 404s (not
500). Because this route needs the `X-Workspace-Id` + bearer headers, the frontend fetches it as a
**blob via the api client**, never a bare `window.open`.

### PATCH /api/disk/{file}

Metadata only: `name`, `description` (null clears), `labels` (id array), `folder_id` (move; null =
root). Absent fields are left alone. Moving a resource-owned file → `422`. Returns `FileResource`.

### DELETE /api/disk/{file}

Move to the disk trash (recoverable; stamps `disk_trashed_at`, soft-deletes). Trashing a
resource-owned file → `403` (`FilePolicy::delete`). `204`.

### GET /api/disk/{id}/restore-preview · POST /api/disk/{id}/restore · DELETE /api/disk/{id}/force

The restore matrix (P5). Preview reports whether an in-place restore is possible and why not.
Restore brings the file back **as a disk file** — re-pointing `fileable` at a folder
(`fileable_type = 'folder'`), so a detached file returns to the disk, never back inside the task
that removed it.
Restore body:

- **omit** `target_folder_id` → restore in place (only when `can_restore_in_place`);
- **send** `target_folder_id` (null = root) → restore there. **Required** when the original folder
  is gone / the file was detached (`reason !== null`), else `422`.

Force-delete is the **only** path that removes a blob. These three resolve the row with
`withTrashed()` (route-model binding would 404 a soft-deleted row), so they take `{id}`, not a
bound `{file}`.

---

## Endpoints — folders

All under `/api/disk/folders`.

| Method & path                         | Purpose |
|---------------------------------------|---------|
| `GET /`                               | One level: children of `?parent_id`, or the roots without it. `?trashed=1` → trashed folders, **flat**. Counts loaded. Now serves the folder **picker/tree** (the browse moved to `/disk/items`). |
| `POST /`                              | Create. Body `{ name, parent_id? }`. Depth-10 guarded. |
| `GET /{folder}`                       | The folder + `breadcrumbs` (its **ancestors**, root first — append the folder yourself for a full trail). Loads governance `labels`. |
| `PATCH /{folder}`                     | Edit metadata. Body (all optional, `sometimes`): `{ name, description, icon, labels: [{ id, mode }] }` — `mode ∈ enforced\|recommended`. Absent = untouched, `null` clears. Changing enforced labels recomputes the subtree (F3). Response **omits** the counts. |
| `POST /{folder}/move`                 | Re-parent the folder **with its whole subtree** (one anchored UPDATE). Body `{ target_folder_id }` (null = root). Guards: self / descendant / depth / name-collision → `422` with a specific message. |
| `DELETE /{folder}`                    | Trash (only an empty folder). `204`. |
| `POST /{id}/restore`                  | Restore a trashed folder (`withTrashed`; its parent may be gone → it roots). |

---

## Authorization

`FilePolicy` / `FolderPolicy` — workspace membership is enforced upstream, so these gate
**ownership for mutation** (via `HasCreator`, ADR-0015):

- `viewAny` / `view` — any member (reads are never creator-gated).
- `update` — owner or (system record) workspace-owner fallback. **Allowed even for a
  resource-owned file** (rename/tag/describe from the disk).
- `move` / `delete` — owner **and** not resource-owned (a resource file's lifecycle stays with its
  module).
- `restore` / `forceDelete` — owner (system-record fallback applies).

---

## Related files

- `app/modules/Disk/routes/api.php` — route order, `whereUuid`, throttle buckets
- `app/modules/Disk/Http/Controllers/{Files,Folders,AiImage,AiText,Draft}Controller.php`
- `app/modules/Disk/Services/{File,Folder,ImageAi,Text,Thumbnail,Draft}Service.php`,
  `OpenAiImageEditClient.php`, `ResourceFolderRegistry.php`
- `app/modules/Disk/Support/{PdfRasterizer,PopplerPdfRasterizer}.php` — the PDF thumbnail
  rasterizer (interface + the poppler `pdftoppm` implementation)
- `app/modules/Disk/Agents/DiskTextAiAgent.php` — the laravel/ai agent behind the sync text edit
- `app/modules/Disk/Jobs/EditDiskImageJob.php`,
  `app/modules/Disk/Console/ReapStaleAiEditsCommand.php` — the async AI edit worker + reaper
- `app/modules/Disk/Console/ReapStaleDraftsCommand.php` — the autosave-draft reaper
  (`disk:reap-stale-drafts`, scheduled in `routes/console.php`)
- `app/modules/Disk/Events/DiskAiEditUpdated.php`, `routes/channels.php` — the async AI edit's
  realtime completion push and its channel authorization
- `app/modules/Disk/Models/{File,Folder,DiskAiEdit,DiskFileDraft}.php` — the state scopes (`temp`,
  `diskTrashed`), the AI edit's lifecycle (`Enums/DiskAiEditStatus.php`), and the draft's per-user
  scope (`scopeForUser`)
- `app/modules/Disk/Http/Resources/{File,Folder,DiskAiEdit,Draft}Resource.php`
- `app/modules/Disk/Policies/{File,Folder}Policy.php`
- `config/disk.php` — thumbnail toggle, `pdftoppm` path, scale, timeout, draft retention + max
  payload size
- `docs/decisions/ADR-0016-disk-resources.md` — the design rationale
- `docs/decisions/ADR-0020-file-preview-editing.md` — the full-page preview and async AI edit
  pipeline rationale
- `docs/backend/creator-attribution.md` — the `HasCreator` ownership model these policies use
- `docs/frontend/disk-image-editor.md` — the editor UI, mask brush, and AI panel
- `tests/Feature/FileApiTest.php`, `tests/Feature/FolderApiTest.php`,
  `tests/Feature/FormFileSubmissionTest.php`, `tests/Feature/WorkflowFileAttachmentTest.php`,
  `tests/Feature/DiskAiImageTest.php`, `tests/Feature/DiskAiTextTest.php`,
  `tests/Feature/DiskAiBroadcastTest.php`, `tests/Feature/DiskThumbnailTest.php`,
  `tests/Feature/DiskDraftTest.php`
