# ADR-0036 — R2 sub-stage 3: bots in the generator — session delegation (author overlay + opaque voice + autonomous slot-fill)

**Date:** 2026-07-28 (created)
**Status:** Accepted
**Module:** `App\Modules\Bot` (`BotSessionDelegationController`, `BotSlotFillService`, `BotSlotFillAgent`,
`BotVoiceComposer`), `App\Modules\Generator`
(`SessionDelegationService`, `GenerationSession` — the new overlay columns), `App\Modules\Variables`
(`AiVoiceContext` — the new ambient seam, mirroring `MeterContext`)
**Relates to:** ADR-0034 (the sessions engine this delegation overlays — snapshot-authoritative execution,
the claim/job/refine machinery reused unchanged), ADR-0033 (the AI cost meter — the autonomous slot-fill's
one `ai_text` call is gate-before-spend and session-tagged exactly like any other spend), ADR-0007
(the Bot module's own persona/style/dictionary/phrases/prohibitions material this delegation composes and
snapshots, and the polymorphic-actor pattern `HasCreator` already established for a bot-assigned Task)

---

## Context

R2 sub-stage 2 (ADR-0034) shipped a `GenerationSession` that a human fills and runs. The roadmap's R2
sub-stage 3 ("Boty w generatorze", `docs/product/plan-dzialania.md`) asked for the obvious next step for a
platform whose bots already have a persona/voice (the Bot module, ADR-0007): let a human **delegate** an
editable session to a bot, so the bot (1) fills the session's inputs on its own and (2) becomes the content's
**author** — the generated text reads in the bot's voice, not a generic one.

`GenerationSession::creator` (via `HasCreator`) was ALREADY the polymorphic union `user | workflow_run |
bot`, and `generation_session` was already registered in the app-wide morph map (ADR-0034's own "Planned /
deferred" called this out explicitly) — so the naive shape was "make the bot the `creator`." That naive shape
was rejected before any code was written (see "Alternatives" below); this ADR records what was built instead.

Three coupled problems had to be solved together:

1. **Who owns the session after delegation?** A human still needs to review, edit, refine, undo, delete —
   full control never moves to the bot. But the content should still *read* as the bot's.
2. **How does "the bot's voice" reach content generation** without duplicating the `AiPersona` seam or
   forking the shared `@[ai-text]` generator per module?
3. **How autonomous is "let the bot fill this template in"?** A bot has no hands to pick a Disk file or
   author a nested composite value — some slots can never be safely bot-filled.

## Decisions

**D-A — a separate author OVERLAY, not `creator` reassignment; the human keeps ownership.**
`GenerationSession` gained two columns — `bot_author_id` (nullable, indexed, no cross-module FK — the same
"provenance only" posture `template_id` already has) and `bot_delegation` (json) — instead of ever writing
the bot into `creator_id`. `creator` (the human) is UNCHANGED by a delegation: every ownership check
(`GenerationSessionPolicy`, `is_owner`, `can_edit`, `can_generate`, `can_be_deleted`) still resolves against
the human, so the human retains full refine/undo/archive/delete rights on a delegated session exactly as
before. The overlay is the SOLE new state; nothing about the sessions engine's ownership model (ADR-0034)
changed.

**D-B — the overlay is a snapshot, not a live reference to the bot, and is all-or-nothing.**
`bot_delegation = {author: {id, name, icon}, voice: <opaque string>, snapshot_at, slot_values_before}` is
written in ONE save (`SessionDelegationService::applyDelegation()`) alongside `bot_author_id`; undo
(`clearDelegation()`) nulls BOTH columns in one save. There is no state where one is set and the other is
not. The whole overlay is read SNAPSHOT-not-live — the executor and the resource read these columns, never
the live `bots` table — so editing or deleting the bot after delegation never changes what an already-
delegated session renders or shows (`BotSessionDelegationTest::
test_editing_or_deleting_the_bot_never_changes_a_delegated_sessions_voice`). This mirrors `recipe_snapshot`'s
own "never re-read the live source" invariant (ADR-0034 D1) one level up.

**D-C — the bot's voice is composed into ONE opaque directive string and threaded through a new
per-run ambient seam (`AiVoiceContext`), which REPLACES the resolved `AiPersona` line rather than adding a
second one.** `Bot\Services\BotVoiceComposer::compose()` folds the bot's persona/style/dictionary/phrases/
prohibitions into a single directive — the SAME material and rendering style
`BotTaskExecutionAgent::instructions()` already uses for a bot's task work, deliberately MIRRORED rather than
shared (the two consumers frame the material differently: one instruction block vs. one voice paragraph).
Generator/Variables treat this string as OPAQUE — never parsed, never re-derived — exactly the posture
`globals`/`parts` values already have (D1 of ADR-0035). `Variables\Support\AiVoiceContext` is the exact twin
of `MeterContext`: a shared instance a caller sets around an execution scope and clears in a `finally`; the
existing `AiTextGenerationService::generate()` reads it and, when present, **substitutes it for the resolved
`AiPersona` tone line** in `AiTextAgent`'s system instruction — a delegated run's persona and bot-voice are
never both present; the bot voice wins. A non-delegated run never sets the directive, so `directive() ===
null` and behavior is byte-identical to before delegation existed
(`tests/Unit/Variables/AiVoiceContextTest.php`).

**D-D — the voice reaches the `shot_list` structured call too, as an additive tone clause, WITHOUT relaxing
the strict-JSON contract.** `ShotListAgent` already had a closed output contract (ADR-0035 D6); this
delegation adds an optional constructor argument that, when non-null, appends a "match this voice" clause to
the instruction — the JSON shape/keys are unchanged, so the SAME defensive parse (`ShotListRenderer`)
handles a delegated and a non-delegated run identically. `GenerationSessionExecutor` sets
`AiVoiceContext::setDirective($session->botVoice())` around EVERY render scope (whole-run, per-part
regenerate, per-part refine) in the SAME `finally` block as the existing `MeterContext` session tag —
leak-proof by construction (`tests/Feature/ShotListVoiceTest.php`,
`BotSessionDelegationTest::test_a_delegated_run_renders_in_the_bot_voice_then_clears_it_so_a_later_spend_sees_none`,
`test_a_throw_mid_render_on_a_delegated_session_still_clears_the_voice`). The `storyboard` IMAGE prompt is
DELIBERATELY untouched — it stays the authored `style` only, because an image-generation prompt is not a
text-tone surface the same way a persona/voice line is; only text output (parts + refine + the shot_list's
`voiceover`/`hook`/`cta`) renders in the bot's voice.

**D-E — autonomous slot-fill is included in this slice, scoped to plain typed inputs only; FILE and deep
composite slots are NEVER bot-filled.** `Bot\Services\BotSlotFillService` turns the session's IN-SCOPE slot
schema into a prose prompt and makes ONE metered `ai_text` call (`AiTextGenerationService::generateWith`,
channel `ai_text`, gate-before-spend on the SAME monthly cap every other spend respects, session-tagged via
`MeterContext`) through `BotSlotFillAgent` — a prompt-and-parse agent mirroring `ShotListAgent`'s posture
exactly (strict-but-unenforced JSON contract in the instruction; the caller defensively parses, never trusts
clean JSON). `SessionDelegationService::introspectSlots()`/`isOfferable()` excludes a `file`-based slot and a
"deferred composite" (an `array<object>`, or an object nesting another object/file beyond one level) from
what is even OFFERED to the bot — a bot has no Disk access and must never be able to forge a file reference,
and a nested composite's shape is out of scope for this slice's schema-prompt approach. Every proposed value
is RE-VALIDATED server-side against the SAME `ConstantTypeValidator` descriptor authority a human write
uses (`SessionDelegationService::applyBotSlotValues()`) before persisting — an invalid/unknown/out-of-scope
value is dropped, never stored, and reported by name + reason
(`unknown_slot | out_of_scope | invalid`). A required file slot (or any required slot the bot could not
fill) surfaces in `unfilled_required` — a SOFT signal, not a hard generate-gate — and the session stays a
draft: the human completes it, exactly the same low-trust posture the generate/refine loop already has
toward AI output.

**D-F — this is the one new cross-module edge (`Bot → Generator + Variables`), one-way, and every seam it
crosses takes primitives/opaque strings, never a Bot class.** `SessionDelegationService` (Generator) and
`AiVoiceContext`/`AiTextGenerationService` (Variables) accept a `GenerationSession`, plain strings, and
arrays — never `App\Modules\Bot\Models\Bot`. The Bot module's `BotSessionDelegationController` composes the
bot-specific material (`BotVoiceComposer::compose($bot)`, the `{id, name, icon}` snapshot) and calls the
Generator/Variables seams with the result. Generator/Variables import NOTHING from Bot; Bot is the only
module that imports from both siblings for this feature. Pinned by
`tests/Feature/GeneratorModuleBoundaryTest::test_generator_module_imports_nothing_from_workflows_or_bot`
(Bot joins Workflows as forbidden in Generator) and the new
`tests/Feature/BotModuleBoundaryTest` (asserts BOTH halves: the Bot seam classes DO depend on Generator/
Variables, and `SessionDelegationService` names no Bot class).

**D-G — undo is a full, reversible restore, not merely an overlay-clear.** `applyDelegation()` snapshots the
session's `slot_values` AS THEY ARE AT DELEGATION TIME into `bot_delegation.slot_values_before` — captured
BEFORE the autonomous fill runs (the controller stamps the overlay FIRST, then calls `BotSlotFillService::
fill()`) — so it holds exactly the human's own pre-delegation inputs. `clearDelegation()` (the undo path)
restores `slot_values` from that snapshot before nulling the overlay, in one save. A `delegate → undo` round
trip therefore fully reverts the bot's autonomous fill AND anything it may have overwritten, with ZERO data
loss (`BotSessionDelegationTest::test_undo_restores_the_pre_delegation_slot_values_with_no_data_loss`).
Undo is allowed on `draft`/`ready`/`failed` — a delegated `failed` session must still be revertible — but
409s on `generating` (mirroring the generate/refine "one op at a time" conflict); this is why the resource
exposes `can_undo_delegation` (owner && `is_delegated` && not `generating`) as a SEPARATE flag from
`can_delegate` (owner && editable draft/ready) rather than reusing one gate for both affordances.

**D-H — delegation is opt-in for review, and auto-run is a SEPARATE opt-in on top, per the app's
"gate-przed-wydatkiem" default.** `POST …/delegate` always stamps the overlay + runs the (already metered,
gated) slot-fill; it does NOT start a generation run unless the caller explicitly sets
`auto_generate: true` in the body — the default leaves the session `ready`-to-review so a human can inspect
the bot's fill_report before spending on generation. `auto_generate` claims + dispatches through the SAME
`GenerationSessionRunManager` a manual "Generuj" click uses — no parallel run path.

## Alternatives considered

- **Make the bot the session's `creator`.** Rejected (D-A): `creator` gates delete/full ownership
  everywhere in the sessions engine; reassigning it would mean a bot "owns" a session a human cannot delete
  without a NEW ownership-transfer concept, and would blur "who may still fully control this" — the exact
  ambiguity the roadmap phrase "bot jako **autor** (treść generowana w jego stylu)" (bot as AUTHOR, not
  owner) explicitly avoided by using the word "autor," not "właściciel." The overlay achieves the visible
  goal (attribution + voice) with a strictly smaller blast radius: zero change to any existing ownership
  check.
- **A brand-new persona/tone mechanism for delegated content**, parallel to `AiPersona`. Rejected: `AiPersona`
  already colors `AiTextAgent`'s tone line for every non-delegated ai-text call; inventing a second,
  differently-shaped tone injection point for the delegated case would duplicate that seam for no reason —
  `AiVoiceContext` instead REPLACES the persona line when present, so `AiTextAgent`/`AiTextGenerationService`
  gained one small conditional, not a fork.
- **Native structured output for the slot-fill call** (`laravel/ai`'s `HasStructuredOutput`, already spiked
  and deliberately deferred for `shot_list` in ADR-0035 D6). Deferred here for the identical reason: it would
  need its own metered-call wiring, and a defensive parse is still needed either way since a provider can
  violate a declared schema at the edges.
- **Bot-filling FILE and deep-composite slots** (e.g. by having the bot pick from recent Disk uploads, or
  author nested object fields). Explicitly deferred (D-E): a bot forging a Disk file reference is a security
  concern (arbitrary file exposure) this slice does not need to solve to deliver "the bot fills its own
  voice + text/scalar inputs," and a deep composite's shape needs its own prompt-design work the schema-line
  approach here does not cover well.

## Consequences

- **Positive.** The author overlay (D-A/D-B) is additive and reversible: every existing sessions-engine
  invariant (ownership, the claim/job state machine, per-part history/undo, the lifecycle reaper) is
  untouched — a delegated session is a normal session with two extra columns and one substituted tone line.
- **Positive.** `AiVoiceContext` (D-C) cost zero new metering wiring — it rides the EXISTING
  `AiTextGenerationService`/`MeteredAiCall` seam every ai-text call already goes through, so the cost meter
  (ADR-0033) needed no changes to account for delegated spend.
- **Positive.** The one-way Bot → Generator/Variables edge (D-F), test-pinned in both directions, means a
  future consumer of the delegation seams (e.g. a workflow someday wanting to delegate) can reuse
  `SessionDelegationService` without Generator ever knowing Bot exists.
- **Trade-off (accepted).** Slot-fill scope is intentionally narrow (D-E) — a session with only file/deep-
  composite required slots gets a fill report with everything in `unfilled_required` and nothing filled. This
  is the deliberate low-trust posture, not an oversight; broadening it is future work with its own security
  design.
- **Trade-off (accepted).** `can_delegate` and `can_undo_delegation` are two separate resource flags rather
  than one shared gate (D-G) — a small wire-shape cost for correctness (a failed delegated session must stay
  revertible even though it is not "editable").
- **Deferred scope** (see `docs/backend/generator-sessions-api.md` → "Planned / deferred" for the
  authoritative list): bot autonomy beyond slot-fill (e.g. bot-initiated regenerate/refine), file/deep-
  composite slot bot-fill (see the amendment below for the scope of that refusal), delegation feeding into
  Approvals or Publishing. ~~The `generate_content` workflow step (R2 sub-stage 5)~~ — **BUILT**, see
  `docs/decisions/ADR-0039-workflow-suspend-resume-and-generate-content.md`.

**Amendment (ADR-0039, R2 sub-stage 5).** D-E's FILE-slot refusal above is specific to the BOT trust
boundary (`SlotScopePolicy::Bot`) — a MODEL proposing a value it was never actually given must never be able
to forge a Disk reference, and that reasoning is unchanged. It is not a blanket statement that no automated
caller may ever supply a file slot: ADR-0039 introduces a SECOND policy on the same `SlotScopePolicy` enum,
`Automation`, for the `generate_content` workflow step, which DOES allow a single SCALAR `file` slot — the
mapping there is authored by a trusted workspace human (not proposed by a model) and the file id is resolved
through the tenant-scoped `File` model before anything is persisted. `SlotScopePolicy::Bot`'s refusal here is
unchanged and still absolute. See ADR-0039 D14 for the full reasoning.

See `docs/backend/generator-sessions-api.md` for the full delegate/undo endpoint contract, the overlay +
fill-report wire shapes, and the five new `GenerationSessionResource` fields; `docs/backend/bots-api.md` for
the Bot-module side of the edge; `docs/decisions/ADR-0034-generation-sessions.md` for the sessions engine
this delegation overlays unchanged; `docs/decisions/ADR-0033-ai-cost-meter.md` for the shared metered-call
seam the slot-fill call and every delegated ai-text call route through; `docs/decisions/
ADR-0007-bot-module-design.md` for the persona/style/dictionary/phrases/prohibitions material
`BotVoiceComposer` composes.

**Amendment — `fill_mode`: the fill's intent becomes an explicit click-time choice.** Two owner-reported
defects surfaced in D-E's autonomous slot-fill after it shipped: (1) delegating a session that already had
some (or all) of its inputs filled by a human still made — and billed — an `ai_text` call, and the model
simply echoed the same values straight back (the schema prompt listed every in-scope slot's `[current: …]`
value with no instruction to change it); (2) re-delegating the same session repeatedly kept returning
identical values, because the prompt was byte-identical on every call — exactly the input a provider (and
its cache) turns into the same completion. Both defects trace to the same root cause: the fill had exactly
ONE behavior, and that behavior conflated two legitimate but incompatible readings of "delegate this session
to a bot" — "fill in what's missing" vs. "let the bot take this over and redo it."

Rather than guess which reading the caller wants (or add a heuristic), the fix makes it an explicit request
field the human chooses at the moment they click delegate: `fill_mode: 'gaps' | 'fresh'` on
`POST …/delegate` (`App\Modules\Bot\Enums\SlotFillMode`), optional, an unknown value is a `422`.

- **`gaps` is the default** — the non-destructive reading, so an existing caller that sends neither key (or
  any client written before this amendment) keeps exactly today's now-corrected behavior and can never
  overwrite a human's input. It offers the model ONLY the currently-empty in-scope slots; the already-filled
  ones travel as read-only AUTHOR CONTEXT so the proposal stays coherent with what the human wrote. The
  server does not merely ask nicely: a proposal for an already-filled slot is REFUSED before it ever reaches
  the persist path (`skipped` reason `already_filled`) — belt AND braces on top of it never being offered, so
  a prompt-injected "fill everything" cannot overwrite a human value either. With no gap at all, `fill()`
  short-circuits before the prompt is even built: no provider call, nothing billed
  (`fill_report.nothing_to_fill: true`) — the delegation overlay is still stamped, so the bot still becomes
  the author even when there was nothing left to fill.
- **`fresh` is safe specifically because D-G's undo already exists.** It offers every in-scope slot and
  explicitly instructs the model that its proposal must differ from what is there now ("take it over and do
  it your way"). This is deliberately destructive — it can and will overwrite a human's own prior fills — but
  `DELETE …/delegate` restores `bot_delegation.slot_values_before` in full, so a `delegate(fresh) → undo`
  round trip is a complete, zero-data-loss revert. Without D-G's snapshot-restore already in place, `fresh`
  would not have been an acceptable mode to ship.
- **The repeated-identical-values defect is fixed independently of mode**, by a per-call VARIATION TOKEN
  appended to every fill prompt (`BotSlotFillService::variationLine()`). The finding behind it: the shared,
  budgeted `AiTextGenerationService::generateWith()` seam reaches the provider through laravel/ai's
  `Agent::prompt()`, whose signature carries no sampling knob at all — temperature is read by REFLECTION off
  a compile-time `#[Temperature]` CLASS attribute in `Laravel\Ai\Gateway\TextGenerationOptions::forAgent()`,
  so it is a constant baked into an agent class, not something a caller can vary per call, and not something
  this shared seam can be made to carry without changing every other consumer of it. With no sampling knob
  available, the message itself is the only per-call variable, so a meaningless, never-logged hex token is
  the whole fix: two consecutive `fresh` prompts for the same session now differ by that token alone.

No new cross-module edge, no new column, no schema change — `fill_mode` only changes which slots
`BotSlotFillService` offers and what it tells the model about them; D-E's scope restriction (FILE and deep
composite slots never offered, `SlotScopePolicy::Bot`) and D-G's undo/restore path are unchanged and reused
unmodified by both modes. See `docs/backend/generator-sessions-api.md` → "POST …/delegate" and
`docs/backend/bots-api.md` for the full request/response contract, and `tests/Feature/
BotSessionDelegationTest.php` for the `fill_mode` matrix (gaps byte-preserves a human value + refuses an
already-filled proposal; no-gap = zero AI calls; fresh replaces everything and asks for a different take; two
fresh fills send different prompts; undo-after-fresh).
