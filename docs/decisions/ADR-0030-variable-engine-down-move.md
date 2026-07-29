# ADR-0030 — R2 PR-1a: the shared variable engine down-move (VariableResolver, VariableCatalog, AiTextGenerator)

**Date:** 2026-07-26 (created)
**Status:** Accepted
**Module:** `App\Modules\Variables` (receives the relocated classes), `App\Modules\Workflows` (rewired,
behavior-preserving only), `App\Modules\Generator` (the new consumer this move exists to unblock — see
ADR-0031)
**Relates to:** ADR-0027 (the module extraction this ADR continues — its own "Rejected: moving
`WorkflowConditionEngine`, `WorkflowVariableResolver`, or `WorkflowVariableCatalogService` into Variables
too" is NARROWED, not reversed, by Decision 7 below), ADR-0009 / ADR-0013 (the directive/if-block/pipeline
machinery this move relocates unchanged), ADR-0021 (the composable-catalog-roots recipe this move's
`ROOTS`/`VariableCatalog` widening reuses), ADR-0022–ADR-0026 (the phases whose combined output IS the
relocated engine), ADR-0028 / ADR-0029 (Consts / Functions — already-landed Variables-module consumers
unaffected by this move), ADR-0031 (R2 PR-1b — the Generator module this down-move exists to unblock)

---

## Context

R2 sub-stage 1 ("Templatki", `docs/product/plan-dzialania.md`) needs to render a Template's markdown
`prompt_body` — `@[variable]` directives over `slots.<name>` / `globals.*`, pipelines, custom functions,
potentially `@[ai-text]` — through EXACTLY the same interpolation semantics a workflow step's text field
already runs through (ADR-0013, ADR-0022 onward): the same directive syntax, the same if-block grammar,
the same 83-op pipeline engine, the same custom-function expansion, the same NUL-mask injection guards.

ADR-0027 had already moved the variable TYPE SYSTEM and the pipeline OPERATION ENGINE (`VariableType`,
`Operation`, `OperationExecutor`, `PipelineValidator`, …) down into `App\Modules\Variables`, anticipating
exactly this future need — but it deliberately LEFT the INTERPOLATION resolver
(`WorkflowVariableResolver`) and the catalog COMPOSITION (`WorkflowVariableCatalogService`) in Workflows,
reasoning (ADR-0027's own "Rejected" section) that both were "genuinely Workflows-shaped": the resolver
"assembles a Workflows RUN CONTEXT (`trigger`/`steps`/`globals`)" and the catalog "derives variables from
Workflows-specific sources."

That reasoning held until a second consumer of the SAME interpolation surface arrived. A Template has no
trigger, no steps, and no form — but it needs the identical directive / if-block / pipeline resolution
machinery, over its OWN context (`slots` / `globals`), and the identical FORM-INDEPENDENT half of the
catalog (the type list, the operation catalog, workspace globals, workspace functions) that Workflows
already built on top of Variables (ADR-0021, ADR-0028, ADR-0029). Three options were on the table:

1. **Fork the engine** — copy the resolver into Generator. Rejected outright by the accepted plan: any
   future bug fix, new op, or ADR-0026-style feature would need to land twice, and the two copies would
   silently diverge — exactly the failure mode ADR-0027 already exists to prevent for the type system.
2. **Generator depends on Workflows directly** for the resolver. This creates a LATENT cycle: sub-stage 5
   of the very same roadmap adds a `generate_content` WORKFLOW STEP that needs to depend on GENERATOR (to
   run a template) — so a Generator → Workflows dependency today would become a genuine A → B → A cycle
   the moment that step lands. Rejected.
3. **Promote the genuinely shared, form-independent slice further down into Variables** — a second
   down-move, narrower than option 1's fork and structurally identical in spirit to ADR-0027's own move.
   **Accepted.**

This ADR records that third option: `WorkflowVariableResolver` relocates wholesale into
`App\Modules\Variables\Services\VariableResolver`, and the FORM-INDEPENDENT half of
`WorkflowVariableCatalogService` is extracted into a new `App\Modules\Variables\Services\VariableCatalog`.
It is, like ADR-0027 itself, a **PURE, behavior-preserving refactor** — verified by running the full
pre-existing Workflow/Variables backend suites green with zero assertion changes, plus a new
characterization test pinning one representative resolution byte-identical, BEFORE ADR-0031's own new
functionality (the Generator module, Templates) was added on top. **Zero frontend files changed.**

## Decisions

1. **`VariableResolver` — the interpolation engine — relocates wholesale (git mv + rename) from
   `App\Modules\Workflows\Services\WorkflowVariableResolver` to
   `App\Modules\Variables\Services\VariableResolver`.** Every dependency the resolver already had was
   Variables-owned post-ADR-0027 (`OperationExecutor`, `OperationResolver`, `VariableType`,
   `OperationArg`/`OperationArgType`, `PipelineLimits`, `FunctionScope`, `ScopeRef`, `ValueOrVariable`)
   EXCEPT the AI-text generation call, handled by Decision 3. Every resolution PATH is untouched: variable
   directives (identity + pipeline), the legacy flat `{{...}}` tokens, fenced if-blocks, the structured
   `{kind:'literal'|'variable'}` union, per-reference `default`s, argument-variables (including the
   structural sourceMap/choiceRules/element-pipeline walk), file-subfield reads, and `@[ai-text]`.

2. **The FORM-INDEPENDENT half of the catalog extracts into `App\Modules\Variables\Services\VariableCatalog`.**
   It owns exactly the pieces that never needed a form, a trigger, or a step to compute: `variableTypes()`
   (the type vocabulary), `operations()` (the built-in catalog merged with the workspace's custom
   functions), `globalVariables()` / `globalValues()` (workspace consts, both as catalog entries and as
   the injectable `{key:value}` map), `customFunctionOperations()` (the runtime operation VOs), plus the
   two small shared rules `flatType()` (the TIME/OBJECT → TEXT wire degrade) and `descriptorOptionKeys()`
   (an enum descriptor's flat option-key list). `WorkflowVariableCatalogService::forContext()` now
   DELEGATES every one of these to a `VariableCatalog` instance it depends on, keeping ONLY its genuinely
   Workflows-shaped derivation: trigger-system variables, form-field variables (via
   `InteractsWithFormSchema`), step-output variables, the condition-field/source table, and the
   structural-container/subfield-typemap recursion. The delegation is verified byte-identical — the
   entire pre-existing `WorkflowVariableCatalogTest` suite passed unmodified.

   ```php
   // app/modules/Workflows/Services/WorkflowVariableCatalogService.php (after the extraction)
   class WorkflowVariableCatalogService implements ElementScopeResolver
   {
       public function __construct(
           private WorkflowStepFactory $steps,
           private VariableCatalog $catalog,   // ← the Variables-owned, form-independent surface
       ) {}

       public function variableTypes(): array { return $this->catalog->variableTypes(); }
       public function globalVariables(): array { return $this->catalog->globalVariables(); }
       // …
   }
   ```

3. **A new `App\Modules\Variables\Contracts\AiTextGenerator` interface is the seam the relocated resolver
   depends on for `@[ai-text]`, instead of naming `WorkflowAiTextService` / `WorkflowAiPersona` directly.**
   One method, `generate(string $prompt, ?string $personaId): string` — a plain, optional `?string`
   persona id (so the CONTRACT itself carries no upper-module enum), and a hard MUST-be-fail-closed rule
   (never throws; `''` on a blank prompt, an exhausted budget, or any provider failure).
   `WorkflowAiTextService implements AiTextGenerator`, mapping the contract's plain id to its own
   `WorkflowAiPersona` enum internally (`fromNullable`); bound in
   `WorkflowsModuleServiceProvider::register()`:

   ```php
   // app/modules/Workflows/WorkflowsModuleServiceProvider.php
   $this->app->bind(AiTextGenerator::class, WorkflowAiTextService::class);
   ```

   Deliberately NOT a singleton — the service is resolved fresh together with the resolver for each
   `WorkflowRunJob`, which is what scopes the per-run AI-call budget (`$calls` is instance state).
   ADR-0031 binds a SECOND, different implementation (`NoOpAiTextGenerator`) contextually, only for the
   template-preview path — see that ADR.

4. **The reference whitelist `ROOTS` widens from `['trigger','steps','globals']` to the superset
   `['trigger','steps','globals','slots']`.** `slots` is the template root a workflow context never
   populates (a `slots.*` reference is a whitelisted lookup that resolves to nothing — inert, never
   leaks, never throws); symmetrically, `trigger`/`steps` are inert in a template context.
   `StoreWorkflowRequest` reads the SAME constant (only its import path changed, to
   `App\Modules\Variables\Services\VariableResolver`). `VariablesModuleBoundaryTest::
   test_pipeline_validator_reference_sources_match_the_resolver_roots` — the ADR-0027 pin keeping
   `PipelineValidator::DEFAULT_REFERENCE_SOURCES` and the resolver's `ROOTS` byte-identical — is extended
   to additionally assert both `globals` and `slots` are present, so the two whitelists can never
   silently drift apart across the widened superset either.

5. **Rewiring, not rewriting: every Workflows consumer changes its imports, never its logic.**
   `WorkflowStepRunner`, `WorkflowConditionEngine`, `WorkflowConditionTreeValidator`,
   `WorkflowVariableCatalogService`, `WorkflowAiTextService`, `CreateTaskStep` / `CreateFormReportStep`,
   and `StoreWorkflowRequest` now import `App\Modules\Variables\Services\VariableResolver` (and, where
   relevant, `VariableCatalog` / `AiTextGenerator`) instead of the old Workflows-namespaced classes. No
   call site's LOGIC changed — the run/gate path is behavior-identical, which is exactly what the
   characterization test (Decision 6) exists to prove rather than merely assert.

6. **Verification is a NEW characterization test, not a re-read of the diff.**
   `tests/Unit/Variables/VariableResolverCharacterizationTest.php` resolves ONE representative "workflow
   run" config covering every path the move touched — an identity directive over `globals.*`, a directive
   pipeline ending in a BUILT-IN op, a directive pipeline ending in a `fn:<uuid>` CUSTOM FUNCTION (executed
   by expansion), a legacy flat `{{steps.<key>.*}}` token, a fenced if-block, a structured
   value-or-variable pipeline, and an `@[ai-text]` directive resolved through a FAKE `AiTextGenerator`
   (proving the prompt reaches the generator FULLY resolved, embedded variables substituted) — and asserts
   the WHOLE config resolves byte-identically. A second test proves the `slots` root is genuinely inert in
   a workflow context (Decision 4). `VariablesModuleBoundaryTest` gains
   `test_the_relocated_shared_surface_lives_in_variables` (each of `VariableResolver` / `VariableCatalog` /
   `AiTextGenerator` resolves under `App\Modules\Variables\` and names no Workflows class), and its
   existing "Variables imports nothing from Workflows" file scan now covers these new arrivals for free
   (they live under the scanned root). The full pre-existing Workflow and Variables backend suites — whose
   counts this refactor does not change beyond the new characterization + boundary-test additions, since
   it relocates classes rather than adding new assertions to existing tests — and the full pre-existing
   frontend suite (UNTOUCHED — zero frontend files changed by this ADR specifically) were run green BEFORE
   ADR-0031's own new functionality and its own new tests were added on top. The final combined suite on
   `feat/r2-generator-templatki` (PR-1a + PR-1b together) is 35 Generator + 828 Workflow + 321 Variables
   backend tests, 1519 frontend tests, all green, `Pint`/`vite build` clean.

7. **This NARROWS, not reverses, ADR-0027's own "Rejected: moving `WorkflowConditionEngine`,
   `WorkflowVariableResolver`, or `WorkflowVariableCatalogService` into Variables too."**
   `WorkflowConditionEngine` and the WORKFLOW-SPECIFIC remainder of `WorkflowVariableCatalogService` STAY
   in Workflows exactly as ADR-0027 decided — they are still genuinely Workflows-shaped (a condition
   TREE, `GLOBALS_ROOT` / `readGlobals()`, trigger/step/form derivation). Only the resolver's
   INTERPOLATION ENGINE (which turned out to have no workflow-specific logic left in it once ADR-0027's
   own extraction was done — its "run context" was already just `{trigger,steps,globals}`, three named
   array keys, not a `WorkflowRun` object) and the catalog's FORM-INDEPENDENT slice moved.
   `WorkflowVariableCatalogService` itself stays a Workflows class; it now HAS-A `VariableCatalog` rather
   than IS a superset of one.

## Consequences

- **Positive.** ADR-0031's Generator module can depend on `App\Modules\Variables` ALONE for the entire
  authoring/interpolation surface — same directive syntax, same if-blocks, same 83-op pipeline engine,
  same custom functions, same globals, same fail-closed/NUL-mask injection doctrine — with zero
  duplicated logic and zero Workflows dependency, which is the whole reason this ADR exists.
- **Positive.** The `AiTextGenerator` inversion turns "ai-text is inert in a preview" into a ONE-LINE
  contextual binding (`NoOpAiTextGenerator`, ADR-0031) rather than a resolver-level `if` branch — the
  resolver's own code carries no knowledge that a preview, a Template, or a Generator module exists at
  all.
- **Positive.** Because `generate_content` (the future Workflows step consuming a Template, deferred to
  sub-stage 5) will depend on Generator, and Generator depends only on Variables, the dependency graph
  stays acyclic BY CONSTRUCTION once that step lands — this ADR is the piece of groundwork that makes
  sub-stage 5 buildable without a redesign.
- **Positive.** Pure-refactor discipline was upheld the same way ADR-0027 upheld it — a characterization
  test is the proof, not a claim in this document.
- **Trade-off (accepted): the resolver's own class docblock now speaks in form/template-agnostic
  language** (a "run context" that might be a workflow's `{trigger,steps,globals}` OR a template's
  `{slots,globals}`) — a reader holds slightly more generality in mind than when the class was
  Workflows-only and could say "the workflow run context" unambiguously.
- **Trade-off (accepted): `tests/Unit/Workflows/WorkflowVariableResolverTest.php` deliberately KEPT its
  historical file path/namespace/class name** (`Tests\Unit\Workflows`) even though it now exercises the
  Variables-namespaced `VariableResolver` (imported locally `as WorkflowVariableResolver`, so the test
  body's diff stays near zero). This trades a perfectly-aligned test-suite layout for a much smaller, more
  reviewable diff; a future test-suite reorganization moving resolver/catalog tests into
  `tests/Unit/Variables` is real follow-up work, not attempted here.
- **Trade-off (accepted): `Generator\Services\TemplateSlotValidator::RESERVED_SLOT_NAMES` (ADR-0031)
  INLINES a literal copy** of the four context roots (`trigger`, `steps`, `globals`, `slots`) plus the
  three function-scope names, rather than importing `VariableResolver::ROOTS` — a deliberate choice to
  keep the Generator module's write-validator free of a dependency shaped like "import a constant from
  Variables just to inline it into a bigger literal," at the cost that, unlike
  `PipelineValidator::DEFAULT_REFERENCE_SOURCES` (Decision 4), this particular copy is NOT cross-asserted
  equal by a standing test. Flagged here as an accepted, narrow gap — see ADR-0031 Consequences.
- **Rejected: forking the engine (Option 1).** Would immediately diverge from the shared implementation,
  defeating ADR-0027's own purpose.
- **Rejected: Generator depending on Workflows directly (Option 2).** Would create the exact cycle
  sub-stage 5's `generate_content` step is designed to avoid.

See `docs/backend/generator-api.md` and `docs/decisions/ADR-0031-generator-module-templates.md` for what
this down-move unblocks, and `docs/decisions/ADR-0027-variables-module-extraction.md` for the extraction
this ADR continues.

---

## Amendment (same chapter — the Generator "structural slots" polish) — the subfield descent ALSO moves into `VariableCatalog`

Decision 2 above described the catalog extraction as keeping "the structural-container/subfield-typemap
recursion" in `WorkflowVariableCatalogService`. That held only until Generator's own sub-stage-1 polish —
object/file SLOT descriptors plus `slots.<name>.<sub>` / `globals.<key>.<sub>` write-validation and
runtime resolution, see `docs/backend/generator-api.md` → "Object and file slots" / "Subfield references" —
needed the SAME recursive descent `WorkflowVariableCatalogService` already had, for exactly the reason
Decision 2 itself existed: two domain catalogs (workflow, template) must never fork one implementation.

**The descent is now promoted into `App\Modules\Variables\Services\VariableCatalog`, which becomes its
SINGLE home**: `descriptorSubfieldTypeMap()` (the composed entry point), `fileSubfieldTypeMap()` (a FILE
composite's fixed `{id,name,type,size,url}`, single-sourced from `VariableType::fileSubfieldTypes()`), the
recursive `objectSubfieldTypeMap()` (a non-array OBJECT container's declared `fields`, walked depth-first),
and the shared predicate `isObjectContainer()` (`base === 'object' && array !== true`) all now live there —
their signatures and behavior unchanged, only their address.

`WorkflowVariableCatalogService` no longer OWNS this recursion; it DELEGATES, byte-identical:

```php
// app/modules/Workflows/Services/WorkflowVariableCatalogService.php (after the promotion)
private function addTypeMapEntry(array &$map, string $path, VariableType $type, ?array $descriptor = null): void
{
    $map[$path] = $type;

    foreach ($this->catalog->descriptorSubfieldTypeMap($path, $type, $descriptor) as $subPath => $subType) {
        $map[$subPath] ??= $subType;
    }
}
```

(`addReferenceEntry()`, `elementScopeSubfields()`, and `collectGlobalConditionFields()`'s object-container
check delegate the same way — the last of these keeps its OWN, differently-scoped
`isNonArrayObjectContainer()` for condition-source filtering, a workflow-specific concept unrelated to
this descent; only the SUBFIELD-DESCENT predicate moved.) The pre-existing `WorkflowVariableCatalogTest`
suite — every case exercising a form section, a repeater, a file composite, or an object global's
subfield — passed unmodified: the same byte-identical-delegation discipline Decision 2 itself was verified
under.

**`Generator\Services\TemplateVariableCatalog` is the second, and so far only other, consumer.**
`referenceIndex()` / `typeMap()` both call `$this->catalog->descriptorSubfieldTypeMap($path, $type,
$descriptor)` over every `slots.<name>` / `globals.<key>` entry — the identical call shape
`WorkflowVariableCatalogService` makes — so a `slots.product.name` / `slots.image.url` /
`globals.company.city` reference type-flows from its OWN real base in EITHER module, off ONE
implementation. This extends Decision 2's own reasoning ("so the two can never disagree") one level
deeper than Decision 2 itself reached.

**A related, narrower widening landed in the same polish**:
`ConstantTypeValidator::validateSlotDescriptorShape()` — a new sibling of `validateDescriptorShape()`, both
now delegating to a shared private `validateDescriptor()` that accepts an `$extraBases` parameter — accepts
`base:'file'` for a TEMPLATE SLOT specifically (a file is a legit, reusable template input, unlike a
constant's plain literal), while `validateDescriptorShape()` (what a `Constant` still uses) continues to
reject `file` outright, unchanged. `$extraBases` propagates through the recursive object-field validation
too, so a `file` may nest inside an `object` slot's own declared fields, not only appear at the top level.
See `docs/backend/generator-api.md` → "Object and file slots" for the worked descriptor shapes this
unlocks.

No wire contract changes for Workflows from any of this — the same discipline as this ADR's own original
move: verified by the pre-existing Workflow/Variables suites passing with zero assertion changes, plus
Generator's own new `TemplateSlotValidationTest` / `TemplateCatalogTest` / `TemplatePreviewTest` coverage
of the newly-shared path. See `docs/backend/generator-api.md` for the full Generator-side contract this
promotion unblocks, and `docs/decisions/ADR-0031-generator-module-templates.md`'s own amendment for how
this closes that ADR's Decision 5/9 "subfield references deferred" scope note.
