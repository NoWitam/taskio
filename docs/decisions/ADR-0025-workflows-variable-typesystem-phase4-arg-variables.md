# ADR-0025 — Workflows variable type-system: operation arguments as variables (Phase 4, final)

**Date:** 2026-07-22 (created)
**Status:** Accepted
**Module:** `App\Modules\Workflows`
**Relates to:** ADR-0021 (Phase 0: the composable catalog + the `ROOTS` whitelist,
`resolveValueOrVariable()`, and the write-time reference-index machinery this phase reuses verbatim
for an argument slot — no second resolution/validation mechanism was built), ADR-0022 (Phase 1: the
`{kind:'variable'}` union, the per-reference `default`, and the NUL-mask injection-guard pattern an
argument-variable rides unchanged), ADR-0023 (Phase 2: the third slice of the same rework —
structural types an argument-variable's `ref` may target), ADR-0024 (Phase 3: the `globals` root,
one of the three whitelisted roots — alongside `trigger`/`steps` — an argument-variable may resolve
against), ADR-0013 (the original SB1 pipeline batch — `WorkflowOperationExecutor` extracted as a
pure, contextless transformer shared by the condition engine and the resolver, an invariant this
phase preserves rather than breaks), ADR-0009 (typed variable system: one identity, two
serializations; the "no expression language, ever" invariant this phase's cycle-free,
whitelist-only argument references stay inside)

---

## Context

Phase 0 (ADR-0021) made the catalog composable and fixed the 3-point recipe for a new reference
ROOT. Phase 1 (ADR-0022) added the `descriptor` spine, `TIME`, per-reference defaults, and 5
presence/date-format ops. Phase 2 (ADR-0023) added `object`/`array<object>` containers and the
`file` composite. Phase 3 (ADR-0024) added `globals`, a third reference root holding user-created
literal constants. Every one of those phases grew WHAT a reference could point at, or WHAT SHAPE a
value could carry. None of them touched WHERE inside a pipeline a reference could appear — an
operation's ARGUMENT (`num_add`'s `value`, `date_add_days`'s `value`, `text_append`'s `value`,
`enum_to_choice`'s `mapping`, …) has, since the very first pipeline batch (ADR-0013), always been a
constant an author TYPED ONCE at authoring time. A field's own TOP-level value could be a variable,
and a pipeline could TRANSFORM that value through a chain of operations — but every operation in
that chain only ever accepted literal, hand-typed arguments. "Add however many days the referenced
field says" or "append the value of another field" was inexpressible: the number of days, or the
text to append, had to be baked into the config forever.

Phase 4 closes that one remaining gap — and, by design, is the LAST phase this rework needs: a
VALUE-TYPED operation argument may now itself be the exact same value-or-variable union a top-level
field already carries, recursively (an argument's own pipeline may carry another argument-variable,
and so on, depth-capped). Two sub-batches shipped together on the same working tree: **4a**
(backend — the resolver pre-resolution + write validation) and **4b** (frontend — the recursive
editor slot). This completes the four-phase variable-typesystem rework that began at ADR-0021.

## Decisions

1. **A value-or-variable union is now legal in ANY value-typed operation argument slot — gated by
   one new method, `WorkflowOperationArgType::variableValueType()`, the SINGLE source both the
   write-validator and the runtime resolver read.** It returns the matching `WorkflowVariableType`
   for `TEXT`/`NUMBER`/`BOOLEAN`/`DATE` and `null` for `SELECT`/`SOURCE_OPTION`/`SOURCE_OPTIONS`/
   `SOURCE_MAP`/`CHOICE_RULES`/`CHOICE_FALLBACK` — a real, not merely incidental, boundary: those
   controls' allowed values are constrained to a SOURCE or DESTINATION field's fixed option set
   (e.g. `enum_to_choice`'s `mapping`, `match_to_choice`'s `rules`/`fallback`), and a runtime
   variable's value cannot be checked against that set ahead of time. `argVariableValueType()` in
   `resources/js/next/ui/editor/extensions/operationHelpers.ts` mirrors it exactly on the frontend
   (the same four cases in, `null` for everything else out).

2. **The executor (`WorkflowOperationExecutor`) is UNTOUCHED by this phase — it still only ever
   receives literal arguments — because `WorkflowVariableResolver` pre-resolves every
   variable-shaped argument to a literal BEFORE each op runs, at all three pipeline call sites, by
   RECURSIVELY calling the SAME `resolveValueOrVariable()` a top-level field already uses.** New:
   `resolvePipelineArgs()` → `resolveStepArgs()` → `resolveArgVariable()` → `isVariableArg()`, and
   one new `int $argDepth` parameter threaded through `resolveValueOrVariable()`/
   `resolveVariableUnion()`. `resolveArgVariable()` looks up the argument's declared type via
   `variableValueType()` (Decision 1) and calls `resolveValueOrVariable($field, $context,
   $expectedType, $argDepth + 1)` — literally the same function `create_task.deadline`'s own
   `{kind:'variable'}` value already resolves through, so an argument-variable's OWN pipeline (if it
   has one) is resolved by the SAME `resolveVariableUnion()`, which itself calls
   `resolvePipelineArgs()` again for that pipeline's ops. The three call sites are
   `resolveVariableUnion()` (a field's own pipeline), `applyDirectivePipeline()` (a text directive's
   pipeline), and `evaluateBranchCondition()` (an if-block condition's pipeline) — each now runs
   `resolvePipelineArgs($pipeline, $context, $argDepth)` immediately before
   `$this->executor->execute(...)`. The executor's contextlessness is pinned by
   `WorkflowOperationExecutorTest::test_a_variable_union_arg_reaching_the_executor_fails_closed`: an
   UNRESOLVED `{kind:'variable', ref}` array handed to the executor as an arg is read by e.g.
   `withNumberArg()`'s `is_numeric()` guard, which is false for an array — the op fails closed, never
   crashes, never treats the union as a value.

3. **No cycle is possible by construction — an argument-variable's `ref` can only point at CONTEXT
   DATA (`trigger`/`steps`/`globals`, the resolver's existing `ROOTS`), never at another argument's
   own DEFINITION — so the sole bound needed is finite nesting DEPTH, capped by ONE new shared
   constant read identically by both sides.** `ConditionTreeLimits::MAX_ARG_VARIABLE_DEPTH = 3` — a
   top-level field's own pipeline is depth 0; an argument inside it sits at depth 1; that argument's
   own pipeline (if any) is itself depth 1, so an argument inside IT sits at depth 2; and so on.
   `resolveArgVariable()` fail-softs to the argument's coerced `null` at `$argDepth + 1 > MAX` (never
   a crash — the op then fails closed on the empty argument, collapsing only that one branch);
   `WorkflowConditionTreeValidator::validateArgVariable()` rejects the identical boundary with a
   `422` under the offending argument's own key. Pinned exhaustively on both sides:
   `WorkflowVariableResolverTest::test_arg_variable_nesting_within_the_depth_cap_resolves` /
   `test_arg_variable_nesting_beyond_the_depth_cap_fails_soft` (3 levels resolve — `'t'→'tt'→'ttt'`,
   prefixed to `'Bttt'`; a 4th fails soft to `null`, cascading through the fail-closed op above it)
   and `WorkflowStepValuePipelineValidationTest::test_rejects_an_op_argument_variable_nested_beyond_the_cap`
   (a 4-level chain 422s under the deepest argument's own `...pipeline.0.args.value` key).

4. **Injection safety is INHERITED, not re-invented: a resolved argument value is used LITERALLY by
   the (unchanged) executor, and its output re-enters the SAME NUL-mask stash every resolved value
   already rides, at any nesting depth.** `resolvePipelineArgs()` runs strictly before the executor
   call at each of the three sites (Decision 2), so by the time `WorkflowOperationExecutor::execute()`
   runs, every argument is already a plain literal — the shape it has always accepted; no new masking
   mechanism exists. Pinned by
   `WorkflowVariableResolverTest::test_arg_variable_value_with_reference_like_bytes_is_not_re_interpreted`
   — an argument-variable resolving to a value that literally contains
   `{{trigger.fields.priority}}` is appended by `text_append` and renders that text completely
   verbatim, never re-scanned as a second reference.

5. **Write-time validation threads a new `?array $refCtx` (the reference index + form-availability
   flag) and `int $argDepth` through the existing pipeline walk, validating an argument-variable with
   the EXACT SAME apparatus a top-level ref already gets — and the deliberate `$refCtx === null`
   split is what keeps a CONDITION-tree pipeline (and, by extension, the trigger gate) literal-only,
   matching its unchanged runtime.** `WorkflowConditionTreeValidator::validateValuePipeline()`/
   `walkPipeline()`/`validateArgs()`/`validateArg()` all gained the two parameters; a new
   `validateArgVariable()` (+ `validateArgVariableRef()` + `refFullPath()` + `isVariableArg()`)
   checks: the arg control is value-typed (Decision 1), the ref is a KNOWN entry in
   `$refCtx['index']` (the SAME `WorkflowVariableCatalogService::referenceIndex()` a top-level ref is
   checked against), the ref's catalog type equals the argument's declared type, and — when it
   carries its own `pipeline` — that sub-pipeline is validated RECURSIVELY through the SAME
   `validateValuePipeline()`, one `$argDepth` deeper. `StoreWorkflowRequest::validateVariablePipeline()`
   (the ONE caller of the value-pipeline path — `create_task.deadline`/`.priority`,
   `create_form_report.submissions_from`/`.submissions_to`) now passes its already-built `$refCtx`
   and `argDepth: 0`. The CONDITION-TREE path (`validatePipeline()`, reached from
   `validateCondition()` for a `form_submitted` gate clause) calls `walkPipeline()` with NO override,
   so `$refCtx` stays `null` there — `validateArg()`'s `$refCtx !== null` gate never fires, so an
   argument-variable union falls through to the ordinary LITERAL checks (e.g. a `text` arg's
   `is_string($value)`, false for an array) and is rejected as malformed. This is deliberate, not an
   oversight: `WorkflowConditionEngine` (the trigger gate's RUNTIME) calls
   `WorkflowOperationExecutor::execute()` DIRECTLY — it has no `WorkflowVariableResolver` dependency
   at all, by its own original design — so wiring argument-variables into the write validator without
   ALSO wiring that runtime path would let an author save a gate condition that always fails closed
   at every run. Leaving both sides off keeps write and runtime in lockstep.

6. **The frontend mirrors the recursion through ONE new slot on the shared, host-agnostic pipeline
   editor — which gains only the DECISION of whether to offer an argument-variable, never the UI
   itself — keeping the actual recursive value-or-variable control entirely inside the
   Workflows-owned component that already has the domain types.**
   `resources/js/next/ui/editor/extensions/VariablePipelineEditor.vue` (shared, no dependency on any
   host's types) gained a `depth` prop (default 0) and computes `canOfferArgVariable =
   !!slots.argVariable && depth < MAX_ARG_VARIABLE_DEPTH` per value-typed arg; when true it renders
   the host's `#argVariable` scoped slot (`{ arg, value, depth: depth+1, setValue, disabled }`); when
   false (no slot — conditions/markdown — or at/over the cap) it renders the new
   `PipelineArgLiteralInput.vue`, an EXACT extraction of the previous inline literal controls (same
   model coercions: `number ?? 0`, `date ?? ''`). `resources/js/next/pages/workflows/ValueOrVariableField.vue`
   is the ONLY host that fills the slot today — with ITSELF, recursively: its `#argVariable` template
   renders a nested `<ValueOrVariableField>` one `depth` deeper, translating the raw argument storage
   to/from its own `WorkflowFieldValue` union via `argToUnion()`/`unionToArg()` (a literal argument
   UNWRAPS to its bare value, a variable argument passes the union straight through), with
   `:result-types` set to the argument's OWN declared type. `DateOrVariableField.vue` and every
   operations-modal-enabled field in `WorkflowStepCard.vue` (`priority`, `deadline`,
   `submissions_from`/`submissions_to`) forward a new `arg-variables` pool prop — the SAME show-all
   variable pool their own `:variables` already uses — down to `ValueOrVariableField`; neither owns
   any new UI. `WorkflowConditionModal.vue` (the condition-tree pipeline editor) and the markdown
   editor's if-block/directive panels render `VariablePipelineEditor` WITHOUT the `argVariable` slot
   at all, mirroring the backend's `$refCtx === null` split exactly (Decision 5) — an author is never
   shown a control the backend would reject.

7. **A literal argument serializes byte-identically to before this phase — no `{kind}` wrapper, no
   new key — a REGRESSION anchor pinned on both sides.** The new TS types (`ArgVariableRef`,
   `ArgVariableValue`, `VariableArgValue`) only WIDEN a pipeline step's existing `args` value union to
   ALSO allow the variable shape; the pre-existing literal shapes (`string | number | boolean |
   string[] | Record<string,...> | ChoiceRule[]`) are untouched. Pinned by
   `WorkflowVariableResolverTest::test_op_argument_literal_is_unchanged_backcompat` (backend) and
   `pipelineArgVariable.dom.spec.ts`'s "a LITERAL text arg … serializes byte-identically — a raw
   string, no wrapper" (frontend).

8. **Known, accepted follow-up: a type-mismatched argument-variable surfaces LOCALLY but does not yet
   gate the parent operations-modal's Save — the backend stays fully authoritative regardless.** A
   nested `ValueOrVariableField`'s own `fieldSatisfied`/`is-error` danger skin is computed
   PER-INSTANCE from ITS OWN saved pipeline, so it correctly shows its own "action required" pill.
   But the OUTER field's `modalTypeSatisfied` (which disables the modal's Save button) is
   `pipelineSatisfies()`, which only inspects each STEP's `outputType` — it never descends into a
   step's `args`, so a nested argument-variable's own dissatisfaction cannot reach it; the nested
   field's `update:typeError` event is emitted but is not bound by the `#argVariable` template, so it
   never reaches the outer field's error channel either. Nothing INVALID can actually be persisted —
   `WorkflowConditionTreeValidator::validateArgVariable()` (Decision 5) rejects the same mismatch with
   a `422` no matter what the Save button allowed — so this is a UX polish gap, not a soundness one.
   Tracked as a follow-up, not fixed in this phase.

## Consequences

- **Positive.** This completes the four-phase variable-typesystem rework (ADR-0021 → ADR-0022 →
  ADR-0023 → ADR-0024 → this ADR). The recursion cost almost nothing NEW: the resolver reuses
  `resolveValueOrVariable()`/`resolveVariableUnion()` recursively instead of a second walker, the
  validator reuses `validateValuePipeline()` recursively instead of a second one, injection safety is
  inherited from the existing NUL-mask (no new guard), and cycle-freedom by construction means the
  ENTIRE safety mechanism is one shared integer constant — no visited-set, no graph. The frontend can
  never author a config the backend would reject (the depth cap and the value-typed-only gate are
  mirrored byte-for-byte, `MAX_ARG_VARIABLE_DEPTH = 3` / `variableValueType()` ↔
  `argVariableValueType()`). Every config saved before this phase is unaffected —
  `resolvePipelineArgs()` is a no-op map over a pipeline with no argument-variables (pinned by the
  literal-unchanged regression tests on both sides).
- **Trade-off (accepted): the trigger gate (`WorkflowConditionEngine`) is deliberately NOT wired.**
  An argument-variable in a condition-tree pipeline is rejected at write (Decision 5) and, were one
  to reach the runtime some other way, fails closed rather than crashing (Decision 2's executor pin
  covers this case too). Wiring it would mean giving the condition engine a resolver dependency (or
  duplicating `resolvePipelineArgs()`) it has never had — a genuinely separate, larger structural
  change than this phase's scope, left for a future batch if real demand shows up.
- **Trade-off (accepted): the parent operations-modal Save is not gated by a nested
  argument-variable's own type mismatch (Decision 8).** A UX-only gap; the backend `422` is
  authoritative either way, so nothing unsound can be persisted.
- **Trade-off (accepted): two defensive-only branches exist that the write validator already makes
  unreachable through normal use** — `resolveArgVariable()` returning bare `null` when
  `variableValueType()` is `null` (an option/map/rules control), and the equivalent write-side
  rejection in `validateArgVariable()`. Kept as defense-in-depth (the module's existing posture —
  e.g. the executor's own fail-closed doctrine even though the validator already checks types), not
  because either is expected to be reached by anything the write path accepted.
- **Rejected: giving the executor context/resolver awareness so it could resolve argument-variables
  inline.** Would break the "pure, contextless transformer" invariant that lets
  `WorkflowConditionEngine`, `WorkflowVariableResolver`, and any future caller share identical
  operation semantics without ALSO sharing a resolution mechanism (ADR-0013's original design).
  Pre-resolving in the resolver, before the executor ever sees an argument, keeps that boundary
  intact (Decision 2).
- **Rejected: a bespoke recursive validator/resolver implementation for argument-variables, separate
  from the existing value-or-variable machinery.** `resolveValueOrVariable()`/`validateValuePipeline()`
  already ARE "resolve/validate a value-or-variable union against a declared type" — calling them
  recursively for an argument slot needed only a depth parameter, not a second implementation
  (Decisions 2 and 5).
- **Rejected: an unbounded, visited-set/cycle-detected depth guard.** Unnecessary complexity — an
  argument-variable's `ref` can only ever point at context DATA, never at another argument's
  definition, so there is no graph to detect a cycle in; a bare depth counter is the correctly-sized
  bound (Decision 3).
- **Rejected: building the recursive value-or-variable UI directly into the shared
  `VariablePipelineEditor.vue`, instead of a host-filled slot.** Would couple the shared,
  host-agnostic editor library (used by mentions/AI-text/if-blocks too) to Workflows' own
  `WorkflowFieldValue`/`CatalogVariable` types, breaking the "this shared editor keeps NO dependency
  on the workflow page" invariant the component already documents about itself. The slot pattern
  keeps the MECHANISM (whether to offer it, the depth cap) shared while the UI stays owned by the
  host that has the domain types (Decision 6).

See `docs/backend/workflows-api.md` (the new "Operation arguments as variables (Phase 4, additive)"
subsection under "The typed variable system", plus the Planned/deferred and Related files updates),
`resources/js/next/docs/pages/WorkflowsPage.vue` ("The typed variable system" and "Frontend module"
sections), and `resources/js/next/ui/editor/README.md` ("Variable pipeline (shared editor)") for the
full wire contract and in-app docs mirror this phase shipped.
