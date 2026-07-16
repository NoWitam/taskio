# ADR-0015 — Polymorphic creator (User | WorkflowRun | Bot) and the ownership-via-null model

**Date:** 2026-07-15 (created)
**Status:** Accepted
**Module:** Cross-cutting — `App\Traits\HasCreator`, `App\Http\Resources\CreatorResource`,
`App\Policies\Concerns\ChecksRecordOwnership` — consumed by Tasks, Forms, Workflows, Approvals,
Bot, Disk

---

## Context

`HasCreator` (`app/Traits/HasCreator.php`) stamps `creator_id` on every model that uses it, via a
`saving` hook that fell back to `auth()->id()`. That single-actor assumption broke as soon as a
**WorkflowRun** could author records with no HTTP request in play: a workflow's `create_task` step
runs inside a queued job (`WorkflowRunJob` → `WorkflowStepRunner`), where `auth()->id()` is always
null. `tasks.creator_id` is a `NOT NULL` column (`foreignIdFor(User::class, 'creator_id')`,
central migration `2026_01_19_194834_create_tasks_table.php`), so an engine-authored task crashed
outright on insert. The same gap existed for `create_form_report` (`form_reports.creator_id`).

Separately, `Task::assignee` / `Comment::author` already carry a polymorphic **User | Bot** actor
(ADR-0007 #1), and named-bot approvers reuse `approval_stages.approver_id` with an
`approver_type` discriminator (ADR-0007 #5) — the morphTo-plus-alias-column shape is the
established pattern in this codebase for "more than one kind of actor can fill this slot." This
ADR extends that same pattern to `creator`/`uploader`, and settles a second question the earlier
uses did not have to answer: once a record's creator is not a human, **who is allowed to mutate
it?**

---

## Decisions

### 1. The creator is a 3-member morph set: `user` | `workflow_run` | `bot` — not a 4th "system" pseudo-actor, and not a `bot_run` alias

**Decision:** `creator`/`uploader` becomes a `morphTo('creator', creator_type, creator_id)`
resolving to `App\Models\User`, `App\Modules\Workflows\Models\WorkflowRun`, or
`App\Modules\Bot\Models\Bot`. Morph aliases (`'user'`, `'workflow_run'`, `'bot'`) are registered
via `Relation::enforceMorphMap()` in `AuthModuleServiceProvider`, `WorkflowsModuleServiceProvider`,
and `BotModuleServiceProvider` respectively (mirroring the existing `assignee`/`author` alias
registrations from ADR-0007).

**Alternatives rejected:**
- **A branded `choice`-style union carrying an explicit discriminator field on every payload
  (mirroring the `WorkflowVariableType` "choice" pattern from ADR-0014).** Rejected — that pattern
  exists to validate a small, closed, author-facing option set against a form field. A creator is
  not user-authored content; it is an audit fact the backend derives, so a plain Eloquent morph
  (the pattern ADR-0007 already established for `assignee`/`author`) is the right level of
  ceremony, not a bespoke coercion pipeline.
- **A `bot_run` alias distinct from `bot`**, to distinguish "a Bot created this while executing a
  task" from "a Bot is the record's named owner." Rejected — no code path today has a Bot directly
  author a `HasCreator` record outside of the pre-existing `generate_file` compromise (see
  Decision #5); introducing a second bot-shaped alias ahead of a real second consumer would be
  speculative. `bot` is registered and handled end-to-end in `CreatorResource` /
  `resources/js/next/ui/patterns/creator.ts` for forward-compatibility, but is not yet actually
  stamped by any write path.
- **A dedicated "system" pseudo-user row** (an actual `users` table row representing "the
  engine") that every engine-authored record points `creator_id` at. Rejected — it would need a
  workspace-agnostic sentinel user visible in every tenant, `email`/`name` fields with no real
  meaning, and would make `creatorUser()` return non-null for a record nobody human actually
  owns, defeating the ownership model in Decision #3.

---

### 2. Stamping precedence: explicit id ⟩ active WorkflowRun ⟩ auth user ⟩ null

**Decision:** `HasCreator::bootHasCreator()`'s `saving` hook resolves in this order (fill-only,
never overwrites an already-set id):

1. **Explicit id already on the model** — kept as-is; the type column defaults to `'user'` only
   when the caller left it unset (so a workflow-run stamp made explicitly, e.g. by
   `CreateFormReportStep` → `FormReportService::create()`, is never re-typed).
2. **An active `WorkflowRun`**, read from `WorkflowRunContext::current()` — a singleton the run
   currently executing on this worker publishes for the duration of its step loop
   (`WorkflowStepRunner::run()`, `set()`/`clear()` around a `try`/`finally`). When present, the
   record is stamped `creator_type = $run->getMorphClass()`, `creator_id = $run->id` — **the run
   stamps itself**, regardless of whether an HTTP user happens to be authenticated in the same
   process (a manual test-run does have one; the stamp still goes to the run, not the user).
3. **`auth()->id()`**, unchanged from the pre-refactor behavior.
4. **Neither** — both columns are left null. A `NOT NULL` id column then fails loudly at insert
   time rather than silently mis-attributing the record; this is the same fail-loud posture the
   pre-refactor code already had for the plain-user case.

The lookup is lazy and guarded (`app()->bound(WorkflowRunContext::class)`), so the trait — used by
models across five modules — never hard-depends on the Workflows module being booted.

**Consequence — this is the actual fix for the driver crash.** `create_task` and
`create_form_report` steps run inside `WorkflowStepRunner`, which publishes the executing run to
`WorkflowRunContext` for the whole step loop. Every record a step creates therefore resolves at
rule 2 and is attributed to the run — `creator_type='workflow_run', creator_id=run->id` — whether
the run started from a real event, the schedule sweep, or a manual test-run, and whether or not an
HTTP user happens to be authenticated in that process. `tasks.creator_id` / `form_reports.creator_id`
are never left null again.

---

### 3. Ownership is derived from a human creator only — `isOwnedBy` / `creatorUser` / `ownerUserId`

**Decision:** Three new `HasCreator` methods answer "who owns this, for mutation purposes":

- `creatorUser(): ?User` — the creator, but only if it resolved to a `User`; `null` for a
  `workflow_run` or `bot` creator (and for a legitimately-null creator).
- `isOwnedBy(?User $user): bool` — `$user !== null && creatorUser()?->id === $user->id`. Fail-closed:
  a `null` `$user` is never "the owner," and a system record is owned by nobody.
- `ownerUserId(): ?string` — the hot-path variant used by list resources
  (`WorkflowListResource`, `BotListResource`, `ApprovalPipelineListResource`): reads
  `creator_type`/`creator_id` directly (no `User` load), returning `creator_id` only when the type
  is `'user'` or the legacy `NULL` (pre-polymorphic rows), `null` otherwise.

**A `workflow_run`/`bot`-created record is a SYSTEM record owned by nobody** — not by the human who
triggered the run, not by the workflow's author. This is a deliberate choice: the triggering user
did not write the record's content (the step/agent did), and attributing ownership to "whoever
happened to cause this to run" would be an arbitrary a posteriori grant, not a real authorship
claim.

**Alternative rejected:** Attributing ownership of a run-created record to the workflow's own
`creator_id` (its human author) was considered — it would keep every record mutable by *someone*
without a new fallback mechanism. Rejected because a workflow can outlive or be shared beyond its
original author's direct involvement in each run, and because it would make an unrelated user's
button-triggered manual run silently grant HIS records to the WORKFLOW's author, which is a
surprising cross-user side effect. See Decision #4 for the fallback that avoids records becoming
permanently unmutable instead.

---

### 4. Read access stays 100% workspace-tenancy; `creator` gates MUTATION only, with a workspace-owner fallback for system records

**Decision:** `creator_id`/`creator_type` never participated in, and continue not to participate
in, READ/LIST authorization — that is exclusively `WorkspaceScope`/`TenantAware`. `creator` is
purely a **mutation**-ownership signal, consumed through a new shared trait,
`App\Policies\Concerns\ChecksRecordOwnership::ownsOrManagesSystemRecord()`:

```php
protected function ownsOrManagesSystemRecord(object $record, ?User $user): bool
{
    if ($user === null) return false;
    if ($record->isOwnedBy($user)) return true;

    // Only a system record (no human creator) falls back to the workspace owner.
    if ($record->creatorUser() !== null) return false;

    return app(TenantContext::class)->workspace()?->isOwnedBy($user) ?? false;
}
```

The record's human owner may always act. A system record would otherwise be **permanently
unmutable** (nobody satisfies `isOwnedBy`), so the active workspace's OWNER is granted a narrow
fallback. A record that DOES have a human creator is never escalated to the workspace owner — that
would be cross-user privilege escalation the access model does not otherwise grant. `TaskPolicy`,
`BotPolicy`, `WorkflowPolicy`, and `ApprovalPipelinePolicy`'s mutating gates (`update`, `delete`,
`restore`, plus `changeStatus`/`retry` where applicable) all route through this one trait method.

**Named exception — `TaskStatus::canSetOn` for `ARCHIVE`/`TRASH` uses `isOwnedBy` alone, no
fallback.** Archiving and trashing a task are reached through the general status-transition route,
not the `destroy` endpoint, and that route intentionally does NOT get the workspace-owner escape
hatch: a run-created task's only removal path is `DELETE /tasks/{id}` (which DOES use
`ownsOrManagesSystemRecord` via `TaskPolicy::delete`). This asymmetry is pinned by
`tests/Feature/CreatorAttributionTest.php::test_workspace_owner_cannot_archive_a_run_task_via_status_but_can_destroy_it`
— the workspace owner gets 403 on `PATCH /tasks/{id}/status/{archive|trash}` for a system task, but
200 on `DELETE /tasks/{id}` for the same task. Rationale: archive/trash-via-status is a routine,
low-friction action available to any task owner day-to-day; widening it to "any workspace owner,
for any system task" was judged a larger, less-intentional blast radius than the destroy endpoint,
which already carries the fallback deliberately as the sole removal path.

**Consequence beyond the 8 `creator`-bearing resources.** `CommentPolicy::delete` also gates on
`commentable->isOwnedBy($user)` (comment author OR the commentable's human owner may delete) — this
branch existed before but was checking a dead attribute; it became functional once ownership moved
onto `HasCreator::isOwnedBy`, and now correctly fails closed for a comment on a run-created task
(`tests/Feature/CommentOwnershipTest.php`).

**A capability-flag divergence follows from this split, worth flagging for API consumers:** a
resource's `is_owner` flag (`isOwnedBy($user)`) reflects HUMAN authorship only, while its
`can_be_edited`/`can_be_deleted`/`can_change_status` flags route through the Policy and therefore
DO include the workspace-owner fallback. For a system record, the workspace owner can legitimately
see `is_owner: false` alongside `can_be_edited: true` — this is intended, not a bug, and the
frontend must gate actions on the `can_*` flags, never on `is_owner`.

---

### 5. `WorkflowRun`'s OWN creator is hardcoded `'user'`-typed and explicitly stamped, never inherited from a parent run

**Decision:** `WorkflowRunManager::start()` sets the new run row's `creator_id`/`creator_type`
directly — `creator_id => $creatorId ?? $workflow->creator_id`, `creator_type => 'user'` — rather
than letting `HasCreator`'s `saving` hook infer it. `$creatorId` is the acting user for a MANUAL
run; for an engine-started run (event/schedule) it is `null`, so the run **inherits the workflow's
own author**, never `null`. Both columns are set explicitly so that a re-triggered CHILD run (one
workflow's step causing another workflow to fire while the parent run is still the active
`WorkflowRunContext`) is attributed to ITS OWN workflow's author, not accidentally stamped onto the
parent run by rule 2 of Decision #2.

**Consequence, replacing the previously-documented behavior:** `WorkflowRun.creator_id` is now
**never null**, including for a schedule-sweep run started with no HTTP request in play (it used to
be — see the superseded text in `docs/backend/workflows-api.md`'s "Origin" section, corrected
alongside this ADR). `WorkflowRun.origin` (`event | schedule | manual`) remains the authoritative
signal for how a run began; `creator_id` stays a softer audit field, now simply "never null" instead
of "sometimes null."

**Accepted assumption, not re-verified here:** this hardcodes the run's own creator as human-typed
on the premise that a `Workflow`'s own `creator_id` is always a human (workflows can only be
authored through the authenticated `POST /workflows` surface — no step or agent creates a
`Workflow`). If that ever changes, `WorkflowRunManager::start()` would need to inherit the actual
type, not hardcode `'user'`.

---

### 6. `CreatorResource` — one discriminated union, verbatim-mirrored on the frontend

**Decision:** Every `creator` field across the 8 resources that expose it (see
`docs/backend/creator-attribution.md`) is rendered by one class, `App\Http\Resources\CreatorResource`:

```
user         → { type: 'user',         id, name, email, avatar }
workflow_run → { type: 'workflow_run', id, run_id, label }   // label = the parent workflow's name
bot          → { type: 'bot',          id, name, avatar }
```

Loaded-but-null resolves to `null` (Laravel's nested-resource null handling); a relation the
controller never eager-loaded is OMITTED from the payload entirely (`whenLoaded`). The frontend
mirrors this union field-for-field in `resources/js/next/ui/patterns/creator.ts` (`Creator` type)
and renders it through one component, `CreatorBadge.vue`, rather than each screen re-implementing
the switch. See `docs/backend/creator-attribution.md` for the full endpoint-by-endpoint contract
and worked examples.

**Rationale for `label` on `workflow_run` sourcing the WORKFLOW's name, not the run's:** a
`WorkflowRun` has no name of its own; the workflow definition does. Eager-loading is a
`morphWith([WorkflowRun::class => ['workflow']])` on the `creator` relation (see
`FormReportService::indexByForm()`) so a list of run-created records does not N+1 per row.

---

## Consequences

- `docs/backend/creator-attribution.md` (new) is the canonical cross-module reference for this
  system — the module-specific docs (`docs/backend/workflows-api.md`, `docs/backend/bots-api.md`)
  link to it rather than re-deriving the union shape.
- `FormReportService::indexByForm()`'s "created by me" filter (`?creator_id[]=`) explicitly guards
  `where('creator_type', 'user')` before matching ids — a run/bot's UUID can never accidentally
  collide into a human's "mine" filter (`tests/Feature/FormReportCreatorFilterTest.php`).
  `Task`'s equivalent `user_id[]` list filter has the same guard.
- `FormAnalyticalTableService`'s per-form analytical table gained a `creator_type` column
  alongside the pre-existing `creator_id`/`creator_name`; `creator_name` is populated from a
  `LEFT JOIN users ... AND fs.creator_type = 'user'`, so it resolves to `NULL` (never a wrong name
  or an accidental id collision) for a system-authored submission. See
  `docs/backend/creator-attribution.md` → "AI report analytical table."
- **Known residual gap, not fixed as part of this refactor:** `GenerateFileTool` (Bot module, B5)
  still writes `File::create(['uploader_id' => $this->ctx->task->creator_id, ...])` without setting
  `uploader_type`. Because `uploader_id` IS explicitly set, `HasCreator` rule 1 defaults
  `uploader_type` to `'user'`. This was already a documented compromise pre-dating this refactor
  (a bot has no user row, so the generated file was attributed to "the task's human creator" as a
  stopgap). It is now a slightly SHARPER gap: if the task ITSELF is a system record (its own
  `creator_id` is a `WorkflowRun`/`Bot` uuid, per Decision #2), the generated file's `uploader_id`
  copies that non-user uuid while `uploader_type` is defaulted to `'user'` — so
  `File::creator` resolves to `null` (no matching `users` row) rather than surfacing a
  meaningful creator. Flagged here rather than silently fixed, since it touches
  `app/modules/Bot/Tools/Registry/GenerateFileTool.php` (application code, out of scope for a
  documentation-only pass) — see `docs/backend/creator-attribution.md`'s "Known gaps" section and
  the Open Documentation Gaps note in the corresponding change log.

---

## Related files

- `app/Traits/HasCreator.php` — stamping precedence, `creator()` morphTo, `isOwnedBy`/`creatorUser`/`ownerUserId`
- `app/Http/Resources/CreatorResource.php` — the discriminated union
- `app/Policies/Concerns/ChecksRecordOwnership.php` — `ownsOrManagesSystemRecord()`
- `app/modules/Workflows/Services/WorkflowRunContext.php` — the in-process "current run" holder
- `app/modules/Workflows/Services/WorkflowStepRunner.php` — publishes the run around the step loop
- `app/modules/Workflows/Services/WorkflowRunManager.php` — explicit `creator_id`/`creator_type` on the run row itself
- `app/modules/Tasks/Enums/TaskStatus.php` — `canSetOn()`, the ARCHIVE/TRASH no-fallback exception
- `app/modules/Bot/Tools/Registry/GenerateFileTool.php` — the known `uploader_type` gap (Consequences)
- `app/modules/Forms/Services/FormAnalyticalTableService.php` — `creator_name`/`creator_type` on the analytical table
- `resources/js/next/ui/patterns/creator.ts`, `CreatorBadge.vue` — the frontend mirror
- `docs/backend/creator-attribution.md` — the cross-module reference this ADR points to
- `tests/Feature/CreatorAttributionTest.php`, `tests/Feature/CommentOwnershipTest.php`,
  `tests/Feature/FormReportCreatorFilterTest.php`, `tests/Unit/CreatorResourceTest.php`
