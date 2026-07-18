# ADR-0016 — Disk & Resources: one files table, three states, and copy-not-reference

**Date:** 2026-07-18 (created)
**Status:** Accepted
**Module:** `App\Modules\Disk` — consumed by Forms (FILE element), Workflows (FILE variable +
`create_task` attachments), Tasks (attachments), and the Bot/report writers (`storeContent`)

---

## Context

Before R1, "files" in Taskio meant **attachments**: a `files` row always belonged to another
record (a task, a report) through a `fileable` morph, and there was no way to browse, organize, or
reuse them. R1 introduces the **Disk** — a first-class, workspace-scoped file manager (folders,
metadata, tags, history, trash, an image editor) — without forking the attachment mechanism into a
parallel one. The central tension throughout: a file can now be **three different things** (an
in-flight upload, a file that lives on the disk in its own right, or an attachment owned by another
module), and every query, validation rule, and lifecycle action has to keep them straight while
reusing one table and one `File` model.

Product decisions taken into planning (referenced below as **Q1–Q3** / **P1–P8**):

- **Q1** — the disk shows *all* the workspace's files; from the disk you may only edit metadata.
- **Q2** — the trash holds *disk* deletions only, not every detached attachment.
- **Q3** — anonymous form submitters may upload (upload-only, throttled).
- **P1** Windows-explorer browsing · **P2** materialized-path subtree moves · **P3** depth 10 ·
  **P4** workspace always from the header · **P5** restore with a location preview + target ·
  **P6** a FILE type everywhere (form element + workflow variable) · **P7** read-only virtual
  "Zasoby" folders · **P8** an in-app image editor.

---

## Decisions

### 1. One `files` table, three states discriminated by which columns are set

**Decision:** Keep a single `files` table and `File` model. A row's *state* is derived, not a
column:

| State           | `fileable_type` | `folder_id` | `disk_placed_at` |
|-----------------|-----------------|-------------|------------------|
| **Temp**        | null            | null        | null             |
| **Disk file**   | null            | folder / null (root) | set     |
| **Resource file** | a morph alias | null       | null             |

`File::scopeTemp` = `fileable_type IS NULL AND disk_placed_at IS NULL`;
`File::isOwnedByResource()` = `fileable_type !== null`.

**Alternatives rejected:**
- **A separate `disk_files` table.** Rejected — it would duplicate the whole attachment mechanism
  (upload, blob storage, changelog, labels, the per-workspace path prefix) and force every
  consumer that today takes a `File` (tasks, reports, the `FileResource` shared verbatim with the
  tasks frontend) to learn a second type. Q1 ("the disk shows all files") specifically wants
  attachments and disk files in *one* browsable set.
- **An explicit `state` enum column.** Rejected — it would have to be kept consistent with the
  three foreign keys it summarizes (a row with `state='temp'` but a `folder_id` set is a
  contradiction the DB could not prevent), and the derivation is cheap. The scopes encode the
  invariant in one place instead.

---

### 2. Q1 — the disk shows every file, but a resource file's lifecycle stays with its module

**Decision:** The browser lists disk-native files **and** resource-owned files. From the disk a
resource file may be **renamed, described, and tagged** (`FilePolicy::update` allows it) but
**never moved or trashed** (`move` / `delete` require `!isOwnedByResource()`). Its removal is the
owning module's job (detach it from the task/report).

**Alternative rejected:** letting the disk trash or move an attachment. Rejected — it would let a
user break a task's attachment out from under the task, and "delete from disk" would have to
cascade into the owning module's changelog and state. The disk is a *view + metadata* surface over
resource files, not their owner.

---

### 3. Q2 — the disk trash is a marker of its own, not a reused soft-delete

**Decision:** Trashing from the disk stamps a dedicated `disk_trashed_at` column **in addition to**
the soft delete. The trash view is `withTrashed()->whereNotNull('disk_trashed_at')`
(`File::scopeDiskTrashed`).

**Why not reuse `deleted_at`:** detaching a task attachment already soft-deletes its `File`
(`FileService::detach`). If the trash were "everything soft-deleted," it would fill with every
attachment anyone ever removed from any task — noise the user never deleted *from the disk*. The
separate marker answers the precise question "what did I throw away **here**." Pinned by
`FileApiTest::test_a_detached_attachment_never_shows_up_in_the_disk_trash`.

---

### 4. P2 — folders are a materialized path holding ANCESTORS ONLY (never self)

**Decision:** `folders.path` = `'/{ancestorUuid}/…/'`, root = `'/'`. A folder's own id is **not**
in its path (it is already the row's primary key). This yields:

- **Subtree move = one anchored `UPDATE`** re-prefixing every descendant's path
  (`descendantPrefix()`), pinned by a query-count test.
- **`isAncestorOf()` is non-reflexive** — the move guard rejects self separately.
- `depth()` = separator count; the **depth-10** cap (P3) is checked over the whole subtree after a
  move.

**Consequence — breadcrumbs are ancestors only (a load-bearing gotcha).** `folders/show` and
`restore-preview` return the folder's **ancestors**, root first — the folder itself is **not** in
the list (the path never holds self). Every consumer that wants a trail *ending at* the open
folder must append the folder: the frontend store does (`[...breadcrumbs, data]`), and the restore
dialog appends `original_folder.name`. Assuming "breadcrumbs end at self" silently drops the
current folder from the crumb row and sends the up-navigation to the wrong parent — this was a live
bug in B6e, now covered by regression tests.

**Alternatives rejected:**
- **Adjacency list with recursive CTE reads.** Rejected — every breadcrumb and subtree read
  becomes a recursive query; the materialized path makes them a single `LIKE`/prefix scan, and
  moves a single `UPDATE`.
- **Path that includes self.** Rejected — it reintroduces a self-reference (and a write-time race
  to know your own uuid before you have it), and forces every breadcrumb consumer to strip the last
  segment. Ancestors-only is the shape the descendants prefix actually needs.

---

### 5. P4/root-files — `disk_placed_at` distinguishes a root disk file from an in-flight temp

**Decision:** A file uploaded to the disk root has `folder_id = null` **and** `fileable_type =
null` — structurally identical to a temp. `disk_placed_at` (stamped by `store()` and by `restore()`)
breaks the tie: the browse query includes a row when
`fileable_type IS NOT NULL OR folder_id IS NOT NULL OR disk_placed_at IS NOT NULL`, and
`scopeTemp` excludes anything with `disk_placed_at` set.

**Why an explicit `OR`, not `COALESCE`:** the three columns are varchar / uuid / timestamp, which
Postgres refuses to reconcile in a single `COALESCE`. **Why a timestamp, not a boolean `is_placed`:**
the placement instant is audit-useful and the column doubles as the "became disk-native" marker on
restore; a boolean would carry less and still need setting at the same two sites. Pinned by
`FileApiTest::test_a_file_uploaded_to_the_roo_t_is_placed_and_visible_there`.

---

### 6. P6 — a producer referencing a file it does not own COPIES it; only temps are ever rebound

**Decision:** Two binding primitives, and they only ever touch **temps**:

- `attachToModel()` / `bindTempTo()` rebind rows matched by `->temp()` — a foreign or
  already-placed id in the list is silently ignored, never stolen.
- `copyToModel()` (workflow `create_task` attaching a submission's file to a task) and
  `copyToTemp()` (B8 "pick from Disk") **duplicate** the blob into a new row instead of rebinding.

**Why copy, not reference — the pick-from-Disk case (B8):** a form/task file field expects a temp
the submitter uploaded. An existing disk file has `fileable_type = null` (so the form validator
reads it as an unowned temp) but `disk_placed_at` set / a `folder_id` — so `bindTempTo`'s `temp()`
scope would **silently skip it** (the file would never actually bind to the submission) and the
task claim rule refuses a file in a folder or uploaded by another member. Referencing the id
directly would also make **one blob shared** between the disk and the submission — trashing it from
the disk would break the submission. `copyToTemp` sidesteps all of this: it produces a fresh temp
the actor owns (`HasCreator` stamps `uploader_id`), indistinguishable from an upload downstream, so
**zero** validators or bind paths change. Endpoint: `POST /disk/{file}/copy-to-temp`.

**Alternatives rejected:**
- **Reference the existing file id from the submission.** Rejected — the shared-blob hazard above,
  plus it fails the existing claim rules.
- **Relax the claim rules to accept "any readable disk file" and copy at bind time.** Rejected —
  it spreads the copy into two different bind sites (forms `bindTempTo` + tasks `attachToModel`)
  and complicates two validators, versus one small copy endpoint the pick calls up front.

---

### 7. Mutation responses omit the counts → clients MERGE, never replace

**Decision:** `loadCount(['children','files'])` runs only on the **index** and **show** endpoints.
Rename / move responses are a fresh resource **without** the `whenCounted` keys. The frontend
therefore **merges** a mutation response over the existing grid row (`{...row, ...patch}`) rather
than replacing it — otherwise a renamed non-empty folder's tile would drop to "0 items". "N items"
= `children_count + files_count` (a folder of only subfolders is not "0 items"). All grid mutations
are token-guarded so a superseded response cannot patch a level the user already left.

---

### 8. Security posture — bind after tenant resolution; a strict inline-serve allowlist

**Decision (carried from B0 + the tenancy-binding fix):**

- `ResolveWorkspace` runs **before** `SubstituteBindings`, so a foreign `{file}`/`{folder}` id
  resolves to `404` at bind time — app-wide, not per-controller. (See the tenancy-binding change;
  the ordering is pinned by a test.)
- Binaries are **streamed**, never buffered into memory. `?inline=1` is honoured only for `image/*`
  and `application/pdf`, and **denied for `image/svg+xml`** (an SVG served top-level executes its
  own `<script>` same-origin — `nosniff` cannot help because the declared type is honest). Serving
  always sends `nosniff` + `Content-Disposition`.
- Each surface has its **own** throttle bucket prefix (`disk-read`, `disk-upload`) — without
  distinct prefixes `ThrottleRequests` keys on the user alone, so a thumbnail grid would eat the
  upload budget and even 429 unrelated modules.

**Consequence for the frontend:** because `disk.show` needs the `X-Workspace-Id` + bearer headers,
a file is fetched as a **blob through the api client** and turned into an object URL — a bare
`window.open(file.path)` cannot send those headers and never worked (fixed in the B6 review).

---

### 9. P6 — FILE is a first-class type everywhere, reusing existing machinery

**Decision:**

- **Form element:** the existing `image` element type is **repurposed** to a real single-file
  input (`->format('file')` in the JSON schema) rather than adding a new element type — no content
  migration, no churn across the seven frontend sites that switch on the type string. The
  discriminator in the schema is what keeps a file field from mapping to a MULTI variable.
- **Workflow variable:** a new `WorkflowVariableType::FILE` whose snapshot is **always a list** of
  `{id, name, mime_type, size}`; coercion → ids, stringification → the file **name**. Added via
  the exhaustive `match` arms with **no `default`**, so every resolver/evaluator/executor had to
  handle it in one change (the compiler enforces completeness).

**Alternative rejected:** a `file_type_is` operator. Dropped — "is it a PDF?" is `file_name →
ends_with('.pdf')` and "how many?" is `file_count → gt`, so a dedicated type operator earned its
keep nowhere.

---

### 10. P7 — "Zasoby" is a read-only VIRTUAL tree, grouped in PHP

**Decision:** Resource files are browsed only under a synthetic `Zasoby → type → created-at bucket`
tree whose nodes carry `sys:res:…` ids that **no folder query can resolve** (a non-uuid id → `422`,
never a mutation). The registry (`ResourceFolderRegistry`) defines the types and bucket granularity;
buckets are grouped **in PHP** over a half-open UTC range, not with a driver-specific `GROUP BY`
(at R1 volumes a workspace's attachment set is small; this is where a per-bucket count query would
move if that changes). Resource files are kept out of the folder browser by the `source=disk`
filter.

---

### 11. P8 — the image editor edits pixels, saves a COPY, and never upscales

**Decision:** Filters run on the raw `Uint8ClampedArray` (Rec.601 grayscale, sepia, invert,
brightness, contrast) — **not** `CanvasRenderingContext2D.filter`, whose Safari support is
unreliable and which is not unit-testable without a real canvas. Geometry (rotate 90° / flip /
crop) uses canvas transforms; crop commits the current transform+filter destructively into a new,
smaller working canvas. A working canvas is capped at **4096px** on its longest edge (never
upscales). **Save writes a new file** (`canvas.toBlob` → `uploadFile`, name `<stem>-edited.<ext>`)
— the original is never mutated. The AI-filter slot is present but **disabled** (deferred to R2; no
image-generation infrastructure in R1).

---

### 12. Q3 — anonymous upload is DECIDED but NOT BUILT

**Status: planned, not implemented.** The product decision (anonymous form submitters may upload,
upload-only + whitelist + ~10 MB + throttle) stands, but batch **B4b was dropped** from R1 — no
public/anonymous upload endpoint exists today. All disk routes require `auth:sanctum` +
`RequireWorkspace`. Documented here so the decision is not re-litigated: when built, it is a
separate public endpoint, not a relaxation of the authenticated ones.

---

## Consequences

- `docs/backend/disk-api.md` (new) is the endpoint-by-endpoint contract; it links here for
  rationale rather than re-deriving it.
- The disk reuses `HasCreator` for ownership (`uploader_id`/`uploader_type`) exactly as
  ADR-0015 describes — trash actions gate on `can_be_restored`/`can_be_force_deleted`, and a
  system-created file (e.g. a report output) falls back to the workspace owner for mutation.
- **Known residual gap (pre-existing, not introduced here):** a temp chosen or uploaded in the
  **workflow editor** for a `create_task` attachment can be swept by the 48 h temp GC before the
  workflow ever runs; at run time the literal attachment id is then resolved leniently (a missing
  file is skipped, not a crash). This is a property of the FILE-literal design from B4c, flagged
  for a future "promote editor-chosen temps to permanent" follow-up.
- **Known follow-up:** the dashboard quick-links do not yet surface the Disk module.

---

## Related files

- `app/modules/Disk/Models/{File,Folder}.php` — the state scopes, materialized-path helpers
- `app/modules/Disk/Services/FileService.php` — `store`/`upload`/`copyToModel`/`copyToTemp`/
  `bindTempTo`/`attachToModel`/`trash`/`restore`/`pruneTempFiles`
- `app/modules/Disk/Services/FolderService.php`, `ResourceFolderRegistry.php`
- `app/modules/Disk/Http/Controllers/{Files,Folders}Controller.php`, `routes/api.php`
- `app/modules/Disk/Policies/{File,Folder}Policy.php`
- `app/modules/Forms/Enums/FormElementType.php` — the `image`→file element + `collectFileAnswers`
- `app/modules/Workflows/Enums/WorkflowVariableType.php` — the FILE variable type
- `resources/js/next/pages/disk/*` — the browser, drawer, image editor, pickers
- `resources/js/next/pages/forms/FormFileInput.vue` — upload + pick-from-Disk
- `docs/backend/disk-api.md`, `docs/backend/creator-attribution.md`
- `tests/Feature/{FileApiTest,FolderApiTest,FormFileSubmissionTest,WorkflowFileAttachmentTest}.php`
