# ADR-0021 — Variable catalog is composed from sources; adding a root is a 3-point change

**Date:** 2026-07-21 (created)
**Status:** Accepted
**Module:** `App\Modules\Workflows`
**Relates to:** ADR-0009 (typed variable system: one identity, two serializations), ADR-0013 (runtime operations/if-blocks/ai-text engine, the masking/injection-guard pattern this ADR generalizes)

---

## Context

Phase 0 of the Workflows variable-typesystem rework (branch `refactor/variable-typesystem`) starts
from a real constraint: `WorkflowVariableCatalogService` was **form-coupled** — its only entry
point was `forForm(Form $form)`, which unconditionally built the `FORM_SUBMITTED` trigger-system
variables and required a `Form` to layer field variables on top. A workflow with no form at all —
a `schedule` trigger, or a `form_submitted` workflow before its form is chosen — had no catalog to
call, so the frontend editor carried a static, hand-maintained mirror of the trigger-system
variables and step-output templates to render its variable picker in that case, drifting from the
backend by construction.

Phase 0 made the catalog **composable from sources** and **form-independent**:
`WorkflowVariableCatalogService::forContext(?WorkflowTriggerType $triggerType, ?Form $form = null)`
assembles the same structural sources — trigger-system vars, `steps.<TYPE>.*` output templates,
the operation catalog, the ai-text persona catalog, and (new this phase) the variable-type list —
regardless of whether a form is present; `forForm()` is now a thin `FORM_SUBMITTED`
specialization of it. A new `GET /workflows/catalog` endpoint exposes `forContext()` directly, so
a form-less workflow gets a real, live catalog instead of a static mirror — see
`docs/backend/workflows-api.md` for the endpoint contract.

This groundwork is what makes the rest of the rework tractable: every planned R2 catalog addition
— user-created **global** variables, a loop **item**, a template **slot**, campaign **inputs** —
is, at its core, a new top-level segment ("root") a reference path can start with. Before recording
how those land, this ADR fixes the recipe Phase 0 already establishes for teaching the engine a new
root, and the security invariant that recipe must never break.

## Decisions

1. **A variable ROOT is the first dotted segment of a reference path.** Today exactly two exist,
   `trigger` and `steps` (`WorkflowVariableResolver::ROOTS`). `trigger.fields.status` and
   `steps.create_task.task_id` are both valid references; `env.anything` or `__proto__.anything`
   are not references at all — `isReference()` rejects them and they are left as inert literal
   text (embedded) or resolve to `null` (standalone), never looked up.

2. **Adding a root is a 3-point change, all additive:**
   - **(1) Whitelist it** — add the segment to `WorkflowVariableResolver::ROOTS`. This is the ONE
     gate every resolution path shares (`isReference()`, used identically by the standalone/embedded
     directive resolver, the transitional flat `{{...}}` token resolver, and the structured
     value-or-variable union resolver). Miss this step and the new root's paths simply never
     resolve, no matter what data sits in the context.
   - **(2) Bind its data into the run context** — `WorkflowStepRunner::run()` builds the flat
     `{trigger, steps}` array handed to the resolver for the whole run; a new root adds one more
     top-level key, populated however that root's data actually arrives (a run-scoped value, like
     `trigger`/`steps` today, or a store read for a persistent root — see the tenant-safety rule
     below).
   - **(3) Register a catalog SOURCE** — add a method to `WorkflowVariableCatalogService`
     (alongside `triggerSystemVariables()` / `stepOutputVariables()`) and merge it into
     `forContext()`'s `variables` array, so the editor can browse/autocomplete the new root's
     paths. This is DISCOVERY only — it never gates resolution; the whitelist in (1) does that.

   **Nothing else in the resolver changes.** Resolution stays a dotted `Arr::get` lookup over the
   whitelisted context — fail-soft (a missing path resolves to `null`/stays literal, never throws)
   — with the existing injection guards applying uniformly to every root, old or new: the
   NUL-delimited placeholder masking that stops a resolved directive's looked-up value, or an
   `@[ai-text]` result, from being re-scanned as a second-order reference.

3. **Tenant-safety rules a new root MUST follow:**
   - **A store-backed root** (e.g. a future user-created `globals` root reading from a table) must
     be `WorkspaceScope`-bound — its catalog source and its run-context binding both read through
     the same tenant-scoped query every other Workflows read already uses. A root that could read
     another workspace's stored values would be a tenancy breach, not just a variable-system bug.
   - **A run-supplied root** (like `trigger`/`steps` today, or a future loop `item`) must be
     sanitized identically to the existing roots — its values flow into the same masking pass, so a
     resolved value that happens to contain literal `{{...}}` or `@[...]` bytes (untrusted,
     user-typed content) can never be re-interpreted as a second-order reference.
   - **No new root may widen what the resolver can READ beyond the whitelist.** The whitelist is
     the entire exfiltration boundary (`docs/backend/workflows-api.md` → "The resolver's whitelist
     (exfiltration-safe)"). A root is always an explicit, reviewed addition to `ROOTS`, never
     data-driven (never "any key present in `$context` is a valid root").

## Consequences

- **Positive:** every R2 catalog addition — global variables, a loop `item`, a template `slot`,
  campaign `inputs` — reduces to "one new source + one new root" through this recipe. None of them
  touch the resolver's security core (the whitelist gate, the fail-soft lookup, the masking/
  injection guards) — they extend WHAT can be referenced, never HOW a reference resolves. Phase
  0's `forContext()` composability is what keeps the catalog side additive too: a new source is one
  more array merged into `variables`, not a rewrite of `forForm()`'s call sites.
- **The whitelist is a SINGLE source of truth.** `WorkflowVariableResolver::ROOTS` is `public`
  and is read by BOTH the runtime resolver AND the write-side validator
  `StoreWorkflowRequest::validateVariableRef()` (a STRUCTURED `{kind:'variable',ref}` field's
  `ref.source` is validated against `WorkflowVariableResolver::ROOTS`, no longer a hardcoded copy).
  So the whitelist half of the recipe is genuinely one place: add the root to `ROOTS` and both
  resolution and write-validation accept it. (Markdown directive fields have no write-time source
  validation — there is no PHP markdown parser in this codebase — so they need nothing extra.)
- **Trade-off:** the recipe still touches THREE files (whitelist, context binding, catalog source)
  because runtime resolution, run-context wiring, and editor discovery are genuinely distinct
  concerns; it is not collapsible to one edit without coupling them.
- **Rejected:** resolving roots dynamically from whatever top-level keys happen to be present in
  the context, i.e. no `ROOTS` whitelist at all. Rejected outright as a tenancy/exfiltration risk —
  the whitelist is a deliberate allow-list specifically so a crafted `trigger_config`/
  `steps.*.config` value, or a future context key nobody intended to expose, can never become a
  readable path by accident.

See `docs/backend/workflows-api.md` (`### GET /api/workflows/catalog`, and the `types` catalog key
documented alongside `operations`/`ai_personas`) for the Phase 0 wire contract this groundwork
shipped.
