# ADR-0027 — Variables module extraction: the type system + pipeline engine become a lower-layer module

**Date:** 2026-07-26 (created)
**Status:** Accepted
**Module:** `App\Modules\Variables` (new), `App\Modules\Workflows` (rewired to depend on it)
**Relates to:** ADR-0009 (typed variable system: one identity, two serializations — the identity this
extraction relocates), ADR-0013 (the pure, contextless `WorkflowOperationExecutor` — the invariant this
ADR's `OperationExecutor` continues), ADR-0021 (the composable catalog + the resolver `ROOTS` whitelist
— unchanged by this move), ADR-0022–ADR-0026 (Phases 1–4 of the variable-typesystem rework plus the
array-transform batch — everything they built is what gets relocated here), ADR-0028 (consts rename —
the first thing built ON TOP of this module), ADR-0029 (custom functions — the second)

---

## Context

ADR-0021 through ADR-0026 grew a typed variable/pipeline engine — `WorkflowVariableType`, the 83-op
`WorkflowOperation` catalog, `WorkflowOperationArgType`/`WorkflowOperationArg`, `ArgVariablePolicy`,
`WorkflowOperationExecutor`, `ScopeRef`, `ValueOrVariable`, `UnresolvedArgument` — entirely inside the
Workflows module, because at the time Workflows was its only consumer. R2 (Generator/Templates) and,
sooner, this program's own two additions (workspace CONSTANTS with a shorter public name, and
user-defined FUNCTIONS built from the same operation vocabulary) need that type system and pipeline
engine WITHOUT needing the rest of Workflows — its triggers, steps, schedule machinery, or run engine.
Leaving the engine inside Workflows would force every future consumer to depend on all of that, and
would let the type system silently grow a back-dependency on something Workflows-specific (a condition
operator enum, a trigger type) that a lower-layer module must never carry.

This ADR records a **module extraction**: a new `App\Modules\Variables` module becomes the LOWER layer,
owning the variable TYPE SYSTEM and the pipeline OPERATION ENGINE outright. `App\Modules\Workflows`
becomes the upper layer, depending on Variables for both. The dependency is enforced ONE-WAY: Variables
imports nothing from Workflows, ever — asserted by a standing test, not just a convention.

This is Phase 1 of the larger program this ADR, ADR-0028, and ADR-0029 together record (per the
program's own working numbering: Phase 1 = this extraction, Phase 2 = the consts rename, Phase 3 =
custom functions). Phase 1 is a **PURE, behavior-preserving refactor** — no wire shape, no validation
rule, no runtime outcome changes. It was verified by running the FULL pre-existing backend (~851 tests)
and frontend (~1452 tests, untouched — Phase 1 changed zero frontend files) suites green before Phase 2
and Phase 3 added their own new functionality (and new tests) on top.

## Decisions

1. **A new `App\Modules\Variables` module owns the type system, the pipeline engine, and (from Phase 2
   onward) the Consts + Functions persistence.** Registered as `VariablesModuleServiceProvider` in
   `bootstrap/providers.php`, **BEFORE** `WorkflowsModuleServiceProvider`, so the provider load order
   mirrors the dependency direction:

   ```php
   // bootstrap/providers.php
   App\Modules\Variables\VariablesModuleServiceProvider::class,
   App\Modules\Workflows\WorkflowsModuleServiceProvider::class,
   ```

2. **The dependency is ONE-WAY — Workflows depends on Variables, Variables imports NOTHING from
   Workflows — enforced by a standing architectural test, not just a docblock convention.**
   `tests/Feature/VariablesModuleBoundaryTest.php::test_variables_module_imports_nothing_from_workflows`
   walks every `.php` file under `app/modules/Variables` and asserts none contains the literal string
   `App\Modules\Workflows` — a back-reference would create a cycle and silently defeat the whole point
   of the extraction. A second pin,
   `test_pipeline_validator_reference_sources_match_the_resolver_roots`, asserts
   `PipelineValidator::DEFAULT_REFERENCE_SOURCES` (Variables) is byte-identical to
   `WorkflowVariableResolver::ROOTS` (Workflows) — the write-time whitelist and the runtime whitelist are
   two copies of the SAME list living on two sides of the module boundary, and this test is what keeps
   them from silently drifting apart.

3. **Full symbol rename (the accepted option, over a namespace-only move).** Every class that moved
   into Variables and is genuinely part of the TYPE SYSTEM lost its `Workflow`-prefix, because the prefix
   stops meaning anything once the class lives outside the Workflows module; a handful of already-generic
   names moved unchanged:

   | Old (`App\Modules\Workflows\...`) | New (`App\Modules\Variables\...`) |
   |---|---|
   | `Enums\WorkflowVariableType` | `Enums\VariableType` |
   | `Enums\WorkflowOperation` | `Enums\Operation` |
   | `Enums\WorkflowOperationArgType` | `Enums\OperationArgType` |
   | `DTOs\WorkflowOperationArg` | `DTOs\OperationArg` |
   | `Services\WorkflowOperationExecutor` | `Services\OperationExecutor` |
   | `DTOs\ArgVariablePolicy` | `DTOs\ArgVariablePolicy` (moved, name unchanged) |
   | `DTOs\OperationResult` | `DTOs\OperationResult` (moved, name unchanged) |
   | `Support\ScopeRef` | `Support\ScopeRef` (moved, name unchanged) |
   | `Support\ValueOrVariable` | `Support\ValueOrVariable` (moved, name unchanged) |
   | `Support\UnresolvedArgument` | `Support\UnresolvedArgument` (moved, name unchanged) |
   | `Enums\ConditionTreeLimits` (the 4 PIPELINE caps only — see Decision 4) | `Enums\PipelineLimits` (new name; gains a 5th cap in ADR-0029) |
   | part of `Services\WorkflowConditionTreeValidator` (see Decision 5) | new `Services\PipelineValidator` |

   Genuinely NEW in Variables (no Workflows-side predecessor): `Contracts\OperationDefinition`,
   `Contracts\ElementScopeResolver`, `Contracts\FunctionReferenceLookup`, `Services\OperationResolver` —
   all Decision-6/7 machinery, below. `WorkflowVariableType::operators()` returning bare operator STRING
   ids (not `WorkflowConditionOperator` cases) predates this extraction and is unchanged by it — it is
   exactly what already made the type carry zero back-dependency on Workflows, and is why this move did
   not have to touch that method at all.

   **Rejected: a namespace-only move (keep every `Workflow`-prefixed name, just change its `namespace`
   line).** This was scoped as the fallback/"emergency trim" if the rename proved too large a diff to
   land safely, not the target. It was not needed: Option A (the full rename above) landed cleanly, and a
   `WorkflowOperationExecutor` sitting in `App\Modules\Variables\Services` would be actively misleading —
   it no longer has anything to do with Workflows specifically, and the stale prefix would immediately
   confuse the next reader about which module owns it.

4. **`ConditionTreeLimits` is SPLIT by what it bounds, not moved wholesale.** The four PIPELINE-shaped
   caps — `MAX_PIPELINE_STEPS`, `MAX_ARG_VARIABLE_DEPTH`, `MAX_ELEMENT_PIPELINE_DEPTH`,
   `MAX_ARRAY_ITERATIONS` — moved to the new `App\Modules\Variables\Enums\PipelineLimits`, because they
   bound the PIPELINE ENGINE, which now lives in Variables. The two TREE-shaped caps — `MAX_DEPTH`
   (group nesting) and `MAX_CHILDREN` (children per group) — bound the condition TREE's own recursive
   `{logic, children[]}` shape, which is a Workflows concept (only a `form_submitted` trigger's gate has
   a tree at all), so they STAY in `App\Modules\Workflows\Enums\ConditionTreeLimits`, now a much smaller
   class:

   ```php
   // app/modules/Workflows/Enums/ConditionTreeLimits.php (after the split)
   final class ConditionTreeLimits
   {
       public const MAX_DEPTH = 5;
       public const MAX_CHILDREN = 10;
   }
   ```

   Both classes are read by both a write-validator and a runtime engine on their own side of the split
   (`PipelineValidator` + `OperationExecutor` for the pipeline caps; `WorkflowConditionTreeValidator` +
   `WorkflowConditionEngine` for the tree caps) — the "one constant, read by both sides so a bound can
   never drift between validation and evaluation" doctrine ADR-0025/ADR-0026 already established is
   unchanged by the split, just now spans two classes in two modules instead of one.

5. **`PipelineValidator` is EXTRACTED from `WorkflowConditionTreeValidator` — the tree validator KEEPS
   tree-structure validation and CALLS the new class per leaf pipeline.** Before this ADR,
   `WorkflowConditionTreeValidator` did two genuinely different jobs in one class: walk the condition
   TREE's `{logic, children[]}` shape (structure, depth/children caps, resolving a leaf's `source` against
   the catalog), and walk each leaf's PIPELINE (the descriptor-tracking op walk, per-op input gating +
   output descriptors, argument validation — literals/options/source-maps/choice-rules, argument
   VARIABLES against a reference index, higher-order array ops' element pipelines, and — reused for a
   condition's own optional literal — `validateDefault`). Everything in the second list moved to the new
   `App\Modules\Variables\Services\PipelineValidator`; everything in the first stayed.
   `WorkflowConditionTreeValidator` shrank from a self-contained ~600+-line validator to a 240-line one
   whose entire job is now tree shape + resolving a leaf's `source`/`source_type` against
   `WorkflowVariableCatalogService`, handing the type-flow work off:

   ```php
   // app/modules/Workflows/Services/WorkflowConditionTreeValidator.php (after the split)
   class WorkflowConditionTreeValidator
   {
       public function __construct(
           private WorkflowVariableCatalogService $catalog,
           private PipelineValidator $pipeline,   // ← the Variables-owned engine
       ) {}

       private function validateCondition(...): void
       {
           // ... resolve $type from source_type, validate $source against the catalog ...
           $this->pipeline->validateDefault($validator, $node, $prefix, $type, $enumOptions);
           $this->pipeline->validateConditionPipeline($validator, $node, $prefix, $type, $enumOptions, $refCtx, $this->sourceDescriptor($fields, $node));
       }
   }
   ```

   `StoreWorkflowRequest`'s OTHER pipeline write path — a value-or-variable field's own pipeline
   (`create_task.deadline`/`.priority`, `create_form_report.submissions_from`/`.submissions_to`) — calls
   `PipelineValidator::validateValuePipeline()` directly (it was never routed through the condition-tree
   validator; nothing changed about which class owns which call site, only where the shared type-flow
   logic itself now lives).

6. **Two dependency INVERSIONS keep the one-way boundary intact where the pipeline engine genuinely needs
   something only Workflows can answer.** Both are interfaces OWNED by Variables and IMPLEMENTED by
   Workflows, bound in `WorkflowsModuleServiceProvider` — so Variables can call through the interface
   without ever naming a Workflows class:

   - **`Variables\Contracts\ElementScopeResolver`** — `elementScopeSubfields(array $arrayDescriptor):
     array<string, VariableType>`, the `element.<subfield>` index an `array<object>`/`array<file>`
     element pipeline needs (ADR-0026 wave 3). The catalog already owns this descent (the SAME
     `{key,label,descriptor}` recursion also derives form-field / object-const subfields — see
     ADR-0028), so `WorkflowVariableCatalogService implements ElementScopeResolver` rather than Variables
     growing a second copy of that recursion.
   - **`Variables\Contracts\FunctionReferenceLookup`** — `isReferencedByWorkflow(string $functionId):
     bool`, consulted by the custom-function DELETE guard (ADR-0029) so a function cannot be deleted out
     from under a workflow that still references it. `App\Modules\Workflows\Services\
     WorkflowFunctionReferenceScanner implements FunctionReferenceLookup`, scanning every live,
     workspace-scoped `Workflow`'s `steps`/`conditions` for a `fn:<uuid>` op. Bound optionally
     (`?FunctionReferenceLookup $workflowReferences = null` on `CustomFunctionService`) so Variables keeps
     functioning — the guard just degrades to the function-vs-function check alone — if it is ever used
     without Workflows at all.

   ```php
   // app/modules/Workflows/WorkflowsModuleServiceProvider.php
   $this->app->bind(ElementScopeResolver::class, WorkflowVariableCatalogService::class);
   $this->app->bind(FunctionReferenceLookup::class, WorkflowFunctionReferenceScanner::class);
   ```

7. **The reference-source whitelist a pipeline argument-variable may target is THREADED FROM the request,
   not imported.** `PipelineValidator` needs to know which root names (`trigger`/`steps`/`globals`) are
   legal for an argument-variable's `ref.source` — but that whitelist is `WorkflowVariableResolver::ROOTS`,
   a Workflows-owned runtime concept. Rather than Variables importing it, `StoreWorkflowRequest` builds a
   `$refCtx` array carrying `'sources' => WorkflowVariableResolver::ROOTS` (alongside the reference index,
   the form-availability flag, and — since ADR-0029 — the workspace's custom functions) and passes it down
   through `WorkflowConditionTreeValidator::validate()` into `PipelineValidator`. A `PipelineValidator`
   call site with no `$refCtx['sources']` (a focused unit test driving the engine directly) falls back to
   `PipelineValidator::DEFAULT_REFERENCE_SOURCES`, a private fallback copy pinned byte-identical to
   `WorkflowVariableResolver::ROOTS` by the boundary test (Decision 2).

8. **The extraction is a PURE refactor — verified by the unchanged suite, not merely asserted.** Phase 1
   (this ADR) touched zero frontend files (the wire contracts — request/response JSON shapes, validation
   error keys, runtime resolution — are byte-for-byte unchanged) and changed no runtime behavior on the
   backend: every method that moved kept its logic; only its class name, namespace, and — for the
   tree/pipeline split — which of two classes it lives in, changed. The full pre-existing test suites (BE
   and FE) were run to green BEFORE Phase 2 (ADR-0028) and Phase 3 (ADR-0029) added their own new
   functionality, and its own new tests, on top.

## Consequences

- **Positive.** A future consumer of the type system or the pipeline engine (R2 Generator/Templates,
  named in the product roadmap, or anything else that needs typed values and operation pipelines) can now
  depend on `App\Modules\Variables` alone, without pulling in Workflows' triggers, steps, schedule
  machinery, or run engine. The one-way boundary is not just a convention — a real test fails the build if
  it is ever violated, so the invariant survives future contributors who have not read this ADR. The
  `ElementScopeResolver`/`FunctionReferenceLookup` inversion pattern is now a precedent: the next time the
  lower-layer engine needs one specific fact only the upper layer can answer, the answer is a small,
  Variables-owned interface bound by the Workflows provider, not a back-import.
- **Positive.** Splitting `ConditionTreeLimits` by WHAT it bounds (tree shape vs. pipeline engine) rather
  than moving the whole class keeps each cap physically next to the code that enforces it — a future
  reader of the tree validator sees only tree caps, and a future reader of the pipeline engine sees only
  pipeline caps, instead of one grab-bag class two modules both had to reach into.
- **Trade-off (accepted): pipeline validation is now visibly two classes instead of one
  (`WorkflowConditionTreeValidator` + `PipelineValidator`), and a reader has to know the tree/pipeline
  boundary to find the right one.** Accepted because the two concerns were already logically distinct
  (tree shape vs. type-flow) before this ADR — the extraction makes an existing seam visible in the
  module boundary rather than inventing a new one; the tree validator's docblock explicitly names the
  split ("TREE structure lives here; the per-leaf PIPELINE type-flow ... lives in the Variables module's
  PipelineValidator, which this class CALLS per condition") so the seam is discoverable at the call site.
- **Trade-off (accepted): the reference-source whitelist threading (Decision 7) is one more parameter
  `StoreWorkflowRequest` has to remember to pass.** Rejected the alternative (Variables importing
  `WorkflowVariableResolver::ROOTS` directly) as a direct boundary violation — a hardcoded default,
  boundary-tested against the real list, is the correct shape for a lower layer that must still work
  (with a safe, correct fallback) when driven directly by a unit test with no upper-layer request in the
  loop.
- **Rejected: extracting the engine into a shared, framework-agnostic package instead of a Laravel
  module.** No second Laravel application consumes it today, and Taskio's own modular-monolith convention
  (`app/modules/<Name>`, a `<Name>ModuleServiceProvider`) already gives two modules a clean one-way
  dependency without the overhead of a package boundary (composer path repository, its own test suite,
  its own release process) for a boundary a single test can already enforce.
- **Rejected: moving `WorkflowConditionEngine`, `WorkflowVariableResolver`, or
  `WorkflowVariableCatalogService` into Variables too.** All three stay in Workflows because they are
  genuinely Workflows-shaped: `WorkflowConditionEngine` evaluates a Workflows condition TREE (not just a
  pipeline) and owns `GLOBALS_ROOT`/`readGlobals()`; `WorkflowVariableResolver` assembles a Workflows RUN
  CONTEXT (`trigger`/`steps`/`globals`) and resolves the directive/if-block/ai-text machinery ADR-0013
  built, which has no meaning outside a workflow run; `WorkflowVariableCatalogService` derives variables
  from Workflows-specific sources (a form's trigger fields, a step's output template). Each of them
  CONSUMES the Variables engine (`OperationExecutor`, `PipelineValidator`) rather than being part of it.

See `docs/backend/workflows-api.md` for the wire-level consequences of this extraction (none — the point
of a pure refactor is that the contract is unchanged) and `docs/decisions/ADR-0028-consts-rename.md` /
`docs/decisions/ADR-0029-custom-functions.md` for the two things built on top of this new module.
