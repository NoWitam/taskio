# ADR-0028 — Consts rename: `workflow_globals` → `consts`, `WorkflowGlobal` → `Constant`, a new top-level "Variables" nav — the runtime wire stays `globals.*`

**Date:** 2026-07-26 (created)
**Status:** Accepted
**Module:** `App\Modules\Variables` (persistence moves here), `App\Modules\Workflows` (unaffected at
runtime — see Decision 4)
**Relates to:** ADR-0027 (the module extraction this rename is built on top of — Phase 2 of the same
program), ADR-0024 (Phase 3 of the original variable-typesystem rework — the ADR this one supersedes the
persistence/URL details of; see that ADR's addendum for the pointer back to this one), ADR-0021 (the
`globals` root was already named there as a planned catalog root — this ADR does not touch that
decision), ADR-0009 (typed variable system: one identity, two serializations — the identity this rename
leaves untouched)

---

## Context

ADR-0024 shipped `WorkflowGlobal` — a workspace-scoped, user-created, typed LITERAL constant, persisted
in the Workflows module and reachable at `POST/GET/PUT/DELETE /api/workflow-globals`, referenced from any
workflow as `globals.<key>`. ADR-0027 then extracted the type system and pipeline engine the whole
feature is built on into a new, lower-layer `App\Modules\Variables` module. Once that module existed, two
things about the ORIGINAL `WorkflowGlobal` naming stopped fitting:

1. **The obvious short name for this concept, `Const`, is a PHP reserved word** — a class, or a route
   parameter, cannot be named `Const`/`const`. The feature needed a name that read naturally in the UI
   copy ("Consts", "Stałe") without colliding with the language.
2. **The persistence itself is a Variables concept now, not a Workflows one** — `WorkflowGlobal` living in
   `App\Modules\Workflows\Models` no longer matched where the type system it is typed against actually
   lives, and its URL (`/workflow-globals`) tied it to the Workflows module's own URL namespace even
   though, conceptually, a constant has never been Workflows-specific — any future consumer of the
   Variables engine (R2 Generator/Templates, per the product roadmap) should be able to reference the
   same constants.

This ADR records the rename that followed: table, model, URL, and module all move; a NEW top-level
"Variables" (PL "Zmienne") frontend area is introduced to hold it (plus custom functions, ADR-0029)
instead of leaving it as a Workflows sub-page. **The one thing this ADR does NOT rename is the runtime
WIRE** — every already-stored workflow that references `globals.<key>` keeps resolving, byte-for-byte,
exactly as before.

## Decisions

1. **Table rename: `workflow_globals` → `consts`, via a reversible `Schema::rename`, both
   central (shared-database) and tenant (own-database) schemas — NOT a drop/recreate.** No column, index,
   or constraint changes: `unique(workspace_id, key)` (central) / `unique(key)` (tenant) carry over
   untouched.

   ```php
   // database/migrations/2026_07_26_000000_rename_workflow_globals_to_consts.php (central)
   // database/migrations/tenant/0001_01_01_000047_rename_workflow_globals_to_consts.php (tenant mirror)
   public function up(): void   { Schema::rename('workflow_globals', 'consts'); }
   public function down(): void { Schema::rename('consts', 'workflow_globals'); }
   ```

   Being reversible (not a drop) means no data is lost either direction, and the mechanism itself is the
   ADR-0018/ADR-0017-precedented "structural rename via `Schema::rename`" pattern this codebase already
   uses elsewhere. A dev environment must run `migrate:fresh` + `tenants:migrate` to pick this up (the
   same operational note ADR-0024's own migration carried).

2. **Model rename: `WorkflowGlobal` → `Constant`, moved from `App\Modules\Workflows\Models` to
   `App\Modules\Variables\Models`.** `Const` (without the `ant`) is the PHP reserved word the table name
   itself dodges by shortening to `consts`; the MODEL needed a real class identifier, so it took the full
   word, `Constant`, with an explicit `$table = 'consts'` (Eloquent's own pluralization convention would
   otherwise guess `constants`, not `consts`). `HasCreator`, `TenantAware`, `HasUuids`, `HasFactory` are
   unchanged; the `descriptor`/`value` JSON casts are unchanged.

3. **URL rename: `/workflow-globals` → `/consts`, moved into the Variables module's own
   `routes/api.php`, registered by `VariablesModuleServiceProvider::boot()`.** The apiResource's route
   PARAMETER is pinned to `{constant}` rather than the Laravel default (which would singularize `consts`
   to the reserved word `const`):

   ```php
   // app/modules/Variables/routes/api.php
   Route::apiResource('consts', ConstantController::class)
       ->parameters(['consts' => 'constant'])
       ->only(['index', 'show', 'store', 'update', 'destroy']);
   ```

   Every other shape is a straight lift-and-rename from the ADR-0024 `WorkflowGlobalController`/
   `Store`/`UpdateWorkflowGlobalRequest`/`WorkflowGlobalResource`/`WorkflowGlobalPolicy`/
   `WorkflowGlobalService`/`WorkflowGlobalTypeValidator` quintet — now `ConstantController`/
   `Store`/`UpdateConstantRequest`/`ConstantResource`/`ConstantPolicy`/`ConstantService`/
   `ConstantTypeValidator`, all under `App\Modules\Variables`. Authorization is unchanged: workspace
   membership gates read (`viewAny`/`view`, `$user !== null` — membership itself is enforced upstream by
   `ResolveWorkspace`/`WorkspaceScope`), the creator gates mutation
   (`ChecksRecordOwnership::ownsOrManagesSystemRecord`, in practice always the creator since a constant is
   always user-created). No soft-delete/restore, unchanged from ADR-0024 — a delete is still permanent,
   and a workflow that already embeds a since-deleted constant's `globals.<key>` still fails SOFT to
   `null`/`''` at run time (the module's existing "stale targeting id is a safe no-op" doctrine).

4. **The RUNTIME WIRE `globals.*` is DELIBERATELY PRESERVED byte-identical — this is the whole point of
   the ADR, stated as its own decision, not a side effect.** Every one of the following stayed
   UNTOUCHED by the rename:

   | Wire surface | Stays |
   |---|---|
   | `WorkflowVariableResolver::ROOTS` | `['trigger', 'steps', 'globals']` |
   | The run-context key `WorkflowStepRunner::run()` injects | `'globals' => <flat {key: value} map>` |
   | A catalog variable's `source` | `'globals'` |
   | A catalog variable's `path` | `'globals.<key>'` |
   | `WorkflowConditionEngine::GLOBALS_ROOT` | `'globals'` |
   | `ConstantResource`'s reference field | `'reference' => 'globals.' . $this->key` |
   | The frontend's `VariableSource` union member | `'globals'` |
   | The frontend's path-prefix check | `path.startsWith('globals.')` |

   A stored directive, a `{kind:'variable', ref:{source:'globals', path:'globals.<key>'}}` union, and a
   flat `{{globals.<key>}}` token all keep resolving through the exact same whitelist entry and the exact
   same `Arr::get` lookup they did before this ADR — the resolver, the condition engine, and the catalog's
   `source`/`path` emission needed **zero code changes** for this decision; only the TABLE the value is
   fetched from, and the MODEL class doing the fetching, changed underneath them.

   **Pinned by `tests/Feature/ConstantWireCompatTest.php`** (a characterization test written specifically
   for this rename, run against the renamed `Constant` model):
   - `test_a_stored_globals_reference_still_resolves_to_the_constant_value_after_the_rename` — a condition
     tree carrying `source: 'globals.nazwa_marki'` still resolves through
     `WorkflowConditionEngine::passes()` to the constant's stored value.
   - `test_the_catalog_still_emits_the_globals_source_and_path_for_a_constant` — `forContext()` still
     emits `{source: 'globals', path: 'globals.nazwa_marki'}` for a `Constant` row.
   - `test_the_constant_resource_still_exposes_the_globals_reference` — `GET /api/consts` still returns
     `data.0.reference === 'globals.nazwa_marki'`.

5. **A NEW top-level "Variables" (PL "Zmienne") frontend area replaces the Workflows sub-page.** Router:

   ```
   /next/variables                → redirects to /next/variables/consts
   /next/variables/consts         → ConstantsView.vue   (formerly WorkflowGlobalsView.vue, under /next/workflows/globals)
   /next/variables/functions      → FunctionsView.vue   (new, ADR-0029)
   ```

   `VariablesModuleLayout.vue` is the shell (inner sub-nav + its two pages), mirroring the shape every
   other top-level `next` module shell already uses. The FE authoring/editor stack was renamed to match
   the backend: `WorkflowGlobalsView.vue`/`WorkflowGlobalEditorDrawer.vue`/`WorkflowGlobalValueField.vue`/
   `WorkflowGlobalRow.vue` → `ConstantsView.vue`/`ConstantEditorDrawer.vue`/`ConstantValueField.vue`/
   `ConstantRow.vue`, and the client-side mirror of the type validator,
   `workflowGlobals.ts`'s `WorkflowGlobalTypeValidator` mirror, became `consts.ts`'s mirror of
   `ConstantTypeValidator`. The store now calls `/api/consts`. Nothing about the AUTHORING GRAMMAR itself
   changed in this rename (the 6 authorable bases, the object/array rules, the NUL-byte reject) — see
   ADR-0024 for that; this ADR only moved where it is reached from and what it is called.

## Consequences

- **Positive.** The rename gives the feature a name that reads naturally in UI copy without colliding
  with a PHP reserved word, and moves its persistence to sit next to the type system it is typed against
  (ADR-0027) instead of inside the Workflows module that merely CONSUMES it. The new top-level Variables
  area gives constants (and, immediately, functions — ADR-0029) a home that is not scoped to "workflow
  configuration," which matches how they are actually used: a constant or a function is workspace-wide,
  reusable by anything that can run a pipeline, not a Workflows-only concept.
- **Positive.** Because the wire stayed untouched (Decision 4), this rename needed **zero data migration
  for stored workflow content** — every condition, directive, and value-or-variable pipeline already
  saved against `globals.<key>` keeps resolving unchanged. The only migration this ADR required was the
  reversible table rename itself.
- **Trade-off (accepted): two different names now denote the same concept, deliberately.** The
  table/model/URL/nav vocabulary is "consts"/"Constant"/"Consts"/"Stałe"; the runtime/wire vocabulary is
  still "globals" (`globals.<key>`, `GLOBALS_ROOT`, `source:'globals'`). A maintainer has to know both —
  this is named here explicitly, not left for someone to discover by surprise later. The alternative
  (renaming the wire too) is the rejected option directly below.
- **Rejected: renaming the runtime wire to `consts.*` to match.** Would require a DATA migration rewriting
  every stored condition/directive/pipeline that references `globals.<key>` across every workspace (shared
  and every own-database tenant), for a rename that is purely organizational (a table/URL/nav change, not
  a semantic one) — real risk and real migration cost for a cosmetic consistency gain. Rejected; the wire
  decoupling from the table/model/URL name is deliberate (the exact same posture ADR-0024's own model
  docblock already stated: "the RUNTIME WIRE stays byte-identical: a reference is still `globals.<key>`
  ... a deliberate decoupling of the wire from the table name").
- **Rejected: keeping `/workflow-globals` as the URL while still moving the model into Variables.**
  Considered, since the wire itself did not need to move — but rejected because the whole point of giving
  constants a new top-level nav area was to stop presenting them as a Workflows sub-feature; leaving the
  URL under the Workflows namespace while everything else moved would have been an inconsistent half-step.
- **Rejected: a `Const` model / `const` route parameter, accepting whatever escaping Laravel/PHP would
  need.** PHP's reserved-word restriction on bare identifiers (class names, `define`d constants) is not
  worth working around with an escaped/quoted identifier for a name a plain word (`Constant`/`consts`)
  already expresses cleanly.

## Addendum note (recorded here, and cross-referenced from ADR-0024)

ADR-0024 ("user-created LITERAL global variables, Phase 3") remains the authoritative record for the
FEATURE itself — the LITERAL-only scope, the authorable-type boundary, the NUL-byte injection guard, the
deferred computed-const idea, and the deferred FE authoring depth (nested object children,
array-of-object) all still apply unchanged, just to a renamed model. ADR-0024 carries a short addendum
pointing here for the persistence/URL/nav rename; this ADR does not restate the feature's own design.

See `docs/backend/workflows-api.md` (the "Consts" endpoints under "## Endpoints", renamed from "Workflow
GLOBALS") for the current wire contract, and `docs/decisions/ADR-0027-variables-module-extraction.md` for
the module boundary this rename's persistence now lives behind.
