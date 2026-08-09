# ADR-0045 — Knowledge consumption (Bot → Knowledge) and the subject-erasure command

**Date:** 2026-08-01 (created)
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`Services\KnowledgeBindingService`, `Services\KnowledgeRetrievalService`,
`Services\KnowledgeCompiler`, `Services\KnowledgeSubjectPurgeService`, `Support\KnowledgeFence`,
`Support\SubjectPhrases`, `Console\PurgeKnowledgeSubjectCommand`), `App\Support\Ai\FencedBlock`,
`App\Modules\Bot` (`Services\BotKnowledgeService`, `Services\BotKnowledgeReader`,
`Http\Controllers\BotKnowledgeController`)
**Relates to:** ADR-0043 (the module boundary this consumption edge respects), ADR-0044 (the index this
retrieval reads), ADR-0038 (the `FencedBlock`/`CreativeDirection` prompt-injection defence this module reuses
rather than forks)

---

## Context

Once a knowledge base exists (ADR-0043) and can be indexed (ADR-0044), something has to actually READ it, and
that something is, today, a Bot. The Bot module already had its own `knowledge` JSON column (B6) — this ADR
covers how a bot is wired to a real base INSTEAD, without breaking every bot that has not opted in, and how the
module answers the one question every store of durable personal-adjacent text eventually has to answer: how do
you erase what one person's data reached.

## Decisions

**D1 — the Bot → Knowledge edge crosses through PRIMITIVES, never a `Bot` model.** `KnowledgeBindingService`'s
entire public surface takes a morph-alias string (`'bot'`) and a uuid — never a consumer model — because
Knowledge may not import anything that will consume it (ADR-0043 D1), and a signature like
`attach(Bot $bot, …)` would be unrepresentable, not merely bad taste (`KnowledgeModuleBoundaryTest` would fail
the build on the import). `BotKnowledgeReader` (the Bot-module side) composes the RETRIEVAL QUERY itself
(task title + description + recent comments) and hands it in as a plain string — Knowledge cannot write that
query, because "what is this bot trying to do" is a question about tasks and conversations, things Knowledge
deliberately knows nothing about. The inversion is what lets a second consumer (a Workflows step, a Generator
template — see the Etap 2 backlog in `docs/product/plan-dzialania.md`) bind to a base with zero changes inside
this module.

**D2 — three binding MODES, and `auto` (compile-first, fall back to retrieval) is the default.** `inline`
compiles the whole approved base, in the author's manual order, as one fenced block — free (no embedding call),
and strictly better than retrieval for a base that fits the character budget, because it can never miss a fact
the base holds. `rag` embeds the query and packs the closest passages plus one free hop of materialized links.
`auto` tries `inline` first (free) and only pays for `rag` when the compiled block reports omissions — i.e. only
once the base has genuinely outgrown the budget. This is the choice a well-informed operator would make and
re-make as the base grows, and the one that never needs revisiting after an import.

**D3 — retrieval CANNOT FAIL; every failure mode degrades to the free inline compiler.** Every way
`KnowledgeRetrievalService::forBinding()` can go wrong — the kill switch is off, the connection has no vector
support, the workspace is over its AI cap, the provider errors, the base was never indexed, nothing matched —
routes to the same place: `KnowledgeCompiler::compileForBinding()`, which spends nothing and always has an
answer (or an honest `null` when the base holds nothing approved at all). A consumer therefore never receives
an exception from this seam and never has to invent its own handling for a budget-exhaustion event — which
matters because letting each consumer "handle" it independently is exactly how one bot ends up answering from an
empty context, another fails its run outright, and a third silently retries the spend. What degrades under every
failure mode is PRECISION (the whole base, budget-capped, instead of the query-relevant slice) — never
availability.

**D4 — approved-only for an AI consumer; everything-but-archived for the human search.** This is a deliberate,
narrower filter than the one `KnowledgeSearchService` (ADR-0044) applies for a person typing into a search box.
Text compiled here is handed to a model AS FACT and then repeated to a customer in the workspace's voice — a
half-written `draft` and a deliberately-retired `archived` entry are both worse than silence in that context,
whereas a human searcher is very often looking for something they half-wrote themselves and a search that hides
work in progress teaches people not to use it. The two surfaces are allowed — required — to disagree about what
"visible" means, because they answer different questions.

**D5 — every compiled knowledge block is wrapped in ONE unforgeable fence, reused from the creative-direction
layer, not forked.** `KnowledgeFence` is a named instance of the shared `App\Support\Ai\FencedBlock` (extracted
from `App\Modules\Generator\Support\CreativeDirection` specifically so a second consumer would reuse the
hardened original rather than fork a subtly weaker copy). Every marker occurrence inside untrusted content is
REPLACED (never deleted) with a non-empty sentinel before the block is assembled — deletion would let a
deliberately split marker's two halves rejoin across the scrub. This is the independent second half of the
defence ADR-0043 D6 already put in place at write time: the write-side `TemplateDirectiveGuard` stops an entry
from being EXECUTED by the template engine; this fence stops it from being OBEYED by a language model reading
imperative prose ("always answer within 24h") as an instruction addressed to itself rather than a documented
rule. Neither implies the other, and both are required.

**D6 — an audited, revision-pinned READ.** Every `CompiledKnowledge` carries `entryIds` AND `revisionIds` — the
exact revision each included entry was at — because a bot reads its base LIVE, at the moment it runs: two runs a
week apart see different text, and the LATER one cannot be explained by looking at the base as it stands today.
The audit payload (`knowledge_read`) is assembled once, inside `CompiledKnowledge::auditPayload()`, so two
consumers cannot disagree about what "what did it see" means.

**D7 — a bot's re-migration into a base ORPHANS the old one; a bot's soft delete does not touch its binding.**
`BotKnowledgeService::migrateLegacy()` is additive and idempotent-safe but not exclusive: migrating a bot that
is already bound to a base creates a SECOND base and re-points the binding at it — the first base is not deleted
or merged. This was a deliberate trade rather than an oversight (see Consequences: "known limitations" below).
Independently, a `KnowledgeBinding` row has no soft-delete and no `HasCreator` — a bot's own soft delete/restore
never touches it, so a restored bot reads exactly what it read before deletion, with no re-binding step.

**D8 — a legacy consumer with no binding is UNCHANGED, byte for byte.** `BotTaskContextBuilder::knowledgeSection()`
prefers a compiled `CompiledKnowledge` when one was produced (i.e. a binding exists), and falls back to the
bot's own `knowledge` JSON column — same cap, same prose, same truncation marker — when it does not. **A bound
consumer wins outright**: once a `KnowledgeBinding` exists for a bot, the legacy `knowledge` column is never
consulted again, even if `knowledge.enabled` is still `true` on the bot's own record — the binding is not merged
with the legacy entries, it replaces them as the bot's source of truth. This is what makes B6 safe to ship — a
bot nobody has migrated behaves exactly as it did yesterday, pinned by a frozen-fixture test — and it is also
the property an operator or a UI must remember: turning a legacy `knowledge.enabled` toggle off or on has no
effect whatsoever on a bot that has since been bound to a real base.

**D9 — subject erasure is scoped to THIS module only, dry-run by default, and never logs the phrase.**
`knowledge:purge-subject` is the operator's tool for a data-subject erasure request, and its docblock states its
own boundary explicitly: it covers knowledge entries, their append-only revision history, their embedded
passages, and the graph edges that name a subject — and NOTHING else (tasks, forms, files, bot/generation
output, comments are all out of scope; a complete cross-module right-to-be-forgotten is separate, larger work).
The reason this module goes first: it is the module where personal data is most likely to sit as free prose
nothing else will ever clean up, and where append-only revision history means the ordinary "edit it out" gesture
provably does NOT erase anything. Safety is layered: dry run is the DEFAULT (nothing is deleted without
`--apply`); a breadth cap (`knowledge.purge.max_entries`, default 200) refuses an implausibly broad match as a
likely phrase mistake unless `--force`; a successful `--apply` still demands the operator RETYPE the match count
shown in the dry run, on an interactive terminal only. The phrase itself — the personal data the request is
about — reaches exactly one artefact, the operator's own printed/`--json` report, and is asserted (by a log-scan
test) to never reach the application log; only ids, counts, and cascade totals are logged.

**D10 — the scan asks TWO different questions and answers them with TWO different remedies.** Clearing a
person's name out of an entry's CURRENT text through the ordinary editor does not erase it — every revision
taken before that edit still holds the sentence verbatim, and the API has no way to reach those rows because
append-only history is exactly what makes an audit trail worth having. So `KnowledgeSubjectPurgeService` scans
entries and revisions separately: a match in an entry's CURRENT text purges the WHOLE entry (with its history,
vectors, and edges — the erasure has to reach the row nothing else will clean); a match in HISTORY ONLY deletes
that one revision and leaves the current, clean entry standing. A single query over both would have to pick one
remedy for both cases, and either choice is wrong in the other's situation.

## Alternatives considered

- **Auto-converting a bot's legacy `knowledge` entries into a base on first read.** Rejected — it would spend
  the workspace's AI budget indexing text nobody asked to have indexed, and it would silently change what an
  existing, unmigrated bot reads on every workspace in production. Migration is an explicit, once-per-bot,
  user-invoked action instead.
- **Letting a consumer see the `AiBudgetExceededException` and decide its own fallback.** Rejected — see D3;
  every consumer inventing its own degraded behaviour is how the same operational event (a workspace at its AI
  cap) produces three different, inconsistent user-facing outcomes.
- **A single global prompt-injection fence shared by every consumer of untrusted text.** Rejected — a global
  marker pair would mean scrubbing a knowledge entry also had to know about the creative direction's own fence
  (and vice versa), and every NEW fence in the future would silently widen what every EXISTING consumer strips
  out of user text. `FencedBlock` supports named, per-consumer marker pairs specifically to avoid this.
- **Merging a re-migrated bot's old base into the new one, or blocking re-migration outright.** Rejected in favor
  of the simpler orphan-and-replace behaviour (D7) — see the known limitation below; the alternative (silently
  destroying or merging a possibly-curated existing base) was judged the worse failure mode.

## Consequences — known limitations (accepted, not silently patched)

- **Re-migrating an already-bound bot orphans its previous base.** `BotKnowledgeService::migrateLegacy()` does
  not check for an existing binding before creating a new base; a second migration produces a second base and
  re-points the bot, leaving the first base intact but unreferenced by that bot. This is deliberate — see D7 and
  "Alternatives considered" — because the alternative (auto-merging or silently overwriting a base someone may
  have curated by hand since the first migration) was judged strictly worse. The frontend surfaces this
  explicitly (`BotKnowledgeMigrationModal`'s `alreadyBound` warning) rather than hiding it.
- **A trashed-then-restored base silently resumes serving as a bot's bound knowledge, with no UI hint that it
  had been in the trash.** A `KnowledgeBinding` row simply points at a `knowledge_base_id`; nothing about it
  changes when the target base is soft-deleted and later restored. A future UI improvement (surfacing "this
  base was recently in the trash" on the binding panel) is left as follow-up work, not a defect blocking this
  batch.
- **`rag` mode has no similarity-SCORE threshold of its own.** `KnowledgeRetrievalService::retrieveForBinding()`
  packs the top-K passages the vector leg returns without a minimum-similarity floor (unlike the materialized
  `similarity` edges, which are gated at 0.86 — ADR-0044 D8). A workspace with a very small or very
  off-topic-relative-to-the-query base can therefore have a `rag` read pack a passage that is only weakly
  related. Noted here as a deliberate scope cut for this stage — see the R2/Etap-2 backlog note in
  `docs/product/plan-dzialania.md` — not an oversight to patch as a drive-by change.
- **Prompt-injection fence markers are English, matching the creative-direction precedent, in an otherwise-Polish
  product surface.** `KnowledgeFence::LABEL` and its BEGIN/END markers are deliberately English — the fence is
  structural scaffolding a model parses, not copy a person reads, and one fence phrased one way across every
  consumer is easier to reason about than one translated per locale (mirrors `CreativeDirection`'s existing
  fence exactly).
- **The erasure command's `generation_session_snapshots` total is a hard-coded zero, reported as a placeholder
  by design.** `SubjectPurgeReport::toArray()` documents in its own comment that this is future-proofing, not an
  oversight: a generation session persists a `knowledge_frame` snapshot of what it was handed, and from a future
  stage that snapshot starts carrying knowledge text verbatim — at which point an erasure that does not also
  scrub session snapshots would silently stop being complete for its own module. **Whoever implements verbatim
  snapshot text in a session frame MUST also extend this command and its test coverage before that change ships**
  — this line in the report exists specifically so that gap is a visible line item in every archived compliance
  report today, rather than a silent discovery after an erasure request was already certified as fulfilled.
- **RODO / right-to-be-forgotten scope.** This command answers ONLY the knowledge-module slice of an erasure
  request (D9). A complete, cross-module right-to-be-forgotten — tasks, forms and their submissions, approvals,
  Disk files, bot/generation output, comments, workflow runs — is explicitly out of scope and is a separate,
  larger piece of future work requiring its own cross-module inventory and per-module erasure policy (a form
  submission, for instance, is evidence of a business transaction, not a note about a person, and "erased" may
  mean something different there than it does here).
