# ADR-0048 — Subject paradigm and the live graph

**Date:** 2026-08-05
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`Agents\KnowledgeDraftAgent`, `Services\KnowledgeDraftService`,
`Services\KnowledgeDraftSessionService::publishAmendment()`, `Services\KnowledgeGraphOpsApplier`,
`Services\KnowledgeRelationService`, `Enums\KnowledgeRelationType` (5 new cases),
`Support\RelationVocabulary::checkProperties()`), config `knowledge.drafting.max_entries_per_session`/
`max_new_entities`, `knowledge.relations.max_relations_per_entry`, `lang/{pl,en}/knowledge.php`
(`relation_types`/`relation_types_inverse`, 5 new keys), `tests/Feature/KnowledgeSubjectParadigmTest.php`
**Relates to:** ADR-0046 (the composer this batch rewrites the doctrine of), ADR-0047 (the relation
storage, audit trail and matrix this batch is the first to actually exercise at scale)

---

## Context

The composer (ADR-0046/0047) was structurally complete — sessions, drafts, typed relations, a review
gate — and produced nothing usable on real material. Pasting a chronicle about a trip ("an influencer
visited Paris 12–15 July, saw the Eiffel Tower, announced a contest Łukasz Barszcz won, flew to
Thailand, an incident drew criticism, then reconciled over sushi in Tokyo") returned four entries titled
"Pobyt w Paryżu", "Podróż do Tajlandii", "Konkurs na wyjazd", "Spotkanie na sushi" — narrative episodes,
not the people and places the material was about. Every entry's `entry_type` was `NULL`. The graph had
zero relations, on every session, including ones run after the relation infrastructure had shipped.

Two independent causes produced the empty graph, and both are recorded here because the first is an
operational lesson worth keeping, not only a bug that got fixed.

**Cause 1 — a stale queue worker.** `php artisan queue:work` boots once and keeps the booted code in
memory for the life of the process; it does not reload after a file changes. A worker that had been
running for sixteen hours was still executing a pre-graph snapshot of the module from before
`KnowledgeRelation`, `graph_ops`, and the relation-aware `KnowledgeDraftService` existed. The proof was a
reflection dump requested from inside the LIVE worker process: its in-memory `KnowledgeDraftService`
constructor took 3 parameters where the file on disk takes 5 (`$notes`, `$ops` — the very parameters
`graph_ops` is written through — were simply not there), its `KnowledgeDraftAgent` instructions were
5439 characters with zero occurrences of `graph_updates`, and its class was 861 lines against 1338 on
disk. Under the code actually on disk, a session reaching `ready` with `graph_ops IS NULL` is
IMPOSSIBLE — the column is written unconditionally, in the same `forceFill` as the terminal status, at
the single place in the whole module that sets a session to `READY`. Every `NULL` on record was
therefore proof, not a symptom to interpret: the process producing it could not have been running the
code in the tree. **Decision: `graph_ops` stays a plain nullable `jsonb` column with no `DEFAULT`,
deliberately.** A default would have let a stale-worker session settle into a plausible-looking empty
structure and hidden exactly the signal that broke this open — `NULL` is the cheapest stale-worker
detector this module has, and giving the column a default would delete it. See `CLAUDE.md` for the
operational writeup (`queue:restart` after every module edit).

**Cause 2 — a cold-start deadlock, independent of the worker and NOT fixed by restarting it.** The
entire entity/relation contract in the composer's instructions lived under a single conditional heading,
"WHEN THE REQUEST CARRIES A 'KNOWN ENTITIES' BLOCK" — and that block was rendered into the prompt only
when the entity-resolution pass had matched at least one existing entry (`resolution_set['entities']`
non-empty). On an empty base — every base, the first time anyone used it — that list is always empty, so
the block never rendered, so the model was never told the graph contract existed, so it returned only
`entries[]`, so the base stayed without entities, so the block never rendered on the NEXT run either. A
closed loop with no way in: **a base could not bootstrap its own graph no matter how many sessions were
run against it**, and this explains `entry_type = NULL` on every entry too — `type` existed only inside
that same gated block, never in the plain `entries[]` shape the cold-start path actually used.

## Decisions

**D1 — an entry names a SUBJECT, never an episode; the doctrine is stated as a rule, a test, and a
worked counter-example, not as a single sentence.** The prior instruction ("one entry answers one
question, like an encyclopedia article") was true and insufficient: a narrative read through that lens
splits cleanly into episodes, and an episode-as-entry is internally coherent and still worthless a year
later — nobody searches for "Podróż do Tajlandii" and nothing links to it twice. The composer's
instructions now open with an explicit rule ("BEFORE YOU DIVIDE ANYTHING, list every thing the material
is ABOUT that goes on existing after it… THOSE NAMES ARE YOUR ENTRIES"), a mechanical test ("if the
title would mean nothing to somebody who has not read this material, it is not an entry" — the ONE-YEAR
TEST), and a full worked example naming the WRONG titles for the influencer material alongside the RIGHT
ones, because a single positive rule under-determines the boundary and a prohibition needs a concrete
shape to refuse. A subject the material names and says something about gets its own entry however little
material there is about it — stated as an explicit override of the pre-existing "write fewer entries
rather than padding them" rule, which is about thin prose and was otherwise starving exactly the
short-but-real subjects (a city visited for one paragraph) this doctrine exists to keep.

**D2 — `event` is allowed for an entry ONLY when it carries a name of its own that would still be
searched for later; a self-check treats more than one per answer as a doctrine violation — and the case
was deliberately not removed outright.** A relation is strictly binary (`from_entry_id`/`to_entry_id`
both `NOT NULL`, no reification — see ADR-0047) with scalar-only properties, so a fact with three or more
participants and its own identity (a named contest: an organiser, a winner, a prize, a date people will
ask about again) LOSES two of its participants the moment it is forced into an edge. The instruction
therefore keeps `event` as a legal `type`, restricted to named things with their own identity — never a
trip, a visit, a stay, a meeting, or "the scandal" — and states the check a writer applies to their own
answer: "if more than ONE entry in your answer is typed 'event', you have divided the material into a
story." **This self-check exists ONLY in the prompt.** No server-side note or warning fires when a run
returns more than one `event` entry (`DraftRunNotes` carries no such code) — the boundary between "a
named contest" and "an episode wearing an event badge" is enforced entirely by the model's own reading
of the instruction, with a human reviewer as the only backstop. See "Known limitations."

**D3 — ONE channel creates an entry, not two; the model no longer declares `entities[]`, and the server
synthesizes it from the drafts it already laundered.** Before this batch, `entries[]` (drafts, reviewed,
diffed, no type) and `entities[]` (`N<n>` declarations feeding
`KnowledgeGraphOpsApplier::createDeclaredEntities()`, real entries with a type, no review card) were two
independent paths to a new row, invisible to each other. As long as the composer never populated
`entities[]` this cost nothing; the moment the subject doctrine asks the model to name "Paryż" so it can
both write the entry AND relate it to things, a model naming it in both sections produces TWO entries —
silently, because `mintSlug()` de-collides the second to `paryz-2` without complaint, and a reviewer
refusing the duplicate CARD is still left with the duplicate ROW created through the other channel.
Resolution: the model's JSON contract drops `entities[]` entirely ("Do NOT return an `entities` section:
your entries ARE the entities and their `ref` is their handle" — enforced by omission from the model
contract, not by a server-side refusal of the key, since the key is never read from the model's reply at
all). `entries[]` gains a REQUIRED `ref` (`N1`, `N2`, …) on every `create`, used to address that entry as
either end of a `graph_updates` operation in the SAME answer. After laundering, slug de-collision and any
seed rename have all run — so the slug is FINAL — `KnowledgeDraftService::withDeclaredEntities()`
synthesizes `graph_ops.entities[]` itself: one item per `create` draft that claimed a `ref`, each carrying
`from_draft_slug` set to that draft's own final slug. This is the SAME source of truth the relation matrix
judges against (the entry's real, stored `entry_type`), and it is what makes D4 possible.

**D4 — the applier BINDS an already-published draft instead of creating a second entry, keyed on a
marker only the server may write.** `KnowledgeGraphOpsApplier::createDeclaredEntities()` now checks
`from_draft_slug` on each synthesized entity: when present, it looks up a LIVE, published entry at that
exact slug (`draft_session_id IS NULL`, not a shadow) and returns it — never creating a row — because
`accept()` publishes the reviewer's selected drafts BEFORE applying the graph half, so the entry named by
`from_draft_slug` already exists by the time this runs. Not found means the reviewer did not accept that
particular draft; the handle resolves to nothing and every relation naming it is reported honestly as
`dependency_not_accepted` — a code that already existed and fits exactly. **`from_draft_slug` is
whitelisted through laundering (`is_string` only, no further validation) precisely because it can never
arrive from the model** — `entities[]` is discarded wholesale from the parsed reply and replaced by the
server's own synthesis before laundering ever runs, so there is no position in the pipeline where a model
could name a marker pointing at somebody else's draft. The path that creates an entity FROM SCRATCH still
exists, for entities carrying no `from_draft_slug` — the backward-compatible fallback for sessions frozen
before this batch (see "Known limitations").

**D5 — the entity-resolution pass's `unresolved[]` reaches the prompt; it used to be paid for and
discarded.** Entity resolution (a metered, real AI call — `ai_knowledge_resolve`) runs before composition
and produces, among other things, a list of things the material names that the base does not have YET,
each with a guessed `kind`. Nothing read this list: `prompt()` used only `entities` (things the base
already has) and `ambiguous`. A composer facing an empty base was therefore asked to identify "what is
this text about" from prose it was reading for the first time, having already paid to have that exact
question answered by an earlier pass. `KnowledgeDraftService::namedSubjects()` now renders it as its own
prompt block ("THINGS THIS MATERIAL NAMES that the base does not have yet, from an earlier reading pass…
Every one the material actually says something about gets its OWN entry") — no new AI call, the single
cheapest quality lever available.

**D6 — a newly created relation is stamped `ended`, not `active`, when its own `valid_to` already lies in
the past; the duplicate rule is split on whether the assertion carries a date.** `create()` used to stamp
every relation `active` unconditionally — harmless while relations described standing states nobody
backdated, and actively wrong the moment the composer began recording closed episodes: "visited Paris,
12–15 July" written as `active` asserts, in the present tense and forever, that the subject IS in Paris
(and in Bangkok, and in Tokyo, for every episode the same base records). `scopeCurrent()` filters on
`state`, never on dates, so the state has to carry the answer — `KnowledgeRelationService::stateFor()`
now derives it from `valid_to` at creation. The identical mechanism closes the per-entry relation cap's
biggest cost: a closed episode no longer competes for the budget (see D8). This in turn split the
duplicate guard on the presence of a date: a DATED assertion is compared against every relation of the
same pair/verb/date in ANY state, because the date IS its identity and a born-`ended` episode would
otherwise stop being a duplicate of itself the instant it was written — re-running identical material
would stack a fresh copy of every episode on every pass. An UNDATED assertion is compared only against
`active` rows, because nothing else tells two undated statements apart, and "she left the company, then
rejoined" has to stay expressible as two facts rather than one refused as a duplicate of a stale one.

**D7 — the type matrix is published inside the composer's own prompt, not just on the API.** The matrix
(ADR-0047) had been ADVISORY-BY-ACCIDENT rather than advisory-by-design: since the composer never set
`entry_type` on anything it wrote, every composed relation had at least one untyped end, `checkTypes()`
always returned `UNKNOWN_TYPES`, and NOTHING the matrix could refuse was ever actually checked. D3 fixes
that — entries now carry a real type — which makes the matrix's dormancy end at the same moment: a
composed relation can now be genuinely `REFUSED`. Shipping D3 without also showing the matrix to the
model would have made the fix a regression: types would start existing, the matrix would start refusing,
and the writer producing the relations had never been told which pairs were legal — a probe against a
semantically reasonable sentence (`participated_in` from a `person` to a `place`) returned
`{"code":"pair_refused"}` under exactly that condition. The instructions now embed the full pair table for
every verb, inline, as part of "WHAT A RELATION MAY SAY."

## Ontology

**Five new verbs, all added because a concrete sentence in ordinary material had no verb that fit:**
`visited` (a TEMPORARY stay — "she spent three days in Paris" — kept apart from `located_in`, which
means permanently situated: a tower in a city, a city in a country; before `visited` existed, the only
available verb for a stay was `located_in`, and dated it produced a graph asserting the subject IS,
right now, wherever they ever briefly were), `organized` (`created` does not accept `event` as a target,
so "announced a contest" had no verb at all), `won` (previously representable only as
`participated_in` + `properties.role = "winner"`, a fact buried in unindexable free text), `interacted_with`
(DIRECTED, and the most structural gap of the five: between two `person` entries exactly two verbs
existed, `knows` and `opposes`, and BOTH are symmetric — an apology, a criticism, a thanks, any act with
an author and a target, had no way to record its direction at all), and `related_to` (the closed
vocabulary's own fallback — before it, a fact matching no verb was DISCARDED entirely by instruction, on
the reasoning that approximating with a neighbouring verb corrupts the base's claims; `related_to` keeps
the fact, weakly, in `description`, on the position that a findable weak edge beats a silently lost fact).
**`located_in.fromTypes()` no longer accepts `PERSON`** — narrowed specifically because `visited` now
exists, to close off having two competing conventions for the identical fact in one base.

**Two universal property keys, `sentiment` and `confidence`, are now accepted by every verb** —
`RelationVocabulary::checkProperties()` validates them by VALUE (`positive`/`negative`/`neutral`/`mixed`;
`low`/`medium`/`high`), not only by key name, unlike every other property key, which stays free text. The
distinction is deliberate: `sentiment`/`confidence` exist specifically to be FILTERED ON ("show me what
went wrong around this person"), and a column meant for filtering is worth exactly as much as its value
set is disciplined — a free-text sentiment would reproduce, one property key at a time, the exact drift
("works on"/"pracuje nad"/"assigned to" as four unrelated edges) the closed relation vocabulary exists to
prevent. **Explicitly NOT folded into `state`** — `active`/`ended`/`retracted` answers "do we still
assert this," a different question from "how did it go," and conflating them would break
`scopeCurrent()`, the historical filter, and the audit trail in one move.

## Defects found and fixed along the way

**(h) `publishAmendment()` built its DTO without `entry_type`, and `KnowledgeEntryService::update()`
writes the column from the DTO unconditionally — so every ACCEPTED amendment silently untyped its
target.** Invisible for as long as nothing was ever typed (the composer's own drafts had no type before
this batch, so there was nothing to lose), and would have re-broken the matrix one entry at a time the
moment K1/D3 shipped without this fix: a person entry typed `person`, appended to once, would come back
`entry_type = NULL` — quietly re-opening exactly the dormancy D7 exists to close. Fixed by carrying
`entryType: $target->entry_type` (the TARGET's existing, live type, never the shadow draft's own — a
shadow always carries `entryType: null`, which is correct for the shadow row itself and would be wrong
if passed straight through to the publish DTO) into `publishAmendment()`'s `KnowledgeEntryDTO`.

**(i) `enforceSeed()` can RENAME a draft's slug, and the entity-handle synthesis used to run before that
rename could happen.** A seeded session (opened from a red wikilink) requires its one draft to land at an
exact, pre-named slug; `enforceSeed()` is what enforces that, by renaming the draft if the model proposed
a different one. Minting `graph_ops.entities[].from_draft_slug` before this rename would stamp every
handle with a slug that, by the time acceptance runs, no draft carries any more — every relation touching
that entity would then report a missing dependency that reads exactly like the reviewer's own refusal.
Fixed by ordering `generate()` so the seed rename runs BEFORE `withDeclaredEntities()` mints the binding
markers, with the ordering stated explicitly in code as load-bearing rather than left to be discovered by
whoever reorders these calls next.

## Known limitations

- **The type matrix goes from checking nothing to checking real writes for the first time — expect a
  wave of `pair_refused` on the first sessions run against it.** Every base's relations were previously
  untyped by construction, so the matrix (ADR-0047 D4) never actually refused anything; D7 is what makes
  it live. Watch `graph_ops.rejected[]` on early runs rather than assuming a refusal is a prompt bug.
- **`interacted_with.act` and `related_to` are the two verbs most exposed to "bag" drift.** `act` is a
  short free word (`apologised`, `criticised`, `supported`, `thanked`, …) constrained only by the
  prompt's own suggested list, not by a closed enum in code — the same failure mode the closed relation
  vocabulary exists to prevent, reopened at the property level because the set of human acts is not
  closed the way the set of relation TYPES is. `related_to` is the vocabulary's own escape hatch and
  carries no constraint on its `description` at all. Review the actual distribution of `act` values and
  `related_to` usage after real sessions accumulate (the plan that shaped this batch suggested roughly
  20) before deciding whether either needs a closed list.
- **A response now asks for up to 16 entries plus their relations in one answer — longer and costlier
  than the previous 8-entry ceiling.** The composer's spend already rides its own metered channel
  (`ai_knowledge`, ADR-0046 D9) under the workspace's own cap; watch `ai_usage_events` on early runs
  rather than assuming a cost regression is unrelated to this batch.
- **Sessions frozen before this batch have no `from_draft_slug` on their synthesized entities and take
  the CREATE-FROM-SCRATCH path in `createDeclaredEntities()` — this is deliberate backward compatibility,
  not a gap to close.** A pre-batch session's `graph_ops` was built under the old, two-channel contract;
  forcing it through the new bind-only path would simply fail to find a draft that was never marked with
  the new marker. Left to self-heal the same way other session-shaped compatibility gaps in this module
  do — `knowledge:reap-draft-sessions`'s ordinary retention window.
- **The `event`-entity self-check (D2) has no server-side backstop.** An earlier draft of this batch's
  plan proposed a `notes` warning when a run's answer types more than one entry `event`, pinned by a
  dedicated test; neither shipped. The boundary between "a named contest" and "an episode wearing an
  event badge" is enforced by the model reading the instruction and, ultimately, by a human reviewer —
  there is no code-level guard if a future run drifts back toward narrative `event` entries. Revisit if
  the reviewed distribution of `entry_type: event` across sessions suggests the prompt alone is not
  holding the line.
- **The entities cap (`max_new_entities`, 16) and the drafts cap (`max_entries_per_session`, 16) are now
  set equal on purpose, but nothing enforces that they STAY equal.** Since entities are synthesized 1:1
  from `create` drafts (D3), `max_new_entities` can only ever bind first if it is configured LOWER than
  `max_entries_per_session` — a mismatch introduced later (by an operator changing one env var and not
  the other) would silently reintroduce the exact overflow-without-explanation failure mode `op_cap_reached`
  was added to report. No validation ties the two together today.
- **The existing five episode-titled entries from the pre-fix testing base were not migrated.** They live
  in soft-deleted synthetic test bases; the recommendation is a fresh session on the same source material
  rather than a rewrite migration, because the migration's cost (splitting narrative prose into subjects,
  assigning dates, inferring relations) exceeds the cost of one more composition run, and the result of a
  fresh run is grounded in the new contract rather than in a guess about the old prose's intent. No
  decision has been made yet about whether narrative-titled entries in a REAL (non-synthetic) base should
  ever be proactively retyped — that would be its own, larger piece of work (a classification pass plus a
  review queue), explicitly out of scope here.

## Alternatives considered

- **Server-side detection and rejection of episode-shaped titles (regex/heuristic on the title).**
  Rejected — no reliable signal distinguishes "Podróż do Tajlandii" from a legitimately-named subject by
  string shape alone; the fix has to live in what the model is asked to produce, not in a filter on what
  it returns.
- **Keeping `entities[]` as a second, model-declared channel and reconciling duplicates after the fact
  (e.g. a dedup pass matching slugs).** Rejected — reconciliation is strictly harder than prevention: a
  dedup pass has to guess which of two entries for "Paryż" is canonical, which relations to repoint, and
  what to do with a reviewer who already approved one card and refused the other. One channel removes the
  question rather than answering it.
- **Removing `event` as a legal entry type entirely, folding named events into `interacted_with{act}` on
  the people involved.** Rejected (see D2) — a relation is strictly binary with scalar properties, so an
  event with an organiser, a winner and a prize loses at least one of those three the moment it is forced
  onto an edge instead of a node; the narrower "named things only" rule was judged the better trade than
  losing arity entirely.
- **A hard server-side cap of one `event` entry per answer, refusing the rest.** Rejected in favour of a
  prompt-level self-check plus human review — refusing outright would destroy a legitimately second named
  event in one answer (two separate named conferences in one document, say) rather than merely flagging
  the common case (a story cut into fake events). Left as a documented gap rather than a false-confident
  guard; see "Known limitations."
