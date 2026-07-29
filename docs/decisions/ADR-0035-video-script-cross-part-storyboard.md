# ADR-0035 — `video_script` rework: cross-part context + structured shot_list/storyboard

**Date:** 2026-07-28 (created)
**Status:** Accepted — **AMENDED BY [ADR-0038](ADR-0038-creative-direction-layer.md)**. The shot-count
numbers recorded below are SUPERSEDED and must not be read standalone: `storyboard_max_shots` is no longer a
fixed 5 with a 2→5 shape (the count is ADAPTIVE, the platform ceiling is 8, a template may TIGHTEN it via
`content.storyboard.max_shots`, and the run's effective cap is `min(authored, ceiling)`), and BOTH image
budgets (`image_generate_max_calls_per_session`, `image_edit_max_calls_per_session`) are now kept in
lock-step with that ceiling at 8. The storyboard base prompt also gained a continuity clause + the ADR-0038
direction anchor. Everything else here (the `parts.*` root, the structured shot_list/storyboard contract,
per-shot addressing, fail-soft posture) still stands.
**Module:** `App\Modules\Generator` (`ContentTypeRegistry`, `GenerationSessionExecutor`,
`GenerationSessionRefiner`, `TemplateContentValidator`/`TemplateSlotValidator`, `TemplateVariableCatalog`,
`ShotListRenderer`, `ShotListAgent`), `App\Modules\Variables` (`VariableResolver` — the new `parts` root +
`collectReferenceIds` scanner, a general engine primitive, not video-specific)
**Relates to:** ADR-0034 (the sessions engine this rework extends — snapshot-authoritative execution,
fail-soft-per-part, the claim/job/refine-loop machinery reused unchanged), ADR-0032 (the Template
content-recipe model — `ContentTypeRegistry`/`PartKind`/D8 kind-driven behavior this rework's two new
kinds slot into), ADR-0030 (the shared `VariableResolver` the new `parts` root is added to), ADR-0033 (the
AI cost meter / per-run call-count budgets this rework's `storyboard_max_shots` bound interacts with)

---

## Context

R2 sub-stage 1/2 shipped `video_script` as `[script, scene_plan]`: a free-form scenario body plus an
optional ordered list of scenes, each a narration + an optional image. Once the owner reviewed it against
a real use case — producing an actual short-form TikTok/Reels video — the output was rejected as
**useless**, not merely unpolished:

1. **No coherent shot structure.** A `script` is one blob of prose and `scene_plan`'s scenes are
   independent narrations; nothing ties them into a single hook → shots → CTA arc with per-shot timing
   (on-screen visual, spoken voiceover, seconds) — the actual shape a short video needs.
2. **No way for one part to build on another's REAL output.** Every part rendered from the SAME snapshot +
   slot values in isolation; a later part could not reference what an earlier part's AI call had actually
   produced (only the raw slots/globals), so "write a caption that matches the script the AI just wrote"
   was not expressible — a gap bigger than `video_script` alone, since ANY multi-part recipe could benefit
   from it.

This rework covers two coupled changes, built as Phase A (a general engine primitive) and Phase B
(`video_script`'s new shape, which is the first — but not the only possible — consumer of Phase A):

- **Phase A — cross-part context.** A content-type PART's authored body may reference an EARLIER part's
  GENERATED output via a new `parts.<key>` variable source, resolved exactly like `globals.<key>`.
- **Phase B — `shot_list` + `storyboard`.** `video_script` recomposes to `[shot_list, storyboard]`: one
  structured AI call produces a coherent hook/shots/cta shot list, and the executor iterates its shots to
  generate one image per shot.

## Decisions

**D1 — `parts` is a new WHITELISTED root on the shared `VariableResolver`, general and TEXT-ONLY, not a
`video_script`-specific mechanism.** `VariableResolver::ROOTS` gained `parts` alongside
`trigger`/`steps`/`globals`/`slots` (ADR-0030's superset design already anticipated exactly this kind of
addition — an unpopulated root is inert, so Workflows stays byte-identical). The Generator executor
populates it PER RUN as a plain `{<partKey>: <rendered text>}` map, so `parts.<key>` resolves through the
IDENTICAL whitelisted-dotted-lookup + NUL-mask path `globals.<key>` already uses — zero new resolver
machinery, and a value that itself contains reference/directive bytes is never re-interpreted (injection-
safe by construction, the same guarantee `globals` already had). Only a part whose `ok` result is a STRING
(`text_body`/`script`/`shot_list` — via `GenerationSessionExecutor::partContribution()`) contributes; an
`image_plan`/`scene_plan`/`storyboard` result has no natural flattened text and contributes nothing.

**D2 — earlier-only, acyclic BY CONSTRUCTION, enforced independently at THREE layers (defense in depth).**
A part may only ever reference a part declared BEFORE it in the content type's ordered parts
(`ContentTypeRegistry::partKeysBefore()`):

1. **The editor catalog** offers only `parts.<earlierKey>` variables for the part currently being authored
   (`TemplateVariableCatalog::partVariables()`, fed by `POST /generator/catalog`'s new optional
   `content_type`/`part_key` scoping).
2. **The write validator** (`TemplateSlotValidator::validateCrossPartReferences()`) rejects a
   forward/self/unknown `parts.*` reference as a `422`.
3. **The executor**, independently of (2), re-derives the earlier-only scope from the SNAPSHOT at both
   render times: a whole run accumulates the `parts` map top-to-bottom starting EMPTY
   (`GenerationSessionExecutor::renderParts()`), and an isolated per-part op (regenerate/refine) SEEDS it
   from the session's ALREADY-STORED results, scoped to strictly-earlier keys
   (`GenerationSessionExecutor::seedParts()`/`earlierPartKeys()`) — so even a hypothetical bug that let a
   forward reference past (2) would still resolve EMPTY at (3), never against a later part's output
   (`CrossPartContextTest::test_a_forward_part_reference_seeds_empty_in_both_a_full_run_and_a_refine`).

**D3 — the write gate and the runtime STALENESS scan share ONE scanner, so neither can drift from what the
resolver actually resolves.** A flat `@[variable]` regex is BLIND to a reference nested inside an
`@[ai-text]` prompt or an if-block condition/body, and to the transitional flat `{{…}}` token form. Both
the write-time gate (`TemplateSlotValidator::validateCrossPartReferences()`) and the staleness dependency
scan (`GenerationSessionRefiner::crossPartDependents()` → `referencedPartKeys()`) instead walk EVERY string
leaf of the authored content through the SAME `VariableResolver::collectReferenceIds()` — a scanner that
mirrors the resolver's real parsing (top-level directives, `@[ai-text]` payloads recursively, if-block
markers' condition + body, AND the flat-token pass), so a reference is found WHEREVER the resolver would
actually resolve one. This was hardened across TWO review rounds after the ORIGINAL implementation used a
flat directive regex in both places: C1 fixed the staleness scan (which could previously UNDER-mark a
downstream dependent whose `parts.<key>` ref was nested, leaving it silently stale-but-unflagged); C2 fixed
the write gate the same way, plus added the D2(3) seed-time defense-in-depth. Pinned by
`CrossPartContextTest::test_a_forward_part_reference_nested_in_an_ai_text_prompt_is_rejected`,
`test_a_self_part_reference_in_an_if_block_condition_is_rejected`,
`test_an_earlier_part_reference_nested_in_an_ai_text_prompt_is_accepted`, and the four
`test_regenerating_upstream_marks_a_downstream_that_references_it_via_*_stale` cases (directive / ai-text /
if-block / flat token).

**D4 — refining/regenerating an UPSTREAM part marks its downstream dependents `stale: true`; this is a
PASSIVE FE hint, NEVER an auto-cascaded re-run.** `GenerationSessionRefiner::markDownstreamStale()` sets
the flag on every already-produced dependent's result (a never-generated downstream has nothing to stale);
the flag rides the JSON verbatim (`GenerationSessionResource` emits `results` as-is) for the FE to render a
"may be out of date" badge. Auto-cascading was explicitly rejected (see "Alternatives" below) — a stale flag
costs nothing, a cascaded re-run silently re-spends the user's AI budget. A full `generate` clears every
part's results (and so every stale flag); regenerating the downstream part itself replaces its result with
a fresh, non-stale one.

**D5 — the PREVIEW endpoint (`POST /generator/preview`) does NOT populate the `parts` context root; a
`parts.<key>` reference in a preview always resolves EMPTY, unlike a real session run.**
`TemplateRenderService::render()` builds ONE execution context shared by every part (no accumulation loop
across parts, unlike the executor) — a deliberate scope-limit of this rework, not an oversight: previewing
cross-part coherence would require either running the SAME accumulation the executor does (duplicating run
semantics into the advisory preview path) or faking part outputs with no real content to show. The preview
stays what it always was — a per-part, in-isolation render — and `parts.*` behaves exactly like any other
unpopulated whitelisted root (inert, per `VariableResolver::ROOTS`'s own contract). This is a real,
user-visible asymmetry worth stating plainly rather than letting a reader assume the preview mirrors a run.

**D6 — `shot_list` is ONE metered structured AI call, via PROMPT-AND-PARSE, not laravel/ai's native
structured output.** `laravel/ai` v0.4.3 (the version this codebase runs) DOES support native JSON-schema
structured output (`HasStructuredOutput` + `illuminate/json-schema` + `StructuredTextResponse`) — a spike
confirmed this. `ShotListAgent` deliberately does NOT use it: the strict JSON contract (`{hook, shots:[
{visual, voiceover, seconds}], cta}`) is baked into the agent's system instruction instead, and
`ShotListRenderer` defensively parses the raw text reply (fence-stripped, balance-scanned for the first
top-level JSON object; a bare top-level JSON array is also accepted as the shots list; a non-JSON, non-blank
reply is kept as raw `text` with `parse_ok:false` and `shots:[]`; a BLANK reply returns `null`, failing the
part soft). This rides the EXISTING metered/budgeted/fail-closed ai-text seam
(`AiTextGenerationService::generateWith`) with ZERO new metering wiring — it counts as one ordinary
`ai_text` call against the session's existing per-run budget — and a defensive parse is needed EITHER way,
since a provider can violate a declared schema at the edges regardless of the API used. See "Alternatives"
below for why native structured output was not ruled out permanently, only deferred.

**D7 — `storyboard` reads its sibling `shot_list`'s STRUCTURED shots by direct INTRA-COMPOSITION, a
separate mechanism from the generic `parts.*` text root, kind-specific by necessity.** A storyboard is not
AUTHORED per-shot; it is EXECUTOR-ITERATED: `GenerationSessionExecutor::executeStoryboardPart()` finds the
first `shot_list`-kind part declared before it (`firstShotListKeyBefore()`) and reads its STRUCTURED
`shots[]` array directly from the in-progress whole-run results (or, for an isolated storyboard op, from the
session's stored result) — never through a `parts.<key>` TEXT lookup, since the generic root is deliberately
text-only (D1) and a shot's `{visual, voiceover, seconds}` triple is not text. Because this dependency is
INVISIBLE to the Phase-A `collectReferenceIds` scan, the staleness check special-cases it structurally
(`GenerationSessionRefiner::crossPartDependents()`'s case (b): a storyboard is a dependent of its sibling
`shot_list` regardless of any `parts.*` reference — pinned by
`StoryboardTest::test_regenerating_the_shot_list_marks_the_storyboard_stale_without_regenerating_it`). For
EACH shot, the executor produces ONE `ai_generate` image: prompt = the authored `style` (resolved through
the normal session resolver) + the shot's `visual` — but the VISUAL itself is resolved with an IDENTITY
function, never re-run through the directive resolver, because it is the shot-list AI's OWN output and must
never be re-interpreted as a directive (the same injection-safety posture `globals`/`parts` values already
have, extended to a nested AI-to-AI handoff). Fan-out is CLAMPED to `storyboard_max_shots` (below) on BOTH
sides — the parsed shot list (`ShotListRenderer::normalizeShots()`) and the storyboard iteration
(`GenerationSessionExecutor::resolveShotListShots()`) — so a runaway model reply can never inflate the image
cost past the configured bound.

**D8 — per-shot addressing (`storyboard.<i>`) REUSES the existing per-part op contract verbatim; no new
endpoints.** `regenerate`/`refine`/`undo`/the serve endpoint/`save-to-disk` all already dispatch by parsing
a `<baseKey>.<index>` dotted sub-key (the SAME mechanism `scene_plan.<i>` established in R2 sub-stage 2c);
this rework only taught the dispatch to recognize a STORYBOARD-kind base part too
(`GenerationSessionExecutor::storyboardShotRef()`, `GenerationSessionRefiner::storyboardShotOp()`). A
`storyboard.<i>` regenerate re-images just that shot; a refine is an AI EDIT of that shot's current bytes
(reusing the same `editImageAt()` seam an `image_plan` refine uses); undo/history/blob-GC are the identical
per-key bookkeeping every other image part already had. The bare `storyboard` part itself is NOT free-text
refinable (a multi-shot composite has no single current output to revise, `422` — the same rule
`scene_plan` already had); refining `shot_list` marks the sibling `storyboard` `stale` (D7).

**D9 — LEGACY back-compat is SNAPSHOT-AUTHORITATIVE, unchanged in kind from ADR-0034's own D1.**
`script`/`scene_plan` stay in the closed `PartKind` vocabulary and are still fully render-/refine-/
undo-capable, but `ContentTypeRegistry::all()` no longer lists them under `video_script` — a NEW template
can never author them again. `ContentTypeRegistry::legacyParts()` + `partsForSnapshot()` keep an EXISTING
session's stored `recipe_snapshot` (which still carries `script`/`scene_plan` keys) rendering EXACTLY as it
always did, by resolving each snapshot content key against the definition ∪ the legacy map rather than the
live registry — mirroring D1's "a session never re-reads the live template" invariant one level down (the
registry itself can recompose without corrupting an existing session). Pinned by
`StoryboardTest::test_a_legacy_script_scene_plan_snapshot_still_renders_both_parts`.

**D10 — `storyboard_max_shots` (default 5) is the REAL fan-out bound, and the existing budget/timeout
invariants are EXTENDED to accommodate it rather than relaxed.** `image_generate_max_calls_per_session`'s
default was raised 2→5 to match `storyboard_max_shots`, so a full storyboard's per-shot `ai_generate` calls
all fit inside the per-run budget (otherwise the LAST 3 of 5 shots would routinely fail on a fresh
`post_with_image`-tuned default). `RunGenerationSessionJob::$timeout` (300s) is DELIBERATELY left unchanged:
the timeout-invariant reasoning `config/generator.php` already documented for the text/image-edit budgets
(the `ai.*_timeout` values are per-call HUNG-PROVIDER ceilings, not expected runtimes — a healthy call
returns in a few seconds) is extended, not reopened, to the storyboard case — 5 generates at a healthy ~5s
each is ≪ 300s, and raising the job timeout to cover a PESSIMAL all-calls-hang scenario would widen the
`WithoutOverlapping` lock and the stale-reaper window ADR-0034's 2b/2d hardening deliberately pinned.

## Alternatives considered

- **Native structured output (laravel/ai v0.4.3 `HasStructuredOutput`) for `shot_list`.** Confirmed
  available, deliberately not used (D6): it would need its OWN metering wiring parallel to the existing
  `ai_text` seam (`AiTextGenerationService::generateWith`), and would NOT remove the need for a defensive
  parse — a provider can violate a declared JSON schema at the edges regardless of the API surface used to
  request it. Deferred, not rejected outright: a future iteration COULD adopt it once a metered
  structured-call seam exists, without changing `ShotListRenderer`'s public contract (`generate`/`revise`
  returning the same normalized shape either way).
- **Raising `RunGenerationSessionJob::$timeout` to cover a bigger/slower storyboard.** Rejected (D10): would
  widen the `WithoutOverlapping` lock window and the stale-reaper's required cutoff (ADR-0034 D8), a real
  cost for a case the fan-out BOUND already prevents from ever occurring in a healthy run.
- **A structured (non-text-only) `parts.*` root**, so a later part could read an earlier `image_plan`'s
  metadata or a `shot_list`'s shots directly through the generic cross-part mechanism. Rejected for v1
  (D1/D7): the ONLY concrete need (storyboard reading shot_list's shots) is handled as a SEPARATE,
  kind-specific intra-composition read with no generic-root plumbing required; inventing a generic
  structured-reference grammar with no second consumer yet would be speculative scope, and would need its
  own type-flow/injection-safety design the text-only root gets for free by riding `globals`' existing path.
- **Auto-cascading a stale downstream part's regenerate.** Rejected (D4): silently re-spending a user's AI
  budget without an explicit action is the wrong default for a metered, cost-conscious surface; a passive
  hint the user acts on (or ignores) costs nothing and keeps every AI spend an explicit choice.
- **Deleting `script`/`scene_plan` from the codebase entirely** (a hard cutover) instead of the
  snapshot-authoritative legacy path (D9). Rejected: the branch is unmerged but NOT data-free by the time
  this rework landed (unlike ADR-0032's schema reshape, which had zero rows) — real generation sessions can
  already exist against the old shape once the branch/PR is live, and breaking their render would violate
  the "a session's output is a stable historical record" property ADR-0034 D1 already established.

## Consequences

- **Positive.** The cross-part `parts` root (D1) is a genuinely GENERAL primitive — any future content type
  or part kind gets "reference an earlier part's text" for free, not something bolted onto `video_script`
  specifically.
- **Positive.** The three-layer earlier-only enforcement (D2) plus the shared-scanner authority (D3) means
  the write gate, the editor's picker, and the runtime staleness scan can never quietly drift apart — a
  future reference-form the resolver learns to parse (another nested-marker case) automatically closes the
  gap in all three places at once, since they all delegate to the SAME `collectReferenceIds()`.
- **Positive.** Reusing the existing per-part op contract for `storyboard.<i>` (D8) meant zero new
  endpoints, zero new FE data-fetching code paths — the chat surface's regenerate/refine/undo/serve/
  save-to-disk affordances work on a storyboard shot exactly as they already did on a `scene_plan` scene.
- **Positive.** `shot_list`'s prompt-and-parse choice (D6) means the storyboard/shot-list rework shipped
  with ZERO new AI-provider integration surface — it is, from the meter's point of view, an ordinary
  `ai_text` call.
- **Trade-off (accepted).** The preview/run asymmetry (D5) means an author cannot preview what a
  cross-part-referencing part will actually say before running a real (billed) session — acceptable because
  the preview was already advisory/best-effort pre-rework (e.g. `@[ai-text]` was already inert-but-labeled
  there), and simulating a full accumulation run in the preview would mean either duplicating the executor's
  run semantics into an advisory path or fabricating fake earlier-part content to show.
- **Trade-off (accepted).** `storyboard`'s intra-composition dependency (D7) is invisible to the generic
  `parts.*` write/staleness machinery and had to be special-cased in the staleness scan — a small,
  documented, test-pinned exception rather than a fully generic solution, judged acceptable because a
  second such structural dependency does not exist yet in the closed `PartKind` set.
- **Rejected: structured cross-part references** — would have required a second, more complex
  reference/type-flow design for a single concrete need already served by (D7)'s narrower mechanism.
- **Rejected: auto-cascade on stale** — would silently spend a user's AI budget; the passive `stale` hint
  (D4) keeps every spend explicit.

See `docs/backend/generator-sessions-api.md` for the full session-side wire contract (`parts.*` context,
`shot_list`/`storyboard` result shapes, `storyboard.<i>` op addressing, the `stale` flag,
`storyboard_max_shots`), `docs/backend/generator-api.md` for the Template-side write/preview contract
(the new part kinds' authored content shapes, the cross-part write-validation), `docs/decisions/
ADR-0034-generation-sessions.md` for the sessions engine this rework extends unchanged, and
`docs/decisions/ADR-0032-generator-content-recipe.md` for the content-recipe model / `PartKind` vocabulary
these two new kinds slot into.
