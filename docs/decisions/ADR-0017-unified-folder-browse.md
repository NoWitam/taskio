# ADR-0017 — Unified folder browse via a reusable staged cursor paginator; path-based folder URLs

**Date:** 2026-07-18 (created)
**Status:** Accepted
**Module:** `App\Support\Pagination` (new, reusable) + `App\Modules\Disk` + the `next` frontend
**Supersedes:** the two-call folder browse from ADR-0016 (folders and files stay one table each;
only how the browser READS a folder level changes)

---

## Context

R1 shipped the disk browser reading a folder level with **two** requests — `GET /disk/folders?parent_id=X`
(subfolders, flat) and `GET /disk?folder_id=X&source=disk&cursor=` (files, cursor-paginated) — and
addressed the folder with a query string (`/next/disk?folder=<id>`). Two coupled requests for one
screen is awkward (two loading/error states, two cursors, ordering split across calls), and a folder
is a resource, so it belongs in the path. This ADR unifies the browse behind one endpoint and one
cursor, and moves the folder into the URL path — which required a small but genuinely reusable piece
of infrastructure: a cursor paginator that spans **two tables**.

---

## Decisions

### 1. A reusable `StagedCursorPaginator` — cursor pagination across N ordered stages

**Decision:** `App\Support\Pagination\StagedCursorPaginator` paginates several ordered **stages**
(each a query over ANY table) as one logical, sequentially-concatenated list — all of stage 0, then
all of stage 1, … — behind a **single** `?cursor=` and the exact Laravel cursor envelope
(`{ data, links, meta: { next_cursor, prev_cursor, per_page } }`). It is deliberately
disk-agnostic:

```php
StagedCursorPaginator::make(24)
    ->stage('folders', fn () => $folders->childrenQuery($folder))   // orderBy(name).orderBy(id)
    ->stage('files',   fn () => $files->diskFilesInFolderQuery($folder)) // orderBy(created_at desc).orderBy(id desc)
    ->paginate($request->query('cursor'));
```

**How it works (and why it's small):**
- Each stage **delegates its multi-column seek to Laravel's own `Builder::cursorPaginate()`** — the
  tuple `(a,b) > (v1,v2)` WHERE is never hand-rolled. The paginator only orchestrates *accumulation*
  across stages (fetch `remaining` from the resumed stage; when it's exhausted, roll into the next
  from its top) until it has `perPage + 1` rows (the probe row proves a next page exists).
- The result is a **subclass of `Illuminate\Pagination\CursorPaginator`** (`StagedCursorResult`) that
  overrides only `nextCursor()`/`previousCursor()`. Because a resource collection preserves the
  paginator and `CursorPaginator::toArray()` emits `meta.next_cursor` from `nextCursor()->encode()`,
  the envelope is **byte-identical** to any other cursor endpoint — a true drop-in, no
  resource-pipeline changes.
- The cursor is a **stock `Illuminate\Pagination\Cursor`** whose parameters are the resumed stage's
  order columns **plus a `_stage` marker**. `_stage` is inert to the per-stage seek (Laravel reads
  only that stage's order columns), so the incoming cursor is passed straight through.

**Contract:** every stage's `orderBy` MUST end in a unique column (the primary key) — a seek on a
non-unique final column skips/duplicates at page edges. `FolderService::childrenQuery` therefore
adds `->orderBy('id')` (its list order was `name` alone).

**Correctness at the stage boundary (the subtle part):** the next cursor always points at the LAST
EMITTED item, in whatever stage it lives. Resuming there seeks strictly after it; when that seek
dries up (0 rows — e.g. it was the stage's last row) the loop rolls into the next stage from the
top. The probe row is discarded and correctly re-served on the next page — no sentinel encoding, no
gaps, no duplicates. Pinned by `StagedCursorPaginatorTest` (mid-page and exact-page-edge boundaries).

**Alternatives rejected:**
- **A SQL `UNION` of the two tables.** Rejected — folders and files have different columns and
  different resources; a union forces a lowest-common-denominator projection and a shared order key,
  and loses the clean per-model eager-loads (`withCount` vs `with(['labels'])`).
- **Hand-rolled cursor + seek.** Rejected — Laravel's `paginateUsingCursor` already implements the
  exact multi-column seek and value extraction; reusing it per stage is less code and less risk than
  re-deriving it.
- **A single-table paginator plus client-side merge.** Rejected — the client would need both cursors
  and the merge/order logic; the whole point is ONE cursor and ONE ordered list from the server.

**Scope now:** forward only (`prev_cursor` is always `null`, shape-valid); the cursor is
direction-aware (`_pointsToNextItems`), so backward is an additive follow-up when a consumer scrolls
both ways.

**Security:** the cursor is NOT a trust boundary. Each stage builds a fresh, tenant-scoped
`Model::query()` (global `WorkspaceScope`), so a forged/tampered cursor can only yield an odd or
empty page, never another workspace's rows. An unknown `_stage` or a cursor missing the stage's
order columns degrades to the first page (guarded), never a 500.

### 2. One endpoint for a folder's contents — `GET /disk/items/{folder?}`

**Decision:** `GET /api/disk/items` (root) / `GET /api/disk/items/{folder}` (a uuid) returns the
folder's subfolders **and** disk-native files as one staged-paginated list — each item a
`DiskItemResource` (the existing `FolderResource`/`FileResource` verbatim, with a `kind`
discriminator prepended) — plus `folder` (the open folder, for the trail's last crumb) and
`breadcrumbs` (its ancestors). The old two calls remain, narrowed to their other jobs: `GET
/disk/folders?parent_id=` is now the folder **picker/tree**; `GET /disk?…` handles Zasoby buckets,
the trash, and filtered/faceted reads. Zasoby (files-only) and the trash (flat folders) keep their
existing endpoints — only the real-folder browse, which has the folders+files duality, unifies.

**Frontend consequence — minimal churn:** the store makes the one call and **splits the mixed list
back into its existing `folders`/`files` arrays by `kind`**, driven by one cursor. The tile grid and
every mutation helper are unchanged; folders load fully before files by construction, so the
folders-then-files render order holds across pages.

### 3. The current folder is a URL path segment, not a query param

**Decision:** the frontend route is `disk/:folder?` (`/next/disk/<uuid>`), and the API takes the
folder in the path (`/disk/items/{folder}`). The synthetic `sys:res…` / `sys:trash` ids ride the
**same** optional path param (they are single segments with no slash), so one route still serves the
whole tree — a deliberate simplicity choice over prettier dedicated aliases for the special levels.

---

## Consequences

- New reusable primitive `App\Support\Pagination\{StagedCursorPaginator,StagedCursorResult}` — the
  first occupant of `app/Support/`. A future cross-table feed (e.g. a unified activity stream) adopts
  it unchanged.
- `docs/backend/disk-api.md` documents the new endpoint and the narrowed roles of the two old ones.
- **Not migrated (deliberate):** the trash's own folders+files duality still uses two calls (its
  folders are a finite flat list, so pagination there buys nothing). Revisit only if trash volumes
  ever warrant it.

---

## Related files

- `app/Support/Pagination/StagedCursorPaginator.php`, `StagedCursorResult.php`
- `app/modules/Disk/Http/Controllers/FilesController.php` (`items()`), `routes/api.php`
- `app/modules/Disk/Http/Resources/DiskItemResource.php`
- `app/modules/Disk/Services/FolderService.php` (`childrenQuery`), `FileService.php` (`diskFilesInFolderQuery`)
- `resources/js/next/app/router/index.ts`, `pages/disk/DiskView.vue`, `app/stores/disk.ts`, `pages/disk/types.ts`
- `tests/Feature/StagedCursorPaginatorTest.php`, `tests/Feature/DiskItemsTest.php`,
  `resources/js/next/app/stores/__tests__/disk.spec.ts`
