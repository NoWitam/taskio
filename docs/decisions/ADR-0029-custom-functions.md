# ADR-0029 — Custom functions: user-defined pipeline operations, nesting, and the fail-closed safety design

**Date:** 2026-07-26 (created)
**Status:** Accepted
**Module:** `App\Modules\Variables`
**Relates to:** ADR-0027 (the module extraction this feature is built on — Phase 3 of the same program),
ADR-0028 (consts — the sibling first-class Variables concept this feature's nav/persistence pattern
mirrors), ADR-0013 (`WorkflowOperationExecutor`/now `OperationExecutor` as a PURE, contextless
transformer — the invariant custom-function execution had to fit inside without breaking),
ADR-0025 (operation arguments as variables — the write-time `$refCtx`/`$argDepth` threading machinery
custom-function args reuse), ADR-0026 (array transform operations — the scoped `element`/`index`
overlay and `ScopeRef` generalization this feature's FRAME STACK builds directly on)

---

## Context

ADR-0027 gave the type system and pipeline engine a home outside Workflows; ADR-0028 gave workspace
CONSTANTS a home there too. The natural third piece — and the reason both of those needed to exist first
— is letting a workspace member define their OWN reusable pipeline OPERATION: a named transform with
typed inputs and a typed output, built once from the SAME 83-op vocabulary every workflow pipeline
already uses, and then reusable from ANY pipeline exactly like a built-in op. A workflow author who
repeatedly composes the same 4-step "normalize a phone number" or "compute a due-date from a priority"
pipeline inline, field after field, gains no way to name that composition and reuse it — this feature
closes that gap.

The interesting design work is almost entirely SAFETY: a user-authored operation that can reference OTHER
user-authored operations is, by construction, a small user-programmable graph — and a graph a user
controls is exactly where a cycle (`A` calls `B` calls `A`) becomes a real risk, not a hypothetical one.
This ADR records the shape of the feature and, in detail, the two independent fail-closed backstops that
make nesting safe: a write-time graph check that REJECTS a cycle before it can be saved, and a
runtime depth/visited-set pair that FAILS CLOSED if a cycle ever reaches execution anyway (a corrupted,
hand-written, or raced row that bypassed the write check).

## Decisions

1. **Identity is the DB uuid; the wire op id is `fn:<uuid>`; the editable `name` is a user-facing label
   only.** A `CustomFunction` row's primary key IS its identity — renaming a function never changes its
   uid, so a pipeline step already saved as `{"op": "fn:b1b2c3d4-..."}` never breaks when the function is
   renamed. `fn:` is a RESERVED prefix: `OperationResolver::resolve()` checks built-in ops FIRST
   (`Operation::tryFrom($id)`), and only when that misses AND the id starts with `fn:` does it search the
   supplied custom functions — so a function's uid can never collide with a built-in op id (none starts
   with `fn:`) or with the `globals` wire root (a completely different namespace: op ids vs. reference
   roots). A `fn:<uuid>` that resolves to nothing (a deleted function, a cross-workspace uid, a typo in a
   hand-written row) resolves to `null` — the caller then fails CLOSED (the write-time walk rejects an
   unknown op; the runtime executor returns a failure), never a silent mis-resolution.

2. **A function definition: ONE input type, typed NAMED ARGS, ONE return type, a saved BODY pipeline over
   `{input + args}` terminating in the return type.** Persisted on `CustomFunction`
   (`app/modules/Variables/Models/CustomFunction.php`, table `custom_functions`, central + tenant
   migrations, workspace-scoped like every other tenant-aware model, `HasCreator`-stamped, no
   soft-delete):

   | Column | Shape |
   |---|---|
   | `name` | string — a label only, NOT unique (the uuid is identity) |
   | `description` | nullable text |
   | `input_type` | one `VariableType` id |
   | `args` | JSON list of `{name, description?, type}` — a typed, named argument |
   | `return_type` | one `VariableType` id |
   | `body` | JSON — the saved pipeline steps `Array<{op, args}>` |

   An arg name must be a safe identifier (`/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/`), UNIQUE within the function,
   and may NOT be `input`, `element`, or `index` — the three names already reserved by the scope machinery
   (Decision 6) — enforced by `FunctionDefinitionValidator::RESERVED_ARG_NAMES`. Args have NO default
   values in this version (every declared arg is required at every call site).

3. **CRUD: `GET/POST /api/functions`, `GET/PUT/DELETE /api/functions/{function}`** —
   `CustomFunctionController`/`Store`/`UpdateCustomFunctionRequest`/`CustomFunctionResource`/
   `CustomFunctionPolicy`/`CustomFunctionService`, the identical shape ADR-0028 established for consts
   (workspace-membership read, creator-only mutation via `ChecksRecordOwnership`). `FunctionDefinitionValidator`
   is the SINGLE authority for a definition's shape — input/return types, arg names/types, and the body —
   shared by Store/Update exactly as `ConstantTypeValidator` is for a const.

4. **Functions CAN NEST — a body may call another function — validated write-time with a 3-COLOUR DFS
   over the reference graph.** `FunctionDefinitionValidator::validateAcyclic()` builds a directed graph —
   one node per workspace function, an edge `A → B` whenever `A`'s body references `fn:B` (collected by
   `referencedFunctionIds()`, which walks the body recursively, matching any `op`/`operationId` key
   carrying the reserved `fn:` prefix at ANY nesting depth: inside element pipelines, argument-variable
   sub-pipelines, choice-rule `when`s, reducers) — with the row currently being saved SUBSTITUTED into the
   graph as its own node (keyed by its own uuid on UPDATE; a synthetic, unreferenceable node on CREATE, so
   a brand-new function can never itself be part of a cycle — nothing can reference a uuid that does not
   exist yet). `hasCycle()` runs a standard three-colour DFS (0 = unvisited, 1 = visiting, 2 = done); a
   back-edge to a VISITING node — including a node's edge to itself (a direct self-reference) — is a
   cycle. Any cycle anywhere in the graph, not just one touching the row being saved, is rejected with a
   422 under `body`: **"A function may not reference itself, directly or through another function (a
   cycle was detected)."** This check runs REGARDLESS of any type/body error elsewhere in the same
   request — a self-reference is always caught even if the rest of the definition is also invalid.

5. **Runtime: a SECOND, INDEPENDENT fail-closed backstop — `PipelineLimits::MAX_FUNCTION_EXPANSION_DEPTH`
   (5) plus an active-function VISITED-SET — because the write-time graph check cannot see a row that
   bypassed it.** A corrupted, hand-written, or raced database row is a real possibility the write path
   cannot prevent by construction (unlike, say, an argument-variable's depth, which ADR-0025 proved has NO
   possible cycle at all). `App\Modules\Variables\Support\FunctionScope` carries this state through the
   (pure, re-entrant) `OperationExecutor`:

   - `overDepth(): bool` — `true` once entering one more function would exceed
     `PipelineLimits::MAX_FUNCTION_EXPANSION_DEPTH`. Bounds LEGITIMATE deep nesting AND any cycle (a cycle
     that somehow got saved would recurse forever without this — the depth cap turns "forever" into "at
     most 5 expansions, then fail closed").
   - `hasVisited(string $functionId): bool` — `true` when `$functionId` is already on the ACTIVE call
     chain (`$this->visited`, appended by `enter()` on every expansion). This is the backstop the depth cap
     alone does not give you: it catches a cycle on its FIRST re-entry, at whatever depth it happens to
     occur, rather than only once the cap is exhausted.

   `OperationExecutor::expandFunction()` checks BOTH, unconditionally, before doing anything else:

   ```php
   if ($scope->overDepth() || $scope->hasVisited($op->id())) {
       return self::FAIL; // fail CLOSED — never loops, never throws
   }
   ```

   Both gates return the SAME `OperationResult::failure()` an ordinary type-mismatch or unknown-op
   already returns — no new failure channel, no exception, consistent with the executor's pre-existing
   "never throws" doctrine (ADR-0013). A condition built on a function that hits either gate simply
   evaluates `false`; a value-producing field simply resolves to its own soft default.

6. **Execution is BY EXPANSION: the engine binds `{input, <argName>…}` into a scope FRAME and RE-ENTERS
   the SAME `execute()` on the function's saved body, one level deeper — there is no second interpreter.**
   `OperationExecutor::expandFunction()`:

   1. Builds the frame — `functionFrame()` — `{'input': {value: <the running value>, type: <the
      function's declared input type>}, '<argName>': {value: <that arg's PRE-RESOLVED literal>, type:
      <the arg's declared type>}, ...}` for every declared arg (a missing arg — only possible from a
      corrupted config, since the write validator requires every declared arg — binds `null`, which the
      body's first consuming op then fails closed on, same as any other absent value).
   2. `$scope->enter($op->id(), $frame)` — returns a NEW `FunctionScope` at `depth + 1`, with `$op->id()`
      appended to the visited chain, and the frame INSTALLED (replacing any caller frame outright — see
      Decision 7 for why this matters).
   3. Builds a FRESH context carrying ONLY the entered scope (`$entered->writeInto([])`) — a function body
      is PURE over `{input, args}`: it is NEVER handed the caller's `trigger`/`steps`/`globals`, nor any
      enclosing array-element `scope` overlay. A stray reference to any of those inside a (corrupted) body
      simply resolves to nothing.
   4. Pre-resolves the body's own TOP-LEVEL `input`/`<argName>` scope references to literals for this call
      (`resolveScopePipeline()`, reading the just-installed frame) and re-enters `execute()` on the
      resolved body, at the SAME `$elementDepth` (function-expansion depth and array-element depth are
      tracked independently — see Decision 7).
   5. VERIFIES the body's terminal type EXACTLY equals the function's declared return type — a mismatch
      (only reachable from a corrupted row; the write validator already forces this equality) fails CLOSED
      rather than silently coercing a wrong-typed value that could open a downstream gate. A body that
      itself fails (an unknown op, a nested cycle, an `assert_present` HARD failure) also fails the
      function CLOSED — notably, a HARD failure does **NOT** escalate past a function boundary the way it
      would at top level (ADR-0022): the function absorbs it into an ordinary fail-closed result, so a
      boolean-returning function used inside a condition simply reads `false` rather than aborting the run.

   The executor itself gains NO new interpreter, no recursive-descent evaluator, no bytecode — "execution"
   of a custom function is nothing but a bound frame plus a deeper call to the exact same `execute()`
   every other pipeline already runs through, which is what keeps the addition small and keeps every
   existing invariant (fail-closed, never throws, pure/contextless besides the threaded scope) intact for
   free.

7. **The FRAME STACK: `ScopeRef` generalizes from a fixed `element`/`index` root pair to a CALLER-SUPPLIED
   root list, so an array-transform pipeline (map/filter/sort/reduce, ADR-0026) NESTED INSIDE a function
   body can see BOTH scopes at once.** Before this feature, `ScopeRef::leaf()` always checked a reference's
   path root against the fixed pair `['element', 'index']`. It now takes an explicit `$roots` parameter
   (defaulting to that same pair, so every pre-existing call site is byte-identical), and
   `OperationExecutor::scopeRoots(array $context): array` computes the LIVE root set for wherever
   execution currently is:

   ```php
   private function scopeRoots(array $context): array
   {
       $frameRoots = FunctionScope::fromContext($context)->frameRoots(); // e.g. ['input', 'amount']

       return $frameRoots === []
           ? ScopeRef::DEFAULT_ROOTS                                     // ['element', 'index'] — unchanged
           : array_values(array_unique([...ScopeRef::DEFAULT_ROOTS, ...$frameRoots]));
   }
   ```

   `FunctionScope::frameRoots()` returns the distinct first path segment of every binding key currently in
   the frame (`input`, plus each arg name). So an `array_map` element pipeline that sits INSIDE a custom
   function's body resolves references against the UNION of the map's own `element`/`index` AND the
   enclosing function's `input`/`<argNames>` — a body computing "for each item in a list arg, multiply it
   by the `rate` arg" can reference `element` (the current list item) and `rate` (the function's own arg)
   in the SAME element pipeline. **This is the "frame stack" the feature's design brief called for** — not
   an unbounded stack of every ancestor function's frame (Decision 8 explains why that is unnecessary), but
   the two scopes that are ever simultaneously live: the CURRENT function's own `{input, args}` frame, and
   — only while inside one — the CURRENT array-element pipeline's `element`/`index`.

   **The write side is validated with the IDENTICAL union, not a parallel rule someone could let drift.**
   `PipelineValidator::validateElementPipeline()` merges `enclosingFrameOnly($enclosingScopeVars)` — the
   caller's function-body scope vars, with `element`/`index`/`element.*` stripped out to avoid a stale
   inner frame leaking across an already-closed nesting level — with the FRESH `element`/`index` (+ any
   `element.<subfield>` entries) for the pipeline being walked. This is, deliberately, the exact mirror of
   `OperationExecutor::scopeRoots()`'s union — the same "one shared definition read by both sides" doctrine
   every other cap/whitelist in this engine already follows (ADR-0025's `MAX_ARG_VARIABLE_DEPTH`,
   ADR-0026's `MAX_ELEMENT_PIPELINE_DEPTH`/`MAX_ARRAY_ITERATIONS`), so the write validator can never accept
   a scope reference the runtime would then fail to resolve, or vice versa.

8. **A function CALL does NOT stack frames across function boundaries — `FunctionScope::enter()` REPLACES
   the frame, it does not push onto it.** When function `A`'s body calls function `B`, `B`'s frame
   (`{input: <A's argument to B>, <B's own arg names>: ...}`) REPLACES `A`'s frame entirely for the
   duration of `B`'s execution — `B`'s body cannot reference `A`'s `input` or `A`'s own arg names, only its
   OWN. This is a deliberate reading of "a function body is pure over `{input, args}`": a function's
   contract is exactly its declared signature, so letting a callee reach into its caller's bindings would
   make that contract a lie (the same value could behave differently depending on who happened to call it).
   The frame stack of Decision 7 is therefore exactly two levels deep in practice — the currently-executing
   function's own frame, plus one array-element overlay nested inside it — never deeper, regardless of how
   many functions are nested inside one another (the DEPTH cap and the VISITED-SET, Decision 5, are what
   bound the CALL chain itself; the frame the innermost call can see is always just its own).

9. **The delete guard: BLOCKED with a 422 while any OTHER function, or any WORKFLOW, still references
   it — fail-closed, never a silent dangling reference.** `CustomFunctionService::delete()` checks, in
   order: (1) `isReferencedByAnotherFunction()` — scans every OTHER workspace function's body for a
   `fn:<this-uuid>` op, reusing the SAME `FunctionDefinitionValidator::referencedFunctionIds()` edge
   extraction the cycle graph uses, so a uuid-prefix false match can never wrongly block or allow a delete;
   (2) `isReferencedByWorkflow()` — delegated through `FunctionReferenceLookup` (ADR-0027 Decision 6) to
   `WorkflowFunctionReferenceScanner`, which scans every LIVE (non-trashed), workspace-scoped `Workflow`'s
   `steps`/`conditions` for the same `fn:<uuid>` pattern. Either match blocks the delete with a distinct
   `ValidationException` message under `function`. This is the SAME doctrine ADR-0024 established for a
   const's own would-be delete-while-referenced case (a const has no such guard today — deleting one just
   fails soft at runtime — see ADR-0024 Consequences) applied more strictly here, because an UNRESOLVABLE
   function reference does not merely go blank like a missing constant value would; it makes the WHOLE
   pipeline step it appears in fail closed, a materially worse silent break for something a user may not
   even remember referencing a function.

10. **The catalog merge: every workspace function appears as an operation on every pipeline surface,
    filtered by input type exactly like a built-in.** `WorkflowVariableCatalogService::forContext()`
    merges `Operation::catalog()` (the 83 built-ins) with `functionCatalog()` — one entry per workspace
    function, `{id: 'fn:<uuid>', input, output, args: [{id: argName, type: argType}], label: name,
    description}`. `label`/`description` are ADDITIVE, function-only wire fields (a built-in op has
    neither; the frontend localizes a built-in's label via i18n, but reads a function's own name/
    description VERBATIM — there is nothing to localize about a user's own words). Because the FE's
    add-operation menu already filters the catalog by the running pipeline's current type
    (`operationsForType`), a function automatically appears everywhere its OWN declared input type makes
    it eligible, with no separate wiring — the exact same mechanism that already surfaces every built-in
    op this way.

11. **Current scalar-first arg-call-control limitation, stated explicitly (accepted, not an oversight).**
    `CustomFunctionOperation::argControl()` — the call-site CONTROL a function's own argument exposes when
    it is used as an op elsewhere — maps `number`/`boolean`/`date` to their own literal controls and
    EVERYTHING ELSE (`enum`, `multi`, `file`, `object`, `time`) to a plain stringifiable TEXT control. A
    function whose argument is declared `enum`, for instance, can be called today, but the call site offers
    a free-text box for it rather than a picker constrained to that enum's real option set. This mirrors
    the fact that a function is, so far, only referenceable from ANOTHER function's body (Decision 4's
    validation) or from a workflow pipeline argument slot (Decision 10) — richer, per-type call-site
    controls (an enum picker, a file picker) are real future work, not blocked on anything structural, just
    not built in this slice.

## Consequences

- **Positive.** A workspace can now name and reuse a pipeline composition exactly like a built-in
  operation, closing a real authoring gap (the same 4-step transform typed inline, field after field).
  Nesting is genuinely safe: a cycle is REJECTED at write time for the overwhelming majority of cases (a
  human authoring through the editor can never save one), and the two runtime backstops (depth cap +
  visited-set) mean even a cycle that somehow reaches the database — a hand-crafted API call, a data
  import, a race between two concurrent saves — cannot loop the engine; it fails one pipeline step closed,
  same as any other bad configuration this engine already tolerates.
- **Positive.** Execution-by-expansion added no second interpreter and no new fail mode: a custom function
  op is, from the executor's own perspective, still just "resolve an op, check its input type, run it,
  check the output" — the SAME `execute()` loop, one frame deeper. Every existing invariant (pure,
  contextless besides the threaded scope; never throws; fail-closed on any malformed input) is inherited
  for free rather than re-implemented.
- **Positive.** The frame-stack generalization (`ScopeRef`'s caller-supplied roots + `OperationExecutor::
  scopeRoots()`'s union) cost the array-transform feature (ADR-0026) nothing structurally — its own
  `element`/`index` scope is exactly `ScopeRef::DEFAULT_ROOTS`, the parameter's own default, so every
  pre-existing element-pipeline call site (one outside any function body) is byte-identical.
- **Trade-off (accepted): a function call does not let a callee see its caller's frame (Decision 8).** A
  deliberate reading of "pure over `{input, args}}`" — considered and rejected the alternative (a genuine
  N-deep stack exposing every ancestor's bindings) as both unnecessary (nothing in this feature's actual
  use cases needs it) and a worse contract (a function's behavior would then depend on its call site, not
  just its own declared signature).
- **Trade-off (accepted): the scalar-first call-control limitation (Decision 11).** Named explicitly so it
  reads as scoped-out, not forgotten — a function typed against a richer base (enum/multi/file/object) is
  fully valid and executes correctly; only the AUTHORING UI for supplying that argument at a call site is
  narrower than the type system itself supports.
- **Trade-off (accepted): no default argument values in this version (Decision 2).** Every declared arg is
  required at every call site — simpler to validate and simpler to reason about for a first version; a
  default-value feature is real future work if authoring demand shows it is needed, following the SAME
  kind of design work ADR-0022's per-reference `default` needed for an ordinary variable reference.
- **Rejected: allowing an argument-variable inside a function's body to reference `trigger`/`steps` (a
  live workflow run's context) directly.** Would break "a function body is pure over `{input, args}`" —
  the same function could then behave differently depending on which workflow, or even which RUN of the
  same workflow, happened to call it, defeating the entire point of a function having a fixed, reusable
  signature. A function's only inputs are its declared `input` and its declared `args`; anything a caller
  wants a function to see must be passed as one of those.
- **Rejected: a bespoke interpreter/AST for the function body, separate from the pipeline engine.** The
  body IS a pipeline (`Array<{op, args}>`), the exact same shape a workflow's own value-or-variable field
  or condition pipeline already is — reusing `execute()` by expansion (Decision 6) means a custom function
  can do everything the pipeline engine already does (including reference OTHER functions, use array
  transforms, take argument-variables) with no separate feature-parity tracking between "what a function
  body can do" and "what a workflow pipeline can do."
- **Rejected: catching a cycle ONLY at write time, trusting that no row can ever bypass it.** Explicitly
  rejected as insufficiently fail-closed for this codebase's own stated doctrine — a database row can be
  corrupted, hand-written, or reached via a race between two concurrent saves each individually valid at
  the instant they were checked; the runtime depth cap + visited-set (Decision 5) exist specifically
  because "the write path already prevents it" is not, by itself, a safety proof the way "the runtime
  itself cannot loop, structurally" is.

See `docs/backend/workflows-api.md` (the new "Functions" endpoints under "## Endpoints", the
`CustomFunctionResource` shape, the `fn:<uuid>` catalog entries under "The typed variable system", and
"Custom functions — nesting, the frame stack, and the fail-closed safety design" for the full wire
contract and the fail-closed/cycle/frame-stack rules this ADR records) and
`docs/decisions/ADR-0027-variables-module-extraction.md` for the module boundary this feature lives
behind.
