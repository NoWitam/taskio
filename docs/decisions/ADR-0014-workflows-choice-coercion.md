# ADR-0014 — Choice coercion: mapping a value pipeline into a destination field's option set

**Date:** 2026-07-14 (created)
**Status:** Accepted
**Module:** Workflows (`app/modules/Workflows/`)

---

## Context

ADR-0013 gave a value-or-variable field (`create_task.priority`/`.deadline`,
`create_form_report.submissions_from`/`.submissions_to`) an OPTIONAL, write-validated operations
pipeline: pick any referenceable variable, transform it, and the write validator checks the
pipeline's TERMINAL type against the field's accepted type(s) (`priority` → `enum` or `text`;
the date fields → `date`). In practice this under-served exactly the field it was built for: a
task's `priority` is not really "any enum or text value" — it is one of FOUR specific strings
(`TaskPriority::ids()` = `urgent|high|medium|low`). The old terminal rule happily accepted a
pipeline like `enum_to_text` that produces an arbitrary string ("Pilne!", a source option's own
label, …) which is not a valid `TaskPriority` at all; at RUN time that non-member value simply
soft-defaults to `medium` (the field's existing soft-failure doctrine), silently discarding
whatever the author actually intended. Real authoring need: "map this form's free-text `category`
answer onto a task priority" (`blog`→`high`, `news`→`low`, …) or "if the headline contains
`BREAKING`, mark it `urgent`, else `medium`" — both are MAPPINGS into a small fixed set, not a
generic enum-or-text transform. This ADR adds the two operations that make that mapping
first-class and write-validated against the REAL destination option set, closing the gap between
"the pipeline type-checks" and "the pipeline actually produces something the field can use."

---

## Decisions

### 1. Two new terminal operations (`enum_to_choice`, `match_to_choice`), not a widened terminal rule

**Decision:** `WorkflowOperation` grows from 66 to 68 members with `enum_to_choice` (`enum` →
`enum`, a per-option `sourceMap` whose mapType is `enum`) and `match_to_choice` (`text` → `enum`,
an ordered `{when, then}` rule list + a required `fallback`). A new predicate,
`WorkflowOperation::producesChoice()`, is `true` for exactly these two ops. A value pipeline
targeting a **choice field** (currently only `create_task.priority`) must be non-empty and END in
a `producesChoice()` op; every mapped/ruled target value in that pipeline is checked against the
field's real option set at write time (`WorkflowConditionTreeValidator::validateValuePipeline()`).

**Alternatives rejected:**
- **Keep the old "enum or text" terminal rule and validate the RESULT instead of the pipeline
  shape** — rejected: the terminal type is known at validation time (the pipeline is fully typed),
  but the terminal VALUE is not (it depends on the run's actual data — e.g. `enum_to_text`'s
  output could be any string the author typed into its mapping). There is no way to statically
  prove an arbitrary `text`-terminal pipeline will only ever produce a `TaskPriority` member;
  requiring the LAST op to be a dedicated choice-producing op is the only way to make that
  guarantee checkable at write time.
- **Reuse `enum_to_text`/`match`-style ops as-is and just widen validation to check the terminal
  op's OWN map/rule values against the target set** — rejected: `enum_to_text` and any future
  `*_to_text` op are legitimately meant to produce free text elsewhere (a directive pipeline
  feeding a title, for instance); overloading their validation with a field-specific option-set
  check would mean the SAME op id behaves differently depending on which field it happens to sit
  in — reintroducing exactly the "op semantics drift by context" problem ADR-0013 §1 rejected a
  second engine to avoid. A dedicated, unambiguous op id (`producesChoice() === true`) keeps every
  op's meaning constant regardless of where it is used.
- **A single combined op ("enum_to_choice_or_text_match")** — rejected: `enum_to_choice` (mapping
  an ENUM source) and `match_to_choice` (rule-matching a TEXT source) have genuinely different
  input types and argument shapes (a per-option map vs. an ordered rule list + fallback); merging
  them would need a discriminated-union argument shape for one op id, complicating both the
  descriptor and the pipeline editor for no real benefit over two small, single-purpose ops.

**Rationale:** A closed, enumerated operation vocabulary (ADR-0008 #4, ADR-0009 §2, ADR-0013 the
module-wide alternatives section) is the standing doctrine for this module specifically because it
lets the write-path validator and the frontend's pipeline editor agree on exactly what a pipeline
can produce. Two new, unambiguous terminal ops extend that vocabulary additively (a new id, never a
renamed/repurposed one) without opening a general expression language.

**Consequence:** `WorkflowOperation::catalog()` (and therefore `GET
/forms/{form}/workflow-catalog`'s `operations` key) now returns 68 descriptors instead of 66.
`docs/backend/workflows-api.md`'s "Choice fields" section documents the wire contract;
`resources/js/next/ui/editor/extensions/standardOperations.ts` mirrors both new ops byte-for-byte
(label `match_to_choice` → "Dopasuj do wyboru" / `enum_to_choice` → "Zamień na wybór").

---

### 2. The destination option set is INJECTED PER-FIELD, never a static part of the op descriptor

**Decision:** Neither `enum_to_choice`'s `mapping` arg nor `match_to_choice`'s `rules`/`fallback`
args carry a fixed option list in `WorkflowOperation::argDescriptors()`. Two new argument CONTROL
kinds exist purely to mark "this arg's options come from the DESTINATION field, not the source
variable or a literal": `WorkflowOperationArgType::CHOICE_RULES` and `::CHOICE_FALLBACK` (plus
`SOURCE_MAP` gaining a `mapType: enum` case for `enum_to_choice`'s mapping). The actual allowed
VALUES (`TaskPriority::ids()` today) are passed as a `$targetOptions` parameter, threaded from
`StoreWorkflowRequest::validateCreateTaskConfig()` → `validateUnionOrLiteral()` →
`WorkflowConditionTreeValidator::validateValuePipeline()`, and — on the frontend — from
`WorkflowStepCard.vue`'s `vovFieldSpecs.priority.targetOptions` (a `priorityOptions` computed built
from the same literal `low|medium|high|urgent` list the priority `Select` already uses) down into
`ValueOrVariableField`'s `:target-options` prop and the `VariablePipelineEditor` it opens.

**Alternatives rejected:**
- **A new branded `WorkflowVariableType::CHOICE` (or `PRIORITY`) in the closed 6-member type
  set** (`text|number|boolean|date|enum|multi`) — rejected: `priority` is not a NEW kind of value
  at rest, it is the SAME `enum` type ADR-0009 §2 already gave it (`TaskPriority` is exactly one
  enum among many a form field could carry) — the only thing that differs field-to-field is WHICH
  option strings are valid. Adding a type purely to carry "this enum has these specific options"
  would duplicate the type for every future fixed-option field (a status field, a category field,
  …), forcing `WorkflowVariableType`'s exhaustive `match` arms (coercion, operator tables, the FE's
  parallel union) to grow one branch per NEW FIELD rather than staying closed over kinds of data.
  The type stays `enum`; only the validator's targeted-field awareness changes.
- **A parallel "v2 choice pipeline" system, separate from the existing value-or-variable
  pipeline** — rejected outright per this module's standing "no parallel v2 systems, add-only wire
  vocabulary" doctrine (`.claude/rules/architecture.md`; ADR-0009 §2, ADR-0013 module-wide
  alternatives): the existing `{kind:'variable', ref, pipeline}` union and
  `WorkflowConditionTreeValidator`'s walk are reused completely unchanged in SHAPE — only a NEW
  terminal-op check and a NEW `$targetOptions` parameter are added to the same walk.
- **Hardcode `TaskPriority::ids()` inside `WorkflowOperation`/`WorkflowOperationArg` for
  `enum_to_choice`/`match_to_choice` specifically** — rejected: it would coincidentally work for
  the one field that exists today but bakes a Tasks-module enum into the Workflows module's
  operation catalog (a layering violation — operations are meant to be generic across every
  possible destination field), and would need a NEW op the moment a second choice field appears
  (e.g. a future form-report status). Per-field injection means the SAME two ops serve any future
  choice field for free.

**Rationale:** "Validate the actual destination, not a proxy for it" — the field asking for a
value (`create_task.priority`, via `StoreWorkflowRequest`) is the one place that genuinely knows
its own option set; the generic operation catalog and pipeline validator should stay ignorant of
WHICH field they are being used for, exactly as they already are for every other op.

**Consequence:** Adding a second choice field in the future (e.g. a form-report outcome enum) needs
ZERO new operations — only a new `validateUnionOrLiteral(..., $targetOptions)` call site with that
field's own option list. `docs/backend/workflows-api.md` documents this "inject per field, not per
op" rule explicitly so a future contributor does not go looking for a `TaskPriority` reference
inside `WorkflowOperation`.

---

### 3. Write-validation tightened for `priority`; runtime coercion left untouched (validator-only change)

**Decision:** `create_task.priority`'s accepted pipeline terminal was `enum` **or** `text` (ADR-0013
§5). It is now: a non-empty pipeline that ends in a `producesChoice()` op, full stop — a bare
identity ref (no pipeline) or a terminal like `enum_to_text` is now REJECTED at write time
(`422` under `steps.<i>.config.priority.pipeline`). The RUNTIME side
(`WorkflowVariableResolver::resolveVariableUnion()`) is **completely unchanged**: it still just
executes whatever pipeline is stored and coerces the result to `WorkflowVariableType::ENUM`,
soft-defaulting to `medium` on any failure/mismatch — `producesChoice()` is checked ONLY by the
write validator, never by the resolver.

**Alternatives rejected:**
- **Also enforce `producesChoice()` at RUN time** (fail the step, or re-validate on every
  execution) — rejected: the write validator already guarantees a NEWLY saved `priority` pipeline
  ends in a choice op; re-checking at every run would be redundant work on the hot path for no
  behavioral gain, and would risk turning an old, already-saved (pre-this-ADR) pipeline into a
  newly-hard-failing run instead of leaving its existing soft-default behavior alone.
- **Write a data migration to fix/reject every already-saved `priority` pipeline that does not end
  in a choice op** — rejected: no user-facing incident exists yet (the soft-default-to-`medium`
  behavior was always there and is not silently wrong, just less precise than intended), and a
  migration touching every workflow's `steps` JSON is a disproportionate response to a
  validator-only tightening. An old workflow simply cannot be RE-SAVED unchanged if its `priority`
  pipeline predates this rule — the author fixes it when they next edit that step, same as any
  other validation tightening in this module's history (e.g. the step key charset fix).

**Rationale:** Tightening a WRITE validator without touching RUNTIME behavior is the smallest safe
change: every already-running workflow keeps firing exactly as before (no surprise task failures
from a pre-existing config), while every NEW or RE-SAVED config is held to the stricter, more
correct rule. This mirrors the same "validator-only, runtime unchanged" shape ADR-0013 §5 already
used to justify validating the value-or-variable pipeline (a structured field) while leaving the
markdown directive pipeline runtime-only (an unstructured one) — consistency of doctrine, not just
of code.

**Consequence:** `docs/backend/workflows-api.md`'s write-time-validation table and the new "Choice
fields" section state the tightened rule explicitly, calling out that it is a validator-only
change. `tests/Feature/WorkflowStepValuePipelineValidationTest.php` pins the new
`test_rejects_a_non_choice_terminal_for_priority` case (an `enum_to_text`-terminated pipeline that
used to pass now fails) alongside the acceptance cases for both new ops.

---

## Related files

- `app/modules/Workflows/Enums/WorkflowOperation.php` — decision #1 (`ENUM_TO_CHOICE`,
  `MATCH_TO_CHOICE`, `producesChoice()`, the 68-op `catalog()`)
- `app/modules/Workflows/Enums/WorkflowOperationArgType.php` — decision #2 (`CHOICE_RULES`,
  `CHOICE_FALLBACK`, `SOURCE_MAP`'s `mapType: enum` case)
- `app/modules/Workflows/DTOs/WorkflowOperationArg.php` — decision #2 (`choiceRules()` /
  `choiceFallback()` factories)
- `app/modules/Workflows/Services/WorkflowOperationExecutor.php` — decision #1 (`matchToChoice()`,
  the `enumMap()` reuse for `enum_to_choice`'s runtime semantics)
- `app/modules/Workflows/Services/WorkflowConditionTreeValidator.php` — decisions #2, #3
  (`validateValuePipeline`'s `$targetOptions` parameter, `validateChoiceRules`/
  `validateChoiceFallback`/the `sourceMap` `enum` mapType branch)
- `app/modules/Workflows/Http/Requests/StoreWorkflowRequest.php` — decisions #2, #3
  (`validateCreateTaskConfig`'s `TaskPriority::ids()` injection, `validateVariablePipeline`'s
  `$targetOptions` threading)
- `resources/js/next/ui/editor/extensions/standardOperations.ts` — decision #1 (the FE mirror of
  both ops, including their PL/EN labels)
- `resources/js/next/pages/workflows/ValueOrVariableField.vue` — decisions #2, #3 (`targetOptions`
  prop, `pipelineSatisfies(...)` gating Save on the choice terminal, the "needs choice op" modal
  hint)
- `resources/js/next/pages/workflows/WorkflowStepCard.vue` — decision #2
  (`vovFieldSpecs.priority.targetOptions`, the saved-model type-error gate)
- `tests/Feature/WorkflowStepValuePipelineValidationTest.php` — decisions #1–#3 (acceptance +
  rejection cases for both ops and the tightened terminal rule)
- `tests/Unit/Workflows/WorkflowOperationExecutorTest.php` — decision #1 (runtime semantics of
  both ops)
- `tests/Feature/WorkflowVariableCatalogTest.php` — decision #1 (the 68-op catalog count + the
  choice ops' label-less descriptor shape)
- `docs/decisions/ADR-0013-workflows-step-operations-conditionals-ai-text.md` — decisions #1, #5,
  which this ADR extends (the shared executor; the "validated union vs. runtime-only markdown"
  split)
- `docs/backend/workflows-api.md` — the "Choice fields" section (wire contract) + the updated
  write-time-validation table
- `docs/next/workflows-uxui-spec.md` — §4.9, updated for the choice-targeting Modal affordance
  ("Zamień na wybór" / match-to-choice mapping UI)
