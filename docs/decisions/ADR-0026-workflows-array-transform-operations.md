# ADR-0026 — Workflows array transform operations: descriptor-tracking walker, scoped element pipelines, terminal-by-construction

**Date:** 2026-07-25 (created)
**Status:** Accepted
**Module:** `App\Modules\Workflows`
**Relates to:** ADR-0009 (typed variable system: one identity, two serializations — the flat/`descriptor`
split this ADR's array element typing rides), ADR-0013 (the pure, contextless `WorkflowOperationExecutor`
+ fail-closed doctrine every array op stays inside), ADR-0021 (the composable catalog + the resolver's
`ROOTS` whitelist an `element`/`index` scope deliberately does NOT join), ADR-0022 (Phase 1 — the
`descriptor` spine this ADR's walker widens from a flat type to a full descriptor), ADR-0023
(Phase 2 — `object`/`array<object>` containers and the `file` composite, the exact element shapes wave 3
teaches the catalog to descend into), ADR-0024 (Phase 3 — `globals`, one of the three whitelisted roots a
scope-rooted object/file element pipeline explicitly REJECTS), ADR-0025 (Phase 4 — operation arguments as
variables; the `ELEMENT_PIPELINE`/`REDUCE_SEED` arg kinds this ADR adds are new `ArgVariablePolicy`
members reusing that same single-source-of-truth pattern, not a parallel mechanism)

> **Note on numbering.** The design brief that scoped this feature (a "master contract" reviewed and
> accepted before implementation) referred to it informally as "ADR-0023 — array transform operations."
> By the time this ADR was written, `ADR-0023` had already been assigned to the Phase 2 structural-types
> decision record (`ADR-0023-workflows-variable-typesystem-phase2.md`, committed earlier on this same
> branch). This document is filed as **ADR-0026** — the next free number — to avoid colliding with an
> existing, already-referenced ADR. No content differs from what was scoped; only the filename/number
> does.

---

## Context

The variable-typesystem rework (ADR-0021 → ADR-0025) gave every workflow reference a `descriptor`
alongside its flat `type`, added structural containers (`object`/`array<object>`/`file`), a `globals`
root, and let any operation argument itself be a variable. None of it gave an author a way to
**transform a whole array** — count its elements, pick one, or run a per-element computation (map a
list to another shape, filter it, sort it, fold it into one value). A repeater answer or a
`globals`-stored list variable could be referenced and passed through file-subfield-style paths, but
never iterated. This gap sat squarely on the path to R2 (Generator/Templates), which needs exactly this
primitive to loop over generated content.

Two things about the existing engine made "just add six more ops" harder than it sounds:

1. **The write-time type-flow walker (`WorkflowConditionTreeValidator::walkPipeline`) tracked a flat
   `WorkflowVariableType`, not a descriptor.** An array op needs to accept "any array, regardless of
   its element's base type" — `array<number>`, `array<enum>`, `array<object>` all satisfy "is an
   array" — which a flat-type gate (`op->inputType() === $currentType`) cannot express: there is only
   ONE flat array-carrying type (`MULTI`), so a flat gate would either reject every element base except
   one, or accept everything indiscriminately and lose the ability to type-check what happens
   downstream of `at`/`map`.
2. **The engine has no scoping concept.** Every existing reference resolves against one of three global
   roots (`trigger`/`steps`/`globals`) via a single whitelist, checked identically everywhere. A
   per-element `element`/`index` binding is fundamentally different: it must be visible ONLY inside the
   one pipeline currently iterating, and invisible — a write-time rejection, not just an empty
   resolution — everywhere else, including the trigger condition tree.

This ADR records the decisions behind closing both gaps, plus the six new operations and their runtime
contract. Delivered in three reviewer-gated waves — **Wave 1** (descriptor-tracking walker + `count`/`at`
over scalar/enum arrays), **Wave 2** (the element-pipeline arg kind + scoped `element`/`index` +
`map`/`filter`/`sort`/`reduce` over scalar/enum arrays), **Wave 3** (`array<object>`/`array<file>` element
access) — each landing BE, FE, tests, and review before the next started. All 6 ops, plus the walker and
scoping mechanism, are now merged: BE 818 / FE 1438 tests green.

## Decisions

1. **The six new operations, and their two shapes.** `array_count`/`array_at` are O(1), whole-array,
   pipeline-less transforms; `array_map`/`array_filter`/`array_sort`/`array_reduce` are higher-order —
   each carries a PER-ELEMENT PIPELINE run once per element.

   | op | input | output | pipeline arg | terminal constraint |
   |----|-------|--------|--------------|---------------------|
   | `array_count` | `array<T>` | `number` | — | — |
   | `array_at` | `array<T>` | `T` (nullable) | signed `index` (1-based, clamped) | — |
   | `array_map` | `array<T>` | `array<U>` | element pipeline (rooted at `T`) | any base `U` — not itself an array |
   | `array_filter` | `array<T>` | `array<T>` | element pipeline (rooted at `T`) | `boolean` |
   | `array_sort` | `array<T>` | `array<T>` | element pipeline (rooted at `T`) | `number` |
   | `array_reduce` | `array<T>` | `U` (base, non-array, non-null) | a typed `seed(U)` + an accumulator pipeline (rooted at `U`) | = seed base `U` |

   Ids are only ever appended (the enum's pinned wire-contract doctrine,
   `WorkflowConditionEngineTest::test_operation_ids_are_the_pinned_wire_contract`): the catalog grows
   77 → 79 with `array_count`/`array_at` (wave 1), then 79 → 83 with `array_map`/`array_filter`/
   `array_sort`/`array_reduce` (wave 2). `WorkflowOperation::isArrayOp()` marks all six; a NEW
   `isCollectionOp()` marks only the four higher-order ones — the walker and the executor both read
   these two predicates to route an array op away from the ordinary flat-type / single-value dispatch
   path, never duplicating the membership list.

2. **The descriptor-tracking walker is the load-bearing change: the write-time type-flow validator
   (and its frontend mirror) now track a full DESCRIPTOR through a pipeline, not a flat type — and the
   swap is a PROVABLE no-op for every existing operation.** `WorkflowConditionTreeValidator::walkPipeline`
   grows a sibling `walkPipelineDescriptor()` carrying `$currentDescriptor` (the `WorkflowVariableType::
   descriptor()` shape: `{base, nullable, array, options?, fields?, elementDescriptor?}`) instead of
   `$currentType`. The per-op INPUT gate is now `opAcceptsDescriptor()`: an array op
   (`WorkflowOperation::isArrayOp()`) accepts iff `$currentDescriptor['array'] === true` — regardless of
   element base — while every other (legacy) op keeps the EXACT old rule, just re-expressed:
   `WorkflowVariableType::fromDescriptor($currentDescriptor) === $op->inputType()`. The per-op OUTPUT is
   `WorkflowOperation::outputDescriptor(array $inputDescriptor, array $args, ?array $terminalDescriptor):
   array` — a NEW method whose `default` arm, covering every op that existed before this ADR, is
   `return $this->outputType()->descriptor();`. Because `fromDescriptor()` is the exact inverse of
   `descriptor()`, that default arm is byte-identical to the old `$currentType = $op->outputType()` for
   every non-array op — a mechanically provable no-op, not merely an intended one. The array ops
   override it: `array_count` → `NUMBER`'s descriptor; `array_at` → the input's ELEMENT descriptor with
   `nullable:true, array:false`; `array_filter`/`array_sort` → the input descriptor unchanged;
   `array_map` → the element pipeline's own TERMINAL descriptor (computed by the caller, recursively
   walking that pipeline) with `array:true, nullable:false`; `array_reduce` → the seed's descriptor
   (`array:false, nullable:false`). The FE mirror, `operationHelpers.ts`'s `resolveType`/
   `computeInputType`/`pipelineSatisfies` (+ step-building), tracks the identical descriptor instead of a
   bare `VariablePrimitive`; `VariableOperationDefinition` gained an optional `resolveOutput(inputDescriptor,
   args, terminalDescriptor)`, defaulting to the old static `outputType` for every scalar op and supplied
   only by the six array ops.

   **Element descriptor derivation** (`WorkflowOperation::elementDescriptorOf()`, private, called by
   `array_at`'s and the walker's element-typing): prefer an explicit `elementDescriptor` when the array
   descriptor carries one (an `array<object>`/`array<file>`/typed `array<scalar>` from a later wave);
   otherwise the element IS the array descriptor collapsed to a single item — a `MULTI` wire value
   `{base:'enum', array:true, options}` yields an ELEMENT descriptor `{base:'enum', array:false,
   options}`, carrying the option list along so `array_at`'s result still validates as a real enum,
   choosable in a downstream `enum_is`/`enum_to_choice` etc. A `map`-produced `array<scalar>`'s element
   descriptor is simply `{base: scalarBase, array:false}`. Nested arrays (`array<array<...>>`) are
   rejected outright — a `map` terminal that is itself an array 422s at write (`validateElementPipeline`'s
   `$isMap` branch) and is never offered as a completable terminal in the FE editor.

3. **Scoped synthetic variables `element`/`index` (source `scope`) are valid ONLY inside an element
   pipeline — never in a global root (`trigger`/`steps`/`globals`), and never in the trigger's
   condition tree — enforced by ONE source-aware helper three engine layers now share.**
   `App\Modules\Workflows\Support\ScopeRef::leaf(array $ref): ?string` is the single predicate for "is
   this ref the synthetic scope" — SOURCE-AWARE: a ref with a real, non-scope `source` (`globals`/
   `trigger`/`step`) is never treated as scope even if its path root happens to be `element`/`index`,
   and only `source: 'scope'` (or an absent source, tolerated for legacy/hand-written rows) roots
   against `ROOTS = ['element', 'index']`. `leaf()` returns the full scope path — `element`, `index`, or
   (wave 3) `element.<subfield>` — keyed on the ROOT segment, so a subfield tail rides along for
   `element` while `index` (a leaf scalar) rejects any `.<sub>` tail. `ScopeRef` exists because THREE
   layers each grew a private copy of this predicate and they had DRIFTED (see the Bug fixed in the
   same batch, below): `WorkflowConditionTreeValidator` (write-time gate), `WorkflowVariableResolver`
   (keeps a scope ref OUT of global pre-resolution), and `WorkflowOperationExecutor` (resolves a scope
   ref per iteration against the runtime overlay). All three now call `ScopeRef::leaf()`/
   `ScopeRef::isScopeUnion()` and can no longer disagree.

   **Runtime.** `WorkflowVariableResolver::ROOTS` is UNCHANGED — `element`/`index` are deliberately NOT
   added there (that would be a fail-OPEN global root). Instead, `WorkflowOperationExecutor` threads a
   SCOPED OVERLAY into the per-element sub-run: `scopeOverlay(array $context, mixed $element, int
   $index): array` returns `['scope' => ['element' => $element, 'index' => (float) $index]] + $context`
   — a separate `scope` key merged alongside (never into) the existing context, resolved by a per-element
   run only. Before the executor calls `execute()` for that element, `resolveScopePipeline()`
   pre-resolves every SCOPE-rooted variable argument in the element pipeline for THIS iteration (an
   argument that is `globals`/`trigger`/`steps`-rooted was already rejected at write time — see Decision
   5 — so only scope refs reach this pass).

   **Write time.** `walkPipelineDescriptor()`/`validateElementPipeline()` gain a `?array $scopeVars`
   param — `{'element': {type, enumOptions}, 'index': {type: NUMBER, enumOptions: null}, 'element.<sub>':
   {...}, ...}` — injected ONLY while walking an element pipeline. `validateArgVariableRef()` consults it
   IN ADDITION TO the ordinary reference index: a scope ref resolves against `$scopeVars`, everything
   else against the whitelisted index exactly as before. A stored ref to `element`/`index` OUTSIDE any
   element pipeline (`$scopeVars === null`) is REJECTED at write with a 422, and — defence in depth — if
   one somehow reached runtime anyway it resolves to `null` there too (the overlay is never present
   outside a per-element sub-run), collapsing the condition to `false`, never a crash.

   **Bug fixed in the same batch (Wave-2 Finding B): the two LEAF-ONLY copies of this predicate, before
   `ScopeRef` existed, flagged ANY ref whose path LEAF was literally `element`/`index` as scope —
   ignoring a real `source`.** A genuine workspace global, trigger field, or step output NAMED
   `element`/`index` (e.g. `globals.index`, `source:'globals', path:'globals.index'`) was
   write-ACCEPTED as an ordinary reference (correct — the write-time gate was already source-aware) but,
   at RUNTIME, mis-flagged as the loop's OWN scope variable by the resolver and the executor: the
   resolver refused to pre-resolve it, and inside an element pipeline it resolved to the CURRENT LOOP
   INDEX instead of the global's actual stored value — a wrong-value substitution silently feeding a
   `filter`/`map` predicate that ultimately gates a condition. `ScopeRef` is the single fix: all three
   sites now agree, byte-for-byte, on what counts as scope.

4. **A new arg kind, `ELEMENT_PIPELINE` (+ `REDUCE_SEED`), carries the per-element pipeline — NOT the
   generic whole-arg value-or-variable machinery Phase 4 (ADR-0025) built.**
   `WorkflowOperationArgType::ELEMENT_PIPELINE` (a list of pipeline steps `{op, args}` — the identical
   wire shape `ChoiceRule.when`/`ArgVariableValue.pipeline` already use) and `::REDUCE_SEED` (a
   self-describing typed literal `{type, value}`, `type ∈ text|number|boolean|date`) are two NEW
   `WorkflowOperationArgType` cases whose `argVariablePolicy()` returns
   `ArgVariablePolicy::elementPipeline()` — a THIRD named constructor alongside `value()`/`option()`/
   `structural()`/`options()`, with both `$refTypes` and `$coerceTo` null (`isStructural()` true), like a
   structural container — but semantically distinct: it is not "one reference supplies a whole map",
   it is "this arg carries a NESTED PIPELINE the write-validator and resolver route through a DEDICATED
   branch keyed on the arg CASE", never the generic per-entry union resolution a `sourceMap`/
   `choiceRules` gets. `array_map`/`array_filter`/`array_sort` each declare ONE `elementPipeline('pipeline')`
   arg; `array_reduce` declares `reduceSeed('seed')` + `elementPipeline('reducer')` — the two-arg model
   (a typed seed literal establishing the accumulator's initial value AND its required type, plus a
   separate reducer pipeline) rather than folding the seed into the pipeline's own first step, so the
   accumulator's type `U` is declared once, up front, and both the write-validator and the executor read
   it from the SAME place before a single element runs.

5. **The two wire shapes for an element pipeline — bare vs. scope-rooted — and why both exist.** A
   scalar/enum element array's element pipeline (waves 1-2) is the BARE list shape,
   `Array<{op, args}>`, rooted implicitly at the element itself (no op can be applied to "nothing", so
   the base is always the current element value). An `array<object>`/`array<file>` element pipeline
   (wave 3) CANNOT root at the whole element — no operation consumes a raw object/file snapshot — so it
   is instead the value-or-variable UNION shape a structured field already uses:
   `{kind:'variable', ref:{source:'scope', path:'element.<field>', type}, pipeline: [...]}`. The ref's
   `path` picks ONE subfield of the current element (a repeater row's field, or one of a file's
   `id|name|type|size|url`); `pipeline` transforms that subfield's value exactly like a bare-list element
   pipeline would transform a scalar element. `WorkflowOperationExecutor::elementRunner()` is the single
   place that recognizes both shapes for a given `pipeline`/`reducer` arg (`ValueOrVariable::isVariable`
   distinguishes them) and normalizes each into one internal `{ref, steps}` runner; a union whose ref is
   NOT a scope leaf is rejected as malformed (a whole-array variable reference makes no sense inside an
   element pipeline). `array_reduce`'s reducer NEVER takes the scope-rooted shape — it always roots at
   the accumulator `U`, so `allowScopeRoot` is `false` for it on both sides; a union there is simply
   rejected the same way a malformed bare-list would be.

6. **Terminal type is enforced BY CONSTRUCTION, never handled as a runtime special case — the owner's
   explicit directive.** `filter` must terminate `boolean`, `sort` must terminate `number`, `reduce`'s
   reducer must terminate the seed's own base `U`, `map` may terminate any single base (never another
   array). Both sides gate identically: the BACKEND write-validator
   (`WorkflowConditionTreeValidator::validateElementPipeline()`) checks `$requiredTerminal` (or, for
   `map`, that the terminal descriptor's `array` flag is not `true`) and 422s a mismatch under the
   pipeline's own key; the FRONTEND editor makes it impossible to even ATTEMPT saving a
   wrong-terminal pipeline — the per-op terminal gate (`argResultTypes()`/`pipelineSatisfies()` in the
   element-pipeline editor) disables "done"/"save" on the embedded editor until the running descriptor
   matches. **A wrong-terminal input can therefore never reach the database.** The ONLY residual at
   runtime is a CORRECTLY-typed pipeline that fails on specific element DATA (unavoidable — a division
   by zero, an unparseable date on one particular element, etc.) — which is not a terminal-type problem
   at all, and is handled by the fail-closed matrix below (Decision 8), a necessary runtime consequence
   rather than an authoring choice.

   **Non-scope arg-variables are REJECTED inside a scope-rooted object/file element pipeline
   specifically (wave 3), for validator/runtime SYMMETRY.** Inside a bare-list (scalar/enum) element
   pipeline, an ordinary op argument may still be an ordinary `globals`/`trigger`/`steps` arg-variable
   (Phase 4/ADR-0025's mechanism, unchanged) — the full `$refCtx` stays in scope there. But inside a
   SCOPE-ROOTED union (the object/file shape from Decision 5), the runtime can resolve ONLY
   `element.<subfield>`/`index` scope refs — `WorkflowVariableResolver::resolveDescriptorArg()`
   short-circuits on a scope variable and `WorkflowOperationExecutor::resolveScopePipeline()` fails the
   WHOLE element sub-run CLOSED on any non-scope union it encounters — so the write-validator
   deliberately validates THAT union's own inner arg-variables against a SCOPE-ONLY reference index
   (empty `index`, `fields_available: true`), rejecting a `globals.*`/`trigger.*`/`steps.*` reference at
   write time rather than letting it be saved and silently fail-closed at every run. This is a
   NARROWER rule than the bare-list case, deliberately: it exists so the write gate and the runtime
   agree exactly, not to generally restrict arg-variables inside array ops.

7. **Fail-closed matrix and caps — every higher-order op's DATA-level failure mode is named, not
   incidental.**
   - `array_map` fails the WHOLE op CLOSED on ANY single element's sub-run failure — never
     drop-and-continue, because the output array's LENGTH must match the input's; silently shortening it
     would itself be a silent data-corrupting side effect.
   - `array_filter` DROPS a failed or non-boolean-terminal element — fail-closed in the sense that an
     unevaluable predicate can never KEEP an element it should have excluded (nor let a garbage result
     open a downstream gate).
   - `array_sort` sends a failed/non-numeric-terminal element's key to `null`, which sorts LAST; ties
     (including multiple failed keys) preserve original relative order — `usort`'s comparator is a
     stable-order tiebreak on the original index, not merely PHP's incidental stability.
   - `array_reduce` KEEPS THE PRIOR ACCUMULATOR on a failed or wrong-terminal-type reducer sub-run —
     never corrupting the accumulator's type or silently substituting a sentinel.
   - `array_count`/`array_at` are pure and total: `count()` never fails; `at`'s failure mode is the
     1-based signed clamp below, never a hard error.

   **Two caps, checked before a single element runs, shared identically by the write-validator and the
   runtime executor** (`app/modules/Workflows/Enums/ConditionTreeLimits.php`): `MAX_ARRAY_ITERATIONS =
   1000` — an array longer than this fails the op CLOSED outright (a hostile/huge stored value, mirroring
   `MAX_PIPELINE_STEPS`'s existing role); `MAX_ELEMENT_PIPELINE_DEPTH = 3` — an element pipeline nested
   beyond this depth (a `map` whose own element pipeline contains another `map`, and so on) is REJECTED
   at write and fails CLOSED at runtime. Both bounds are defined once and read by both sides, so they can
   never drift, exactly like `MAX_ARG_VARIABLE_DEPTH` (ADR-0025) already does for argument-variable
   nesting.

8. **The `at` clamp — 1-based, signed, clamped to the nearest end, total, never throws.**
   `index > 0` (1-based) past the array's length clamps to the LAST element; `index < 0` counts from the
   end (`-1` = last) and, past the start, clamps to the FIRST element; `index === 0` returns the FIRST
   element (treated as equivalent to `1`); an EMPTY array returns `null` regardless of `index` (the
   legitimately-absent-element case — `at`'s output descriptor is always `nullable: true`); a missing or
   non-numeric `index` argument fails the op closed (the index is a required arg, not optional). Pinned
   identically on the backend (`WorkflowOperationExecutor::arrayAt()`) and mirrored by the FE's own
   `Indeks` control defaults.

## Consequences

- **Positive.** The array-transform primitive R2 (Generator/Templates) needs — loop over a list, pick
  or fold from it — now exists as a first-class, type-checked, fail-closed capability, built entirely on
  the engine's existing invariants (pure contextless executor, whitelist-only references, no expression
  language) rather than a parallel mechanism. The descriptor-tracking walker is a pure ADDITIVE widening:
  every non-array pipeline (the 77 ops that existed before this ADR) validates identically bit-for-bit,
  proven by `outputDescriptor()`'s default arm being the mechanical inverse of the old
  `$currentType = $op->outputType()`. `element`/`index` scoping cost the engine no new global-root
  concept — it stays a per-run OVERLAY, never touching `WorkflowVariableResolver::ROOTS` — so nothing
  about the existing whitelist's exfiltration-safety posture changed. Terminal-by-construction means a
  wrong-terminal element pipeline is a CLASS OF BUG THAT CANNOT BE SAVED, not merely one that is caught
  later; the only residual runtime risk is ordinary per-element DATA failure, handled by a fully-named
  fail-closed matrix.
- **Trade-off (accepted): a `map`-produced non-enum scalar array LOSES its true element base at
  runtime, because the executor collapses every array to a normalized `MULTI`/`string[]`.** The pure
  executor (`WorkflowOperationExecutor::toStringList()`/`normalizeInput()`) has no descriptor to carry —
  it is, by design (ADR-0013), a contextless value transformer, not a type-flow analyzer; that job
  belongs entirely to the WRITE-time walker. Concretely: `WorkflowOperation::stepOutputType()` derives
  `array_at`'s runtime type from the INPUT descriptor via `outputDescriptor()`/`fromDescriptor()` — which
  is correct for a DIRECT `<multi source> |> array_at |> enum_is` (the source array's element base, e.g.
  `enum`, survives because it comes straight from the catalog's own descriptor) — but a `map`-produced
  `array<text>` (a non-enum scalar base synthesized MID-PIPELINE) has no descriptor of its own at
  runtime; the executor can only see "an array of strings" and the runtime type stays typed `ENUM` (the
  MULTI-collapse's only scalar-array convention), while the write-time walker correctly typed it `TEXT`.
  The result: `map |> array_at |> text_op` VALIDATES at write time (the walker's descriptor tracking is
  correct) but FAILS CLOSED at runtime (the type mismatch the executor's flat-type gate still enforces).
  This is deliberately NOT papered over by relaxing the global enum/text gate — that would change
  existing, unrelated semantics for the sake of one narrow case. Recovering this would need the pure
  executor to carry descriptor state end-to-end, a materially larger change queued as a named follow-up
  (see "Planned / deferred" in `docs/backend/workflows-api.md`), not attempted in this ADR's scope. The
  DIRECT (non-map-produced) case is unaffected and correct.
- **Trade-off (accepted): `array_at` over an `array<object>`/`array<file>` returns the raw element
  snapshot, but that snapshot cannot be piped through a FURTHER operation.** `at`'s result is usable via
  PATH access (the resolver reads its subfields, e.g. as a value-or-variable target elsewhere), but its
  runtime type degrades to the flat element base, so chaining a downstream OP onto it fails closed. This
  is accepted, not a bug to fix: the entire point of shipping `map`/`filter`/`sort`/`reduce`'s
  per-element pipelines (Decision 5) is that object/file per-element TRANSFORMATION goes through the
  scope-rooted subfield mechanism, not through `at`'s O(1) single-pick.
- **Rejected: giving the executor a descriptor-aware runtime type map so `map`'s true element base
  survives to a later `at`.** Would break the "pure, contextless transformer" invariant `WorkflowCondition
  Engine`/`WorkflowVariableResolver` both rely on sharing without ALSO sharing a type-flow analyzer
  (ADR-0013's original design, reaffirmed by ADR-0025 Decision 2's "executor stays pure" rejection for
  argument-variables). The write-time walker is where type-flow analysis belongs; teaching the runtime
  the same analysis would be a second implementation of it, with its own drift risk.
- **Rejected: letting `element`/`index` join `WorkflowVariableResolver::ROOTS` as a fourth global root.**
  Would be a fail-OPEN global root usable from ANY reference anywhere in a workflow (a condition, a
  top-level field, a directive), not just inside the element pipeline it is meaningful in — exactly the
  class of scope leak `ScopeRef`'s source-aware design exists to prevent. The per-run overlay keeps the
  binding strictly local to the one sub-run it belongs to.
- **Rejected: a bespoke per-op literal reader for the element-pipeline / reduce-seed args, mirroring how
  `sourceMap`/`choiceRules` are handled.** `ArgVariablePolicy::elementPipeline()` deliberately reuses the
  SAME two-null-facet shape `structural()` already established (Phase 4/ADR-0025), routing both to
  `isStructural()`'s existing branch point on both the validator and resolver sides, rather than adding a
  fourth top-level branching concept for what is, at the "does this arg have a whole-arg variable form"
  question, the same answer (no) for a different reason.
- **Rejected: allowing `array_reduce`'s reducer to root at a scope-rooted `element.<subfield>` union like
  map/filter/sort do.** The reducer's base is ALWAYS the accumulator (`U`, the seed's own type) — there is
  no sensible "root at the element instead" reading for a fold, since the whole point of a reducer is to
  combine the accumulator WITH the element, not transform the element alone. `allowScopeRoot: false` for
  reduce keeps this a validator/runtime symmetry statement rather than an arbitrary restriction.
- **Rejected: silently tolerating the leaf-only `element`/`index` name collision (the Wave-2 Finding B
  bug) as an edge case not worth fixing.** A GLOBAL, TRIGGER, or STEP variable genuinely named `index` or
  `element` is a real, unremarkable authoring choice (not a contrived edge case), and the bug's effect —
  silently substituting the loop's OWN index/element value for that global's real stored value inside a
  `filter`/`map` predicate — can silently gate a condition on the WRONG data. `ScopeRef` was built and all
  three call sites migrated to it in the same batch this ADR ships, not deferred.

See `docs/backend/workflows-api.md` ("Array transform operations" under "The typed variable system", and
"g. Array transform operations" under "Runtime operations, if-blocks, and AI text" for the fail-closed
matrix / caps table) for the full wire contract. **The in-app docs mirror
(`resources/js/next/docs/pages/WorkflowsPage.vue`, "The typed variable system") is PLANNED, not yet
written** — every earlier phase of the variable-typesystem rework (ADR-0021 → ADR-0025) added its
matching section there in the same batch; this feature has not yet had that pass. A follow-up
documentation batch should add it, coordinated with the Frontend module owner.
