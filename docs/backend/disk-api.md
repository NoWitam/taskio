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
"pick from Disk" (B8). The design rationale lives in
[`docs/decisions/ADR-0016-disk-resources.md`](../decisions/ADR-0016-disk-resources.md).

---

## Concepts

### A file is in exactly one of three states

`files` is one table (`App\Modules\Disk\Models\File`); which columns are set decides what a row
*is*:

| State           | `fileable_type` | `folder_id`        | `disk_placed_at` | Shows in the browser? | Lifecycle owner |
|-----------------|-----------------|--------------------|------------------|-----------------------|-----------------|
| **Temp**        | `null`          | `null`             | `null`           | No                    | Nobody yet — swept after 48 h if never claimed |
| **Disk file**   | `null`          | a folder or `null` (root) | set       | Yes (`source=disk`)   | The disk (its uploader) |
| **Resource file** | a morph alias (`task`, `form_report`, `form_submission`, …) | `null` | `null` | Only under "Zasoby"   | The owning module |

`File::scopeTemp` = `fileable_type IS NULL AND disk_placed_at IS NULL`. `disk_placed_at` is what
lets a **root** disk file (no folder, no owner) still be distinguishable from an in-flight temp —
without it, both are `(null, null)`. See ADR-0016 §5.

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
  "labels": [ /* LabelResource[], when loaded */ ],

  "source": "disk | task | form_report | form_submission | …",  // fileable_type ?? 'disk'
  "created_at_iso": "ISO 8601 | null",
  "updated_at_iso": "ISO 8601 | null",
  "disk_trashed_at": "ISO 8601 | null",

  "can_be_updated": true,
  "can_be_moved": true,
  "can_be_deleted": true,
  "can_be_restored": false,
  "can_be_force_deleted": false
}
```

- `source` is the browser's origin facet: `'disk'` for a disk-native file, else the owning
  resource's morph alias.
- A **resource file** (`source !== 'disk'`) may be renamed / described / tagged from the disk but
  never moved or trashed (`can_be_moved` / `can_be_deleted` are `false`) — its lifecycle belongs
  to the owning module.

### FolderResource

```json
{
  "id": "uuid",
  "name": "string",
  "parent_id": "uuid | null",
  "depth": 0,
  "ancestor_ids": ["uuid", "…"],            // root first; the raw path is never exposed
  "has_children": true,                      // whenCounted('children') only
  "children_count": 3,                       // whenCounted('children') only
  "files_count": 5,                          // whenCounted('files') only
  "created_at": "ISO 8601 | null",
  "updated_at": "ISO 8601 | null",
  "deleted_at": "ISO 8601 | null",
  "can_be_updated": true,
  "can_be_moved": true,
  "can_be_deleted": true,
  "can_be_restored": false
}
```

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

All under `/api/disk`. Literal segments (`temp`, `folders`, `resources`) are declared before the
`{file}` wildcard and every id segment is `whereUuid`, so a literal is never read as a file id.

### GET /api/disk

Browse the disk (cursor-paginated, 24/page, newest first). Temp uploads are **always** excluded.

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
missing/empty value = root). Stamps `disk_placed_at`. Returns `201` + `FileResource`. Throttled
`60/min` (`disk-upload` bucket).

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
Restore brings the file back, **always severing `fileable_*`** (a detached file returns as a
standalone disk file, never back inside the task that removed it) and stamping `disk_placed_at`.
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
| `GET /`                               | One level: children of `?parent_id`, or the roots without it. `?trashed=1` → trashed folders, **flat**. Counts loaded. |
| `POST /`                              | Create. Body `{ name, parent_id? }`. Depth-10 guarded. |
| `GET /{folder}`                       | The folder + `breadcrumbs` (its **ancestors**, root first — append the folder yourself for a full trail). |
| `PATCH /{folder}`                     | Rename. Body `{ name }`. Response **omits** the counts (do not overwrite them client-side). |
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
- `app/modules/Disk/Http/Controllers/{Files,Folders}Controller.php`
- `app/modules/Disk/Services/{File,Folder}Service.php`, `ResourceFolderRegistry.php`
- `app/modules/Disk/Models/{File,Folder}.php` — the state scopes (`temp`, `diskTrashed`)
- `app/modules/Disk/Http/Resources/{File,Folder}Resource.php`
- `app/modules/Disk/Policies/{File,Folder}Policy.php`
- `docs/decisions/ADR-0016-disk-resources.md` — the design rationale
- `docs/backend/creator-attribution.md` — the `HasCreator` ownership model these policies use
- `tests/Feature/FileApiTest.php`, `tests/Feature/FolderApiTest.php`,
  `tests/Feature/FormFileSubmissionTest.php`, `tests/Feature/WorkflowFileAttachmentTest.php`
