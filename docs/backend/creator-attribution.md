# Backend reference: polymorphic `creator` attribution and ownership

Cross-cutting concern — not owned by a single module. Backs `App\Traits\HasCreator`,
`App\Http\Resources\CreatorResource`, `App\Policies\Concerns\ChecksRecordOwnership`. See
**ADR-0015** for the full design record (alternatives considered, rationale); this page is the
practical, endpoint-by-endpoint contract.

> Every module doc (`workflows-api.md`, `bots-api.md`, …) that mentions `creator` or `is_owner`
> links here instead of redefining the shape — keep this page as the single source of truth for
> the union itself.

---

## Concepts

A `HasCreator` record's author is **polymorphic**: a human `User`, an engine `WorkflowRun`
(automation), or a `Bot`. Stored as an id column plus a nullable morph-type discriminator —
`creator_id`/`creator_type` on most tables, `uploader_id`/`uploader_type` on the Disk `File`
table. A `NULL` type is read as the `'user'` alias (pre-polymorphic legacy rows keep resolving to
their human creator with no data migration needed).

```
creator_id / creator_type  ──morphTo──▶  User          ('user')
                                          WorkflowRun    ('workflow_run')
                                          Bot            ('bot')
```

### Stamping precedence (`HasCreator`'s `saving` hook — fill-only, never overwrites an explicit id)

| Order | Source                                             | Result                                                              |
|-------|------------------------------------------------------|--------------------------------------------------------------------|
| 1     | An explicit id already set on the model                | Kept as-is; type defaults to `'user'` only if left unset.        |
| 2     | An active `WorkflowRun` (`WorkflowRunContext::current()`) | `creator_type = $run->getMorphClass()`, `creator_id = $run->id` — **the run stamps itself.** |
| 3     | `auth()->id()`                                          | `creator_type = 'user'`, `creator_id = auth()->id()`.             |
| 4     | None of the above                                        | Both columns left `null`. A `NOT NULL` id column fails loudly at insert rather than mis-attributing. |

`WorkflowRunContext` is an in-process singleton the currently-executing run publishes for the
duration of its step loop (`WorkflowStepRunner::run()`, set/cleared in a `try`/`finally`). This is
why **every record a `create_task`/`create_form_report` step creates is attributed to the RUN**
(`creator_type='workflow_run'`) regardless of whether the run started from a real event, the
schedule sweep, or a manual test-run, and regardless of whether an HTTP user happens to be
authenticated in the same process. Before this, an engine-authored `Task`/`FormReport` crashed on
insert (`creator_id` is `NOT NULL` on both tables) because the old logic only ever fell back to
`auth()->id()`, which is always `null` inside a queued job.

### Models using `HasCreator` (11)

| Model             | Id column     | Type column      | Morph alias registered in                  |
|--------------------|-----------------|--------------------|-----------------------------------------------|
| `Task`               | `creator_id`     | `creator_type`       | —                                              |
| `Form`               | `creator_id`     | `creator_type`       | —                                              |
| `FormSubmission`      | `creator_id`     | `creator_type`       | —                                              |
| `FormReport`           | `creator_id`     | `creator_type`       | —                                              |
| `Bot`                    | `creator_id`     | `creator_type`       | `BotModuleServiceProvider` (`'bot'`)             |
| `BotAction`               | `creator_id`     | `creator_type`       | `BotModuleServiceProvider` (`'bot_action'`)      |
| `Workflow`                 | `creator_id`     | `creator_type`       | `WorkflowsModuleServiceProvider` (`'workflow'`)  |
| `WorkflowRun`                | `creator_id`     | `creator_type`       | `WorkflowsModuleServiceProvider` (`'workflow_run'`) |
| `ApprovalPipeline`             | `creator_id`     | `creator_type`       | —                                              |
| `ApprovalProcess`                | `creator_id`     | `creator_type`       | —                                              |
| `File` (Disk)                      | `uploader_id`    | `uploader_type`      | —                                              |

`'user'` → `App\Models\User` is registered in `AuthModuleServiceProvider`. `Relation::enforceMorphMap()`
is used everywhere (not the loose default of storing the FQCN) — an unregistered class throws
`ClassMorphViolationException` at write time, so a new `HasCreator` consumer must register its own
alias if it introduces one.

**Not every model in this table exposes a `creator` field on its own API resource.** `WorkflowRun`,
`BotAction`, and `ApprovalProcess` use `HasCreator` purely for the stamping/ownership mechanism —
their resources do not currently render `CreatorResource`. The 8 resources that DO are listed below.

---

## The discriminated union (`CreatorResource`)

```
user         → { type: 'user',         id, name, email, avatar }
workflow_run → { type: 'workflow_run', id, run_id, label }
bot          → { type: 'bot',          id, name, avatar }
```

- `avatar` is always `null` today for a `user` — Taskio has no avatar-upload feature yet
  (`UserResource` itself always emits `avatar: null`); this is pre-existing, not specific to the
  creator union.
- `label` on `workflow_run` is the **parent workflow's name** (a run has no name of its own),
  nullable when the workflow record is gone/unnamed. Always eager-load the creator relation with
  `morphWith([WorkflowRun::class => ['workflow']])` to avoid an N+1 per row on a list.
- `bot` is fully modeled end-to-end (`CreatorResource`, the frontend `Creator` union, `CreatorBadge`)
  but **no write path stamps it today** — registered for forward-compatibility, not yet reachable.

### Presence rules (Laravel's nested-resource null handling via `whenLoaded`)

| Relation state                     | `creator` in the response         |
|--------------------------------------|--------------------------------------|
| Not eager-loaded                        | Key **omitted** entirely.               |
| Eager-loaded, resolves to `null`           | Key present, value `null` (legacy/unattributed row). |
| Eager-loaded, resolves to a User/WorkflowRun/Bot | The matching shape above.        |

Every production resource embeds it identically: `'creator' => CreatorResource::make($this->whenLoaded('creator'))`
— never construct this by hand.

### Worked examples

```json
// A human-created record
{ "creator": { "type": "user", "id": "9c1e...", "name": "Ola Kowalska", "email": "ola@example.com", "avatar": null } }

// A workflow-run-created (system) record
{ "creator": { "type": "workflow_run", "id": "c1c2...", "run_id": "c1c2...", "label": "Nightly Cleanup" } }

// A bot-created record (modeled, not yet reachable by any write path)
{ "creator": { "type": "bot", "id": "1111...", "name": "Nightly Helper", "avatar": null } }

// Loaded but unattributed (legacy row)
{ "creator": null }

// Relation not eager-loaded — the key is absent, not null
{ "id": "...", "name": "..." }
```

---

## Where `creator` is exposed (the 8 resources)

| Module     | Resource                    | Endpoint(s)                                                    | List variant carries `creator`? |
|-------------|--------------------------------|--------------------------------------------------------------------|-------------------------------------|
| Tasks         | `TaskResource` (DETAIL only)      | `GET/PUT /tasks/{id}`                                                 | **No** — `TaskListResource` (`GET /tasks`) omits `creator` (and `is_owner`) entirely; lean by design. |
| Forms          | `FormResource`                      | `GET/POST/PUT /forms/{id}`                                              | `FormListResource` (`GET /forms`) **does** carry `creator`. |
| Forms          | `FormListResource`                   | `GET /forms`                                                            | (same resource used for both list and the `form` sub-object elsewhere) |
| Forms          | `FormSubmissionResource`              | `GET /forms/{form}/submissions`, `GET /form-submissions/{id}`             | Same resource for list and detail; always carries `creator`. |
| Forms          | `FormReportResource`                    | `GET /forms/{form}/reports`, `GET /form-reports/{id}`                       | Same resource for list and detail; always carries `creator`. |
| Workflows        | `WorkflowResource` (DETAIL only)          | `GET/POST/PUT /workflows/{id}`                                                 | **No** — `WorkflowListResource` (`GET /workflows`) carries only `is_owner` (via the hot-path `ownerUserId()`, no `User` load). |
| Approvals          | `ApprovalPipelineResource` (DETAIL only)     | `GET/POST/PUT /approval-pipelines/{id}`                                          | **No** — `ApprovalPipelineListResource` carries only `is_owner` (same hot-path pattern). |
| Bot                  | `BotResource` (DETAIL only)                    | `GET/POST/PUT /bots/{id}`                                                          | **No** — `BotListResource` carries only `is_owner` (same hot-path pattern). |

The general pattern: **a lean list resource never eager-loads `creator`** (to avoid an N+1 across a
page of rows) and instead exposes only `is_owner`, computed by the id/type-only `ownerUserId()` hot
path. A full detail resource eager-loads `creator` (with the `morphWith` N+1 guard for a
`workflow_run`) and renders the whole union. **Forms is the one module where the "list" resource
(`FormListResource`) is reused as both the list AND the embedded `form` sub-object on other
resources, so it carries `creator` unconditionally** — it is not lean in the same sense as
`TaskListResource`/`WorkflowListResource`/`BotListResource`.

---

## Ownership — `is_owner` vs `can_be_*`

`creator` never gates READ/LIST access — that is exclusively workspace tenancy
(`WorkspaceScope`/`TenantAware`), unchanged by any of this. It gates **mutation** ownership only,
through three `HasCreator` methods:

| Method                          | Returns                                                                                  |
|-----------------------------------|----------------------------------------------------------------------------------------------|
| `creatorUser(): ?User`               | The creator, only if it resolved to a `User`. `null` for a `workflow_run`/`bot` creator.        |
| `isOwnedBy(?User $user): bool`         | `$user !== null && creatorUser()?->id === $user->id`. Fail-closed.                              |
| `ownerUserId(): ?string`                 | Hot-path variant for list resources — reads the type/id columns directly, no `User` load.        |

A `workflow_run`/`bot`-created record is a **system record owned by nobody** — not the human who
triggered the run, not the workflow's author (see ADR-0015 §3 for why that attribution was
rejected). Left there, it would be permanently unmutable, so mutating Policies route through
`App\Policies\Concerns\ChecksRecordOwnership::ownsOrManagesSystemRecord()`:

1. The record's human owner (`isOwnedBy`) may always act.
2. Otherwise, ONLY if the record has NO human creator (a system record), the **active workspace's
   OWNER** may act, as a fallback.
3. A record that DOES have a (different) human creator is never escalated to the workspace owner.

`TaskPolicy`, `BotPolicy`, `WorkflowPolicy`, `ApprovalPipelinePolicy`'s mutating gates all use this.

**Exception — `TaskStatus::canSetOn` for `ARCHIVE`/`TRASH` uses `isOwnedBy` alone, no fallback.**
Even the workspace owner cannot archive/trash a system task via
`PATCH /tasks/{id}/status/{archive|trash}` — `DELETE /tasks/{id}` (which DOES carry the fallback,
via `TaskPolicy::delete`) is the system task's only removal path. See ADR-0015 §4.

**Capability-flag divergence to design the frontend around:** a resource's `is_owner` reflects
HUMAN authorship only. `can_be_edited`/`can_be_deleted`/`can_change_status` (Bot, Workflow) route
through the Policy and DO include the workspace-owner fallback — so for a system record, the
workspace owner can legitimately see `is_owner: false` alongside `can_be_edited: true`. **Always
gate UI actions on the `can_*` flags, never on `is_owner`** — `is_owner` is presentational
("does this say YOU made it"), the `can_*` flags are authoritative.

**`ApprovalPipelineResource.can_be_edited`/`can_be_deleted` are the one exception worth flagging
explicitly: they call the MODEL's `canBeEdited()`/`canBeDeleted()` directly** (`!hasActiveProcesses()`)
— a business-STATE check only, with no ownership/authorization component. `is_owner` on that same
resource is the only ownership signal the frontend gets without attempting the write and reading a
403; this is a pre-existing asymmetry with Bot/Workflow's Policy-routed flags, not something this
refactor introduced or changed.

---

## AI report analytical table — `creator_name` is human-only, `creator_type` is raw

`FormAnalyticalTableService` (per-form dynamic Postgres table, `form_analytical_<form-uuid>`)
carries both `creator_id` and `creator_name` columns, populated by `indexSubmission()`:

```sql
LEFT JOIN users u ON u.id = fs.creator_id AND fs.creator_type = 'user'
```

`creator_id` is the raw morph id (may be a `User`, `WorkflowRun`, or `Bot` uuid). `creator_name`
resolves through a `LEFT JOIN` **gated on `creator_type = 'user'`**, so a `workflow_run`/`bot`-authored
(system) submission resolves `creator_name` to `NULL` rather than a wrong name or an accidental id
collision with an unrelated `users` row. `create_form_report`'s AI analysis prompt
(`app/modules/Forms/Jobs/CreateFormReport.php`) documents both columns to the model explicitly —
`creator_id (UUID) - ID twórcy (człowiek, automatyzacja lub bot)`, `creator_type (TEXT) - typ twórcy:
'user' (człowiek), 'workflow_run' (automatyzacja) lub 'bot'` — and steers it toward `creator_name`
for any "who submitted this" grouping, since that column is the one guaranteed to name a human or
be cleanly `NULL`.

`FormReportService::indexByForm()`'s `?creator_id[]=` ("created by me") list filter uses the same
discipline at the report level (not the analytical table): `where('creator_type', 'user')` before
matching ids, so a system-authored report can never surface under a human's id filter even if a
uuid happened to collide (`tests/Feature/FormReportCreatorFilterTest.php`).

---

## Known gaps (documented, not fixed by this pass)

- **`GenerateFileTool` (Bot module, B5) does not set `uploader_type`.** It writes
  `File::create(['uploader_id' => $task->creator_id, ...])`, so `HasCreator` rule 1 defaults
  `uploader_type` to `'user'`. This was already a documented compromise pre-dating the polymorphic
  refactor (a bot has no user row, so a bot-generated file is attributed to "the task's human
  creator" as a stopgap — see `docs/backend/bots-api.md` → `generate_file`). It is now a sharper
  edge case: **if the task itself is a system record** (its own `creator_id` is a
  `WorkflowRun`/`Bot` uuid), the generated file's `uploader_id` copies that non-user uuid while
  `uploader_type` stays defaulted to `'user'` — `File::creator` then resolves to `null` (no
  matching `users` row) instead of a meaningful creator. This is an existing, out-of-scope
  application-code gap, not something to silently patch in a docs pass — flagged here and in
  ADR-0015 so it is not mistaken for new/undiscovered behavior later.
- **`bot` is not yet stamped by any write path.** The morph branch, resource shape, and frontend
  union are all built and tested, but no code today gives a `Bot` model as the explicit creator of
  a `HasCreator` record. It exists for forward-compatibility (mirroring how `assignee`/`author`
  already support a Bot actor) rather than a currently-reachable state.

---

## Frontend mirror

`resources/js/next/ui/patterns/creator.ts` defines the `Creator` union (`UserCreator |
WorkflowRunCreator | BotCreator`) verbatim-matching the backend shapes, plus `creatorLabel()` /
`creatorIcon()` helpers. `resources/js/next/ui/patterns/CreatorBadge.vue` is the one component that
renders it (glyph + name), used instead of each screen re-implementing the `type` switch:

```vue
<CreatorBadge :creator="task.creator" size="sm" />
```

- `user` → a real `Avatar` (image → initials → user glyph).
- `workflow_run` → a `workflow` glyph (info-subtle) + `"Automatyzacja: <workflow>"` (generic label
  when `label` is null).
- `bot` → a `sparkles` glyph (primary-subtle) + the bot's name (matches the existing `BotIdentity`
  treatment so a bot is never confused with a user).
- `null`/omitted → a generic user glyph + a neutral placeholder (`"System"` by default, or a
  caller-supplied `fallback`) — never blank.

Current consumers: `resources/js/next/pages/tasks/TaskDetailsDrawer.vue`,
`resources/js/next/pages/forms/SubmissionCard.vue`.

---

## Related files

- `app/Traits/HasCreator.php`
- `app/Http/Resources/CreatorResource.php`
- `app/Policies/Concerns/ChecksRecordOwnership.php`
- `app/modules/Workflows/Services/WorkflowRunContext.php`, `WorkflowStepRunner.php`, `WorkflowRunManager.php`
- `app/modules/Tasks/Enums/TaskStatus.php` (`canSetOn` ARCHIVE/TRASH exception)
- `app/modules/Bot/Tools/Registry/GenerateFileTool.php` (known gap)
- `app/modules/Forms/Services/FormAnalyticalTableService.php`, `Jobs/CreateFormReport.php`
- `resources/js/next/ui/patterns/creator.ts`, `CreatorBadge.vue`
- `docs/decisions/ADR-0015-polymorphic-creator-ownership.md`
- `tests/Feature/CreatorAttributionTest.php`, `tests/Feature/CommentOwnershipTest.php`,
  `tests/Feature/FormReportCreatorFilterTest.php`, `tests/Unit/CreatorResourceTest.php`
