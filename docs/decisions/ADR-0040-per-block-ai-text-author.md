# ADR-0040 — per-block `@[ai-text]` AUTHOR: a bot replaces the persona picker

**Date:** 2026-07-29 (created)
**Status:** Accepted
**Module:** `App\Modules\Variables` (`Contracts\AuthorVoiceResolver`, `Support\AiVoiceContext`,
`Support\NullAuthorVoiceResolver`, `Services\AiTextGenerationService`, `Agents\AiTextAgent`), `App\Modules\Bot`
(`Services\BotAuthorVoiceResolver`), `App\Modules\Generator` (`Services\RecipeAuthorVoiceSnapshotter`,
`Services\GenerationSessionService`, `Services\GenerationSessionExecutor`, `Services\GeneratorAiTextService`,
`Models\GenerationSession`), `App\Modules\Workflows` (`Services\WorkflowStepRunner`), frontend
`resources/js/next/ui/editor/extensions/aiText.ts` + `AiTextPanel.vue`, `resources/js/next/ui/forms/
BotSelect.vue`, `resources/js/next/ui/forms/Select.vue`, `resources/js/next/app/stores/botDirectory.ts`
**Relates to:** ADR-0013 §4 (the original `@[ai-text]` persona decision this one amends — see the amendment
appended there), ADR-0036 (the session-level bot-delegation voice this feature generalizes to block
granularity — same `AiVoiceContext` seam, same `BotVoiceComposer` composition, reused not forked), ADR-0038
(the creative-direction "voice wins on tone" rule this ADR widens from session-only to per-block), ADR-0039
(the workflow suspend/resume engine this feature's live-per-pass resolution rides inside `WorkflowStepRunner`
unchanged)

---

## Context

ADR-0013 shipped `@[ai-text]` with a small, CLOSED set of tone personas (`neutral`/`friendly`/`formal`/
`concise`) and explicitly rejected letting an author pick one of their workspace's Bots as the "persona" —
left as a *possible future*, not built. ADR-0036 (R2 sub-stage 3) then built exactly that idea, but only at
the SESSION level: delegating a whole Generator session to a bot makes every block in that session render in
the bot's voice.

That left a gap the owner asked to close: a single template or workflow step routinely mixes several
authorial voices in one document (a hook in one bot's playful voice, a CTA in another's formal one), and
nothing let an individual `@[ai-text]` block name its own author independent of whether the whole run was
delegated. The fix generalizes ADR-0036's mechanism one level down — from "the session's author" to "this
block's author" — and, since the previous persona picker and this new author picker occupy the same UI slot
and answer the same underlying question ("whose voice writes this text?"), the owner also decided to REMOVE
the persona picker from the editor rather than run two competing pickers side by side.

## Decisions

**D1 — a Variables-side CONTRACT (`AuthorVoiceResolver`), a Bot-side implementation
(`BotAuthorVoiceResolver`), inversion of dependency.** Variables is the lowest shared layer (both Workflows
and Generator depend on it, never the reverse — `VariablesModuleBoundaryTest`); an author is, today, always a
Bot, but Variables must not import `App\Modules\Bot` to know that. `AuthorVoiceResolver::voicesFor(array
$authorIds, ?string $workspaceId): array` is declared in Variables and bound to `BotAuthorVoiceResolver` by
the Bot module's own provider (`bind`, overriding Variables' own `bindIf(…, NullAuthorVoiceResolver::class)`
default). The two verbs are chosen so the winner does NOT depend on provider load order — an unconditional
`bind` wins over an already-installed default, and `bindIf` cannot clobber an already-bound concrete — because
getting this wrong is fail-safe and therefore invisible (no run breaks; authored blocks just quietly stop
sounding authored). Both halves are pinned in `BotModuleBoundaryTest` by registering each provider LAST; the
order in `bootstrap/providers.php` is deliberately NOT asserted, so a harmless reorder stays harmless.
This is the SAME inversion pattern `AiTextGenerator` already established in PR-1a (ADR-0030) and
the SAME edge shape ADR-0036 used for session delegation — reused, not reinvented. `App\Modules\Bot` joins
the forbidden-import list in both `VariablesModuleBoundaryTest` and the Workflows/Generator boundary tests
that already forbade it, so a stray import fails loudly.

**D2 — a BATCH lookup, one query per recipe/run, never per block.** `voicesFor()` takes every author id a
document mentions and returns them in ONE `whereIn` query. `RecipeAuthorVoiceSnapshotter` (Generator) and
`WorkflowStepRunner::authorVoicesFor()` (Workflows) both scan their document ONCE — through the SAME shared
scanner, `VariableResolver::collectAiTextAuthorIds()`, which walks nested prompts and if-block branches so a
buried author is never missed — collect the distinct ids, and call `voicesFor()` a single time. A per-block
author therefore never turns into an N+1 across a recipe or a run, however many blocks name one.

The returned map is keyed by the id spelling the CALLER asked with, not the one the database echoes back: a
`uuid` column matches case-INSENSITIVELY, so an UPPERCASE author id (reachable through an API-created template
or an import) resolves a row and is PAID FOR, and a lowercase key would then miss in
`effectiveDirective()`, which looks the id up exactly as its own directive spells it — a paid query whose voice
is silently discarded. All matching spellings are keyed, so two blocks naming the same author in different case
are both voiced from the one query. Normalizing case inside `AiVoiceContext` instead was rejected: it would
push a rule about id BYTES into the Variables layer for no gain.

**D3 — Generator FREEZES the resolved voices into `recipe_snapshot.author_voices` at session CREATION; Workflows
resolves them LIVE, once per pass.** These are deliberately different, because the two engines have different
existing invariants to preserve:

- A `GenerationSession` is already snapshot-authoritative (ADR-0034 D1: `recipe_snapshot` is captured once
  and never re-read from the live template) — freezing `author_voices` alongside it at
  `GenerationSessionService::create()` is the SAME rule applied to one more field, not a new one. Editing or
  deleting a bot after a session exists can never change what that session renders, exactly as editing the
  source template already cannot. `author_voices` is a SERVER-ONLY key inside the snapshot — never
  serialized onto `GenerationSessionResource` or any other wire response; the FE never needs it and must
  never see it (it is opaque, trusted, agent-instruction material — same posture as `bot_delegation.voice`).
- A `WorkflowRun` has no equivalent snapshot to freeze into — a run always executes the workflow definition
  AS IT IS NOW (there is no "recipe" object a run owns the way a session owns one). `WorkflowStepRunner::run()`
  therefore resolves the whole definition's authors LIVE, once per pass, before the step loop starts, and
  installs the map on the ambient `AiVoiceContext` for that pass. This is also the right answer for
  suspend/resume (ADR-0039): a resumed pass re-enters `run()` from the parked position and rebuilds the map
  from the database exactly like it rebuilds everything else — nothing about author voices needs its own
  entry in the `waiting_on` persistence. The direct, ACCEPTED consequence: **editing a bot's voice changes
  what an in-flight run produces from the moment of the edit onward** — a run already past a step keeps what
  it already generated (steps do not re-run), a SUSPENDED run resolves the edited voice for every step still
  ahead of it on resume, and a fresh run picks it up immediately. This is consistent with every other
  Workflows read (a run always reads live state — the trigger's form, `globals`, the target task) and is a
  DELIBERATE asymmetry with the Generator side, not an oversight; see "Alternatives" below.

**D4 — precedence: BLOCK beats SESSION/RUN, decided in exactly one place.**
`AiVoiceContext::effectiveDirective(?string $authorId)` is the single method every caller (Workflows' and
Generator's shared `AiTextGenerationService::generate()`, and `GeneratorAiTextService::withDirection()` for
the creative-direction tone suppression) asks, so the rule is never duplicated: a block's OWN author, if it
resolves, wins; otherwise the ambient session/run-wide voice (an ADR-0036 delegated session, or — on the
Workflows side — nothing, since Workflows never sets a session-wide voice) applies; with neither, the legacy
`personaId` tone applies. The mental model is "a delegated bot writes everything that has no author of its
own" — delegation is the FALLBACK a block can locally override, not a stronger claim a block author could
ever lose to. The reverse ordering (session wins over block) was rejected: it would make an explicit,
per-block authoring choice silently overridden by a whole-session default, defeating the entire point of
adding block-level granularity.

**D5 — FAIL-SAFE, not fail-closed: an unresolvable author degrades TONE, never blanks or breaks a block.**
`BotAuthorVoiceResolver::voicesFor()` never throws and never returns a sentinel for "not found" — an id that
is malformed, unknown, soft-deleted, or belongs to another workspace is simply ABSENT from the returned map.
`AiVoiceContext::effectiveDirective()` treats an absent id identically to no-author-at-all: it falls through
to the session voice, then the persona tone, then neutral. The alternative — fail-CLOSED, e.g. refusing to
generate a block whose author cannot be resolved, or rendering an explicit error in its place — was rejected:
a deleted bot is a routine, expected lifecycle event (a workspace member removing a bot they no longer use),
not an integrity violation, and a template/workflow authored months earlier should keep producing USABLE
text with a degraded tone rather than start failing a field it always used to fill. The one place this
posture is visibly surfaced to a human is the editor: `AiTextPanel.vue` distinguishes a DEFINITIVE "author
unavailable" (404/403) from an inconclusive network failure, and offers "Clear author" — but the RUNTIME
degradation is silent by design; the editor's warning is a courtesy, not a gate.

"Never throws" is a contract clause, not a language guarantee, so the CONSUMERS uphold it rather than merely
trust it: `WorkflowStepRunner::authorVoicesFor()` degrades a throwable (severed connection, deadlock, a tenant
database missing the authors' table) to the empty map, and the map is INSTALLED as the first statement inside
the run scope's `try` — the same scope whose `finally` unwinds the published run context, the ai-text budget
and the voice map. Outside it, a throwable would skip that unwind and leave a DEAD run published PROCESS-WIDE,
so every later job the worker picked up would be stamped with it (ADR-0015) and charged for its AI spend
(ADR-0037). The degradation is deliberately not reported: a `QueryException`'s message carries the failed
statement AND its bindings, i.e. the author ids, which never go to a log. Correspondingly, the contract now
states that an implementation MUST validate an id's SHAPE before querying — the collector reports what the
content says (arbitrary bytes included), so the refusal has to live in the implementation.

`RecipeAuthorVoiceSnapshotter::snapshot()` upholds the same clause on the OTHER consumer, for a different
reason: it runs on the INTERACTIVE create (and inside the queued automated one), where an escaping throwable
would not leak process state — it would simply 500 a plain "create session" for any recipe that merely NAMES
an author, converting a fail-safe tone degradation into a hard outage on the feature's own happy path. Both
guards are pinned by a test that binds a throwing resolver and asserts the session is still created and still
renders its text.

**D6 — the workspace boundary is REFUSED, not widened, when it cannot be pinned.**
`BotAuthorVoiceResolver` requires an explicit `$workspaceId` unless the application is in own-database
tenancy mode (where the dedicated connection IS the boundary). A queued Workflows run has no ambient active
workspace (`WorkspaceScope` is a documented no-op there), so if a caller ever forgot to pass the run's own
`workspace_id`, an ambient-only query would resolve EVERY workspace's bots — exactly the cross-tenant leak
the explicit id exists to prevent. Rather than let that happen, the resolver returns an EMPTY map (fail-safe,
per D5) when neither a pinned id nor own-database mode is present. Losing every author's tone in that
(currently unreachable, defensively guarded) scenario is an acceptable cost; reading a foreign workspace's
bots is not.

**D7 — the cost-meter ACTOR is unaffected by a per-block author.** The workspace's $ AI budget (ADR-0037) is
gated and attributed per (workspace, actor) — the human/bot/workflow-run that OWNS the session or run. A
block naming a different bot as its AUTHOR does not re-attribute that block's `ai_text` spend to the named
bot; the spend is still charged against the session/run's own actor. This is a deliberate refusal, not an
oversight: re-attributing cost per block-level author would let a template author route arbitrary AI spend
onto another actor's (or another bot's) monthly cap purely by naming it in a text field — a budget-bypass
vector the $-first design (ADR-0037) exists specifically to close. Voice and cost attribution are
INTENTIONALLY decoupled seams.

**D8 — the persona picker is REMOVED from the editor UI; `personaId` keeps working at runtime, unpickable.**
The owner's call: once an author picker answers "whose voice writes this text?" more expressively than a
4-value closed tone enum, keeping both pickers in the same panel would ask the user to reconcile two competing
answers to the same question. `AiTextPanel.vue` therefore no longer renders a persona `Select` — a block
already carrying a `personaId` (every block authored before this feature) shows it as a READ-ONLY "legacy
tone" bar with a "Clear tone" action, and precedence (D4) has the author win over it when both are present.
`personaId` is NOT removed from the wire format or the backend: `AiPersona::fromNullable()`, the `ai_personas`
catalog endpoints, and the fallback rendering path are all UNCHANGED, so a session/run created before this
feature — with no `author_voices` in its frozen snapshot at all — renders exactly as it always did (the
fail-safe path this ADR's whole design leans on). The "Etykiety wiedzy" (`labels`) field is hidden in the same
pass for an unrelated reason: it was decoded off the wire and then immediately discarded by the runtime — it
never did anything — so showing an editable control for it was actively misleading. `labels` data already
stored on existing blocks is preserved byte-for-byte (still decoded, still re-emitted unchanged on save); only
the now-honest editor control is gone.

**D9 — the creative-direction TONE is now suppressed per block, not merely per session (ADR-0038 widened).**
ADR-0038's "voice wins on tone" rule originally asked only whether the SESSION was delegated. `GeneratorAiTextService::
withDirection()` now asks `AiVoiceContext::effectiveDirective($authorId)` instead — the SAME precedence
function D4 centralizes — so a block with its OWN author suppresses the derived direction's `tone` field even
inside an otherwise UNdelegated run. Without this widening, an author-written block in a non-delegated run
would have carried both a bot voice AND a competing derived tone instruction in the same prompt. A block with
no effective voice at all (no author, no session delegation) keeps the full, unchanged direction projection.

**D10 — `shot_list` carries no part-level author; a refine speaks in the part's author only when the part
declares exactly ONE.** A `shot_list` brief has no natural single "author" slot of its own (its nested shots
do); the picker is therefore not offered at that level in the editor. `GenerationSessionExecutor::
partAuthorId()` — used when refining an already-rendered `text_body`/`script` part — scans that part's content
for every `@[ai-text]` author and applies one only when the part names exactly one distinct author. A refine is
ONE revision of the part's WHOLE output, so it has exactly one voice to speak in; when the part's blocks name
SEVERAL authors, no single one of them is that voice, and handing the part to whichever the scan reached first
would rewrite another author's text in a stranger's tone with nothing in the UI saying so. An AMBIGUOUS part
therefore falls back to the run-wide session voice and then the persona — the same ladder an unresolvable
author already takes. Deriving from the frozen content (rather than re-deriving per call) still keeps repeated
refines of the same part stable.

Known limitation, accepted: the shared scanner reports only the authors that ARE named, so a part mixing ONE
authored block with author-LESS ones counts as unambiguous and refines in that one author's voice.
Distinguishing "one author + plain blocks" from "one author only" would require threading a per-BLOCK sink
through `VariableResolver`'s whole scan walk — a wide change to the most delicate shared code for a case
materially rarer than two competing authors.

**D11 — the TREE node carries both halves of the author, ahead of any consumer needing it.**
`App\Markdown\Tree\Nodes\AiTextNode` round-trips `authorId` AND the display-only `authorName` snapshot. Reach
today is ZERO — `MarkdownTreeCast` is used only by Task and Comment, while ai-text blocks are enabled only in
the Generator and Workflows, which persist RAW markdown rather than trees — so this is deliberately
speculative: the cost is one array key, and the cost of the omission would be permanent, silent data loss the
first time a tree-backed field enables ai-text (a chip labelling a deleted author "Unknown author" with no way
back, traced to a missing array key far from where it was noticed). Pinned by `tests/Unit/Markdown/
AiTextNodeTest.php`. `toArray()` is consequently no longer byte-identical to its pre-feature output for a node
that has been through `fromArray()`; with no tree-backed consumer of ai-text, nothing observes the ordering.

## Alternatives considered and rejected

- **Session wins over block (D4 reversed).** Rejected: it would make the more specific, more deliberate
  authoring signal (a human explicitly picking a voice for THIS block) lose to the coarser default — the
  opposite of what "add block-level granularity" was for.
- **Freeze Workflows' author voices too, e.g. into a definition-level snapshot column.** Rejected (D3):
  Workflows has no analogous "recipe" the way a Generator session has `recipe_snapshot`; a workflow RUN is
  defined to execute the definition as it stands, and every other reference a run resolves (form fields,
  `globals`, target records) is already live-read, not snapshotted. Introducing a snapshot for just this one
  field would be a new, inconsistent invariant solely for this feature.
- **Re-attribute AI-spend cost to a block's named author (D7 reversed).** Rejected: turns a text-authoring
  choice into a budget-attribution mechanism, defeating the $-first cap's purpose (ADR-0037).
- **Fail-closed on an unresolvable author (D5 reversed) — block the field, or render a visible error string.**
  Rejected: a routine bot deletion should not turn every template/workflow that ever named it into a
  hard failure; degrading gracefully to the next tone in the precedence chain matches how every other
  `@[ai-text]` failure mode in this codebase already behaves (fail-closed only at the truly unrecoverable
  edges — a blank prompt, an exhausted budget, a provider outage).
- **Keep the persona picker alongside the new author picker (D8 reversed).** Rejected by the owner: two
  competing "whose voice?" controls in one panel is worse UX than one, and `personaId` staying functional
  read-only for legacy blocks costs nothing new to build (the fallback path already had to exist for D5).

## Consequences

- **Positive.** The same `AiVoiceContext`/`AuthorVoiceResolver` seam ADR-0036 built for session delegation now
  serves BOTH session-wide and per-block authoring with zero forking — one precedence function, one voice
  composer, one opaque-string contract.
- **Positive.** The batch-lookup discipline (D2) means this feature costs at most ONE extra query per session
  creation and per workflow run pass, regardless of how many `@[ai-text]` blocks a document has.
- **Trade-off (accepted).** The Generator/Workflows asymmetry (D3) is a genuinely different behavior a user
  can observe: a Generator session's authored voice is permanently frozen at creation, while a workflow step's
  resolves fresh on every run (and, for a resumed run, fresh for every remaining step). This must be
  documented clearly wherever both are described, since it is easy to assume they behave identically.
- **Trade-off (accepted).** `personaId`/`ai_personas` remain permanently in the codebase as a read-only legacy
  path with no way to newly create one — an intentionally asymmetric deprecation (write path removed, read
  path kept) rather than a full removal, because a hard migration of every existing `personaId` block was
  judged unnecessary: the fail-safe fallback already renders it correctly forever.
- **Deferred / out of scope.** A redo/undo for a per-block author choice beyond the editor's own Cancel;
  bulk "reassign every block currently authored by bot X" tooling; surfacing `author_voices` (or any part of
  it) on any wire resource — it remains a server-only key, by design.

See `docs/backend/generator-sessions-api.md` ("Per-block AI-text authors" / recipe_snapshot shape),
`docs/backend/generator-api.md` ("The RESOLVED voice is a SESSION-run concept"), `docs/backend/
workflows-api.md` (§c, `@[ai-text]` — the "Per-block AUTHOR" / "Resolved LIVE" paragraphs) for the full
endpoint/wire contracts, and `resources/js/next/ui/editor/README.md` for the `authorId`/`authorName`
emit-or-omit wire format.
