# ADR-0022 — Workflows variable type-system: descriptor representation, TIME, per-reference defaults, presence & date-format ops (Phase 1)

**Date:** 2026-07-22 (created)
**Status:** Accepted
**Module:** `App\Modules\Workflows`
**Relates to:** ADR-0009 (typed variable system: one identity, two serializations), ADR-0013
(runtime operations/if-blocks/ai-text engine — the masking/injection-guard pattern this ADR
reuses verbatim), ADR-0014 (choice-coercion — the append-only operation-catalog precedent this
ADR continues), ADR-0021 (Phase 0: the composable, form-independent catalog + the resolver
whitelist as the engine's single source of truth)

---

## Context

Phase 0 (ADR-0021, branch `refactor/variable-typesystem`, committed as `3e75f11`) made the
variable catalog composable and form-independent. Phase 1 — split into sub-batches **1a**
(catalog/descriptor-only changes) and **1b** (runtime/pipeline changes), plus their matching
frontend work — is the next iteration, driven by four small but genuinely independent gaps that
all needed closing before R2 (Generator/Templates) can add its own catalog roots on top of this
foundation:

1. **A form's TIME element had no identity of its own.** `FormElementType`'s `time` control
   (`format: 'time'` on the JSON schema) fell into `WorkflowVariableCatalogService`'s generic
   `text` catch-all alongside `url` and genuinely-unknown element types — indistinguishable from
   free text despite the form builder already offering it as a distinct, validated control.
2. **An enum/multi variable's options carried no human label.** `FormElementType::toJsonSchema`
   emits only the option VALUES into the schema's `enum` array — the `{value, label}` pairs
   authored in the element's `config.options` never survive into the catalog. The editor's
   variable picker, and a pipeline's `sourceOption`/`sourceMap`/choice args, rendered the raw
   stored value (e.g. `blog`) instead of the label an author actually typed for it (e.g. "Blog").
3. **A reference that resolves empty had no first-class fallback.** A blank form field or a step
   output that never fired left an author no lighter option than wrapping every use of that
   reference in a fenced if-block just to express "use X, or fall back to Y" — a common enough
   authoring need to deserve its own small affordance.
4. **The operation catalog had no presence vocabulary or safe date rendering.** Testing "is this
   set at all" (as opposed to comparing its value) and rendering a date in an author-chosen — but
   still safe — notation are both frequent building blocks for real automation content (e.g. "use
   the deadline if set, else the creation date, formatted DD/MM/YYYY") that the existing 72-op
   catalog (ADR-0013 + ADR-0014) had no operations for.

All four are ADDITIVE and behavior-preserving: an existing stored workflow, an existing directive,
and an existing `{kind:'variable'}` union all keep parsing and running byte-for-byte unchanged. No
migration was needed and none was run.

## Decisions

1. **A structured `descriptor` is ADDED to every catalog variable, alongside the unchanged flat
   `type` — the flat wire stays the closed contract, `descriptor` carries the richer shape.**
   `WorkflowVariableCatalogService::variable()` now emits `descriptor: { base, nullable, array,
   options? }` next to the existing `type`/`enumOptions?`/`nullable?` keys, built by the new
   `WorkflowVariableType::descriptor(array $options, bool $nullable)`:
   - `base` — the type's own scalar base, EXCEPT `MULTI`, whose base is `enum` (a multi is
     "an array of enum"); every other type, `TIME` included, is its own base
     (`descriptorBase()`'s `default` arm — appending a future case never breaks it).
   - `array` — `true` only for `MULTI`.
   - `nullable` — mirrors the variable's existing `nullable` flag (e.g. `trigger.task.id`).
   - `options` — present ONLY when `base === 'enum'`: a `{key, label}` list. `key` is the exact
     same string the flat `enumOptions` already carries (the wire value stored/matched at
     runtime — nothing downstream that keys off it changes); `label` is the human label, resolved
     from the form element's `config.options` (`schemaOptionLabels()` / `collectOptionLabels()` /
     `labeledOptions()` — one tree pass per form, no N+1) for a form field, or the value itself
     (`optionsFromValues()`) for a system/step enum variable that has no element config to read
     (e.g. `trigger.source`).

   The flat `type`/`enumOptions` were deliberately left UNTOUCHED rather than folded into a
   richer shape in place: every existing reader (the resolver's `coerce()`, the condition
   evaluator, the operation executor, and the frontend's closed `WorkflowVariableType` union) is
   built against the flat 7/8-member vocabulary, and changing what it carries — even losslessly —
   would force every one of those call sites to change in the same PR. Adding a second, richer
   field next to it costs one more key on the wire and lets every existing consumer ignore it
   completely.

2. **`WorkflowVariableType::TIME` is appended — descriptor-only this phase, a deliberate LOUD
   tripwire rather than a runtime feature.** `WorkflowVariableCatalogService::mapSchemaToVariableType()`
   now recognises `format: 'time'` and returns the real `TIME` case, so `descriptor.base` is
   `'time'` for a time field. But the FLAT wire `type` still degrades `TIME` to `TEXT`
   (`WorkflowVariableCatalogService::flatType()`), and `TIME.operatorCases()` returns `[]` (no
   condition operators — a stored `time` condition is rejected at write time exactly like before,
   never reaching evaluation). This is not an oversight: `WorkflowVariableResolver::coerce()`,
   `WorkflowConditionEvaluator`, and `WorkflowOperationExecutor::normalizeInput()` all still
   dispatch on an EXHAUSTIVE PHP `match` over the ORIGINAL 7 cases (no `default` arm), and the
   frontend mirrors a CLOSED 7-member `WorkflowVariableType` union. Letting `time` reach any of
   those today would either throw an `UnhandledMatchError` at run time or fail TypeScript
   compilation, for zero behavioral gain — there is no runtime TIME support to expose yet. Keeping
   `TIME` descriptor-only makes the gap LOUD and inspectable (the type exists, is visibly
   catalogued, and visibly does nothing beyond identification) instead of a silent landmine for
   whoever wires up real TIME semantics in a later phase.

3. **A per-reference literal `default` is added to both wire serializations, substituted
   BEFORE the pipeline runs, through the SAME NUL-mask injection-guard path a resolved value
   already uses — a security invariant, stated explicitly.** The markdown directive gained an
   optional `data.default` (read by `WorkflowVariableResolver::decodeDirective()`, tolerant of
   absence/non-scalar); the `{kind:'variable'}` union gained a sibling `default` key (read in
   `resolveVariableUnion()`). `WorkflowVariableResolver::applyDefault()` is the ONE place both
   serializations funnel through: when the looked-up value is `null` or `''`, the default replaces
   it; otherwise the real value is used untouched. This runs UNCONDITIONALLY — for an identity-only
   reference exactly as for a piped one — and, when a pipeline follows, the substituted default
   flows through the SAME `WorkflowOperationExecutor::normalizeInput()` a real value would, so a
   missing date can default to an ISO string and still be formatted by `date_format` downstream.
   Critically, the default is substituted at EXACTLY the point a real context value would be, so
   an embedded directive's result — default or not — is masked behind the existing NUL-delimited
   placeholder BEFORE the transitional flat `{{...}}` pass runs (see ADR-0013 / the resolver's
   "Reviewer fix" masking note). A default literal that happens to contain `{{...}}` or `@[...]`
   bytes (an author fat-fingering something reference-shaped into a fallback string) is therefore
   NEVER re-interpreted as a second-order reference — the exact same guarantee untrusted
   user-typed form content already had, extended for free because the default enters the pipeline
   through the identical code path. A standalone directive or a structured-slot default is never
   re-scanned at all (there is no second pass over that shape to re-interpret it against). The
   frontend only serializes `default` when it is non-empty
   (`encodeVariableDirective`/`ValueOrVariableField.vue`'s `saveModal()`), so a reference with no
   default is byte-identical to a pre-Phase-1 payload.

4. **5 append-only pipeline operations join `WorkflowOperation`, growing the catalog 72 → 77 —
   ids are only ever appended, never reordered or removed (the enum's own pinned wire-contract
   doctrine, unit-tested by `WorkflowConditionEngineTest::test_operation_ids_are_the_pinned_wire_contract`).**
   `coalesce` (nominal `text`→`text`, arg `fallback`), `is_present` (→`boolean`, nullary),
   `is_null` (→`boolean`, nullary), and `assert_present` (nominal `text`→`text`, nullary) form the
   PRESENCE family (`WorkflowOperation::isPresenceOp()`): `WorkflowOperationExecutor::execute()`
   dispatches them BEFORE the normal per-step `inputType() !== currentType` gate, so — unlike
   every other op — they accept the RUNNING value as-is regardless of its declared type, including
   a base that failed normalization outright (a genuinely absent/unrepresentable value). Their
   declared `inputType()`/`outputType()` (nominal `text`) are what the catalog/validator advertise
   today; the REAL runtime type-flow is the executor's own presence dispatch. `date_format`
   (`date`→`text`, arg `pattern`) is an ordinary (non-presence) op: it renders a `CarbonImmutable`
   through a SAFE-TOKEN whitelist (`YYYY MMMM MMM MM DD D HH mm` + the separators ` - / : . ,`,
   longest-token-first) compiled to a PHP `date()` format string — a raw PHP format string is
   NEVER accepted; any byte outside the whitelist fails the WHOLE pattern closed (soft failure,
   never a throw).

   **`assert_present` is the ONE opt-in HARD failure in the pipeline engine.** Over an empty
   value it returns the new `OperationResult::hardFailure()` (`OperationResult` gained a
   `bool $hard` flag) — still a `failed` result, so a CONDITION caller (which only ever reads
   `$result->failed`, e.g. an if-block's boolean check) is completely unaffected and stays
   fail-closed to `false` exactly as before. A VALUE-producing caller
   (`WorkflowVariableResolver::applyDirectivePipeline()` / `resolveVariableUnion()`) is the one
   place that additionally checks `$result->hard` and RE-RAISES it as a `RuntimeException`, which
   the run records as that step's failure — the run stops there, joining the field's existing
   hard-fail doctrine (e.g. a blank `create_task.title`). The executor ITSELF still never throws:
   the hard signal is a return value read by exactly one call site, not a language-level exception
   crossing the engine's own boundary.

## Consequences

- **Positive.** The frontend can finally show real option labels (`variableOptionList()` in
  `workflowVariables.ts` prefers `descriptor.options`, falling back to the flat `enumOptions`
  — label = value — only for older/label-less responses) without a second lookup or duplicating
  form config on the client; every wire consumer keeps keying off the unchanged `key`/value, so
  no downstream pipeline, condition, or choice-mapping (ADR-0014) needed to change. An author no
  longer needs an if-block just to supply "use X, or fall back to Y" — one small field
  (`ValueOrVariableField.vue`'s and `VariablePanel.vue`'s "Default when empty" input) does it.
  Presence testing and safe date formatting close two frequently-needed gaps without growing the
  "expression language" surface the whitelist doctrine (ADR-0009 / ADR-0021) deliberately keeps
  closed — every new op is still a single, total, fail-closed-or-fail-soft primitive like every
  op before it. All four changes are strictly additive on both wire formats, so nothing already
  stored needed a migration or a re-save to keep working.
- **Trade-off (accepted): `descriptor` duplicates information already expressible, for 6 of the
  8 types, via the existing `{type, enumOptions}` pair.** Accepted because the alternative —
  reshaping the flat fields in place — would touch the closed contract every existing consumer
  already trusts (see Decision 1); a second additive key is the cheaper, safer surface.
- **Trade-off (accepted, deferred to Phase 2): a validator/runtime asymmetry around the presence
  family.** `WorkflowConditionTreeValidator::walkPipeline()` — the write-time type-flow gate for a
  value-or-variable pipeline (`priority`/`deadline`/`submissions_from`/`submissions_to`) — was NOT
  touched this phase: it still requires an EXACT `op->inputType() === currentType` match at every
  step, including for `coalesce`/`is_present`/`is_null`/`assert_present` (nominally `text`), while
  the RUNTIME executor already bypasses that exact check for the same four ops. Today this is
  INERT (no shipped field pipeline opens with a presence op over a non-text ref, and a markdown
  directive's pipeline has no write validation at all — there is no PHP markdown parser in this
  codebase — so it is entirely unaffected), but it means a presence op is only WRITABLE at the
  start of a *value-or-variable* pipeline when the reference happens to be `text`-typed, narrower
  than what the engine can already execute. Left as-is deliberately to keep this batch's diff
  small and reviewable; relaxing `walkPipeline` to mirror the executor's bypass is real but
  separable work for Phase 2 (it needs its own threading design, the same way ADR-0014 had to
  thread `targetOptions` through that same validator for the choice ops).
- **Trade-off (accepted, deferred to Phase 2): two defensive gaps, neither reachable today.**
  `WorkflowOperationExecutor::normalizeInput()`'s `match` is still exhaustive over the ORIGINAL 7
  `WorkflowVariableType` cases with no `default` arm — a hypothetical future call with
  `baseType: TIME` would throw an `UnhandledMatchError` rather than failing closed. No such call
  exists today (`TIME` never reaches the executor as a base type — see Decision 2), so this is
  latent, not live; a defensive `default` arm is queued, not required, for this additive phase.
  Separately, `date_format`'s `pattern` argument is write-validated only as a generic string
  (`WorkflowOperationArgType::TEXT`, i.e. `is_string($value)`), never against the safe-token
  whitelist — a malformed pattern is caught only at RUN time (fails soft to `''`/`null`), never as
  a `422`. Both are named explicitly here so they read as accepted, tracked debt rather than an
  oversight discovered later.
- **Rejected: surfacing `time` on the flat wire type now, instead of degrading it to `text`.**
  Would require adding a `default` arm (or a full 8th case) to every exhaustive `match` this
  engine and the frontend union depend on, for a type that has no runtime behavior to expose yet
  — pure risk for zero present benefit. The descriptor is the ADDITIVE surface built exactly for
  landing a new base type without that blast radius.
- **Rejected: a general format-string / expression mini-language for `date_format`** (e.g.
  honoring a raw PHP `date()` format, or an ICU-style pattern). The module's foundational
  invariant — no expression language, ever; a plain whitelisted lookup with no filters,
  arithmetic, or method calls (see "the resolver's whitelist" in `docs/backend/workflows-api.md`,
  and ADR-0021's tenant-safety rules for the same whitelist-as-exfiltration-boundary posture) —
  exists specifically to keep the reference grammar a closed, auditable
  vocabulary. A raw format string is not itself a code-execution risk, but accepting one reopens
  exactly the "author-controlled mini-language" precedent that invariant exists to avoid, for a
  need a small closed token set already serves.
- **Rejected: letting `assert_present` throw directly out of `WorkflowOperationExecutor`.** The
  executor's "never throws" invariant is relied on by every existing caller with no try/catch
  anywhere in the condition-evaluation path (most importantly `WorkflowConditionEngine` and an
  if-block's boolean check, both explicitly fail-closed-to-`false` per ADR-0013). One throwing op
  would force every caller — including ones that must never hard-fail a condition — to add
  exception handling. The `OperationResult::hard` flag keeps the executor throw-free and lets the
  ONE caller that should escalate (a value-producing resolution) opt in explicitly.
- **Rejected: a variable-valued (not just literal) per-reference default.** A "default to this
  OTHER reference" would need its own resolution and recursion-depth bookkeeping — the same class
  of complexity as an if-block — for a need already served by composing an if-block or a
  `coalesce` pipeline step when it actually arises. A plain literal covers the common "fixed
  fallback text" case with a one-line reuse of the existing masking guard; not ruled out as a
  future Phase 2 richer default if real authoring demand shows up.

See `docs/backend/workflows-api.md` ("Structured `descriptor` (phase-1a)", "Per-reference
defaults (phase-1b)", and "Presence, null-handling, and date-format ops (phase-1b, append-only)"
under "The typed variable system" / "Runtime operations, if-blocks, and AI text") for the full
wire contracts this phase shipped, and the "Accepted residual risks" / "Planned / deferred"
sections for the Phase-2 items named above.
