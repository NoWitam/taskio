# ADR-0046 — Knowledge AI composer: draft sessions, shadow amendments, and the erasure extension

**Date:** 2026-08-02 (created)
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`Models\KnowledgeDraftSession`, `Models\Scopes\WithoutDraftsScope`,
`Services\KnowledgeDraftSessionService`, `Services\KnowledgeDraftService`,
`Services\KnowledgeDraftRetrievalService`, `Services\KnowledgeDraftRelationService`,
`Agents\KnowledgeDraftAgent`, `Jobs\GenerateKnowledgeDraftsJob`,
`Console\ReapAbandonedDraftSessionsCommand`, `Events\KnowledgeDraftSessionUpdated`,
`Exceptions\KnowledgeDraftBudgetExceeded`, `Exceptions\KnowledgeContextAlreadyExpanded`,
`Exceptions\StaleKnowledgeWriteException`, `Support\ShadowSlug`, `Support\SimilarityThreshold`,
`Http\Controllers\KnowledgeDraftSessionController`, `Http\Controllers\KnowledgeEntryRevisionController::draftDiff()`,
`Services\KnowledgeSubjectPurgeService` (extended)), migrations
`2026_08_07_000007_create_knowledge_draft_sessions_table.php` through
`2026_08_07_000010_add_context_expanded_at_to_knowledge_draft_sessions_table.php` (+ tenant mirrors
`0001_01_01_000064` through `0001_01_01_000067`)
**Relates to:** ADR-0043 (the module and the entry this batch reuses instead of forking), ADR-0044
(the index a draft is deliberately kept out of), ADR-0045 (the retrieval/erasure surfaces this batch
extends), ADR-0038 (the `FencedBlock` prompt-injection defence `KnowledgeFence` also wraps this
prompt), ADR-0033/ADR-0037 (the shared `MeteredAiCall` gate this module's drafting spend rides)

---

## Context

ADR-0043 shipped a fully manual editor: any workspace member could type a title and content and save
an entry directly. The owner reviewed that surface after R1/R2 landed elsewhere in the product and made
an explicit pivot, recorded in the project memory as "brainstorm+plan+budowa": knowledge entries should
come into existence through an AI composer, not a blank form. Three decisions came with the pivot,
stated here because they are policy choices the code enforces without being provable by reading any one
file in isolation:

- **AI-only in the UI.** Every "new entry" affordance in the frontend (the reader's empty state, the
  entries table header, the graph's ghost-node panel, a red wikilink) now routes to the composer. The
  manual editor (`KnowledgeEntryEditorView.vue`) keeps editing an *existing* entry — creating one by
  typing into a blank form is gone from every screen.
- **A change to an existing entry publishes immediately, the same as always.** The composer's
  amendments do not introduce a second approval gate on top of what ADR-0043 D7 already decided (any
  member may edit any entry). Accepting an amendment is exactly as authoritative as a human editing the
  entry by hand — see D5 below.
- **The composer's spend rides its own channel (`ai_knowledge`), and the whole chapter ships as one
  commit.** Both are process/ledger decisions rather than architecture, and are recorded here because
  D9 and D3 depend on the first one being true.

This is the design of two batches built back to back against that pivot (drafting sessions,
create-only; then amendments, relations, and diffing) plus the RODO extension that followed once
drafts existed as real rows a purge request had to reach.

## Decisions

**D1 — a draft is an ordinary `KnowledgeEntry` row, never a second table.** `knowledge_entries` grew one
nullable column, `draft_session_id`. A draft is created through the same `KnowledgeEntryService` every
manual save uses, so it gets revisions, slug uniqueness, metadata validation against the base's schema,
and the write-side directive guard **for free** — and "accept" is a single `UPDATE … SET
draft_session_id = null`, not a copy. A parallel `draft_entries` table would have had to re-earn every
one of those properties, and turned acceptance into the one operation in the whole module where a bug
silently loses what the user just reviewed. The session itself (`KnowledgeDraftSession`) owns only the
*conversation*: the raw source text (re-read on every refinement — the model never edits its own
previous answer, it re-derives the whole set from the source plus the instruction history), the status
machine (`idle | generating | ready | failed`), and the accumulated instruction history.

**D2 — invisibility is a GLOBAL SCOPE, not a query convention, and it is a property of RETRIEVAL, not
of STORAGE.** `WithoutDraftsScope` (`whereNull('draft_session_id')`) is attached to `KnowledgeEntry` via
`#[ScopedBy]`, so every existing read in the product — the entry list, the reader, hybrid search, the
graph, the trash, the bot's context compiler — needed **zero code changes** to stop seeing unreviewed,
machine-written text, and cannot regress by a future feature forgetting a `whereNull`. The escape hatch
(`withDrafts()` / `onlyDraftsOf()`, both scope macros) is confined to the composer's own controller and
service — it reads as an alarm at every other call site. The consequence stated explicitly because it
was originally missed (see D8): a draft is a completely ordinary row for every concern that is *not*
"should a reader see this" — a permanent database constraint (D4's `CHECK`), a foreign key, tenancy, and
critically the data-erasure scan all see it unless they too apply the scope. "Nobody can see it" and "it
is not there" are different claims, and the module's second half (D8) is the place that distinction was
paid for.

**D3 — a draft is never indexed; acceptance is what starts its retrieval life.**
`KnowledgeEntryObserver::queueIndexing()` refuses outright while `isDraft()` is true — indexing a draft
would spend real money embedding text nobody has approved, and worse, its chunks would sit in the
vector table where `KnowledgeRetrievalService` (ADR-0045) could reach them, letting a bot quote a
machine-written, unreviewed draft to a customer as fact. Acceptance clears `draft_session_id`, which
fires the model's `updated` event; the observer's own comment states the gotcha this needed a dedicated
branch for: `wasChanged('index_digest')` alone would never trigger, because the digest a draft carries
was computed the moment it was written and never touched again — so the trigger is
`wasChanged(WithoutDraftsScope::COLUMN) && !isDraft()`, "became public", independent of whether the
text itself moved.

**D4 — a SHADOW draft (an amendment) is a draft that targets a real entry, and the invariant that makes
that safe is a database `CHECK` constraint, not only application code.** `targets_entry_id` +
`target_revision_id` (the revision the composer actually saw, frozen at generation time) turn a shadow
into a proposal rather than a duplicate: the composer, shown the base's real entries as context (D6),
returns an `action: "update"` naming the entry it wants to amend instead of writing a near-duplicate.
`target_revision_id` is what makes acceptance safe — it is replayed as the optimistic-lock token
(`expected_revision_id`) when the amendment publishes, so a human who edited the target in the meantime
gets a `409 knowledge_stale_write` instead of a silent overwrite by text a model wrote against a version
that no longer exists. The invariant — **a shadow is always a draft**
(`targets_entry_id IS NOT NULL ⇒ draft_session_id IS NOT NULL`) — is enforced by a Postgres `CHECK`
constraint (`knowledge_entries_shadow_is_draft`), because its breach is the one failure mode that would
be catastrophic and invisible at the same time: a row that escaped it would surface in the base as an
ordinary entry carrying a reserved slug and a pointer at another entry, and nothing short of the
database itself can promise that never happens. A shadow's own slug is synthetic and unmistakable
(`__shadow-<ulid>`, minted by `ShadowSlug` — the leading double underscore cannot be produced by
`Str::slug`), reserved so it can never collide with a real title, is **excluded from de-collision and
from the wikilink rewrite map** (a sibling draft's `[[cennik]]` must resolve to the LIVE `cennik`, never
to a proposal about it), and is never shown — the UI renders the target's own title, because that is
what the proposal is about.

**D5 — a shadow may target only an entry the composer was actually SHOWN; a hallucinated target
degrades to a create, it is never dropped.** The composer is handed a small, FROZEN retrieval set (D6)
as the allow-list for `action: "update"`. A target slug outside that set is not merely refused, it is
**re-interpreted**: `KnowledgeDraftService::launder()` turns the proposal into a plain create rather
than discarding it, because the model naming an address it cannot see has either hallucinated a slug or
been talked into one by the material, and either way the *content* it wrote may still be worth a human's
attention — the one thing that must never happen is silently writing over an entry nobody offered it. A
target already claimed by another proposal in the same run cannot be targeted twice either (the second
would carry a target revision that the first proposal's acceptance would immediately make stale), so
the same degrade-to-create rule applies. Accepting a shadow republishes the amendment through
`KnowledgeEntryService::update()` — appending a revision, re-syncing links, re-queuing indexing — the
exact same path a human's own edit takes; there is no second, AI-flavoured write path with different
guarantees.

**D6 — retrieval context is embedded ONCE per session and FROZEN, never recomputed by a refinement; a
node's `amended_by` is a list, because the graph's edge invariant is total.**
`KnowledgeDraftRetrievalService::retrieve()` embeds the source text a single time (`ai_embedding`
channel) when the session opens, ranks the base's existing chunks against it, groups matches into
entries, and keeps the ones clearing `SimilarityThreshold::for()` — the SAME length-aware bar
`KnowledgeSimilarityLinker` (ADR-0044 D8b) uses for materialized edges, extracted rather than forked so
"related enough to amend" means one thing across the module. The result is stored as `retrieval_set`
jsonb and **never touched again by a refinement** — the composer has to keep judging its proposals
against the evidence it first saw, or a shadow's frozen `target_revision_id` would silently stop meaning
what it meant. `expand-context` (D7) is the one deliberate way to widen it. Separately, in the relations
*preview* (`KnowledgeDraftRelationService`), a shadow draws no node of its own and no edge to its
target — an edge would have an endpoint (the shadow) that the graph's own rendering contract does not
want to draw as a second circle beside the entry it amends. So the pending amendment is recorded as an
**annotation on the target node**, `amended_by: [{draft_id}]` — a *list*, deliberately, even though one
target normally carries at most one pending shadow per session: the graph's edge invariant ("every
edge's endpoints are present in `nodes[]`") is total, and a shape that could not represent two proposals
against one entry (two sessions run in parallel, say) would have to change the day that happens rather
than already accommodating it.

**D7 — `expand-context` is a metered, explicit, once-per-round action; the "already spent" fact is
stamped SERVER-SIDE, not held in a browser tab.** Widening the retrieval set spends one more embedding
call, so it has its own endpoint rather than happening silently inside a refinement — the UI shows its
price before the user asks for it. Before this, "already expanded" existed only as client state, so a
page reload, a second open tab, or a second reviewer looking at the same session was offered the
identical, deterministic retrieval a second time and paying for it bought nothing. `context_expanded_at`
(a session column) fixes that: a second call within the same round throws
`KnowledgeContextAlreadyExpanded` (**422**, `knowledge_context_already_expanded`) for *everyone* looking
at the session, stamped **only after** the retrieval actually returned (a refused or failed expansion
must leave the action available — a budget blip must never lock a session out of it). The flag is
cleared **inside the same guarded `UPDATE` that takes the generation claim** — not as a separate write —
because a revision consumes the widened context (the composer re-derives its whole set against it), so
two clicks racing a refine-and-reopen cannot half-apply the reset. This is what keeps the loop a reviewer
actually wants — widen, revise, widen again — open, while closing the only case that buys nothing:
widening twice in a row against unchanged inputs.

**D8 — RODO purge covers draft sessions and their raw material as their own category, and a symmetry
bug in the first cut is documented as a fixed regression, not a hypothetical.**
`KnowledgeSubjectPurgeService` originally lifted only the soft-delete filter when scanning entries,
leaving a **draft** entry invisible to the scan while its **revisions** (which carry no draft column at
all) were fully visible — the practical result was that applying an erasure request deleted a draft's
history and left its live, name-carrying text standing untouched. The scan now runs through
`withDrafts()` on both the entry and the revision queries (see D2 — this is exactly the "storage, not
UI" consequence that ADR predicted), closing the asymmetry. Beyond the entries a draft leaves behind, a
session's own `source_text` (the raw material a person pasted — a transcript, an email thread, a policy
document) and `prompt_history` (a refinement instruction can itself carry a person's name — "rewrite the
part about Jan Kowalski") are reachable by NOTHING else: the text is never chunked, never indexed, and
purging every entry in the workspace would leave it sitting untouched in `knowledge_draft_sessions`. A
matched session is therefore its own report category (`SubjectSessionMatch`) and, on `--apply`, is
**abandoned whole** through `KnowledgeDraftSessionService::abandon()` — the identical path a user's own
"discard" button takes — because a raw pasted blob has no field-level surgery to perform on it, and
editing around a name would leave drafts derived from the sentences that were removed. `totals.matches`
(the number an operator retypes to confirm an `--apply`) **includes** matched sessions, and
`draft_entries_purged`/`cascaded_session_drafts` are reported as explicit **subsets** of the headline
entry count — an operator is certifying the destruction of two different kinds of thing (published
knowledge someone wrote, and machine output nobody approved), and a single figure would hide the second
inside the first.

**D9 — the composer's spend rides its own channel, `ai_knowledge`, added to `MeteredAiCall`/
`AiTextGenerationService` as an optional, backward-compatible parameter.**
`AiTextGenerationService::generateWith()` grew a fifth, nullable `?string $channel = null` argument,
defaulting to the existing `'ai_text'` when omitted — **byte-preserving** for every caller that predates
this batch (Workflows' `ai_generate` step, the Generator's text blocks). `KnowledgeDraftService` is the
first and, as of this batch, only caller that passes a channel explicitly
(`KnowledgeDraftService::CHANNEL = 'ai_knowledge'`), so a workspace's AI-usage report can attribute
composer spend distinctly from every other text-generation surface rather than folding it into a bucket
that already means something else. Retrieval and the relations preview's embedding batch both spend on
the pre-existing `ai_embedding` channel (the same one indexing and search use) — they are indexing-shaped
work, not drafting-shaped work, and the distinction the new channel draws is specifically about the ONE
call that writes prose.

**D10 — the composer settles EXCLUSIVELY on a realtime broadcast; there is no polling fallback
anywhere.** A composition run takes tens of seconds; polling that is either wastefully frequent or
visibly slow, and the interval is a number nobody can pick well. `KnowledgeDraftSessionUpdated`
broadcasts `{ id, status }` **only** — never the drafts, which is deliberate: the payload rides a
private, per-**workspace** channel (`knowledge.workspace.{workspaceId}`, authorized by plain workspace
membership) that fans out to every composer open in that workspace, and the drafts are the user's own
material turned into text — the client already has an authenticated, workspace-scoped REST endpoint to
fetch them through, and that is where the real authorization decision belongs, not on a broadcast
payload. This mirrors the standing project requirement already enforced for the Generator's own session
runs (`useSessionSettle.ts`'s own module comment): "generation must settle on the websocket event, not a
poll loop." `useComposeSettle.ts` is a deliberate 1:1 clone of that composable against this module's own
channel/event/store rather than a shared abstraction — the two differ in three concrete facts (channel,
event, store) and coupling two modules' realtime paths behind one generalized helper was judged a worse
trade than the ~60 lines the clone costs; a third caller is the trigger to extract one.

**"Exclusively" required closing a real gap in `GenerateKnowledgeDraftsJob::releaseSession()`, since
patched.** The job's own second settle path — reached when the kill switch was flipped after a run was
already queued, or when the job dies on its own timeout — wrote the session's terminal `failed` status
but, in an earlier revision of this batch, did **not** dispatch `KnowledgeDraftSessionUpdated`. That left
the composer waiting on an event that would never arrive on exactly the two failure modes least likely
to be noticed in ordinary testing (a mid-flight kill-switch flip; a 300-second job timeout), which is the
kind of gap "exclusively" cannot afford to have. `releaseSession()` now dispatches the same event the
service's own `settle()` does on every path that writes `FAILED`, so the invariant this D10 states — a
transition is never persisted without the push — holds identically whether the run finished normally,
failed inside `KnowledgeDraftService::generate()`, or was released by the job wrapper. Verified in
`app/modules/Knowledge/Jobs/GenerateKnowledgeDraftsJob.php`.

**D11 — AI-only is a POLICY the frontend enforces, not an invariant the server enforces; the write
contract ADR-0043 shipped is unchanged and is what the composer itself calls.**
`POST /knowledge/bases/{base}/entries` still exists, is still reachable, and is still exactly the
endpoint ADR-0043 documented — because the composer's own acceptance path calls it (a plain create
publishes a draft by clearing `draft_session_id`, which is a save through the same `KnowledgeEntryService`
a direct `POST` would also reach). "AI-only" describes what the ten-odd "new entry" entry points in the
UI now route to, not a new server-side gate — a script, a future consumer, or a test fixture may still
create an entry directly, and nothing in this batch makes that a mistake. The one place a red
(unresolved) wikilink's slug is enforced server-side rather than merely suggested by the UI: opening the
composer from a ghost link carries `seed_slug` (+ optionally `seed_title`), and
`KnowledgeDraftService::enforceSeed()` guarantees the run either produces an entry at exactly that slug
or the whole session fails with `seed_missed` — a model that ignored the requirement does not get to
hand back the wrong address silently.

## Alternatives considered

- **A separate `knowledge_draft_entries` table.** Rejected — see D1; it would re-earn revisions, slug
  uniqueness, metadata validation and the directive guard from scratch, and turn "accept" into a copy
  instead of a single column write.
- **A per-query `whereNull('draft_session_id')` convention instead of a global scope.** Rejected — cheap
  to write and, per D2's own history, wrong the first time a feature is added without knowing drafts
  exist. A global scope pays the cost once, in the one place it cannot be forgotten.
- **Blocking acceptance of a shadow whose target has moved, rather than reporting a per-draft
  conflict.** Rejected in favour of the design ADR-0043's own optimistic-lock convention already
  implies: `POST …/accept` is per-session but PARTIAL — one stale shadow is reported as a conflict
  alongside every draft that DID publish, rather than rolling the whole accepted batch back and telling
  a reviewer none of their five approvals happened because one target moved elsewhere.
- **Letting a hallucinated amendment target silently disappear.** Rejected — see D5; the content a model
  wrote may still be worth a human's attention, and turning it into a create rather than discarding it
  is the one choice that never destroys work while never risking an unauthorized overwrite.
- **Polling for session completion.** Rejected outright by the standing project convention (D10), not
  merely by preference for this batch.
- **A shared, parameterized realtime-settle composable instead of cloning `useSessionSettle.ts`.**
  Deferred — see D10; three concrete differences (channel, event, store) made a generalized abstraction
  harder to read than the clone, and coupling the two modules' realtime paths was judged the worse trade
  until a third caller appears.

## Consequences

- **The composer's per-operation cost is never estimated on the wire.** `compose-availability` and every
  session response carry the workspace's *budget state* (`cost_used`/`cost_cap`/`warn_reached`/
  `period.resets_at`) but never a "this run will cost approximately $X" figure — no service in this
  batch computes one before a run starts, because a language model's own output length is not knowable
  in advance. The frontend's own source-form component states this explicitly rather than inventing a
  number: "a made-up number is worse than no number." A per-operation estimate is a real, open follow-up
  candidate, not a decision this batch made against it.
- **A target entry with more than one pending shadow against it is representable on the wire
  (`amended_by` is a list) but only partially addressable from the UI.** The relations preview's
  "jump to the amendment" affordance reads `amended_by[0]` — the first proposal only. Two sessions
  proposing amendments to the same entry at once is rare (each session's own de-collision already
  prevents it happening TWICE inside one run) but not impossible, and the day it is common enough to
  matter, the UI needs a real multi-amendment affordance; the wire shape already supports it.
- **Relation detection inside the composer (D6) is deterministic-plus-vector, never LLM-judged.** The
  preview's edges are wikilinks (parsed), mentions (title string-matching), and cosine similarity over
  embeddings — the same three mechanisms ADR-0044 already ships for the live graph. Nothing in this
  batch asks a model "are these two passages saying the same thing" as a reasoning step; a
  language-model-graded relation judgment is out of scope here and would belong to the Etap 2 knowledge
  consumption work the product roadmap already earmarks (`docs/product/plan-dzialania.md`).
- **`knowledge:reap-draft-sessions` was initially implemented and tested but NOT wired into the
  scheduler — an operational gap found during the final documentation pass and CLOSED before the
  chapter shipped.** It is now registered in `routes/console.php` as `daily()->withoutOverlapping()`
  (daily rather than its siblings' five-minute cadence: those reapers *recover* stranded claims where
  minutes of delay are user-visible, while this one *deletes* on a fourteen-day window —
  `knowledge.drafting.abandon_after_days` — so the closest analogue is `disk:prune-temp-files`, one
  step further out). The gap's class — "documented retention window with no schedule line", dangerous
  precisely because the data it fails to delete is invisible by design — is pinned by
  `ScheduledMaintenanceCommandsTest`, which DERIVES the required set from registered `App\Modules\`
  commands named `reap`/`sweep`/`prune` instead of enumerating them, so a future reaper is covered the
  moment it is named like one. `knowledge:purge-subject` stays unscheduled by the same naming rule,
  deliberately: an irreversible erasure command must never run unattended.
- **The composer's ONLY entry point into an existing base's material is a session the user manually
  opens by pasting text.** Nothing in this batch proposes an amendment on its own initiative — from a
  bot's completed task, a workflow run, or any other module's activity. Every proposal starts from a
  human deciding to paste something into the composer. An autonomously-triggered "the platform noticed
  something and suggests an update" surface is a materially different, larger feature and is explicitly
  future work, not an oversight of this batch.
- **The own-database (own-DB) tenant SCHEMA matrix has been extended for this batch's new tables; a
  tenant-mode RUNTIME smoke test has not.** `KnowledgeTenantSchemaTest` (the real-DDL check that a
  fresh tenant database provisions the module's schema correctly, guarded by `TENANT_DB_TESTS=1`) now
  asserts `knowledge_draft_sessions` (alongside the pre-existing tables), the entry table's three
  composer columns (`draft_session_id`, `targets_entry_id`, `target_revision_id`),
  `knowledge_draft_sessions.context_expanded_at`, and the `knowledge_entries_shadow_is_draft` `CHECK`
  constraint — closing the schema-level half of what was originally a broader gap. What remains open:
  **no tenant-mode test actually RUNS `GenerateKnowledgeDraftsJob` or
  `ReapAbandonedDraftSessionsCommand` against a provisioned own-database tenant** — both call
  `TenantManager::configure()` exactly as their siblings do, but that call path is today verified only
  by reading the code and by the schema existing, not by a real composition run or a real reap sweep
  executing against an own-database connection. Closing it is a small, well-scoped follow-up (one
  tenant-mode smoke test per job/command) rather than a redesign.
- **A code-comment inconsistency, not a behavioural gap, was found and is left for a future cleanup.**
  Three files (`KnowledgeComposeView.vue`, `KnowledgeDraftRelationsPanel.vue`,
  `pages/knowledge/types.ts`) carry a header/inline comment stating the amendment surface — the
  `amended_by` annotation, the third diff baseline, the 409 rebase flow — is "not here yet" / "B15b, not
  B15a". The code in the same files fully implements all three (verified above, D4/D6/D7 and the diff
  panel's `target` baseline). The comments predate the batch that finished the feature and were not
  updated; documentation in this ADR and in `docs/backend/knowledge-api.md` /
  `resources/js/next/docs/pages/KnowledgePage.vue` describes the ACTUAL, shipped behaviour. Updating the
  stale comments themselves is a trivial, safe follow-up, deliberately left untouched by this
  documentation batch (which does not modify code).
