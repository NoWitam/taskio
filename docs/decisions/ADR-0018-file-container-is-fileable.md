# ADR-0018 — A file's container is its `fileable` (folder for disk files); drop `folder_id`/`disk_placed_at`

**Date:** 2026-07-18 (created)
**Status:** Accepted
**Module:** `App\Modules\Disk` (+ `App\Modules\Tasks` claim rule)
**Revises:** ADR-0016 §1 (the three file states) and §5 (`disk_placed_at`)

---

## Context

ADR-0016 modelled a file's disk placement with a dedicated `folder_id` column, and needed a second
column — `disk_placed_at` — purely to tell a **root** disk file (`folder_id NULL`) apart from an
in-flight **temp** upload (also `folder_id NULL`, `fileable_type NULL`). But `files` already carries a
polymorphic `fileable` (the record a file belongs to: a task, a report, a submission). A disk file's
"container" is its folder, which is exactly the same shape of relationship — so a separate `folder_id`
(plus the disambiguating `disk_placed_at`) was redundant with `fileable`.

---

## Decision

**A file's container IS its `fileable`.** `fileable_type` now discriminates all three states, and
`folder_id` / `disk_placed_at` are dropped:

| State           | `fileable_type`            | `fileable_id`        |
|-----------------|----------------------------|----------------------|
| **Temp**        | `NULL`                     | `NULL`               |
| **Disk file**   | `'folder'` (`File::FOLDER_TYPE`) | the folder, or `NULL` for the workspace **root** |
| **Resource file** | a resource morph (`task`, `form_report`, `form_submission`, …) | the owning record |

So `fileable` generalizes to "what this file lives in": a **folder** for a disk file, the owning
**resource** for an attachment. `'folder'` is the morph alias already registered for `Folder` in
`DiskModuleServiceProvider`, so `File::fileable()` resolves to the containing folder for free.

**Derived, so the contract is preserved:**
- `File::scopeTemp` = `fileable_type IS NULL`; `File::scopeDiskNative` = `fileable_type = 'folder'`.
- `File::isOwnedByResource()` excludes `'folder'` (a folder container is NOT resource ownership).
- `File::folder()` is a `belongsTo(Folder, 'fileable_id')`; a **virtual** `folder_id` accessor
  (`fileable_type === 'folder' ? fileable_id : null`) keeps `$file->folder_id` and the API Resource's
  `folder_id`/`source` fields byte-identical — **the frontend is untouched**.
- `Folder::files()` is a `morphMany(File, 'fileable')`, so `withCount('files')` counts exactly its
  disk files.
- Restore no longer "severs" the fileable — a trashed file comes back as a disk file by re-pointing
  `fileable` at a folder (`fileable_type = 'folder'`), which is why a detached attachment still must be
  given a target.
- The task attachment claim rule (`StoreTasksRequest`) now checks a temp precisely as
  `fileable_type === null`.

**Alternatives rejected:**
- **Keep `folder_id` + `disk_placed_at`.** Rejected — two columns encoding what one polymorphic
  relation already expresses; `disk_placed_at` existed only to work around the `folder_id`/temp
  ambiguity that disappears here.
- **A virtual `folder_id` mutator so writes stay `folder_id`-shaped.** Rejected — a `folder_id = null`
  write is ambiguous (root disk file vs. not-a-disk-file), which is the very ambiguity this ADR
  removes. Writers set `fileable` explicitly (`FileService::folderPlacement()`); only reads are
  virtualized for contract compatibility.

---

## Consequences

- The `add_disk_columns` migration (central + tenant mirror) no longer adds `folder_id` /
  `disk_placed_at`. On this unpushed branch the columns are simply not created; a dev DB needs
  `migrate:fresh` (tests rebuild via `RefreshDatabase`). No data backfill is written because there is
  no shipped data.
- Test factories gained `File::factory()->inFolder(?Folder)` / `->atRoot()` (a plain factory is a
  temp); tests can no longer pass `folder_id` (it is not a column).
- The changelog's folder-move tracker moves from `folder_id` to the real `fileable_id` column, mapped
  to the folder name only when the container is a folder.
- Unrelated but adjacent: the mixed staged-paginator page (`StagedCursorResult`) gained a
  type-grouped `loadMissing`, so `items()` can eager-load `creator` on both folders and files without
  N+1 in the per-item capability flags.

---

## Related files

- `app/modules/Disk/Models/File.php` (`FOLDER_TYPE`, `folder()`, `getFolderIdAttribute`, `scopeTemp`,
  `scopeDiskNative`, `isOwnedByResource`, changelog), `Folder.php` (`files()` morphMany)
- `app/modules/Disk/Services/FileService.php` (`folderPlacement`, `index`, `diskFilesInFolderQuery`,
  `store`/`update`/`restore`/`copyToDisk`/`pruneTempFiles`), `FolderService.php` (`trash`)
- `app/modules/Disk/Http/Resources/FileResource.php` (`source`), `app/modules/Tasks/Http/Requests/StoreTasksRequest.php`
- `database/migrations/2026_07_17_000101_add_disk_columns_to_files_table.php` (+ tenant mirror),
  `database/factories/FileFactory.php`
- `app/Support/Pagination/StagedCursorResult.php` (`loadMissing`)
