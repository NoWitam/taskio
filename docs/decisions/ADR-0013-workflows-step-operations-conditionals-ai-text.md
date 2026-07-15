# ADR-0013 — Workflow steps: variable operations, conditional blocks, and AI-generated text at runtime

**Date:** 2026-07-14 (created)
**Status:** Accepted
**Module:** Workflows (`app/modules/Workflows/`)

> **Extended by ADR-0014 (2026-07-14, choice coercion).** Decision #1's operation count (66) grew
> to 68 with two new choice-producing terminals (`enum_to_choice` / `match_to_choice`) that let a
> value-or-variable pipeline map into a destination field's fixed option set (e.g. task
> `priority`) — see ADR-0014 for the design record. Decision #5's write-validation table
> (`create_task.priority` → `enum` or `text` terminal) is TIGHTENED by that ADR: a priority
> pipeline must now end in a choice-producing op specifically; the REST of this ADR (the shared
> executor, if-blocks, `@[ai-text]`) is unchanged.

---

## Context

ADR-0009 §2 shipped the typed variable system as deliberately IDENTITY-ONLY: a directive/union
reference could look a value up and coerce it, but never transform it — "an operations pipeline
(computed values: string concatenation, date formatting, conditional value selection) is
explicitly deferred… no user-facing requirement for computed transformations existed at design
time." Since then two things changed the calculus: (1) the CONDITION side of the module (a
separate, concurrently-developed batch) grew a typed operations pipeline of its own — a form
field run through operations that must terminate in boolean — proving out a 66-operation, 6-type
executor against real usage; and (2) real workflow authoring surfaced concrete needs an
identity-only reference cannot satisfy: "put tomorrow's date in the title" (a transform), "only
include this paragraph when priority is urgent" (a conditional block), and "write a short,
on-brand description for me" (generated text) are all common, not exotic, requests for a
task/report text field. This batch (SB1: operations + if-blocks; SB2: AI text) closes that gap
FOR STEP FIELDS specifically, reusing the condition side's executor rather than building a second
one.

---

## Decisions

### 1. `WorkflowOperationExecutor` — ONE engine for the 66 operations, shared by conditions AND steps

**Decision:** The 66 operation implementations (`WorkflowOperation`: 16 text, 16 number, 3
boolean, 18 date, 6 enum, 7 multi) live in exactly ONE place, `WorkflowOperationExecutor` —
extracted from what were previously PRIVATE methods on `WorkflowConditionEngine`. It exposes one
method, `execute(baseValue, baseType, pipeline, context): OperationResult`, and is fail-closed by
construction (`OperationResult::failure()` for any unknown op, type mismatch, unparseable
arg/date, divide-by-zero, unmapped enum option, or over-long pipeline — never an exception).
`WorkflowConditionEngine` feeds it a condition's pipeline and requires a boolean-true terminal;
`WorkflowVariableResolver` feeds it a directive / if-block-condition / value-or-variable pipeline
and reads the typed (or stringified) result.

**Alternatives rejected:**
- **A second, step-scoped operations engine, independent of the condition engine's** — rejected:
  the condition side already had a working 66-op, 6-type executor (extracted from
  `WorkflowConditionEngine`'s own private methods); duplicating its semantics (1-based substring,
  0=Sunday weekday, strict-Y-m-d date parsing, fail-closed divide-by-zero, …) for steps would
  create exactly the "two engines that can drift" problem ADR-0009 §2 already warned against for
  a hypothetical future pipeline. A single shared engine means the SAME op id behaves identically
  whether it appears in a gate condition or a step's title.
- **Leave the operations private inside `WorkflowConditionEngine` and have the resolver call
  into it directly** — rejected: `WorkflowConditionEngine` also owns condition-tree-specific
  concerns (group/AND-OR evaluation, the legacy flat-list delegation) that have nothing to do with
  running a bare pipeline over a value. Extracting the operation execution into its own class
  keeps each class's responsibility singular and makes the shared dependency explicit in both
  constructors.

**Rationale:** "One source of truth per concept" is the same doctrine the module already applies
elsewhere (one schedule compiler, one catalog service, one condition-tree validator) — the 66
operations are a single vocabulary the FRONTEND also mirrors byte-for-byte
(`standardOperations.ts`), so the backend side of that vocabulary should be equally singular.

**Consequence:** `WorkflowConditionEngine` and `WorkflowVariableResolver` both take
`WorkflowOperationExecutor` as a constructor dependency; neither re-implements operation
semantics. `docs/backend/workflows-api.md`'s "Runtime operations, if-blocks, and AI text"
section and the Conditions section both point at the same executor.

---

### 2. ADR-0009 §2 REVERSED for step fields: directive/value-or-variable pipelines are now built and executed at runtime

**Decision:** A step's markdown directive (`@[variable]("…")`) and a value-or-variable union
field (`{kind:'variable', ref, pipeline?}`) may now carry a NON-EMPTY operations pipeline that
`WorkflowVariableResolver` executes at RUN time (see `docs/backend/workflows-api.md` for the
full mechanics). This explicitly REVERSES the relevant part of ADR-0009 §2's "Consequence —
PLANNED, not built" note ("an operations pipeline… is explicitly deferred"). Everything else
ADR-0009 §2 decided remains true and UNCHANGED: the ONE canonical identity (`{source, path,
type}`), the TWO serializations (directive vs. union), the identity-only DIRECTIVE PAYLOAD shape
(no `wfType`-style embedded type field — the pipeline's base type is still recovered from the
catalog/type-map, never trusted from the directive), and the resolver's `trigger`/`steps`-only
whitelist.

**Alternatives rejected:**
- **A THIRD serialization specifically for "variable + pipeline"** — rejected: the existing two
  serializations already have a natural extension point (the directive's `data.pipeline` array
  was always part of the editor's directive schema, simply ignored by the resolver until now; the
  union's `pipeline` key is a natural sibling to `ref`). Adding a third shape would fragment the
  "one identity, two serializations" property ADR-0009 §2 established for no benefit — both
  existing shapes already had room to grow into exactly this.
- **Keep pipelines identity-only forever and instead teach steps to accept richer LITERAL
  authoring (e.g. a date-math helper in the deadline picker)** — rejected: this would solve one
  narrow case (date arithmetic on a literal) while leaving "transform a REFERENCED value" (the
  actually-requested capability) unsolved, and would need its own bespoke UI per field rather than
  reusing the condition side's already-built, generic pipeline editor.

**Rationale:** The condition side proved the pipeline model (a typed base value, a bounded list
of `{op, args}` steps, a typed terminal) generalizes cleanly beyond "must end in boolean" — a
step's title/description/priority/deadline is exactly the same shape of problem ("transform this
typed value, then use the result"), just with a different accepted terminal set. Reusing the
proven engine was strictly cheaper and safer than inventing new pipeline semantics for steps.

**Consequence:** `docs/backend/workflows-api.md`'s banner and "typed variable system" section are
corrected to describe pipeline execution instead of "MVP references are identity-only, pipeline
content is ignored." `docs/next/workflows-uxui-spec.md` and the in-app docs page's "Planned /
deferred" list drop "an operations pipeline… deliberately deferred" as a planned item — it is
built.

---

### 3. Conditional `if-block`s in step text fields — runtime-only, fail-closed, depth-capped at 6

**Decision:** A step's multi-line text field (`description`, `guidelines`) may contain a fenced
`` ```if-block `` container — the SAME byte format the next Markdown editor already serializes
for its generic if-block feature (used elsewhere for e.g. condition-authoring UX). At run time,
`WorkflowVariableResolver` evaluates each branch's condition (a `variableId` + a pipeline through
`WorkflowOperationExecutor`, required to terminate boolean-true) IN ORDER and substitutes the
WHOLE fence with the winning branch's body, resolved recursively. Nesting is capped at depth 6
(`IF_BLOCK_MAX_DEPTH`) — a margin over the editor's own authoring default of 3, so a document the
editor itself would never author past depth 3 still has headroom if hand-edited or migrated.
Every failure mode (a missing/foreign variable, an unparseable condition, an executor failure) is
fail-closed to `false` (picks `ELSE`, or `''` with none) — NEVER surfaced as a validation error,
because **there is no PHP markdown parser in this codebase to validate it at write time.** A
step's text-field content is therefore accepted as opaque markdown on save; if-block correctness
is a pure runtime property.

**Alternatives rejected:**
- **Write-time structural validation of the if-block fence** (parse the markdown server-side,
  check fence/branch well-formedness) — rejected as disproportionate: it would require
  introducing a markdown/if-block PARSER on the backend (a new dependency and a new place for the
  FE/BE parsers to drift) purely to catch a class of error that is already fail-closed and
  harmless at runtime (a malformed if-block simply resolves emptier than intended, it never
  corrupts data or crashes a run). The value-or-variable pipeline gets write validation (decision
  #5) specifically BECAUSE it lives in a structured, already-parsed field — the markdown case has
  no equivalent structured representation to validate without first building a parser.
- **A different depth cap than 6** — rejected in favor of "editor default + margin": exactly
  matching the editor's own default (3) would make ANY off-editor content (a hand-crafted config,
  a future import path) with slightly deeper nesting silently truncate even though the RUNTIME has
  no real reason to refuse it; the cap exists to bound worst-case recursion cost, not to enforce
  the editor's UX default.

**Rationale:** Fail-closed-to-false is the same posture the condition tree already takes for a
missing/foreign source — a misconfigured or stale conditional should degrade the CONTENT of a
field, never crash or block the run. Runtime-only validation is an honest consequence of the
codebase's existing constraint (no markdown parser), not a corner cut for this feature
specifically — the same constraint already applied to every other piece of markdown content a
step field accepts.

**Consequence:** `docs/backend/workflows-api.md` documents the if-block contract as RUNTIME-ONLY,
explicitly contrasted with the value-or-variable pipeline (decision #5), which IS validated.

---

### 4. `@[ai-text]` — real AI generation at run time, budgeted per run, persona = tone only (not the bot system)

**Decision:** A step's multi-line text field may contain an `@[ai-text]("…")` directive whose
`prompt` (itself resolved through the SAME resolver first, so nested variables/if-blocks/AI-text
resolve before the model sees it) is sent to a new, tool-less `WorkflowAiTextAgent` via
`WorkflowAiTextService`. The service is FAIL-CLOSED (a blank prompt, an exhausted per-run budget,
or any provider/transport failure all resolve to `''`, logged, never thrown) and BUDGETED per run
(`config('workflows.ai_text_max_calls_per_run')`, default 10 — a NEW run-scoped counter,
independent of the existing run-COUNT cost caps) and length-capped
(`config('workflows.ai_text_max_chars')`, default 2000). `WorkflowAiPersona` is a small, CLOSED
set of TONES — `neutral` (default) | `friendly` | `formal` | `concise` — each just a short
English style line folded into the agent's system instruction; the model is always told to WRITE
in the language of the (resolved) prompt, so the English tone line never forces English output.

**Alternatives rejected:**
- **Reuse the Bot/Character system as the "persona"** (let the author pick one of their
  workspace's configured Bots to write the text) — rejected for THIS batch: a Bot carries tools,
  a model/provider choice, and a broader "character" concept meant for autonomous task execution,
  none of which apply to "write one short piece of text for a field, with no tools, right now." A
  Bot-as-persona would also couple a workflow definition to a specific Bot's lifecycle (deleted,
  reassigned, disabled) for a feature that only ever needs a TONE. **Left as a possible future**
  (not built): if a real need for "write like Bot X" emerges, it should be evaluated as its own
  decision, not smuggled in as a rename of the current closed tone set.
- **Trust the model's output unconditionally (no length cap, no budget)** — rejected: the same
  "never trust a model's self-report at face value" posture the schedule-assist feature already
  established (ADR-0009 §4) applies here too, scaled to the risk actually present — a length cap
  bounds a runaway generation, and a per-run call budget bounds fan-out spend from a single
  workflow run (many `@[ai-text]` directives across many steps/fields in one run).
- **Meter `@[ai-text]` calls against the existing per-workflow/month run budget instead of a new
  per-run counter** — rejected: the run-count budget (`max_runs_per_month`,
  `max_runs_hard_cap`) meters HOW MANY RUNS happen; it says nothing about how much a SINGLE run
  can internally fan out. A run with five `@[ai-text]`-carrying fields should not silently consume
  five months' worth of budget from one execution — a dedicated per-run cap is the correct unit
  for "cost incurred inside one execution," mirroring how `max_depth` is also a PER-RUN-CHAIN
  bound distinct from the monthly run count.

**Rationale:** The tool-less, no-structured-output agent design mirrors `ScheduleAssistAgent`'s
established pattern in this module (plain text/JSON out, no tool calls, defensive parsing) —
consistency with an already-reviewed pattern rather than inventing a new agent shape. Persona as
tone-only keeps the feature's blast radius narrow and its behavior fully predictable (a fixed,
reviewable style instruction set) rather than delegating tone to a user-configured, potentially
tool-bearing Bot.

**Prompt-injection posture (accepted, bounded risk) — a decision, not an oversight.** The
resolved prompt necessarily embeds values taken from user-submitted forms (untrusted input). The
agent's instructions frame everything in the prompt as DATA to write about, never as commands,
and instruct the model to ignore any embedded attempt to change its rules. This mitigation is NOT
a proof of safety — a sufficiently adversarial form submission could still influence the
generated text's CONTENT. The decision to accept this residual risk rests on the blast radius
being narrow BY CONSTRUCTION: the agent has no tools (nothing to abuse into a side effect), its
output lands only in a task/report text field inside the SAME workspace the run belongs to, that
output is length-capped, and it can reference only the already-whitelisted `trigger`/`steps`
context. No mitigation beyond prompt framing + narrow blast radius is implemented; a future
hardening pass (e.g. output sanitization/classification) is possible but not scoped here.

**Consequence:** `config/workflows.php` gains `ai_text_max_calls_per_run` /
`ai_text_max_chars`. `GET /forms/{form}/workflow-catalog` gains a label-less `ai_personas`
sibling key (mirroring the existing label-less `operations` catalog pattern). `docs/backend/
workflows-api.md` documents the full contract including the fail-closed table and the
injection-posture note.

---

### 5. Value-or-variable pipelines ARE write-validated; markdown directive pipelines are NOT (and cannot be, without a new parser)

**Decision:** The `{kind:'variable', ref, pipeline?}` union field's optional pipeline (used by
`priority`, `deadline`, `submissions_from`, `submissions_to`) IS validated on save —
`WorkflowConditionTreeValidator::validateValuePipeline()` walks it from `ref`'s declared type
through each op to a REQUIRED terminal type per field (`priority` → enum|text; the three date
fields → date), reusing the exact same op/arg validation the condition tree already applies. A
markdown directive's pipeline (inside `title`/`description`/`name`/`guidelines`) is NOT
validated on save — see decision #3's rationale (no markdown parser exists server-side to inspect
it).

**Alternatives rejected:**
- **Skip write validation for the value-or-variable pipeline too**, treating it exactly like the
  markdown case for consistency — rejected: the union field is a STRUCTURED (JSON) value, already
  fully parsed by the time `StoreWorkflowRequest` sees it — there is no missing-parser excuse here,
  and these fields (`priority`, `deadline`, report windows) feed directly into typed DTO fields
  that themselves demand a specific type, so validating early gives a clear 422 instead of a
  confusing soft-default (`priority` silently becoming `medium`) at run time.
- **Build a minimal if-block/directive-aware markdown validator just for this** — rejected as
  disproportionate scope for the value this batch is delivering; see decision #3.

**Rationale:** "Validate what you can cheaply and correctly parse; accept and fail closed at
runtime for what you cannot" is a consistent, honest split — it is not an inconsistency to flag,
it is the direct consequence of one field being structured JSON and the other being freeform
markdown with no server-side parser.

**Consequence:** `docs/backend/workflows-api.md`'s "Write-time validation" subsection documents
both halves of this split explicitly side by side, so a future contributor does not mistake the
asymmetry for an oversight.

---

## Alternatives considered and rejected (module-wide, not decision-specific)

- **Do nothing — keep ADR-0009 §2's identity-only stance permanently** — rejected: real authoring
  needs (date math in a title, a conditional paragraph, AI-drafted text) surfaced quickly enough,
  and the condition side had already proven the underlying engine, that shipping a second
  identity-only cycle would have meant reinventing the same pipeline model later anyway, at
  higher cost (a second executor to reconcile with the condition side's).
- **A general-purpose expression language** (arbitrary function calls, string templates with
  logic) instead of a closed, enumerated operation set — rejected for the same reason ADR-0008 #4
  and ADR-0009 §2 already rejected it for conditions: a closed, enumerated vocabulary is what lets
  the write-path validator and the frontend's pipeline editor stay in lockstep (every op has a
  known input/output type and arg shape) and keeps the resolver's `Arr::get`-only whitelist
  property intact — an expression language would reopen exactly the exfiltration-surface question
  ADR-0008 #4 closed.

---

## Related files

- `app/modules/Workflows/Services/WorkflowOperationExecutor.php` — decision #1 (the shared 66-op engine)
- `app/modules/Workflows/DTOs/OperationResult.php` — decision #1 (the fail-closed result shape)
- `app/modules/Workflows/Enums/WorkflowOperation.php` — decision #1 (the 66-operation catalog + descriptors)
- `app/modules/Workflows/Services/WorkflowConditionEngine.php` — decision #1 (the condition-tree consumer)
- `app/modules/Workflows/Services/WorkflowVariableResolver.php` — decisions #2, #3, #4 (directive pipelines, if-blocks, ai-text orchestration)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — decisions #1, #2, #4 (`runtimeTypeMap`, `referenceIndex`, `ai_personas` in the catalog)
- `app/modules/Workflows/Http/Requests/StoreWorkflowRequest.php` — decision #5 (`validateVariablePipeline`/`validateUnionOrLiteral`)
- `app/modules/Workflows/Services/WorkflowConditionTreeValidator.php` — decision #5 (`validateValuePipeline`, shared arg/type-flow rules)
- `app/modules/Workflows/Enums/WorkflowAiPersona.php` — decision #4 (the closed tone set + label-less catalog)
- `app/modules/Workflows/Agents/WorkflowAiTextAgent.php` — decision #4 (the tool-less generation agent, injection-framing instructions)
- `app/modules/Workflows/Services/WorkflowAiTextService.php` — decision #4 (fail-closed, per-run budget, length cap)
- `config/workflows.php` — decision #4 (`ai_text_max_calls_per_run`, `ai_text_max_chars`)
- `app/modules/Workflows/Http/Controllers/WorkflowVariableCatalogController.php` — decision #4 (`ai_personas` response key)
- `resources/js/next/ui/editor/extensions/standardOperations.ts`, `extensions/VariablePipelineEditor.vue`, `extensions/IfConditionPanel.vue`, `extensions/aiText.ts`/`AiTextPanel.vue` — the frontend authoring surfaces these decisions serialize for (see `resources/js/next/ui/editor/README.md`)
- `resources/js/next/pages/workflows/WorkflowStepCard.vue`, `ValueOrVariableField.vue`, `DateOrVariableField.vue`, `workflowVariables.ts` — the step-editor wiring of the typed variable feed + pipelines
- `docs/backend/workflows-api.md` — "Runtime operations, if-blocks, and AI text" section (the rewritten runtime contract) + the Steps section's per-field capability note
- `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` — §2, whose "operations pipeline deferred" consequence this ADR reverses (the rest of §2 — the ONE identity, TWO serializations, and the wfType-rejection incident — remains authoritative and unchanged)
- `docs/next/workflows-uxui-spec.md` — §4.6/§4.7, updated to the as-built step editor (collapsible cards, type-selection add cards, the Value|Variable toggle + pipeline, if-block/AI-enabled multi-line fields)
- `docs/decisions/ADR-0014-workflows-choice-coercion.md` — extends decisions #1 and #5 with the two choice-producing terminals and the per-field `targetOptions` mechanism
