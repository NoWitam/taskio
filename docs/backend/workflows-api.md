# Backend API: Workflows module

Module: `app/modules/Workflows/` — the variable TYPE SYSTEM and pipeline OPERATION ENGINE it runs on
(`VariableType`, `Operation`, `OperationExecutor`, `PipelineValidator`, …) live in a separate, LOWER
layer, `app/modules/Variables/`, which Workflows depends on (never the other way around) — see
**ADR-0027-variables-module-extraction.md**. The Consts and Functions endpoints documented below
also live in `app/modules/Variables/`, not Workflows — see the note at each section.
Auth: all endpoints require `auth:sanctum` + `X-Workspace-Id` header (resolved by `ResolveWorkspace` middleware).
Tenant scope: `TenantAware` trait — all queries are automatically scoped to the active workspace.

> **Etap 5.1 re-scope.** This document describes the CURRENT contract after the 5.1 re-scope
> (B1–B7, all complete and reviewed): **2 trigger types** (`form_submitted`, `schedule`), **2
> step types** (`create_task`, `create_form_report`), a **typed condition/variable system**, and
> AI schedule-assist. It supersedes the Etap-5 contract (5 triggers: `task_created`,
> `task_status_changed`, `form_submitted`, `approval_finished`, `schedule`; 4 steps:
> `create_task`, `assign_bot`, `attach_form`, `start_approval`; flat untyped conditions) —
> see ADR-0009 for the re-scope rationale and ADR-0008 for the run-engine decisions that still
> hold. This document describes IMPLEMENTED behavior only; anything not yet built is marked
> **PLANNED**.
>
> **Runtime operations, if-blocks, and AI text in step fields (SB1/SB2, this revision) —
> ADDITIVE, not breaking.** A step's text fields (`create_task.description`,
> `create_form_report.guidelines`) and value-or-variable fields
> (`create_task.priority`/`.deadline`, `create_form_report.submissions_from`/`.submissions_to`)
> now execute variable-operation PIPELINES, conditional `if-block`s, and `@[ai-text]`
> AI-generated text at RUN time — see "Runtime operations, if-blocks, and AI text" below. This
> REVERSES ADR-0009 §2's "an operations pipeline is explicitly deferred" — see
> **ADR-0013-workflows-step-operations-conditionals-ai-text.md** for the reasoning. Nothing about
> the wire shape existing consumers relied on changed (an old workflow with identity-only
> directives / pipeline-less value-or-variable fields keeps behaving exactly as before); every new
> capability is opt-in content authored through the frontend editor.
>
> **Choice-coercion for value pipelines (this revision) — the operation catalog grows 66→68;
> tightens (not breaks) the value-or-variable WRITE validator for a "choice" field.** Two new
> operations, `enum_to_choice` and `match_to_choice`, let a value-or-variable pipeline map an
> arbitrary source variable into a DESTINATION field's own fixed option set — e.g. mapping a
> form's free-text `category` answer onto `create_task.priority`'s
> `urgent|high|medium|low`. `Operation::producesChoice()` marks these two ops as CHOICE
> terminals; a pipeline targeting a choice field (currently only `priority`) must now be
> non-empty and END in one — a bare identity ref or a pipeline ending in a non-choice op (e.g.
> `enum_to_text`) is **REJECTED** at write time (previously accepted under the "enum or text"
> terminal rule). This is a validator-only tightening: a workflow SAVED before this change keeps
> running unchanged (the runtime coercion path was never affected), but re-saving a step whose
> `priority` pipeline does not end in a choice op now 422s. See
> **ADR-0014-workflows-choice-coercion.md** for the full design record and
> "Runtime operations, if-blocks, and AI text" → "Choice fields" below for the wire contract.
>
> **Schedule model v2 (this revision) — BREAKING, supersedes the 12/16-family model.** The
> family-based vocabulary (`WorkflowScheduleFamily`, previously 16 members) described in earlier
> revisions of this document is RETIRED. `trigger_config.schedule` is now a COMPOSITIONAL
> descriptor of three independent axes — `{ time, day?, month?, exclusions?, tz? }` — combined
> with AND, instead of one of 16 named presets. This is a BREAKING wire change: the write path and
> the schedule-preview endpoint no longer accept `{ family, params }`; every response
> (`WorkflowResource`, the assist envelope) always returns the v2 shape; and
> `GET /workflows/meta/schedule-families` (the family-discovery endpoint) is REMOVED — there is no
> vocabulary left to discover, the frontend owns the axis/mode list and mirrors the numeric bounds
> from `ScheduleLimits` directly. A schedule **stored** before this change keeps firing and keeps
> rendering unchanged — `LegacyScheduleUpgrader` transparently upgrades a legacy `{ family, params
> }` block to v2 at every read/compile boundary, so no data migration was run. See ADR-0012 for
> the design decisions (ADR-0012 supersedes ADR-0010 §7's frontend two-mode decision) and the
> Schedule section below for the full v2 contract — the family table that used to live there is
> gone.
>
> **Polymorphic `creator` (this revision) — CORRECTS previously-documented behavior, not a wire
> change.** `WorkflowRun`'s creator, and every record a step creates (`create_task`,
> `create_form_report`), now attribute correctly and are never left null — see the "Creator
> attribution" note in the `create_form_report` section, the corrected "Origin" note under
> Trigger dispatch pipeline, and the Capability flags table below. Full cross-module reference:
> `docs/backend/creator-attribution.md` and **ADR-0015-polymorphic-creator-ownership.md**.
>
> **Runs monitoring — a GLOBAL cross-workflow feed + a schedule run's `schedule_descriptor` (this
> revision) — ADDITIVE, no breaking change.** A new `GET /workflows/runs` endpoint lists every run
> in the active workspace regardless of which workflow started it, sharing its filter contract
> (`state[]`/`origin[]`/`trigger_type[]`, `date_from`/`date_to`/`date_preset`) with the existing
> `GET /workflows/{workflow}/runs` via one `IndexWorkflowRunsRequest` — plus a global-only
> `workflow_id` scope. Every list filter is now an ARRAY (a legacy single-scalar deep-link such as
> `?state=completed` is still tolerated, coerced to a one-element array) and an unrecognised enum
> member is silently dropped rather than 422'd (unchanged from the original runs index, just
> formalized here). `WorkflowRunResource` additionally carries a `workflow` block on INDEX rows
> (global feed only, `whenLoaded`) and a `schedule_descriptor` on SHOW for a `schedule`-origin run
> (the parent workflow's v2 schedule block, upgraded through `LegacyScheduleUpgrader`) — see the
> Runs endpoints below and **ADR-0016-workflows-global-runs-and-schedule-reason.md** for the design
> record. The human-readable "why did this fire" sentence itself is computed on the FRONTEND
> (`describeOccurrence` in `resources/js/next/pages/workflows/workflowSchedule.ts`), the same
> FE-owns-the-grammar split ADR-0012 established for the schedule sentence — the backend exposes
> data, never prose.
>
> **Retry a FAILED run (this revision) — a new mutating endpoint, ADDITIVE, no breaking change.**
> `POST /workflows/{workflow}/runs/{run}/retry` re-executes a terminal `failed` run. The engine has
> **no mid-run resume** (`WorkflowStepRunner` always executes from the first step), so a "retry"
> STARTS A NEW run for the same workflow, reusing `{run}`'s stored `trigger_payload` (the
> `form_submitted` form/submission snapshot, or the schedule's `scheduled_at`) — the original failed
> row is never mutated. Authorization mirrors run-now exactly (`WorkflowPolicy::run`, any workspace
> member); the new run is `manual`-origin, attributed to the acting user, `pending`, depth 0,
> `origin_run_id` null. **Returns 201 Created**, not the 202 run-now returns — see the endpoint
> section below for the full contrast. A non-`failed` run 422s under the `run` key; the same
> run-budget cap run-now enforces 422s under `workflow`.
>
> **Form-independent variable catalog + a `types` catalog key (this revision, Phase 0 of the
> variable-typesystem rework) — ADDITIVE, no breaking change.** `WorkflowVariableCatalogService`
> is now composed from sources via `forContext(?WorkflowTriggerType, ?Form)` — `forForm()` is a
> thin `FORM_SUBMITTED` specialization of it — so a new `GET /workflows/catalog` endpoint serves a
> real catalog for a form-less workflow (e.g. a `schedule` trigger) instead of the frontend
> maintaining a static mirror of the trigger-system variables / step-output templates. Both catalog
> responses also gained a `types` key — `[{ id, primitive, operators }]`, one entry per
> `VariableType` — describing the full type vocabulary label-lessly (the FE localizes),
> the same pattern `operations`/`ai_personas` already use. See
> **ADR-0021-workflows-variable-catalog-composable-roots.md** for the "adding a root is a 3-point
> change" recipe this groundwork sets up for the rest of the rework (global variables, a loop item,
> a template slot, campaign inputs), and "GET /api/workflows/catalog" / the `types` key below for
> the wire contract.
>
> **Structured `descriptor`, a `TIME` type, per-reference `default`s, and 5 append-only ops (this
> revision, Phase 1 of the variable-typesystem rework) — ADDITIVE, no breaking change.** Every
> catalog variable now ALSO carries a structured `descriptor` (`{ base, nullable, array, options?
> }`) alongside the unchanged flat `type` — enum/multi options carry real `{key,label}` pairs (the
> label lives in the form element's config, not the JSON schema) instead of a bare value list. A
> new `VariableType::TIME` case joins the vocabulary (the form builder's TIME element,
> previously silently folded into `text`) — it is descriptor-only THIS phase: the flat wire `type`
> still degrades it to `text` and it carries no condition operators (`operatorCases()` is `[]`), a
> deliberate loud tripwire rather than a silent `UnhandledMatchError` once real TIME semantics
> land. Both wire serializations of a reference (the markdown directive's `data.default`, the
> `{kind:'variable'}` union's `default`) gained an OPTIONAL literal `default`, substituted for a
> null/`''` lookup BEFORE the pipeline runs, through the SAME NUL-mask injection-guard path a
> resolved value already uses. The operation catalog grows **72 → 77**, append-only: `coalesce`,
> `is_present`, `is_null`, `assert_present` (the type-agnostic presence family — the ONE opt-in
> HARD failure in the pipeline engine), and `date_format` (a safe-token date renderer, never a raw
> PHP format string). See **ADR-0022-workflows-variable-typesystem-phase1.md** for the full design
> record and "Structured `descriptor`", "Per-reference defaults", and "Presence, null-handling,
> and date-format ops" below for the wire contracts.
>
> **Structural types — `object`/`array<object>` containers + the `file` composite (this revision,
> Phase 2 of the variable-typesystem rework) — ADDITIVE, representation only, no breaking
> change.** A catalog variable's `descriptor` may now also carry a recursive `fields` list. A form
> SECTION additionally surfaces as an `object` catalog variable grouping its children — its
> existing flat leaf variables (`section.field`) are UNCHANGED and still the only thing a
> condition/reference actually resolves against. A REPEATER's exclusion (see "Repeaters are
> EXCLUDED from the catalog" below) is LIFTED the same way: it now surfaces as ONE `array<object>`
> entry so the editor can see it exists, though it still has no per-element path. Both bases are
> descriptor-only, mirroring the `TIME` tripwire (Decision 2 of ADR-0022): flat wire `type`
> degrades to `text`, `operatorCases()` is `[]`. A `file` variable's descriptor now ALSO carries
> its fixed `{id,name,type,size,url}` subfields — but, unlike `object`, its flat wire `type` stays
> `file`, so text→name, structural→id(s), copy-on-attach, and the `filled`/`empty` condition
> operators are all UNCHANGED. The trigger snapshot itself gains a `url` key
> (`File::serveUrl()` → the access-controlled `disk.show` route, never a raw storage path). All 5
> subfield paths are individually referenceable, including as PIPELINE-bearing references (the
> write-validation reference index now enumerates them); a repeater's element subfields are
> deliberately NOT. (A LATER batch extends this SAME mechanism to a non-array `object` descriptor's
> own declared fields too — recursively, e.g. a Phase-3 global's interior — leaving the
> repeater/array boundary exactly as stated here; see "Structural descriptor" below.) This is
> REPRESENTATION ONLY — looping a repeater or a multi-file answer is
> explicitly out of scope, deferred to R2-Generator. See
> **ADR-0023-workflows-variable-typesystem-phase2.md** for the full design record (including why
> this is a DIFFERENT slice of work than the "Phase 2" items ADR-0022 deferred) and "Structural
> descriptor: object containers & the file composite" below for the wire contracts.
>
> **User-created LITERAL global variables — a new `globals` catalog source + resolver root (this
> revision, Phase 3 of the variable-typesystem rework) — ADDITIVE, no breaking change.** A
> workspace member may now create a **global**: a named, typed LITERAL constant (`WorkflowGlobal`)
> that becomes a `globals.<key>` reference usable in EVERY workflow — form-independent, resolved
> from its stored `value` at run time. This phase is deliberately scoped to LITERAL storage only:
> no computed values, no references to other variables, no cycle detection (a computed global may
> be a later iteration — see "Planned / deferred"). New CRUD endpoints
> (`GET/POST /workflow-globals`, `GET/PUT/DELETE /workflow-globals/{id}`) let a workspace member
> author one; workspace membership gates read, the creator gates mutation.
> `WorkflowVariableResolver::ROOTS` grows `['trigger','steps']` → `['trigger','steps','globals']`
> (the ADR-0021 3-point recipe applied verbatim — `globals` was already named there as a planned
> example root), `WorkflowStepRunner` injects every global's stored value into the run context as
> a `{<key>: <value>}` map, and `WorkflowVariableCatalogService::globalVariables()` /
> `globalValues()` compose globals into `forContext()` for EVERY trigger type. See
> **ADR-0024-workflows-variable-typesystem-phase3-globals.md** for the full design record and the
> new "Workflow GLOBALS" endpoints (below, under Endpoints) / "The `globals` root" (under "The
> typed variable system") for the wire contracts.
>
> **Array transform operations (this revision) — the operation catalog grows 77 → 83, additive; the
> write-time validator now tracks a full `descriptor` through a pipeline instead of a flat type.**
> Six new operations — `array_count`, `array_at` (whole-array, O(1), no pipeline) and
> `array_map`/`array_filter`/`array_sort`/`array_reduce` (higher-order, each carrying a PER-ELEMENT
> PIPELINE) — let a pipeline iterate ANY array (a `globals`-stored list, a `map`-produced array, an
> `array<object>` repeater, an `array<file>` answer), regardless of its element base. Two new
> synthetic scope variables, `element`/`index`, resolve ONLY inside an element pipeline — never in a
> global root or the trigger condition tree — via a new source-aware
> `App\Modules\Variables\Support\ScopeRef` helper shared by the write-validator, the resolver, and the
> executor. Terminal type is enforced BY CONSTRUCTION: `filter`→`boolean`, `sort`→`number`,
> `reduce`→the seed's own base, `map`→any single base (never another array) — a wrong-terminal
> pipeline can never be saved. See **ADR-0026-workflows-array-transform-operations.md** for the full
> design record and "Array transform operations" (under "The typed variable system") / "g. Array
> transform operations — fail-closed matrix and caps" (under "Runtime operations, if-blocks, and AI
> text") below for the wire contract.
>
> **The Variables module extraction, the Consts rename, and custom Functions (this revision) — a
> PURE refactor plus two additive features; NO wire change to anything documented above this
> point.** The variable TYPE SYSTEM (`VariableType`, `Operation`, `OperationArgType`/`OperationArg`,
> `ArgVariablePolicy`, `OperationResult`, `ScopeRef`, `ValueOrVariable`, the array-op caps) and the
> pipeline OPERATION ENGINE (`OperationExecutor`, and a NEW `PipelineValidator` extracted from
> `WorkflowConditionTreeValidator`'s own pipeline-walking half) moved into a new, lower-layer
> `App\Modules\Variables` module — Workflows depends on it, Variables imports NOTHING from
> Workflows, a one-way boundary asserted by `VariablesModuleBoundaryTest`. `WorkflowConditionTreeValidator`
> itself STAYS in Workflows, now narrower: only the condition-TREE shape (`{logic, children[]}`,
> depth/children caps) and resolving a leaf's `source` against the catalog — it CALLS the new
> `PipelineValidator` per leaf pipeline. See **ADR-0027-variables-module-extraction.md** for the
> full symbol-rename map and the `ElementScopeResolver`/`FunctionReferenceLookup` dependency
> inversions that keep the boundary one-way.
>
> On top of that new module, two things were built. **(1) Consts** — the `workflow_globals`
> table/`WorkflowGlobal` model/`/workflow-globals` URL are RENAMED to `consts`/`Constant`/`/consts`
> (`Const` being a PHP reserved word) and moved into Variables, with a NEW top-level "Variables"
> (PL "Zmienne") frontend nav area replacing the old Workflows sub-page. **The runtime WIRE is
> DELIBERATELY untouched** — a reference is still `globals.<key>`, the resolver whitelist still
> reads `'globals'`, `WorkflowConditionEngine::GLOBALS_ROOT` is still `'globals'` — pinned by
> `ConstantWireCompatTest`. See **ADR-0028-consts-rename.md**. **(2) Custom functions** — a
> workspace member may now define a reusable pipeline OPERATION (one input type, typed named args,
> one return type, a saved body pipeline), reachable at `POST/GET/PUT/DELETE /api/functions` and
> surfaced on every pipeline's `operations` catalog as `fn:<uuid>`. Functions may NEST; a cycle is
> rejected at WRITE time (a 3-colour DFS over the reference graph) and, as a second, independent
> backstop, fails CLOSED at RUNTIME (an expansion-depth cap + an active-function visited-set) if a
> corrupted row ever bypasses the write check. See **ADR-0029-custom-functions.md**, and "Custom
> functions" (under "The typed variable system") / the "Functions" endpoints below for the full
> contract.
>
> **R2 PR-1a — the interpolation engine + the form-independent catalog move one layer further
> down, into Variables (this revision) — a PURE refactor, NO wire change to anything documented
> above this point.** `WorkflowVariableResolver` relocates wholesale to
> `App\Modules\Variables\Services\VariableResolver` (import paths change; every resolution path —
> directives, flat tokens, if-blocks, the structured union, per-reference defaults, argument
> variables, `@[ai-text]` — is unchanged), and the FORM-INDEPENDENT half of
> `WorkflowVariableCatalogService` (the type list, the operation catalog, workspace globals,
> workspace functions) extracts into a new `App\Modules\Variables\Services\VariableCatalog`, which
> `WorkflowVariableCatalogService` now delegates to rather than owns — verified byte-identical by a
> new characterization test (`tests/Unit/Variables/VariableResolverCharacterizationTest.php`) and
> the full pre-existing suite. A new `App\Modules\Variables\Contracts\AiTextGenerator` interface
> replaces the resolver's direct dependency on `WorkflowAiTextService`; Workflows binds its real,
> budgeted implementation in its own provider, unchanged for every existing caller. The reference
> whitelist `ROOTS` widens from `['trigger','steps','globals']` to the superset
> `['trigger','steps','globals','slots']` — `slots` is a NEW root (see below) that stays inert
> (resolves to nothing) in every workflow context. Note: several PHASE-NARRATIVE mentions of
> `WorkflowVariableResolver` further down in this document (the Phase 3/4/array-ops revision notes,
> and the sections under "The typed variable system") describe the class under the name it carried
> AT THAT TIME and are left as historical narrative, not rewritten — the class itself now lives at
> the path above. See **ADR-0030-variable-engine-down-move.md**.
>
> **R2 PR-1b — a new `App\Modules\Generator` module lands, owning generation Templates (this
> revision) — additive; nothing in this document changed by its arrival.** `Generator` depends on
> `Variables` (the engine PR-1a just relocated) and imports NOTHING from `Workflows` — the two are
> SIBLING modules sharing only the lower Variables layer, so a `generate_content` WORKFLOW STEP can
> depend on `Generator` without creating a cycle. A `Template`
> is a reusable, workspace-scoped prompt whose `prompt_body` carries the SAME `@[variable]`
> directive serialization documented in this file, resolved by the SAME `VariableResolver`, over
> its own `slots.<name>` root (the `slots` addition to `ROOTS`, above) instead of `trigger`/`steps`.
> See `docs/backend/generator-api.md` for the full Generator/Templates contract and
> **ADR-0031-generator-module-templates.md** for the design record.
>
> **R2 sub-stage 5 — `generate_content` SHIPPED, along with a generic suspend/resume engine for the
> whole run loop.** The `Workflows → Generator` edge anticipated above is now real: a third step type,
> `generate_content` (`App\Modules\Workflows\Steps\GenerateContentStep`), runs a Generator Template and
> publishes its output. It is the run loop's FIRST — and, per the current design, only —
> **suspending** step: it starts the generation, throws `StepSuspended`, and the run parks in a new
> `WorkflowRunState::WAITING` state until a fresh job resumes it once the generation settles. This
> also means the state-machine table and the "Reserved, not produced" language further down this
> document, and the Ops-notes claim that `create_form_report` is the module's only genuinely-async
> step, are now HISTORICAL and corrected in place — see "Steps: `generate_content`" and "Suspend/
> resume engine" below, and **ADR-0039-workflow-suspend-resume-and-generate-content.md** for the full
> design record.

---

## Concepts

A **Workflow** is a stored automation DEFINITION: `trigger → conditions → an ordered list of
steps`. It does nothing by itself — a **WorkflowRun** is one EXECUTION of that definition,
created when the trigger fires (or a user runs it manually) and driven through its steps by a
queued job. Each step's outcome is recorded as a **WorkflowRunStep** audit row.

```
Workflow (definition)
  └─ trigger_type + trigger_config   (what starts it: form_submitted | schedule)
  └─ conditions[]                    (form_submitted only: typed {field,field_type,operator,value}, AND-combined)
  └─ steps[]                         (ordered actions: create_task, create_form_report, generate_content)

WorkflowRun (one execution)
  └─ state machine: pending → running → completed | failed
                                 │  ▲
                       suspend() │  │ claimResume()      (generate_content only — R2 sub-stage 5)
                                 ▼  │
                                waiting                   (cancelled still reserved, unused)
  └─ origin: event | schedule | manual
  └─ context: { trigger: {...}, steps: { <key>: {...output} } }
  └─ waiting_on / waiting_key / waiting_since   (only while state = waiting — see "Suspend/resume engine")
  └─ WorkflowRunStep[]  (one audit row per executed step, in order — a suspended step writes NO row until it resolves)
```

A workflow is always created **INACTIVE** (`status` is never accepted on create/update — the
body field is ignored/rejected; the ONLY way to flip it is `PATCH /workflows/{id}/status`). An
inactive workflow's trigger never fires from a real domain event, but it CAN still be run
manually (test-before-activate — see the manual-run endpoint below).

### WorkflowStatus

| Value      | Meaning                                                        |
|------------|-----------------------------------------------------------------|
| `active`   | The trigger may fire (event/schedule). Manual run also works.  |
| `inactive` | The trigger never fires from a real event. Manual run still works (test-run). |

### WorkflowTriggerType

| Value             | Fires when…                                                                 |
|--------------------|-------------------------------------------------------------------------------|
| `form_submitted`    | A form submission transitions into its **approved** state (Taskio has no separate "submit" event — a submission counts as submitted once approved). |
| `schedule`           | A cadence fires (a v2 compositional `{ time, day?, month? }` descriptor — see the Schedule section). Never event-dispatched. |

**The other three Etap-5 trigger types (`task_created`, `task_status_changed`,
`approval_finished`) were REMOVED in the 5.1 re-scope** — see ADR-0009 §1. Any workflow
definition or code path referencing them is stale.

### WorkflowRunState

```
pending ──claim──▶ running ──release(completed|failed)──▶ terminal
                     │  ▲
           suspend() │  │ claimResume()
                     ▼  │
                    waiting
```

| Value       | Meaning                                                                 |
|-------------|--------------------------------------------------------------------------|
| `pending`   | Run row created; job not yet claimed it.                                |
| `running`   | Claimed; steps executing.                                                |
| `waiting`   | **Produced by the engine (R2 sub-stage 5).** A step ({@see `generate_content`, currently the only one) threw `StepSuspended` — it handed work to something outside this process (a real generation running on the queue) and cannot publish its output yet. NOT terminal; bounded by `workflows.wait_timeout`, not `run_timeout`. See "Suspend/resume engine" below and ADR-0039. (Superseded: this row previously read "Reserved, not produced by the MVP engine" per ADR-0008 #11 — that is no longer accurate.) |
| `completed` | Every step succeeded.                                                    |
| `failed`    | A step failed (or the job/worker failed) — the run stopped at that step. |
| `cancelled` | **Still reserved, not produced.** Anticipates a future manual-cancel action — unaffected by the suspend/resume engine; a `waiting` run cannot be cancelled today either (see "Planned / deferred"). |

`WorkflowRunState::isTerminal()` is `true` for `completed`, `failed`, `cancelled`.

### WorkflowRunStepStatus

`pending` (row created before execution) → `succeeded` (with an `output` payload) or `failed`
(with an `error`). The runner always writes the row already resolved (`succeeded`/`failed`) —
`pending` exists in the vocabulary but is not observed on a finished step in practice.

---

## Endpoints

### GET /api/workflows

List workflow definitions in the workspace. Cursor-paginated, 8 per page, newest first.

**Query**

| Param    | Required | Notes                                              |
|----------|----------|------------------------------------------------------|
| `search` | no       | case-insensitive match on `name` / `description`   |
| `status` | no       | `active \| inactive` — exact match, omitted if blank |
| `cursor` | no       | cursor from `meta.next_cursor` for the next page   |

**Response** `200 OK`

```json
{ "data": [ WorkflowListResource ], "meta": { "next_cursor": "string | null" } }
```

---

### POST /api/workflows

Create a workflow definition. Always created **inactive** — a `status` field in the body is
silently accepted by the FormRequest (not validated) but never persisted; the DTO does not
carry `status` at all, and `WorkflowService::create()` hardcodes `WorkflowStatus::INACTIVE`.

**Base fields (every trigger type)**

| Field                     | Required             | Constraints                                                        |
|----------------------------|-----------------------|------------------------------------------------------------------|
| `name`                      | yes                    | string, max 255                                                    |
| `description`               | no                      | string, max 2500                                                   |
| `icon`                       | no                      | string, max 100                                                    |
| `trigger_type`               | yes                     | `form_submitted` \| `schedule`                                    |
| `trigger_config`             | per-type                | see the per-trigger sections below                                |
| `conditions`                  | no                       | array, max 50; **only valid for `form_submitted`**; see the Conditions section |
| `conditions.*.field`          | required-if-present      | string, max 255, MUST start with `fields.` (e.g. `fields.abc123`) |
| `conditions.*.field_type`     | required-if-present      | one of `VariableType` (`text\|number\|boolean\|date\|enum\|multi`) |
| `conditions.*.operator`       | required-if-present      | one of `WorkflowConditionOperator`, MUST belong to `field_type`'s allow-list |
| `conditions.*.value`          | required-if-present (unless value-less) | shape depends on the operator — see the Conditions section |
| `steps`                        | yes                      | array, min 1, max 50                                               |
| `steps.*.type`                  | yes                      | `create_task` \| `create_form_report` \| `generate_content` \| `create_event` (at most 2 `generate_content` steps per workflow — see the Steps section) |
| `steps.*.key`                    | yes                      | string, max 100, **distinct across the whole array**, `[A-Za-z0-9_]+` only (letters/digits/underscore — see below) — used for `{{steps.<key>.*}}` |
| `steps.*.config`                 | no                       | object; shape depends on `steps.*.type` (see the Steps section)   |

`status` is NOT in this table — it is not an accepted create field. `PUT` (update) uses the
identical rule set (it subclasses `StoreWorkflowRequest`); only the authorization target
differs (an existing workflow, creator-only).

**Cross-type / cross-step rejection**: any `trigger_config.<key>` outside the submitted
`trigger_type`'s allow-list, or any `steps.*.config.<key>` outside the submitted step's own
allow-list, is rejected as a 422 (`withValidator()` walks the payload and reports the shallowest
foreign segment — e.g. a `schedule.*` block on a `form_submitted` trigger fails on
`trigger_config.schedule`, and a `guidelines` field on a `create_task` step fails on
`steps.<i>.config.guidelines`).

**Errors**

| Code | Field                              | Meaning                                                    |
|------|--------------------------------------|--------------------------------------------------------------|
| 422  | `name`                               | Required, max 255.                                          |
| 422  | `trigger_type`                       | Required; must be `form_submitted` or `schedule`.            |
| 422  | `steps`                              | Missing / empty / more than 50.                              |
| 422  | `steps.*.key`                        | Duplicate key across the step list, OR a character outside `[A-Za-z0-9_]` (reviewer fix — a key is substituted into `steps.<key>.<output>` dotted paths and read via `Arr::get`, so a dot/space would silently break every reference to the step; the frontend mirrors this with a `sanitizeStepKey` guard that strips invalid characters as they are typed). |
| 422  | `steps.*.type`                       | Unknown step type.                                           |
| 422  | `steps.<i>.config.<key>`             | A key not allowed for that step's own type.                  |
| 422  | `trigger_config.<key>`               | A key that does not belong to this trigger type's config (cross-type rejection). |
| 422  | `conditions`                         | Present on a non-`form_submitted` trigger.                    |
| 422  | `trigger_config.form_id`             | Conditions present but no form selected (conditions REQUIRE a form). |
| 422  | (per-trigger / per-step keys — see below) | Missing/invalid field for the submitted type.            |
| 403  | —                                     | User not authenticated or not a workspace member.            |

**Response** `200 OK` — `WorkflowResource` with `creator` loaded.

---

### GET /api/workflows/{id}

Fetch one workflow definition (soft-deleted workflows are NOT found; restore first).

**Response** `200 OK` — `WorkflowResource` with `creator` loaded.

**Errors**: `403` not a workspace member, `404` not found.

---

### PUT /api/workflows/{id}

Update a workflow. Same validation rules as `POST`. Authorization: creator only
(`WorkflowPolicy::update`). Never touches `status` — the status endpoint owns that transition.
If the workflow is an ACTIVE schedule workflow, changing its `trigger_config.schedule` cadence
**re-arms** `next_due_at` from the new cadence (see the Schedule section).

**Response** `200 OK` — `WorkflowResource`. **Errors**: `403` not creator, `404` not found,
`422` validation (same table as POST).

---

### DELETE /api/workflows/{id}

Soft-delete a workflow. Authorization: creator only.

**Response** `200 OK` — `{ "message": "Workflow deleted successfully" }`.
**Errors**: `403` not creator, `404` not found.

---

### POST /api/workflows/{id}/restore

Restore a soft-deleted workflow. `{id}` resolves through `Workflow::withTrashed()`.
Authorization: creator only (`WorkflowPolicy::restore`).

**Response** `200 OK` — `WorkflowResource`. **Errors**: `403` not creator, `404` not found
(including trashed).

---

### PATCH /api/workflows/{workflow}/status

Toggle `active | inactive`. **The only path that mutates status.** Authorization: creator only
(`WorkflowPolicy::changeStatus`).

**Body**

```json
{ "status": "active" }
```

**Effect on scheduling:** activating an ACTIVE schedule-triggered workflow **arms**
`next_due_at` from its cadence; deactivating **nulls** `next_due_at` so the schedule sweep's
`WHERE` clause never sees it. A `form_submitted` workflow is unaffected either way.

**Response** `200 OK` — `WorkflowResource`. **Errors**: `403` not creator, `404` not found,
`422` unknown status value.

---

### POST /api/workflows/{workflow}/run

Manually run a workflow **on demand** — works on ANY workflow, **including an INACTIVE one**
(test-before-activate). Authorization: any workspace member (`WorkflowPolicy::run` mirrors
`view` — running is not creator-gated). Returns **202 Accepted**: the run executes asynchronously
under a real queue, or in-process immediately under `QUEUE_CONNECTION=sync`.

**Body**

```json
{ "target_id": "b1b2c3d4-..." }
```

Target requirement depends on the workflow's `trigger_type`:

| Trigger type       | `target_id` resolves to…                                        | Required? |
|----------------------|----------------------------------------------------------------------|-----------|
| `form_submitted`      | a `FormSubmission` (tenant-scoped)                                   | yes       |
| `schedule`              | none — payload is the minimal `{ scheduled_at }` a real sweep would build | no (omit `target_id`) |

**Response** `202 Accepted` — `WorkflowRunResource` (the stable core shape: id, state, origin,
trigger_type, timing, error — no `steps`/`trigger_payload`, since neither is loaded for this
shape).

```json
{
  "data": {
    "id": "c1c2c3c4-...",
    "state": "completed",
    "state_label": "Zakończony",
    "state_tone": "success",
    "origin": "manual",
    "trigger_type": "form_submitted",
    "depth": 0,
    "origin_run_id": null,
    "error": null,
    "started_at": "2026-07-09T10:00:00.000000Z",
    "finished_at": "2026-07-09T10:00:01.000000Z",
    "created_at": "2026-07-09T10:00:00.000000Z",
    "duration_seconds": 1
  }
}
```

**Manual runs COUNT toward the run-budget caps and are REFUSED (422), not silently skipped**,
at the ceiling — the opposite of an event trigger, which skips silently. See Cost limits below.

**422 error-key contract — EXACTLY two keys** (the frontend maps these by **key**, never by
message; the Polish prose may change). The 5.1 re-scope DROPPED the `approval_process` key that
existed under the Etap-5 `approval_finished` trigger — that trigger type no longer exists, so
there is nothing left to key a "no concluded process" error under:

| Key          | When                                                                  |
|---------------|--------------------------------------------------------------------------|
| `target_id`   | Missing (for `form_submitted`, which needs one) or well-formed-but-unresolvable (not found in this tenant). |
| `workflow`     | The run-budget cap (per-workflow monthly or workspace-wide hard cap) is already reached. |

```json
// target_id unresolvable
{ "message": "...", "errors": { "target_id": ["Wskazane wysłanie formularza nie istnieje w tej przestrzeni roboczej."] } }

// cap reached
{ "message": "...", "errors": { "workflow": ["Ten workflow osiągnął limit uruchomień na ten miesiąc. Uruchomienie zostało zablokowane."] } }
```

A manual run's payload is built by the **same** `WorkflowTriggerPayloadFactory` a real event
uses, so `{{trigger.*}}` / directive references resolve identically regardless of how the run
started — a test-run proves what a real trigger would do.

**vs. retry** (`POST /workflows/{workflow}/runs/{run}/retry`, below): run-now builds a FRESH
payload from a caller-supplied `target_id` and returns **202 Accepted**; retry reuses an existing
FAILED run's already-stored `trigger_payload` (no `target_id`, no body at all) and returns **201
Created**. Both share the same authorization and the same run-budget cap.

---

### GET /api/workflows/{workflow}/runs

Read-only monitoring: a workflow's runs, cursor-paginated (15/page), newest first
(`created_at DESC`, `id DESC` tiebreak — UUIDv7 ids are monotonic, so this keeps pagination
stable across a boundary where several runs share a timestamp). Authorization: `view` on the
parent workflow (any workspace member — runs are workspace-visible read-only monitoring).
Validation + filtering are shared with the global feed below via ONE `IndexWorkflowRunsRequest` +
`WorkflowRunService` — see that endpoint for the full filter reference; this endpoint additionally
hard-scopes to `{workflow}` (a `workflow_id` query param, if sent, is simply ignored here — the
URL already pins the workflow).

**Query** (all optional, all AND-combined)

| Param             | Notes                                                                 |
|-------------------|------------------------------------------------------------------------|
| `state[]`         | subset of `WorkflowRunState`. Also accepts a single legacy scalar (`?state=completed`), coerced to a one-element array. An unrecognised member is **silently dropped** (no filter, no error) — mirrors the Bot inbox `state` filter. |
| `origin[]`        | subset of `WorkflowRunOrigin`. Same array/legacy-scalar/tolerant-drop rules as `state[]`. |
| `trigger_type[]`  | subset of `WorkflowTriggerType`. Same array/legacy-scalar/tolerant-drop rules. |
| `date_from` / `date_to` | a date range on `created_at`, via the shared `scopeFilterByDate` every next list screen already emits. |
| `date_preset`     | `today \| this_week \| last_week \| this_month` — a shortcut inherited from the same scope. |
| `cursor`          | cursor from `meta.next_cursor`.                                        |

**Response** `200 OK` — list shape carries `steps_count` (via `withCount`) but **excludes**
`trigger_payload` and `steps` (potentially large; detail-only). The per-workflow index does NOT
eager-load the `workflow` relation (the caller already has the workflow context from the URL), so
rows here carry no `workflow` block — that is a global-feed-only addition, see below:

```json
{
  "data": [
    {
      "id": "c1c2c3c4-...",
      "state": "completed",
      "state_label": "Zakończony",
      "state_tone": "success",
      "origin": "event",
      "trigger_type": "form_submitted",
      "depth": 0,
      "origin_run_id": null,
      "error": null,
      "started_at": "2026-07-09T10:00:00.000000Z",
      "finished_at": "2026-07-09T10:00:01.000000Z",
      "created_at": "2026-07-09T10:00:00.000000Z",
      "duration_seconds": 1,
      "steps_count": 2
    }
  ],
  "meta": { "next_cursor": "string | null" }
}
```

**Errors**: `403` not a member, `404` unknown workflow.

---

### GET /api/workflows/runs

The GLOBAL runs feed: every run in the active workspace, regardless of which workflow started it
— cursor-paginated (15/page), newest first, same ordering/tiebreak as the per-workflow index.
Declared BEFORE the `workflows/{workflow}` apiResource route so the static `runs` segment always
wins the match. Authorization: `viewAny` on `Workflow` (`$user !== null` — any authenticated
workspace member; cross-workspace isolation is enforced upstream by `ResolveWorkspace` +
`WorkspaceScope`, not by this policy check). Backed by `WorkflowRunController::global()` →
`WorkflowRunService::runsGlobal()`.

**Query** — the same `state[]` / `origin[]` / `trigger_type[]` / `date_from` / `date_to` /
`date_preset` / `cursor` params as the per-workflow index above, **plus**:

| Param         | Notes                                                                     |
|---------------|----------------------------------------------------------------------------|
| `workflow_id` | nullable uuid. Honored **only** by this global feed — scopes the feed to one workflow (an alternative entry point to the per-workflow index, e.g. from a saved view). Not validated against tenant scope explicitly; a foreign-workspace id simply matches nothing (`WorkspaceScope` already isolates the base query). |

**Response** `200 OK` — the SAME row shape as the per-workflow index, with one addition: each row
carries a `workflow` block (`id`, `name`, `icon`, `status`, `trigger_type`) — the parent workflow
is eager-loaded (`with('workflow')`) specifically for this feed, since the caller has no other way
to know which workflow a row belongs to:

```json
{
  "data": [
    {
      "id": "c1c2c3c4-...",
      "state": "completed",
      "state_label": "Zakończony",
      "state_tone": "success",
      "origin": "event",
      "trigger_type": "form_submitted",
      "depth": 0,
      "origin_run_id": null,
      "error": null,
      "started_at": "2026-07-09T10:00:00.000000Z",
      "finished_at": "2026-07-09T10:00:01.000000Z",
      "created_at": "2026-07-09T10:00:00.000000Z",
      "duration_seconds": 1,
      "steps_count": 2,
      "workflow": {
        "id": "b1b2c3d4-...",
        "name": "Escalate urgent tickets",
        "icon": "workflow",
        "status": "active",
        "trigger_type": "form_submitted"
      }
    }
  ],
  "meta": { "next_cursor": "string | null" }
}
```

**Errors**: `403` not an authenticated workspace member.

---

### GET /api/workflows/{workflow}/runs/{run}

One run with its full detail: `trigger_payload` and the ordered `steps` timeline (eager-loaded,
ordered by `position`). **Nested-ownership guard**: `{run}` is bound independently of
`{workflow}` (both resolve through `WorkspaceScope`), so the controller explicitly checks
`$run->workflow_id === $workflow->id` and throws a 404 if a run from a DIFFERENT workflow (but
the same workspace) is requested under this workflow's URL — otherwise it would leak through.

**Response** `200 OK`

```json
{
  "data": {
    "id": "c1c2c3c4-...",
    "state": "failed",
    "state_label": "Błąd",
    "state_tone": "danger",
    "origin": "event",
    "trigger_type": "form_submitted",
    "depth": 0,
    "origin_run_id": null,
    "error": "create_form_report step could not find form `...` in this workspace.",
    "started_at": "2026-07-09T10:00:00.000000Z",
    "finished_at": "2026-07-09T10:00:01.000000Z",
    "created_at": "2026-07-09T10:00:00.000000Z",
    "duration_seconds": 1,
    "trigger_payload": { "form": { "id": "...", "name": "...", "is_anonymous": false }, "submission": { "id": "..." }, "source": "manual", "task": null, "submitted_at": "2026-07-09T09:59:00.000000Z", "fields": { "status": "urgent" } },
    "steps": [
      {
        "id": "d1d2d3d4-...",
        "position": 0,
        "type": "create_task",
        "key": "make_task",
        "status": "succeeded",
        "status_label": "Zakończony",
        "status_tone": "success",
        "payload": { "task_id": "...", "title": "..." },
        "error": null,
        "created_at": "2026-07-09T10:00:00.500000Z"
      },
      {
        "id": "e1e2e3e4-...",
        "position": 1,
        "type": "create_form_report",
        "key": "make_report",
        "status": "failed",
        "status_label": "Błąd",
        "status_tone": "danger",
        "payload": null,
        "error": "create_form_report step could not find form `...` in this workspace.",
        "created_at": "2026-07-09T10:00:01.000000Z"
      }
    ]
  }
}
```

**`schedule_descriptor` — SCHEDULE runs only.** When the run's `trigger_type` is `schedule`, the
response additionally carries `schedule_descriptor`: the parent workflow's `trigger_config.schedule`
block, upgraded to the v2 shape (`{ time, day?, month?, exclusions?, tz? }`, same shim
`WorkflowResource` uses — see the Schedule section) via `LegacyScheduleUpgrader`. Absent
(`null`/omitted key) for a `form_submitted` run, or for a schedule run whose workflow carries no
schedule block. This is DATA only — the backend does not compute a "why did this run fire" sentence;
the frontend derives that itself (`describeOccurrence`) from `schedule_descriptor` plus the run's own
`trigger_payload.scheduled_at`:

```json
{
  "data": {
    "id": "f1f2f3f4-...",
    "state": "completed",
    "state_tone": "success",
    "origin": "schedule",
    "trigger_type": "schedule",
    "trigger_payload": { "scheduled_at": "2026-07-09T08:00:00.000000Z" },
    "schedule_descriptor": {
      "time": { "mode": "at", "at": ["08:00"] },
      "day": { "mode": "weekdays", "weekdays": [1, 3, 5] },
      "tz": "Europe/Warsaw"
    }
  }
}
```

**Errors**: `403` not a member, `404` unknown run, or a run belonging to a different workflow.

---

### POST /api/workflows/{workflow}/runs/{run}/retry

Retry a **FAILED** run: starts a **NEW** run for `{workflow}`, reusing `{run}`'s stored
`trigger_payload`. The engine has **no mid-run resume** — `WorkflowStepRunner` always executes
from the first step — so this is not a resume, it is a fresh run over the same trigger context
(the `form_submitted` form/submission snapshot, or the schedule's `scheduled_at`). The original
failed run is never mutated — it stays `failed` and a second retry of it is still allowed. Route
name `workflows.runs.retry`. Authorization: same as run-now, any workspace member
(`WorkflowPolicy::run` — not creator-gated).

**Nested-ownership + cross-tenant guards** (both a 404, same as `GET .../runs/{run}` above, and
enforced in `RetryWorkflowRunRequest::authorize()` before the terminal-state check ever runs):
`{run}` must belong to `{workflow}` (a run of a DIFFERENT workflow in the same workspace 404s),
and `{run}` must belong to the ACTIVE workspace (route-model binding runs BEFORE
`ResolveWorkspace`, so the request re-checks the run through the now-active `WorkspaceScope` — a
cross-workspace run 404s rather than leaking a 403 that would confirm it exists elsewhere).

**Body**: none (empty `POST`).

**Response** `201 Created` — `WorkflowRunResource`, the SAME stable-core shape run-now returns (no
`steps`/`trigger_payload` — neither is loaded for this shape), but describing the **NEW** run, not
the retried one:

```json
{
  "data": {
    "id": "g1g2g3g4-...",
    "state": "pending",
    "state_label": "Oczekuje",
    "state_tone": "neutral",
    "origin": "manual",
    "trigger_type": "form_submitted",
    "depth": 0,
    "origin_run_id": null,
    "error": null,
    "started_at": null,
    "finished_at": null,
    "created_at": "2026-07-15T10:05:00.000000Z",
    "duration_seconds": null
  }
}
```

The new run is always `origin: "manual"` and `creator` = the acting user (`creator_type: "user"`)
regardless of what started the original failed run (`event`/`schedule`/`manual`) — a retry is a
user-initiated action, like run-now. `depth` is always `0` and `origin_run_id` is always `null`:
retrying does not extend the original run's re-trigger chain, it starts a new top-level one.

**Errors**

| Code | Key         | When                                                                                          |
|------|-------------|------------------------------------------------------------------------------------------------|
| 401  | —           | Guest (unauthenticated).                                                                       |
| 403  | —           | Authenticated but not a member of the workflow's workspace.                                     |
| 404  | —           | `{run}` missing, belongs to a DIFFERENT workflow than `{workflow}` (foreign-workflow), or belongs to a DIFFERENT workspace (cross-tenant) — all three collapse to "does not exist under this parent", same as `GET .../runs/{run}`. |
| 422  | `run`       | `{run}` is not in the terminal `failed` state — `pending`/`running`/`waiting`/`completed`/`cancelled` are all rejected (a completed run is not "with an error"). |
| 422  | `workflow`  | The run-budget cap is already reached — the SAME per-workflow monthly soft cap + workspace-wide hard cap `POST .../run` enforces. |

```json
// not a failed run
{ "message": "...", "errors": { "run": ["Ponowić można tylko uruchomienie zakończone błędem."] } }

// cap reached
{ "message": "...", "errors": { "workflow": ["Ten workflow osiągnął limit uruchomień na ten miesiąc. Uruchomienie zostało zablokowane."] } }
```

**vs. `POST /workflows/{workflow}/run` (run-now, above)** — same authorization and the same
run-budget cap, but retry returns **201 Created** (a fresh run over the FAILED run's already-stored
`trigger_payload`, no request body) where run-now returns **202 Accepted** (a fresh run over a
caller-supplied `target_id`).

---

### ~~GET /api/workflows/meta/schedule-families~~ — REMOVED (v2)

The REV3 family-discovery endpoint (the 16-family vocabulary + per-family param descriptors) no
longer exists. The v2 compositional descriptor has no closed family list to discover — the
frontend owns the axis/mode/label inventory itself and mirrors every numeric bound from
`App\Modules\Workflows\Enums\ScheduleLimits` as a TypeScript constant
(`SCHEDULE_LIMITS` in `resources/js/next/pages/workflows/workflowSchedule.ts`), so there is nothing
left for a runtime discovery call to serve. A request to this path now 404s (unmatched route).

---

### POST /api/workflows/meta/schedule-preview

LIVE SCHEDULE PREVIEW: projects the next N fire instants of a proposed (not-yet-saved) v2 cadence,
so the FE schedule builder can show a running "next runs" list as the user edits, and can centre
that list on an arbitrary anchor instant ("jump to date"). Static path, declared before the
`{workflow}` resource. Any authenticated member may call it — it exposes no tenant data, only a
projection over the public cadence grammar. Backed by `WorkflowSchedulePreviewController` +
`WorkflowScheduleService::nextOccurrences()` / `occurrencesFrom()` (PURE functions — no tenancy, no
model writes). Throttled `60,1` (per user) on top of the FE's own debounce, since the projection
loop is CPU-bound.

**Body**

```json
{ "schedule": { "time": { "mode": "at", "at": ["08:00"] }, "day": { "mode": "weekdays", "weekdays": [1, 3] }, "tz": "Europe/Warsaw" }, "count": 6 }
```

| Field       | Required | Constraints                                                          |
|--------------|-----------|--------------------------------------------------------------------------|
| `schedule`     | **yes**    | the same v2 `{ time, day?, month?, exclusions?, tz? }` block the write path accepts, validated by the SAME `WorkflowScheduleRulesValidator` — with ONE difference (below). A legacy `{ family, params }` block is upgraded through the read-shim first (`LegacyScheduleUpgrader`), so a not-yet-migrated draft still previews. |
| `count`          | no          | integer 1–12, default 6 — how many occurrences to project.       |
| `anchor`           | no          | an ISO-8601 datetime string to centre the projection on (see below). Omitted ⇒ project forward from `now()`. |

**The ONE validation difference from the write path**: `SchedulePreviewRequest` calls
`WorkflowScheduleRulesValidator::secondPass(..., checkEmpty: false)` — the empty-schedule guard
(the check that rejects a cadence with no reachable occurrence) is OFF. Every STRUCTURAL rule
(unknown mode, out-of-bounds field, malformed window/exclusions, a field foreign to the chosen
mode) still returns a 422 exactly as it would on save. An over-constrained but otherwise
well-formed schedule (e.g. `day.weekdays:[1]` whose `exclusions.weekdays` also excludes Monday) is
NOT a 422 here — it comes back as DATA (`empty: true`), so the FE can render a pre-save warning
instead of surfacing a confusing validation error for a block that is otherwise well-formed.

**`anchor` — centres the projection instead of starting from `now()`.** An ISO-8601 datetime.
WITHOUT a UTC offset it is read as a WALL-CLOCK time in the schedule's own `tz`; WITH an offset it
is an absolute instant (the offset wins). When present, `occurrences[0]` is the occurrence AT OR
BEFORE the anchor (prev-or-at) when one exists within the horizon, followed by the ascending
occurrences strictly after it — so a caller can render a "previous" tile distinct from the
forward list. **Paging is a plain re-call**: to fetch the next page, call again with `anchor` set
to the last occurrence already shown; there is no separate cursor field. Omitting `anchor` behaves
exactly like the pre-anchor contract (`nextOccurrences()` from `now()`).

**Response** `200 OK` — FLAT, no `data` wrapper:

```json
{
  "occurrences": ["2026-07-13T06:00:00.000000Z", "2026-07-15T06:00:00.000000Z", "2026-07-20T06:00:00.000000Z"],
  "count": 6,
  "empty": false,
  "approximate": false
}
```

| Field           | Meaning                                                                                    |
|-------------------|-----------------------------------------------------------------------------------------------|
| `occurrences`       | ISO-8601 UTC instants, ASCENDING — at most `count`, may be FEWER (or `[]`) when the cadence has no reachable occurrence within `WorkflowScheduleService`'s horizon (1000 iterations / 10 years). Without `anchor` every entry is strictly after `now()`; WITH `anchor`, `occurrences[0]` may equal the anchor's prev-or-at occurrence exactly (the rest are strictly ascending after it). Same string format `WorkflowResource` uses for `next_due_at` (`toISOString()`), so the FE parses one shape everywhere. |
| `count`               | echoes the resolved (defaulted/clamped) count that was requested.                             |
| `empty`                 | `true` when `occurrences` is `[]` — an over-constrained `exclusions` set (or, in principle, any cadence the compiler cannot resolve). This is DATA, never a 422, on this endpoint. |
| `approximate`             | **ALWAYS `false` in v2.** Every v2 cadence is a wall-clock-anchored grid (there is no phase-from-activation interval left — see `CompiledSchedule`'s docblock), so a projection is always exact. The field is KEPT (not dropped) purely for response-shape stability; do not read it as a live signal. |

**This is the ONLY place occurrence dates are computed for the FE.** The frontend never
locally re-implements cron math to render a preview or a "next run" hint — every preview
surface (the builder's live preview strip, the AI-assist modal's proposal preview) calls this
endpoint.

---

### GET /api/forms/{form}/workflow-catalog

The TYPED variable catalog for a `form_submitted` workflow built on `{form}`: the reference-able
variables (trigger system vars + per-form field vars + step outputs) and the condition field
descriptors. This is the contract the workflow editor and AI-assist consume. Authorization:
`FormPolicy::view` (the endpoint lives in the Workflows module — it owns the "variable catalog"
concept — but binds a `Form` and defers to the Forms module's own view policy, mirroring how the
dispatch service already reads `Form`).

**Response** `200 OK`

```json
{
  "data": {
    "variables": [
      { "source": "trigger", "path": "trigger.submission.id", "name": "Submission ID", "type": "text" },
      { "source": "trigger", "path": "trigger.form.id", "name": "Form ID", "type": "text" },
      { "source": "trigger", "path": "trigger.form.name", "name": "Form name", "type": "text" },
      { "source": "trigger", "path": "trigger.source", "name": "Source", "type": "enum", "enumOptions": ["manual", "task"] },
      { "source": "trigger", "path": "trigger.submitted_at", "name": "Submitted at", "type": "date" },
      { "source": "trigger", "path": "trigger.task.id", "name": "Task ID", "type": "text", "nullable": true },
      { "source": "trigger", "path": "trigger.fields.status", "name": "Status", "type": "enum", "enumOptions": ["open", "urgent"] },
      { "source": "steps", "path": "steps.create_task.task_id", "name": "Utwórz zadanie · task_id", "type": "text" },
      { "source": "steps", "path": "steps.create_task.title", "name": "Utwórz zadanie · title", "type": "text" },
      { "source": "steps", "path": "steps.create_form_report.report_id", "name": "Utwórz raport formularza · report_id", "type": "text" },
      { "source": "steps", "path": "steps.create_form_report.report_name", "name": "Utwórz raport formularza · report_name", "type": "text" }
    ],
    "fields": [
      { "path": "fields.status", "field_id": "status", "label": "Status", "type": "enum", "operators": ["is", "is_not", "in"], "enumOptions": ["open", "urgent"] }
    ]
  }
}
```

A `variable` is `{ source, path, name, type, enumOptions?, nullable? }` — `source` is
`'trigger' | 'steps'`, `path` is the FULL dotted path a reference resolves against (identical in
both serializations — the editor directive and the structured union), `type` is a
`VariableType`. A `field` descriptor (the CONDITION builder's subset) is `{ path,
field_id, label, type, enumOptions?, operators }` — `operators` is exactly the type's allowed
operator set (`VariableType::operatorCases()`), so the FE can never offer an operator the
backend would reject.

**Repeaters are EXCLUDED from the catalog** — honest, not an oversight: a repeater's answers are
an array-of-objects (JSONB) that `fields.<id>` cannot resolve to a single comparable
scalar/flat-set, so emitting a variable for one would be a dead path (a reference that always
resolves to something the condition evaluator or a step config could never meaningfully use).
See ADR-0009 §7.

**Update (Phase 2a, ADR-0023) — that exclusion is now narrower.** A repeater still has no FLAT
LEAF variable of its own (the statement above is unchanged for that case), but it now ALSO
surfaces as ONE `array<object>` container entry — see "Structural descriptor: object containers &
the file composite" below. A SECTION, similarly, now ALSO surfaces as an `object` container
alongside its unchanged flat leaves. Neither container is a condition source or offers a
per-element path.

---

### GET /api/workflows/catalog

The FORM-INDEPENDENT variable catalog — the same catalog contract as
`GET /api/forms/{form}/workflow-catalog` above, but assembled from sources
(`WorkflowVariableCatalogService::forContext()`) so a workflow with **no form at all** — a
`schedule` trigger, or a `form_submitted` workflow before a form is chosen — still gets a real
catalog instead of the frontend maintaining a static mirror of the trigger-system variables and
step-output templates. A static `workflows/catalog` path, declared BEFORE the `{workflow}`
apiResource routes so it never binds as an id. Backed by
`WorkflowVariableCatalogController::index()` → `IndexWorkflowCatalogRequest` →
`WorkflowVariableCatalogService::forContext()`.

**Query**

| Param          | Notes                                                                                        |
|----------------|-------------------------------------------------------------------------------------------------|
| `trigger_type` | **required WITHOUT `form_id`** (`required_without:form_id`) — one of `WorkflowTriggerType::ids()` (`form_submitted`, `schedule`). Ignored when `form_id` is present (a form implies `form_submitted`, matching `forForm()`). |
| `form_id`      | optional uuid. When present, layers that form's field variables + condition field descriptors onto the structural catalog, exactly like the form-bound route.                                       |

**Two authorization paths** (`IndexWorkflowCatalogRequest::authorize()`):

- **No `form_id`** — `WorkflowPolicy::viewAny` (`$user !== null`, any authenticated user). The
  response carries **structural metadata only** — trigger-system vars for `trigger_type`, the
  `steps.<TYPE>.*` output templates, `operations`, `ai_personas`, `types` — **no tenant rows and no
  per-form field variables** — so authentication alone is enough to gate it; a workspace header is
  NOT required to call it (unlike the blanket "all endpoints require ... X-Workspace-Id" note at
  the top of this document), though membership is still enforced upstream by `ResolveWorkspace`
  whenever one is sent.
- **With `form_id`** — the form is resolved under `WorkspaceScope` (`Form::find`) and gated by
  `FormPolicy::view`, exactly like the `{form}` route-model binding above. A foreign-workspace or
  nonexistent `form_id` therefore **404s** (mirrors the binding failure), not 403.

**Response** `200 OK` — the identical envelope/shape `GET /api/forms/{form}/workflow-catalog`
returns (`{ data: { variables, fields, operations, ai_personas, types } }`; see the `types` key
below); a form-less call simply returns `fields: []` and no `trigger.fields.*` variables. Example
(`?trigger_type=schedule`, no form):

```json
{
  "data": {
    "variables": [
      { "source": "trigger", "path": "trigger.scheduled_at", "name": "Scheduled at", "type": "date" },
      { "source": "steps", "path": "steps.create_task.task_id", "name": "Utwórz zadanie · task_id", "type": "text" }
    ],
    "fields": [],
    "operations": [...],
    "ai_personas": [{ "id": "neutral" }, { "id": "friendly" }, { "id": "formal" }, { "id": "concise" }],
    "types": [...]
  }
}
```

**Errors**: `401` unauthenticated. `422` under `trigger_type` when both `trigger_type` and
`form_id` are absent, or `trigger_type` names an id outside `WorkflowTriggerType::ids()`. `404`
when `form_id` is a well-formed uuid that does not resolve in the active workspace (foreign or
missing).

This is why the endpoint exists: before Phase 0 of the variable-typesystem rework, a schedule
workflow's editor had no server catalog to call at all and carried a hand-maintained mirror of the
trigger-system variables / step-output templates instead — see
**ADR-0021-workflows-variable-catalog-composable-roots.md**.

---

### POST /api/workflows/schedule-assist

AI SCHEDULE ASSIST: turns a natural-language schedule description into the structured v2
`trigger_config.schedule` config (`{ time, day?, month?, exclusions?, tz? }`) the human builder
uses — or an honest report of what cannot be expressed, with an optional approximating
alternative. Authorization: any authenticated workspace member (workspace membership already
enforced upstream by `ResolveWorkspace`; the endpoint exposes no tenant data).

**The prompt's vocabulary is assembled programmatically from the v2 enums** — `ScheduleAssistAgent`
builds its instructions from `ScheduleTimeMode`, `ScheduleDayMode`, `ScheduleMonthMode`,
`ScheduleDaySpecial` and `ScheduleLimits` at construction time (one line per mode, one bound per
constant), so the model is never told about a mode or a bound the validator/compiler do not
actually enforce — the prompt cannot drift from the code. A model that still answers in the
PRE-v2 `{ family, params }` shape is not rejected either: the re-validation gate below runs every
proposed config through `LegacyScheduleUpgrader` first, so an old-format proposal is judged by the
same v2 rules and, if valid, returned **normalized to v2** — both `config` and `alternative.config`
are rewritten through the upgrader before the envelope is returned, so the FE always receives v2.

**Genuinely feasible today** (a non-exhaustive sample — see `ScheduleAssistAgent::examplesSection()`
for the full worked list the model is shown): multiple daily fire times ("o 8 i 17"), a set of
weekdays in one schedule ("w poniedziałki i środy"), the Nth/last weekday of the month ("ostatni
piątek miesiąca"), the last working day of the month, an every-N-days/hours/minutes grid with an
optional time-of-day window, and an `exclusions` skip filter ("oprócz weekendów", "oprócz sierpnia").

**Still genuinely infeasible** (see `ScheduleAssistAgent::semanticCaveats()` for the exact, current
list the model is instructed to report honestly): a rolling interval the wall-clock grids cannot
express (e.g. "dokładnie co 90 minut", "co 2,5 godziny" — the grids are modulo-N, not phased from
an arbitrary start), "every N weeks" (no such axis), one-off single dates, and sub-minute cadences.
That list is **exhaustive**: the VOCABULARY is the only thing that may make a request infeasible.

**Under-specified is NOT infeasible.** A request the descriptor expresses fine but which the user
did not pin down — a vague time of day ("rano", "po południu", "wieczorem"), no time at all
("codziennie"), a fuzzy count ("kilka razy dziennie") — is answered `feasible:true` with concrete
values CHOSEN by the model and NAMED in `explanation`, so the author sees them and can adjust them
in the builder. Disclosure is what separates this from silently substituting a different schedule:
an approximated *pattern* still only ever lands in `alternative` with `feasible:false`.

**Calendar knowledge is used, not refused.** There is no "public holiday" axis and there will not
be one (jurisdiction-specific, moves yearly, would need a data feed) — but holidays ARE expressible
as concrete `exclusions.dates`, so the agent enumerates them from its own knowledge instead of
reporting "no holiday support". "Dni wolne od pracy" therefore compiles to weekdays `[1..5]` (or
`exclusions.weekdays [0,6]`) plus the dated public holidays, capped at `EXCLUSIONS_DATES_MAX` (50).
The service passes **today's date in the caller's `tz`** to the agent for exactly this reason:
without that anchor the enumerated year is whatever the model's training suggests, producing a
config that validates and compiles cleanly while excluding the wrong days. The date list is fixed,
not a live feed — the agent states which years it covered in `explanation`. When no date can be
resolved, the agent is instructed to emit no dated exclusions at all rather than guess a year.

**Body**

```json
{ "prompt": "codziennie o 8 i 17 oprócz weekendów", "tz": "Europe/Warsaw" }
```

| Field    | Required | Constraints                                    |
|-----------|-----------|-------------------------------------------------|
| `prompt`   | yes        | string, max 500 — UNTRUSTED free text, treated purely as data by the agent. |
| `tz`         | no          | nullable, a valid IANA timezone string.         |

**Response** `200 OK` — a feasible multi-time-plus-exclusions example (pinned by
`WorkflowScheduleAssistTest::test_feasible_multi_time_config_with_exclusions_passes_the_gate`):

```json
{
  "data": {
    "feasible": true,
    "config": { "time": { "mode": "at", "at": ["08:00", "17:00"] }, "exclusions": { "weekdays": [0, 6] }, "tz": "Europe/Warsaw" },
    "unsupported": [],
    "alternative": null,
    "explanation": "Codziennie o 8:00 i 17:00, z pominięciem weekendów."
  }
}
```

A feasible example using the `day.special` axis (the last weekday of the month — previously only
reachable through the `alternative` channel in the pre-v2 vocabulary):

```json
{
  "data": {
    "feasible": true,
    "config": { "time": { "mode": "at", "at": ["09:00"] }, "day": { "mode": "special", "special": "last_weekday", "weekday": 5 }, "tz": "Europe/Warsaw" },
    "unsupported": [],
    "alternative": null,
    "explanation": "Ustawiłem harmonogram na ostatni piątek każdego miesiąca o 9:00 (Europe/Warsaw)."
  }
}
```

An honest infeasible example WITH a valid alternative (pinned by
`test_infeasible_with_valid_alternative_is_passed_through` — "co 2 tygodnie", every-other-week, has
no axis; the model honestly falls back to a plain weekly schedule):

```json
{
  "data": {
    "feasible": false,
    "config": null,
    "unsupported": ["co 2 tygodnie — brak osi „co N tygodni”"],
    "alternative": { "config": { "time": { "mode": "at", "at": ["08:00"] }, "day": { "mode": "weekdays", "weekdays": [1] } }, "note": "Zaproponowano co tydzień w poniedziałek zamiast co 2 tygodnie." },
    "explanation": "Nie można ustawić co 2 tygodnie; proponuję co tydzień w poniedziałek."
  }
}
```

**429** — `WORKFLOWS_ASSIST_RATE_PER_MINUTE` (default 5) exceeded for this user, keyed
`workflow-schedule-assist:{userId}`. **This throttle needs a PERSISTENT cache store** — the
default `database` cache driver is fine in production; under the `array` driver the counter
resets every process (fine for tests, wrong for a real multi-request server). See Ops notes.

**The re-validation guarantee.** The model's `feasible`/`config` self-report is NEVER trusted
directly:

1. The agent runs tool-less, emitting a strict JSON string (`ScheduleAssistAgent`) — parsed
   defensively; malformed/empty output collapses to a safe `feasible:false` envelope, never a
   500.
2. Every response key is WHITELISTED — unknown keys the model invented are dropped before
   reaching the API response. The whitelist forwards BOTH the v2 axis keys (`time`, `day`,
   `month`, `exclusions`) AND the legacy bridge keys (`family`, `params`, `times`) verbatim, so a
   still-legacy-speaking proposal reaches the gate unmodified rather than being stripped.
3. A `config` claimed `feasible:true` is upgraded through `LegacyScheduleUpgrader` (a no-op on an
   already-v2 block) and RE-VALIDATED against the exact same rules the write path uses
   (`WorkflowScheduleRulesValidator`) AND run through the real compiler
   (`ScheduleCompiler::compile()`) as a final sanity gate. Either failing downgrades the
   response to `feasible:false`, `config:null`, with the validation failure appended to
   `unsupported`.
4. An `alternative.config` that fails the same gate is dropped (`alternative:null`) rather than
   surfaced broken — its failure reason is NOT appended to `unsupported` (only a main-config
   downgrade does that; a dropped alternative is simply removed, pinned by
   `test_dropped_alternative_does_not_leak_validator_jargon`).
5. A surviving config with no `tz` inherits the caller's `tz` hint (an explicit `tz` from the
   model wins).

This means: **a config the endpoint returns as `feasible:true` is GUARANTEED to be a valid,
compilable schedule** — the backend re-derives that guarantee itself, it does not take the
model's word for it.

**The residual honesty limit (accepted, not a bug).** The re-validation gate proves a returned
config is STRUCTURALLY valid and COMPILABLE — it cannot prove the config SEMANTICALLY matches
what the user asked for. If the model mis-reads "every weekday" as a plain daily `time.at` and
wrongly marks it `feasible:true`, the backend has no way to detect that the resulting (valid,
compilable) config is not what was actually requested — a structurally-valid-but-wrong config can
still reach the caller. Mitigations: the agent's instructions explicitly enumerate the closed
axis/mode vocabulary and forbid inventing a mode/field or approximating silently into `config`
(an approximation must go through `alternative` + an honest `note`, never straight into
`feasible: true`/`config`); the frontend always surfaces `explanation` so the user can
sanity-check the result before saving; nothing about this endpoint is fully
closed-loop-verifiable server-side.

**Stateless, not run-cap-counted.** A schedule-assist call creates nothing (no task, no run) — it
is metered by its OWN per-user throttle (`assist_rate_per_minute`), never against
`config('workflows.max_runs_per_month')` / `max_runs_hard_cap` (those meter workflow RUNS).

---

**Consts (renamed from "Workflow globals" — ADR-0028; module: `App\Modules\Variables`).** The next 5
endpoints are CRUD for **consts** — workspace-scoped, user-created, typed LITERAL constants that
become `globals.<key>` references in every workflow. Module: `ConstantController` /
`Store`/`UpdateConstantRequest` / `ConstantService` / `ConstantResource` / `ConstantPolicy` /
`ConstantTypeValidator`, all under `app/modules/Variables`. **The table/model/URL/nav are renamed
(`workflow_globals`→`consts`, `WorkflowGlobal`→`Constant`); the RUNTIME WIRE is NOT** — a const is
still referenced as `globals.<key>`, resolved through the unchanged `globals` root (see "The
`globals` root" under "The typed variable system" below for how a const is CONSUMED
(referenced/resolved) — this block covers only how it is AUTHORED). See
**ADR-0028-consts-rename.md** for the full rename record, including why `Const`/`const` could not be
used directly and why the wire was deliberately left untouched.

### GET /api/consts

List the workspace's consts. Cursor-paginated, 20 per page, ordered by `name` (ascending — unlike
the workflow list's "newest first"). Authorization: `ConstantPolicy::viewAny` — any
authenticated user; workspace membership itself is enforced upstream by `ResolveWorkspace` /
`WorkspaceScope`, the same split the form-less `GET /workflows/catalog` path already uses.

**Query**

| Param    | Required | Notes                                              |
|----------|----------|------------------------------------------------------|
| `search` | no       | case-insensitive match on `name` OR `key`          |
| `cursor` | no       | cursor from `meta.next_cursor` for the next page   |

**Response** `200 OK`

```json
{ "data": [ ConstantResource ], "meta": { "next_cursor": "string | null" } }
```

---

### POST /api/consts

Create a const. Authorization: `ConstantPolicy::create` (any authenticated user).

**Body**

| Field         | Required | Constraints                                                                 |
|----------------|----------|------------------------------------------------------------------------------|
| `name`          | yes      | string, max 255                                                              |
| `key`             | no       | string, max 63. Omitted ⇒ slugged from `name` (`Str::slug($name, '_')`, underscores); given explicitly, it must match `/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/` and be unique within the active workspace (a name that slugs to `''`, e.g. `"!!!"`, requires an explicit key). |
| `descriptor`       | yes      | `{ base, nullable?, array?, options?, fields? }` — `base` must be one of `text\|number\|boolean\|date\|enum\|object` (**`file`, `time`, and `multi`-as-a-base are NOT authorable** — see "The `globals` root" below). `nullable`/`array`, when present, must be booleans. `options` (a non-empty `{key,label?}` list, distinct keys) is required when `base:'enum'`; `fields` (a non-empty `{key,label?,descriptor}` list, safe+distinct keys, each child descriptor itself recursively well-formed) is required when `base:'object'`. |
| `value`             | required-unless-nullable | may be omitted/`null` only when `descriptor.nullable:true`; otherwise a LITERAL matching `descriptor` (a scalar for a single base, a list of the element type for `array:true`, an object matching every declared `fields` key for `base:'object'`) — see the value-validation summary below. |

**Validation summary** (`ConstantTypeValidator`, the SINGLE authority shared by
Store/Update):

| Code | Field                                     | Meaning                                                                 |
|------|---------------------------------------------|----------------------------------------------------------------------------|
| 422  | `name`                                        | Required, max 255.                                                        |
| 422  | `key`                                            | Unsafe identifier, already used in this workspace, or unresolvable (blank slug + no explicit key). |
| 422  | `descriptor.base`                                  | Not one of the 6 authorable bases.                                    |
| 422  | `descriptor.<flag>`                                  | `nullable`/`array` present but not a boolean.                       |
| 422  | `descriptor.options` / `descriptor.options.<i>`         | Missing/empty/non-list options (enum), or an option with a blank/duplicate key. |
| 422  | `descriptor.fields` / `descriptor.fields.<i>.key` / `descriptor.fields.<i>.descriptor.*` | Missing/empty/non-list fields (object), an unsafe/duplicate field key, or a malformed child descriptor. |
| 422  | `value`                                                  | Type mismatch (scalar base), non-list for `array:true`, `null` on a non-nullable type, OR the value contains a NUL byte ANYWHERE (a string key or value, any depth) — see "The `globals` root" below. |
| 422  | `value.<index>`                                            | An array element fails its element-type check.                    |
| 422  | `value.<field>`                                              | An object field fails its own descriptor check, or is an undeclared key. |
| 401  | —                                                              | Unauthenticated.                                                |

**Response** `201 Created` — `ConstantResource` with `creator` loaded (Laravel's own
`wasRecentlyCreated` resource-response rule applies here, since `store()` returns the freshly
saved model directly). Example — the payload
`{ "name": "Nazwa marki", "descriptor": { "base": "text", "nullable": false, "array": false },
"value": "Taskio" }`:

```json
{
  "data": {
    "id": "…",
    "name": "Nazwa marki",
    "key": "nazwa_marki",
    "reference": "globals.nazwa_marki",
    "descriptor": { "base": "text", "nullable": false, "array": false },
    "value": "Taskio",
    "creator": { "type": "user", "id": "…", "name": "…" },
    "is_owner": true,
    "can_be_edited": true,
    "can_be_deleted": true,
    "created_at": "…",
    "updated_at": "…"
  }
}
```

Note `reference` — **byte-identical to before the rename**, still `globals.<key>`, never
`consts.<key>`; this is the wire-preservation decision ADR-0028 records.

---

### GET /api/consts/{constant}

Fetch one const. Authorization: `ConstantPolicy::view` (any workspace member) — a
foreign-workspace `{constant}` is filtered out by `WorkspaceScope` before route-model binding ever
sees it, so it 404s, never 403. (The route parameter is `{constant}`, not `{const}` — `Route::
apiResource('consts', ...)` would otherwise singularize to the reserved PHP word `const`; see
ADR-0028.)

**Response** `200 OK` — `ConstantResource` with `creator` loaded. **Errors**: `404` not
found (including a foreign-workspace id).

---

### PUT /api/consts/{constant}

Update a const. Same body/validation rules as `POST` (the key-uniqueness check excludes the
const's own row). Authorization: creator only (`ConstantPolicy::update` →
`ChecksRecordOwnership::ownsOrManagesSystemRecord` — in practice always the creator, since a
const's creator is always a human user; the trait's workspace-owner fallback for a creator-less
SYSTEM record never applies to a const).

**Response** `200 OK` — `ConstantResource`. **Errors**: `403` not creator, `404` not found,
`422` validation (same table as POST).

---

### DELETE /api/consts/{constant}

Permanently delete a const. **No soft-delete, no restore** — unlike `Workflow`, `Constant`
does not use `SoftDeletes` (no `deleted_at` column); the row is gone immediately
(`ConstantService::delete()` is a hard `Model::delete()`). A workflow that already
references the deleted const's `globals.<key>` keeps running unaffected — the reference simply
fails SOFT to `null`/`''` at run time, the same as any other missing path (no orphan-cleanup, the
module's existing "stale targeting id is a safe no-op" doctrine — **unlike** a custom function,
below, a const carries no delete-while-referenced guard). Authorization: creator only.

**Response** `200 OK` — `{ "message": "Constant deleted successfully" }`. **Errors**: `403`
not creator, `404` not found.

---

**Functions (ADR-0029; module: `App\Modules\Variables`).** The next 5 endpoints are CRUD for
**custom functions** — workspace-scoped, user-defined pipeline OPERATIONS: one input type, typed
named args, one return type, and a saved BODY pipeline over `{input + args}` that terminates in the
return type. A saved function appears as a `fn:<uuid>` entry on the SAME `operations` catalog every
workflow pipeline reads (see "Custom functions" under "The typed variable system" below) — this
block covers only how a function is AUTHORED. Module: `CustomFunctionController` /
`Store`/`UpdateCustomFunctionRequest` / `CustomFunctionService` / `CustomFunctionResource` /
`CustomFunctionPolicy` / `FunctionDefinitionValidator`.

### GET /api/functions

List the workspace's functions. Cursor-paginated, 20 per page, ordered by `name`. Authorization:
`CustomFunctionPolicy::viewAny` — any authenticated user; workspace membership is enforced upstream
by `ResolveWorkspace`/`WorkspaceScope`.

**Query**

| Param    | Required | Notes                                              |
|----------|----------|------------------------------------------------------|
| `search` | no       | case-insensitive match on `name`                   |
| `cursor` | no       | cursor from `meta.next_cursor` for the next page   |

**Response** `200 OK`

```json
{ "data": [ CustomFunctionResource ], "meta": { "next_cursor": "string | null" } }
```

---

### POST /api/functions

Create a function. Authorization: `CustomFunctionPolicy::create` (any authenticated user).

**Body**

| Field          | Required | Constraints                                                                 |
|-----------------|----------|------------------------------------------------------------------------------|
| `name`           | yes      | string, max 255 — a label only, NOT unique (identity is the row's uuid).    |
| `description`      | no       | string, max 2000                                                          |
| `input_type`         | yes      | one `VariableType` id                                                  |
| `args`                 | yes      | array (may be empty) of `{ name, description?, type }` — `name` a safe identifier (`/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/`), UNIQUE within the function, and NOT `input`/`element`/`index` (reserved scope names); `type` one `VariableType` id. |
| `return_type`          | yes      | one `VariableType` id                                                |
| `body`                   | yes      | array — the pipeline steps `Array<{op, args}>`, over the scope `{input, <argName>…}`, that must terminate in `return_type`. May reference OTHER workspace functions (`fn:<uuid>` steps, nesting allowed) — see "Custom functions" below for the cycle/depth rules. |

**Validation summary** (`FunctionDefinitionValidator`, the SINGLE authority shared by
Store/Update):

| Code | Field                          | Meaning                                                                 |
|------|----------------------------------|----------------------------------------------------------------------------|
| 422  | `name`                            | Required, max 255.                                                      |
| 422  | `input_type` / `return_type`        | Not a valid `VariableType` id.                                        |
| 422  | `args`                                | Not a list.                                                          |
| 422  | `args.<i>`                              | Not an object.                                                    |
| 422  | `args.<i>.type`                           | Not a valid `VariableType` id.                                  |
| 422  | `args.<i>.name`                             | Not a safe identifier, a reserved scope name (`input`/`element`/`index`), or a duplicate within the function. |
| 422  | `args.<i>.description`                        | Present but not a string.                                     |
| 422  | `body`                                           | Missing/not an array, OR the reference graph is CYCLIC (a self-reference or a cycle through another function) — see "Custom functions" below. |
| 422  | `body.<m>.op` / `body.<m>.args.<key>` / …          | The SAME per-op type-flow / argument-variable errors a value-or-variable pipeline gets (`PipelineValidator`), walked from `input_type` and required to terminate in `return_type`. |
| 401  | —                                                     | Unauthenticated.                                                |

**Response** `201 Created` — `CustomFunctionResource` with `creator` loaded. Example — the payload
`{ "name": "Uppercase", "input_type": "text", "args": [], "return_type": "text",
"body": [{ "op": "text_uppercase" }] }`:

```json
{
  "data": {
    "id": "b1b2c3d4-...",
    "name": "Uppercase",
    "description": null,
    "input_type": "text",
    "args": [],
    "return_type": "text",
    "body": [{ "op": "text_uppercase" }],
    "creator": { "type": "user", "id": "…", "name": "…" },
    "is_owner": true,
    "can_be_edited": true,
    "can_be_deleted": true,
    "created_at": "…",
    "updated_at": "…"
  }
}
```

A function WITH typed args and a body referencing them —
`{ "name": "Discounted price", "input_type": "number", "args": [{ "name": "rate", "type": "number" }],
"return_type": "number", "body": [{ "op": "num_multiply", "args": { "value": {
"kind": "variable", "ref": { "source": "scope", "path": "rate", "type": "number" } } } }] }` —
resolves `rate` off the function's own scope FRAME, not a top-level `trigger`/`steps`/`globals`
reference; see "Custom functions" below.

Note the id has NO `fn:` prefix here — `id` is the bare uuid identity (the resource's own primary
key, matching every other CRUD resource in this codebase); `fn:` is prepended only on the OPERATION
CATALOG'S wire op id (`'fn:' . $id`, see "Custom functions" below), never on this resource.

---

### GET /api/functions/{function}

Fetch one function. Authorization: `CustomFunctionPolicy::view` (any workspace member) — a
foreign-workspace `{function}` 404s (filtered by `WorkspaceScope` before binding), never 403.

**Response** `200 OK` — `CustomFunctionResource` with `creator` loaded. **Errors**: `404` not found
(including a foreign-workspace id).

---

### PUT /api/functions/{function}

Update a function. Same body/validation rules as `POST` — including acyclicity: the cycle graph
substitutes THIS function's own node with its NEW body before checking, so renaming an existing
function's body to reference a function that (transitively) already references this one is still
rejected. Authorization: creator only (`CustomFunctionPolicy::update` →
`ChecksRecordOwnership::ownsOrManagesSystemRecord`).

**Response** `200 OK` — `CustomFunctionResource`. **Errors**: `403` not creator, `404` not found,
`422` validation (same table as POST).

---

### DELETE /api/functions/{function}

Delete a function — **BLOCKED with a 422 while it is still referenced**, by ANOTHER function's body
OR by any WORKFLOW's step configs/conditions (a `fn:<uuid>` op anywhere in either), fail-closed
rather than leaving a dangling reference that would fail every future run of whatever used it. No
soft-delete/restore (like `Constant`, unlike `Workflow`). Authorization: creator only.

**Response** `200 OK` — `{ "message": "Function deleted successfully" }`.

**Errors**

| Code | Key          | When                                                                          |
|------|---------------|-------------------------------------------------------------------------------|
| 403  | —              | Not creator.                                                                 |
| 404  | —                | Not found.                                                                 |
| 422  | `function`         | Referenced by ANOTHER function's body — `"This function is used by another function and cannot be deleted."` |
| 422  | `function`           | Referenced by a WORKFLOW's step configs/conditions — `"This function is used by a workflow and cannot be deleted."` |

---

## Capability flags

`WorkflowResource` (detail) exposes the same server-authoritative capability-flag convention as
Bot/Approvals — the frontend must never invent authorization, only read these:

| Flag                | Source                                                    |
|-----------------------|----------------------------------------------------------------|
| `is_owner`             | `isOwnedBy(auth user)` — TRUE only for a HUMAN creator match; a run/bot-created (system) workflow is `false` even for the workspace owner. See `docs/backend/creator-attribution.md`. |
| `can_be_edited`        | `WorkflowPolicy::update` — the creator, OR (system workflow only) the workspace owner fallback. |
| `can_be_deleted`       | `WorkflowPolicy::delete` — same fallback rule as `can_be_edited`.                    |
| `can_change_status`    | `WorkflowPolicy::changeStatus` — same fallback rule as `can_be_edited`.               |
| `can_run`               | `WorkflowPolicy::run` (any workspace member).                |

**A `Workflow` is created only through the authenticated `POST /workflows` surface** (no engine
step creates a `Workflow`), so its creator is always human in practice today — the fallback row
above documents the general Policy behavior, not an observed divergence for this resource.

`WorkflowResource` also carries `creator` — the polymorphic discriminated union (`user | workflow_run
| bot | null`), eager-loaded on every detail response (`GET/POST/PUT/{id}/restore`). See
`docs/backend/creator-attribution.md` for the full shape and worked examples;
`resources/js/next/ui/patterns/creator.ts` / `CreatorBadge.vue` render it on the frontend.

`WorkflowListResource` carries only `is_owner` (lean list shape, via the hot-path `ownerUserId()` —
no `creator` eager-load, no `User` load) plus `step_count` (derived: `count(steps ?? [])`) and
`next_due_at`.

---

## Trigger configs — per type

### `form_submitted`

```json
{ "form_id": "b1b2c3d4-...", "source": { "in": ["manual", "task"] }, "anonymous": false }
```

| Key                | Required | Notes                                                                |
|----------------------|----------|--------------------------------------------------------------------------|
| `form_id`             | no       | nullable, tenant-scoped `Form` uuid (`ScopedExists(Form::class)`). `null` = any form. **REQUIRED when `conditions` is present** (conditions read one form's typed answer map — there is nothing to type-check against without one). |
| `source`               | no       | nullable object, `{ in: string[] }`.                                     |
| `source.in`            | no       | nullable array, each element `'manual' \| 'task'` (`Rule::in`). Absent/empty = any source. |
| `anonymous`             | no       | nullable boolean. `null` = either; `true`/`false` matches the form's `is_anonymous` flag exactly. |

**Matching (`WorkflowDispatchService::matchesFormSubmitted`)** — every present clause is
AND-combined, evaluated against the whitelisted trigger payload (no extra queries — `form.id`,
`source`, and `form.is_anonymous` are already in the snapshot):

- `form_id` absent/null → matches every form; present → must equal the payload's `form.id`.
- `source.in` absent/empty → matches every source; present → the payload's `source` must be a
  member.
- `anonymous` absent/null → matches either; present → must equal the payload's `form.is_anonymous`.

**Source vocabulary — `manual | task`, derived from the submission's polymorphic `submittable`
morph.** A `task`-attached submission (filled out as part of a task's attached form) normalizes
to `'task'`; everything else (a submission filled directly against the form, with no task) is
`'manual'`. **A bot-authored submission is NOT distinguishable from a human one in this
vocabulary today** — both normalize to whichever of `manual`/`task` their submittable happens to
be; there is no `source` value that means "a bot filled this in". See ADR-0009 §5 (deferred:
would need a new column to track authorship, not just the submittable morph).

**`anonymous` is a DERIVED tri-state**, not a stored trigger property: it reads
`form.is_anonymous` off the trigger payload at match time. The config's `anonymous` value is
itself a plain nullable boolean (not a tri-state enum) — `null` genuinely means "don't filter on
this", which IS the third state.

### `schedule`

```json
{ "schedule": { "time": { "mode": "at", "at": ["09:00"] }, "day": { "mode": "weekdays", "weekdays": [1, 3, 5] }, "tz": "Europe/Warsaw" } }
```

`schedule` is the v2 COMPOSITIONAL descriptor — three independent axes combined with AND, not one
of a closed set of named presets. See the Schedule section below for the complete axis/mode
reference; this table is the top-level shape only.

| Key                 | Required                        | Notes                                              |
|-----------------------|------------------------------------|-----------------------------------------------------|
| `schedule`             | **yes**                             | object, `{ time, day?, month?, exclusions?, tz? }`. |
| `schedule.time`      | **yes**                             | the WHEN-in-the-day axis; one of `at` / `every_minutes` / `every_hours`. The only required axis. |
| `schedule.day`      | no (default `every_day`)                          | the WHICH-day axis; one of `every_day` / `every_n_days` / `weekdays` / `month_days` / `special`. |
| `schedule.month`           | no (default `every_month`)                                  | the WHICH-month axis; one of `every_month` / `every_n_months` / `months`. |
| `schedule.exclusions`         | no                                  | object, `{ months?: int[1-12] max 11, weekdays?: int[0-6] max 6, dates?: 'Y-m-d'[] max 50 }` — a post-filter that drops any occurrence matching. See the Schedule section. |
| `schedule.tz`      | no                                  | IANA timezone string; default `config('app.timezone')` (UTC). |

Every field is validated by the ONE shared `WorkflowScheduleRulesValidator` (write path, AI-assist
re-validation, and the preview endpoint all delegate to it), so the accepted shape can never drift
from what `ScheduleCompiler` understands.

`schedule` workflows are **never event-dispatched** — `WorkflowDispatchService::dispatch()`
hard-refuses `WorkflowTriggerType::SCHEDULE` at the top. The ONLY path that starts a schedule run
is the `workflows:run-scheduled` sweep (or a manual run).

---

## Conditions — the typed system

Conditions are an OPTIONAL, flat list of `{ field, field_type, operator, value }` clauses,
**AND-combined** — every clause must pass for the workflow to run. An empty/absent list always
passes (an ungated workflow). **Conditions are ONLY valid for `form_submitted`** (a schedule run
has no field source in the MVP; any condition on a schedule trigger is a 422) and **REQUIRE
`trigger_config.form_id`** to be set (field conditions read one form's typed answer map — there
is no form to type-check against without one). Applied AFTER trigger matching, over the SAME
whitelisted `fields` map `{{trigger.fields.*}}` exposes.

`field` MUST be a `fields.<id>` path. The write path deliberately does NOT load the form schema
to verify `<id>` exists (that would couple write-validation to form content and race with form
edits) — a stale/wrong id is handled honestly at EVALUATION time by the missing-path semantics
below.

### The operator × type matrix (`VariableType::operatorCases()`)

| `field_type` | Allowed operators                          | Payload comparison                                          |
|---------------|-----------------------------------------------|------------------------------------------------------------------|
| `text`         | `equals`, `not_equals`, `contains`             | string-normalized equality / negation; substring for `contains`. |
| `number`        | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`            | numeric comparison; a non-numeric side always fails.             |
| `date`           | `before`, `after`, `on`, `between`               | Carbon instant/day compare; `between` takes a `[from, to]` pair. |
| `enum`            | `is`, `is_not`, `in`                              | string equality / negation / array membership.                   |
| `multi`            | `includes`, `excludes`                             | array membership over the payload's array value.                 |
| `boolean`           | `is_true`, `is_false`                               | value-LESS — truthiness of the payload value.                    |

An operator submitted for the WRONG `field_type` is a 422 (`conditions.<i>.operator`): *"The
`in` operator is not valid for a `date` field."*

### Value shape per operator

| Operator(s)                                       | Value shape                                                          |
|-------------------------------------------------------|--------------------------------------------------------------------------|
| `is_true`, `is_false`                                   | **value-less** — the `value` key is absent/ignored.                     |
| `between`                                                | a 2-element array `[from, to]` of parseable date strings.                |
| `in`                                                       | a non-empty array of strings.                                             |
| `before` / `after` / `on`                                    | a single parseable date string.                                          |
| `eq` / `neq` / `gt` / `gte` / `lt` / `lte`                     | a single numeric value.                                                  |
| everything else (`equals`/`not_equals`/`contains`/`is`/`is_not`/`includes`/`excludes`) | a single non-null, non-empty scalar (or an array for `includes`/`excludes`, matched against the payload's array). |

### Missing-path semantics (verified in `WorkflowConditionEvaluator`)

When `field`'s dotted path is ABSENT from the payload (`Arr::get` misses), the clause **fails for
every operator EXCEPT the operators that assert an ABSENCE** — `not_equals`, `neq`, `is_not`,
`excludes` — which **PASS** (`WorkflowConditionOperator::passesOnMissingPath()`):

```
"fields.status equals urgent"       on a payload with no fields.status  → FAILS
"fields.status not_equals urgent"   on a payload with no fields.status  → PASSES
"fields.tags includes urgent"       on a payload with no fields.tags   → FAILS
"fields.tags excludes urgent"       on a payload with no fields.tags   → PASSES
```

Rationale: *"status is_not done" should hold when the payload carries no status at all (there is
nothing that equals done), whereas "status is done" cannot hold without a status.* This keeps
every negative operator a true logical negation over present values while giving the intuitive
answer when the field is absent.

An **unknown type/operator combination**, or an operator not in the type's own allow-list
(should never occur post-validation — the FormRequest enum-checks and cross-checks both), fails
**closed** (the clause never passes), so a corrupted definition can never silently open the gate.

### Example

```json
"trigger_config": { "form_id": "b1b2c3d4-...", "source": null, "anonymous": null },
"conditions": [
  { "field": "fields.priority", "field_type": "enum", "operator": "is", "value": "urgent" },
  { "field": "fields.due_date", "field_type": "date", "operator": "before", "value": "2026-08-01" }
]
```

---

## The typed variable system

A **variable identity** is always the triple `{ source: trigger|steps, path, type }` — `path` is
the FULL dotted path a reference resolves against (e.g. `trigger.fields.status`,
`steps.create_task.task_id`), identical across both serializations below. This ONE identity is
served by `GET /forms/{form}/workflow-catalog` (or, for a form-less workflow,
`GET /workflows/catalog` — see above) and consumed by both step config surfaces.

### Two serializations, resolved by `WorkflowVariableResolver`

**1. TEXT / markdown fields** (e.g. `create_task.title`, `create_form_report.name`,
`.guidelines`) carry the next editor's **variable directive** — a markdown directive of the
exact byte shape the editor's `encodeVariableDirective` produces:

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.status\",\"name\":\"Status\",\"type\":\"text\",\"locked\":false}}")
```

The directive's identity lookup (`data.id`) is always honored. **SB1 adds RUNTIME pipeline
execution**: when `data.pipeline` is a non-empty list of `{operationId|op, args}` steps (the next
editor's pipeline-editor format), `WorkflowVariableResolver` runs it through the shared
`OperationExecutor` and STRINGIFIES the typed result into the surrounding text; an EMPTY
(or absent) pipeline keeps the original identity-only behavior unchanged, WITH one reviewer fix:
a STANDALONE identity chip that IS the whole field (no pipeline) now always STRINGIFIES its
looked-up value before it reaches the field — a bare non-text variable (a multi-select, a number,
a boolean) resolving into e.g. `create_task.title` becomes its text representation, never the raw
array/scalar that would otherwise reach the title's non-empty-string check and hard-fail the run
with a misleading "requires a title". **The directive still
carries NO extra type field beyond the editor's own primitive** (`data.type` is the editor's
rendering primitive, text/number/boolean — NOT the workflow type, and NOT trusted as the
pipeline's base type either). The pipeline's REAL base type is recovered, in order: (1) the run's
TYPE MAP by path (`WorkflowVariableCatalogService::runtimeTypeMap()`), (2) failing that, the
pipeline's first operation's declared input type (the editor authored the pipeline against the
real type), (3) failing that, `text`. See "Runtime operations, if-blocks, and AI text" below for
the full pipeline/if-block/ai-text contract, including the fail-closed doctrine.

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.due_date\",\"name\":\"Due date\",\"type\":\"text\",\"locked\":false,\"pipeline\":[{\"operationId\":\"date_add_days\",\"stepId\":\"s1\",\"args\":{\"value\":3},\"outputType\":\"date\"}]}}")
```

resolves to the field's `due_date` value plus 3 days, stringified `Y-m-d` (a `date`-typed
terminal renders `Y-m-d`; every other terminal type renders through the same stringify rules the
identity embed already used — numbers naturally, booleans as `true`/`false`).

**2. NON-TEXT (structured) fields** (`create_task.priority`, `.deadline`;
`create_form_report.submissions_from`, `.submissions_to`) carry the **`{kind}` union** — SB1 adds
an OPTIONAL `pipeline` to the `variable` arm:

```json
{ "kind": "literal", "value": "high" }
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.priority", "type": "enum" } }
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.category", "type": "enum" }, "pipeline": [{ "op": "enum_to_choice", "args": { "mapping": { "blog": "high", "news": "low" } } }] }
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.headline", "type": "text" }, "pipeline": [{ "op": "match_to_choice", "args": { "rules": [{ "when": "BREAKING", "then": "urgent" }], "fallback": "medium" } }] }
```

`resolveValueOrVariable()` handles this: a `literal` resolves (and type-coerces) its `value`
directly; a `variable` WITHOUT a `pipeline` (or an empty one) looks up `ref.path` in the run
context then coerces the result to the field's EXPECTED type, unchanged from before SB1 (e.g.
`priority` coerces to `VariableType::ENUM`, `deadline` to `DATE`). A `variable` WITH a
non-empty `pipeline` instead runs it through `OperationExecutor` **from `ref.type`**
(never the field's expected type — the pipeline's own declared base) and coerces the TYPED RESULT
to the field's expected type. A pipeline FAILURE coerces to `null` — the exact same soft default
an unresolved ref already produced, so a bad pipeline degrades exactly like a bad reference (the
field's own soft doctrine still applies: `priority` defaults to `medium`, `deadline` to `null`). A
bare scalar in a structured slot (no `kind` wrapper) is tolerantly treated as a literal, unchanged.
**Unlike the markdown directive's pipeline, THIS pipeline IS write-validated** — see "Write-time
validation" below, and "Choice fields" further down for `priority`'s stricter, CHOICE-only
terminal contract (`enum_to_choice` / `match_to_choice` — the runtime coercion above is unchanged,
only the write validator is stricter).

### Coercion table (`WorkflowVariableResolver::coerce`)

| Expected type | Coercion                                                       |
|-----------------|-------------------------------------------------------------------|
| `date`            | Carbon-parsed to an ISO-8601 string; unparseable/empty → `null`.   |
| `number`            | numeric value + 0 (int or float); non-numeric → `null`.             |
| `boolean`             | `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.                          |
| `multi`                 | array passthrough; a scalar is wrapped in a 1-element array.        |
| `enum` / `text`           | scalar cast to string; a non-scalar (array) → `null`.                |

---

### The resolver's whitelist (exfiltration-safe)

Exactly two readable context roots: `trigger` and `steps`. A path rooted anywhere else (e.g.
`env.*`, `config.*`, `__proto__.*`) is **NOT recognised as a reference at all** — a standalone
occurrence resolves to `null`/stays literal, an embedded occurrence stays completely literal in
the surrounding text. There is no expression language (no filters, arithmetic, method calls, or
conditionals) — a plain whitelisted `Arr::get` lookup has no code path through which a crafted
`trigger_config`/`steps.*.config` value could execute anything beyond a dictionary read.

### Transitional flat `{{...}}` tokens

`{{trigger.task.id}}`-style flat tokens (both standalone — resolving to the raw typed value —
and embedded — stringified into surrounding text) are **still resolved**, alongside the
directive/union serializations above. This keeps any already-authored step config or e2e path
working during the 5.1 migration; it is NOT a second expression language, just the same
whitelisted `Arr::get` lookup under different bracket syntax. New editor-authored content uses
the directive/union shapes; the flat grammar is not the primary authoring path going forward.

**Reviewer fix — an embedded directive's resolved value is MASKED before the flat pass runs**, so
it can never be re-scanned as a second flat token. Without this, a form value that itself
happens to contain literal `{{...}}` bytes (typed by an end user, not authored by the workflow)
would be substituted in by the directive pass and then ACCIDENTALLY matched again by the flat-token
pass right after — a second-order "injection" purely by coincidence of content. Each resolved
embedded directive is stashed behind a NUL-delimited placeholder (form values can never contain a
NUL byte — Postgres rejects it — so a value can never forge one) and restored only AFTER the flat
pass has already run. This mirrors the same masking `@[ai-text]`'s generated output already uses
(see "Runtime operations, if-blocks, and AI text" → §c above) — applied here to every directive's
looked-up value, not just AI-generated text.

### Structured `descriptor` (phase-1a, additive)

Every `variable` entry in BOTH catalog responses (`GET /forms/{form}/workflow-catalog`,
`GET /workflows/catalog`) now ALSO carries `descriptor: { base, nullable, array, options? }`
(`WorkflowVariableCatalogService::variable()`, built by `VariableType::descriptor()`) —
alongside the UNCHANGED flat `type`/`enumOptions?`/`nullable?` keys. Nothing about the flat shape
changed; `descriptor` is a second, richer view of the same variable:

| Key | Meaning |
|---|---|
| `base` | The type's own scalar base — EXCEPT `multi`, whose base is `enum` (a multi is "an array of enum"); every other type (`time` included) is its own base. |
| `array` | `true` only for a `multi` variable. |
| `nullable` | Mirrors the variable's own `nullable` flag. |
| `options` | Present ONLY when `base === 'enum'` (an `enum` or `multi` variable): a list of `{ key, label }`. `key` is the SAME string the flat `enumOptions` already carries (the wire value stored/matched at runtime — unchanged); `label` is the human-readable option label. |

For a FORM field (a `select`/`checklist` element), `label` is read from the element's
`config.options` — the only place it survives, since `FormElementType::toJsonSchema` emits option
VALUES only into the JSON schema `enum`. When the element config carries no label for an option,
the label falls back to the option's own value. A system/step enum variable (e.g.
`trigger.source`) has no element config to read, so every option's label equals its key.

```json
{ "source": "trigger", "path": "trigger.fields.category", "name": "Category", "type": "enum",
  "enumOptions": ["blog", "news"],
  "descriptor": { "base": "enum", "nullable": false, "array": false,
    "options": [{ "key": "blog", "label": "Blog" }, { "key": "news", "label": "News" }] } }

{ "source": "trigger", "path": "trigger.fields.channels", "name": "Channels", "type": "multi",
  "enumOptions": ["fb", "ig"],
  "descriptor": { "base": "enum", "nullable": false, "array": true,
    "options": [{ "key": "fb", "label": "Facebook" }, { "key": "ig", "label": "Instagram" }] } }
```

A `time` field (the form builder's TIME element, `format:'time'`) keeps its flat `type` degraded
to `text` (unchanged runtime/FE behavior — see "Runtime operations" below), but its
`descriptor.base` is the real `time`:

```json
{ "source": "trigger", "path": "trigger.fields.start_time", "name": "Start time", "type": "text",
  "descriptor": { "base": "time", "nullable": false, "array": false } }
```

**Frontend consumption**: `CatalogVariable.descriptor` is OPTIONAL on the TypeScript side (a
label-less/older fixture without it still parses). `variableOptionList()`
(`resources/js/next/pages/workflows/workflowVariables.ts`) prefers `descriptor.options`
(rendering the human `label`, emitting the `key` as the stored/compared value) and falls back to
the flat `enumOptions` (label = value) only when no descriptor is present — the single place the
editor turns a variable's choices into human labels, so the variable picker, pipeline
`sourceOption`/`sourceMap` args, and the choice-mapping UI (ADR-0014) all agree.

### Per-reference `default`s (phase-1b, additive)

Both wire serializations of a variable reference gained an OPTIONAL literal `default`:

- The markdown directive: a `data.default` scalar, alongside `data.id`/`data.pipeline`/etc.
- The `{kind:'variable'}` union: a sibling `default` key next to `ref`/`pipeline`.

`WorkflowVariableResolver::applyDefault()` substitutes it when the looked-up value is `null` or
`''` (empty string) — for an identity-only reference exactly as for a piped one. The default is
substituted BEFORE any pipeline runs, so it can itself be transformed/formatted like a real value
(e.g. a missing date reference can default to an ISO string a downstream `date_format` op then
renders). When the reference already resolves to a real value, the default is never consulted.

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.due_date\",\"name\":\"Due date\",\"type\":\"text\",\"locked\":false,\"pipeline\":[],\"resultType\":\"text\",\"default\":\"2026-01-09\"}}")
```

```json
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.due_date", "type": "date" }, "default": "2026-01-09" }
```

**Injection-guard invariant (stated explicitly — a security property, not an implementation
detail).** A substituted default enters the resolved-value stream at EXACTLY the point a real
context value would, so it flows through the SAME NUL-delimited placeholder mask an embedded
directive's resolved value already uses before the transitional flat `{{...}}` pass runs (see the
"Reviewer fix" masking note above). A default literal that happens to contain `{{...}}` or
`@[...]` bytes is therefore NEVER re-interpreted as a second-order reference — the exact same
guarantee untrusted user-typed form content already had. A standalone directive or a
structured-slot default is never re-scanned at all (there is no second pass over that shape).

**Wire economy**: the frontend only serializes `default` when it is non-empty
(`encodeVariableDirective`; `ValueOrVariableField.vue`'s `saveModal()`), so a reference with no
default stays byte-identical to a pre-Phase-1 payload. The "Default when empty" affordance appears
in both places a reference is edited once one is picked: `ValueOrVariableField.vue` (the
structured value-or-variable field, e.g. `create_task.deadline`) and the markdown editor's
`VariablePanel.vue` (the `@[variable]` chip's edit modal) — one low-emphasis text input each,
empty ⇒ omitted from the wire.

See **ADR-0022-workflows-variable-typesystem-phase1.md** for the full design record (why a second
additive `descriptor` field instead of reshaping the flat one, the TIME loud-tripwire trade-off,
and why the default is a plain literal rather than a nested reference).

### Structural descriptor: object containers & the file composite (phase-2a/2b/2b.1, additive)

`descriptor` may now also carry a recursive `fields` list — `{ base, nullable, array, options?,
fields? }` — for three structural shapes, layered additively on top of the phase-1a shape above.
Nothing about the flat `type`/`enumOptions` contract changes for any existing
scalar/enum/multi/date/boolean/text variable; `fields` is present only where noted below.

**1. A form SECTION also surfaces as an `object` container (`array:false`), additive alongside its
unchanged flat leaves.** `WorkflowVariableCatalogService::containerVariables()` walks the form's
top-level schema fragments; a section's own catalog entry groups its children, but every child is
STILL ALSO emitted as its own flat `section.field` variable exactly as before — a reference to
`trigger.fields.details.note` keeps resolving unchanged; the container entry is a new, additional
view of the same data, not a replacement path.

```json
{ "source": "trigger", "path": "trigger.fields.details", "name": "Details", "type": "text",
  "descriptor": { "base": "object", "nullable": false, "array": false,
    "fields": [
      { "key": "note", "label": "Note", "descriptor": { "base": "text", "nullable": false, "array": false } }
    ] } }
```

**2. A REPEATER's exclusion (see "Repeaters are EXCLUDED from the catalog" above) is lifted the
same way — it now surfaces as ONE `array<object>` container (`array:true`).** Unlike a section, a
repeater's element fields have NO flat leaf of their own (`fields.items.item_name` is still
unresolvable — nothing changed there); they exist ONLY inside `descriptor.fields`.

```json
{ "source": "trigger", "path": "trigger.fields.items", "name": "Items", "type": "text",
  "descriptor": { "base": "object", "nullable": false, "array": true,
    "fields": [
      { "key": "item_name", "label": "Item name", "descriptor": { "base": "text", "nullable": false, "array": false } }
    ] } }
```

Both container bases are DESCRIPTOR-ONLY, mirroring the `TIME` tripwire (ADR-0022 Decision 2): the
flat wire `type` degrades to `text` (`WorkflowVariableCatalogService::flatType()`) and
`operatorCases()` is `[]` — never a condition source. `conditionFields()` additionally filters out
every `descriptor.base === 'object'` entry before building the condition-field list, so a
container never mis-advertises itself as a text-conditionable field. A container carries no
`field_id` and no flat `enumOptions`. Only a form's TOP-LEVEL section/repeater gets its own catalog
entry — a container nested inside another container (a section inside a repeater, say) is visible
only inside its parent's recursive `fields`, with no flat leaf and no reference-index path of its
own.

**3. A `file` variable's descriptor now ALSO carries its fixed composite subfields — but, unlike
`object`, the flat wire `type` STAYS `file`.** `id`/`name`/`type`/`url` are `text`, `size` is
`number` — the single source is `VariableType::fileSubfieldTypes()`, which both the
descriptor and the reference index below read from, so the three can never disagree:

```json
{ "source": "trigger", "path": "trigger.fields.attachment", "name": "Attachment", "type": "file",
  "descriptor": { "base": "file", "nullable": false, "array": false,
    "fields": [
      { "key": "id",   "label": "id",   "descriptor": { "base": "text",   "nullable": false, "array": false } },
      { "key": "name", "label": "name", "descriptor": { "base": "text",   "nullable": false, "array": false } },
      { "key": "type", "label": "type", "descriptor": { "base": "text",   "nullable": false, "array": false } },
      { "key": "size", "label": "size", "descriptor": { "base": "number", "nullable": false, "array": false } },
      { "key": "url",  "label": "url",  "descriptor": { "base": "text",   "nullable": false, "array": false } }
    ] } }
```

Unlike a section/repeater child (whose `label` is the real human label authored in the form
builder), a file subfield's backend `label` is just its own key (`'name'`, `'size'`, …) — the
frontend supplies the human-facing label (`"Name"`/`"Size"`, localized) purely client-side, the
same way `operations`/`ai_personas`/`types` are already localized. Because the flat wire `type`
for a FILE variable is untouched (still `file`, never degraded), every existing file behavior is
unchanged: a text field still stringifies to the name, a structured slot still coerces to the
id(s) (what `create_task`'s copy-on-attach reads), and the condition operator set stays
`filled`/`empty`.

**The trigger file snapshot gains a `url` key** (`WorkflowTriggerPayloadFactory::fileSnapshots()`)
— the file's own access-controlled serve route, built by the new
`App\Modules\Disk\Models\File::serveUrl()` (`route('disk.show', [...])`), NEVER the raw storage
path. `disk.show` is gated end-to-end (`auth:sanctum` + `RequireWorkspace` + a tenant-scoped
`{file}` binding that 404s a foreign/trashed id), so embedding it in a persisted/logged snapshot is
safe — it is not a forever-public link. The snapshot is built from, and always describes, the
ORIGINAL submission file; a later `create_task` step's copy-on-attach creates a new file with its
own id/url, and the trigger snapshot is never rewritten to point at the copy:

```json
[{ "id": "b1b2c3d4-...", "name": "raport.pdf", "mime_type": "application/pdf", "size": 1234,
   "url": "https://app.taskio.test/api/disk/b1b2c3d4-..." }]
```

**File subfield paths are individually referenceable — including as PIPELINE-bearing references
(phase-2b.1) — because `referenceIndex()` and `runtimeTypeMap()` now enumerate all 5 of them for
every FILE-typed entry**, single-sourced from `VariableType::fileSubfieldTypes()`. A
value-or-variable pipeline may therefore target e.g. `trigger.fields.attachment.name` (type-flows
as `text`) or `trigger.fields.attachment.size` (type-flows as `number`) — a wrong-typed op on one
now fails with the ordinary `422` type-mismatch error under `.pipeline.<m>.op`, not "unknown
variable". At RUNTIME, `WorkflowVariableResolver` resolves a subfield reference through the SAME
directive/union/if-block/flat-token machinery every other path already uses — a file answer
(always a snapshot list, even for one file) collapses to its single element to read the subfield;
a multi-element list takes the FIRST, fail-soft (true per-element access is out of scope — see
below). **A REPEATER element's subfield (e.g. `fields.items.item_name`) is deliberately NOT
enumerated** by either table — a pipeline-bearing reference to one still resolves to an unknown
variable, `422`.

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.attachment.name\",\"name\":\"Attachment › Name\",\"type\":\"text\",\"locked\":false}}")
```

```json
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.attachment.name", "type": "text" },
  "pipeline": [{ "op": "text_uppercase", "args": [] }] }
```

**Update (a later batch): non-array OBJECT descriptor fields join the SAME mechanism, recursively.**
`descriptorSubfieldTypeMap()` — the single source `addReferenceEntry()`/`addTypeMapEntry()` both
call — is now `fileSubfieldTypeMap() + objectSubfieldTypeMap()`. The new
`objectSubfieldTypeMap()` walks a **non-array** `object` descriptor's own declared `fields`
RECURSIVELY into `<path>.<key>` entries, guarded by `isObjectContainer($descriptor)`
(`base === 'object' && array !== true`) — the identical rule the editor's picker tree
(`isObjectContainer()` in `workflowVariables.ts`) uses to decide what expands, so the two can never
disagree. This reaches EVERY non-array object descriptor: a form SECTION's own container entry is
now redundantly covered too (harmlessly — its pre-existing FLAT leaf entry always wins via the
existing `??=` dedupe guard, so it keeps its `enumOptions`), and — the practical unlock — a
Phase-3 `globals.<key>` object's interior, which has NO separate flat-leaf pass at all (see "The
`globals` root" below). Recursion STOPS the instant it reaches an `array:true` object descriptor
(a REPEATER), so a repeater's element subfields remain UNREFERENCEABLE at every nesting depth,
exactly as stated above — this update widens the non-array case only. A descriptor-derived
subfield entry — FILE or OBJECT — carries NO `enumOptions`, an accepted limitation unchanged from
the file-subfield case. See
**ADR-0023-workflows-variable-typesystem-phase2.md**'s addendum for the full record.

```json
{ "source": "globals", "path": "globals.address", "name": "Address", "type": "text",
  "descriptor": { "base": "object", "nullable": false, "array": false,
    "fields": [
      { "key": "city", "label": "city", "descriptor": { "base": "text", "nullable": false, "array": false } }
    ] } }
```

`globals.address.city` is now a KNOWN `text` entry in `referenceIndex()`/`runtimeTypeMap()` (it was
not, before this update) — a value-or-variable pipeline may target it with full write-time
type-checking, exactly like `globals.address` itself; the runtime needed no change (a plain
whitelisted `Arr::get` over the injected `globals` map already resolved it).

**Frontend consumption.** The editor's variable picker (`expandVariables()` in
`workflowVariables.ts`, feeding `toEditorVariables`/`toEditorVariablesTyped`/`variablesOfType`)
expands a FILE composite into its unchanged whole-file entry PLUS one pickable per subfield (path
`<file>.<key>`, a qualified display name like "Attachment › Name", the subfield's own scalar
type — including its `.id`, which deliberately bypasses the SF3.2 rule that otherwise hides system
identifiers). A SECTION contributes NOTHING new to the picker (its leaves are already flat
top-level entries — re-offering the whole object, which resolves to a nested map, would only
duplicate/confuse). A REPEATER contributes exactly ONE entry, relabelled with a "(list)" suffix, no
children. `CatalogVariableDescriptor.base` (TypeScript) widened to accept `'object'`; a new
recursive `CatalogDescriptorField` interface backs `descriptor.fields`; `CatalogType.id` widened to
tolerate the two descriptor-only ids (`'time'`, `'object'`) the catalog's `types[]` list now also
carries — none of this touches the closed, 8-member `VariableType` union a variable's own
flat `type` still uses.

**This is REPRESENTATION ONLY.** Making the whole form structure — and a file's own facets —
visible/referenceable is the entire scope of this phase; actually LOOPING a repeater or a
multi-file answer (iterating per element with its own binding) is explicitly OUT OF SCOPE, deferred
to R2-Generator. See **ADR-0023-workflows-variable-typesystem-phase2.md** for the full design
record, including why this is a DIFFERENT slice of work than the "Phase 2" items ADR-0022 named as
deferred (`TIME` runtime semantics, the presence-op `walkPipeline` asymmetry, the two defensive
hardening items) — none of those three are touched by this phase; see "Accepted residual risks"
below.

---

### The `globals` root — consts, user-created LITERAL constants (Phase 3, additive; persistence renamed to `Constant`/`consts` in ADR-0028)

`globals` is a THIRD reference root, alongside `trigger`/`steps` — `WorkflowVariableResolver::ROOTS`
is now `['trigger', 'steps', 'globals']`. Unlike `trigger`/`steps`, it is not derived from the
CURRENT run at all: it is the active workspace's own set of user-created **consts** (see
"Consts" under Endpoints above for how one is authored — the model/table/URL were RENAMED from
`WorkflowGlobal`/`workflow_globals`/`workflow-globals` by ADR-0028, but this reference ROOT's own
name, `globals`, was deliberately left untouched; "a const" and "a `globals.<key>` reference" name
the exact same thing), injected into every run's
context as a flat `{<key>: <stored value>}` map (`WorkflowStepRunner::run()` →
`WorkflowVariableCatalogService::globalValues()`) and composed into the catalog for EVERY trigger
type — `form_submitted`, `schedule`, or even a form-less/trigger-less catalog call — since a global
has no trigger/form context to be scoped by. A `globals.<key>` reference works in EITHER
serialization exactly like `trigger.*`/`steps.*` already do: the markdown directive
(`@[variable]("...{\"id\":\"globals.brand\"}...")`), the transitional flat token
(`{{globals.brand}}`), and the `{kind:'variable', ref:{source:'globals', path:'globals.brand',
type:'text'}}` structured union all resolve it identically — no new resolver code path was needed,
only the whitelist addition and the context binding (see
**ADR-0024-workflows-variable-typesystem-phase3-globals.md** for the full "3-point recipe"
record, and **ADR-0028-consts-rename.md** for the later persistence rename this root's own name was
deliberately exempted from). Resolution is FAIL-SOFT like every other root: a deleted or unknown key
resolves to `null` (standalone) / stays out of the surrounding text (embedded), never an error.

**Catalog shape.** A global's catalog entry is `{ source: 'globals', path: 'globals.<key>', name,
type, descriptor, enumOptions? }` — `descriptor` is the EXACT stored descriptor (not re-derived),
and the flat `type` is recovered from it via the new `VariableType::fromDescriptor()` (the
inverse of `descriptor()`):

```json
{ "source": "globals", "path": "globals.nazwa_marki", "name": "Nazwa marki", "type": "text",
  "descriptor": { "base": "text", "nullable": false, "array": false } }

{ "source": "globals", "path": "globals.hashtagi", "name": "Hashtagi", "type": "multi",
  "descriptor": { "base": "text", "nullable": false, "array": true } }
```

An `array<scalar>` global (e.g. a `text` base with `array:true`, like the `hashtagi` example above)
rides the PRE-EXISTING `multi` flat type — the one array-carrying case every existing resolver/
evaluator/executor `match` and the frontend's closed type union already handle — rather than a new
flat type; its `descriptor.base` still reads the true element base (`text`) and it carries NO
`enumOptions` key at all (it is not enum-based). An `object`-based global rides the Phase-2 `object`
descriptor-only tripwire the same way a form SECTION does (flat `type` degrades to `text`,
`operatorCases()` empty — never a condition source; a global is never offered as a condition field
regardless of base, the same as a step output). `referenceIndex()` and `runtimeTypeMap()` both
enumerate every `globals.<key>` path too, so a value-or-variable pipeline (e.g.
`create_task.deadline`) may target a global with full write-time type-checking, exactly like a
trigger/step reference.

**Authorable types (write path only — see "Consts" → `POST` above for the full
validation table).** A const's `descriptor.base` is one of `text | number | boolean | date | enum
| object` — **`file` and `time` are NOT authorable** (a const holds a plain typed constant, never
a Disk file or a type with no runtime semantics yet), and `multi` is not a base at all (it is
`enum` + `array:true`, the same convention every other catalog variable uses).
`ConstantTypeValidator` (renamed from `WorkflowGlobalTypeValidator`, ADR-0028) is the SINGLE place
this is enforced, shared by both `Store`/`UpdateConstantRequest`.

**Injection safety (a security invariant, stated explicitly).** A const's `value` is
user-authored, persisted, and later interpolated into a step's text/structured fields — the exact
shape untrusted content takes elsewhere in this module. It is protected TWO ways: (1) AT WRITE
TIME, `ConstantTypeValidator` rejects a value containing a NUL byte anywhere (any string key
or value, any depth) — `consts.value` (renamed from `workflow_globals.value`) is a plain `json`
column, which (unlike `jsonb`) does not itself refuse one, so this closes the one persistence path
in this module that could otherwise carry a NUL end to end; (2) AT RESOLVE TIME, a const's value
rides the SAME NUL-delimited placeholder masking an embedded directive's looked-up value already
uses (see "Transitional flat `{{...}}` tokens" above) — so a value that merely LOOKS like a
reference (e.g. literally containing the text `{{trigger.fields.secret}}` or `@[variable]...`)
renders completely VERBATIM, in every resolution shape, and is never re-interpreted as a
second-order reference. Pinned by
`ConstantCrudTest::test_a_value_carrying_a_nul_byte_is_rejected` (write-time) and
`WorkflowVariableResolverTest::test_a_global_value_with_reference_like_bytes_is_not_re_interpreted`
(resolve-time, using a fixture literally named `globals.evil`).

**Update (a later batch): an OBJECT global's own declared fields are now referenceable too.** At
Phase 3 ship time, only a global's TOP-LEVEL `globals.<key>` path was indexed — an `object`-based
global's own interior fields (`globals.address.city`) were not yet their own reference-index
entries, even though the variable picker (once it grew an expandable tree — see "The typed variable
system" → the arg-variables/Phase-4 area for the batch this shipped alongside) could already offer
them for picking. `objectSubfieldTypeMap()` (see "Structural descriptor: object containers & the
file composite" above) closes that gap: an object global's `descriptor.fields` are now enumerated
recursively into the reference index and the runtime type map, so a value-or-variable pipeline may
target `globals.address.city` with full write-time type-checking — the picker and the validator now
agree on every node the picker can emit a ref for. See
**ADR-0024-workflows-variable-typesystem-phase3-globals.md**'s addendum for the full record.

See **ADR-0024-workflows-variable-typesystem-phase3-globals.md** for the full design record
(including the LITERAL-only scoping decision, why `file`/`time` are excluded, and the deferred
frontend authoring depth), **ADR-0028-consts-rename.md** for the persistence rename (table/model/URL/
nav — the `globals` wire itself is unaffected), and `resources/js/next/docs/pages/WorkflowsPage.vue`
("Workflow Globals", under "The typed variable system") for the in-app docs mirror — **not yet
updated for the ADR-0028 rename; a Frontend-coordinated follow-up should refresh its `Constant`/
`consts` naming and its `/next/variables/consts` route/component references.**

### Operation arguments as variables (Phase 4, additive — completes the rework; widened in a later "Phase 4b" batch)

ANY operation argument — value-typed OR option/structural, not just a field's own top-level value —
may now be the SAME `{kind:'variable', ref, pipeline?, default?}` union a structured field's value
already uses, in place of a constant literal, and RECURSIVELY (an argument's own `pipeline` may
itself carry another such argument). `num_add`'s `value`, `date_add_days`'s `value`,
`text_append`'s `value`, but ALSO `enum_to_choice`'s `mapping`, `match_to_choice`'s `rules`/
`fallback`, a `select`/`sourceOption` pick — every control — can now be pulled from
`trigger`/`steps`/`globals` context instead of being typed once at authoring time:

```json
{ "op": "date_add_days", "args": { "value": {
  "kind": "variable",
  "ref": { "source": "trigger", "path": "fields.upload.size", "type": "number" },
  "pipeline": [{ "op": "num_add", "args": { "value": 2 } }]
} } }
```

```json
{ "op": "enum_to_choice", "args": { "mapping": {
  "kind": "variable",
  "ref": { "source": "globals", "path": "globals.category_map", "type": "text" }
} } }
```

**`OperationArgType::argVariablePolicy(): ArgVariablePolicy` is the single gate both sides
read** (`app/modules/Variables/DTOs/ArgVariablePolicy.php`) — it REPLACED the earlier, narrower
`variableValueType()`, which returned a type only for the four value controls and `null`
(LITERAL-ONLY) for every option/map/rules/select control. `ArgVariablePolicy` carries two facets:
`$refTypes` (the `VariableType`s a ref/terminal may declare at WRITE time; `null` =
STRUCTURAL) and `$coerceTo` (the RUNTIME coercion target; `null` = STRUCTURAL pass-through) — the
two nulls always coincide, enforced by three named constructors (`value()`, `option()`/`options()`,
`structural()`).

| Arg control | `$refTypes` (write gate) | `$coerceTo` (runtime) |
| --- | --- | --- |
| `text` / `number` / `boolean` / `date` | its own one type (strict) | its own type — unchanged from phase 4a |
| `select` / `sourceOption` / `choiceFallback` | `enum` \| `text` | `enum` (a string) — option-SET membership is a RUNTIME fail-soft concern, unverifiable at write time |
| `sourceOptions` | `multi` | `multi` (an array) — per-element membership likewise deferred to runtime |
| `sourceMap` / `choiceRules` | `null` — STRUCTURAL | `null` — the raw context array passes through untouched |

A STRUCTURAL arg-variable supplies the WHOLE `{option: target}` map / `{when, then}` rule list from
ONE reference — it never gets a sub-pipeline (no operation BUILDS a structure), so its "coercion" is
simply handing the resolved ref's raw array value to the executor's existing map/rules reader
(`WorkflowVariableResolver::resolveStructuralArgVariable()`), which already fail-softs on a
malformed value exactly as it does for a malformed literal.

**Runtime.** `OperationExecutor` is completely UNCHANGED — it still only ever receives
literal args (a STRUCTURAL arg's "literal" is the raw map/rule-list array itself).
`WorkflowVariableResolver::resolvePipelineArgs()` pre-resolves every op's variable-shaped argument to
a literal BEFORE the executor runs, at all three pipeline call sites (a `{kind:'variable'}` field's
own pipeline, a text directive's pipeline, an if-block condition's pipeline) — a VALUE/OPTION
argument goes through the SAME `resolveValueOrVariable()` a top-level field already uses (read the
whitelisted ref, apply the argument's own pipeline, coerce via the policy's `$coerceTo`); a
STRUCTURAL argument goes through `resolveStructuralArgVariable()` instead (no sub-pipeline, no
coercion — the raw array or `null`). An unresolvable ref, a failed sub-pipeline, or nesting beyond
the depth cap all resolve FAIL-SOFT (the policy's coerced `null` for VALUE/OPTION, bare `null` for
STRUCTURAL) — the op then fails closed on the empty argument exactly as it already does for a
malformed literal, never a crash. An unresolved variable union that somehow reached the executor
directly (bypassing the resolver) also fails closed, never crashes, and never treats the union as a
value — pinned by
`OperationExecutorTest::test_a_variable_union_arg_reaching_the_executor_fails_closed`.

**Depth cap — the only bound needed, because there are no cycles.** An argument's `ref` can only
point at CONTEXT DATA (`trigger`/`steps`/`globals`, the resolver's existing `ROOTS`), never at
another argument's own definition, so a cycle is impossible by construction.
`PipelineLimits::MAX_ARG_VARIABLE_DEPTH = 3` (`App\Modules\Variables\Enums\PipelineLimits`) gates both sides identically, for every category:
the write validator `422`s a 4th nesting level under the deepest argument's own key
(each extra level appends another `.pipeline.<m>.args.<key>`), and the runtime resolver fail-softs at
the identical boundary — an author can never save a config the runtime would reject.

**Write validation — literal-only in a condition-tree pipeline; STRUCTURAL args skip the type-equality check.**
`StoreWorkflowRequest`'s value-pipeline path (`create_task.deadline`/`.priority`,
`create_form_report.submissions_from`/`.submissions_to` — the only pipeline that was already
write-validated, see "d. Write-time validation" below) validates an argument-variable with the SAME
machinery a top-level ref gets: the ref must be a KNOWN entry in the reference index
(`WorkflowVariableCatalogService::referenceIndex()`). For a VALUE/OPTION control its catalog type
must equal the argument's accepted type(s), and a present sub-pipeline is validated recursively, one
level deeper; for a STRUCTURAL control (`$policy->isStructural()`) the strict type-equality check is
SKIPPED — a whitelisted + indexed ref is the whole gate, because the flat variable-type vocabulary
cannot express a map/rule-list to check against, and no sub-pipeline is type-flowed (there is nothing
to type-flow FROM). A CONDITION-TREE pipeline (the `form_submitted` trigger gate) stays LITERAL-only
for EVERY control — the validator only accepts an argument-variable when handed a reference index,
and the condition-tree call site never supplies one, so a variable union there is rejected under the
ordinary literal-shape checks. This is deliberate, mirroring the runtime: `WorkflowConditionEngine`
(the trigger gate's evaluator) calls the executor DIRECTLY, with no resolver/pre-resolution pass at
all — an argument-variable there is intentionally NOT wired, on EITHER side, for any control.

**Injection safety** is inherited, not re-invented: a resolved argument value — VALUE/OPTION scalar
or STRUCTURAL array alike — is used literally by the executor and its output rides the SAME NUL-mask
placeholder stash a resolved directive value already uses (see "Transitional flat `{{...}}` tokens"
above) — an argument that resolves to a value containing `{{...}}`/`@[...]`-shaped bytes (at any
depth inside a structural map/rule-list too) renders it verbatim, never re-interpreted, at any
nesting level. Pinned by
`WorkflowVariableResolverTest::test_arg_variable_value_with_reference_like_bytes_is_not_re_interpreted`.

**Frontend.** `operationHelpers.ts`'s `argVariablePolicy()` (+ `isStructuralArg()`) mirrors the
backend match case-for-case; `PipelineArgLiteralInput.vue` is now the SINGLE literal control for
EVERY arg kind (value control, phase-4a's original scope, AND option/map/rules), so
`VariablePipelineEditor.vue` no longer inlines any literal control of its own. The recursive
arg-variable picker (`ValueOrVariableField.vue` filling the shared editor's `#argVariable` slot with
itself) offers the FULL show-all variable pool for every arg, unfiltered by type — the terminal gate
plus the mismatch skin enforce appropriateness, not the picker's contents — and a STRUCTURAL arg's
recursive field gets no operations catalog at all (no sub-pipeline to build).

See **ADR-0025-workflows-variable-typesystem-phase4-arg-variables.md** (incl. its Phase 4b addendum)
for the full design record — this COMPLETES the four-phase variable-typesystem rework (ADR-0021 →
ADR-0022 → ADR-0023 → ADR-0024 → ADR-0025) — and `resources/js/next/docs/pages/WorkflowsPage.vue`
("The typed variable system" and "Frontend module" sections) for the in-app docs mirror.

### Array transform operations (additive; the operation catalog grows 77 → 83)

Six new operations let a pipeline transform a WHOLE array instead of one scalar value — the
primitive needed to iterate a `globals`-stored list, a repeater answer, a multi-file answer, or an
array `map` already produced. `Operation::isArrayOp()` marks all six; a NEW
`isCollectionOp()` marks only the four that carry a per-element pipeline.

| op | input | output | pipeline arg | terminal constraint |
|----|-------|--------|--------------|---------------------|
| `array_count` | `array<T>` | `number` | — | — |
| `array_at` | `array<T>` | `T` (nullable) | signed `index` (1-based, clamped — see below) | — |
| `array_map` | `array<T>` | `array<U>` | element pipeline, rooted at `T` | any base `U` — never itself an array |
| `array_filter` | `array<T>` | `array<T>` | element pipeline, rooted at `T` | `boolean` |
| `array_sort` | `array<T>` | `array<T>` | element pipeline, rooted at `T` | `number` |
| `array_reduce` | `array<T>` | `U` (a base, non-array, non-null) | a typed `seed(U)` + an accumulator pipeline, rooted at `U` | = the seed's own base `U` |

`array_count`/`array_at` are the wave-1 ops (whole-array, O(1), no pipeline arg); `array_map`/
`array_filter`/`array_sort`/`array_reduce` are the wave-2/3 higher-order ops. All six accept ANY
array regardless of its element's base type (`array<number>`, `array<enum>`, `array<object>` all
qualify as "an array") — a flat-type gate cannot express that, which is why the write-time walker now
tracks a full `descriptor`, not a flat type (below).

**The descriptor-tracking walker — the load-bearing change.**
`PipelineValidator::walkPipeline()`'s write-time type-flow gate for a pipeline now
tracks a running `$currentDescriptor` (the `VariableType::descriptor()` shape — `{base,
nullable, array, options?, fields?, elementDescriptor?}`) instead of a flat `VariableType`.
Per step: an ARRAY op (`isArrayOp()`) is accepted iff `$currentDescriptor['array'] === true`
(`opAcceptsDescriptor()`), regardless of element base; every OTHER op keeps the EXACT pre-existing
rule, re-expressed against the descriptor:
`VariableType::fromDescriptor($currentDescriptor) === $op->inputType()`. The terminal type
after each op is `Operation::outputDescriptor(array $inputDescriptor, array $args, ?array
$terminalDescriptor = null): array` — its `default` arm, covering EVERY op that existed before this
op set, is `return $this->outputType()->descriptor();`, which `fromDescriptor()`'s inverse relation
to `descriptor()` makes a PROVABLE no-op for every non-array op (byte-identical to the old flat-type
walk). The array ops override it: `array_count` → `NUMBER`'s descriptor; `array_at` → the input's
ELEMENT descriptor with `nullable:true, array:false`; `array_filter`/`array_sort` → the input
descriptor unchanged; `array_map` → the element pipeline's own TERMINAL descriptor (computed
recursively by walking that pipeline) with `array:true, nullable:false`; `array_reduce` → the seed's
descriptor (`array:false, nullable:false`). The FE mirror
(`resources/js/next/ui/editor/extensions/operationHelpers.ts`'s `resolveType`/`computeInputType`/
`pipelineSatisfies`) tracks the identical descriptor; `VariableOperationDefinition` gained an optional
`resolveOutput(inputDescriptor, args, terminalDescriptor)`, used only by the six array ops (every
other op keeps its static `outputType`).

An array's ELEMENT descriptor is derived by preferring an explicit `elementDescriptor` (an
`array<object>`/`array<file>`/typed `array<scalar>`) when the array descriptor carries one; otherwise
the element IS the array descriptor collapsed to a single item — a `MULTI` wire value `{base:'enum',
array:true, options}` yields element descriptor `{base:'enum', array:false, options}` (the option
list rides along, so `array_at`'s result is still a real, choosable enum downstream). Nested arrays
are rejected: a `map` terminal that is itself an array 422s at write and cannot be completed in the
editor.

```json
{ "op": "array_map", "args": { "pipeline": [
  { "op": "num_add", "args": { "value": 1 } }
] } }
```

```json
{ "op": "array_reduce", "args": {
  "seed": { "type": "number", "value": 0 },
  "reducer": [{ "op": "num_add", "args": { "value": {
    "kind": "variable", "ref": { "source": "scope", "path": "element", "type": "number" }
  } } }]
} }
```

**Scoped synthetic `element`/`index` — valid ONLY inside an element pipeline.** Two synthetic
variables, `element` (the array's ELEMENT descriptor) and `index` (`number`, 1-based), resolve inside
a `map`/`filter`/`sort`/`reduce` element pipeline and NOWHERE else — never in a top-level field's own
pipeline, a directive, an if-block condition, or the `form_submitted` trigger's condition tree.
`App\Modules\Variables\Support\ScopeRef::leaf(array $ref): ?string` is the ONE predicate all three
engine layers now share for "is this ref the synthetic scope" — SOURCE-AWARE: a ref whose `source` is
a real root (`globals`/`trigger`/`step`) is never scope even if its path happens to be
`element`/`index`; only `source: 'scope'` (or an absent source, tolerated for legacy rows) roots
against `element`/`index`. `leaf()` also returns a subfield tail — `element.<field>` — for the
`array<object>`/`array<file>` case below.

- **Write time**: `walkPipeline`/`validateElementPipeline` inject a `$scopeVars` map (`{'element':
  {type, enumOptions}, 'index': {type: NUMBER}, ...}`) ONLY while walking an element pipeline; a
  stored `element`/`index` reference OUTSIDE one is rejected with a `422`.
- **Runtime**: `element`/`index` are deliberately NOT added to `WorkflowVariableResolver::ROOTS`
  (that would be a fail-OPEN global root reachable from anywhere). Instead
  `OperationExecutor::scopeOverlay()` merges a SEPARATE `{'scope': {'element': ..., 'index':
  ...}}` key into the run context for one element's sub-run only; a scope reference that somehow
  reaches runtime outside that overlay resolves to `null` (never a crash), collapsing the enclosing
  condition to `false`.

```json
{ "kind": "variable", "ref": { "source": "scope", "path": "element", "type": "number" } }
{ "kind": "variable", "ref": { "source": "scope", "path": "index", "type": "number" } }
```

**`element.<subfield>` — an `array<object>` (repeater) or `array<file>` element's own fields.**
`WorkflowVariableCatalogService::elementScopeSubfields(array $arrayDescriptor): array` is the ONE
place a repeater/file array is descended for element access — it reuses the SAME
`objectSubfieldTypeMap()`/`fileSubfieldTypeMap()` the file/object-container work (ADR-0023) already
built, scoped to the synthetic `element` root, for THIS pipeline's write-validation/resolution only.
This is deliberately isolated from the global reference index and the condition-field list: a
repeater's element subfield is STILL not a global reference (unchanged from ADR-0023) — it exists only
inside an element pipeline's own scope.

Because no operation can consume a whole object/file snapshot, an `array<object>`/`array<file>`
element pipeline for `map`/`filter`/`sort` is NOT the bare `Array<{op,args}>` list — it is a
scope-rooted value-or-variable UNION, picking one subfield and transforming it:

```json
{ "op": "array_filter", "args": { "pipeline": {
  "kind": "variable",
  "ref": { "source": "scope", "path": "element.price", "type": "number" },
  "pipeline": [{ "op": "num_gt", "args": { "value": 100 } }]
} } }
```

`array_reduce`'s reducer NEVER takes this scope-rooted shape (it always roots at the accumulator, not
the element) — a union there is rejected the same way a malformed bare list would be. Inside a
scope-rooted union specifically, an op argument may reference ONLY `element.<subfield>`/`index` scope
variables — an ordinary `globals`/`trigger`/`steps` arg-variable is REJECTED at write inside it (unlike
an ordinary bare-list element pipeline, where such an arg-variable is still allowed, unchanged from
Phase 4/ADR-0025) — because the runtime can only resolve scope refs inside this specific shape
(`OperationExecutor::resolveScopePipeline()` fails the WHOLE element sub-run closed on any
non-scope union it meets); the write gate mirrors that boundary exactly rather than accepting a config
the runtime would always fail.

**The element-pipeline argument controls — `elementPipeline`/`reduceSeed` — are NOT the generic
whole-arg value-or-variable machinery Phase 4 built.**
`OperationArgType::ELEMENT_PIPELINE` (a bare `Array<{op,args}>` OR, for an object/file element,
the scope-rooted union above) and `::REDUCE_SEED` (a self-describing typed literal `{type, value}`,
`type ∈ text|number|boolean|date`) are two new arg-control kinds whose `argVariablePolicy()` returns a
third named `ArgVariablePolicy` constructor, `elementPipeline()` (`$refTypes`/`$coerceTo` both null,
like `structural()`) — but routed through a DEDICATED validator/resolver branch keyed on the arg
CASE, never the generic per-entry structural handling `sourceMap`/`choiceRules` get. `array_map`/
`array_filter`/`array_sort` each declare one `elementPipeline('pipeline')` arg; `array_reduce`
declares `reduceSeed('seed')` + `elementPipeline('reducer')` — the two-arg model: the seed literal
fixes the accumulator's initial value AND its required type `U` up front, and the reducer pipeline
must terminate in that same `U`.

**Terminal-by-construction — a wrong-terminal pipeline can never be saved.**
`PipelineValidator::validateElementPipeline()` enforces, at write time: `filter` must
terminate `boolean`, `sort` must terminate `number`, `reduce`'s reducer must terminate the seed's own
base `U`, and `map` may terminate any single base but never another array (`array<array<...>>` is
rejected). A mismatch 422s under the pipeline's own key. The frontend editor mirrors this by
disabling the element-pipeline editor's own save/done control until the running descriptor satisfies
the op's terminal — an author cannot even ATTEMPT to persist a wrong-terminal pipeline. The only
residual at runtime is a correctly-typed pipeline failing on specific element DATA (a division by
zero, an unparseable date on one element, …) — a data-level failure, not a terminal-type one — handled
by the fail-closed matrix in "g. Array transform operations" below.

See **ADR-0026-workflows-array-transform-operations.md** for the full design record (incl. the
`ScopeRef` unification of a name-collision bug the three engine layers previously disagreed on, and
the accepted `map |> array_at |> <op>` runtime-typing limitation). **In-app docs mirror: PLANNED, not
yet written.** Every earlier phase of this rework (Phases 0-4) added a matching section to
`resources/js/next/docs/pages/WorkflowsPage.vue` ("The typed variable system") in the same batch; this
feature has not yet had that pass — a follow-up in-app-docs batch should add it there, coordinated
with the Frontend module owner (`resources/js/next/pages/workflows/`).

---

### Custom functions — nesting, the frame stack, and the fail-closed safety design (additive; ADR-0029)

A workspace member may define a **custom function**: a reusable, user-authored pipeline OPERATION —
ONE input type, typed named ARGS, ONE return type, and a saved BODY pipeline over `{input + args}`
terminating in the return type. Once saved, it appears as an ordinary entry on the SAME `operations`
catalog every pipeline surface already reads, selectable in any pipeline whose running type matches
the function's declared input type — see "Functions" under "## Endpoints" above for the full
authoring contract (`POST/GET/PUT/DELETE /api/functions`). This section covers how a function is
CONSUMED: its wire identity, how it reaches the catalog, how it executes, and — the bulk of the
design work — how nesting (a function calling another function) is kept safe.

**Identity is the DB uuid; the wire op id is `fn:<uuid>`.** `OperationResolver::resolve()` checks
BUILT-IN ops first (`Operation::tryFrom($id)`); only when that misses AND the id carries the reserved
`fn:` prefix does it search the workspace's custom functions for a matching uuid. No built-in op id
starts with `fn:`, and the prefix is a completely different namespace from the `globals` reference
root, so a function's uuid can never collide with either. A `fn:<uuid>` that resolves to nothing (a
deleted function, a foreign-workspace uuid, a typo in a hand-written row) resolves to `null` — the
write-time walk then rejects it as an unknown op, and the runtime executor returns an ordinary
failure; neither ever mis-resolves it as something else. Renaming a function never changes its uuid,
so a pipeline step already saved as `{"op": "fn:b1b2c3d4-..."}` never breaks when the function's
`name` changes — `name` is a user-facing label only, not part of the identity, and is NOT unique.

**The catalog merge — every workspace function is an `operations` entry, filtered by input type
exactly like a built-in.** `WorkflowVariableCatalogService::forContext()` merges
`Operation::catalog()` (the 83 built-ins) with one additional entry per workspace function:

```json
{
  "id": "fn:b1b2c3d4-e5f6-...",
  "input": "text",
  "output": "text",
  "args": [{ "id": "prefix", "type": "text" }],
  "label": "Normalize phone number",
  "description": "Strips spaces/dashes and prepends the country code."
}
```

`label`/`description` are ADDITIVE, function-only wire keys — a built-in op carries neither (the FE
localizes a built-in's label via i18n; a function's own `name`/`description` are read VERBATIM, since
there is nothing to localize about a user's own words). `args` is `[{id: argName, type: argType}]` —
the function's OWN declared arg types, so a caller's pipeline editor can render each arg's input by
its real type. Because the FE's add-operation menu already filters the catalog by the pipeline's
CURRENT running type, a function is automatically offered everywhere its own `input` type makes it
eligible, with no separate wiring — the identical mechanism every built-in op already uses.

**Functions CAN NEST** — a function's `body` may itself contain a `fn:<uuid>` step referencing
ANOTHER workspace function. This is the one place a user-authored GRAPH exists in this engine, and it
is the reason the rest of this section is safety design, not feature description.

**WRITE-TIME: a cycle is REJECTED before it can be saved — a 3-colour DFS over the reference
graph.** `FunctionDefinitionValidator::validateAcyclic()` builds a directed graph — one node per
workspace function, an edge `A → B` whenever `A`'s body references `fn:B` — with the row currently
being saved substituted into the graph as its own node (its uuid on UPDATE; a synthetic,
unreferenceable node on CREATE, so a brand-new function can never itself be part of a cycle — nothing
can reference a uuid that does not exist yet). References are collected RECURSIVELY, at any nesting
depth (element pipelines, argument-variable sub-pipelines, choice-rule `when`s, reducers) — a
function reference hidden three levels deep inside a `map` element pipeline is found exactly like a
top-level one. A back-edge to a node still marked VISITING — including a node's edge to itself, a
direct self-reference — is a cycle; ANY cycle anywhere in the graph is rejected with a `422` under
`body`, regardless of any other error in the same request: `"A function may not reference itself,
directly or through another function (a cycle was detected)."`

**RUNTIME: two INDEPENDENT fail-closed backstops, because a corrupted/hand-written/raced row can
bypass the write check.** `App\Modules\Variables\Support\FunctionScope` carries the execution state
through the pure, re-entrant `OperationExecutor`:

| Backstop | Trigger | Effect |
|---|---|---|
| `overDepth()` | Entering one more function would exceed `PipelineLimits::MAX_FUNCTION_EXPANSION_DEPTH` (**5**) | The op fails CLOSED (an ordinary failure result — never a crash, never a loop). |
| `hasVisited($functionId)` | `$functionId` is already on the ACTIVE call chain | The op fails CLOSED — catches a cycle on its FIRST re-entry, at whatever depth it occurs, rather than only once the depth cap is exhausted. |

```php
if ($scope->overDepth() || $scope->hasVisited($op->id())) {
    return self::FAIL; // fail CLOSED — never loops, never throws
}
```

Both checks run BEFORE anything else in `OperationExecutor::expandFunction()`. A condition built on a
function that hits either gate simply evaluates `false`; a value-producing field resolves to its own
soft default — the SAME fail-closed doctrine every other malformed pipeline configuration in this
engine already follows (never an exception escaping the executor). Pinned by
`tests/Unit/Variables/OperationExecutorFunctionTest.php::test_a_five_deep_function_chain_executes_but_a_deeper_one_fails_closed`
(exactly 5 nested calls succeed, a 6th fails closed),
`test_a_corrupted_cycle_is_caught_by_the_visited_set`, and `test_a_direct_self_cycle_is_caught_by_the_visited_set`.

**Execution is BY EXPANSION — no second interpreter.** `OperationExecutor::expandFunction()`: (1)
binds `{'input': {value: <the running value>, type: <the function's input type>}, '<argName>':
{value: <that arg's pre-resolved literal>, type: <the arg's declared type>}, ...}` into a scope
FRAME; (2) `$scope->enter($op->id(), $frame)` returns a scope one level deeper, with the function's id
appended to the visited chain and the frame INSTALLED; (3) builds a FRESH context carrying ONLY the
entered scope — a function body is PURE over `{input, args}`, so it is never handed the caller's
`trigger`/`steps`/`globals`, nor any enclosing array-element `scope` overlay; (4) pre-resolves the
body's own top-level `input`/`<argName>` scope references to literals, then RE-ENTERS the SAME
`execute()` on the body, one level deeper; (5) VERIFIES the body's terminal type exactly equals the
function's declared return type — a mismatch (only reachable from a corrupted row; the write
validator already forces this equality) fails CLOSED rather than coercing a wrong value. A HARD
failure (`assert_present` over an empty value, ADR-0022) does **NOT** escalate past a function
boundary — the function absorbs it into an ordinary fail-closed result, so a boolean-returning
function used inside a condition simply reads `false` rather than aborting the run.

**THE FRAME STACK: `ScopeRef` generalizes from a fixed `element`/`index` root pair to a
CALLER-SUPPLIED root list, so an array-transform element pipeline (ADR-0026) NESTED INSIDE a
function's body sees BOTH scopes at once.** `ScopeRef::leaf()` now takes an explicit `$roots`
parameter (defaulting to `['element', 'index']`, so every pre-existing call site outside a function
body is byte-identical), and `OperationExecutor::scopeRoots()` computes the LIVE root set as the
UNION of the default element/index pair with `FunctionScope::frameRoots()` (`input` plus each arg
name currently bound):

```php
private function scopeRoots(array $context): array
{
    $frameRoots = FunctionScope::fromContext($context)->frameRoots(); // e.g. ['input', 'rate']

    return $frameRoots === []
        ? ScopeRef::DEFAULT_ROOTS                                     // ['element', 'index'] — unchanged
        : array_values(array_unique([...ScopeRef::DEFAULT_ROOTS, ...$frameRoots]));
}
```

So a `map`/`filter`/`sort`/`reduce` element pipeline sitting inside a function body can reference
`element`/`index` (the array being iterated) AND the enclosing function's `input`/`<argName>` in the
SAME pipeline — e.g. a function `"apply rate to each item"` (`input: multi`, `args: [{name: rate,
type: number}]`) whose body is `array_map` with an element pipeline that does
`element |> num_multiply(rate)`. The WRITE side validates against the IDENTICAL union, not a parallel rule that could drift:
`PipelineValidator::validateElementPipeline()` merges the enclosing function's scope vars
(`input`/arg names, with any INNER `element`/`index`/`element.*` stripped so a stale, already-closed
nesting level's scope can never leak) with the fresh `element`/`index` for the pipeline being walked —
the exact mirror of `OperationExecutor::scopeRoots()`'s union, so the write validator can never accept
a scope reference the runtime would then fail to resolve, or vice versa. Pinned by
`tests/Unit/Variables/OperationExecutorFunctionTest.php::test_a_body_maps_over_the_input_with_a_function_arg_in_scope`
(and its `_filters_`/`_reduces_` siblings).

**A function CALL does NOT stack frames across function boundaries.** When function `A`'s body calls
function `B`, `FunctionScope::enter()` REPLACES `A`'s frame with `B`'s own — `B`'s body can reference
its OWN `input`/args only, never `A`'s. A function's contract is exactly its declared signature;
letting a callee reach into its caller's bindings would make that contract a lie (the same function
could then behave differently depending on who happened to call it). The frame stack above is
therefore always exactly two levels deep in practice — the CURRENTLY-EXECUTING function's own frame,
plus one array-element overlay nested inside it — never deeper, regardless of how many functions are
nested inside one another; it is the depth cap and the visited-set (above) that bound the CALL chain
itself. Pinned by `test_a_nested_function_executes` and `test_a_function_runs_inside_a_match_to_choice_rule_condition`.

**The delete guard is fail-closed too** — see `DELETE /api/functions/{function}` above: blocked with
a `422` while ANOTHER function's body, or any WORKFLOW's step configs/conditions, still references it
(`FunctionReferenceLookup`, the dependency inversion ADR-0027 records), so a delete can never leave a
dangling `fn:<uuid>` reference that would fail every future run of whatever used it.

**Current limitation, stated explicitly (accepted, not an oversight): scalar-first argument
call-controls.** `CustomFunctionOperation::argControl()` maps a `number`/`boolean`/`date` arg to its
own literal control; EVERY OTHER type (`enum`, `multi`, `file`, `object`, `time`) takes a plain
stringifiable TEXT control at the call site. A function whose arg is declared `enum`, for instance, is
fully valid and executes correctly, but the CALLER's pipeline editor offers a free-text box for it
today rather than a picker constrained to that enum's real option set — richer per-type call
controls are real future work, not blocked on anything structural.

See **ADR-0029-custom-functions.md** for the full design record (the complete rejected-alternatives
list, including why a callee cannot see its caller's frame and why the write/runtime cycle checks are
both needed) and **ADR-0027-variables-module-extraction.md** for the `ElementScopeResolver`/
`FunctionReferenceLookup` dependency-inversion pattern this feature's delete guard and array-element
scoping both reuse. **In-app docs mirror: PLANNED, not yet written** — see the array-transform
section's own note above; this feature has not yet had its `resources/js/next/docs/pages/
WorkflowsPage.vue` pass either.

---

## Runtime operations, if-blocks, and AI text (SB1 / SB2)

Before this revision both serializations above only ever did identity lookup + coercion. SB1
extends the runtime resolver (`WorkflowVariableResolver`) with three additional capabilities, and
SB2 adds a fourth (AI text generation). All four share ONE property: **they never throw** — every
failure mode collapses to a fail-closed default (`''` for a text-field directive/if-block/ai-text
result, the field's own soft coercion for a value-or-variable pipeline — see the coercion table
above), so a bad configuration degrades a field's CONTENT, never the whole run (except where a
field's own hard-fail doctrine already applied, e.g. a blank `create_task.title` — see the Steps
section's hard/soft table, unchanged).

**Where each is enabled** (a frontend/editor decision, not a backend restriction — the resolver
itself would execute any of these in any text field): all four text fields —
`create_task.title`/`.description` and `create_form_report.name`/`.guidelines` — offer directive
pipelines, if-blocks, AND `@[ai-text]`. This is a FE-only change (SF3.6 in
`docs/next/workflows-uxui-spec.md` §4.6.3): `title`/`name` originally rendered as toolbar-less,
single-line editors offering pipelines only, but now render as ordinary multi-line editors with
the full toolbar, carrying the same capability `description`/`guidelines` always had. See the
Steps section for the field-by-field config table.

### a. Directive pipelines (`@[variable]` in text fields)

Covered above under "Two serializations" — a non-empty `data.pipeline` on the markdown directive
transforms the resolved value through `OperationExecutor` and stringifies the typed
result into the surrounding text.

### b. Conditional `if-block`s in text fields

A markdown field may contain a FENCED `if-block` container — the exact byte format the next
editor's markdown serializer produces (see `resources/js/next/ui/editor/README.md` for the
authoring/encoding side, which this runtime mirrors byte-for-byte):

```text
```if-block {"id":"if_1","v":1}
[[IF {"id":"b1","condition":{"variableId":"trigger.fields.priority","pipeline":[{"op":"enum_is","args":{"value":"urgent"}}]}}]]
This is urgent — @[variable]("...")!
[[ELSE_IF {"id":"b2","condition":{"variableId":"trigger.fields.priority","pipeline":[{"op":"enum_is","args":{"value":"high"}}]}}]]
High priority.
[[ELSE {"id":"b3"}]]
Standard priority.
```
```

`WorkflowVariableResolver::resolveString` scans for `` ```if-block `` fences and, for each one,
evaluates every branch's `condition.variableId` + `condition.pipeline` IN ORDER — `IF`, then each
`ELSE_IF`, falling to `ELSE`, or to `''` when nothing matches — and replaces the WHOLE fence with
the winning branch's body, resolved RECURSIVELY (a branch body may itself carry nested
directives, nested if-blocks, or an `@[ai-text]`). A condition reads `variableId` off the run
context, recovers its REAL base type the same way a directive pipeline does (type map by path,
else the pipeline's first-op input type, else falling to `boolean`), runs it through
`OperationExecutor`, and requires a `true` **boolean** terminal. Nesting is capped at
depth **6** (`IF_BLOCK_MAX_DEPTH` — a margin over the editor's own default `maxDepth: 3`) — beyond
the cap a block resolves to `''`. A missing/non-reference `variableId`, an unparseable condition,
or ANY executor failure is fail-closed to `false` (never surfaced as an error) — a misconfigured
condition simply falls through to `ELSE` (or to `''` with no `ELSE`).

### c. `@[ai-text]` — AI-generated text (SB2)

A markdown field may contain an `@[ai-text]("<json>")` directive — `<json>` (after `\"`→`"`
un-escaping) is `{"v":1,"data":{id, personaId, prompt, labels}}`; `labels` is accepted on the wire
but NOT consumed by the runtime (an editor-only concern). At RUN time:

1. `data.prompt` (markdown that may itself contain a nested `@[variable]`, if-block, or another
   `@[ai-text]` directive) is resolved through the SAME resolver / run context / type map FIRST —
   so form values, step outputs, and conditional branches land in the prompt BEFORE it reaches the
   model. Nested `@[ai-text]` is depth-capped at **3** (`AI_TEXT_MAX_DEPTH`) — beyond the cap
   resolves to `''` with NO further AI call spent.
2. The resolved prompt + `App\Modules\Variables\Enums\AiPersona::fromNullable(personaId)` (defaults
   to `neutral` for a null/unknown id) are handed to `WorkflowAiTextService::generate()` — now a THIN
   DECORATOR that keeps the per-run call-count budget and delegates to the shared
   `App\Modules\Variables\Services\AiTextGenerationService`, which runs the tool-less
   `App\Modules\Variables\Agents\AiTextAgent` (provider/model from `config('ai')`; NO tools, NO
   structured-output schema — plain text only, read from `$response->text`) and trims + length-caps
   the result.
3. The generated string REPLACES the directive span verbatim — it is never re-interpreted as a
   reference/directive itself (every `@[ai-text]` span is masked to an inert placeholder before
   the variable/flat-token passes run, then restored with the generated text afterward).

**Fail-closed, budgeted, length-capped — never an exception:**

| Failure mode | Result |
|---|---|
| Blank prompt after resolution | `''` — no AI call spent. |
| Per-run call budget exhausted (`config('workflows.ai_text_max_calls_per_run')`, default 10) | `''` — logged, no call. |
| Provider/transport failure (missing key, timeout, any exception) | `''` — logged, never thrown. |
| Over the workspace's AI token cap (`config('ai.meter.monthly_token_cap')`, 0 = disabled) | `''` — the shared Variables cost meter gates BEFORE spend; full meter docs land in R2 sub-phase 2e. |
| Generated text longer than `config('workflows.ai_text_max_chars')` (default 2000) | truncated (multibyte-safe) to the cap. |

The call BUDGET is scoped to ONE run — `WorkflowAiTextService` is resolved fresh alongside the
resolver for each `WorkflowRunJob` (never bound as a container singleton), so its call counter
naturally resets every run; it bounds how much a SINGLE run can fan out into AI spend,
independent of the run-budget cost caps below (`max_runs_per_month` etc.), which meter the NUMBER
of runs, not AI calls within one.

**Personas — a closed set of TONES, legacy and read-only as of R2 (ADR-0040).** `App\Modules\Variables\
Enums\AiPersona`: `neutral` (default) | `friendly` | `formal` | `concise` — each folds a short English style
instruction into the agent's system prompt (steering TONE only; the agent is always told to write in the
language of the resolved prompt, so the English tone line never forces English output). ADR-0013 originally
rejected the Bot/Character system as this seam's persona and left "write like Bot X" as a possible future.
That future was built (see "Per-block AUTHOR" immediately below) — but as an ADDITIVE picker, not a rename of
this enum: `personaId`, `AiPersona::fromNullable()`, and the `ai_personas` catalog key below are all
UNCHANGED and still fully functional at runtime for a block saved before that feature. The EDITOR no longer
offers this persona Select for a new pick — see ADR-0013 §4's appended amendment.

**Per-block AUTHOR (R2, ADR-0040) — the picker that replaced the persona Select.** An `@[ai-text]` block's
JSON payload gains two OPTIONAL keys, EMIT-OR-OMIT: `authorId` (a workspace Bot id) and `authorName` (a
display-only snapshot, never authoritative — the backend reads `authorId` only, no alias). The bot named
contributes its VOICE — persona/style/dictionary/phrases/prohibitions, composed by `Bot\Services\
BotVoiceComposer` — never its knowledge or its tools; no task execution happens from this seam. A block
already carrying a legacy `personaId` renders, in the editor, as a READ-ONLY "legacy tone" bar rather than a
pickable Select.

**Resolved LIVE, once per RUN PASS — never frozen (unlike the Generator's `recipe_snapshot.author_voices`,
`docs/backend/generator-sessions-api.md`).** A workflow run has no snapshot object to freeze author voices
into — a run always executes the DEFINITION as it stands. `WorkflowStepRunner::run()` therefore scans the
WHOLE step definition for every `@[ai-text]` `authorId` it names (via the shared `VariableResolver::
collectAiTextAuthorIds()` scanner — nested prompts and if-block branches included), resolves them in ONE
batch lookup (`AuthorVoiceResolver::voicesFor()`) pinned to the run's own `workspace_id` (a queued run has no
ambient active workspace — an unpinned lookup would be unconstrained), and installs the map on the ambient
`Variables\Support\AiVoiceContext` for that pass — SAVED/RESTORED (not merely set/cleared), the identical
idiom the run context and the per-run `@[ai-text]` budget already use, so a re-triggered CHILD run executing
in-process inside a step neither loses nor pollutes its PARENT's author map.

**Consequence for a SUSPENDED, later-resumed run (ADR-0039).** Because the map is rebuilt on every pass
rather than carried in `waiting_on`, a resumed pass re-enters `run()` and resolves the CURRENT state of every
named bot — so editing (or deleting) a bot's voice WHILE a run is parked changes what every step still ahead
of the resume point produces, even though the step that originally triggered the suspension already saw the
OLD map on its first pass. A step that already succeeded before the suspension is unaffected (steps never
re-run). This is a deliberate consequence of "a run always reads live state," not a bug — see ADR-0040 D3 for
the full reasoning and its contrast with the Generator's snapshot-and-freeze posture.

**Precedence — one function decides it, the block's own author wins.**
`AiVoiceContext::effectiveDirective(?string $authorId)` is asked by the SAME shared `AiTextGenerationService::
generate()` this section already describes: a block's own author (if it resolves) wins; a workflow run never
sets a run-wide voice of its own (that concept exists only on the Generator side, ADR-0036), so with no
resolvable block author the legacy `personaId` tone applies, defaulting to `neutral`. An author that cannot be
resolved (deleted bot, foreign workspace, malformed id) is FAIL-SAFE, never fail-closed: it is simply absent
from the map, so the block falls through to the next tone in the chain and still renders — it is never left
blank and never fails the step.

**Cost attribution is UNCHANGED — for the per-BLOCK author.** A block naming a bot as its author does not
re-attribute that block's `ai_text` spend to the named bot — the run's own actor (`HasCreator`'s
polymorphic union) still pays, identically to every other spend this run makes. **The STEP-level
`generate_content.bot_id` is deliberately the opposite**: it DELEGATES the whole created session, so every
spend of that session attributes to the BOT (`GenerationSession::meterActor()` — the same semantics as a
manual delegation; see "Author delegation (`bot_id`)" above). The line between the two is authorship
granularity: a block-level voice is a styling choice inside someone else's run and must not move spend off
that someone's cap, while a session-level delegation makes the bot the author of record for the whole
artifact. See `docs/decisions/ADR-0040-per-block-ai-text-author.md`
for the full design record (the Variables-side `AuthorVoiceResolver` contract, the Bot-side inversion of
dependency, and every alternative considered).

**Prompt-injection posture (accepted, bounded risk).** The resolved prompt embeds values taken
from user-submitted forms (untrusted input). `App\Modules\Variables\Agents\AiTextAgent`'s instructions frame
EVERYTHING in the prompt as DATA to write about, never as commands, and explicitly tell the model
to ignore any embedded command/role-play/rule-change attempt. The blast radius stays narrow even
if that framing is defeated: the agent has NO tools, its output lands only in a task/report text
field inside the SAME workspace the run belongs to, it is length-capped, and it can reference only
the whitelisted `trigger`/`steps` context (the same exfiltration-safe whitelist every other
directive already relies on — see below). This is a documented, ACCEPTED risk, not eliminated.

Both catalog endpoints — `GET /forms/{form}/workflow-catalog` and, since Phase 0 of the
variable-typesystem rework, the form-independent `GET /workflows/catalog` (see above) — also
return `ai_personas` (the label-less persona catalog the ai-text editor's persona picker consumes;
the FE localizes via `workflows.aiPersona.<id>`) and `types` (see below):

```json
{
  "data": {
    "variables": [...],
    "fields": [...],
    "operations": [...],
    "ai_personas": [{ "id": "neutral" }, { "id": "friendly" }, { "id": "formal" }, { "id": "concise" }],
    "types": [
      { "id": "text", "primitive": "text", "operators": ["equals", "not_equals", "contains"] },
      { "id": "number", "primitive": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"] },
      { "id": "boolean", "primitive": "boolean", "operators": ["is_true", "is_false"] },
      { "id": "date", "primitive": "text", "operators": ["before", "after", "on", "between"] },
      { "id": "enum", "primitive": "text", "operators": ["is", "is_not", "in"] },
      { "id": "multi", "primitive": "text", "operators": ["includes", "excludes"] },
      { "id": "file", "primitive": "text", "operators": ["filled", "empty"] }
    ]
  }
}
```

(`operations` — the 68-op catalog `Operation::catalog()` the directive/value pipelines
above run on (66 at the time SB1/SB2 shipped `ai_personas` as its sibling key; now 68 after the
`enum_to_choice`/`match_to_choice` addition — see "Choice fields" below).)

**`types`** (`WorkflowVariableCatalogService::variableTypes()`) — one entry per
`VariableType` case, `{ id, primitive, operators }`, in enum declaration order (text,
number, boolean, date, enum, multi, file):

- `id` — the `VariableType` value.
- `primitive` — the EDITOR primitive (`VariableType::editorPrimitive()`) this type
  degrades to inside a markdown directive's `data.type`. Only `number` and `boolean` keep their
  own primitive; `date`/`enum`/`multi`/`file` all degrade to `text` (see "Two serializations"
  above) — the directive carries no other type hint, so this is how the FE knows which types
  round-trip losslessly through a directive and which don't.
- `operators` — exactly `VariableType::operators()` for that type, the SAME set already
  shown per-field in `fields[].operators` and enforced by the condition write-validator (see "The
  operator × type matrix" below) — one wire source for the type vocabulary instead of a
  hand-maintained frontend mirror of it.

Label-less like `operations`/`ai_personas` (the FE localizes each type's display name); it exists
so a form-less catalog can still describe the full type system without a static frontend mirror —
the same motivation `GET /workflows/catalog` itself was built for.

**Phase 1 additions (append-only, no breaking change to either list).** `types` gains an 8th
entry, `{ id: "time", primitive: "text", operators: [] }` — `VariableType::TIME` is
descriptor-only this phase (see "Structured `descriptor`" above), so it carries no operators yet.
`operations` grows from 68 to **77** (72 immediately before this phase, +5 append-only —
`coalesce`/`is_present`/`is_null`/`assert_present`/`date_format`, see "Presence, null-handling,
and date-format ops" below).

**Phase 2 addition (append-only).** `types` gains a 9th entry, `{ id: "object", primitive: "text",
operators: [] }` — `VariableType::OBJECT` is descriptor-only, the same tripwire as `time`
(see "Structural descriptor: object containers & the file composite" above). `operations` is
UNCHANGED at 77 — this phase added no new pipeline operations, only catalog/reference-index/
resolver-lookup surface.

**Array-ops addition (append-only).** `operations` grows 77 → **83** — six array-transform ops
(`array_count`/`array_at`/`array_map`/`array_filter`/`array_sort`/`array_reduce`); see "Array
transform operations" above.

**Module extraction (ADR-0027) — a NAMESPACE change only, zero wire change.** `Operation::catalog()`
now lives in `App\Modules\Variables\Enums\Operation` (moved from Workflows), and `types`'s entries
now derive from `App\Modules\Variables\Enums\VariableType` — the 83 built-in op ids, their
`input`/`output`/`args` shapes, and the `types` list's 9 entries are all BYTE-IDENTICAL to before the
move; only which module owns the class changed.

**Custom functions (ADR-0029, additive) — `operations` also carries one entry per WORKSPACE
FUNCTION, on top of the fixed built-in count above.** Unlike the built-ins, this part of the catalog
is workspace-specific and has no fixed size: `WorkflowVariableCatalogService::forContext()` merges
`Operation::catalog()` with one `{id: 'fn:<uuid>', input, output, args, label, description}` entry
per function the active workspace has defined (empty when the workspace has none, or when the
catalog is read with no active workspace at all — e.g. a queue/console context — mirroring the
`globals` catalog source's own workspace guard). See "Custom functions" above for the full merge
mechanics, the `label`/`description` additive wire keys, and the fail-closed/cycle/frame-stack rules
governing how a `fn:<uuid>` entry actually executes.

### d. Write-time validation — runtime-only vs. validated

There is NO PHP markdown parser in this codebase, so a text field's directive pipeline / if-block
/ ai-text content is **NOT validated at write time** — `StoreWorkflowRequest` only checks that
`title`/`name` (etc.) are non-empty strings, never parses their markdown content. Every failure
mode described above is therefore a RUNTIME concern only, always fail-closed — an author can save
a step whose description contains a malformed if-block or a pipeline that will fail at every run;
the workflow saves, and the field simply resolves emptier than intended at run time.

**The value-or-variable pipeline is the one exception.** Because it lives in a structured
(non-markdown) field, `StoreWorkflowRequest` (via
`PipelineValidator::validateValuePipeline()`) DOES type-flow-validate it on save —
walking the pipeline from the ref's declared type through each op to a required TERMINAL type per
field:

| Field | Allowed pipeline terminal(s) |
|---|---|
| `create_task.priority` | **CHOICE** — must END in a choice-producing op (`enum_to_choice` / `match_to_choice`); see "Choice fields" below. |
| `create_task.deadline` | `date` |
| `create_form_report.submissions_from` / `.submissions_to` | `date` |

A wrong terminal, an unknown op, a type mismatch mid-pipeline, or a bad arg (an out-of-catalog
`sourceOption`/`sourceMap` key, a non-Y-m-d literal date, a foreign arg key, …) is a `422` under
`steps.<i>.config.<field>.pipeline.<m>.op` / `.args.<key>` / `.args.<key>.<index>` — the SAME
indexed-key convention a condition pipeline already uses (`PipelineValidator` is the
ONE place both pipelines' arg/type rules live), so the frontend maps every message to the
offending pipeline step. The reference catalog this validates against (`ref.type` +
`sourceOption`/`sourceMap` option membership) is `WorkflowVariableCatalogService::referenceIndex()`
— built ONLY when some step actually carries a value-or-variable pipeline, so the common
(pipeline-less) create/update path pays no extra cost.

### e. Choice fields — `targetOptions` + the `producesChoice()` terminal rule

A **choice field** is a value-or-variable field whose destination is not just a workflow TYPE but
one of a small, FIXED set of option VALUES — today, exactly `create_task.priority`
(`TaskPriority::ids()` = `urgent|high|medium|low`). Two operations exist to map an arbitrary
source value into such a set:

| Op | Input → output | Args | Runtime semantics |
|---|---|---|---|
| `enum_to_choice` | `enum` → `enum` (choice) | `mapping` — a `sourceMap` arg (`mapType: enum`): each SOURCE option value → one DESTINATION option value. | Looks the source option up in `mapping`; an UNMAPPED source option fails closed (same as every other `enum_to_*` op). |
| `match_to_choice` | `text` → `enum` (choice) | `rules` — a `choiceRules` list of `{when, then}` (first match wins); `fallback` — a REQUIRED `choiceFallback` (used when no rule matches, keeping the op total). | Compares the text value against each rule's `when` in order; the first equal match's `then` wins, else `fallback`. A missing/blank `fallback` fails closed. |

`Operation::producesChoice()` is `true` for exactly these two ops (and only these two) —
callers check this method at the TERMINAL-op position rather than hardcoding op ids, so a future
choice-producing op is picked up automatically. Any NON-text source (number/boolean/date/multi)
reaches `match_to_choice` the same way it reaches any other text-only op: through the existing
`*_to_text` op first (e.g. `date_to_text` → `match_to_choice`).

**The destination option set is injected PER-FIELD, not part of the static op descriptor.**
`enum_to_choice`'s `mapping` arg and `match_to_choice`'s `rules`/`fallback` args are declared with
NO fixed option list (`OperationArgType::CHOICE_RULES` / `CHOICE_FALLBACK`, and a
`sourceMap` arg whose `mapType` is `enum`) — the actual allowed VALUES
(`$targetOptions`, e.g. `TaskPriority::ids()`) are threaded into
`PipelineValidator::validateValuePipeline()` by the caller
(`StoreWorkflowRequest::validateCreateTaskConfig()`) for the ONE field that needs them today. This
keeps the 68-op catalog itself generic (an op descriptor never hardcodes "priority") while still
letting the write validator enforce that every mapped/ruled value is a real option of the
DESTINATION field.

**Write contract for a choice field.** A value-or-variable field whose destination is a choice
field:

- a **literal** must still be a plain member of the field's own enum (e.g. a literal `priority`
  must be a valid `TaskPriority`) — unchanged from before this batch;
- a **variable** MUST carry a NON-EMPTY pipeline that ENDS in a `producesChoice()` op, and every
  mapped/ruled target value in that pipeline must be `⊆` the field's option set. A bare/identity
  variable ref (no pipeline) or a pipeline whose terminal is NOT a choice op (e.g. `enum_to_text`,
  which used to be accepted under the old "enum or text" terminal rule) is now **REJECTED** —
  `422` under `steps.<i>.config.priority.pipeline` (no pipeline) or
  `.pipeline.<m>.op` (wrong terminal) or `.pipeline.<m>.args.mapping.<key>` /
  `.args.rules.<i>.then` / `.args.fallback` (an out-of-set target value).

```json
{ "op": "enum_to_choice", "args": { "mapping": { "blog": "high", "news": "low" } } }
{ "op": "match_to_choice", "args": { "rules": [{ "when": "BREAKING", "then": "urgent" }], "fallback": "medium" } }
```

`deadline` / `submissions_from` / `submissions_to` are plain `date` fields — they never carry
`targetOptions` (the date terminal is not a choice), so their existing "enum or text vs. date"
distinction is untouched by this section.

**This is a validator-only tightening, not a runtime behavior change.** `WorkflowVariableResolver`
never calls `producesChoice()` — at RUN time a `priority` pipeline still just executes and coerces
its result to `VariableType::ENUM` exactly as before (a result outside `TaskPriority`
soft-defaults to `medium`, same as an unresolved/unknown value always has). A workflow SAVED
before this change keeps firing and keeps producing the same task priority it always did; only
attempting to RE-SAVE a step whose `priority` pipeline does not end in a choice op now fails with
a `422` where it previously passed. See `docs/decisions/ADR-0014-workflows-choice-coercion.md` for
the full rationale (why a generic `enum` type + injected `targetOptions` + a `producesChoice()`
terminal rule, instead of a new branded "choice" type in the closed
`VariableType`/`OperationArgType` sets).

### f. Presence, null-handling, and date-format ops (phase-1b, append-only)

5 operations are appended to `Operation` — ids are only ever appended, never reordered or
removed (pinned by `WorkflowConditionEngineTest::test_operation_ids_are_the_pinned_wire_contract`),
growing the catalog **72 → 77**:

| Op | Input → output (nominal) | Args | Runtime semantics |
|---|---|---|---|
| `coalesce` | `text` → `text` | `fallback` (literal) | The running value when present, else `fallback` normalized to the running type. |
| `is_present` | `text` → `boolean` | — | `true` when the running value is non-empty. |
| `is_null` | `text` → `boolean` | — | The negation of `is_present`. |
| `assert_present` | `text` → `text` | — | The running value when present; over an EMPTY value, the ONE opt-in HARD failure (see below). |
| `date_format` | `date` → `text` | `pattern` (literal, safe-token) | Renders the date via the safe-token pattern below. NOT a presence op. |

**The first four are the PRESENCE family** (`Operation::isPresenceOp()`). Their declared
`input`/`output` above are the NOMINAL shape the catalog and the write-validator advertise; at
RUN time `OperationExecutor::execute()` dispatches them BEFORE the normal per-step
`inputType() !== currentType` gate, so — unlike every other op — they accept the running value AS
IS regardless of its declared type, including a base value that failed normalization outright (a
genuinely absent/unrepresentable value a normal op would already have failed closed on).
"Empty" for this family (`isEmptyValue()`) is `null`, `''`, `[]`, or an unnormalizable base —
mirroring the existing FILLED/EMPTY condition semantics.

**`date_format`'s safe-token whitelist** (`OperationExecutor::DATE_FORMAT_TOKENS`,
matched longest-first so `MMMM` never loses to `MM`) — a raw PHP `date()` format string is NEVER
honored; any byte outside this table or the literal separators ` - / : . ,` fails the WHOLE
pattern CLOSED (soft failure — `''`/coerced null downstream — never a throw):

| Token | Renders |
|---|---|
| `YYYY` | 4-digit year |
| `MMMM` | full month name |
| `MMM` | short month name |
| `MM` | 2-digit month |
| `DD` | 2-digit day |
| `D` | unpadded day |
| `HH` | 2-digit hour |
| `mm` | 2-digit minute |

Example: pattern `DD/MM/YYYY` over `2026-01-09` renders `09/01/2026`; `D MMMM YYYY` renders
`9 January 2026` (`OperationExecutorTest`).

**`assert_present` is the ONE opt-in HARD failure in the pipeline engine.** `OperationResult`
gained a `bool $hard` flag + a `hardFailure()` factory. Over an empty value, `assert_present`
returns `OperationResult::hardFailure()` — still a `failed` result, so a CONDITION caller (which
only ever reads `$result->failed`) is unaffected and stays fail-closed to `false` exactly as
before. A VALUE-producing caller (`WorkflowVariableResolver::applyDirectivePipeline()` /
`resolveVariableUnion()`) additionally checks `$result->hard` and RE-RAISES it as a
`RuntimeException`, which the run records as that step's failure — the run stops there, joining
the field's existing hard-fail doctrine (e.g. a blank `create_task.title`). The executor itself
still NEVER throws — the hard signal is a return value read by exactly one call site.

**Frontend mirror**: `standardOperationsCatalog()` declares the same 5 ids with the same nominal
input/output the backend catalog advertises (labels only — the FE does not special-case presence
semantics, it renders the op like any other); `date_format`'s `pattern` arg carries a persistent
`hint` (`VariablePipelineEditor.vue`) showing the safe-token legend under the field.

**A known asymmetry, inert this phase (Phase 2 item)**: `PipelineValidator::walkPipeline`
— the write-time type-flow gate for a value-or-variable pipeline (`priority`/`deadline`/
`submissions_from`/`submissions_to`) — was not changed this phase and still requires an EXACT
`op->inputType() === currentType` match at every step, including for the 4 presence ops (nominally
`text`), where the runtime executor above already bypasses that exact check. See "Accepted
residual risks" below and ADR-0022 for the full reasoning.

### g. Array transform operations — fail-closed matrix and caps

Every higher-order array op's DATA-level failure mode (as opposed to the wrong-terminal case, which
is rejected at WRITE time — see "Array transform operations" above) is named explicitly:

| op | on a per-element sub-run failure | on a non-matching terminal |
|---|---|---|
| `array_map` | fails the WHOLE op closed | (cannot occur — terminal is gated at write) |
| `array_filter` | drops the element | drops the element (a non-`boolean` terminal is treated as a failure) |
| `array_sort` | sends the element LAST | sends the element LAST (a non-`number` terminal is treated as a failure); ties (incl. multiple failed keys) keep their ORIGINAL relative order — a stable sort |
| `array_reduce` | keeps the PRIOR accumulator, unchanged | keeps the PRIOR accumulator (a terminal that is not the seed's own base `U` is treated as a failure) |
| `array_count` / `array_at` | — pure and total, cannot fail on data | — |

`array_map` is the one op that fails the WHOLE transform closed rather than degrading per-element,
because its output array's LENGTH must equal its input's — silently dropping a failed element would
itself corrupt the result. `array_filter`/`array_sort`/`array_reduce` all degrade PER ELEMENT (or, for
reduce, per fold step) instead, since a filtered-out/last-sorted/unfolded element cannot silently open
a downstream gate on garbage the way returning a wrong VALUE could.

**Caps — checked BEFORE a single element runs, shared identically by the write-validator and the
runtime executor** (`App\Modules\Variables\Enums\PipelineLimits` — split out of Workflows'
`ConditionTreeLimits` when the pipeline engine moved to the Variables module, see ADR-0027; the tree
caps `MAX_DEPTH`/`MAX_CHILDREN` stayed behind on `App\Modules\Workflows\Enums\ConditionTreeLimits`):

| Constant | Value | Effect when exceeded |
|---|---|---|
| `MAX_ARRAY_ITERATIONS` | 1000 | The op fails CLOSED outright — an array longer than this is never iterated. |
| `MAX_ELEMENT_PIPELINE_DEPTH` | 3 | A `map`/`filter`/`sort`/`reduce` whose OWN element pipeline contains another array op nested beyond this depth is REJECTED at write and fails CLOSED at runtime. |

Both mirror the existing pattern set by `MAX_PIPELINE_STEPS` (a single pipeline's own length) and
`MAX_ARG_VARIABLE_DEPTH` (ADR-0025's argument-variable nesting cap) — one shared constant read
identically by both sides, so the accepted bound can never drift between validation and evaluation.

**`array_at`'s clamp — 1-based, signed, clamped to the nearest end, total, never throws:**

| `index` | Result |
|---|---|
| `> 0`, within bounds | the element at that 1-based position |
| `> 0`, past the array's length | the LAST element (clamped) |
| `0` | the FIRST element (treated as `1`) |
| `< 0`, within bounds | counts from the end (`-1` = last, `-2` = second-last, …) |
| `< 0`, past the start | the FIRST element (clamped) |
| any index, on an EMPTY array | `null` — `array_at`'s output descriptor is always `nullable: true` |
| missing / non-numeric `index` | the op fails closed (the index is a required argument) |

**Known runtime-typing limitation (accepted, not a defect — see ADR-0026 Consequences).** The pure,
contextless `OperationExecutor` collapses EVERY array to a normalized `MULTI`/`string[]` at
run time and has no descriptor to recover a NON-ENUM scalar element base a `map` synthesized
MID-PIPELINE. A DIRECT `<multi source> |> array_at |> <op>` is correctly typed at runtime (the
element base comes straight from the source's own catalog descriptor), but `map |> array_at |>
text_op` — where `map` produced a plain `array<text>` — VALIDATES at write time (the descriptor
walker is correct) yet FAILS CLOSED at runtime (the executor's flat-type gate still sees `ENUM`, the
MULTI-collapse's only scalar-array convention). Recovering this needs the executor to carry
descriptor state end-to-end — a materially larger change, deliberately not attempted in this
revision; the global enum/text type gate is NOT relaxed to paper over it. Separately, `array_at` over
an `array<object>`/`array<file>` returns the raw element snapshot (usable via PATH/subfield access)
but a FURTHER operation chained onto it fails closed — object/file per-element TRANSFORMATION is the
job of the scope-rooted element pipelines above, not `array_at`.

---

## Steps

Steps run **in the order they are stored**, each acting only through an existing domain service
(`TaskService`, `FormReportService`) — never a raw model write that bypasses business logic. A
step's output is merged into `context.steps.<key>` so LATER steps can reference it via
`{{steps.<key>.*}}` / the directive/union serializations above.

**First failure stops the run.** Steps commit independently (no all-or-nothing transaction
around the whole run): a workflow whose first step created a task and whose second step failed
leaves the task in place, and the run timeline shows exactly where it stopped.

**Runtime capability per field (SB1/SB2 — see "Runtime operations, if-blocks, and AI text"
above).** All four text fields — `title` / `name` / `description` / `guidelines` — resolve
DIRECTIVE PIPELINES, `if-block`s, AND `@[ai-text]` identically (the resolver treats every text
field the same; which of these an author can actually insert is purely a frontend editor
decision — see the "Where each is enabled" note above and `docs/next/workflows-uxui-spec.md`
§4.6.3 SF3.6, which now offers the full toolbar on `title`/`name` too). `priority` /
`deadline` / `submissions_from` / `submissions_to` (the `{kind}` union fields) resolve an
OPTIONAL, WRITE-VALIDATED pipeline on their `variable` arm (type-flowed to the field's own
accepted terminal — see the Write-time validation subsection above).

### `create_task`

Creates a task through `TaskService::create()` (so label-attach and bot-dispatch side effects
fire as they would for a user-created task). Starts in `TO_DO`. Reaches ENTITY-FORM PARITY with
the create-task form (attachments excluded):

| Config field             | Required | Type            | Failure mode                                                             |
|----------------------------|----------|-------------------|--------------------------------------------------------------------------|
| `title`                      | **yes**  | resolved string     | **HARD** — blank after resolution fails the WHOLE step (`RuntimeException`; run stops here). Clamped to 255 chars (the `tasks.title` column width) AFTER resolution — see the note below. |
| `description`                 | no       | resolved string       | n/a — absent/blank → `null`.                                             |
| `priority`                     | no       | `{kind}` union, ENUM   | **SOFT** — unresolved/unknown → defaults to `medium`.                    |
| `deadline`                       | no       | `{kind}` union, DATE     | **SOFT** — unresolved/unparseable/blank → `null`.                        |
| `labels`                          | no       | literal array of uuids     | **SOFT** — a foreign/unknown label id is silently ignored by `TaskService`'s attach (never throws); a stray "ghost" label id from a deleted label is simply dropped. |
| `assignee_type` / `assignee_id`     | no, both-or-neither | literal `'user'\|'bot'` + uuid | a partial pair (only one present) is treated as no assignee.        |
| `form_id`                              | no       | literal uuid                | optional, no existence guard beyond the write-time `ScopedExists` check. |
| `approval_pipeline_id`                   | no       | literal uuid              | optional, same as above.                                                 |

**Output**: `{ task_id, title }`.

**Reviewer fix — the resolved title is clamped to the column width, not just checked non-empty.**
`title` is unbounded at write time (the raw config may contain a directive/pipeline/`@[ai-text]`
whose RESOLVED length is unknowable before it actually runs), so `CreateTaskStep` clamps the
resolved string to 255 chars (`mb_substr`, multibyte-safe) AFTER resolution, right before the
non-empty check — without this, a long composed value (a verbose AI-generated sentence, a wide
pipeline concatenation) could overflow the `tasks.title` column and fail the step with a raw SQL
error instead of a clean, expected outcome.

**Hard vs. soft failure table** (the exact behavior a stale/bad reference produces):

| Field         | Bad resolution → | Behavior      |
|----------------|--------------------|-----------------|
| `title`          | blank string          | **HARD FAIL** — whole step fails, run stops. |
| `priority`         | unresolved/unknown        | soft-default `medium`.                    |
| `deadline`           | unresolved/unparseable/blank | soft-default `null`.                   |
| `labels` (ghost ids)   | id no longer exists        | silently ignored/dropped, no error.       |

A bot assignee goes through `TaskService`'s normal `maybeDispatch()` — only an execution-capable
bot on a `TO_DO` task starts a bot run.

### `create_form_report`

Creates a Form REPORT through `FormReportService::create()` — the SAME path
`StoreFormReportRequest`'s controller uses. Creating the report fires its `created` model event,
which dispatches `App\Modules\Forms\Jobs\CreateFormReport` (`implements ShouldQueue`). The step is
**FIRE-AND-FORGET**: it returns as soon as the row is created and NEVER waits for the AI
analysis/report completion (matching the interactive manual create-report behavior).

| Config field           | Required | Type                      | Notes                                                                 |
|--------------------------|----------|------------------------------|----------------------------------------------------------------------|
| `form_id`                  | **yes**  | literal uuid                   | tenant-scoped (`Form::find`, `WorkspaceScope` applies). A missing/foreign/disabled form **HARD-FAILS** the step. |
| `name`                       | **yes**  | resolved string                  | **HARD** — blank after resolution fails the step. Clamped to 255 chars (the `form_reports.name` column width) after resolution, same reviewer fix as `create_task.title` above. |
| `guidelines`                   | no       | resolved string                    | absent/blank → `null`.                                                 |
| `sources`                        | no       | literal subset of `['task','form']`  | absent/empty → `[]` (no source filter — every source analysed).       |
| `submissions_from`                 | no       | `{kind}` union, DATE                   | **defaults from the STEP, not a request** — the form's `enabled_at` date, or today if `enabled_at` is null. |
| `submissions_to`                     | no       | `{kind}` union, DATE                     | defaults to today.                                                    |

**Output**: `{ report_id, report_name }`.

**Window default semantics — this lives IN the step, mirroring the request's own defaults but
computed independently at run time** (not copied from the trigger payload): `submissions_from`
defaults to `form.enabled_at` (as `Y-m-d`) when the config key is absent, or `now()` if
`enabled_at` is null; `submissions_to` defaults to `now()` (as `Y-m-d`) when absent. An
explicitly-supplied-but-unresolvable/blank value ALSO falls back to the same default (never
leaves the DTO's non-nullable window field empty).

`sources` here is `['task','form']` — the ANALYSIS-SOURCE vocabulary (which submissions count
toward the report), **NOT** the same vocabulary as the trigger's `source.in` (`['manual','task']`,
the SUBMITTABLE-morph vocabulary) — the two `task`/`form`-ish enums look similar but answer
different questions; do not conflate them.

**Creator attribution (corrected — see ADR-0015).** `FormReport` uses the polymorphic `HasCreator`.
Because this step runs INSIDE a live `WorkflowRunContext` (`WorkflowStepRunner` publishes the
executing run around the whole step loop), the report is attributed to the **run itself** —
`creator_type='workflow_run', creator_id=run->id` — for EVERY origin (event, schedule, AND manual),
whether or not an HTTP user happens to be authenticated in that process. `creator_id` is never
`null` for a step-created report. The task created by `CreateTaskStep` is attributed the same way.
This SUPERSEDES the previous text on this page, which described `FormReport` inheriting
`auth()->id()` (null for a schedule run) — that was the actual crash `HasCreator`'s polymorphic
stamping was built to fix (a `NOT NULL creator_id` column, `auth()->id()` always null inside a
queued job). See `docs/backend/creator-attribution.md` for the full stamping-precedence contract.

### `generate_content` (R2 sub-stage 5)

Runs a Generator **Template** (`docs/backend/generator-api.md`) and publishes the produced content —
the ONLY step type in this module that **suspends**: it starts a real, budgeted generation on the
Generator's own queue and does not resolve `context.steps.<key>` until much later, when a fresh job
resumes the run. See "Suspend/resume engine" below for the mechanism; this section covers the step's
own config/output/error contract. Full design record: **ADR-0039** (amended 2026-07-31 — see its
Addendum for the `bot_id` author-delegation contract).

`App\Modules\Workflows\Steps\GenerateContentStep implements SuspendableWorkflowStep`. The
`Workflows → Generator` edge is crossed in exactly this one class, and strictly one-way — it calls
only the Generator's HTTP-free automation seams (`SessionAutomationService`, `SessionDelegationService`
under `SlotScopePolicy::Automation`, `GenerationSessionRunManager::claimAndDispatch`,
`SessionContentProjector`, `GeneratedImageExporter`) and the Generator names no Workflows class
anywhere (pinned by `GeneratorModuleBoundaryTest` + `WorkflowsGeneratorBoundaryTest`).

| Config field   | Required | Type                                    | Notes |
|-----------------|----------|--------------------------------------------|----------------------------------------------------------------------|
| `template_id`      | **yes**  | literal uuid                                  | Workspace-scoped `Template`. Missing/foreign/unknown → `422` on `template_id`. |
| `slots`               | no       | map of the template's DECLARED slot name → a literal or a `{kind}` value-or-variable union, typed at the SLOT's own type | Only declared slot names are accepted (an unknown name → `422`); a value's pipeline is type-flowed to the slot's own type. |
| `folder_id`               | no       | literal uuid (workspace-scoped Disk `Folder`) | Where the produced images are exported. `null`/omitted = the Disk root. A folder deleted mid-wait DEGRADES to the Disk root at resume time (see "Operational notes" below) rather than losing the already-paid-for content. |
| `name`                        | no       | literal string                                | The session's display name. Defaults to the template's own name when omitted/blank. |
| `bot_id`                         | no       | literal uuid (workspace-scoped `Bot`)         | Delegates the created session to this bot — the SAME author overlay (voice + frozen look) an interactive delegation stamps. `null`/omitted/blank = no author (generates in the house voice). Unresolvable → `422` at save (`bot_invalid`) and a hard run-time refusal (`bot_unavailable`) if one slips through anyway. See "Author delegation" below. |

**Config allow-list is exactly these five keys** (`StoreWorkflowRequest::allowedStepKeys()`) — `slots`
is the one FREE-FORM map (its keys are the chosen template's own slot names, not a fixed vocabulary),
so it is allowed wholesale at the top level and checked against the template's own declarations
(`validateTemplateSlotMapping()`) instead.

**Outputs** (flow automatically into the variable catalog via the step class's static
`outputDescriptors()`, exactly like `create_task`/`create_form_report`):

| Output              | Type     | Notes |
|-----------------------|----------|-----------------------------------------------------------------------------|
| `session_id`             | TEXT     | The generation session's uuid — provenance, and what the FE deep-links into the Generator with. |
| `content`                    | TEXT     | The ASSEMBLED text of the finished piece (`Generator\Services\SessionContentProjector` — the server-side sibling of the FE's `FinalPostBody.vue` composition, not a second implementation). `''` for an image-only recipe, or when the session produced nothing. |
| `image_file_ids`                | FILE     | The ids of the Disk files this step exported — a following `create_task.attachments` can reference these straight through. |
| `status`                            | TEXT     | The session's settled status — **effectively always `ready`** in practice: a `failed` session HARD-FAILS the step instead (the run stops), so this value is published only on the ready path. Emitted verbatim rather than hard-coded so the descriptor stays honest if a softer settlement is ever introduced. |
| `has_failed_parts`                     | BOOLEAN  | `true` when the run finished `ready` but some part (or a per-shot/per-scene image) failed — the Generator's own per-part fail-soft. Lets a later step gate a publish on completeness. |

**Author-time `422`s** (`StoreWorkflowRequest::validateGenerateContentConfig()` /
`validateTemplateSlotMapping()` / `validateGenerateContentBudget()`), all granular so the FE step editor
can highlight the exact row:

| Code | Field | Meaning |
|------|--------------------------------------|----------------------------------------------------------------------------|
| 422  | `steps.<i>.config.template_id`          | Missing, not a uuid, or not a template in this workspace. |
| 422  | `steps.<i>.config.folder_id`                | Not a uuid, or not a Disk folder in this workspace. |
| 422  | `steps.<i>.config.bot_id`                       | Not a uuid, or unknown to the SAME `SessionAuthorIdentityResolver` seam the run itself uses — via its cheap existence probe `knowsAuthor()` (`validateAuthorId()`), never the full composition, so a storage/composition fault can no longer masquerade as "bot not available" (the save succeeds; a DB fault inside the probe itself is an honest 500). Deliberately not a `ScopedExists` rule, because Workflows may not name the Bot module directly. Message key `bot_invalid`. |
| 422  | `steps.<i>.config.name`                         | Present but not a string. |
| 422  | `steps.<i>.config.slots.<name>`                    | A REQUIRED slot left unmapped, OR mapped to `null`/`''` (`descriptor.nullable !== true` — a descriptor has no separate `required` key). |
| 422  | `steps.<i>.config.slots.<name>`                       | A mapped name the template does NOT declare (almost always a typo — reported alongside the unmapped-required error the SAME typo usually also causes). |
| 422  | `steps.<i>.config.slots.<name>`                          | A mapped value's `{kind:'variable'}` pipeline does not type-flow to the slot's own accepted terminal(s) — its own type, or, for an ARRAYED slot, its own type OR the plain element type (see "Arrayed-slot pipeline terminals" below). |
| 422  | `steps.<i>.config.slots.<name>`                             | **The composite-slot refusal** — a REQUIRED `object`-base slot (any shape), or a REQUIRED `array:true` + base `file` slot, is refused outright (the template "cannot be driven by a workflow at all"); the SAME shapes are refused when a NULLABLE slot is explicitly mapped (leaving it unmapped to generate empty is fine). A SCALAR `file` slot is **not** refused — see "The composite-slot refusal" below. |
| 422  | `steps.<i>.type`                                               | A 3rd (or later) `generate_content` step in the same workflow — the cap is **2** (`validateGenerateContentBudget()`). |

**Author delegation (`bot_id`).** When the step names a bot, the created session is delegated to it
EXACTLY as an interactive delegation would — the SAME `SessionDelegationService::applyDelegation()`
overlay `BotSessionDelegationController` stamps, composed by the SAME `BotDelegationIdentityComposer`
(`docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`, `docs/decisions/
ADR-0042-character-visual-identity.md`): a VOICE, and — when the bot's visual module is on and it has an
approved likeness — a frozen LOOK (the character's reference bytes, copied once). The workflow's own
`slots` mapping stays the ONLY source of the session's inputs: unlike the interactive delegation's
optional `fill_mode`/`auto_generate` autonomous fill, the bot never fills a `generate_content` session's
slots itself. Bot STATUS is not a filter — a paused/inactive bot is still a legal author, since naming
one is authored configuration, not an execution capability. See "Run-time flow" below for exactly when
the identity is resolved and stamped, and **ADR-0039**'s 2026-07-31 addendum for the full design record
(the write-time seam, the refusal-before-creation invariant, and why a resume never re-delegates).

**The composite-slot refusal, and the scalar-`file` divergence from the Bot module's blanket
refusal.** `StoreWorkflowRequest::isUnsuppliableSlot()` refuses two shapes at AUTHORING time, before any
run could ever discover the problem itself — `GenerateContentStep` itself performs NO author-time
validation of its own; the write-side gate is entirely on the request:

- an `object`-base slot, ANY shape — the shared resolver has no object COERCION, so an object literal
  (bare or `{kind:'literal'}`-wrapped) the author writes into `slots.<name>` would resolve to `null` and
  the value could never reach the session;
- an `array:true` + base `file` slot (a list of files) — a deferred composite
  `Generator\Enums\SlotScopePolicy::accepts()` refuses outright regardless of caller, so the fill would
  drop it as out-of-scope.

Before this authoring-time gate existed both failure modes were silent until a real run: a REQUIRED
composite slot saved cleanly and then HARD-FAILED EVERY SINGLE RUN (leaving an orphan `draft` session
behind each time — see "Operational notes"), and a NULLABLE one silently generated with an empty slot,
quietly discarding the author's mapping. A **scalar** `file` slot (not a list) is deliberately **not**
refused — `Generator\Enums\SlotScopePolicy::Automation` allows it (D4/D14 of ADR-0036/ADR-0039): the
value is resolved through the value-or-variable union to a single file id, then re-validated through the
tenant-scoped `Disk\Models\File` model before it is persisted, so a foreign/deleted/made-up id simply
does not resolve and is dropped like any other invalid value. This is a deliberate divergence from the
Bot module's own delegation (`SlotScopePolicy::Bot`, ADR-0036), which refuses EVERY file slot
unconditionally — there a model is proposing values it was never given and could fabricate a Disk
reference, whereas here a trusted workspace human authored the mapping and the tenant scope is the
actual boundary. See ADR-0039 D14 for the full reasoning.

**Arrayed-slot pipeline terminals.** `StoreWorkflowRequest::slotPipelineTerminals()` computes the
type(s) a mapped `{kind:'variable'}` pipeline may end in for a given slot. A SCALAR slot accepts exactly
its own type. An ARRAYED slot (`{base:<scalar|enum>, array:true}`, which maps to `VariableType::MULTI`)
accepts EITHER its own `MULTI` type OR its plain ELEMENT type — e.g. an `array<text>` slot accepts a
pipeline ending in `MULTI` or one ending in plain `TEXT`. Demanding `MULTI` alone was an unreachable
dead end: no pipeline operation produces a `multi` from a scalar source (only `array_map`/`array_filter`/
`array_sort` do, and those need an array INPUT), so a text variable mapped onto an `array<text>` slot
with any non-identity pipeline was an unavoidable `422` with no way to satisfy it. The runtime already
tolerates this: `VariableResolver::coerce()`'s `MULTI => is_array($value) ? array_values($value) : [$value]`
wraps a resolved scalar into a one-element list, so admitting the element terminal at write time only
accepts what the resolver could already deliver at run time — no runtime behavior changed, only what the
author-time validator accepts. `object`/`array<file>` descriptors never reach this check — the
composite-slot refusal above rejects them first.

**Run-time flow.**

1. `run()` resolves `template_id` through the tenant-scoped `Template` model (a workspace boundary
   check the automation seam itself does not perform); a missing/foreign/deleted id hard-fails the step
   (`RuntimeException`, run stops).
2. The optional AUTHOR (`bot_id`) is resolved through the Generator's `SessionAuthorIdentityResolver`
   contract — **BEFORE the session exists**. Absent/`null`/blank means no author (generates anonymously,
   unchanged). A NAMED but unresolvable bot (deleted, foreign-workspace, or a malformed id that slipped
   past the write-time check) HARD-FAILS the step with `bot_unavailable` — and because this happens
   before `createFromTemplate` runs, the refusal leaves no draft session behind at all (contrast step 5
   below).
3. Each declared slot the config maps is resolved through the SAME value-or-variable union
   `create_task`'s fields use, at the slot's OWN type (recovered from its stored descriptor).
4. An EMPTY session is created (`SessionAutomationService::createFromTemplate`, snapshot-authoritative
   per ADR-0034 D1). When step 2 resolved an author, the SAME `SessionDelegationService::applyDelegation()`
   overlay an interactive delegation stamps is applied NOW, before anything fills the session — then the
   session is FILLED under `SlotScopePolicy::Automation`
   (`SessionDelegationService::applySlotValues`), which re-validates every value against its descriptor
   and reports what it filled/skipped/left unfilled.
5. A required slot the fill report still lists as unfilled HARD-FAILS the step BEFORE any spend,
   naming the offending slot(s) — see "Operational notes" for what happens to the session it already
   created.
6. The session is claimed and dispatched on the REAL queue connection
   (`GenerationSessionRunManager::claimAndDispatch`, through `RealQueueConnection`) — the SAME
   budget-gated, atomically-claimed choke point a manual "Generuj" click or a bot `auto_generate` uses,
   so the pre-run `429 ai_budget_exceeded` gate (`docs/backend/workspace-ai-usage-api.md`) applies
   unchanged; an over-cap workspace is refused BEFORE the session is claimed and nothing is billed.
7. The step throws `StepSuspended` and the run parks `waiting`.
8. On resume, `terminalStatusFor(sessionId)`: `null` (session gone/purged) and `failed` are TERMINAL
   failures (a vanished or failed session can never settle, so the step fails the run with the real
   cause rather than waiting out the timeout and reporting a misleading one); `generating`/`draft`
   RE-SUSPEND on the SAME correlation key (idempotent — the next settle event or sweep resumes it);
   only `ready` collects: `SessionContentProjector::project()` assembles `content`, and
   `GeneratedImageExporter::saveToDiskIfPresent()` exports EVERY produced image to Disk — done in the
   RESUME phase, inside the run, so `HasCreator` stamps every exported file `uploader_type='workflow_run'`
   (exporting inside the generation worker instead would run with no run context and usually no
   authenticated user, leaving a NULL uploader — see ADR-0039 D7). **`bot_id` in the replayed config is
   NEVER re-read here** — the author was resolved and stamped once, at steps 2/4; a resume collects, it
   does not re-author (pinned by `WorkflowGenerateContentBotTest`).

**The run lifecycle, author-visible.** A workflow with a `generate_content` step visits
`running → waiting → running → completed | failed` — the middle `waiting` hop being the whole point of
this feature. See "Suspend/resume engine" below for the columns/mechanics, and
`docs/next/…`/`resources/js/next/docs/pages/WorkflowsPage.vue` for how the Runs UI surfaces it.

**Operational notes.**

- **A `folder_id` deleted while the run waits DEGRADES to the Disk root**, with a logged warning
  (ids only, never content) — the content is already paid for by the time resume runs, so losing it to a
  404 on a now-missing folder would be strictly worse than filing it at the root instead.
- **A required-slot failure leaves an ORPHAN `draft` session behind.** The step deliberately does NOT
  delete the session it just created before hard-failing (step 5 above) — it shows the workflow author
  exactly what the automation managed to fill, for debugging. The Generator's own idle/stale-draft
  lifecycle reaper (`generator:reap-sessions`, `docs/backend/generator-sessions-api.md`) eventually
  cleans it up like any other abandoned draft. Contrast an unresolvable AUTHOR (step 2 above), which
  fails BEFORE any session exists and so leaves nothing behind at all.
- **Attribution.** The session is created INSIDE the run, so `HasCreator` stamps it
  `creator_type='workflow_run', creator_id=<run id>` — the executor then reads that back as the explicit
  METER ACTOR, so every AI event the generation makes carries `actor_type='workflow_run'`,
  `actor_id=<run id>`, and the session id — WITHOUT needing `WorkflowRunContext` to survive into the
  generation worker (it does not; the worker is a different job on the real queue). **When the step
  named an author, this is OVERRIDDEN**: `GenerationSession::meterActor()` reports the bot instead
  (`['bot', bot_author_id]`) whenever the session is delegated, so a delegated automated generation's
  spend attributes to the bot — exactly like an interactively-delegated session, with no new attribution
  code (the pre-existing R2 sub-stage 4 mechanism already reads whichever overlay is stamped).

---

### `create_event` (R3 B4)

Puts a **calendar event** on the workspace's grid, through the Calendar module's own DTO and
service (`CalendarEventDTO` / `CalendarEventService`) — never a raw model write, exactly like
every other step. Full read/write contract for calendar events themselves (the all-day/instant
discriminator, the resource shapes, the write endpoints a human uses for the same table) lives in
[`docs/backend/calendar-api.md`](calendar-api.md); this section covers only the step's own
config/output/error contract. Design record: **ADR-0051**.

`App\Modules\Workflows\Steps\CreateEventStep`. The `Workflows → Calendar` edge is crossed in
exactly this one class and is one-way — the Calendar names nothing under `app/modules/Workflows`
(`CalendarModuleBoundaryTest`). An event created this way is attributed to the **executing run**,
never to whoever triggered it — nothing in the step sets a creator; `HasCreator` stamps
`creator_type='workflow_run'` because `WorkflowStepRunner` publishes the run to
`WorkflowRunContext` around the whole step loop (the same mechanism `create_task`/
`create_form_report` rely on, ADR-0015).

| Config field   | Required                                     | Type                                | Failure mode |
|-----------------|-----------------------------------------------|----------------------------------------|--------------|
| `title`             | **yes**                                          | resolved string                          | **HARD** — blank after resolution fails the whole step. Clamped to 255 chars (the `calendar_events.title` column width) after resolution, the same reviewer fix `create_task.title` carries. |
| `description`           | no                                                 | resolved string                            | absent/blank → `null`. |
| `all_day`                  | **yes**, and **LITERAL ONLY** — never a variable      | boolean                                       | Missing/not-a-boolean → **422 at authoring time** (`steps.<i>.config.all_day`), never a run-time failure. |
| `start_date`                   | required iff `all_day=true`, forbidden otherwise         | literal \| variable union, DATE                  | **HARD** when required and unresolvable — an event with no position in time has no square to draw it on, so the run stops rather than writing an unselectable row. |
| `starts_at`                        | required iff `all_day=false`, forbidden otherwise            | literal \| variable union, DATE                     | **HARD**, same reasoning as `start_date`. |
| `ends_at`                              | no, only meaningful when `all_day=false`                         | literal \| variable union, DATE                        | **SOFT** — unresolvable, unparseable, or earlier than `starts_at` (which a run-time variable can perfectly well produce) all yield `null`. An event with no stated end is ordinary; dropping a nonsensical end keeps the event and loses only the part that made no sense. |

**There is no `color` field.** An event has no colour of its own to author — see
`docs/backend/calendar-api.md` → "The `event` source's colour is a constant" — so the grid
gives every event the same server-assigned constant regardless of what a run's definition
says. A stored definition still carrying the key is refused by the allow-list (see the 422
table below), the same place every other foreign key on this step type is refused.

**Output**: `{ event_id, title }`.

**Why `all_day` is literal-only, unlike every value-or-variable field beside it.** It is the
discriminator deciding which OTHER fields are required — a run-time value would make the write
validator unable to demand the right fields for the branch the run will actually take, and a
definition could pass authoring-time validation and still reach a run with no date in it at all.
A literal keeps the mistake catchable at the one moment an author is still looking at it.

**Why a missing date is a HARD failure, unlike `create_task.deadline` (optional, soft-defaults to
`null`).** A task with no deadline is a perfectly ordinary task. An event with no position in time
is not an event — there is no square on the grid to put it on — so writing the row anyway would
leave a `calendar_events` entry the read path can never select and nobody will ever see, which is
worse than a run that stops and says why.

**Author-time `422`s** (`StoreWorkflowRequest::validateCreateEventConfig()` — the SAME
both-ways discriminator enforcement `StoreCalendarEventRequest` applies to a hand-made event,
applied here to a *definition*):

| Code | Field                                | Meaning |
|------|-----------------------------------------|---------|
| 422  | `steps.<i>.config.title`                    | Missing or blank. |
| 422  | `steps.<i>.config.all_day`                      | Missing, or not a literal boolean (e.g. a `{kind:'variable'}` union). |
| 422  | `steps.<i>.config.start_date`                       | Present while `all_day=false` (a timed event has no separate day — remove it or turn on `all_day`), OR absent while `all_day=true`. |
| 422  | `steps.<i>.config.starts_at` / `.ends_at`               | Present while `all_day=true` (an all-day event has no time of day), OR `starts_at` absent while `all_day=false`. |
| 422  | `steps.<i>.config.<foreign key>`                                | Anything outside `{title, description, all_day, start_date, starts_at, ends_at}` — notably `color` (an event has no colour of its own to set — see the field table above) and `subject_type`/`subject_id`: the pointer is deliberately **not** part of this step's vocabulary, since a run-created event is a standalone annotation, not a reference back into the run. |

**Run-time flow.** `title` and `description` resolve through the same directive/pipeline resolver
`create_task` uses. Each date field resolves through the shared value-or-variable union at type
`DATE`; the resolver coerces even a bare-day literal to a full ISO instant, so the all-day branch
**re-prints the day the resolved value itself names** (`Carbon::parse($resolved)->format('Y-m-d')`)
rather than converting anything — a bare `2026-02-01` round-trips to `2026-02-01` with no timezone
involved, because an all-day event has no zone to convert *into* in the first place. This is the
one place a regression here could quietly write an instant into an all-day row, which is why it is
pinned by `CalendarEventWorkflowStepTest::test_a_run_creates_an_all_day_event_without_acquiring_a_time`
rather than trusted to work by inspection alone.

**Whose clock the TIMED branch (`starts_at`/`ends_at`) reads.** The same rule
`POST`/`PUT /api/calendar/events` uses — see `docs/backend/calendar-api.md` → "Whose midnight —
the write side" — applies here: a value with **no** zone is read on the **workspace's** clock, an
**explicit** offset (`+02:00`, `Z`, a full identifier) always wins. Both doors resolve through the
identical class, `App\Modules\Calendar\Services\CalendarInstantResolver`, so a Warsaw workspace
automating "14:00" and a person typing "14:00" into the event drawer land on the same instant. The
step's config editor sends a full moment for this branch — `yyyy-mm-ddTHH:mm`, not a bare day — so
the literal an author types already carries an hour, matching what the resolver expects.

**The rule reads the AUTHOR'S OWN TEXT for a literal field — never what the variable resolver
hands back — and that split is a real, accepted limitation, not an oversight to be
rediscovered by tracing an offset bug:**

- A **literal** `starts_at`/`ends_at` (the author typed a date into the step editor) is read
  through `CalendarInstantResolver` exactly as written, so a zone-less literal is interpreted in
  the **workspace's** timezone — agreeing with the API and the grid.
- A **variable**-sourced value has no such text to read. By the time it reaches this step, the
  Variables module's own `DATE` coercion has already run it through a bare
  `Carbon::parse($v)->toIso8601String()`, which stamps a zone-less value with
  `config('app.timezone')` (`'UTC'`) — so the value this step sees already looks like a caller who
  said `Z`, and taking it as given (the same "an explicit zone wins" rule, applied to what
  actually arrived) reads it as **UTC**, not the workspace's timezone.

Fixing this would mean changing the Variables module's `DATE` coercion, which also feeds
`create_task.deadline` and `create_form_report`'s submission-window fields — out of scope for this
step alone, and deliberately left visible here rather than silently patched around: a workflow
whose `starts_at` is a variable reference (a trigger timestamp, another step's output) lands in
UTC even on a non-UTC workspace, while the identical text typed as a literal lands correctly.

---

## Suspend/resume engine (R2 sub-stage 5)

The generic mechanism `generate_content` (above) is the first — and, today, only — consumer of. A step
that has handed work to something outside this process parks its run in `waiting` instead of publishing
an output; a later, FRESH job resumes the run from exactly where it left off. Full design record:
**ADR-0039-workflow-suspend-resume-and-generate-content.md**.

### The signal — `StepSuspended`, a throw, not a sentinel return

`App\Modules\Workflows\Exceptions\StepSuspended($kind, $correlationKey, $payload)`. A step's `run()`
throws it instead of returning; `App\Modules\Workflows\Steps\SuspendableWorkflowStep` (which
`GenerateContentStep` implements) additionally declares `resume($config, $run, $context, $wait): array`,
called at the EXACT position the wait targets once it is time to collect the outcome. A plain
`WorkflowStep` knows nothing about suspension — the base contract is untouched.

A sentinel return value was rejected: a step's return is merged VERBATIM into `context.steps.<key>`, so
a magic marker key would leak into the user-visible variable catalog and could collide with a real
output name. A throw reuses the runner's EXISTING "a step that cannot proceed must throw" contract,
symmetrically, as the non-terminal sibling of an ordinary step failure. `WorkflowStepRunner`'s
`catch (StepSuspended)` is ordered BEFORE its `catch (Throwable)` — PHP takes the first matching catch,
so the ordering is load-bearing: reversed, every suspension would record as a step failure.

### The three new `workflow_runs` columns

Additive, nullable, central + tenant mirrors — `database/migrations/2026_08_04_000000_add_waiting_columns_to_workflow_runs_table.php`
/ `database/migrations/tenant/0001_01_01_000055_add_waiting_columns_to_workflow_runs_table.php`. A
workflow WITHOUT a suspending step never writes them; they stay `NULL` for the whole run — the
byte-identical-behavior guarantee.

| Column | Type | Meaning |
|---|---|---|
| `waiting_on` | json | `{kind, step_key, step_type, position, payload, config, definition_hash, ai_text_calls}` — see below. |
| `waiting_key` | string, INDEXED | An opaque correlation key (`<kind>:<uuid>` — for `generate_content`, `generation_session:<session uuid>`). What a settle listener/the sweep/a resume job's atomic claim look a run up by. |
| `waiting_since` | timestamp | When the wait started — RE-STAMPED on every park, including a re-park of the SAME step onto the SAME leg. `workflows.wait_timeout` therefore bounds time since the LAST park, not the run's total wait time (see "Timeout ordering" below for why this cannot loop). |

`waiting_on.config` is the step's config **as already resolved at the moment it suspended** — replayed
verbatim on resume, never re-resolved a second time. This is what makes a spend-incurring directive
(`@[ai-text]`) in a suspendable step's config pay exactly once, and it is what makes `folder_id` on
resume the same folder the run started with even if the step's config was edited (definition drift is
its own hard failure — see below) or the workflow's constants changed while it waited.
`waiting_on.definition_hash` is the whole-definition fingerprint (see "Definition drift" below).
`waiting_on.ai_text_calls` re-seeds the run's per-run `@[ai-text]` budget on resume, since a fresh job
means a fresh `WorkflowAiTextService` instance.

**PRIVACY.** `waiting_on` carries the RESOLVED config, which may hold form-submitted personal data (a
`{{trigger.fields.*}}` reference resolves to whatever the submitter typed) and AI-generated content. It
is **never** serialized into `WorkflowRunResource`, never logged. Keep it that way when touching this
code.

### The resume — a fresh job, never a serialized continuation, atomically and CORRELATED-claimed

`WorkflowRunResumeJob(runId, workspaceId, waitingKey)` — three scalars, the SAME idiom
`BotTaskRunManager`/`BotTaskExecutionJob` already use for the Bot module's own claim/resume cycle. It
never carries the model or any resolved state; `handle()` re-reads the `WorkflowRun` row, re-establishes
tenancy explicitly, and calls `WorkflowStepRunner::resume($run)`, which rebuilds `context` FROM THE
DATABASE: `context.steps` from what the suspension persisted, `globals`/custom functions/the runtime
type map RE-READ LIVE (a constant edited while the run waited affects steps that run AFTER the resume,
exactly as it would for any run started after the edit — the deliberate "fresh job, not a continuation"
semantic).

`WorkflowRunManager::claimResume($run, $waitingKey)` mirrors `claim()` exactly — a guarded
`UPDATE workflow_runs SET state='running', started_at=now() WHERE id=? AND state='waiting' [AND
waiting_key=?]` — but the CORRELATED predicate matters specifically because a resumed step may
`StepSuspended` AGAIN (a multi-leg wait; `generate_content`'s own `resume()` does exactly this while the
session is still `generating`/`draft`). Two independent triggers can learn a wait settled (below), and
either can be redelivered at-least-once — without the key predicate, a stale/duplicate job for one LEG
could win the claim and resume a DIFFERENT leg the step has since re-suspended onto. A lost claim
(already resumed, re-suspended, reaped, or the run released) is a clean, silent no-op — never a second
execution of the suspended step.

### Definition drift — a whole-definition fingerprint, not just a per-position check

The workflow may have been edited (or deleted) while the run waited. `WorkflowStepRunner::definitionHash()`
SHA1-hashes the whole ordered step definition at BOTH suspend and resume time;
`hasDefinitionDrift()` checks it FIRST. The per-position checks alone (same key, same type, still a
`SuspendableWorkflowStep`) only see the ONE position the wait targets — an edit ANYWHERE else in the
definition (a step appended after the suspended one, a LATER step's config rewritten, or even the
suspended step's own config edited without touching its key/type) used to pass silently and could alter
a run already in flight. Either check failing releases the run `failed` with `workflows.runs.definition_changed`
rather than silently skipping or corrupting work. A wait persisted before this fingerprint existed
carries no hash and falls back to the old per-position-only guard.

### Two triggers, one correctness guarantee

**The settle LISTENER (`ResumeWaitingRunOnSessionTerminal`) is a latency optimization.** It listens to
the Generator's own `GenerationSessionUpdated` event (the SAME broadcast the chat's own settle push
already uses, ADR-0034 D10) and dispatches a correlated `WorkflowRunResumeJob` the moment a session it
is watching turns `ready`/`failed`. Workflows reacts to a Generator-owned signal — the Generator needs
zero knowledge Workflows exists. It never throws (wrapped + reported; a failure here must not fail the
GENERATION job it runs inside).

**The waiting-run SWEEP (`WorkflowRunManager::reapWaitingRuns()`, driven by
`workflows:reap-stale-runs`, already scheduled `everyFiveMinutes()`, per-tenant like its stale-RUNNING
sibling) is the CORRECTNESS guarantee, and must stay one.** `GenerationSessionRunManager::broadcastTerminal()`
deliberately SKIPS the broadcast when no workspace is active — precisely the case when the Generator's
OWN lifecycle reaper (`generator:reap-sessions`, sweeping with tenant context cleared) settles a
stranded session to `failed`. An event-only design would therefore strand exactly the runs that most
need recovering. The sweep asks through the kind-keyed `WaitResolverRegistry` (Workflows' own contract —
a feature module registers its `WaitResolver` from its own provider, mirroring the
`Variables\Contracts\AiTextGenerator` inversion) → `GenerationSessionWaitResolver` (lives in Workflows,
not Generator, because the FEATURE here is the STEP, a Workflows class, and the Generator must never
name Workflows) → `SessionAutomationService::terminalStatusFor()` (a plain status string, no Generator
model reached) — a direct DB read, independent of any broadcast ever having fired.

The sweep queries the `waiting` rows in TWO partitions (fresh / stale, split on `waiting_since` vs. the
cutoff), each `chunkById(SWEEP_CHUNK)` — `WorkflowRunManager::SWEEP_CHUNK = 20` — so its memory stays
bounded no matter how many runs are parked, and so a row released mid-sweep cannot shift a page and skip
a run.

Per parked run, in order: SETTLED → dispatch the correlated resume job (even a long-overdue wait is
RESUMED, never failed, once it settled); GONE → fail the run now; past `workflows.wait_timeout` → fail
as timed out; otherwise leave it waiting. An unregistered kind or a throwing resolver downgrades to
PENDING, never GONE — a transient/deploy fault can never wrongly time out a healthy wait.

### `wait_timeout` and the timeout ordering invariant

`config('workflows.wait_timeout')` (env `WORKFLOWS_WAIT_TIMEOUT`, default **2700s**) is the LAST-RESORT
release for a run parked `waiting` — the normal way a wait ends is the resolver reporting SETTLED, not
this timeout. Each window in the chain below must be strictly wider than the one it backstops, so the
most informative recovery always gets the first chance to turn an abandoned piece of work into a real,
SETTLED outcome instead of a generic timeout:

```
RunGenerationSessionJob timeout      300s   (the generation's own SIGALRM window)
  < WithoutOverlapping lock expiry   600s   (releaseAfter 30s / expireAfter 600s)
  < workflows.run_timeout            900s   (stale-RUNNING reaper — NEVER matches `waiting`)
  < generator.session_stale_after   1800s   (the Generator's own stale-session reaper)
  < workflows.wait_timeout          2700s   (stale-WAITING reaper — last resort)
```

`WorkflowRunJob::$timeout` is now explicitly declared (**720s**) rather than silently inheriting the
worker's `--timeout` (Laravel's 60s default) — a pre-existing gap (SB2's `@[ai-text]` could already need
up to 600s worst-case) fixed alongside this feature because `WorkflowRunResumeJob` needs the identical
budget for its own SIGALRM window, the two jobs now being conceptually one pass split across a park.

---

## Worked create-payload examples (verified wire shapes)

These payloads are the EXACT wire the frontend's `WorkflowEditorDrawer` POSTs, pinned by
`resources/js/next/pages/workflows/__tests__/WorkflowEditorDrawer.spec.ts` (`SAVE payload —
form_submitted wire` / `SAVE payload — schedule wire`).

### `form_submitted`

```json
{
  "name": "My workflow",
  "description": null,
  "icon": null,
  "trigger_type": "form_submitted",
  "trigger_config": { "form_id": "form-a", "source": { "in": ["manual"] }, "anonymous": true },
  "conditions": [
    { "field": "fields.status", "field_type": "enum", "operator": "is", "value": "a" }
  ],
  "steps": [
    { "type": "create_task", "key": "task", "config": { "title": "Do it" } }
  ]
}
```

### `schedule`

```json
{
  "name": "My workflow",
  "description": null,
  "icon": null,
  "trigger_type": "schedule",
  "trigger_config": { "schedule": { "time": { "mode": "at", "at": ["09:00"] } } },
  "steps": [
    { "type": "create_task", "key": "task", "config": { "title": "Do it" } }
  ]
}
```

Note the **absence of the `conditions` key entirely** on a schedule payload (not an empty array
— the key is omitted) — conditions are structurally only meaningful for `form_submitted`.

---

## Manual runs — target contract summary

| Trigger type       | `target_id` resolves to…  | Required? | 422 bag on failure                    |
|-----------------------|------------------------------|-----------|------------------------------------------|
| `form_submitted`        | a `FormSubmission`             | yes         | `target_id` (unresolvable) or `workflow` (cap) |
| `schedule`                 | none (omit `target_id`)          | no           | `workflow` (cap) only                      |

The 422 error bag is **EXACTLY** `target_id` + `workflow` — no third key. (Etap-5's
`approval_process` key does not exist in the 5.1 contract; the `approval_finished` trigger it
belonged to was removed.)

---

## Trigger dispatch pipeline

Every real domain trigger and the manual-run endpoint funnel through **one** seam,
`WorkflowDispatchService`. The pipeline runs cheapest gates first:

```
1. TRIGGER-TYPE MATCH   Workflow::query()->where(status=active, trigger_type=$type)
                         (tenant scope automatic; schedule workflows structurally excluded —
                          dispatch() hard-refuses WorkflowTriggerType::SCHEDULE up front)
2. TARGETING            the inline form_submitted match (form_id / source / anonymous) — cheap
                         in-memory check on the payload snapshot, no extra queries
3. CONDITIONS           WorkflowConditionEvaluator — the typed {field, field_type, operator,
                         value} AND-gate
4. LOOP / COST GATES     depth (re-trigger chain) → per-workflow monthly cap → workspace hard cap
                         An event that trips ANY of these is skipped SILENTLY (Log::info only).
5. WorkflowRunManager::start()   creates the `pending` run row and defers the job dispatch
```

The manual-run endpoint (`WorkflowManualRunService` + `dispatchManual()`) BYPASSES steps 1–3
(it targets ONE explicit workflow, resolved id, no targeting/conditions gate — a manual run is
a deliberate, explicit execution) but still enforces the cap gate (step 4), as a **422** instead
of a silent skip.

### Observer / service seam hook points

Each domain write defers its trigger dispatch to `DB::afterCommit()` — ONE `afterCommit` layer
per authoring write, so the dispatch only runs after the write that produced the payload has
actually committed:

| Domain event                          | Hook                                                       |
|------------------------------------------|----------------------------------------------------------------|
| Form submission approved                  | `App\Modules\Forms\Observers\FormSubmissionObserver::saved()` (fires on `wasChanged('approved_at')` OR a freshly-created row with `approved_at` already set) |

The Etap-5 task-created / task-status-changed / approval-concluded hooks (`TaskObserver`,
`ApprovalService::dispatchWorkflowTrigger()`) were REMOVED along with their trigger types. Any
reference to them is stale.

### `DB::afterCommit` layering (single layer, not double-deferred)

The hook above wraps its `dispatch()` call in `DB::afterCommit`, so `dispatch()` runs only after
the authoring transaction commits. `WorkflowRunManager::start()` ALSO wraps its job dispatch in
`DB::afterCommit` — but by the time `dispatch()` executes (already post-commit) there is no open
transaction, so that inner `afterCommit` fires **immediately** (Laravel runs a callback
synchronously when no transaction is active). The run ROW is created synchronously inside
`dispatch()`; only the JOB dispatch is (harmlessly) re-wrapped. There is no double deferral and
no risk of the job running before the run row exists.

### Origin (`WorkflowRunOrigin`)

| Value       | Set by…                                                              |
|---------------|----------------------------------------------------------------------|
| `event`        | `WorkflowDispatchService::dispatch()` (a real domain event matched). |
| `schedule`      | `RunScheduledWorkflowsCommand` (the sweep — see below).             |
| `manual`         | `WorkflowManualRunService` / `dispatchManual()`.                    |

`origin` is the **authoritative** signal for how a run began — **not** `creator_id`.
`WorkflowRunManager::start()` stamps the run row's OWN `creator_id`/`creator_type` EXPLICITLY
(bypassing `HasCreator`'s generic `saving`-hook inference, so a child re-trigger run is never
accidentally stamped onto its parent's `WorkflowRunContext`): the acting user for a MANUAL run,
or — for an engine-started run (event/schedule) — the WORKFLOW'S OWN AUTHOR
(`creator_id => $creatorId ?? $workflow->creator_id`, `creator_type => 'user'` always). **`creator_id`
is therefore never `null` on ANY run**, including a schedule-sweep run started outside a request
(real cron) — this corrects the previously-documented behavior on this page (the old `HasCreator`
fallback to `auth()->id()` did leave a schedule run's `creator_id` null; that gap is closed — see
ADR-0015 §5). Regardless: **read `origin`, never infer engine-vs-manual from `creator_id`** — an
EVENT run fired inside an authenticated HTTP request still carries the workflow's author (which
may or may not be the same person triggering the event) as `creator_id`, not "whoever caused the
event."

---

## Loop protection

A workflow step can author a change (create a task) that itself matches ANOTHER workflow's
trigger — or, since `create_form_report` creates a `FormReport` (not a `FormSubmission`), a
step's `create_task` output could in principle feed a downstream `form_submitted` workflow only
if that task's form is later itself submitted (not automatic). Two independent mechanisms bound
runaway re-triggering regardless of the exact chain shape:

### Origin-tagging + depth (`WorkflowRunContext`)

`WorkflowRunContext` is an in-process singleton holder for the run **currently executing on
this worker**. `WorkflowStepRunner::run()` sets it for the duration of a run and clears it in a
`finally` block. When `WorkflowDispatchService::maybeStart()` computes a new run's depth/origin:

- If a run IS currently executing on this worker (a step authored the re-triggering change),
  the new run is that run's **child**: `depth = parent.depth + 1`, `origin_run_id = parent.id`.
- Otherwise it is top-level: `depth = 0`, `origin_run_id = null`.

`config('workflows.max_depth')` (default 3, env `WORKFLOWS_MAX_DEPTH`) bounds the chain: a
would-be child run whose `depth` would exceed this is refused (`Log::info`, no run created) —
so a workflow that keeps re-triggering itself (or another workflow) cannot loop unbounded.

A manual run and a schedule-sweep run are ALWAYS top-level (`depth = 0`, no `origin_run_id`) —
loop protection only concerns chains authored BY a running workflow's own steps.

**The loop-surface note (now dormant, kept for the mechanism's sake):** with the 5.1 step set
(`create_task`, `create_form_report`), a step no longer directly authors an approval conclusion
or a task-status transition the way the Etap-5 `assign_bot`/`start_approval` steps could — so
the most direct re-trigger loops from Etap-5 are structurally gone. The depth/origin machinery is
kept exactly as-is (not simplified away) because it remains the correct general safety net for
ANY future step type that authors a change able to feed back into a trigger, and because a
`create_task` step's created task can still itself be submitted through a form later, indirectly
reaching a `form_submitted` trigger.

### Cost limits (`config/workflows.php`)

A workflow **run** is the unit of cost (the same doctrine as `config/ai.php`'s per-task bot-run
cap): rather than metering tasks created / reports created separately, the NUMBER OF RUNS stands
in as the cost proxy.

| Config key             | Env var                        | Default | Meaning                                                                |
|---------------------------|-------------------------------------|---------|--------------------------------------------------------------------------|
| `max_runs_per_month`       | `WORKFLOWS_MAX_RUNS_PER_MONTH`       | 100     | SOFT per-workflow monthly budget. `WorkflowRunManager::runsThisMonth($workflow)`. |
| `max_runs_hard_cap`         | `WORKFLOWS_MAX_RUNS_HARD_CAP`         | 500     | ABSOLUTE **workspace-wide** monthly ceiling across ALL workflows, **including manual runs**. `runsThisMonthAcrossWorkspace()`. |
| `run_timeout`               | `WORKFLOWS_RUN_TIMEOUT`               | 900 (s) | Stale-claim reaper threshold — see Run lifecycle below.                 |
| `wait_timeout`                | `WORKFLOWS_WAIT_TIMEOUT`                | 2700 (s) | Stale-WAIT reaper threshold (R2 sub-stage 5) — the LAST-RESORT release for a run parked `waiting` past this window. See "Suspend/resume engine" above for the full timeout-ordering invariant. |
| `max_depth`                  | `WORKFLOWS_MAX_DEPTH`                 | 3       | Re-trigger depth guard — see above.                                     |
| `assist_rate_per_minute`       | `WORKFLOWS_ASSIST_RATE_PER_MINUTE`      | 5       | AI schedule-assist per-user throttle — a SEPARATE meter, not counted against the run budget. |
| `ai_text_max_calls_per_run`      | `WORKFLOWS_AI_TEXT_MAX_CALLS_PER_RUN`     | 10      | `@[ai-text]` calls allowed within ONE run (SB2) — a PER-RUN budget, not per-workflow/month. Occurrences beyond the cap resolve to `''`, no call spent. |
| `ai_text_max_chars`               | `WORKFLOWS_AI_TEXT_MAX_CHARS`               | 2000    | Length cap applied to each generated `@[ai-text]` string (SB2), multibyte-safe truncation. The target field's own DB limit still applies on top. |

**Either run cap being reached refuses a new run** (`WorkflowDispatchService::capReached()`,
shared by the event pipeline, the schedule sweep, and the manual endpoint's 422 check — one
method, so all three paths enforce the identical ceiling). Behavior differs by how the run was
ABOUT to start:

| Path              | At-cap behavior                                                                  |
|---------------------|---------------------------------------------------------------------------------------|
| Event trigger        | Skipped **silently** (`Log::info`), never surfaced to the user who triggered the underlying domain action (e.g. approving a submission never fails because a workflow it might have triggered is over budget). |
| Schedule sweep         | Skipped, but the due SLOT IS STILL CONSUMED (see the Schedule section's "slot-consumed" doctrine) — a capped workflow does not backlog-fire every missed slot once the cap later lifts. |
| Manual run               | Refused with a **422** on the `workflow` key (see the manual-run endpoint above) — a manual run is a deliberate action, so silent skipping would be confusing; the caller needs to know it did not happen. |

---

## Schedule

`schedule`-triggered workflows are driven entirely by `WorkflowScheduleService` (the single
source of truth for "what is this workflow's next fire time") plus the
`workflows:run-scheduled` console sweep. **This revision replaces the closed 12/16-family
vocabulary (ADR-0009 §3, ADR-0010) with a COMPOSITIONAL descriptor v2** — see ADR-0012 for the
full design record. Instead of picking one of N named presets, a schedule is now THREE
INDEPENDENT axes combined with AND — `time` (WHEN in the day, required), `day` (WHICH day,
optional) and `month` (WHICH month, optional) — plus an optional `exclusions` skip filter and an
optional `tz`. A fire happens only when time AND day AND month all match, minus any exclusion:

```
{ "time": { "mode": "…", … }, "day"?: { "mode": "…", … }, "month"?: { "mode": "…", … },
  "exclusions"?: { "months"?: [...], "weekdays"?: [...], "dates"?: [...] }, "tz"?: "…" }
```

Every field is validated in ONE place, `WorkflowScheduleRulesValidator` (shared verbatim by the
write path, the AI-assist re-validation, and the preview endpoint), and compiled in ONE place,
`ScheduleCompiler`. Both the compiler and the SHAPE half of the grammar
(`RecurrenceDescriptorValidator`, which `WorkflowScheduleRulesValidator` renders into this module's
sentences) now live in the shared `App\Support\Recurrence` layer, extracted so the Calendar module can
eventually reuse the same cadence engine without naming Workflows — see
**ADR-0052-shared-recurrence-layer.md** for the design record; nothing about this wire contract
changed. All numeric bounds live in `App\Support\Recurrence\Enums\ScheduleLimits` — the single
contract the frontend mirrors as a TypeScript constant. Validation errors are keyed
`trigger_config.schedule.{time,day,month,exclusions,tz}.*` (write path) or `schedule.*` (the
AI-assist's standalone re-check).

### TIME axis (`schedule.time`) — the only required axis

| `time.mode` | Fields | Limits | Semantics |
|---|---|---|---|
| `at` | `at`: string[] of `'HH:mm'` | 1–6 distinct entries | Fires at EACH listed wall-clock time (the union across the list) — e.g. `at:["08:00","17:00"]` fires twice a day. |
| `every_minutes` | `minutes`: int; optional `from`/`to`: `'HH:mm'` | `minutes` 1–59 | A WALL-CLOCK minute grid (`:00,:15,:30,:45` for `minutes:15`) — NOT an activation-phased interval. The optional window bounds it to part of the day (both-or-neither, `from` strictly before `to`). |
| `every_hours` | `hours`: int; `minute`: int (optional, default 0); optional `from`/`to`: int hour | `hours` 1–23, `minute` 0–59, window bounds 0–23 | An every-N-hours grid at `:minute` past the hour, MODULO N within the day (resets at midnight — the gap across midnight can be shorter than `hours`). The optional window bounds it to a whole-hour range. |

### DAY axis (`schedule.day`) — optional, defaults to `every_day`

| `day.mode` | Fields | Limits | Semantics |
|---|---|---|---|
| `every_day` | — | — | No day restriction (the default). |
| `every_n_days` | `n`: int; optional `from`/`to`: int day-of-month | `n` 1–31, window bounds 1–31 | A day-of-month step. The optional window bounds it to a day-of-month range. |
| `weekdays` | `weekdays`: int[] | 1–7 distinct, each 0–6 (`0`=Sunday) | A SET of weekdays — several days per week in ONE schedule (e.g. Mon+Wed+Fri → `[1,3,5]`). |
| `month_days` | `days`: int[] | 1–31 distinct, each 1–31 | A SET of calendar days. A day a given month lacks (31 in February) SKIPS that month's fire — never clamped. Use `special:last_day` for a guaranteed month-end fire. |
| `special` | `special`: one of the rules below, + that rule's own params | — | A month-anchored day a plain set cannot express. |

**`day.special` rules** (`ScheduleDaySpecial`):

| `special` | Extra params | Semantics |
|---|---|---|
| `last_day` | — | The last calendar day of the month. |
| `nth_weekday` | `ordinal` (1–5), `weekday` (0–6) | The ordinal-th occurrence of a weekday in the month (e.g. ordinal=1, weekday=1 → "first Monday"). `ordinal=5` SKIPS any month with only four occurrences of that weekday — not clamped to the fourth. |
| `last_weekday` | `weekday` (0–6) | The LAST occurrence of a weekday in the month (e.g. weekday=5 → "last Friday") — a GUARANTEED monthly fire, unlike `nth_weekday` ordinal=5. |
| `last_working_day` | — | The last Mon–Fri of the month. **BESPOKE** — see the note below — and therefore **REQUIRES `time.mode = at`** (a dedicated 422 on `time.mode` otherwise); it fires at explicit `HH:mm` times, never on a minute/hour grid. Does **NOT** account for public holidays — a month whose last weekday is a holiday still fires that day. |

**Why `last_working_day` is bespoke, not compiled to a cron token.** The
`dragonmantank/cron-expression` v3.6.0 `LW` token does NOT mean "last working day of the month" —
it parses the `L` in `LW` as day `0`, which normalizes to the PREVIOUS month and returns
wrong/garbage dates (verified directly against the installed version, not assumed from
documentation). "Last working day" also cannot be expressed as any single standard cron
expression (it is the LATEST of {last Mon, …, last Fri}, not a fixed day-of-month/day-of-week
rule). `WorkflowScheduleService` therefore computes it directly: start at the month's last
calendar day and step backward over Saturday/Sunday, resolved as a local wall-clock time in the
schedule's tz then converted to UTC — the same wall-clock-then-convert pattern every other axis
follows. It carries the `time.at` list (one or more `HH:mm` fire times) and the set of months the
`month` axis allows, so it composes with a month restriction (e.g. "last working day of every
quarter-end month"). See ADR-0012 for the full record.

### MONTH axis (`schedule.month`) — optional, defaults to `every_month`

| `month.mode` | Fields | Limits | Semantics |
|---|---|---|---|
| `every_month` | — | — | No month restriction (the default). |
| `every_n_months` | `n`: int; optional `from`/`to`: int month | `n` 1–12, window bounds 1–12 | A **JANUARY-ANCHORED** month step (`1, 1+n, 1+2n, … ≤ 12`), MODULO the year — mirrors `every_hours`' hour-of-day-modulo-N doctrine. `n=5` gives {1,6,11} (Jan/Jun/Nov), then resets in January — the gap across the year boundary (Nov→Jan, 2 months) can be SHORTER than `n`. The optional window narrows the grid to a month range (e.g. `n:3, from:3, to:11` → March/June/September). |
| `months` | `months`: int[] | 1–12 distinct, each 1–12 | A SET of months (1=January). |

### Windows (`from`/`to`) — the shared bounded-range pattern

Four axis fields carry an OPTIONAL window: `time.every_minutes` (`'HH:mm'` pair), `time.every_hours`
(int hour 0–23 pair), `day.every_n_days` (int day-of-month 1–31 pair), and `month.every_n_months`
(int month 1–12 pair). Every window is **BOTH-OR-NEITHER** (supplying only `from` or only `to` is
a 422) and **`from` strictly less than `to`** — a window never wraps midnight (time) or the year
end (month). Omitting the window entirely means "unbounded" (the full grid, all day / all hours /
all months).

### Composition semantics

The three axes are INDEPENDENT and AND-combined: `day` fills EITHER the compiled day-of-month OR
day-of-week field (never both at once, so the classic cron dom/dow OR-trap cannot arise), and
`month` fills the month field — every `time` fire moment carries the SAME day/month restriction.
`exclusions` and `tz` never reach the compiled cadence grammar: exclusions are applied as a
POST-FILTER (below) and `tz` only resolves wall-clock fields before converting to UTC.

**Compiled form (implementation detail).** `ScheduleCompiler` turns a validated descriptor
into either a NON-EMPTY LIST of `dragonmantank/cron-expression` strings (one per `time.at` entry,
or up to 3 for a minute window split across an hour boundary — `WorkflowScheduleService` takes the
earliest strictly-after candidate across the whole list) or, for `day.special:last_working_day`,
the bespoke non-cron form described above. This is an implementation detail the FE never needs —
the wire contract is always the `{ time, day, month }` descriptor, never a cron string.

### Exclusions (`schedule.exclusions`)

An optional post-filter, `{ months?, weekdays?, dates? }`, evaluated in the SCHEDULE's own
timezone AFTER a candidate fire time is computed — never inside the compiled cadence itself:

| Key         | Shape                                          | Effect                                                      |
|---------------|--------------------------------------------------|------------------------------------------------------------------|
| `months`        | int array, values 1–12, max 11 entries, distinct   | drop a candidate whose LOCAL month is in this list.               |
| `weekdays`         | int array, values 0–6 (0=Sunday), max 6 entries, distinct | drop a candidate whose LOCAL day-of-week is in this list.  |
| `dates`               | `'Y-m-d'` string array, max 50 entries, distinct        | drop a candidate whose LOCAL date exactly matches an entry.  |

A candidate is dropped when its month **OR** its weekday **OR** its date matches (any one clause
is enough — the clauses are OR-combined against the SAME candidate, not independently applied
cadences). `months` is capped at 11 and `weekdays` at 6 so an exclusion list can never rule out
EVERY possible value of that dimension by itself — but a combination (or `dates`) can still leave
a schedule with no reachable occurrence at all; see "the empty-schedule guard" below.

```json
{ "time": { "mode": "at", "at": ["09:00"] }, "exclusions": { "weekdays": [0, 6] } }
```

The filter is a LOOP inside `WorkflowScheduleService::nextDueAt()`: compute the next union
candidate, and if it is excluded, advance the cursor past it and recompute — for EVERY compiled
form, including the bespoke `last_working_day` cadence ("every day except weekends" really does
skip the whole weekend, not just the boundary instant). Two HARD LIMITS bound the loop so an
unreachable schedule can never hang: at most **1000 iterations**, and a **10-year horizon** from
the search's start instant — exceeding either returns `null` (treated as "no occurrence").

**The empty-schedule guard.** After every structural rule passes, `WorkflowScheduleRulesValidator`
computes the schedule's actual first occurrence (reusing `WorkflowScheduleService::nextOccurrences`)
and rejects the write with a 422 on `trigger_config.schedule.exclusions` if the cadence has NO
reachable occurrence (e.g. `day.weekdays:[1]` whose `exclusions.weekdays` also excludes Monday) —
an unfireable schedule must never persist. **This guard is OPTIONAL**, controlled by the
validator's `checkEmpty` parameter: the write path (`StoreWorkflowRequest`/`UpdateWorkflowRequest`)
and the AI-assist re-validation keep it ON; the live `schedule-preview` endpoint turns it OFF, so
an over-constrained draft comes back as `{ empty: true }` DATA for a pre-save warning instead of a
422 while the user is still mid-edit. Every STRUCTURAL check (unknown mode, out-of-bounds field,
malformed window/exclusions) still runs and still 422s on preview.

### Timezone

`schedule.tz` defaults to `config('app.timezone')` (UTC). Every axis is now a WALL-CLOCK cadence
(there is no phase-from-activation interval left in v2), so every axis resolves **IN** the
schedule's own timezone — via the compiled cron form for a normal descriptor, or the bespoke
calculation for `last_working_day` — then converts to UTC for storage; a "09:00 Europe/Warsaw"
schedule fires at the correct UTC instant year-round (07:00 UTC in winter CET, 06:00 UTC in summer
CEST). `exclusions` are evaluated against the candidate RE-EXPRESSED in this same tz (an exclusion
is a wall-clock-day concept, like the rest of the cadence).

Every computed fire time is **strictly after** the `$from` instant it was computed from
(never equal), and always stored/returned in UTC.

### DST behavior (spring-forward AND fall-back, both pinned by unit tests)

**Spring-forward gap**: during a spring-forward gap the requested local wall-clock time does not
exist (e.g. 02:30 on the changeover night, Europe/Warsaw 2026-03-29 02:00→03:00). The cron
library shifts the non-existent local time FORWARD past the gap (e.g. that day's fire resolves to
03:30 local instead of crashing or looping) — one skewed fire is the accepted trade-off for a
rare boundary; the following day returns to the configured time.

**Fall-back overlap**: during a fall-back the local wall-clock hour repeats (Europe/Warsaw
2026-10-25 03:00→02:00, so local 02:00–03:00 happens TWICE that night). A wall-clock schedule at
`02:30` therefore fires **TWICE** that calendar night, at two DISTINCT UTC instants: `00:30 UTC`
(02:30 CEST, +02:00 — the first pass, before the clock rolls back) then `01:30 UTC` (02:30 CET,
+01:00 — the second pass, after). The strictly-after invariant is what makes this safe: because
the two local 02:30s are different UTC instants, advancing the cursor from the first to the
second is genuine forward progress — the SAME UTC moment is never fired twice, and the following
day returns to a single 02:30 (01:30 UTC). This is the cron library's observed resolution and is
**PINNED as accepted behavior**, not something the module works around — see
`tests/Unit/Workflows/WorkflowScheduleServiceTest.php::test_fall_back_fires_both_local_0230_instances`.

### Legacy read-shim (`LegacyScheduleUpgrader`) — no data migration was run

A schedule stored BEFORE this revision in the pre-v2 `{ family, params }` shape (or the earlier
Etap-5 preset shape) is upgraded to v2 TRANSPARENTLY at every read/compile boundary —
`WorkflowResource` (so the FE editor always seeds from the v2 shape), `WorkflowScheduleService` /
`ScheduleCompiler` (so an old row keeps firing), and the AI-assist re-validation (so a
model that still answers in the old vocabulary is judged fairly). Detection is by the presence of
a `family` key (v2 blocks never carry one); a v2 block is returned VERBATIM (the shim is
idempotent), and an unknown/garbage family is returned unchanged too — it then fails v2 validation
honestly on its missing `time`, exactly like any malformed block. `exclusions`/`tz` pass through
unchanged in both shapes. **No data migration or backfill was run** — every previously stored
schedule keeps working through this shim; only a NEW write must use the v2 shape (the write path
does not accept `{ family, params }` at all — see the BREAKING note at the top of this document).
See `LegacyScheduleUpgrader`'s docblock for the complete family→axis mapping table. It now lives
(like the rest of the compiler/engine) under `App\Support\Recurrence` — see ADR-0052.

### Live preview — `POST /workflows/meta/schedule-preview`

See the endpoint section above for the full request/response contract. In short: it is the ONLY
place occurrence dates are computed for the frontend (the FE never re-implements cadence math
locally); it validates with the empty-guard OFF (`empty: true` instead of a 422); it accepts an
optional `anchor` to centre the projection (the prev-or-at occurrence first, then ascending) so a
"jump to date" / paging UI needs no separate cursor field; and `approximate` is now ALWAYS
`false` (every v2 cadence is a wall-clock grid — there is no activation-phased interval left to
be indicative about).

### `workflows:run-scheduled` sweep

Scheduled `everyMinute()` + `withoutOverlapping()` in `routes/console.php`. This is the **ONLY**
path that starts a schedule run (the event dispatcher hard-refuses `SCHEDULE` by design).
Requires the app's `schedule:run` entry to actually be on cron/supervisor — like any Laravel
scheduled command, nothing fires without that. See Ops notes.

**Per-candidate race-safe compare-and-swap (`WorkflowScheduleService::claimDue`)**:

```sql
UPDATE workflows
SET next_due_at = <recomputed next fire time>, last_scheduled_run_at = now()
WHERE id = ? AND next_due_at = <the value the sweep just read> AND status = 'active'
```

Postgres row-locks the UPDATE, so exactly one concurrent sweep wins (`affected = 1`) and any
rival that already advanced the slot loses (`affected = 0` → skip). This is the **second**
concurrency guard beyond `withoutOverlapping` — even two overlapping sweep processes fire a due
workflow at most once. The WHERE compares the RAW stored `next_due_at` (`getRawOriginal`), so
it is a byte-for-byte match against what the DB holds, with no cast/precision mismatch.

**SLOT-CONSUMED doctrine**: the slot advances the MOMENT the CAS is won — **before** the cap
check. A workflow over its run budget therefore consumes and silently skips its due slot rather
than backlog-firing every missed slot once the cap later lifts (matches the event dispatcher's
silent-skip behavior for the same reason: caps are a spend ceiling, not a queue to drain later).

### Arming lifecycle (`WorkflowScheduleService::arm`)

`next_due_at` is armed (given a concrete future value) or nulled (cleared) at every write that
could affect it — never computed lazily elsewhere:

| Trigger                                     | Effect                                                                    |
|------------------------------------------------|--------------------------------------------------------------------------------|
| Create                                          | Always inactive → `next_due_at` stays `null` (never armed on create).         |
| `PATCH .../status` → `active` on a schedule workflow | Arms `next_due_at` from the cadence.                                     |
| `PATCH .../status` → `inactive`                    | Nulls `next_due_at`.                                                      |
| `PUT` (update) — cadence changed on an ACTIVE schedule workflow | Re-arms `next_due_at` from the NEW cadence.                       |
| `PUT` (update) — any other workflow                  | `arm()` no-ops to `null` (not armable: not schedule, or not active).      |

**Self-healing**: the sweep's `armOrphans()` step ARMS (does not fire) any ACTIVE schedule
workflow found with a `NULL next_due_at` (a legacy/edge state — e.g. one activated before this
mechanism existed). It only sets the first due time; it does not fire on that same pass.

---

## Run lifecycle

### Atomic claim (`WorkflowRunManager::claim`)

```sql
UPDATE workflow_runs
SET state = 'running', started_at = now()
WHERE id = ? AND state = 'pending'
```

A single conditional UPDATE, row-locked by Postgres, so exactly one concurrent worker can flip
`pending → running` (`affected = 1` = won the claim). A LOST claim (already running or
terminal) is a silent no-op in `WorkflowRunJob::handle()` — race-safe even under a non-sync
queue. `started_at` is stamped in the SAME statement, so the reaper can distinguish a
genuinely-stuck run from a slow-but-alive one.

### `$tries = 1` + `failed()` release, guarded by `isTerminal()`

`WorkflowRunJob::tries = 1` — no automatic Laravel retry. Two independent release paths exist:

1. `WorkflowStepRunner::run()` catches every step `Throwable` itself and releases the run to
   `failed` with the step's error message — this covers essentially all real failures.
2. `WorkflowRunJob::failed(Throwable $e)` is the LAST-RESORT release: fires on a job-level
   failure (e.g. a timeout, or an exception escaping around `handle()` rather than inside a
   step). It re-checks `$run->state->isTerminal()` first and no-ops if the run is already
   terminal (so it can never "double-release" a run the step runner already finished).

Neither path covers a hard process kill (SIGKILL, OOM, `queue:restart` mid-job) — the job
simply stops with no chance to run `failed()`, stranding the run in `running` forever (`claim()`
only matches `pending`, so nothing else can recover it). That is what the reaper is for.

### `workflows:reap-stale-runs`

Scheduled `everyFiveMinutes()` + `withoutOverlapping()`. Runs BOTH sweeps every invocation:

1. **`WorkflowRunManager::reapStaleRuns()`** — releases runs stuck in `running` past
   `config('workflows.run_timeout')` (default 900s) to `failed` with a timeout error. Also reaps
   **NULL-started orphans** (rows claimed before the `started_at` column/mechanism existed — a
   defensive catch-all, not expected in steady state post-deploy). Must exceed the longest
   plausible real run duration, or a slow-but-alive run would be reaped prematurely.
2. **`WorkflowRunManager::reapWaitingRuns()`** (R2 sub-stage 5, additive) — the stale-WAIT sweep;
   see "Suspend/resume engine" above for the full settled/gone/timed-out decision order and the
   timeout-ordering invariant it depends on.

**Per-tenant sweep**: `workflow_runs` lives in the shared database (shared-mode workspaces) AND
in each own-database workspace, so both this command and `workflows:run-scheduled` run BOTH sweeps
once unscoped on the default connection, then once per own-DB workspace (activating `TenantContext`
+ `TenantManager` for each) — mirrors `ReapStaleBotRunsCommand`.

---

## Tenancy

`Workflow`, `WorkflowRun`, `WorkflowRunStep` all use the `TenantAware` trait — every query is
automatically scoped to the active workspace via `WorkspaceScope`.

| Table                | Central (shared-db) migration                                  | Tenant (own-db) mirror                                          |
|-------------------------|----------------------------------------------------------------------|-----------------------------------------------------------------------|
| `workflows`               | `database/migrations/2026_07_07_000200_create_workflows_table.php` | `database/migrations/tenant/0001_01_01_000025_create_workflows_table.php` |
| `workflow_runs`             | `database/migrations/2026_07_07_000201_create_workflow_runs_table.php` | `database/migrations/tenant/0001_01_01_000026_create_workflow_runs_table.php` |
| `workflow_run_steps`         | `database/migrations/2026_07_07_000202_create_workflow_run_steps_table.php` | `database/migrations/tenant/0001_01_01_000027_create_workflow_run_steps_table.php` |
| `consts` (Variables module; renamed from `workflow_globals`, ADR-0028) | created `database/migrations/2026_07_22_000000_create_workflow_globals_table.php`, renamed `database/migrations/2026_07_26_000000_rename_workflow_globals_to_consts.php` | created `database/migrations/tenant/0001_01_01_000046_create_workflow_globals_table.php`, renamed `database/migrations/tenant/0001_01_01_000047_rename_workflow_globals_to_consts.php` |
| `custom_functions` (Variables module; ADR-0029) | `database/migrations/2026_07_27_000000_create_custom_functions_table.php` | `database/migrations/tenant/0001_01_01_000048_create_custom_functions_table.php` |

The tenant (own-db) mirrors omit `workspace_id` (one tenant database = one workspace) and carry
no cross-database foreign keys (`workflow_id`, `creator_id`, `workflow_run_id` are plain UUID
columns, matching the project-wide no-cross-DB-FK convention used by `bot_actions`). Both the
schedule sweep and the stale-run reaper explicitly iterate every own-database workspace in
addition to the shared connection (see above) — a workflow living only in one tenant's own
database would otherwise never be swept. `consts` and `custom_functions` are `Constant`/
`CustomFunction` models — `App\Modules\Variables\Models`, not Workflows — included here because
they share the identical `TenantAware`/`WorkspaceScope` isolation and central/tenant dual-schema
convention every table above uses; see ADR-0027/ADR-0028/ADR-0029 for the module they actually
live in.

**`consts` (Phase 3, additive; table renamed from `workflow_globals` in ADR-0028 via a reversible
`Schema::rename`, not a drop/recreate — no data loss either direction).** Its central `workspace_id`
is a plain nullable, indexed UUID column, not a declared foreign key — unlike
`workflows.workspace_id`'s `foreignIdFor(Workspace::class)` — though both are scoped identically at
the QUERY layer by the same `WorkspaceScope`/`TenantAware` machinery. The central table's
`unique(workspace_id, key)` becomes a plain `unique(key)` in the tenant mirror (one tenant database =
one workspace, so the reference namespace stays per-workspace either way); `creator_id`/
`creator_type` follow the same nullable, no-cross-DB-FK, auto-stamped-by-`HasCreator` convention as
every other table above.

**`custom_functions` (ADR-0029, new — not a rename).** Same central/tenant split, same nullable
`workspace_id` (central) / omitted `workspace_id` (tenant), same `creator_id`/`creator_type`
convention. `name` carries NO uniqueness constraint in either schema (identity is the row's uuid,
never the name — see "Custom functions" under "The typed variable system" above); `input_type`/
`return_type` are plain strings (a `VariableType` id), `args`/`body` are `json`.

---

## Ops notes

- **CORRECTED — `create_form_report` is NOT genuinely async even under a real queue; the whole run
  loop deliberately forces the `sync` driver.** The previous text on this page claimed
  `App\Modules\Forms\Jobs\CreateFormReport` runs "later on a queue worker" whenever `QUEUE_CONNECTION` is a real driver.
  That is wrong. `WorkflowRunJob`/`WorkflowRunResumeJob` both call `Queue::setDefaultDriver('sync')`
  around the ENTIRE step loop (save/restore around the pass, not merely for one step) — **load-bearing,
  not incidental**: it is what keeps `WorkflowRunContext` published for anything a step "fires and
  forgets", so `HasCreator` can stamp the resulting row with the RUN (ADR-0015; see the
  "Creator attribution" note above — a schedule/event run's `FormReport` would otherwise have no
  authenticated user to attribute to). The practical consequence: `create_form_report`'s
  `App\Modules\Forms\Jobs\CreateFormReport` runs **INLINE, in-process, before the step returns**, REGARDLESS of
  `QUEUE_CONNECTION` — a schedule-sweep or event-dispatch worker processing this step synchronously
  performs the full AI report analysis before the step (and therefore the run) moves on. This has
  always been true; it went undocumented until R2 sub-stage 5 needed to escape it deliberately (see
  below).
- **`generate_content` (R2 sub-stage 5) is the ONE escape hatch out of the forced `sync` driver, and
  it is the genuinely-async step this module actually has.** `App\Modules\Workflows\Support\
  RealQueueConnection` is a small container singleton both run jobs publish the PRE-OVERRIDE queue
  connection into (before forcing `sync`); `GenerateContentStep::run()` reads it explicitly when
  dispatching the generation session's claim, so that ONE dispatch reaches the real queue while
  everything else in the same step loop still runs inline under the sync override. A step that
  dispatches without reading `RealQueueConnection` gets the forced `sync` value like everything
  else — this is deliberate: only a step that actually SUSPENDS the run (implements
  `SuspendableWorkflowStep`) has a reason to escape. See "Suspend/resume engine" above and
  ADR-0039 D1/D8.
- **The schedule-assist throttle (`assist_rate_per_minute`) needs a PERSISTENT cache store.**
  `RateLimiter` reads/writes the DEFAULT cache store. The production default (`database`) is
  fine; the `array` driver resets every process — correct for tests exercising the throttle
  in-process, WRONG for a real multi-worker/multi-request deployment (each process would get its
  own counter, defeating the throttle).
- **`schedule:run` must be on cron/supervisor.** Both `workflows:run-scheduled`
  (`everyMinute()`) and `workflows:reap-stale-runs` (`everyFiveMinutes()`) are registered via
  Laravel's scheduler in `routes/console.php` — like any Laravel scheduled command, NOTHING
  fires unless the environment actually invokes `schedule:run` on a real interval (cron entry or
  a supervisor process). This is unconditional infrastructure, not optional.

---

## Accepted residual risks / known notes

These are documented, reviewed trade-offs — not a TODO list.

- **Bot-authored form submissions are not distinguishable in `source`.** See the `form_submitted`
  trigger-config section and ADR-0009 §5 — `source` is derived purely from the submittable
  morph (`manual`/`task`), which cannot express "who/what filled this in". DEFERRED: would need
  a new column tracking submission authorship.
- **The residual schedule-assist honesty limit.** Re-validation guarantees STRUCTURAL
  validity/compilability of an AI-proposed schedule, never SEMANTIC correctness against the
  user's actual intent — see the `/workflows/schedule-assist` endpoint section above.
- **`day.special:last_working_day` and every other axis/mode ignore public holidays.** A computed
  "last working day" (or any other fire date) that lands on a public holiday still fires
  normally — there is no holiday-calendar concept anywhere in the schedule vocabulary. See
  Planned/deferred below.
- **DST fall-back double-fire is accepted, not a bug.** A wall-clock schedule whose time falls in
  a fall-back-overlap hour (e.g. a daily `02:30` in Europe/Warsaw on the October changeover) fires
  TWICE that calendar night at two distinct UTC instants — see the DST section above. This is the
  cron library's observed resolution, pinned by a unit test, and treated as correct: the
  wall-clock time genuinely occurred twice that night.
- **Stale targeting ids are a safe no-op.** If a workflow's `trigger_config.form_id` references
  a form that later gets deleted, the targeting match simply never matches it again — no error,
  no orphan-cleanup needed. The definition just becomes effectively untargeted until edited.
- **Thundering-herd at popular schedule times** (e.g. many workspaces choosing `09:00 daily`)
  is bounded by the run-budget caps, not eliminated by scheduling jitter. The sweep runs every
  minute and processes all due workflows found in the read, so a large clustered due-set is
  handled in one pass rather than smeared — acceptable at current scale.
- **Sweep commands (`workflows:run-scheduled`, `workflows:reap-stale-runs`) have NO per-tenant
  `try/catch`.** A failure sweeping one own-database workspace's connection currently aborts the
  rest of that command's run for every subsequent workspace in the loop. This is a consistent,
  accepted pattern (matches the existing Bot reaper commands) — hardening (isolating per-tenant
  failures so one bad connection doesn't starve the others) is queued as future work, not done
  in this batch.
- **`creator_id` is non-null on EVERY run, including engine-authored (event/schedule) ones** —
  `WorkflowRunManager::start()` explicitly inherits the workflow's own author for an engine run,
  never leaving it null. This is expected, intentional behavior (see ADR-0015 §5 and the Origin
  section above), not a bug: `origin` remains the authoritative signal for how a run began; never
  infer engine-vs-manual from `creator_id`.
- **The pre-existing `ResolveWorkspace`-after-`SubstituteBindings` middleware ordering** means
  route-model-binding for `{workflow}` / `{run}` resolves BEFORE the tenant/workspace context is
  set (`bootstrap/app.php` appends `ResolveWorkspace` to the `api` group, which already runs
  `SubstituteBindings` earlier in Laravel's default ordering). In practice this is caught by
  `WorkspaceScope` at QUERY time — every list/read in this module is independently
  workspace-scoped, so cross-workspace ROWS never leak into a response
  (`tests/Feature/WorkflowRunReadTest.php::test_run_index_returns_only_active_workspace_runs`
  documents exactly this reliance). This is an **app-wide, pre-existing condition, NOT
  introduced by the Workflows module** — a fix (reordering the middleware, or moving workspace
  resolution earlier) is queued separately as a cross-cutting concern, not scoped to this batch.
- **A `TIME` variable is descriptor-only and not yet conditionable (Phase 1a).**
  `VariableType::TIME` — and, since Phase 2a, `OBJECT` — has NO condition operators
  (`operatorCases()` = `[]`) and its flat wire `type` still degrades to `text`
  (`WorkflowVariableCatalogService::flatType()`), so a descriptor-only type is rejected at write
  time and the FE keeps mirroring a closed type union. **Update (2026-07-24, safety batch):** the
  runtime half of this is no longer a "loud tripwire" — it was REACHABLE.
  `WorkflowConditionEngine` `tryFrom`s a stored `source_type` RAW, so a legacy / hand-written /
  imported row naming `time`/`object` reached `OperationExecutor::normalizeInput()`'s
  exhaustive `match` and raised an `UnhandledMatchError` out of a gate that runs INSIDE form
  submission (a 500). `normalizeInput()` (and `WorkflowVariableResolver::coerce()`) now have a
  fail-soft `default` arm: an unsupported base type collapses the pipeline to an ordinary failure
  → the condition is simply `false`, ahead of the presence-family bypass (so `is_null` can not
  answer `true` for a type the engine cannot read). Write-time rejection remains the primary
  guard. The condition EVALUATOR (legacy flat clauses) was already total via the empty
  `operatorCases()` check and needed no change. See ADR-0022's amendment.
- **`PipelineValidator::walkPipeline`'s type gate does not yet know about the
  presence-op family (Phase 1b).** The write validator for a value-or-variable pipeline
  (`priority`/`deadline`/`submissions_from`/`submissions_to`) still requires an EXACT
  `op->inputType() === currentType` match at every step, including `coalesce`/`is_present`/
  `is_null`/`assert_present` (nominally `text`); the RUNTIME executor already special-cases these
  4 to accept any type (`isPresenceOp()`). Today this is INERT — no shipped field pipeline opens
  with a presence op over a non-text ref, and a markdown directive's pipeline has no write
  validation at all (there is no PHP markdown parser in this codebase), so it is unaffected — but
  it means a presence op is only writable at the START of a value-or-variable pipeline when the
  reference is itself `text`-typed. Relaxing `walkPipeline` to mirror the executor's bypass is
  deferred to Phase 2. See ADR-0022.
- **"Phase 2" of the variable-typesystem rework turned out to be structural types, not the three
  items named above.** ADR-0022 used "Phase 2" to name three specific follow-ups: `TIME` gaining
  real runtime semantics, the `walkPipeline` presence-op asymmetry immediately above, and two
  defensive hardening items (next bullet's `normalizeInput` gap, and write-time `date_format`
  pattern validation). The actual next batch shipped `object`/`array<object>` containers and the
  `file` composite instead (ADR-0023) — none of the three files those items live in
  (`PipelineValidator`, `OperationExecutor`, the TIME resolver/evaluator arms)
  were touched. All three remain outstanding, now deferred to a later, unnumbered phase — see
  "Planned / deferred" below.
- **A structural container (`object`/`array<object>`) is representation-only — no loop, no
  per-element access (Phase 2a, ADR-0023).** A repeater's own catalog entry, and a file's
  composite subfields, make the WHOLE form structure and a file's own facets visible/referenceable;
  they do not add a way to iterate a repeater's elements or a multi-file answer. Actually looping
  is out of scope, explicitly deferred to R2-Generator (which needs its own element-cardinality /
  output-binding design).
- **A file's `descriptor.array` is `false` for every field shipped today, even though the
  resolver's subfield collapse is already defensively multi-file-aware (Phase 2b, ADR-0023).** No
  form-builder surface exists to author a field that accepts more than one file, so
  `WorkflowVariableResolver::collapseFileSnapshot()`'s "take the first element, fail-soft" behavior
  is forward-defensive plumbing for a shape the type already declares support for, not evidence of
  a shipped multi-file capability.
- **A container nested inside another container has no referenceable path of its own (Phase 2a,
  ADR-0023).** Only a form's TOP-LEVEL section/repeater gets its own catalog entry; a section
  nested inside a repeater (or vice versa) is visible only inside its parent's recursive
  `descriptor.fields`, with no flat leaf and no reference-index path — not referenceable at all,
  not even as a whole object, until a real per-element loop context exists to give it one. (This
  is about the CONTAINER's own identity, unchanged by the later `objectSubfieldTypeMap()` update
  above — a non-array container's individual DECLARED FIELDS are, since that update, indexed by
  path one level down from wherever the container itself sits; only a REPEATER still blocks
  everything beneath it, at every depth.)
- **A const is LITERAL-only — no computed values, no cross-variable references, no cycle
  detection (Phase 3, ADR-0024).** `Constant` (renamed from `WorkflowGlobal`, ADR-0028) stores
  exactly a `descriptor` + a matching literal `value`; nothing reads `value` as an expression or a
  pointer to another const/trigger/step value. A COMPUTED const (one derived from another variable)
  is real, plausible future demand, explicitly PLANNED — see "Planned / deferred" below — not built
  in this phase.
- **A const has no soft-delete/restore, unlike `Workflow` (Phase 3, ADR-0024) — and, unlike a
  custom function, no delete-while-referenced guard either.** `ConstantService::delete()` is a
  hard `Model::delete()` — there is no `deleted_at` column and no restore endpoint. A workflow that
  already embeds a since-deleted const's `globals.<key>` reference keeps running: the reference
  fails SOFT to `null`/`''` at run time (the same "stale targeting id is a safe no-op" doctrine the
  module already applies to a deleted form/label), never an error — but the deleted const's own
  stored value cannot be recovered afterward.
- **The frontend's const-authoring editor ships a narrower type-authoring depth than the backend
  validator accepts (Phase 3, ADR-0024).** `ConstantTypeValidator` already validates a nested
  object/array/enum child inside an object's `fields`, and `array:true` on an `object` base
  (array-of-object), recursively and correctly — but `ConstantEditorDrawer.vue`'s type
  builder does not offer either combination yet (an object field's own type picker is scalar-only;
  the array toggle is disabled for an object base, with an in-UI note). Sending either shape
  directly to `POST /api/consts` validates and persists normally; only the editor's own
  picker is narrower. A pure frontend follow-up, not blocked on any backend change.
- **`map |> array_at |> <op>` fails closed at runtime even though it validates at write time
  (array-ops, ADR-0026).** The pure, contextless `OperationExecutor` collapses every array to
  a normalized `MULTI`/`string[]` and cannot recover a non-enum scalar element base a `map`
  synthesized mid-pipeline; a DIRECT `<multi source> |> array_at |> <op>` is unaffected (its element
  base comes from the source's own catalog descriptor). See "g. Array transform operations" above and
  ADR-0026's Consequences for the full reasoning; recovering this needs the executor to carry
  descriptor state end-to-end, deliberately not attempted in this revision.
- **`array_at` over an `array<object>`/`array<file>` returns a snapshot that cannot be piped through a
  further operation (array-ops, ADR-0026).** The snapshot is usable via path/subfield access, but a
  chained op on it fails closed — object/file per-element transformation is the job of the
  scope-rooted element pipelines (`map`/`filter`/`sort`/`reduce`), not `array_at`'s O(1) single pick.
  This is accepted by design, not a gap to close.

---

## Related files

- `app/modules/Workflows/` — module root
- `app/modules/Workflows/Models/Workflow.php`, `WorkflowRun.php`, `WorkflowRunStep.php`
- `app/modules/Workflows/Services/WorkflowService.php` — definition CRUD
- `app/modules/Workflows/Services/WorkflowDispatchService.php` — the event/manual dispatch seam
- `app/modules/Workflows/Services/WorkflowManualRunService.php` — manual-run target resolution + 422s; also owns `retry()` (terminal-FAILED guard + the shared run-budget cap)
- `app/modules/Workflows/Http/Requests/RetryWorkflowRunRequest.php` — retry's authorization + nested-ownership/cross-tenant 404 guards
- `app/modules/Workflows/Http/Controllers/WorkflowRunController.php` — `index`/`global`/`show`/`retry` (run monitoring + retry)
- `app/modules/Workflows/Services/WorkflowRunManager.php` — claim / release / reaper / cap counters
- `app/modules/Workflows/Services/WorkflowRunContext.php` — in-process current-run holder (loop-depth seam)
- `app/modules/Workflows/Services/WorkflowStepRunner.php` — executes a claimed run's steps
- `app/modules/Workflows/Services/WorkflowStepFactory.php` — step-type → implementation
- `app/modules/Workflows/Services/WorkflowConditionEvaluator.php` — typed condition matrix
- `app/modules/Workflows/Services/WorkflowTriggerPayloadFactory.php` — the whitelisted `{{trigger.*}}` payload builder
- `app/modules/Variables/Services/VariableResolver.php` — the directive + `{kind}` union resolver (replaces the Etap-5 flat `ReferenceResolver`); relocated from `app/modules/Workflows/Services/WorkflowVariableResolver.php` in the R2 PR-1a down-move (ADR-0030)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — the typed variable/condition catalog
- `app/Support/Recurrence/Enums/ScheduleTimeMode.php`, `ScheduleDayMode.php`, `ScheduleMonthMode.php`, `ScheduleDaySpecial.php` — the v2 axis/mode enums (moved from `app/modules/Workflows/Enums/` — see ADR-0052)
- `app/Support/Recurrence/Enums/ScheduleLimits.php` — the ONE place every numeric bound lives (the FE mirrors it verbatim; moved from `app/modules/Workflows/Enums/` — see ADR-0052)
- `app/Support/Recurrence/Enums/RecurrenceViolationCode.php`, `app/Support/Recurrence/RecurrenceViolation.php` — the shared shape-grammar's violation codes and value object (ADR-0052)
- `app/Support/Recurrence/ScheduleEngine.php` — the pure cadence arithmetic (`nextDueAt`, `nextOccurrences`, `occurrencesFrom`, `previousOrAtOccurrence`, `occurrencesBetween`, `occurrenceDaysBetween`, `isApproximate`, `timezone`); extracted from `WorkflowScheduleService` (ADR-0052)
- `app/Support/Recurrence/RecurrenceDescriptorValidator.php` — the shared SHAPE grammar (`violations()`/`unreachable()`), answering in codes only; rendered into this module's sentences by `WorkflowScheduleRulesValidator` (ADR-0052)
- `app/modules/Workflows/Services/WorkflowScheduleService.php` — the module-facing facade: CAS claim + `arm()`/`isArmable()` (the only members left that touch a `Workflow` model) delegating everything else to `ScheduleEngine`
- `app/Support/Recurrence/ScheduleCompiler.php` — the v2 descriptor → cron-list/last-working-day compiler (the one cadence grammar); renamed from `WorkflowScheduleCompiler` and moved from `app/modules/Workflows/Services/` (ADR-0052)
- `app/Support/Recurrence/CompiledSchedule.php` — the compiled cadence value object (`cron` list / `last_working_day` kinds — no interval kind in v2; moved from `app/modules/Workflows/Services/` — see ADR-0052)
- `app/modules/Workflows/Services/WorkflowScheduleRulesValidator.php` — the ONE schedule-block rule set (write path + AI re-validation + preview, `checkEmpty` toggle); per-key type/range rules + the English-sentence renderer over the shared grammar's codes
- `app/Support/Recurrence/LegacyScheduleUpgrader.php` — the read-shim upgrading a stored/proposed legacy `{ family, params }` block to v2 (no data migration was run; moved from `app/modules/Workflows/Services/` — see ADR-0052)
- `app/modules/Workflows/Services/WorkflowScheduleAssistService.php` — AI assist orchestration + re-validation gate
- `app/modules/Workflows/Agents/ScheduleAssistAgent.php` — the tool-less natural-language agent, prompt built from the v2 enums/limits
- `app/modules/Workflows/Http/Requests/SchedulePreviewRequest.php`, `Http/Controllers/WorkflowSchedulePreviewController.php` — the live schedule-preview endpoint (incl. the `anchor` param)
- `app/modules/Workflows/Steps/` — the step implementations (`CreateTaskStep`, `CreateFormReportStep`,
  `GenerateContentStep`, `CreateEventStep` — R3 B4, see the `create_event` section above and
  `docs/backend/calendar-api.md`)
- `app/modules/Workflows/Jobs/WorkflowRunJob.php`
- `app/modules/Workflows/Console/RunScheduledWorkflowsCommand.php`
- `app/modules/Workflows/Console/ReapStaleWorkflowRunsCommand.php`
- `app/modules/Workflows/Policies/WorkflowPolicy.php`
- `app/modules/Workflows/routes/api.php`
- `app/modules/Forms/Observers/FormSubmissionObserver.php` — form_submitted hook
- `app/modules/Forms/Services/FormReportService.php`, `Jobs/CreateFormReport.php` — the `create_form_report` step's sink
- `app/modules/Calendar/Services/CalendarEventService.php`, `DTOs/CalendarEventDTO.php` — the `create_event` step's sink (R3 B4; full contract in `docs/backend/calendar-api.md`)
- `config/workflows.php` — caps, run timeout, max depth, assist throttle
- `routes/console.php` — schedule registration for both console commands
- `tests/Feature/WorkflowCrudTest.php`
- `tests/Feature/WorkflowDispatchTest.php`
- `tests/Feature/WorkflowReferenceContractTest.php` — pins the `{{trigger.*}}` payload paths for `form_submitted`/`schedule` (updated for the 5.1 trigger set)
- `tests/Feature/WorkflowRunEngineTest.php`
- `tests/Feature/WorkflowRunReadTest.php`
- `tests/Feature/WorkflowRunRetryTest.php` — the retry endpoint (new-run wiring, terminal-state 422, budget-cap 422, foreign-workflow/missing/cross-tenant 404s, guest 401, non-member 403)
- `tests/Feature/WorkflowScheduleSweepTest.php`
- `tests/Feature/WorkflowScheduleAssistTest.php` — v2 examples incl. the legacy-proposal read-shim case
- `tests/Feature/WorkflowSchedulePreviewTest.php` — the preview endpoint (empty/`anchor`/prev-or-at semantics, `approximate` always false, checkEmpty-off behavior)
- `tests/Feature/WorkflowStepsTest.php`
- `tests/Feature/CalendarEventWorkflowStepTest.php` — the `create_event` step (R3 B4): creator attribution, the all-day discriminator surviving the resolver, both authoring-time and run-time failure modes
- `tests/Feature/WorkflowVariableCatalogTest.php`
- `tests/Unit/Workflows/WorkflowConditionEvaluatorTest.php`
- `tests/Unit/Workflows/WorkflowScheduleServiceTest.php` — includes the DST spring-forward AND fall-back pins, the `exclusions` post-filter loop, `last_working_day`
- `tests/Unit/Workflows/WorkflowScheduleCompilerTest.php` — per-axis compiled-cron assertions, the `last_working_day` month-filter cases (tests `App\Support\Recurrence\ScheduleCompiler`; the test class itself kept its pre-move name)
- `tests/Unit/Workflows/WorkflowScheduleLegacyUpgraderTest.php` — the full legacy-family → v2 mapping table
- `tests/Unit/Workflows/WorkflowScheduleRulesRendererTest.php` — the module's rendering half of the split validator: every shared `RecurrenceViolationCode` renders a sentence (exhaustive by test), plus the standalone `validate()` seam (ADR-0052)
- `tests/Unit/Workflows/WorkflowVariableResolverTest.php`
- `tests/Feature/RecurrenceLayerBoundaryTest.php` — the shared layer's own boundary guard: no module names, an allowlisted namespace ceiling, no executive surface (byte scan + reflection over public signatures) — ADR-0052
- `tests/Unit/Recurrence/RecurrenceDayProjectionTest.php` — the day-projection anchor decision, re-derived from live tzdata rather than hardcoded (ADR-0052)
- `resources/js/next/pages/workflows/__tests__/WorkflowEditorDrawer.spec.ts` — pins the exact create-payload wires reproduced above (incl. the v2 `schedule` wire)
- `docs/decisions/ADR-0012-workflows-schedule-descriptor-v2.md` — this revision's schedule design decisions (compositional descriptor, read-shim, flat anchored preview, AI-modal-with-approval)
- `docs/decisions/ADR-0010-workflows-schedule-rebuild.md` — the 12→16-family batch; §7 (frontend two-mode) is SUPERSEDED by ADR-0012, the rest stands as history
- `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` — the 5.1 re-scope decisions
- `docs/decisions/ADR-0008-workflows-module-design.md` — run-engine decisions that still hold (superseded sections marked)
- `docs/decisions/ADR-0051-calendar-module-design.md` — the Calendar module's own design record (the `create_event` step crosses into it one-way); full API contract in `docs/backend/calendar-api.md`
- `docs/decisions/ADR-0052-shared-recurrence-layer.md` — the schedule engine's extraction to `App\Support\Recurrence`, the shape-vs-prose validation split, and the day-projection anchor decision; zero wire-contract change
- `docs/next/workflows-uxui-spec.md` — the frontend UX/UI specification (REVISION 4 — the v2 compositional builder)
- `app/modules/Variables/Services/OperationExecutor.php` — the shared pipeline engine (72→77 ops; phase-1b's presence family + `date_format`)
- `app/modules/Variables/DTOs/OperationResult.php` — pipeline outcome, incl. the `hard` flag (phase-1b)
- `app/modules/Variables/Enums/Operation.php` — the 77-op enum (`inputType`/`outputType`/`argDescriptors`/`producesChoice`/`isPresenceOp`)
- `app/modules/Variables/Enums/VariableType.php` — the 8-case type enum incl. `TIME` and `descriptor()` (phase-1a)
- `app/modules/Variables/Services/PipelineValidator.php` — the value-pipeline write validator (the `walkPipeline` presence-op asymmetry noted above)
- `tests/Unit/Workflows/OperationExecutorTest.php`, `tests/Unit/Workflows/WorkflowVariableResolverTest.php` — phase-1b presence/date-format/default coverage
- `tests/Feature/WorkflowVariableCatalogTest.php` — phase-1a `descriptor` coverage
- `docs/decisions/ADR-0022-workflows-variable-typesystem-phase1.md` — this phase's design record
- `app/modules/Disk/Models/File.php` — `serveUrl()` (phase-2b), the `disk.show` URL builder the trigger file snapshot's `url` key reuses
- `tests/Feature/WorkflowFileAttachmentTest.php` — the file snapshot's `url` key, incl. the "not a storage path" pin (phase-2b)
- `tests/Feature/WorkflowStepValuePipelineValidationTest.php` — file-subfield pipeline write validation, incl. the repeater-element-ref rejection (phase-2b.1)
- `resources/js/next/pages/workflows/workflowVariables.ts` — `expandVariables()`/`descriptorBaseToType()`, the structural-descriptor FE expansion (phase-2c)
- `resources/js/next/pages/workflows/types.ts` — `CatalogDescriptorField`, the widened `CatalogVariableDescriptor.base`/`CatalogTypeId` (phase-2c)
- `docs/decisions/ADR-0023-workflows-variable-typesystem-phase2.md` — this phase's design record (object/array<object> containers, the file composite, phase-2a/2b/2b.1/2c)
- `app/modules/Variables/Models/Constant.php` — the LITERAL constant model (Phase 3; renamed from `WorkflowGlobal` and moved from Workflows in ADR-0028)
- `database/migrations/2026_07_22_000000_create_workflow_globals_table.php` + `database/migrations/2026_07_26_000000_rename_workflow_globals_to_consts.php`, `database/migrations/tenant/0001_01_01_000046_create_workflow_globals_table.php` + `database/migrations/tenant/0001_01_01_000047_rename_workflow_globals_to_consts.php` — the dual central/tenant schema, create then reversible rename (Phase 3 / ADR-0028)
- `app/modules/Variables/Http/Controllers/ConstantController.php` — CRUD (Phase 3; renamed from `WorkflowGlobalController`)
- `app/modules/Variables/Http/Requests/StoreConstantRequest.php`, `UpdateConstantRequest.php` — identity (`name`/`key`) validation + the type-validator hand-off (Phase 3; renamed from `Store`/`UpdateWorkflowGlobalRequest`)
- `app/modules/Variables/Services/ConstantTypeValidator.php` — the single authorable-type + value authority, shared by Store/Update (Phase 3; renamed from `WorkflowGlobalTypeValidator`)
- `app/modules/Variables/Services/ConstantService.php` — CRUD persistence (Phase 3; renamed from `WorkflowGlobalService`)
- `app/modules/Variables/Http/Resources/ConstantResource.php` — the `globals.<key>` reference (byte-identical wire) + capability-flag wire shape (Phase 3; renamed from `WorkflowGlobalResource`)
- `app/modules/Variables/DTOs/ConstantDTO.php` (Phase 3; renamed from `WorkflowGlobalDTO`)
- `app/modules/Variables/Policies/ConstantPolicy.php` — workspace-membership read, creator-only mutation (Phase 3; renamed from `WorkflowGlobalPolicy`)
- `database/factories/ConstantFactory.php` — `text()`/`number()`/`boolean()`/`date()`/`enum()`/`textList()`/`object()`/`objectList()` states (Phase 3; renamed from `WorkflowGlobalFactory`)
- `app/modules/Variables/Enums/VariableType.php` — `fromDescriptor()`, the inverse of `descriptor()` (Phase 3)
- `tests/Feature/ConstantCrudTest.php` — CRUD, type/value validation, key rules, workspace-scoped authorization (Phase 3; renamed from `WorkflowGlobalCrudTest`)
- `tests/Feature/ConstantCatalogTest.php` — the `globals` catalog source, reference index, runtime type map, workspace scoping (Phase 3; renamed from `WorkflowGlobalCatalogTest`)
- `tests/Unit/Workflows/WorkflowVariableResolverTest.php` — the `globals` root resolution + the injection-safety pin (`test_a_global_value_with_reference_like_bytes_is_not_re_interpreted`) (Phase 3)
- `docs/decisions/ADR-0024-workflows-variable-typesystem-phase3-globals.md` — this phase's design record (LITERAL-only scope, the `globals` root, dual persistence, the authorable-type boundary, the NUL-reject injection invariant, the deferred FE authoring depth); see its 2026-07-26 addendum for the ADR-0028 rename pointer
- `resources/js/next/pages/variables/ConstantsView.vue`, `ConstantEditorDrawer.vue`, `ConstantValueField.vue`, `ConstantRow.vue` — the consts management screen (Phase 3, frontend; renamed and moved from `resources/js/next/pages/workflows/WorkflowGlobals*.vue`/`WorkflowGlobalValueField.vue`/`WorkflowGlobalRow.vue` into the new top-level Variables area, ADR-0028)
- `resources/js/next/pages/variables/consts.ts` — the draft⇆descriptor mapping + the client-side `ConstantTypeValidator` mirror (Phase 3, frontend; renamed from `resources/js/next/pages/workflows/workflowGlobals.ts`)
- `resources/js/next/app/stores/consts.ts` — list/CRUD store, invalidates every cached catalog after a mutation (Phase 3, frontend; renamed from `resources/js/next/app/stores/workflowGlobals.ts`)
- `resources/js/next/pages/variables/VariablesModuleLayout.vue` — the new top-level "Variables"/"Zmienne" nav shell holding Consts + Functions (ADR-0028)
- `app/modules/Variables/VariablesModuleServiceProvider.php` — registers the `consts`/`functions` API routes + the `ConstantPolicy`/`CustomFunctionPolicy` gates; registered BEFORE `WorkflowsModuleServiceProvider` in `bootstrap/providers.php` (ADR-0027)
- `app/modules/Variables/routes/api.php` — `Route::apiResource('consts', ...)->parameters(['consts' => 'constant'])`, `Route::apiResource('functions', ...)->parameters(['functions' => 'function'])`
- `tests/Feature/ConstantWireCompatTest.php` — characterization pins that the `globals.*` wire (runtime resolution, catalog source/path, the resource's `reference` field) is byte-identical after the ADR-0028 rename
- `docs/decisions/ADR-0027-variables-module-extraction.md` — the module boundary Consts (and Functions, below) now live behind: the one-way Workflows→Variables dependency, the full symbol-rename map, the `ElementScopeResolver`/`FunctionReferenceLookup` dependency inversions, `VariablesModuleBoundaryTest`
- `docs/decisions/ADR-0028-consts-rename.md` — this rename's own design record (why `Const`/`const` was unusable, the reversible `Schema::rename` migrations, the new Variables nav, and the wire-preservation decision)
- `app/modules/Variables/Contracts/OperationDefinition.php` — the shared shape a pipeline step's op resolves to, implemented by BOTH `Operation` (built-in) and `CustomFunctionOperation` (below), so the walk/executor never special-case which kind they are holding (ADR-0027/ADR-0029)
- `app/modules/Variables/Contracts/ElementScopeResolver.php`, `Contracts/FunctionReferenceLookup.php` — the two dependency-inversion interfaces Variables owns and Workflows implements/binds (`WorkflowVariableCatalogService`, `WorkflowFunctionReferenceScanner`) so the module boundary stays one-way (ADR-0027)
- `app/modules/Variables/Services/OperationResolver.php` — resolves a step's `op` id to a built-in `Operation` OR a workspace `CustomFunctionOperation` (built-ins checked first, `fn:` prefix required for a function match) — the ONE indirection that replaced every direct `Operation::tryFrom` in the engine (ADR-0029)
- `app/modules/Variables/Models/CustomFunction.php` — the function definition model: one input type, typed named args, one return type, a saved body pipeline (ADR-0029)
- `database/migrations/2026_07_27_000000_create_custom_functions_table.php`, `database/migrations/tenant/0001_01_01_000048_create_custom_functions_table.php` — the dual central/tenant schema (ADR-0029)
- `app/modules/Variables/Http/Controllers/CustomFunctionController.php`, `Http/Requests/Store`/`UpdateCustomFunctionRequest.php`, `Http/Resources/CustomFunctionResource.php`, `Policies/CustomFunctionPolicy.php`, `Services/CustomFunctionService.php` — CRUD + authorization (ADR-0029)
- `app/modules/Variables/Services/FunctionDefinitionValidator.php` — the single authority for a function's definition (input/return types, arg names/types, the body via `PipelineValidator`) AND the write-time 3-colour-DFS cycle check (`validateAcyclic()`/`hasCycle()`/`referencedFunctionIds()`) (ADR-0029)
- `app/modules/Variables/Support/CustomFunctionOperation.php` — the `OperationDefinition` view of a `CustomFunction` row (wire id `fn:<uuid>`, the runtime `body()`/`argTypes()` Phase-3b added) (ADR-0029)
- `app/modules/Variables/Support/FunctionScope.php` — the execution state threaded through `OperationExecutor`: the workspace's functions, the expansion DEPTH, the active-function VISITED chain, and the current scope FRAME; `overDepth()`/`hasVisited()` are the two fail-closed backstops, `frameRoots()` feeds the generalized `ScopeRef` (ADR-0029)
- `app/modules/Variables/Enums/PipelineLimits.php` — `MAX_FUNCTION_EXPANSION_DEPTH` (5), the function-nesting depth cap (ADR-0029)
- `app/modules/Workflows/Services/WorkflowFunctionReferenceScanner.php` — implements `FunctionReferenceLookup`; scans live, workspace-scoped workflows for a `fn:<uuid>` reference (the delete guard's Workflows-side half) (ADR-0029)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — `customFunctionOperations()`/`functionCatalog()`/`functionArgWire()`, merging every workspace function into the `operations` catalog as `{id:'fn:<uuid>', input, output, args, label, description}` (ADR-0029)
- `app/modules/Workflows/Services/WorkflowStepRunner.php` — threads the workspace's `customFunctionOperations()` into the run's `FunctionScope` so a `fn:<uuid>` step config op executes (ADR-0029)
- `app/modules/Workflows/Services/WorkflowConditionEngine.php` — threads functions into the condition-gate's own `FunctionScope` so a `fn:<uuid>` op is usable inside a `form_submitted` trigger condition too (ADR-0029)
- `tests/Feature/CustomFunctionCrudTest.php`, `tests/Feature/CustomFunctionValidationTest.php` — CRUD, definition/body validation, and the self-reference/two-function/three-deep cycle rejections (ADR-0029)
- `tests/Feature/WorkflowCustomFunctionTest.php` — the catalog `fn:<uuid>` merge, a workflow referencing a type-compatible/incompatible function (write-accept/reject), and the delete-while-referenced-by-a-workflow guard (ADR-0029)
- `tests/Unit/Variables/OperationExecutorFunctionTest.php` — RUNTIME execution: binding/reading a named arg, nested function calls, a map/filter/reduce element pipeline with a function arg IN SCOPE (the frame stack), the 5-deep-succeeds/6-deep-fails-closed depth cap, and the corrupted/direct self-cycle visited-set pins (ADR-0029)
- `tests/Unit/Variables/OperationResolverTest.php`, `tests/Unit/Variables/EngineOperationResolverCompletenessTest.php` — `OperationResolver`'s built-in-first/`fn:`-prefix resolution, and the "zero direct `Operation::tryFrom` in engine code" completeness gate (ADR-0027/ADR-0029)
- `tests/Unit/Variables/ScopeRefTest.php` — `ScopeRef::leaf()`'s generalized, caller-supplied `$roots` parameter (ADR-0029)
- `tests/Feature/VariablesModuleBoundaryTest.php` — the one-way dependency pin (no `App\Modules\Workflows` string anywhere under `app/modules/Variables`) + the `PipelineValidator::DEFAULT_REFERENCE_SOURCES` ↔ `WorkflowVariableResolver::ROOTS` equality pin (ADR-0027)
- `resources/js/next/pages/variables/FunctionsView.vue`, `FunctionEditorDrawer.vue`, `FunctionRow.vue` — the functions management screen (ADR-0029, frontend)
- `resources/js/next/pages/variables/functions.ts` — the reserved-scope-name/safe-identifier mirrors, the arg-draft shape, `functionScopeVars()` (the `{input, <argName>…}` scope feed the body-pipeline editor runs on), and the `fn:<uuid>` op-id helpers (ADR-0029, frontend)
- `resources/js/next/app/stores/functions.ts` — list/CRUD store, invalidates every cached catalog after a mutation (ADR-0029, frontend)
- `docs/decisions/ADR-0029-custom-functions.md` — this feature's design record (identity, nesting, the write-time cycle DFS, the runtime depth-cap/visited-set backstops, execution-by-expansion, the frame-stack generalization, the delete guard, the accepted scalar-first arg-control limitation)
- `app/modules/Variables/Enums/PipelineLimits.php` — `MAX_ARG_VARIABLE_DEPTH`, the one shared arg-variable nesting cap (Phase 4; split out of Workflows' `ConditionTreeLimits` in the Variables module extraction, ADR-0027)
- `app/modules/Variables/Enums/OperationArgType.php` — `argVariablePolicy()`, the per-category gate (Phase 4; renamed from the value-typed-only `variableValueType()` in the Phase 4b widening)
- `app/modules/Variables/DTOs/ArgVariablePolicy.php` — the two-facet (`refTypes`/`coerceTo`) per-arg-control policy DTO `argVariablePolicy()` returns; `null`/`null` = STRUCTURAL (Phase 4b)
- `app/modules/Variables/Services/VariableResolver.php` — `resolvePipelineArgs()`/`resolveStepArgs()`/`resolveArgVariable()`/`resolveStructuralArgVariable()`/`isVariableArg()`, the `argDepth`-threaded pre-resolution ahead of the executor (Phase 4; `resolveStructuralArgVariable()` added in Phase 4b; relocated from Workflows in the R2 PR-1a down-move, ADR-0030)
- `app/modules/Variables/Services/PipelineValidator.php` — `validateArgVariable()`/`validateArgVariableRef()`/`refFullPath()`, the `refCtx`/`argDepth`-threaded write validation, incl. the STRUCTURAL loose-gate branch (Phase 4 / 4b)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — `descriptorSubfieldTypeMap()`/`objectSubfieldTypeMap()`, indexing a non-array OBJECT descriptor's own fields recursively into the reference index + runtime type map (structural-referenceability follow-up, shipped alongside Phase 4b)
- `app/modules/Workflows/Http/Requests/StoreWorkflowRequest.php` — `validateVariablePipeline()` now passes its `$refCtx` + `argDepth: 0` into the value-pipeline walk (Phase 4)
- `app/modules/Variables/Services/OperationExecutor.php` — unchanged this phase; pinned as the "stays pure" boundary (Phase 4)
- `tests/Unit/Workflows/WorkflowVariableResolverTest.php` — argument-variable resolution, the depth-cap resolve/fail-soft pair, the injection-safety pin (Phase 4)
- `tests/Unit/Workflows/OperationExecutorTest.php` — `test_a_variable_union_arg_reaching_the_executor_fails_closed`, the executor-stays-pure boundary pin (Phase 4)
- `tests/Feature/WorkflowStepValuePipelineValidationTest.php` — argument-variable write validation, incl. the depth-cap `422`, the byte-verbatim persistence pin, and (Phase 4b) the widened option/structural-control coverage
- `resources/js/next/ui/editor/extensions/VariablePipelineEditor.vue` — the `depth` prop + `argVariable` scoped slot, offered for EVERY arg control since Phase 4b (Phase 4)
- `resources/js/next/ui/editor/extensions/PipelineArgLiteralInput.vue` — the literal control for EVERY arg kind (value AND, since Phase 4b, option/map/rules), shared by the editor's own fallback and the arg-variable field's value mode (Phase 4)
- `resources/js/next/ui/editor/extensions/operationHelpers.ts` — `MAX_ARG_VARIABLE_DEPTH`, `argVariablePolicy()` + `isStructuralArg()` (Phase 4; renamed/widened from `argVariableValueType()` in Phase 4b)
- `resources/js/next/ui/editor/extensions/types.ts` — `ArgVariableRef`, `ArgVariableValue`, `VariableArgValue` (Phase 4)
- `resources/js/next/ui/editor/extensions/VariableTypeIcon.vue` — the shared type-icon + `nullable`("?")/`array`("[]") modifier markers, incl. an sr-only `typeLabel` (UX refinement batch, shipped alongside Phase 4b)
- `resources/js/next/pages/workflows/ValueOrVariableField.vue` — the recursive `#argVariable` slot fill (now for every arg control) + `argToUnion()`/`unionToArg()` adapters; the per-reference "Default when empty" control relocated here into the ops modal, nullable-gated + typed via `ConstantValueField.vue` (renamed from `WorkflowGlobalValueField.vue`, ADR-0028) (Phase 4 / UX refinement batch)
- `resources/js/next/pages/workflows/VariableTreePicker.vue` — the expandable ARIA-tree variable picker (`role="tree"`/`treeitem`, keyboard expand/collapse/select/type-ahead), replacing the flat qualified-name Select (UX refinement batch)
- `resources/js/next/pages/workflows/workflowVariables.ts` — `variablePickerTree()`/`flattenPickerNodes()`, building the picker tree from a flat, already-filtered variable list (UX refinement batch)
- `resources/js/next/pages/workflows/DateOrVariableField.vue`, `resources/js/next/pages/workflows/WorkflowStepCard.vue` — forward the new `arg-variables` pool prop (Phase 4)
- `resources/js/next/ui/editor/__tests__/pipelineArgVariable.dom.spec.ts` — slot-gating (every control, depth cap) + raw-arg serialization pins (Phase 4, frontend)
- `resources/js/next/pages/workflows/__tests__/ValueOrVariableField.spec.ts` — the recursive-field pick/round-trip tests (Phase 4, frontend)
- `app/modules/Variables/Enums/Operation.php` — the 83-op enum (grew from 77); `array_count`/`array_at`/`array_map`/`array_filter`/`array_sort`/`array_reduce`, `isArrayOp()`, `isCollectionOp()`, `outputDescriptor()` (array-ops, ADR-0026)
- `app/modules/Variables/Enums/OperationArgType.php` — `ELEMENT_PIPELINE`/`REDUCE_SEED` arg-control kinds (array-ops, ADR-0026)
- `app/modules/Variables/DTOs/ArgVariablePolicy.php` — the third named constructor `elementPipeline()` + `isStructural()` (array-ops, ADR-0026)
- `app/modules/Variables/DTOs/OperationArg.php` — `elementPipeline()`/`reduceSeed()` descriptor factories (array-ops, ADR-0026)
- `app/modules/Variables/Support/ScopeRef.php` — the single source-aware `element`/`index`(`.subfield`) scope-ref predicate shared by the validator/resolver/executor (array-ops, ADR-0026 — fixes the Wave-2 Finding B name-collision bug)
- `app/modules/Variables/Services/PipelineValidator.php` — `walkPipelineDescriptor()`/`opAcceptsDescriptor()`/`validateCollectionArgs()`/`validateElementPipeline()`/`validateScopeRootedElementPipeline()`/`validateReduceSeed()`, the descriptor-tracking walker + terminal-by-construction gate (array-ops, ADR-0026)
- `app/modules/Variables/Services/OperationExecutor.php` — `applyCollection()`/`arrayMap()`/`arrayFilter()`/`arraySort()`/`arrayReduce()`/`arrayAt()`/`elementRunner()`/`runElement()`/`runReducer()`/`scopeOverlay()`/`resolveScopePipeline()`/`stepOutputType()`, the per-element re-entry + fail-closed matrix + the descriptor-derived `array_at` runtime type (array-ops, ADR-0026)
- `app/modules/Variables/Services/VariableResolver.php` — scope-aware pre-resolution (`ScopeRef`-gated, keeps a scope ref OUT of global pre-resolution) (array-ops, ADR-0026; relocated from Workflows in the R2 PR-1a down-move, ADR-0030)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — `elementScopeSubfields()`, the ONE place a repeater/file array is descended for element-scope access (array-ops, ADR-0026)
- `app/modules/Variables/Enums/PipelineLimits.php` — `MAX_ARRAY_ITERATIONS` (1000), `MAX_ELEMENT_PIPELINE_DEPTH` (3), the two array-op caps shared by the validator and executor (array-ops, ADR-0026; split out of Workflows' `ConditionTreeLimits` in the Variables module extraction, ADR-0027) — also now `MAX_FUNCTION_EXPANSION_DEPTH` (5), the custom-function expansion depth cap (ADR-0029)
- `tests/Unit/Workflows/OperationExecutorTest.php`, `tests/Feature/WorkflowConditionTreeValidationTest.php`, `tests/Feature/WorkflowStepValuePipelineValidationTest.php`, `tests/Unit/Workflows/WorkflowElementScopeValidationTest.php` — array-op runtime + write-validation coverage, incl. the fail-closed matrix, the caps, the terminal-by-construction gate, and the scope name-collision fix (array-ops, ADR-0026)
- `resources/js/next/ui/editor/extensions/standardOperations.ts` — the 6 array ops' FE catalog entries (array-ops, ADR-0026)
- `resources/js/next/ui/editor/extensions/operationHelpers.ts` — the descriptor-tracking `resolveType`/`computeInputType`/`pipelineSatisfies`, `VariableOperationDefinition.resolveOutput()` (array-ops, ADR-0026)
- `resources/js/next/ui/editor/extensions/ChoiceRuleWhenField.vue`, `resources/js/next/ui/editor/extensions/VariableSuggest.vue`, `resources/js/next/ui/editor/extensions/variableFeed.ts` — the element-pipeline editor's reused `when`-machinery embedding + the scope-aware variable feed (array-ops, ADR-0026, frontend)
- `resources/js/next/pages/workflows/WorkflowArgVariableField.vue`, `resources/js/next/pages/workflows/argVariableAdapters.ts` — the array-ops-era `#argVariable` slot fill + adapters, superseding the now-deleted `VariableTreePicker.vue` referenced elsewhere in this list under the Phase-4 UX-refinement batch (that reference is stale — a frontend-agent follow-up should reconcile it)
- `resources/js/next/ui/editor/__tests__/arrayOperations.spec.ts`, `arrayObjectOps.spec.ts`, `arrayTransformOps.spec.ts`, `elementPipeline.dom.spec.ts`, `elementObjectPipeline.dom.spec.ts` — array-op catalog, element-pipeline editor, and scope-rooted object/file coverage (array-ops, ADR-0026, frontend)
- `docs/decisions/ADR-0026-workflows-array-transform-operations.md` — this feature's design record (the six ops, the descriptor-tracking walker, scoped `element`/`index`, terminal-by-construction, the fail-closed matrix/caps, and the accepted `map |> array_at` runtime-typing limitation)
- `docs/decisions/ADR-0025-workflows-variable-typesystem-phase4-arg-variables.md` — this phase's design record (the write-split/resolver-pre-resolves split, the cycle-free depth cap, the `refCtx` write split, the deferred trigger-gate wiring and parent-Save-gate UX follow-ups) plus its Phase 4b addendum (the per-category `ArgVariablePolicy` gate replacing the value-typed-only rule) — completes the four-phase variable-typesystem rework
- `app/modules/Variables/Services/VariableCatalog.php` — the form-independent catalog composition (`variableTypes()`/`operations()`/`globalVariables()`/`globalValues()`/`customFunctionOperations()`/`flatType()`/`descriptorOptionKeys()`) extracted from `WorkflowVariableCatalogService`, which now delegates to it (R2 PR-1a, ADR-0030)
- `app/modules/Variables/Contracts/AiTextGenerator.php` — the ai-text generation seam the relocated `VariableResolver` depends on instead of naming `WorkflowAiTextService` directly; `WorkflowAiTextService implements` it (bound in `WorkflowsModuleServiceProvider`), a template preview binds a no-op instead (R2 PR-1a, ADR-0030)
- `tests/Unit/Variables/VariableResolverCharacterizationTest.php` — the byte-identical-resolution pin for the R2 PR-1a down-move (directives, pipelines incl. a `fn:<uuid>` custom function, flat tokens, if-blocks, the structured union, and `@[ai-text]` via a fake `AiTextGenerator`), plus the `slots`-root-is-inert-for-a-workflow pin (ADR-0030)
- `docs/decisions/ADR-0030-variable-engine-down-move.md` — the R2 PR-1a design record: why `VariableResolver`/`VariableCatalog` relocate into Variables, the `AiTextGenerator` inversion, and the `ROOTS` superset
- `app/modules/Generator/` — the new module (R2 PR-1b, sub-stage 1 "Templatki") that consumes this document's engine over its own `slots.<name>` root; depends on `Variables` only, never `Workflows` — see `docs/backend/generator-api.md` for its full endpoint contract and `docs/decisions/ADR-0031-generator-module-templates.md` for its design record
- `app/modules/Workflows/Exceptions/StepSuspended.php`, `Steps/SuspendableWorkflowStep.php`, `Steps/GenerateContentStep.php`, `Jobs/WorkflowRunResumeJob.php`, `Contracts/WaitResolver.php`, `Services/WaitResolverRegistry.php`, `Services/GenerationSessionWaitResolver.php`, `Listeners/ResumeWaitingRunOnSessionTerminal.php`, `Support/RealQueueConnection.php`, three new `workflow_runs` columns (central `2026_08_04_000000_add_waiting_columns_to_workflow_runs_table.php` + tenant mirror) — R2 sub-stage 5: the generic suspend/resume engine (produces `WorkflowRunState::WAITING` for the first time) + the `generate_content` step consuming it; `app/modules/Generator/Services/SessionAutomationService.php`, `SessionContentProjector.php`, `GeneratedImageExporter.php`, `Enums/SlotScopePolicy.php` on the Generator side. See "Steps: `generate_content`" and "Suspend/resume engine" above and **ADR-0039-workflow-suspend-resume-and-generate-content.md**

## Planned / deferred (not implemented)

- ~~**Wait-for-approval resume**: `WorkflowRunState::WAITING` is declared but never produced.~~ —
  **DONE (R2 sub-stage 5, ADR-0039), no longer deferred.** The engine that produces `WAITING` is
  generic (not approval-specific) — see "Suspend/resume engine" above. Its first and only consumer
  today is `generate_content`, not an approval step; a future `start_approval`-style suspending step
  would reuse the same mechanism (register one `WaitResolver`, implement `resume()`) rather than need
  new engine work. Kept struck through so a reader of an older snapshot understands the change.
- **Manual cancellation of a `waiting` run**: `WorkflowRunState::CANCELLED` is declared but no cancel
  action exists yet — a `generate_content` step that has parked a run cannot be cancelled from the UI
  (the run detail's waiting panel says so explicitly). Unaffected by R2 sub-stage 5.
- **Live push on the run detail page for a `waiting` run.** Unlike the Generator chat's own
  `useSessionSettle()` websocket subscription, the workflow run detail's waiting panel is an HONEST
  SNAPSHOT taken at load — elapsed time is "as of the last read", advanced only by an explicit Refresh
  button. Not built as a deliberate scope cut, not an oversight.
- **Per-part granular `generate_content` outputs.** The step publishes ONE assembled `content` string
  and one `image_file_ids` list — a later step cannot address one specific part's text/image
  individually the way a session's own chat UI can. Would need a richer output shape.
- ~~**A bot delegating a workflow-driven generation.** ADR-0036's bot-delegation overlay
  (`SlotScopePolicy::Bot`) and this feature's automation seam (`SlotScopePolicy::Automation`) are
  sibling trust boundaries today, not composed — a `generate_content` step cannot hand its session to a
  bot mid-run.~~ — **DONE (2026-07-31, ADR-0039 addendum), no longer deferred.** The step's `bot_id`
  config field (see the `generate_content` section above) names the author before the run starts, not
  mid-run: the bot is resolved and the SAME delegation overlay (`SessionDelegationService::
  applyDelegation()`) is stamped the moment the session is created, before the slot fill.
  `SlotScopePolicy::Automation` (the slots) and the bot overlay (the voice/look) now compose on the SAME
  session — the workflow's own `slots` mapping still supplies every input; the bot never fills a slot
  itself. Kept struck through so a reader of an older snapshot understands the change.
- **More than 2 `generate_content` steps per workflow.** A deliberate cap
  (`StoreWorkflowRequest::GENERATE_CONTENT_MAX`) bounding a single run's worst-case AI fan-out and total
  wait time, not a technical ceiling of the engine — an author who needs more splits the work across
  multiple workflows.
- **Bot-authored submission tracking**: `source` cannot express "a bot filled this form in" —
  see the Accepted residual risks section. Needs a new column, not just morph-derived logic.
- **Descriptor-aware runtime typing for a `map`-produced array** (array-ops, ADR-0026): recovering
  `map |> array_at |> <op>`'s true (non-enum) scalar element base at RUNTIME — today it validates at
  write time but fails closed at runtime, see "g. Array transform operations" and the Accepted
  residual risks entry above. Needs `OperationExecutor` (deliberately pure/contextless per
  ADR-0013) to carry descriptor state end-to-end, a materially larger change than this feature's
  scope, not attempted here.
- ~~Operations pipeline for the typed variable system~~ — **DONE (SB1/SB2, ADR-0013), no longer
  deferred.** A directive and a value-or-variable reference now both transform their resolved
  value through the shared 68-op `OperationExecutor` at run time (string ops, date
  arithmetic, arithmetic, per-option mapping, and — since ADR-0014 — mapping into a destination
  field's fixed choice set); ADR-0009 §2's "deferred" consequence is explicitly reversed. Kept
  struck through so a reader of an older snapshot of this doc understands the change.
- **Per-tenant error isolation in the sweep commands**: see the accepted-risk note above.
- **Public holiday awareness**: `day.special:last_working_day` (and every other axis/mode) has NO
  concept of a public holiday calendar — a computed "last working day" or any other fire date
  that lands on a holiday still fires normally. Would need a holiday-calendar data source (and
  almost certainly a per-workspace/per-locale one), a real scope increase, not a tweak.
- **Rolling intervals, every-N-weeks, one-off dates, and sub-minute cadences**: the v2 vocabulary
  has no rolling interval axis (a cadence phased from an arbitrary start rather than a wall-clock
  grid — e.g. "dokładnie co 90 minut", "co 2,5 godziny"), no "every N weeks" axis, no single
  one-off-date cadence, and no sub-minute grid. All four are named explicitly in the AI-assist's
  honest-unsupported list (`ScheduleAssistAgent::semanticCaveats()`) rather than silently
  approximated. `every_n_days`/`every_n_hours`/`every_n_minutes` WITH an optional time-of-day
  window ARE supported (see the TIME/DAY axis tables) — do not confuse these with the unsupported
  rolling-interval case.
- **`TIME` real runtime semantics** (Phase 2 of the variable-typesystem rework): condition
  operators, resolver/evaluator/executor support, and a flat wire representation beyond the
  current `text` degrade. `VariableType::TIME` (phase-1a) is catalog/descriptor-only
  today — see the Accepted residual risks note above and ADR-0022.
- **Presence-op write validation on a non-text reference** (Phase 2): `PipelineValidator::walkPipeline`'s
  exact-type gate does not yet special-case `coalesce`/`is_present`/`is_null`/`assert_present` the
  way the runtime executor already does — see the Accepted residual risks note above and
  ADR-0022.
- **~~Defensive `normalizeInput` default arm~~ — SHIPPED 2026-07-24 (safety batch), and it was
  NOT unreachable.** A stored condition's `source_type` is `tryFrom`'d raw by
  `WorkflowConditionEngine`, so a legacy/hand-written `time`/`object` row DID reach the exhaustive
  `match` and threw out of the form-submission gate. `normalizeInput()` and
  `WorkflowVariableResolver::coerce()` now fail soft on an unsupported base type. Shipped in the
  same batch: the executor's ARRAY-shaped argument readers (`enum_in`/`multi_includes_*`'s
  `values`, `enum_to_*`'s `mapping`, `match_to_choice`'s `rules`) used to consume an unresolved
  `{kind:'variable'}` union AS DATA — which could OPEN a gate — and now reject it as malformed via
  one shared reader and one shared shape predicate
  (`App\Modules\Variables\Support\ValueOrVariable`). See ADR-0022's amendment.
- **Write-time `date_format` pattern validation** (Phase 2 hardening, still deferred):
  `date_format`'s `pattern` arg is validated at write time only as a generic string, not against
  the safe-token whitelist — a malformed pattern is only caught at RUN time (fails soft), never a
  `422`. See ADR-0022.
- **Update: the items immediately above are STILL deferred — Phase 2 (ADR-0023, this
  revision) shipped structural types instead.** `object`/`array<object>` containers and the `file`
  composite (see "Structural descriptor: object containers & the file composite") turned out to be
  the next batch; none of those items were addressed by it. They remain deferred to a
  later, unnumbered phase — EXCEPT the `normalizeInput` default arm, which the 2026-07-24 safety
  batch shipped once it turned out to be reachable (struck through above).
- **Repeater / multi-file per-element LOOP execution** (deferred to R2-Generator, named explicitly
  by ADR-0023): a repeater now has its own `array<object>` catalog entry and a file's composite
  subfields are individually referenceable (Phase 2a/2b), but nothing added a way to iterate a
  repeater's elements or a multi-file answer — no per-element path, no loop binding. This needs
  R2-Generator's own element-cardinality / output-binding design, not an incremental extension of
  the catalog-visibility work this phase did.
- **Computed consts** (Phase 3, ADR-0024; renamed from "computed globals" — ADR-0028): a const that
  DERIVES its value from another const, a trigger field, or a step output — e.g. a const that
  doubles another const's numeric value — rather than holding a plain stored literal. `Constant`
  (renamed from `WorkflowGlobal`) is LITERAL-only; a computed const would need its own
  dependency-graph and cycle-detection design (the same class of work a fenced if-block, a
  value-or-variable pipeline, or — since this document was last updated — a custom function's own
  reference graph needed, see "Custom functions" above), not a byproduct of the CRUD/catalog wiring
  this phase shipped. See ADR-0024 Context.
- **Frontend authoring for array-of-object / nested object-children consts** (Phase 3, ADR-0024):
  `ConstantTypeValidator` already accepts a nested object/array/enum child inside an object
  const's `fields`, and `array:true` on an `object` base, when sent directly to
  `POST /api/consts` — `ConstantEditorDrawer.vue`'s type builder does not offer either
  combination yet (object-field children are scalar-only; the array toggle is disabled for an
  object base, with an in-UI note). A pure frontend follow-up whenever real authoring demand shows
  up, not blocked on a backend change.
- **Richer function argument call-controls** (ADR-0029): a custom function's own argument, when
  called from another pipeline, offers a literal control only for `number`/`boolean`/`date` —
  `enum`/`multi`/`file`/`object`/`time` all fall back to a plain stringifiable text box rather than a
  type-appropriate picker (an enum option list, a file picker). The function still executes
  correctly for any declared arg type; only the CALL-SITE authoring UI is narrower. See "Custom
  functions" above and ADR-0029 Decision 11.
- **Default argument values for custom functions** (ADR-0029): every declared arg is required at
  every call site today — no per-arg default the caller may omit. Real future work if authoring
  demand shows it is needed, following the same design shape ADR-0022's per-reference `default`
  needed for an ordinary variable reference.
- **Trigger-gate (`WorkflowConditionEngine`) argument-variables** (Phase 4, ADR-0025): an operation
  argument may be a variable in a value-or-variable pipeline (`create_task.deadline`/`.priority`,
  `create_form_report.submissions_from`/`.submissions_to`), but NOT in a `form_submitted` condition-
  tree pipeline — rejected at write, and the trigger gate's runtime (`WorkflowConditionEngine`) calls
  `OperationExecutor` directly with no resolver/pre-resolution pass at all. Wiring it would
  need a resolver dependency (or an equivalent pre-resolution pass) the condition engine has never
  had — a genuinely separate structural change, not a byproduct of this phase. See ADR-0025.
- **Parent ops-modal Save gate for a nested argument-variable mismatch** (Phase 4, ADR-0025): a
  type-mismatched argument-variable shows its own local "action required" skin in the frontend
  editor, but the OUTER field's modal Save button is not (yet) disabled by it — `pipelineSatisfies()`
  only inspects each pipeline step's output type, never a step's `args`. The backend `422`
  (`PipelineValidator::validateArgVariable()`) stays fully authoritative regardless, so
  nothing invalid can be persisted; this is a pure frontend UX follow-up. See ADR-0025.
