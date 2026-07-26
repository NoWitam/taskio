# ADR-0024 — Workflows variable type-system: user-created LITERAL global variables (Phase 3)

**Date:** 2026-07-22 (created)
**Status:** Accepted
**Updated:** 2026-07-23 — Addendum below: an object global's own DECLARED FIELDS join the write-side
reference index (closes a picker/validator asymmetry a later UX batch's tree picker would otherwise
have opened)
**Updated:** 2026-07-26 — Superseding addendum below: the persistence this ADR shipped (`WorkflowGlobal`,
table `workflow_globals`, `/api/workflow-globals`) was RENAMED and MOVED — see ADR-0028
**Module:** `App\Modules\Workflows` (persistence moved to `App\Modules\Variables` — see the 2026-07-26 addendum)
**Relates to:** ADR-0021 (Phase 0: the composable, form-independent catalog + the resolver
whitelist recipe this phase applies to a genuinely new root — `globals` was already named there
as a planned example), ADR-0022 (Phase 1: the `descriptor` spine this phase's stored type reuses
verbatim, and the NUL-mask injection-guard pattern this phase extends to a new persisted value
source), ADR-0023 (Phase 2: the `object` descriptor-only base + its flat-wire degrade this phase's
`object`-based global rides unchanged), ADR-0015 (polymorphic creator ownership — the
`HasCreator`/`ChecksRecordOwnership` machinery this phase's authorization reuses), ADR-0009
(typed variable system: one identity, two serializations; the "no expression language, ever"
invariant this phase's LITERAL-only scope stays inside)

---

## Context

Phase 0 (ADR-0021, `3e75f11`) made the variable catalog composable and form-independent, and
fixed the recipe for teaching the resolver a new reference ROOT — explicitly naming "user-created
**global** variables" as one of the catalog additions that recipe was built for. Phase 1
(ADR-0022, `43c5cbd`) added the structured `descriptor` spine every typed value now carries. Phase
2 (ADR-0023, `4281905`) added the `object` structural base and the `file` composite. None of the
three, though, added a second SOURCE of variables — every variable so far derives from a
workflow's own trigger/form/steps, resolved fresh from the run's own payload. Phase 3 is the first
slice that lands a variable with NO trigger/form/step origin at all: a value a user TYPES ONCE,
in a dedicated workspace-level screen, and then references by a stable `globals.<key>` path from
ANY workflow — a brand name, a budget number, a hashtag list, reused instead of re-authored every
time a workflow needs it.

**This slice is deliberately scoped to LITERAL storage only.** A global holds exactly the value a
user typed, typed against the SAME `descriptor` grammar Phase 1/2 already established (so the
catalog/resolver need no new concept to understand it) — never a value computed from another
global, a trigger field, or a step output, and never a value re-evaluated at read time. That
narrower scope is what makes this a one-slice-sized addition: a literal is a stored constant with
no dependency graph, so there is nothing to detect a cycle IN. A **computed** global (`budget_2x`
= `globals.budget` × 2, say) is real, plausible future demand — explicitly **PLANNED**, not
rejected — but needs its own dependency-graph and cycle-detection design the same way a fenced
if-block or a value-or-variable pipeline needed its own design; it is not a byproduct of this
batch. See "Planned / deferred" in `docs/backend/workflows-api.md` for where this is tracked.

## Decisions

1. **A global is LITERAL-only — no computed values, no references to other variables, no cycle
   detection, this slice.** `WorkflowGlobal` (`app/modules/Workflows/Models/WorkflowGlobal.php`)
   stores exactly two JSON columns: `descriptor` (the authoritative type, in the SAME shape
   `WorkflowVariableType::descriptor()` already produces for every other catalog variable) and
   `value` (a literal matching it — scalar, list, object, or null). Nothing in the model, the
   validator, or the resolver ever reads `value` as an expression, a template, or a pointer to
   another row. This keeps the module's foundational "no expression language, ever" invariant
   (ADR-0009 / ADR-0021) intact for a THIRD kind of catalog source, not just the original two.

2. **`globals` is a new catalog source + resolver root, landed through the EXACT 3-point recipe
   ADR-0021 prescribed — no new resolver mechanism was needed.** (1) **Whitelist**:
   `WorkflowVariableResolver::ROOTS` grows `['trigger', 'steps']` → `['trigger', 'steps',
   'globals']` — the one gate every serialization (directive, flat token, `{kind:'variable'}`
   union, if-block condition) already shares, so this single line is what makes `globals.<key>`
   resolvable at all. (2) **Run-context binding**: `WorkflowStepRunner::run()` adds one more
   top-level key to the context it hands the resolver — `'globals' =>
   $this->catalog->globalValues()`, a flat `{<key>: <stored value>}` map fetched once per run.
   (3) **Catalog source**: `WorkflowVariableCatalogService::globalVariables()` is merged into
   `forContext()`'s `variables` array UNCONDITIONALLY — not gated by trigger type or form, since a
   global has neither — so it is present for `form_submitted`, `schedule`, and even a bare
   `forContext(null)` call (pinned by
   `WorkflowGlobalCatalogTest::test_globals_are_form_independent_across_triggers`). Ordinary
   `Arr::get`-based lookup (via the resolver's existing `readContext()`) needed no change at all —
   a `globals.<key>` path resolves exactly like `trigger.fields.x` already did, fail-soft to
   null/`[]` on a missing key (a deleted or foreign-workspace global's stale reference is a safe
   no-op, mirroring the module's existing "stale targeting id" doctrine — see Consequences).
   `referenceIndex()` (the write-time pipeline type-flow gate) and `runtimeTypeMap()` (the runtime
   base-type recovery table) were ALSO extended to enumerate every `globals.<key>` path, so a
   value-or-variable pipeline may target a global with full type-checking, exactly like a
   trigger/step reference. `source` on a global's catalog entry literally equals its root
   (`'globals'`), mirroring `'trigger'`/`'steps'` — no special-casing needed anywhere this triple
   is read.

3. **Persistence mirrors the `workflows` table's own dual central/tenant shape; authorization
   mirrors `WorkflowPolicy` — read is workspace-membership, mutation is (in practice) strictly
   creator-only.** The central migration
   (`database/migrations/2026_07_22_000000_create_workflow_globals_table.php`) carries a nullable,
   indexed `workspace_id` and `unique(workspace_id, key)` (the reference namespace is per
   workspace); the tenant mirror
   (`database/migrations/tenant/0001_01_01_000046_create_workflow_globals_table.php`) omits
   `workspace_id` entirely and makes `key` unique across the whole tenant database (one tenant DB
   = one workspace, the same convention every other tenant-mirrored table already follows).
   `creator_id`/`creator_type` are nullable, carry no cross-database FK, and are auto-stamped by
   the shared `HasCreator` trait's `bootHasCreator()` boot hook (ADR-0015) — neither
   `WorkflowGlobalDTO` nor `WorkflowGlobalService` sets them explicitly. `WorkflowGlobalPolicy`
   gates `viewAny`/`view` on `$user !== null` only (workspace membership itself is enforced
   upstream by `ResolveWorkspace` + `WorkspaceScope`, the same split `WorkflowPolicy`'s own
   form-less catalog path already uses) and `update`/`delete` through the shared
   `ChecksRecordOwnership::ownsOrManagesSystemRecord()` trait. That trait's workspace-owner
   fallback (for a SYSTEM record with no human creator) is dead code for a global in practice — a
   global's creator is always a human `User` (there is no engine author for one) — so mutation is,
   as shipped, strictly creator-only, exactly like a `Workflow`. **Unlike `Workflow`, a global has
   no soft-delete/restore** — the model does not use `SoftDeletes`, the migration has no
   `deleted_at`, and `WorkflowGlobalService::delete()` is a hard `Model::delete()` (pinned by
   `WorkflowGlobalCrudTest::test_can_delete_own_global`'s `assertDatabaseMissing` check) — see
   Consequences for the accepted trade-off.

4. **`WorkflowGlobalTypeValidator` is the SINGLE authority for the authorable-type boundary,
   shared by both `Store`/`UpdateWorkflowGlobalRequest`.** Its `AUTHORABLE_BASES` constant is
   exactly `[text, number, boolean, date, enum, object]` — `file` (a copy-on-attach composite tied
   to an actual uploaded Disk file; a global has no upload flow to originate one from) and `time`
   (no runtime semantics AT ALL yet, ADR-0022 Decision 2, even for a form field) are rejected at
   the `descriptor.base` key; `multi` was never a base to begin with (it is `enum` + `array:true`,
   the same convention every other catalog variable already uses). The value check recurses
   through the identical shape the descriptor declares: an `array:true` descriptor requires a
   `value` that is a list, each element validated against the element (non-array) descriptor; an
   `object` descriptor requires every DECLARED field to validate recursively (a missing field
   reads as `null`, gated by that field's own `nullable`) and rejects any UNDECLARED key outright;
   an `enum` value must string-equal one of `descriptor.options[].key`; a `nullable:false`
   descriptor rejects a `null` value. One accepted looseness, stated explicitly: `number` accepts
   a numeric STRING (`is_numeric`), not only a PHP int/float — looser than what the shipped
   `NumberInput`-backed control ever emits, reachable only from a hand-crafted request, left as-is
   rather than tightened for zero present benefit (mirrors the same latitude a `date` value's
   Carbon-parseable-STRING check already has).

5. **A global's `value` must contain no NUL byte — a write-time guard closing an injection-mask
   assumption gap this exact persistence shape opens.** The resolver's existing NUL-delimited
   placeholder masking (ADR-0013 / ADR-0022) — which stops a resolved directive's looked-up value
   from being re-scanned as a second-order `{{...}}`/`@[...]` reference — has always been able to
   assume every resolved value is NUL-free, because every OTHER value source (a form answer, a
   step output) is ultimately backed by a Postgres `text`/`varchar` column, which rejects a raw
   NUL byte at the SQL layer outright. `workflow_globals.value` is a plain `json` column, not
   `jsonb` — per the type validator's own docblock, a `json` column "permits the NUL byte" where a
   `jsonb` column would not: `jsonb` decomposes its input into its own internal text
   representation at write time, which cannot embed a raw NUL byte, so it is rejected outright;
   `json` simply stores validated JSON syntax as-is. `workflow_globals.value` is therefore the
   first value source in this module that COULD otherwise carry a genuine NUL byte end to end and
   forge the masking placeholder's own delimiter. `WorkflowGlobalTypeValidator::containsNulByte()`
   walks the value recursively (any string key or value, at any depth) and rejects it under the
   `value` key BEFORE the type check runs (pinned by
   `WorkflowGlobalCrudTest::test_a_value_carrying_a_nul_byte_is_rejected`), closing that one gap.
   Once stored, a global's value is exactly as safe as any other resolved value: a value that
   merely LOOKS like a reference (`{{trigger.fields.secret}}`, `@[variable]...`) still renders
   completely literally, in every one of the 4 resolution shapes (standalone/embedded flat token,
   standalone/embedded directive) — pinned by
   `WorkflowVariableResolverTest::test_a_global_value_with_reference_like_bytes_is_not_re_interpreted`,
   using a fixture literally named `globals.evil`.

6. **A stored descriptor's flat wire `type` is recovered by a new, single-purpose inverse method —
   `WorkflowVariableType::fromDescriptor()` — and an `array<scalar>` global rides the PRE-EXISTING
   `multi` flat type rather than inventing a new one.** Every other catalog variable derives its
   `descriptor` FROM a `WorkflowVariableType` (walking a form schema); a global does the opposite —
   it derives its flat `type` FROM a stored `descriptor` — so `fromDescriptor()` is genuinely new,
   the literal inverse of the existing `descriptor()` method. Its mapping: `base:'object'` →
   `OBJECT` (then degrades to `text` on the flat wire via the pre-existing Phase-2 `flatType()`
   tripwire — an object-shaped global rides the identical descriptor-only path a form SECTION
   already does, with zero new cases); `base:'enum'` → `MULTI` when `array:true`, else `ENUM`; any
   scalar base (`text`/`number`/`boolean`/`date`) with `array:true` → **`MULTI`** — the ONE
   pre-existing array-carrying flat type, reused rather than duplicated (pinned by
   `WorkflowGlobalCatalogTest`: a `text` + `array:true` global's flat `type` is `'multi'`, its
   `descriptor.base` stays `'text'`, and it carries NO `enumOptions` key at all, since it is not
   enum-based). This is why `array<text>` (a hashtag list, say) needed no new resolver/evaluator/
   executor case, no new FE union member, and no new coercion rule — `WorkflowVariableResolver::coerce()`'s
   existing `multi → array passthrough` rule and every existing exhaustive `match` already handle
   it.

7. **The frontend mirrors the SAME authorable grammar the backend validates, but deliberately
   ships a NARROWER authoring depth than that backend grammar actually accepts — flagged in-UI,
   not silently hidden.** `resources/js/next/pages/workflows/workflowGlobals.ts` is the client-side
   mirror of `WorkflowGlobalTypeValidator` (same `AUTHORABLE_BASES`, same recursive
   value-vs-descriptor checks, same `SAFE_KEY` regex) feeding
   `WorkflowGlobalEditorDrawer.vue`'s type builder (a `SegmentedControl` over the 6 bases +
   orthogonal `array`/`nullable` toggles + an enum-options editor + an object-fields editor). Two
   combinations the BACKEND validator already accepts recursively — a nested object/array/enum
   child inside an object's `fields`, and `array:true` on an `object` base (an array-of-object) —
   are NOT offered by the editor today: an object field's own type picker is restricted to
   `OBJECT_FIELD_BASES` (`text | number | boolean | date` only, a `<Select>` with no object/enum/
   array choice), and the `array` toggle is disabled outright whenever `base === 'object'`, with a
   visible note (`workflows.globals.form.arrayObjectNote`: *"A list of objects isn't supported here
   yet — model each object separately."*). This is a FRONTEND authoring-surface decision, not a
   second backend rule — sending either shape directly to `POST /workflow-globals` would validate
   and persist exactly as any other well-formed descriptor does; only the editor's own picker
   narrows what a human can build through it today. On the catalog-picker side (independent of
   authoring), `resources/js/next/pages/workflows/workflowVariables.ts`'s `expandVariables()`
   special-cases `source === 'globals'` FIRST — before the container/file expansion rules a Phase-2
   variable goes through — so a global is always offered as ONE self-contained, relabelled entry
   ("Globals › {name}") for EVERY base, including `object`: unlike a form SECTION (which
   contributes nothing new to the picker, its leaves already being flat elsewhere), a global's
   whole value IS the reference, so it is never expanded or dropped.

## Consequences

- **Positive.** Every workflow in a workspace now shares one flat, form-independent namespace of
  typed constants, reusable without re-authoring per workflow — closing a real, common authoring
  gap (a brand name, a budget figure, a hashtag list typed once). ADR-0021's "adding a root is a
  3-point change" recipe holds up unchanged on its first application to a genuinely new, persisted,
  user-authored source (not merely a new trigger/form/step-derived one) — nothing about the
  resolver's whitelist gate, fail-soft lookup, or masking/injection guards needed to change.
  Injection safety for a brand-new, genuinely user-controlled persisted value source was achieved
  by reusing existing machinery almost entirely (the resolver's masking pass) plus one small
  write-time guard (the NUL check) closing the one gap that machinery didn't already cover for
  free — not a parallel security mechanism. Zero new resolver/evaluator/executor cases and zero new
  FE union members were needed for ANY authorable global shape, including an array or an object one
  — `fromDescriptor()` deliberately routes every shape onto a pre-existing flat-wire case
  (`MULTI` for an array, the Phase-2 `OBJECT` tripwire for an object).
- **Trade-off (accepted): computed globals — referencing another global, a trigger/step value, or
  any derived expression — are explicitly OUT of this slice, deferred, not rejected.** See Context.
  A future computed global would still have to stay inside the module's "no expression language,
  ever" invariant (ADR-0009/ADR-0021) — at most a bounded reference/pipeline shape, mirroring a
  value-or-variable field, never a general expression — and would need its own dependency-graph and
  cycle-detection design this slice deliberately does not build.
- **Trade-off (accepted): a global has no soft-delete/restore, unlike `Workflow`.** A delete is
  permanent. A workflow that already embeds a since-deleted global's `globals.<key>` reference
  fails SOFT to `null`/`''` at run time (the exact same fail-soft lookup a missing/foreign path
  already produces) rather than erroring the run — consistent with the module's existing "stale
  targeting id is a safe no-op" doctrine for a deleted form — but the deleted global's stored value
  itself cannot be recovered afterward. Accepted as unnecessary complexity for a first slice; can
  be added later if real usage shows a need.
- **Trade-off (accepted): the frontend ships a narrower authoring depth than the backend validator
  accepts.** Object-field children are scalar-only and array-of-object is not offered by the
  editor (Decision 7) — a deliberate, reviewable-diff-sized authoring surface, not a backend
  limitation; the backend's own recursive validator already accepts both shapes, so a future
  FE-only batch can widen the picker without another backend change. Flagged in-UI rather than
  silently hidden (the `arrayObjectNote` copy).
- **Trade-off (accepted): `workflow_globals.workspace_id` (central migration) is a plain nullable
  indexed UUID column, not a declared foreign key** — unlike `workflows.workspace_id`
  (`foreignIdFor(Workspace::class)`). Both are scoped identically at the QUERY layer by the same
  `WorkspaceScope`/`TenantAware` machinery every tenant-aware model already uses, so this is a
  schema-level inconsistency with the sibling `workflows` table, not a tenancy gap — noted here
  rather than left undocumented.
- **Rejected: modeling a global as just another catalog "form field" variable, or inventing a
  placeholder form/trigger context to hang it off of.** A global has no trigger/form context at
  all — forcing one would misrepresent what it is. A genuinely new root is exactly the shape
  ADR-0021 designed the 3-point recipe for (and named "global variables" as a planned example of).
- **Rejected: allowing `file` or `time` as an authorable global base.** `file` is a copy-on-attach
  composite intrinsically tied to an uploaded Disk file — a global has no upload flow to originate
  one from, so "a file global" has no coherent meaning. `time` has no runtime semantics at all yet
  (ADR-0022 Decision 2) even for a form-derived variable; authoring a literal of a type the engine
  cannot yet act on would be a dead end, not a convenience.
- **Rejected: a brand-new "array" flat wire `type` for an array-typed global.** Would touch every
  exhaustive `match` in the resolver/evaluator/executor and the frontend's closed type union, for a
  shape (`array<scalar>`) the wire vocabulary already expresses losslessly through the pre-existing
  `MULTI` case — `fromDescriptor()`'s whole job is routing onto what already exists, not growing
  the flat vocabulary.
- **Rejected: shipping the FULL recursive FE authoring grammar (nested object children,
  array-of-object) in this same slice.** An avoidable scope increase for this batch; the backend
  grammar already accepts it, so widening the editor is a pure FE-only follow-up whenever real
  authoring demand shows up, not something blocked on another backend change.

See `docs/backend/workflows-api.md` (the new "Workflow GLOBALS" endpoints under "## Endpoints", and
"The `globals` root — user-created LITERAL constants (Phase 3, additive)" under "## The typed
variable system") for the full wire contracts this phase shipped, and
`resources/js/next/docs/pages/WorkflowsPage.vue` (the "Workflow Globals" subsection under "The
typed variable system") for the in-app docs mirror.

---

## Addendum (2026-07-23) — an object global's interior is now write-validatable (closes the picker/validator asymmetry)

Decision 2 above extended `referenceIndex()`/`runtimeTypeMap()` to enumerate every `globals.<key>`
TOP-LEVEL path, so a value-or-variable pipeline could already target `globals.brand` as a whole. It
did NOT, at the time, enumerate an OBJECT global's own DECLARED FIELDS (`globals.address.city`, say)
as their own referenceable paths — only the top-level `globals.address` entry (flat type `text`, the
Phase-2 `object` degrade) existed in the index. A later UX batch's variable picker
(`VariableTreePicker.vue` / `descriptorChildNode()` in `workflowVariables.ts`) expands a
self-contained object — an object global is one, via `isObjectContainer()` — into its declared
`descriptor.fields` as pickable composed child nodes, exactly the way a FILE composite's subfields
already expand. Without a matching write-side change, the picker could construct a
`globals.address.city` ref the write validator would then REJECT as "not a known variable for this
step" — a picker/validator asymmetry, not a soundness bug (nothing invalid could ever persist,
since the validator is authoritative regardless of what the picker offers), but a broken authoring
loop: pick a field the UI shows you, save, get a 422.

`WorkflowVariableCatalogService::objectSubfieldTypeMap()` (see the
`docs/decisions/ADR-0023-workflows-variable-typesystem-phase2.md` addendum for the mechanism) closes
this: `addReferenceEntry()`/`addTypeMapEntry()` now enumerate a non-array `object` descriptor's own
`fields` recursively, so `globals.address.city` (and any deeper nesting an object global declares) is
a KNOWN entry in the reference index and the runtime type map — a value-or-variable pipeline may
target it with full write-time type-checking, exactly like `globals.address` itself. The RUNTIME side
needed no change at all: `globals.address.city` was already a plain whitelisted `Arr::get` over the
injected `globals` map, which resolves a nested key without any special-casing — only the WRITE-side
index was missing the entry. The picker and the validator now agree on every node the tree can emit a
ref for.

This is scoped to the non-array case only, matching ADR-0023's own boundary: an `array:true` global
(an array-of-object, when hand-authored directly against the API — Decision 7's frontend gap) is
unaffected by this addendum, and per-element access into it remains out of scope, exactly as
ADR-0023 established for a form repeater. See
`docs/decisions/ADR-0023-workflows-variable-typesystem-phase2.md`'s addendum for the shared mechanism
and `docs/backend/workflows-api.md` for the updated wire description.

---

## Addendum (2026-07-26) — persistence renamed: `WorkflowGlobal` → `Constant`, `workflow_globals` →
`consts`, `/workflow-globals` → `/consts`, moved into a new `App\Modules\Variables` module (see ADR-0028)

Once the variable-typesystem rework's TYPE SYSTEM and PIPELINE ENGINE were extracted into a new,
lower-layer `App\Modules\Variables` module (ADR-0027), the persistence this ADR shipped moved with the
rest of the feature's natural home, and was renamed at the same time: `Const` being a PHP reserved word,
the model became `Constant` (table `consts`, still explicit via `$table` since Eloquent's own
pluralization would guess `constants`); the URL became `/api/consts`; the FE gained a NEW top-level
"Variables" (PL "Zmienne") nav area (`ConstantsView.vue` etc., replacing the Workflows sub-page this ADR
originally described) alongside the sibling custom-Functions feature (ADR-0029).

**Every decision recorded above in this ADR — the LITERAL-only scope, the 3-point catalog-root recipe,
the authorable-type boundary (`AUTHORABLE_BASES`, now on `ConstantTypeValidator`), the NUL-byte
injection guard, the no-soft-delete trade-off, and the deferred computed-global / deferred FE authoring
depth — is UNCHANGED by the rename.** Only the class/table/URL names and the module they live in moved.
**The one thing explicitly NOT renamed is the runtime WIRE**: `WorkflowVariableResolver::ROOTS` still
whitelists `'globals'`, the run-context key is still `globals`, a catalog entry's `source`/`path` are
still `'globals'`/`'globals.<key>'`, `WorkflowConditionEngine::GLOBALS_ROOT` is still `'globals'`, and
`ConstantResource` still emits `reference => 'globals.' . $key` — a workflow stored before this rename
keeps resolving its `globals.<key>` references byte-for-byte unchanged, pinned by a dedicated
characterization test, `tests/Feature/ConstantWireCompatTest.php`.

See `docs/decisions/ADR-0028-consts-rename.md` for the full record of the rename itself (why `Const` was
unusable, the reversible `Schema::rename` migrations, the new nav, and the wire-preservation decision and
its trade-offs) and `docs/backend/workflows-api.md` (the "Consts" endpoints under "## Endpoints") for the
current wire contract.
