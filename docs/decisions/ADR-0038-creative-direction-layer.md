# ADR-0038 — content-quality rework: narrative contract upgrades + the creative direction layer

**Date:** 2026-07-29 (created)
**Status:** Accepted
**Module:** `App\Modules\Generator` (`Agents\ShotListAgent`, `Agents\CreativeDirectionAgent`,
`Services\CreativeDirectionService`, `Support\CreativeDirection`, `Support\CreativeDirectionContext`,
`Services\GeneratorAiTextService`, `Services\ShotListRenderer`, `Services\GenerationSessionExecutor`,
`Services\GenerationSessionRunManager`, `Services\TemplateContentValidator`), `App\Modules\Variables`
(`Agents\AiTextAgent` — the new optional `$lengthGuidance` parameter)
**Relates to:** ADR-0035 (the `shot_list`/`storyboard` structured contract and the `parts.*` cross-part root
this ADR builds directly on top of — `ShotListAgent`, `ShotListRenderer`, and `storyboard_max_shots` are all
ADR-0035 machinery this ADR extends, not replaces), ADR-0036 (bot delegation — the voice-wins-on-tone rule
below composes with the SAME `AiVoiceContext` ambient seam this ADR's `CreativeDirectionContext` mirrors),
ADR-0037 (the $ cost meter — the one derivation call is gated/metered/actor-attributed through the exact
same seam every other spend already uses)

---

## Context

R2 sub-stage 2's session engine (ADR-0034) and the video_script rework (ADR-0035) made every generation in a
session real, budgeted, and structurally sound — but each generation was still an ISLAND. A `post_with_image`
made its body and its image from the SAME authored recipe, but as two INDEPENDENT `@[ai-text]`/`ai_generate`
calls that had never seen each other's output; a `video_script`'s five storyboard frames were five
independent text→image calls with no shared notion of what the piece even looked like. Nothing carried a
frame of reference FORWARD.

On top of that, `ShotListAgent`'s system instruction carried a hard-coded contract — "3 to 5 SHOTS … Keep it
tight" — that OVERRODE whatever the brief actually asked for. This was empirically diagnosed, not
theorized: a brief stating "a 1–2 minute video" reliably produced a ~15-second, 3-to-5-shot script, because
the fixed system rule always won over the user's stated duration. A system contract that silently overrides
the brief is worse than an unconstrained one — the model has no way to honor the user's actual ask.

Two problems, one root cause (no shared frame + a contract fighting the brief), needed fixing together:

1. **Should the AGENT'S OWN contract adapt to what the brief asks for**, or stay fixed and let a wrapping
   layer compensate after the fact?
2. **How does a session give its later generations context about its earlier ones** — a shared "what is this
   piece" frame — without re-running (and re-billing) any of them, and without silently increasing the
   number of AI calls a budget-conscious workspace pays for?
3. **Where does that shared frame come from** — the raw authored recipe, or the fully resolved brief a real
   run would produce?
4. **How is model-derived, user-influenced content allowed to reach another agent's prompt** without
   crossing the line from "framing information" to "an instruction an attacker's slot value could forge"?

## Decisions

**D1 — the narrative CONTRACT is fixed first, and costs nothing extra (B1).** Two changes to
`ShotListAgent::instructions()`, applied independent of anything else in this ADR (they hold even with the
direction layer's kill switch off):

- **Adaptive shot count, not a fixed range.** The instruction now asks for `{countClause}` — "between 3 and
  {the run's EFFECTIVE cap}" (or "no more than {cap}" when the cap itself sits below 3, so a 1–2-shot recipe
  never sees "between 3 and 1") — with 5–30 seconds per shot stated as a GUIDE, and an explicit PRECEDENCE
  clause: a stated duration (from the brief, or from the derived direction below) AND the shot bound are
  BINDING; the 5–30s span is only a guide, so the model is told to LENGTHEN individual beats past 30s rather
  than shorten the piece to fit the guide.
- **A STORY block that degrades COHERENTLY, not silently, at a low cap.** The instruction asks for a
  through-line, escalation, a payoff that resolves something set up earlier, continuous voiceover, and
  subject-consistent wording. Most of those rules are stated ACROSS shots ("a payoff set up in an EARLIER
  shot") — literally impossible to satisfy at a cap of 1 and barely expressible at 2. Rather than leave an
  unsatisfiable MUST in the prompt (the model then silently breaks one, arbitrarily — worse than no rule),
  `storyClause(int $maxShots)` emits a DIFFERENT block per cap: the full escalation/payoff/continuity/
  consistency rules at `>= 3`, the same rules restated over exactly two shots at `2`, and a single
  "setup-and-payoff-in-one-shot" rule at `1`. The cap-independent spine (through-line, cta-from-payoff) stays
  fixed around whichever variant is chosen.

This is a PREREQUISITE for D2 below, not an alternative to it: a shared creative frame is only useful if the
agent receiving it is actually capable of honoring a stated duration/beat count instead of silently
overriding it.

**D2 — a FULL run derives ONE shared creative direction, once, and every later generation in that run is
made to it.** `CreativeDirectionService::derive()` makes exactly one extra metered `ai_text` call
(`CreativeDirectionAgent`) that returns a small structured JSON object — message/goal/audience/tone/
through-line/arc-beats/subject/setting/visual-style/target-duration/continuity-notes — defensively parsed
and normalized (`CreativeDirection::fromArray()`), then persisted on the session (`creative_direction`, a
new nullable json column, additive migrations) so every later generation in the SAME run — and every later
ISOLATED per-part op — reads the same frame. This is the piece that closes the "island generations" problem:
a `post_with_image`'s image now draws the SAME subject its body describes; five storyboard frames now share
one visual style, one recurring subject, one continuity note.

**D3 — derived from the AUTHORED recipe, never the resolved brief.** `CreativeDirectionService::input()`
feeds the model the SAME no-op-previewed rendering the template editor's live preview already produces
(`@[ai-text]` shows as `[AI: <resolved prompt>]`, no model call) plus a size-capped digest of the run's
scalar slot values — not the text a real `@[ai-text]` call would actually produce. Two reasons, both
load-bearing:

- **Double-billing.** Deriving from the RESOLVED brief would require resolving it first — re-running every
  nested `@[ai-text]` block the direction call itself needs as input, billing each one twice (once to build
  the direction's input, once for the real run).
- **Divergent paraphrase.** A resolved brief is already one model's rewrite of the author's instruction. A
  direction derived from THAT would extract the model's paraphrase of the constraint, not the constraint
  itself — the "1–2 minutes" the author actually typed would already have been softened once before the
  direction agent ever saw it. Deriving from the AUTHORED recipe means the direction agent sees the author's
  own words, verbatim.

**No recipe → no call at all.** A slot digest alone (`{topic: "Espresso"}` with no template content) never
triggers a derivation — inventing a whole creative frame from nothing and injecting it as BINDING would be a
worse failure mode than having no direction; `CreativeDirectionService::input()` returns `''` and the caller
skips the call entirely, at zero cost.

**D4 — the derived direction rides the USER message as fenced DATA, NEVER a system instruction.** The
direction is untrusted-laundered content: a model wrote it, but from user-supplied slot values, so it
carries exactly the same trust level as any other resolved prompt value — never the elevated trust of an
agent's own system instruction. Three consumers get three PROJECTIONS of the same object
(`CreativeDirection::forText()`/`forShotList()`/`forImage()`), each exposing only the fields that consumer
needs, and each is injected at the SAME trust boundary the consumer's existing prompt-is-DATA hardening
already polices:

- `GeneratorAiTextService::generate()` prefixes the resolved `@[ai-text]` prompt with a fenced
  `CREATIVE DIRECTION (data)` block.
- `ShotListRenderer` does the same for the brief/revision prompt; `ShotListAgent` receives only a TRUSTED,
  content-free `bool $directionAware` flag in its SYSTEM instruction (never the block's own content) — a
  framing clause telling the model how to treat data it will see in the user message, without itself
  carrying any of that untrusted content.
- Every `ai_generate` image base (`GenerationSessionExecutor::directedImagePlan()` for a plain image/scene,
  `produceStoryboardShotImage()` for a storyboard shot) composes the direction's compact, UNFENCED visual
  anchor AHEAD of the authored prompt/style/visual — an image model has no system message and no fence to
  respect, so the anchor is prose, not a labeled data block, but it is still resolved through an IDENTITY
  function once composed (never re-interpreted as a directive), exactly like the shot-list's own `visual`
  output already had to be.

**D5 — a Generator-owned ambient context, mirroring the existing `MeterContext`/`AiVoiceContext` idiom.**
`Support\CreativeDirectionContext` is set and cleared in the SAME `finally` block as the meter/actor/voice
tags, at all three `GenerationSessionExecutor` entry points (`execute()`, `renderPartFromSnapshot()`,
`renderRefinedPart()`). It exists for exactly ONE consumer with no parameter-passing seam
(`GeneratorAiTextService`, invoked many frames below the executor through the shared `VariableResolver`);
every other consumer (`ShotListRenderer`, the plain-image and storyboard image composers) IS
executor-reachable and receives the direction as an EXPLICIT parameter instead — the ambient holder is a
narrow escape hatch, not the general pattern. This is the SAME shape `AiVoiceContext` (ADR-0036) already
established for the bot-delegation voice; a THIRD ambient context reusing the identical set/clear/leak-proof
discipline was judged simpler than generalizing the two into one shared abstraction for a single additional
consumer.

**D6 — snapshot + claim lifecycle: derive once, null on a full claim, preserve on a part-op claim.**
`GenerationSessionRunManager::claimAndDispatch()` NULLS `creative_direction` on a FULL-mode claim (each full
run derives fresh — a stale direction from a prior recipe/slot-values combination must never leak into a new
run) and PRESERVES it on a part-op claim (regenerate/refine reuse the SAME frame the full run established, so
a refine stays coherent with everything around it). `GenerationSessionExecutor::directionForFullRun()` reuses
an already-stored direction rather than re-deriving on a redelivered job (idempotent, no double-billing on a
queue retry).

**D7 — the direction call is metered/gated/attributed like any other spend, but lives OUTSIDE the per-run
ai-text call ceiling.** It is gated before spend by the same workspace $ cap (ADR-0037), session-tagged, and
actor-attributed exactly like every other AI call the session drives. It is deliberately NOT counted against
`generator.ai_text_max_calls_per_session`: charging it to that fixed per-run budget would let ONE guaranteed
call starve a real authored part (a recipe with 4 `@[ai-text]` blocks would then only ever resolve 3). It has
its own, tighter timeout (`ai.direction_timeout`, 30s vs. `ai.text_timeout`'s 60s) specifically so it cannot
eat into the run job's fixed 300s SIGALRM window.

**D8 — the shot cap becomes adaptive, and the image-generate budget is kept in LOCK-STEP with it.** An author
may now tighten the platform ceiling per recipe (`content.storyboard.max_shots`, validated at write —
`1..generator.storyboard_max_shots`), and the platform ceiling itself was raised from 5 to 8 (the shot count
is no longer meant to silently cap every longer-form brief). `GenerationSessionExecutor::effectiveShotCap()`
resolves ONE value — `min(authored, ceiling)` — threaded explicitly into the shot-list agent's instructed
bound, the parse clamp, AND the storyboard's per-shot image iteration, so the three can never disagree about
how many beats exist. `generator.image_generate_max_calls_per_session` was raised from 5 to 8 to match: every
listed shot must be RENDERABLE, or the last frames of a long storyboard silently come back frameless — which
reads as a bug, not as a budget, from the chat surface.

**D9 — voice wins on tone.** A delegated session (ADR-0036) already carries the bot's voice in the relevant
agent's SYSTEM instruction. The direction's `tone` field is dropped from `forText()`/`forShotList()`'s
projection (their `$withTone` parameter, `false` while `AiVoiceContext::directive()` is set) rather than
riding alongside the voice and competing over the same wording decision — voice is HOW the content sounds,
direction is WHAT/WHY it exists, and the two must never fight over tone specifically.

## Alternatives considered

- **Contract-only (D1 alone, no shared direction).** Fixes the "brief duration silently overridden" defect,
  but does nothing for the "island generations" problem — a `post_with_image`'s body and image, or five
  storyboard frames, would still be independently generated with no shared frame of reference. Rejected as
  insufficient on its own; kept as a prerequisite for D2, not a substitute.
- **A shot-list-carried anchor** (thread a visual-consistency string through the EXISTING `shot_list` →
  `storyboard` intra-composition instead of a separate derivation). Would give the storyboard a shared
  anchor for free, but does nothing for a `post_with_image`'s body/image pairing (there is no shot list in
  that content type) and nothing for text-to-text consistency across an ARBITRARY content type's parts.
  Rejected because the direction layer needed to be a content-type-agnostic primitive, not a video_script
  special case.
- **Image-to-image chaining** (generate the first storyboard frame, then feed IT as the image base for the
  next shot's edit, instead of N independent text→image calls anchored by a shared prompt). Would give
  genuinely higher visual consistency (the SAME pixels evolve, not just a shared style description) at a
  CHEAPER per-call cost (`ai_edit` vs. `ai_generate`), but is fundamentally SERIAL (frame N+1 cannot start
  until frame N's bytes exist — no per-shot fail-soft parallelism), tends to OVER-PRESERVE composition (a
  masked/whole-image edit drifts toward "the same picture with small changes" rather than "a new shot of the
  same world"), and would need `generator.image_edit_max_calls_per_session` raised to accommodate a full
  storyboard's worth of edits instead of one-off refines. Rejected for v1 as the more invasive change with
  its own budget/parallelism trade-offs; named explicitly below as the v2 path for the one thing prompt
  anchoring cannot give.

## Consequences

- **Positive.** A session's generations now read as ONE piece: a post's body and image describe the same
  subject, a storyboard's frames share one visual world, and a brief's stated duration/beat count is honored
  instead of silently overridden by a fixed system contract.
- **Positive.** The layer is architecturally free when unused: no direction (kill switch off, no recipe to
  derive from, a failed derivation) makes every injection point a byte-identical no-op — nothing about this
  ADR can regress a session that does not benefit from it.
- **Positive.** The one derivation call composes cleanly with every existing cross-cutting concern
  (ADR-0036's voice, ADR-0037's $ gate/actor attribution) by reusing their exact idioms rather than
  inventing parallel machinery.
- **Honest limitation (accepted, not hidden).** Prompt anchoring (D4) gives consistent world/style/palette/
  camera across independently generated frames, but it does **not** give the same character FACE (or exact
  garment/object identity) across frames — each `ai_generate` call is still an independent text→image
  generation that merely STARTS from a shared written description, not from shared pixels. A "recurring
  subject" described in words is enough to keep a storyboard from looking like five different productions,
  but not enough to guarantee the protagonist looks like the same person in every frame. **Image-to-image
  chaining is the named v2 path** for closing that specific gap (see "Alternatives considered" above) — it
  was not built now because it trades away per-shot parallelism/fail-soft independence and needs its own
  budget model (`image_edit_max_calls_per_session` raised to cover a full storyboard's worth of chained
  edits), which is a large enough change to warrant its own planning pass rather than folding it into this
  rework.
- **Trade-off (accepted).** The direction is derived from the AUTHORED recipe (D3), so it can only ever
  reflect what the recipe's static text + slot values actually say — a recipe that is itself vague produces
  a vague (or entirely absent) direction. This is by design (see D3's "no recipe → no call" rule), not a
  defect: inventing a confident-sounding direction from a near-empty recipe would be a worse failure mode
  than deriving none.
- **Trade-off (accepted).** The one extra call adds real latency to a full run (bounded by `ai.
  direction_timeout`, 30s) and real (if small) $ cost on top of every other spend the run already makes,
  even for a recipe that would have rendered coherently without it. The kill switch
  (`generator.direction.enabled`) exists specifically so an operator can opt out workspace-wide if the
  trade-off is not worth it for their content.

See `docs/backend/generator-sessions-api.md` → "Narrative contract upgrades" and "Creative direction layer"
for the shipped wire/config contract, and `docs/backend/generator-api.md` → "`shot_list` & `storyboard`" for
the `content.storyboard.max_shots` authoring knob this ADR introduces.
