# Backend API: Knowledge module

Module: `app/modules/Knowledge/`
Auth: every endpoint requires `auth:sanctum` **and** the `X-Workspace-Id` header
(`RequireWorkspace`) — a base is workspace-owned, and `ResolveWorkspace` deliberately no-ops
when the header is absent, which would leave `WorkspaceScope` inert and let an authenticated user
reach another workspace's base/entry by id. The middleware ORDER (`ResolveWorkspace` →
`RequireWorkspace` → `SubstituteBindings`) is what makes a FOREIGN id 404 at bind rather than in a
policy, pinned app-wide by `ApiMiddlewarePriorityTest` (see `docs/backend/disk-api.md`'s identical
note — the same invariant, every module).
Tenant scope: `KnowledgeBase`, `KnowledgeEntry`, `KnowledgeEntryRevision`, `KnowledgeLink`,
`KnowledgeEntryChunk`, `KnowledgeBinding`, `KnowledgeRelation`, `KnowledgeRelationEvent` all use
`TenantAware`.

A **low-layer module**, deliberately placed on the same tier as Variables: it may depend on
`App\Modules\Variables` and `App\Support`, and imports **nothing** from the modules that consume it
(Bot today; Generator/Workflows are the documented next consumers — see
`docs/product/plan-dzialania.md`). Pinned literally by `KnowledgeModuleBoundaryTest`, which scans
every PHP file under the module root — comments and docblocks included. The design rationale lives
in [`docs/decisions/ADR-0043-knowledge-module-design.md`](../decisions/ADR-0043-knowledge-module-design.md)
(bases, entries, wikilinks, the write-side contract),
[`docs/decisions/ADR-0044-knowledge-index.md`](../decisions/ADR-0044-knowledge-index.md) (chunking,
differential embedding, hybrid search),
[`docs/decisions/ADR-0045-knowledge-consumption-data-erasure.md`](../decisions/ADR-0045-knowledge-consumption-data-erasure.md)
(the Bot → Knowledge read edge and the subject-erasure command),
[`docs/decisions/ADR-0046-knowledge-ai-composer.md`](../decisions/ADR-0046-knowledge-ai-composer.md)
(draft sessions, shadow amendments), and
[`docs/decisions/ADR-0047-knowledge-typed-relations.md`](../decisions/ADR-0047-knowledge-typed-relations.md)
(typed relations — the Wiki-Graf, "Typed relations" below),
[`docs/decisions/ADR-0048-knowledge-subject-paradigm.md`](../decisions/ADR-0048-knowledge-subject-paradigm.md)
(the subject paradigm — 20-verb vocabulary, universal sentiment/confidence properties, the live graph),
and
[`docs/decisions/ADR-0049-knowledge-authorship-withdrawn.md`](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)
(the owner's AI-only pivot — "This module is read-only for humans," directly below).

---

## This module is read-only for humans

**A person may approve, refuse, and redirect. A person may not author.** An entry or a relation enters a
base one way: the AI composer proposes it, and a person accepts, rejects, or asks again in different
words. There is no route, FormRequest, service method or policy ability left that lets a person type
directly into an entry's title or body, trash or restore an entry one at a time, reorder a base, or
assert/edit/end/retract/delete a relation. Full rationale, the three replacement abilities, and the rules
that lost coverage along the way: [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md).

**What remains, exactly:**

- Every READ endpoint — lists, a single entry, revisions (view-only), relations, search, the graph.
- The **entire AI composer surface** — open/refine/rebase/expand-context/accept/abandon a session, and
  reject one draft (`DELETE .../entries/{entry}/draft`) — see "The AI composer" below.
- `POST .../entries/{entry}/retry-index` — re-queues a failed indexing run; authors nothing, changes no
  text.
- Link `POST`/`DELETE .../links/{link}/dismiss` — rejecting a MACHINE suggestion is refusal, not
  authorship; the suggestion was never a person's own assertion.
- The **whole base CRUD surface, including `PATCH` (the charter)** — a base is not an entry, and the
  charter has to stay hand-editable: `knowledge:purge-subject`'s report-only charter category
  (`docs/backend/knowledge-api.md` → "Data erasure" below) exists specifically to send an operator to
  edit it by hand.

**What is withdrawn:** entry `POST`/`PATCH`/`DELETE`/restore/force/reorder; every relation mutation
(`POST`/`PATCH`/`end`/`DELETE`); revision `restore`. See "Endpoints" below — each withdrawn row is marked
as such rather than deleted from this document outright, so a reader who remembers the old contract can
see what replaced it.

**Two barriers, not one.** The route is gone (a request 404s/405s before any controller runs), **and**
`KnowledgeEntryPolicy`/`KnowledgeRelationPolicy` answer `false` in writing for every authoring ability —
kept as methods rather than deleted, because a policy with no method for an ability is a gate
FALLTHROUGH, not a refusal. Three abilities were split out from the now-denied `create`/`update`/`delete`
so the composer itself did not go down with hand-authorship — `compose`, `retryIndex`, `rejectDraft` — see
ADR-0049 D4 and the Authorization table below.

**Consequence worth stating plainly: a typo in a generated entry can only be fixed by another AI call,
never by hand-editing.** The nearest a reviewer gets to a correction is asking the composer to refine or
re-amend the entry and accepting the new proposal.

---

## Concepts

### A knowledge base is a hard container, not a folder

A **base** (`KnowledgeBase`) is a workspace-scoped container with two things that make it more than
a grouping label: a **charter** (free prose stating what the base is for, its tone, its scope — max
10 000 chars) and a **metadata schema** (an ordered list of `{key, label, descriptor}`, where
`descriptor` is the exact type-descriptor shape the shared Variables type system validates a
constant's value against — see `KnowledgeMetadataValidator`). An entry cannot exist outside a base.
Tag-based cross-base faceting was considered and deliberately deferred (ADR-0043).

### An entry is one durable topic, addressed by a stable slug

A **entry** (`KnowledgeEntry`) belongs to exactly one base: `title`, plain-authored `content`
(≤ 40 000 chars, capped by `knowledge.entry_max_chars`), a typed `metadata` map validated against
the base's schema, an editorial **status**, an orthogonal **`stale_at`** review flag, and a manual
**`position`** within the base.

The **slug** is minted ONCE from the entry's first title and never follows a rename — `[[wikilinks]]`
address entries by slug, and a slug that tracked the title would break every inbound link the moment
someone fixed a typo in a heading. Changing the slug is a deliberate, explicit act (the `slug` field
on an update, normalized through the same function a wikilink target goes through) — never a side
effect of retitling.

### Editorial status (4 values) is orthogonal to `stale_at`

`KnowledgeEntryStatus`: `draft` (being written) → `proposed` (awaiting a human blessing) →
`approved` (the workspace stands behind it) → `archived` (kept for history, deliberately no longer
current). B1 stores and filters the status but gates nothing on it directly — different **consumers**
decide which statuses they read (the human search reads everything but `archived` by default; an AI
consumer reads `approved` only — see below).

`stale_at` is a **separate, orthogonal** timestamp — a review-due flag, not a fifth status. An
`approved` entry can be stale (still today's best answer, but due for a look); a `draft` can be
perfectly current. `is_stale` on the wire is `stale_at !== null && stale_at.isPast()`.

### Revisions are append-only

Every save that changes the AUTHORED state (title/content/metadata — decided by comparing the
entry's index digest, never by a naive field diff, so a metadata map that merely round-tripped with
its keys reordered does not manufacture a revision) appends a `KnowledgeEntryRevision`. There is no
`updated_at` on a revision row — a revision that could be edited is not an audit trail.
`current_revision_id` on the entry doubles as the **optimistic-lock token** (see `expected_revision_id`
below).

**Restoring an old revision is withdrawn** (the AI-only authoring pivot — see
[ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)). History stays readable
(`GET .../revisions`), but nothing republishes an old body onto the current entry any more:
`KnowledgeEntryService::restoreRevision()` no longer exists as a method, not merely as a route, so
there is no caller anywhere that could perform it. The nearest surviving path is the composer — ask it
to reproduce the earlier text, then accept that proposal, which is authorship going through review
again, not a rollback.

### Wikilinks and ghosts

`[[slug]]` or `[[slug|human label]]` in an entry's content is parsed into a `KnowledgeLink` row
(`source: wikilink`) pointing at the resolved target, or — when nothing with that slug exists yet —
a **ghost**: the edge is stored with `to_entry_id: null` and its `target_slug` intact. A save
DELETES and RE-INSERTS an entry's whole wikilink set (never a diff — the content is the sole
authority, and diffing five rows buys a class of drift bugs for no benefit). The moment an entry with
a matching slug is created, restored, or renamed into existence, every ghost pointing at that slug is
adopted automatically (`KnowledgeLinkService::attachGhosts`).

A `KnowledgeLink`'s `source` is one of four, and the four have different owners that must never
overwrite one another (part of the row's unique key `[from_entry_id, target_slug, source]`):

| Source | Owner | Notes |
|---|---|---|
| `wikilink` | The entry's own content | Deleted + re-inserted on every save. |
| `similarity` | The vector layer | Machine-proposed (`score` + `evidence`), re-derived on every re-index; a human's `dismissed_at` stamp survives a re-index (see below). |
| `mention` | The mention scanner | The entry's prose NAMES the target's title. Machine-proposed, no `score`, `evidence` is the first mention's `{char_start, char_length}`. Re-derived on every re-index; dismissals survive. |
| `manual` | A human, drawn in the UI | Nothing automated may remove it. |

Only the machine-proposed kinds (`similarity`, `mention`) may be DISMISSED — the resource says so per
row with `can_be_dismissed`, so a client never has to re-derive the rule from `source`. A `wikilink` is
what the text says (edit the text); a `manual` edge is deleted, not refused.

### Mention edges — the graph derives itself from prose

Nobody types `[[wikilinks]]`. A base whose author writes "wieży Eiffla" rather than
`[[wieza-eiffla]]` would otherwise have no edges at all, so on every index pass the module scans an
entry's content for the titles of other live entries in the same base. It costs no AI call.

The rule, exactly: both sides are word-tokenized and normalized through the same transliteration the
slugs use; a title matches when **all** of its words appear **consecutively** in the content; a content
word matches a title word by stem (the title word minus up to 3 trailing characters, minimum 4 — Polish
inflection makes exact matching useless), and title words shorter than 4 characters must match exactly.

**A target is named by its title, or by ANY of its `aliases` — never a looser rule for one than the
other.** Each alias is compiled into its own pattern through the identical mechanics (date-stripping,
the same stem rule, the same "too short/reduces to nothing" refusal), so an alias cannot smuggle in a
match a title's own name could not have earned. This is deliberately the cheap substitute for a Polish
lemmatiser this stack does not have — see `docs/ai/reference-links.md` → Knowledge module, entry 5 —
not a second, weaker matching path. One mention per target regardless of how many names (title or any
alias) hit: whichever name is found EARLIEST in the content wins, so writing a good alias list can
never double an edge.

It runs in both directions on every index — the entry naming others, and older entries that already
name it — so a newly created entry gains its inbound edges immediately instead of waiting for every
older note to be re-saved. A mention is never drawn where a `wikilink` or `manual` edge already points,
never creates a ghost, is capped at `knowledge.links.max_mentions` per entry, and is skipped forever
once dismissed.

**Dates are stripped from the title before matching.** Real titles carry them — "Zwiedzanie wieży
Eiffla -13 lipiec 2026" — and prose never repeats an entry's own date stamp, so under an all-words
rule one stray `2026` made such a title permanently unmatchable. Bare numbers and Polish month names
(nominative *and* genitive: `styczeń`/`stycznia` … `grudzień`/`grudnia`) are dropped; punctuation and
dashes never become tokens at all. What remains must still be a name: a title reducing to nothing
("Lipiec 2026") or to a single word shorter than 5 characters is not scanned for.

**Known limitations:**

- Stem matching over-matches. A title that is an ordinary noun ("Cennik") will be found inside every
  note using that word, and a short title stems broadly ("Paryz" → `pary`, which also matches
  "parytet"). Accepted because a mention is a dismissible suggestion, not an assertion.
- All remaining title words must appear **consecutively**. A title of several distinctive words
  behaves best; one whose words are only ever used apart in prose will not produce edges. Matching a
  *subset* of title words was considered and rejected — it would connect "Podróż paryż - lipiec 2026"
  to every note containing "podróż", which is the opposite failure and much harder to notice.

Bumping `knowledge.links.version` re-derives every base's edges on the next
`php artisan knowledge:sweep-index` and reuses every stored embedding (zero AI spend) — the supported
way to roll out a heuristic change. Current value: **3** — version 2 was the date-stripping fix above;
version 3 is exactly the alias-matching change just described, so every base gets its alias-derived
edges the next time it sweeps, with no entry write anywhere required.

### Typed relations — ASSERTED, never derived, never swept

Full design rationale: [ADR-0047](../decisions/ADR-0047-knowledge-typed-relations.md).

A `KnowledgeLink` is DERIVED — parsed from prose or measured by a vector, deleted and rebuilt on every
save/re-index. A `KnowledgeRelation` is the opposite kind of thing: a TYPED STATEMENT ("Anna WORKS ON
the refund project") that a person approved — either drawn by hand or proposed by the AI composer
(ADR-0046) and accepted — and it lives in its **own table**, never touched by the link sweep. Two
entries may hold several relations of the same type at once (`"met on 2026-08-15"` and
`"met on 2026-09-12"` are two facts, not a duplicate) — there is no unique constraint on
`(from_entry_id, to_entry_id, relation_type)`; only an identical **active** relation (same pair, verb,
`valid_from`) is refused as a duplicate.

**The vocabulary is a closed set of 20 verbs, fixed in code** (`KnowledgeRelationType`). The original 15
describe standing STATES: `member_of`, `works_on`, `knows`, `created`, `owns`, `located_in`,
`participated_in`, `occurred_during`, `part_of`, `is_a`, `uses`, `depends_on`, `precedes`, `caused`,
`opposes`. Five more (added for ADR-0048, the composer's subject paradigm) describe EPISODES — what a
subject DID, with an author, a direction, and usually an end date: `visited` (a temporary stay — "spent
three days in Paris" — as opposed to `located_in`, which means permanently situated: a tower in a city),
`organized`, `won`, `interacted_with` (DIRECTED — see below), and `related_to` (a last-resort fallback so
a fact with no matching verb is a weak edge rather than a silent loss). Three verbs are **symmetric**
(`knows`, `opposes`, `related_to`) — stored ONCE, in a canonical direction (the smaller entry id first),
rendered undirected. Every other verb is genuinely directed and carries two i18n labels
(`label`/`inverse_label`) so a panel anchored on either end words the same row correctly without
re-deriving grammar. **`interacted_with` is the only directed verb between two people** — before it,
`knows` and `opposes` were the sole verbs allowed between two `person` entries and both are symmetric, so
no directed human act (an apology, a criticism, a thanks) could be recorded at all; it reads FROM THE
ACTOR, carrying `properties.act` (a short free word — see "Known limitations" in ADR-0048) and
`properties.sentiment`. Each verb declares which OWN `properties` keys it accepts (most accept none or
one; an unknown key is REFUSED, not dropped — the same stance the metadata validator takes) **plus two
UNIVERSAL keys every verb accepts regardless**: `properties.sentiment` (`positive`/`negative`/`neutral`/
`mixed`) and `properties.confidence` (`low`/`medium`/`high`) — validated by VALUE, not only by key name,
because a column meant to be filtered on is only as useful as its value set is disciplined. Each verb
also declares which `KnowledgeEntryType`s may sit at each end (`fromTypes()`/`toTypes()`) — the whole
vocabulary, including the type matrix, is published as `relation_vocabulary` on `KnowledgeBaseResource`
(see below) so a client never has to keep its own copy of the ontology in sync.

**A newly created relation is stamped `ended`, not `active`, when its own `valid_to` is already in the
past.** `create()` used to stamp every relation `active` unconditionally, which was harmless while a
relation described a standing state nobody backdated — it stopped being harmless the moment the composer
began recording closed episodes: "visited Paris, 12–15 July" written as `active` asserted, in the present
tense forever, that the subject IS in Paris (and in Bangkok, and in Tokyo). `scopeCurrent()` filters on
`state`, not on dates, so the state has to say it. The same change means a closed episode no longer
consumes the per-entry relation cap (below), which a subject-shaped graph — naturally full of dated,
finished visits — would otherwise exhaust on history alone.

**The duplicate rule is split on whether the relation carries a date, and both halves are necessary.** A
DATED assertion is compared against every relation of the same pair/verb/date in ANY state — the date IS
its identity ("visited Paris on 12 July" is one fact), and since a closed episode is now born `ended`,
comparing only against `active` rows would mean a relation stopped being a duplicate of itself the moment
it was written, and re-running the same material would stack a fresh copy of every episode on every pass.
An UNDATED assertion is compared only against `active` rows, because nothing else distinguishes two
undated statements except whether the first is still standing — "she left the company, then rejoined"
has to stay expressible, and comparing an undated create against an already-`ended` undated relation
would refuse a real, new fact as a duplicate of a stale one.

**`entry_type`** (`KnowledgeEntryType`: `person`, `organization`, `event`, `place`, `product`, `work`,
`concept`, `other`) exists on an entry for exactly one job — letting the type matrix say "a person
WORKS ON a project" is a sentence and "a city WORKS ON a person" is not. `null` (every entry written
before this column existed) and `other` (a deliberate "none of these") are both treated as **unknown**
by the matrix, which only REFUSES a write when **both** ends are typed and the pair is outside it — see
"The pair matrix is advisory" below.

**A base may narrow the vocabulary.** `KnowledgeBase.relation_types` is `null` (never configured — the
whole vocabulary) or an array of allowed type ids (`[]` legitimately means none) —
`RelationVocabulary::for($base)`. `null` and `[]` are deliberately different answers.

**The pair matrix is advisory, not a hard gate — a deliberate departure from this module's own
fail-closed posture elsewhere (see ADR-0047 D4).** `RelationVocabulary::check()` returns one of three
verdicts: `ALLOWED` (both ends typed, pair in the matrix), `REFUSED` (both ends typed, pair NOT in the
matrix — the only hard refusal, `422 knowledge_relation_pair_refused`), or `UNKNOWN_TYPES` (at least one
end untyped or `other` — accepted, never refused). Refusing on an untyped guess would reject correct
relations across every base written before `entry_type` existed, with total confidence, over data
nobody had been asked for.

**The matrix was DORMANT until ADR-0048, and is not any more.** The AI composer never set `entry_type`
on anything it wrote, so a composed relation always had at least one untyped end — every write from the
composer landed in `UNKNOWN_TYPES` and NOTHING was ever actually checked. Now that the composer types
its entries (see "The AI composer" below), this matrix judges real writes, and it was published inside
the composer's own prompt for exactly that reason — a matrix that starts refusing sentences a writer was
never shown the rules for produces confusing refusals for reasons nobody was told. Operationally: expect
a wave of `pair_refused` on the first sessions run against it, and watch `graph_ops.rejected[]` while it
settles (see ADR-0048 "Known limitations").

**Lifecycle — three ways to stop asserting a relation, and only one of them is destructive.**
`KnowledgeRelationState`: `active` (asserted now) → `ended` (WAS true, has a `valid_to`, the row is
KEPT) or `retracted` (was NEVER true — a mistaken assertion, the row is KEPT so the base remembers a
wrong claim was made and rejected) → a separate, irreversible hard `DELETE` (hard-restricted to the
relation's creator or the workspace owner — see Authorization). `superseded_by_id` (set only via
`supersede`) points FORWARD from an ended relation at the one that replaced it, so a reader following a
fact that stopped being true arrives at the current one rather than a dead end. **The machine-facing
contract (the AI composer) knows only `create`/`update`/`end`.** `supersede` remains reachable
indirectly, through an accepted `create` that carries a bound `replaces`. **As of the AI-only authoring
pivot ([ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)), `retract` and the hard
`DELETE` are reachable by NOBODY through the product** — the relation panel that used to offer them by
hand is gone, and `KnowledgeGraphOpsApplier` (the composer's sole write path) never calls either.
`delete()` survives as a service method reachable from exactly one place outside a test: the
`knowledge:purge-subject` erasure command (see "Data erasure" below). `retract` has no caller anywhere
in the shipped product today — the lifecycle value stays because the state machine is meaningless
without it, and because a scoped, human-facing "this was never true" affordance remains a plausible
future re-addition.

**`origin`** (`KnowledgeRelationOrigin`): `composer` (an AI proposal a person approved) is the **only
value any relation can receive from this batch forward** — `KnowledgeGraphOpsApplier`, the sole
surviving writer, always passes `origin: KnowledgeRelationOrigin::COMPOSER`. `human` (drawn in the UI by
a person, from nothing) and `promoted` (a machine-derived link upgraded into a typed statement, stamping
the source link's `dismissed_at`) are **historical values only** — a relation carrying either was
written before the authoring withdrawal; nothing can produce either value today. Provenance, not
authorship — the `creator` columns already record which account performed the write; `origin` is what
lets a base later ask "what do we believe only because a model said so."

### The chunk table is the index, never rendered directly

`KnowledgeEntryChunk` is one embedded passage of an entry — the unit of retrieval. Its `embedding`
column (`vector(1536)`, pgvector) has **no Eloquent cast**; nothing outside
`App\Modules\Knowledge\Support\ChunkVector` reads or writes it directly, and every list/detail read
uses `scopeWithoutEmbedding()`. `(knowledge_entry_id, ordinal)` is a stable citation address, reused
by search results and similarity-edge evidence alike.

### Index status (6 values) — orthogonal to editorial status

`KnowledgeIndexStatus`, tracked per entry:

| Value | Meaning | `isRetryable()` |
|---|---|---|
| `pending` | New, or the text changed; nothing has embedded it yet. | No — already queued. |
| `indexing` | A worker holds it. | No — a live worker holds it (released by the stale-claim reaper if the worker died). |
| `indexed` | Every chunk carries a current embedding. | No — nothing left to do. |
| `partial` | Some chunks embedded, some did not — a run interrupted mid-way. **Genuinely, incompletely retrievable**, distinct from `failed` because work already paid for is kept. | **Yes.** |
| `pending_budget` | Refused BEFORE spending: the workspace is at its AI cap. Nothing is broken; raising the cap (not a retry) is the fix, but retry re-queues once it has. | **Yes.** |
| `failed` | The run could not produce anything usable. | **Yes.** |

Whether an entry **needs** work is never read off this status column alone — the authority is
`index_digest != indexed_digest` (`KnowledgeEntry::needsIndexing()`), so a worker that died holding
`indexing` can never make a stale entry look current.

### Search: two legs, fused, honest about what it could not do

`GET /knowledge/search` and `GET /knowledge/bases/{base}/search` run a lexical leg (the shared
`Searchable` scope) and a vector leg (one metered query embedding), fused by reciprocal-rank fusion
(K=60). **Not paginated** — a relevance ranking has no stable cursor; the response reports
`meta.has_more` and caps at `knowledge.search.max_results` (default 25). Shows every status except
`archived` by default (a human is often searching for something they half-wrote). See
[ADR-0044](../decisions/ADR-0044-knowledge-index.md) D6.

### Consumption: `inline` / `rag` / `auto`, approved-only, never fails

A bound consumer (a Bot today) reads via `KnowledgeBindingMode`:

- **`inline`** — the whole `approved` base, in the author's manual order, verbatim. Free (no
  embedding call); never misses a fact; degrades by SILENTLY DROPPING WHOLE ENTRIES (never
  truncating one mid-sentence) once the base outgrows the character budget — the dropped titles are
  named inside the block itself.
- **`rag`** — the passages closest in meaning to the caller's query, plus one free hop of
  materialized links. Costs exactly one embedding call.
- **`auto`** (default) — compiles inline first (free); if the compiled block fits the budget whole
  (no omissions), that answer is kept — it is strictly better than retrieval. Only pays for `rag`
  once the base has genuinely outgrown the budget.

Retrieval for an AI consumer is **stricter than the human search**: `approved` status only, always —
text compiled here is handed to a model as FACT. Every retrieval path is wrapped in a single,
per-consumer, unforgeable prompt fence (`KnowledgeFence`, built on the shared `App\Support\Ai\FencedBlock`
also used by the Generator's creative-direction layer) — independent of, and in addition to, the
write-side `TemplateDirectiveGuard` (one stops an entry being *executed* by the template engine, the
other stops it being *obeyed* by a model reading its prose as an instruction). See
[ADR-0045](../decisions/ADR-0045-knowledge-consumption-data-erasure.md).

**This path cannot fail.** Every failure mode — kill switch off, no vector support, over budget,
provider error, an un-indexed base, nothing matched — degrades to the free inline compiler, which
itself only returns `null` when the base holds nothing approved at all. A consumer never sees an
exception from `KnowledgeRetrievalService::forBinding()`.

---

## Resource shapes

### KnowledgeBaseResource

```json
{
  "id": "uuid",
  "name": "string",
  "description": "string | null",
  "charter": "string | null",
  "language": "pl",
  "metadata_schema": [{ "key": "region", "label": "Region", "descriptor": { "base": "enum", "options": [...] } }],

  "relation_types": ["member_of", "works_on", "..."],
  "relation_vocabulary": [
    { "id": "member_of", "label": "is a member of", "inverse_label": "has member", "symmetric": false,
      "property_keys": ["role"], "from_types": ["person", "organization"], "to_types": ["organization"] }
  ],

  "entries_count": 12,
  "ghost_links_count": 3,
  "index_summary": { "total": 12, "pending": 1, "indexing": 0, "indexed": 10, "partial": 0, "pending_budget": 0, "failed": 1 },

  "creator": { "type": "user", "id": "uuid", "name": "string", "email": "string", "avatar": null },

  "is_owner": true,
  "can_be_edited": true,
  "can_be_managed": true,
  "can_be_deleted": true,

  "created_at": "ISO 8601 | null",
  "updated_at": "ISO 8601 | null",
  "deleted_at": "ISO 8601 | null"
}
```

- **`entries_count` / `ghost_links_count` / `index_summary` are ALWAYS present**, on every response
  path including a freshly created or updated base — `KnowledgeBaseService::attachAggregates()` is
  called by every controller method that returns one. `index_summary.total === entries_count`,
  measured in ENTRIES (an entry-level `index` object below counts CHUNKS instead).
  `ghost_links_count` excludes dismissed ghosts and ghosts drawn by a trashed entry (neither is
  actionable) — render it **only when > 0**, a permanent "0" is noise.
- `can_be_edited` (everyday: name/description/language) is a **different ability** from
  `can_be_managed` (governance: charter + metadata schema) — see Authorization below. A UI that
  collapses them into one "edit" button will produce 403s it cannot explain.
- **`relation_types` is always the RESOLVED list** — `RelationVocabulary::idsFor($base)` — never the raw
  stored column: a base that never configured one gets back every one of the 15 ids, so a client renders
  one type picker from one field instead of re-implementing "null means everything" itself. See "Typed
  relations" above.
- **`relation_vocabulary` is the WHOLE ontology `relation_types` indexes into** — every one of the 15
  types' both labels, `symmetric`, `property_keys` and the entry-type matrix, sent WITH the base so a
  relation editor needs no second request. Full shape:
  `{ id, label, inverse_label, symmetric, property_keys: string[], from_types: string[], to_types: string[] }`
  per type — `from_types`/`to_types` are `KnowledgeEntryType` ids the matrix allows at each end.

### KnowledgeEntryResource (single entry — the editor's payload)

```json
{
  "id": "uuid",
  "knowledge_base_id": "uuid",
  "title": "string",
  "slug": "string",
  "content": "string",
  "metadata": { "region": "eu" },
  "aliases": ["string", "string"],
  "entry_type": "person | organization | event | place | product | work | concept | other | null",

  "status": "draft | proposed | approved | archived",
  "stale_at": "ISO 8601 | null",
  "is_stale": false,
  "position": 0,

  "current_revision_id": "uuid",

  "index": {
    "status": "pending | indexing | indexed | partial | pending_budget | failed",
    "chunks_count": 8,
    "indexed_chunks_count": 7,
    "needs_indexing": false,
    "can_retry": false
  },

  "draft_session_id": "uuid | null",
  /* targets_entry / target_revision_id / target_revision_stale are ABSENT (whenLoaded false → the key
     itself is dropped, not sent as null) on every entry that is not a SHADOW draft — shown here only
     to document their shape: */
  "targets_entry": { "id": "uuid", "title": "string", "slug": "string", "current_revision_id": "uuid | null" },
  "target_revision_id": "uuid | null",
  "target_revision_stale": false,
  /* amend_mode / amend_section / amended_body are ALSO whenLoaded($this->isShadow()) — absent on every
     ordinary entry and on a plain (non-shadow) draft, shown here only to document their shape: */
  "amend_mode": "append | rewrite",
  "amend_section": "string | null",
  "amended_body": "string",

  "links": [ /* KnowledgeLinkResource[] — outgoing, only when eager-loaded (single-entry view) */ ],
  "backlinks": [ /* KnowledgeLinkResource[] — incoming */ ],

  "creator": { "type": "user", "id": "uuid", "name": "string", "email": "string", "avatar": null },

  "is_owner": true,

  "created_at": "ISO 8601 | null",
  "updated_at": "ISO 8601 | null",
  "deleted_at": "ISO 8601 | null"
}
```

- **No `can_be_edited`/`can_be_deleted`/`can_be_purged`** — removed with the AI-only authoring pivot
  ([ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)). An entry is written by the
  composer and published by an acceptance; no person may edit, trash or purge one by hand, so those
  flags were `false` for everybody, always, and sending three permanently-false booleans on every row of
  every page would be a field the client must read to learn nothing. `is_owner` stays — it says who this
  entry came from, which a reader still wants to know even though it no longer gates a write.
- `current_revision_id` is **the optimistic-lock token** — read it here, send it back as
  `expected_revision_id` on the next save. See "409 knowledge_stale_write" below.
- `index.indexed_chunks_count` is **null**, not `0`, on a connection with no vector support
  (`ChunkVector::supported()` false) — "cannot be counted here," not "zero passages indexed." Treat
  a `null` differently from `0` in the UI (a `0` on an `indexed` entry with `chunks_count: 0` means a
  genuinely empty body, which is `indexed` by definition).
- `index.can_retry` is read from the **same enum predicate** (`KnowledgeIndexStatus::isRetryable()`)
  the retry endpoint itself enforces — the flag can never promise an action that then 422s.
- `links` / `backlinks` appear **only when eager-loaded** (the show/store/update/restore/retry
  responses load them; the LIST resource never carries them).
- **`draft_session_id` / `targets_entry` / `target_revision_id` / `target_revision_stale` are the AI
  composer's fields (see "The AI composer" below) — and the two groups behave DIFFERENTLY on the wire,
  which matters for a client that checks "is this field here" vs. "is this field null".**
  `draft_session_id` is **always present**, `null` on every ordinary, published entry (built with a
  plain array key, no `when()`). A non-null value means this row is an unaccepted draft — reachable
  ONLY through the composer's own endpoints (`withDrafts()`), never through this one, because the
  ordinary `{entry}` route binding applies `WithoutDraftsScope` and 404s on a draft id.
  `targets_entry` / `target_revision_id` / `target_revision_stale`, by contrast, are each wrapped in
  `$this->when($this->isShadow(), …)` — Laravel drops the KEY ENTIRELY when the condition is false, so
  on every entry that is not a SHADOW (`isShadow(): targets_entry_id !== null`) these three keys are
  **absent from the JSON**, not present with a `null` value. The frontend relies on this: it tests key
  presence (`draft.targets_entry` truthy) to decide "is this an amendment", not a `!== null` check,
  which is what makes the distinction load-bearing rather than a formality. `target_revision_stale` is
  computed server-side (`current_revision_id` of the live target vs.
  the revision the composer read) and is safe to trust **before** an accept attempt — the client never
  has to compare revision ids itself.
- **`entry_type` feeds ONLY the typed-relation matrix — nothing else in the module reads it.** `null`
  (every entry written before this column existed) is the normal, common state, not an error state; it
  is NOT part of the index digest (see Concepts → "Write discipline" precedent in `KnowledgePage.vue`) —
  setting it alone never appends a revision and never re-queues indexing, because an entry's kind is a
  fact about the graph, not about the document.
- **`amend_mode` / `amend_section` / `amended_body` exist ONLY on the full, single-entry resource —
  never on `KnowledgeEntryListResource`.** Like the shadow fields above they are `whenLoaded`-gated on
  `isShadow()`; on an ordinary entry or a plain (non-shadow) draft the keys are absent, not null.
  `amend_mode` is `append` (the shadow's `content` column holds the ADDITION alone) or `rewrite` (the
  column holds the whole replacement body); `amend_section` names the heading an append targets, or
  `null` for "append at the end." **`amended_body` is the field to render — never `content` — for an
  append shadow.** It is COMPOSED at read time from the target entry's LIVE text plus the shadow's
  addition (`SectionAppender`, matching what accepting the shadow actually does); rendering the raw
  `content` column instead shows the addition alone as though it were the entry's entire new text, which
  actively misrepresents what accepting the proposal does (see ADR-0047, defect (d)). For a rewrite
  shadow or a plain draft, `amended_body` returns `content` unchanged — the same field is safe to render
  unconditionally on every shadow.

### KnowledgeEntryListResource (list rows — no body)

Same shape as above **minus `content` and `links`/`backlinks`**, plus a 200-char `excerpt` (collapsed
whitespace, from the raw content, `…`-truncated). A **separate resource**, not a flag on the full
one — an entry may hold 40 000 characters, and a 25-row page of full entries would be a megabyte of
text nothing on screen displays. `index` carries the identical shape as the detail resource (a list
row and a detail view must never describe indexing differently); `indexed_chunks_count` rides ONE
correlated sub-select for the whole page (`KnowledgeEntry::scopeWithIndexedChunks`), never an N+1.

`aliases` and `entry_type` **are** carried on the list row (cheap — both are plain columns already on
the query, no extra fetch), so a card can render a type badge or an alias chip without a follow-up
request. **The whole composer/shadow group is NOT** — `draft_session_id`, `targets_entry`,
`target_revision_id`, `target_revision_stale`, `amend_mode`, `amend_section`, `amended_body` exist only
on `KnowledgeEntryResource` (the single-entry view); a list row never represents a draft or a shadow.

### KnowledgeEntryRevisionResource

```json
{
  "id": "uuid",
  "knowledge_entry_id": "uuid",
  "title": "string",
  "content": "string",
  "metadata": {},
  "change_note": "string | null",
  "author": { "type": "user", "id": "uuid", "name": "string", "email": "string", "avatar": null },
  "created_at": "ISO 8601 | null"
}
```

No `updated_at` — there is no such column (see Concepts). `author` (not `creator`) — the underlying
relation is the shared `HasCreator` polymorphic pair, column-overridden to `author_id`/`author_type`
on this table, exposed under its own domain name because on a revision the actor is the author of
THAT version, not the creator of the entry.

### KnowledgeLinkResource (one edge)

```json
{
  "id": "uuid",
  "knowledge_base_id": "uuid",
  "from_entry_id": "uuid",
  "to_entry_id": "uuid | null",
  "target_slug": "string",
  "source": "wikilink | similarity | mention | manual",
  "is_ghost": false,

  "score": 0.91,
  "evidence": { "from_chunk_ordinal": 2, "to_chunk_ordinal": 0 },
  "dismissed_at": "ISO 8601 | null",
  "can_be_dismissed": true,

  "target": { "id": "uuid", "title": "string", "slug": "string" },
  "source_entry": { "id": "uuid", "title": "string", "slug": "string" },

  "created_at": "ISO 8601 | null"
}
```

- `score` / `evidence` are populated for the machine-proposed edges only; a `wikilink` and a `manual`
  edge carry neither. The two derived kinds carry DIFFERENT evidence shapes:
  - `similarity` → `{ "from_chunk_ordinal": int, "to_chunk_ordinal": int }`, plus a `score` in `[0,1]`;
  - `mention` → `{ "char_start": int, "char_length": int }` and `score: null`. The offsets are
    CHARACTER offsets into the **source entry's** content, so
    `mb_substr(content, char_start, char_length)` is the mention itself — the same convention chunks
    and search snippets use.
- `can_be_dismissed` is true for `similarity` and `mention`, false for `wikilink` and `manual`.
- `target` is present when `toEntry` was eager-loaded (the outgoing-links list); `source_entry` when
  `fromEntry` was (the backlinks list). Both are the minimal `{id, title, slug}` — never the whole
  entry.

### KnowledgeRelationResource (one typed relation)

Full design rationale: [ADR-0047](../decisions/ADR-0047-knowledge-typed-relations.md).

```json
{
  "id": "uuid",
  "knowledge_base_id": "uuid",

  "from_entry_id": "uuid",
  "to_entry_id": "uuid",

  "relation_type": "member_of",
  "label": "is a member of",
  "inverse_label": "has member",
  "symmetric": false,

  "description": "string | null",
  "properties": { "role": "CTO" },
  "valid_from": "yyyy-mm-dd | null",
  "valid_to": "yyyy-mm-dd | null",

  "state": "active | ended | retracted",
  "is_active": true,
  "superseded_by_id": "uuid | null",

  "origin": "human | composer | promoted",

  "from_entry": { "id": "uuid", "title": "string", "slug": "string", "entry_type": "person | null" },
  "to_entry": { "id": "uuid", "title": "string", "slug": "string", "entry_type": "organization | null" },

  "creator": { "type": "user", "id": "uuid", "name": "string", "email": "string", "avatar": null },

  "created_at": "ISO 8601 | null",
  "updated_at": "ISO 8601 | null"
}
```

- **`label` / `inverse_label` are BOTH always sent, and neither is decoration.** A relation panel is
  read from ONE entry's point of view, so the same row has to be worded two ways — "is a member of
  Acme" on Anna's page, "has member Anna" on Acme's — and a client deriving the second from the first
  would be re-implementing grammar the server already knows (see `relation_vocabulary` above).
  `symmetric` says the direction carries no meaning at all (`knows`, `opposes`); draw it undirected
  rather than picking an arbitrary arrow.
- **`valid_from`/`valid_to` are DATES (`yyyy-mm-dd`), never timestamps** — the facts this module records
  (employment, membership, a project's span) are known to the day at best.
- **`state` is the whole lifecycle, and the three values are different SENTENCES, not shades of one
  fact.** `active` is a claim about now; `ended` is a claim about the past (`valid_to` says when it
  stopped, the row is KEPT); `retracted` is "this was never true" (also KEPT — see ADR-0047 D5). A UI
  rendering the three identically is lying about two of them. `superseded_by_id` is set only when
  `ended` was reached via `supersede` — a reader following it lands on the relation that replaced this
  one.
- **`origin: composer` is the only value any relation can receive from the AI-only authoring pivot
  forward** ([ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)) — `human` (drawn by a
  person from nothing) and `promoted` (a machine-derived link a person upgraded into a typed statement)
  are **historical values only**, carried by rows written before the withdrawal; nothing can produce
  either today.
- **`from_entry`/`to_entry` are the minimal `{id, title, slug, entry_type}`**, present only when eager
  loaded (every controller path that returns this resource loads them) — never the whole entry, for the
  same reason a graph node is minimal.
- **No capability flags.** `can_be_edited`, `can_be_ended` and `can_be_deleted` are gone — removed with
  the AI-only authoring pivot, because none of those things is possible for anyone any more. Editing,
  ending, retracting and deleting a relation by hand are all withdrawn (see "This module is read-only for
  humans" above and [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md) D7); sending
  three permanently-false booleans on every edge of every graph would be a field the client must read to
  learn nothing.

### KnowledgeGraphResource

```json
{
  "center": "uuid | null",
  "nodes": [{ "id": "uuid", "slug": "string", "title": "string", "entry_type": "person | null", "status": "approved", "is_stale": false, "degree": 3, "distance": 1 }],
  "edges": [
    { "id": "uuid", "kind": "link", "from": "uuid", "to": "uuid",
      "source": "wikilink", "score": null, "evidence": null, "dismissed": false, "can_be_dismissed": false,
      "relation_type": null, "label": null, "inverse_label": null, "symmetric": false,
      "description": null, "properties": null, "valid_from": null, "valid_to": null, "state": null, "origin": null },
    { "id": "uuid", "kind": "relation", "from": "uuid", "to": "uuid",
      "source": null, "score": null, "evidence": null, "dismissed": false, "can_be_dismissed": false,
      "relation_type": "works_on", "label": "works on", "inverse_label": "worked on by", "symmetric": false,
      "description": "string | null", "properties": { "role": "lead" }, "valid_from": "2024-01-01", "valid_to": null,
      "state": "active", "origin": "human" }
  ],
  "ghosts": [{ "target_slug": "polityka-zwrotow", "from_ids": ["uuid", "uuid"], "count": 2 }],
  "truncated": { "hidden_nodes": 0, "hidden_edges": 0 }
}
```

- Nodes are **deliberately minimal** — no content, no excerpt, no metadata. `entry_type` is the one
  addition typed relations bring to a node (feeds the type badge a client draws beside it — see "Typed
  relations" above; nothing else in the response reads it). `degree` and `distance` describe the
  picture that was actually RETURNED (they may differ from the true base-wide degree when the response
  was capped) — never label a node with a fact it does not visibly hold. `distance` is `null` in the
  overview (no centre) and `0` for the centre itself. Every `edges[].from`/`.to` is guaranteed to
  reference an id present in `nodes[]` — the graph is assembled so this always holds; a renderer never
  has to decide what to do with a dangling end.
- **`edges[].kind` is the DISCRIMINATOR — `link` (DERIVED: a wikilink, a mention, a similarity match, or
  a manual edge) or `relation` (ASSERTED: an approved typed statement, never rebuilt by a sweep).**
  Drawing the two identically would be the single most misleading thing this response could do. Every
  field of the OTHER kind is **present-and-null**, not absent — the row shape never changes with its
  kind, only its contents do, so a client never tests for key existence before reading a value.
  `source`/`score`/`evidence`/`can_be_dismissed` are the `link` fields (null on a `relation`);
  `relation_type`/`label`/`inverse_label`/`symmetric`/`description`/`properties`/`valid_from`/
  `valid_to`/`state`/`origin` are the `relation` fields (null on a `link`).
- `ghosts[]` are **not edges** — they have no target node to draw to. `truncated.hidden_nodes` is
  what an uncapped answer would have added (in the overview, the rest of the base); `hidden_edges`
  is what the walk found but could not draw because an endpoint was cut.
- `edges[].dismissed` is always present and `false` unless `?include_dismissed=1` was passed —
  without that flag a dismissed edge is not in the response at all. **Always `false` on a `relation`**
  — dismissal is the soft, reversible "no, unrelated" verdict on a DERIVED guess; a relation was
  asserted rather than guessed, so the available answers are ENDING it (it stopped being true) or
  RETRACTING it (it never was), each its own endpoint (see "Typed relations" endpoints below), never
  `dismiss`.
- `edges[].can_be_dismissed` is always present too — `true` for the machine-proposed LINK kinds
  (`similarity`, `mention`), `false` for `wikilink`/`manual` **and always `false` for `kind: relation`**
  — read from the same enum predicate (`KnowledgeLinkSource::isDismissable()`) the dismiss endpoint
  itself refuses on, `?? false` for a relation (which has no `KnowledgeLinkSource` at all). Identical
  field, identical rule to `KnowledgeLinkResource`'s own `can_be_dismissed` — the graph never has to make
  a client re-derive dismissability from `source`/`kind`.

### KnowledgeSearchResultResource (one search hit)

Extends `KnowledgeEntryListResource`'s shape (delegated, never restated) plus:

```json
{
  "base": { "id": "uuid", "name": "string" },
  "matched_chunk": {
    "ordinal": 2,
    "heading_path": "Cennik > Rabaty",
    "score": 0.87,
    "snippet": "…the readable fragment…",
    "char_start": 512,
    "char_length": 240,
    "highlights": [[540, 6], [601, 4]],
    "truncated_before": true,
    "truncated_after": true
  },
  "matched_chunks_count": 3,
  "rrf_score": 0.032787
}
```

- **Every offset in `matched_chunk` is a CHARACTER offset into the entry's OWN `content`, never
  markup.** The invariant `mb_substr(entry.content, matched_chunk.char_start, matched_chunk.char_length)
  === matched_chunk.snippet` holds exactly, and each `highlights` pair `[start, length]` is an
  absolute sub-range of that same window. **The server never returns `<mark>` or any HTML** — the
  client must render highlights itself from the offsets; injecting server-side markup into
  user-authored text would create a sanitization seam purely for a rendering convenience.
- `matched_chunk` is `null` when the entry has no body at all; `ordinal`/`heading_path`/`score` are
  `null` specifically when the entry was found by the **keyword leg alone** (a brand-new entry the
  indexer has not reached, or a words-match-but-meaning-doesn't hit) — this is a **normal**, not
  degraded, result, and the client renders one shape either way.
  `matched_chunk.score` is a real cosine similarity in `[0, 1]` — use it for a "match: 87%"
  affordance.
- `rrf_score` is **comparable only within one response** — a fused rank score, not a percentage and
  not a similarity. Never render it as either.

### Search response envelope

```json
{
  "data": [ /* KnowledgeSearchResultResource[] */ ],
  "meta": {
    "query": "polityka zwrotow",
    "count": 12,
    "limit": 25,
    "has_more": false,
    "vector_search_skipped": true,
    "vector_search_reason": "budget"
  }
}
```

- `vector_search_reason` is one of `budget | disabled | unsupported | error`, or `null` when both
  legs ran. **This is a 200, never an error** — a search whose vector leg was skipped still returns
  a full page of keyword results and says so; turning an exhausted AI budget into a client-visible
  error would make an ordinary, expected cost event look like an outage.

### KnowledgeDraftSessionResource (the composer's poll payload)

Full design rationale: [ADR-0046](../decisions/ADR-0046-knowledge-ai-composer.md).

```json
{
  "id": "uuid",
  "knowledge_base_id": "uuid",

  "status": "idle | generating | ready | failed",
  "failure_reason": "unparseable | empty | seed_missed | provider | disabled | null",

  "notes": [{ "code": "amend_append_only", "...": "context, varies by code" }],

  "resolution": {
    "entities": [{
      "handle": "E1", "slug": "string", "title": "string", "current_revision_id": "uuid | null",
      "content": "string", "truncated": false, "entry_type": "person | null",
      "relations": [{
        "handle": "R1", "id": "uuid", "type": "works_on", "label": "works on",
        "direction": "out | in", "other_handle": "E4 | null", "other_title": "string",
        "description": "string | null", "properties": {}, "valid_from": "yyyy-mm-dd | null",
        "valid_to": "yyyy-mm-dd | null", "state": "active | ended | retracted"
      }]
    }],
    "ambiguous": [{ "mention": "Łukasz", "candidates": [{ "handle": "E2", "title": "string" }] }],
    "unresolved": [{ "mention": "string", "note": "string | null" }],
    "omitted": ["string"],
    "degraded": ["string"]
  },

  "graph_ops": {
    "entities": [{ "ref": "N1", "title": "string", "slug": "string | null", "entry_type": "person | null", "aliases": [], "from_draft_slug": "string | null" }],
    "wiki_updates": [{ "entity": "N1", "op": "create", "section": null, "content": "string" }],
    "graph_updates": [{ "op": "create", "from": "E1", "to": "N1", "type": "works_on", "description": "string | null", "properties": {}, "valid_from": "yyyy-mm-dd | null", "valid_to": null, "replaces": "R7 | null" }],
    "unresolved": [{ "mention": "string", "note": "string | null" }],
    "rejected": [{ "code": "pair_refused", "type": "works_on", "from": "E1", "to": "N1" }],
    "warnings": [{ "code": "pair_unchecked", "type": "works_on", "from": "E1", "to": "N1" }]
  },

  "source_text": "string",
  "prompt_history": [{ "at": "ISO 8601", "instruction": "string" }],

  "seed_slug": "string | null",
  "seed_title": "string | null",

  "context_expanded_at": "ISO 8601 | null",

  "duplicates": {
    "<draft-entry-id>": { "slug": "string", "title": "string", "score": 0.91 }
  },

  "drafts": [ /* KnowledgeEntryResource[], only when eager-loaded — every controller action loads it */ ],

  "creator": { "type": "user", "id": "uuid", "name": "string", "email": "string", "avatar": null },

  "created_at": "ISO 8601 | null",
  "updated_at": "ISO 8601 | null"
}
```

- **`status` is the whole state machine the client renders from** — `idle` (created, or between runs),
  `generating` (a worker holds the claim — the guarded `UPDATE` in
  `KnowledgeDraftSessionService::claim()` is what makes two clicks against one session impossible),
  `ready` (drafts are on the table), `failed` (a **resting** state, not a dead end — the session keeps
  its source text and instruction history, so retrying is the same action as refining).
- **`failure_reason` is a stable CODE, never prose** — the client owns the wording (room + locale); a
  server-composed sentence would be untranslatable copy baked into the API. `provider` also covers an
  infrastructure fault or a queue-job timeout (`GenerateKnowledgeDraftsJob::failed()`). `disabled` is
  the fifth, distinct value: the module's kill switch (`knowledge.index.enabled`) was flipped off
  after the run was queued (or a stray dispatch reached the job while it was already off) —
  `GenerateKnowledgeDraftsJob::releaseSession()` writes it as a literal string rather than through one
  of `KnowledgeDraftService`'s own `FAILURE_*` constants, so it is worth naming explicitly here rather
  than assuming it lives alongside the other four in one place in the code.
- **`prompt_history` is every instruction asked so far, oldest first**, capped at
  `knowledge.drafting.max_prompt_history` (20; the OLDEST are dropped, because the most recent
  instruction is the one actually being served) — it is replayed into every later prompt, which is
  also why one instruction is capped at `knowledge.drafting.prompt_max_chars` (2000) on the way in.
- **`context_expanded_at` is non-null exactly when `POST …/expand-context` will 422** (see below) —
  read it, do not compute it: a client-side comparison of "did I already click that" cannot see another
  tab or a second reviewer doing it first. A refinement clears it server-side.
- **`notes[]` is what the SERVER did to the model's answer on the last run — codes, never prose.**
  Collected by `DraftRunNotes` as the run executes, **14 emitted codes** today. Six describe what the
  server did to the TEXT and are described here; the other eight arrived with the measurement batch and
  are tabulated at [ADR-0050](../decisions/ADR-0050-knowledge-content-quality-measured.md) —
  `date_not_in_source`, `source_date_unused`, `date_incomplete`, `date_year_unsupported`,
  `facts_truncated`, `facts_unavailable`, `unknown_fact_handle`, `protagonist_without_entry`. (A
  fifteenth constant, `facts_not_covered`, is defined and deliberately never emitted — see the constant.)
  The six text-side codes: `entry_incomplete` (a proposal with no title or no body — not an entry, so it
  was dropped; `field` says which half was missing and `name` carries the model's own slug. Deliberately
  carries NO `slug`, because a slug-bearing note is filed under that entry's card and this proposal has
  none), `amend_append_only` (an entry the composer saw only in part may be appended to, never rewritten — the
  amendment was constrained), `amend_too_long` (appending would push the target past
  `knowledge.entry_max_chars`, so the proposal was dropped), `entry_too_many_chunks` (the FINAL published
  text — the live target's body plus the addition, for a shadow amend — would split into more passages
  than `knowledge.chunking.max_chunks_per_entry` allows, `context.max` names the cap; the proposal was
  dropped rather than accepted and then failing indexing in the background, invisible on any screen —
  this replaces the FormRequest's own chunk-cap dry run, withdrawn with hand-authorship and re-derived
  here), `wikilinks_lost` (a REWRITE proposal drops `[[wikilinks]]` the target currently carries —
  `context.links` names them; not a refusal, because a rewrite that removes a paragraph is supposed to
  remove that paragraph's links, and nothing here can tell an intentional removal from a careless one,
  but it has to be said out loud), and `resolution_degraded` (the entity resolution pass ran with less
  than its full apparatus — `context.reason`). **`wikilinks_lost` lives
  HERE, not in `graph_ops.warnings[]`** — it is a property of the run's TEXT, and everything the server
  did to a proposal's text is reported through this channel; `graph_ops.warnings[]` (below) is the
  GRAPH's own advisory half, and the split is deliberate: one fact, one channel, so a client checking
  both for the same thing never finds it in one and concludes the other broke. Rewritten WHOLESALE per
  run, same as `graph_ops`. There is **no separate "detect relations" state** — relation extraction
  happens INSIDE ordinary composition/refinement, not as a second, independently-triggerable pass (see
  ADR-0047 "Known limitations" — `relations_detected_at` does not exist).
- **`resolution` is WHO AND WHAT the material is about, matched against entries that already exist —
  frozen at session start, stable for the session's whole life, never recomputed by a refinement.**
  `entities[]` are the matched entries (or `degraded`-pass matches) available to the composer as
  amendment/relation targets; `ambiguous[]` is a name the base matched to SEVERAL entries — a QUESTION a
  UI must not skip, not an error; `unresolved[]` is a name the pass could not match to anything;
  `omitted[]` names entries that cleared relevance but did not fit the context budget; `degraded[]`
  non-empty means the pass ran with less than its full apparatus (budget, no vector support, a base past
  the scan limit) — an `unresolved` name there means "not looked for properly," not "not in this base."
- **Each resolved entity's `relations[]` is that entity's OWN typed relations, read from its side — the
  frozen shape a later run/accept resolves an `R<n>` handle against.** One item per `ResolvedRelation`:
  `handle` (`R<n>`, unique across the WHOLE frozen set, not per entity — a later stage can say "end R7"
  without also saying which entity it belongs to); `id` (the relation row this handle names — see
  below); `type`/`label` (the verb, already translated and already read in THIS direction — `direction`
  is `out`/`in`, and the consumer never has to flip it itself, which is the calculation that produces a
  confidently-backwards graph when done wrong); `other_handle` (the far end's OWN handle, present only
  when that entity is ALSO in this frozen set — pulling in every neighbour just to hand it a handle
  would turn a two-entity resolution into a subgraph, so the far end is usually just `other_title` with
  a `null` handle); `description`/`properties`/`valid_from`/`valid_to`/`state` are the relation's own
  fields, unchanged. **The SAME relation row keeps the SAME `handle` when it is listed under both of its
  resolved ends** (`R4` names one edge in the set, never two addresses for one row) — see ADR-0047
  defect (A1) for the bug this fixes.
  - **`id` is new on this shape, and exists for one reason: `resolveRelation()` used to re-derive the
    row a handle named by TYPE and LOWEST ID across the whole base — a guess that was silently wrong the
    moment a base had two active relations of the same type on the same entity.** Carrying the actual
    row id closes that (see ADR-0047 defect (A1) for the full defect and fix). **The model never sees
    it.** `KnowledgeDraftService::knownEntities()` renders this frozen set into the prompt FIELD BY
    FIELD (handle, label, other title, dates, properties, description, and so on) — nothing in the
    prompt-building path dumps the array wholesale — so `id` rides along in the same jsonb column the
    rest of `relations[]` lives in without ever reaching the model's context. This is the identical
    posture the set already took for `current_revision_id` on an entity: a database address the API
    response and the write path both need, present on the wire, never rendered into a prompt.
- **`graph_ops` is WHAT THE RUN PROPOSED TO DO TO THE GRAPH, laundered — nothing here has been
  applied.** `entities[]` are new entities to create (`N<n>` handles) — but since ADR-0048 **the model
  does not write this section at all**. It is SYNTHESIZED SERVER-SIDE, after laundering and after slug
  de-collision/seed-rename are final, from the session's own `entries[]` CREATE drafts: one `entities[]`
  item per draft that carries a `ref`, each stamped with `from_draft_slug` — the draft's OWN final slug,
  written exclusively by the server, never by the model. This is what makes an `N<n>` handle a REAL
  draft's address rather than a second, competing declaration of the same subject — see ADR-0048 for the
  duplicate-entity defect this closes and how `KnowledgeGraphOpsApplier::createDeclaredEntities()` BINDS
  to the published draft by that slug instead of creating a second entry when `from_draft_slug` is
  present (a handle with no marker still creates from scratch — the path kept for sessions frozen before
  this change). Capped at `knowledge.drafting.max_new_entities` (**16**, env
  `KNOWLEDGE_DRAFT_MAX_NEW_ENTITIES`, deliberately equal to `max_entries_per_session` now that entities
  are synthesized 1:1 from drafts rather than declared independently by the model — a mismatched pair of
  caps would have let one silently starve the other). Overflow past the cap is REPORTED
  (`op_cap_reached`, `scope: entities`) rather than dropped in silence — silently dropping the tail of a
  declared entity list used to mean the relations naming those entities failed later with
  `dependency_not_accepted`, a code that points at the reviewer rather than at the cap that actually
  caused it. **`op_cap_reached`'s context key is `max` in all three scopes** —
  `entities`/`wiki_updates`/`graph_updates` — a prior inconsistency (`entities` alone carried `cap`) has
  been unified. `wiki_updates[]` is ONLY a new entity's own initial content now (a content change to an
  EXISTING entity is a shadow draft instead — see ADR-0047 defect (c)); `graph_updates[]` are relation
  operations (`create`/`update`/`end` — `retract`/`delete` never appear, see "Typed relations" above);
  `rejected[]` is a SECTION OF THE REVIEW, not a diagnostic log — **13** possible codes (`unknown_handle`,
  `unknown_relation_type`, `type_not_allowed`, `self_loop`, `forbidden_op`, `unknown_op`,
  `properties_refused`, `duplicate_relation`, `pair_refused`, `op_cap_reached`, `template_directive`,
  `malformed`, `dates_reversed` — `op_cap_reached` alone can carry any of the three scopes,
  `entities`/`wiki_updates`/`graph_updates`). **`dates_reversed`** (`{code, type, valid_from, valid_to}`)
  is checked on `create` ONLY, matching the write-path service exactly, and is the SAME string in all
  three layers this rule lives in — laundering here, `KnowledgeRelationService::assertDates()` at
  write time, and the `knowledge.relations.dates_reversed` i18n key — deliberately, so a client can key
  off one value whether the refusal arrived as a laundering rejection (before a reviewer ever saw the
  operation) or as a direct `POST .../relations` 422 (see "Typed relations" → `POST .../relations`
  below). **`end` is exempt here too, mirroring the service** — a closing date before a relation's
  `valid_from` means the START was wrong, not the ending, and refusing would leave a statement nobody
  can retire; `update` never carries `valid_to` at all, so the case does not arise there either.
  `warnings[]`
  is the advisory half, about the GRAPH ONLY — **4** codes: `pair_unchecked` (advisory type-pair match —
  see "The pair matrix is advisory" above), `ambiguity_unresolved` (a mention the resolution pass raised
  that this run never settled), `moved_to_review` (a content change aimed at an existing entity became a
  shadow draft instead of writing directly — see defect (c) below), and `replaces_unbound` (a `create`'s
  `replaces` named an `end` that did not survive laundering — the relation itself is still proposed,
  only the replacement annotation is dropped). **What the server did to a proposal's TEXT is never
  here** — an append forced in place of a rewrite, or a rewrite that drops wikilinks, are run `notes[]`
  (above), a different channel for a different fact. **Entity handles (`N<n>`) and relation operation
  keys (`graph:<n>`, computed from array position — see `POST .../accept` below) are two different
  addressing schemes** and are not interchangeable.
- **`duplicates` is keyed by DRAFT entry id**, read from a cache the relations endpoint populates —
  empty until `GET …/relations` has run at least once for this session. Never computed inline on this
  resource (an embedding batch inside a serializer would be an uncontrolled spend).
- **`drafts` is the ORDINARY `KnowledgeEntryResource`, not a second shape.** A draft's card gets
  `title`/`content`/`metadata`/`links`/`current_revision_id` for free — including the draft-only fields
  documented above (`draft_session_id`, `targets_entry`, `target_revision_id`,
  `target_revision_stale`).
- **There is no `drafts_count` field.** It was declared on an earlier revision of this resource
  (`whenCounted('drafts')`) but no controller path ever called `loadCount('drafts')`/`withCount('drafts')`
  on a session, so it was always absent from the wire — a dead field with nothing counting it. Removed
  outright rather than left as documented-but-inert. The client counts drafts client-side from the
  `drafts` array it already has loaded.

### Draft diff response — `GET /api/knowledge/entries/{entry}/draft-diff`

```json
{
  "baseline": "original | previous | target",
  "target_revision_stale": false,
  "has_baseline": true,
  "from": { "revision_id": "uuid", "title": "string", "content": "string", "created_at": "ISO 8601" },
  "to": { "revision_id": "uuid", "title": "string", "content": "string", "created_at": "ISO 8601" }
}
```

Bound with `withDrafts()` — the whole point is inspecting an entry the ordinary `{entry}` binding
cannot see. `?baseline=` selects which TEXT is compared against `to` (the draft's current state):

| `baseline` | What `from` is | Valid on |
|---|---|---|
| `original` (default) | The draft's OLDEST revision — the first thing the composer ever generated for this slug. | Every draft. |
| `previous` | The revision immediately before the current one — "what did my last refinement change". | Every draft; `has_baseline: false` on a first generation (nothing precedes it). |
| `target` | The entry this SHADOW amends, at the revision the composer actually read (falls back to the target's current revision if that one was purged). | **Shadow drafts only** — see below. |

- **`baseline=target` on a draft that is not a shadow silently falls back to the `original` computation
  while the response still echoes `"baseline": "target"`.** The endpoint's own match arm only computes
  the target comparison when `isShadow()` is true; a non-shadow request for it does not error, it
  answers with the original-vs-current diff under the requested label. The client is expected to gate
  the `target` option on `isShadow` the same way the UI spec always assumed — never offer it on a plain
  draft.
- **`target_revision_stale` is populated on every response**, not only when `baseline=target` was
  requested — it always reflects whether accepting THIS shadow (if it is one) would hit the optimistic
  lock right now. A non-shadow draft reads `false`. The client does not need to compare
  `current_revision_id`s itself anywhere; this field is the single source of truth for the "baseline
  out of date" badge, checked on every fetch/refetch of the session (there is no separate polling of
  this endpoint for staleness).
- **This is NOT a computed diff.** Both `from.content` and `to.content` are full texts; the actual
  line/word diff is computed client-side (`ui/data/textDiff.ts`) — see
  `resources/js/next/docs/pages/KnowledgePage.vue` §"Write discipline" for why rendering stays a
  frontend concern in this module.

### Draft relations response — `GET /api/knowledge/draft-sessions/{session}/relations`

Wrapped in `{"data": …}`, and **close to, but NOT identical to, the shape `KnowledgeGraphResource`
returns** (`{ center, nodes[], edges[], ghosts[], truncated }`) — close enough that the frontend reuses
its graph canvas/layout/legend for a proposal preview instead of building a second visualization, but
with real differences from the live graph that a client built strictly off the base graph's shape would
get wrong:

- **`nodes[]` here has NO `entry_type`.** The base graph's nodes carry it (see "Typed relations" above);
  this preview's node builder (`KnowledgeDraftRelationService::payload()`) does not read it at all —
  its own comment calls out exactly two additions over the base shape, `is_draft` and `amended_by`, and
  `entry_type` is not a third. A client cannot render a type badge from this endpoint's nodes.
- **`edges[]` here has NO `kind` discriminator.** Every edge this endpoint returns through `edges[]` is a
  DERIVED, soft edge (`source: wikilink | mention | similarity`) — the equivalent of a base-graph `link`
  row — and none of them carry `kind`, unlike the base graph's `edges[]` where `kind` is what tells a
  `link` apart from a `relation`. (The `edge()` builder's own comment — "present so the shape matches
  the base graph's edges exactly" — is stale here and does not describe the current response; it
  predates the base graph gaining `kind`.) The typed-relation half of this proposal is a SEPARATE array,
  `proposed_relations[]` (below), each item of which DOES carry `kind: 'relation'` — so a client wanting
  one merged list of "everything drawable" has to build it itself from `edges[]` (implicitly `kind:
  'link'`) plus `proposed_relations[]` (explicitly `kind: 'relation'`), rather than reading a single
  discriminated array the way the base graph allows.
- **Two additive TOP-LEVEL fields**, `duplicates` and `vector_skipped`, plus the separate
  `proposed_relations[]`/`proposed_entities[]` arrays documented below.

```json
{
  "data": {
    "center": null,
    "nodes": [{
      "id": "uuid", "slug": "string", "title": "string", "status": "approved",
      "is_stale": false, "degree": 2, "distance": null,
      "is_draft": true,
      "amended_by": [{ "draft_id": "uuid" }]
    }],
    "edges": [{ "id": null, "from": "uuid", "to": "uuid", "source": "similarity", "score": 0.91, "evidence": null, "dismissed": false, "can_be_dismissed": false }],
    "ghosts": [],
    "truncated": { "hidden_nodes": 0, "hidden_edges": 0 },
    "duplicates": { "<draft-entry-id>": { "slug": "string", "title": "string", "score": 0.91 } },
    "vector_skipped": "budget | disabled | unsupported | error | null",
    "proposed_relations": [{
      "key": "graph:0", "pair_with": "graph:1 | null", "replaces": "R7 | null",
      "kind": "relation", "id": null, "op": "create", "from": "E1", "to": "N1",
      "relation": null, "from_title": "string", "to_title": "string",
      "relation_type": "works_on", "description": "string | null", "properties": {},
      "valid_from": "yyyy-mm-dd | null", "valid_to": null,
      "depends_on_draft": ["N1"]
    }],
    "proposed_entities": [{ "id": null, "handle": "N1", "title": "string", "slug": "string | null", "entry_type": "person | null", "is_draft": true }]
  }
}
```

- **`center` is always `null`** (a proposal preview is an overview, never a walk from one entry) and
  **`ghosts` is always `[]`** — an unresolved `[[link]]` inside a draft is not a red link in the base
  until the draft is accepted, so reporting it as a ghost here would show work as broken that nobody
  has done.
- **Nothing here is written to `knowledge_links`.** Every edge is a PREVIEW, computed on demand from
  the session's current drafts and cached on the session (`relations_cache`, keyed by a digest of every
  draft's text plus `knowledge.links.version` + `knowledge.chunking.version`) — re-opening the panel
  unchanged costs no AI at all; editing a draft or bumping either version invalidates it.
  `edges[].id` is therefore always `null` and `edges[].can_be_dismissed` is always `false` — nothing
  materialized can be dismissed.
- **`is_draft: true`** marks a circle that is a proposal rather than something that already exists. A
  SHADOW draft never gets its own node and never an edge — see `amended_by` below.
- **`amended_by` is ALWAYS present (empty array when none) and is a LIST**, `[{draft_id}]` — not a
  boolean flag. A shadow draft's pending amendment is recorded as an ANNOTATION on the entry it
  targets rather than as an edge (an edge to a shadow would have an endpoint, the shadow itself, that
  is not in `nodes[]`, breaking the graph's own "every edge references a node" invariant). It is a list
  because more than one pending shadow against the same target is representable (two sessions could,
  in principle, each propose an amendment to the same entry) even though today's frontend only reads
  the first entry (`amended_by[0]`) for its "open the amendment" affordance.
- **`vector_skipped`** is one of the same four reasons search/retrieval already use
  (`budget | disabled | unsupported | error`), or `null` when the embedding batch ran. Fail-soft: the
  deterministic edges (wikilinks, mentions, the amendment annotation) are always present regardless —
  only the similarity legs are what a skip removes.
- **`duplicates`** here is the SAME map the session resource's own `duplicates` field carries — this
  endpoint is what populates that cache in the first place.
- **`proposed_relations[]` and `proposed_entities[]` are the run's `graph_ops` rendered as edges/nodes
  that do not exist yet** — a reviewer deciding whether to accept a proposal is deciding about the graph
  half exactly as much as the prose. `key` is the SAME `graph:<n>` operation key `POST .../accept` takes
  back in `graph_op_keys` (see above) — a client MUST read it from here, never compute its own, because
  a refinement can renumber the operations underneath a held preview. `pair_with` is the OTHER half of a
  bound `create`/`end` replacement pair, or `null` — both operations of a pair carry it, pointing at each
  other, so a client groups them by reading one field instead of matching `replaces` against every `end`
  itself; the UI should offer a bound pair as ONE control, because `accept` refuses a selection that
  splits one (`422 inseparable_ops`). `depends_on_draft` lists which end (if any) is a `N<n>` handle this
  same run also proposes to CREATE — that relation cannot be applied until the named draft is accepted
  too; a reviewer who accepts the relation alone without it gets `dependency_not_accepted` in `skipped[]`
  (see `POST .../accept` above), and this field is what lets a client warn about that BEFORE the click
  rather than after a 200-with-a-skip. `proposed_entities[]` carries `id: null` and `is_draft: true` —
  circles the canvas draws in the same pass as real nodes, keyed by `handle` (`N<n>`) rather than by id.

---

## Endpoints

All under `/api/knowledge`. Literal segments (`search`, `bases`, `links`, `restore`, `force`) are
declared before wildcards throughout; every id parameter is `whereUuid`. (`reorder` was one such literal
segment too, on the entries collection — withdrawn along with the ability itself, see "This module is
read-only for humans" above.)

**Most responses below are a Laravel `JsonResource`/`ResourceCollection`, wrapped in `{"data": …}`**
(the app never calls `JsonResource::withoutWrapping()`). Endpoint descriptions do not repeat "wrapped
in data" for this reason — assume it **unless a description says otherwise**, which is the case for
the following, all of which build their body BY HAND rather than through a `JsonResource`:

| Endpoint | Body |
|---|---|
| `GET .../compose-availability` | Flat — `{ can_compose, reason, budget, limits }`, no `data` key. |
| `POST .../draft-sessions/{session}/accept` | Flat — `{ accepted: […], conflicts: […] }`, no `data` key (`accepted[]` items ARE individually-wrapped `KnowledgeEntryResource`s, but the envelope around them is not). |
| `GET .../entries/{entry}/draft-diff` | Flat — `{ baseline, target_revision_stale, has_baseline, from, to }`, no `data` key. |
| `GET .../draft-sessions/{session}/relations` | Wrapped by hand (`response()->json(['data' => …])`) — same `{"data": …}` shape as a `JsonResource` would produce, but constructed literally rather than through one. |
| `POST /bots/{bot}/knowledge/migrate` | Flat — `{ knowledge_base_id, name, entries_count, mode }`, no `data` key (see "Bot binding" below). |

Every response NOT listed above (bases, entries, revisions, links, search, the graph, and every other
composer endpoint — `store`/`show`/`refine`/`rebase`/`expand-context` all return a
`KnowledgeDraftSessionResource`, `rebase` a `KnowledgeEntryResource`) goes through the framework's
ordinary `JsonResource` wrapping.

**Cursor pagination** — `GET /knowledge/bases` and `GET /knowledge/bases/{base}/entries` are the
only two cursor-paginated list endpoints in this module (25/page). Request the next page with
`?cursor=<token>` (the query param Laravel's `CursorPaginator` reads by default,
`AbstractCursorPaginator::$cursorName`); the envelope is the framework's unmodified default for a
paginated resource collection:

```json
{
  "data": [ /* … */ ],
  "links": { "first": null, "last": null, "prev": "https://…?cursor=… | null", "next": "https://…?cursor=… | null" },
  "meta": { "path": "https://…", "per_page": 25, "next_cursor": "string | null", "prev_cursor": "string | null" }
}
```

`meta.next_cursor: null` means there is no next page. No endpoint in this module overrides
`paginationInformation()`, so this shape is exact, not illustrative. `GET /entries/{entry}/revisions`
is **not paginated** — it returns the entry's whole revision history as a plain `data` array (see
"An entry's revisions" below); `GET /knowledge/search` / `GET /knowledge/bases/{base}/search` are
also not cursor-paginated and carry their own, different `meta` shape (see "Search response
envelope" above).

### Bases — `/api/knowledge/bases`

| Method & path | Purpose |
|---|---|
| `GET /` | List. `?trashed=1` → the workspace's trashed bases instead of live ones. `?search=` (name/description). Cursor-paginated, 25/page. |
| `POST /` | Create. Body: `name` (required, ≤255), `description` (≤2000), `charter` (≤10000), `metadata_schema` (array, ≤50 fields), `language` (≤5, `xx` or `xx-XX`; defaults to the request's app locale). |
| `GET /{base}` | Show. |
| `PATCH /{base}` | Update. **Absent field = unchanged** — a PATCH carrying only `{name}` leaves the charter/schema untouched. Governed fields (`charter`, `metadata_schema`) require `manage`; everything else requires only `update`. Sending a governed field that would actually CHANGE its stored value without the `manage` ability → **403**, never silently dropped. |
| `DELETE /{base}` | Trash the base and, reversibly, everything currently live inside it (see Concepts → cascade in ADR-0043). `204`. |
| `POST /{id}/restore` | Restore. Resolved via `withTrashed()` (a raw `{id}`, not route-model-bound — binding would 404 a soft-deleted row). Restores the base and exactly the entries that fell with it. |
| `DELETE /{id}/force` | Permanent purge — the base, every entry, revision, chunk, and link beneath it. `204`. |

### Entries of a base (collection) — `/api/knowledge/bases/{base}/entries`

| Method & path | Purpose |
|---|---|
| `GET /` | List, in the base's manual `position` order. Filters: `status[]`, `stale=1`, `search=`, `trashed=1` (the base's own entry-level trash — read-only: entries still fall INTO it via the composer's own `delete()` cascades and the erasure command, but nothing restores one out of it individually any more, see below). Cursor-paginated, 25/page. |
| ~~`POST /`~~ | **WITHDRAWN** — no route creates an entry by hand. `POST /knowledge/bases/{base}/entries` still exists as an internal call target ("The AI composer" below): it is what the composer's own **acceptance** step calls, never a human-facing endpoint. See [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md). |
| ~~`POST /reorder`~~ | **WITHDRAWN.** `KnowledgeEntryService::reorder()` no longer exists as a method — not only as a route. `position` is now set exclusively by the composer, at acceptance, through `nextPosition()`; nobody may re-arrange a base's canonical order by hand. |

### One entry (flat) — `/api/knowledge/entries`

Flat, not nested under the base — an entry's id is a uuid, so nesting the item routes would add a
second segment never used for lookup that could disagree with the first. Every row below is relative
to this prefix (e.g. `GET /{entry}` is `GET /api/knowledge/entries/{entry}`).

| Method & path | Purpose |
|---|---|
| `GET /{entry}` | Show. Eager-loads `links`/`backlinks`/`creator`, plus `indexed_chunks_count`. |
| ~~`PATCH /{entry}`~~ | **WITHDRAWN** — no route edits an entry's text by hand. The nearest surviving action is asking the composer to refine or re-amend the entry and accepting the new proposal. See "The `aliases` write contract" and "Rules that lost coverage" below for what this removed besides the endpoint itself. |
| `POST /{entry}/retry-index` | Re-queue indexing for an entry stuck `failed` / `pending_budget` / `partial`. **422** for any other state. Returns the entry with its index status already moved to `pending` (no refetch needed). Gated on its own named ability, `retryIndex` (any member) — split out from `update` (which now denies everyone) precisely so this endpoint did not go down with hand-authorship; see [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md) D4. |
| ~~`DELETE /{entry}`~~ | **WITHDRAWN.** Refusing the composer's PROPOSAL (`DELETE .../entries/{entry}/draft`, "The AI composer" below) is the equivalent act, and it comes first — nothing reaches the base to trash in the first place unless a person already accepted it. |
| ~~`POST /{id}/restore`~~ | **WITHDRAWN.** Entry-level restore is gone entirely, not merely refused for a live entry: `KnowledgeEntryService`'s own read-path comment states it plainly — "Nothing brings them back one at a time any more — restoring the BASE restores what it holds, and that is the only route left." See `POST /bases/{id}/restore` above. |
| ~~`DELETE /{id}/force`~~ | **WITHDRAWN** as a human-facing endpoint. Permanent purge of an entry survives only through `knowledge:purge-subject` (see "Data erasure" below) and through a base's own `DELETE /bases/{id}/force` cascade. |

**Entry write fields are withdrawn as an HTTP contract.** There is no `POST`/`PATCH /entries` left for
title/content/metadata/aliases/status/stale_at/slug/change_note to be sent to — the whole table that used
to live here (create-required vs. update-optional, the 40000-char cap, the metadata schema check, the
directive guard, the chunk-cap dry run) described a FormRequest that no longer exists
(`StoreKnowledgeEntryRequest`/`UpdateKnowledgeEntryRequest`, both deleted). Most of the underlying DOMAIN
RULES still govern what enters a base — they were followed to wherever they are enforced now (mostly the
composer's own laundering, `KnowledgeDraftService`) and re-pinned there, in
`tests/Feature/KnowledgeEntryRulesTest.php` (replacing the deleted `KnowledgeEntryCrudTest`). Three
rules did **not** get a like-for-like replacement and one that looked lost was in fact fixed; see
[ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md) → "Rules that lost coverage" for the
full account, summarized here:

| Old HTTP behaviour | Current behaviour | Where it lives now |
|---|---|---|
| Missing required `title`/`content` → 422 | The proposal is DROPPED, and since the debt sweep the drop is **reported**: `entry_incomplete` in `notes[]`, carrying `field` (`title`/`content`) and `name` (the model's own slug, or the surviving title). No longer silent, and pinned. | `KnowledgeDraftService::launder()`, pinned (`KnowledgeEntryRulesTest::test_a_proposal_without_a_title_is_dropped_and_reported`, `::test_a_proposal_without_a_body_is_dropped_and_reported`, `::test_the_drop_is_reported_against_the_run_and_not_a_card`, `::test_a_complete_proposal_raises_no_such_note`) |
| Content over `knowledge.entry_max_chars` → 422 | TRUNCATED at the cap, not refused. | `launder()`, pinned |
| A metadata field of the wrong type / an undeclared key → 422 (whole request refused) | That ONE field is DROPPED; the rest of the entry survives. | `KnowledgeDraftService::metadata()`, pinned |
| A required (non-nullable) metadata field absent → 422 | No longer enforced — laundering has no notion of "missing". | `KnowledgeDraftService::metadata()`, pinned as a characterized loss |
| Directive syntax (`@[…]`, `{{…}}`, …) in title/content → 422 under the field's key | Still FAIL-CLOSED — the WHOLE draft is dropped (not just the field), because the body is what gets injected into another prompt. | `launder()`, pinned |
| Chunk-cap dry run (`too_many_chunks`) → 422 | Re-derived, not lost: the composer's own laundering asks the same question against the FINAL text and drops the proposal, reporting `entry_too_many_chunks` in `notes[]` (see "notes[] is what the SERVER did…" above). | `KnowledgeDraftService::wouldBurstChunkCap()`, pinned |
| A rename onto a taken slug → 422 `slug_taken` | Re-derived at the SERVICE (`KnowledgeEntryService::update()` now checks `slugTaken()` before applying an explicit rename) — found to be a genuine, unfixed gap during this same batch's hardening and closed; see ADR-0049 defect (j). Not reachable from any live HTTP caller today (the composer's own `publishAmendment()` never sets `slug`), but the barrier exists at the layer a future caller would actually go through. | `KnowledgeEntryService::update()`, pinned |

### The `aliases` write contract — now composer-only

`aliases` — other surface forms this entry is called by (inflections, synonyms) — feeds **only** the
mention layer (`KnowledgeMentionLinker` scans an entry's title AND its aliases; see "Mention edges"
below). It never resolves a `[[wikilink]]`: the slug remains the one and only address a link may
point at — nothing anywhere in `WikilinkParser`/`KnowledgeLinkService` reads `aliases`.

**The HTTP-refusing half of this contract is withdrawn along with `POST`/`PATCH /entries`.** There is no
route left where an 11th alias or a blank alias 422s — that behaviour described a FormRequest that no
longer exists. What survives is the AI composer's own reply-laundering (`KnowledgeDraftService`), where
the `EntryAliases` normalizer **TRUNCATES silently at 10 and drops anything not a non-empty string** — a
model returning fifteen surface forms for one title is being enthusiastic, not malicious, and there is no
form for a person to be shown a refusal on. Also normalized silently, without any note: leading/trailing
whitespace is trimmed and aliases are de-duplicated **case-insensitively** (`" Wieża "`, `"wieża"` and
`"WIEŻA"` collapse to one, keeping the first form's original casing). Pinned by
`KnowledgeEntryRulesTest::test_aliases_are_trimmed_deduplicated_and_capped`.

### Optimistic locking — `409 knowledge_stale_write`

Send `expected_revision_id` (the value read from `current_revision_id`) to get a `409` instead of a
silent overwrite when somebody else saved in between:

```json
{ "code": "knowledge_stale_write", "message": "…", "current_revision_id": "uuid" }
```

Reachable today only through the composer's own accept path (`publishAmendment()`, "The AI composer"
below) — there is no human-facing write endpoint left that could hit it directly. A `null`/omitted
`expected_revision_id` skips the check entirely.

### An entry's revisions — `/api/knowledge/entries/{entry}/revisions`

| Method & path | Purpose |
|---|---|
| `GET /` | **Not paginated** — the entry's WHOLE revision history, newest first, as a plain `data` array (`KnowledgeEntryService::revisions()` calls `->get()`, never `cursorPaginate()`). |
| ~~`POST /{revision}/restore`~~ | **WITHDRAWN.** Republishing an old body under the current entry is authoring its text by another route — see "Revisions are append-only" above and [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md). The history stays fully READABLE through the `GET` above; nothing brings an old version back onto the live entry any more. |

### Graph — `GET /api/knowledge/bases/{base}/graph`

Two modes in one endpoint: **overview** (no `?entry=`, the base's most-connected entries + edges
among them, filled out with recently-updated isolated entries to reach the cap) or **ego** (`?entry=`,
a breadth-first walk from one entry, edges unioned in both directions — a similarity edge is
materialized on only the side re-indexed most recently, so unioning is what makes "related" symmetric
at the point a human looks at it).

| Param | Values | Effect |
|---|---|---|
| `entry` | uuid | Selects ego mode. Must belong to this base — resolved through it, or `404`. |
| `depth` | 1–2 (hard cap `KnowledgeGraphQuery::MAX_DEPTH = 2`) | Ego hops. At depth 3 a well-linked base's ego graph is the whole base with a much more expensive walk. |
| `sources[]` | `wikilink` / `similarity` / `mention` / `manual` | Default: `wikilink` + `similarity` + `mention` (the three DERIVED-from-content kinds). `mention` is a default rather than an opt-in — a base whose author never typed a wikilink has essentially no other edges, so hiding it behind a toggle would leave the default view empty. `manual` is excluded by default because it is not derived. Also accepts a comma-flattened `sources=wikilink,manual`. |
| `min_score` | -1..1 | Default: `knowledge.similarity.threshold` (0.86) — every stored similarity edge already cleared it, so the default hides nothing. Applies only to LINK edges that carry a score — **never applied to a `relation`**, which has no score because it is a statement somebody approved, not a measurement (tightening the similarity floor must never silently erase a fact). |
| `include_dismissed` | boolean | Include dismissed similarity/mention LINK edges (with `edges[].dismissed: true`). No effect on relations, which are never dismissed (see `KnowledgeGraphResource` above). |
| `relations` | boolean | Typed relations (`kind: relation`) — **ON by default**, opposite of `manual`'s default-off, because a relation is an approved fact, not a guess a reader opted into seeing. Set `relations=0` to draw links only. |
| `include_historical` | boolean | Include `ended`/`retracted` relations (with their real `state`). **Off by default** — the graph answers "what is true now"; ended/retracted relations are the graph's own analogue of the panel's "show ended" toggle. No effect on links. |

Capped at `knowledge.graph.max_nodes` (60) — what survives the cap and what was cut is always
reported (see `KnowledgeGraphResource` above).

### Search

| Method & path | Purpose |
|---|---|
| `GET /api/knowledge/search` | Every base in the workspace. Each hit carries `base`. |
| `GET /api/knowledge/bases/{base}/search` | One base, authorized against it directly. |

Both share `KnowledgeSearchRequest`: `q` (required, ≤500 — a spend control, the query is embedded),
`status[]` (defaults to every status but `archived`), `limit` (≤`knowledge.search.max_results`, 25).
See "Search: two legs" above for the fused-response shape.

### Links — dismiss / undismiss

| Method & path | Purpose |
|---|---|
| `POST /api/knowledge/links/{link}/dismiss` | Reject a machine-proposed edge. **Stamps** `dismissed_at`, never deletes — a re-index that re-derives the same similarity would otherwise re-propose an edge the user just rejected. **422** (`knowledge.links.not_dismissable`) for any edge whose `can_be_dismissed` is false — i.e. anything but the machine-proposed kinds (`similarity`, `mention`); a `wikilink` is what the text SAYS (edit the `[[…]]`), a `manual` edge was drawn deliberately (delete it, don't mark it rejected). |
| `DELETE /api/knowledge/links/{link}/dismiss` | Undo — the edge returns live immediately with the SAME score/evidence it already had (never re-derived, which would make the undo unavailable until the next re-index). |

Either verb, gated on any workspace member (`KnowledgeLinkPolicy::dismiss`) — dismissing a wrong
suggestion has to be a one-click act for whoever is looking at it, not routed through ownership.

### Typed relations — `/api/knowledge/{...}`

Full design rationale: [ADR-0047](../decisions/ADR-0047-knowledge-typed-relations.md) (the storage,
matrix and lifecycle) and [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md) (why every
mutation below is withdrawn). See `KnowledgeRelationResource` above for the response shape.

| Method & path | Purpose |
|---|---|
| `GET /knowledge/entries/{entry}/relations` | Every relation touching this entry, either direction. `?include_historical=1` for ended/retracted too. |
| ~~`POST /knowledge/bases/{base}/relations`~~ | **WITHDRAWN**, including the link-PROMOTION path (`promote_link_id`) it used to carry — see below. |
| ~~`PATCH /knowledge/relations/{relation}`~~ | **WITHDRAWN.** |
| ~~`POST /knowledge/relations/{relation}/end`~~ | **WITHDRAWN**, taking its 3-shape body (end/supersede/retract) with it. |
| ~~`DELETE /knowledge/relations/{relation}`~~ | **WITHDRAWN** as a human-facing endpoint. |

**No route asserts, edits, ends, supersedes, retracts or deletes a relation by hand any more.**
`KnowledgeRelationService::create/update/end/supersede/delete` all still exist and still run — they are
what `KnowledgeGraphOpsApplier` calls the moment a person **accepts** what the composer proposed (see
"The AI composer" below) — but there is no FormRequest, no controller action, and (per
[ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md) D3) no policy ability that reaches
any of the five from outside that one call path. The service's own class docblock states it directly:
*"there is no relation FormRequest any more: there is no hand-written relation. What a request layer
used to judge (shapes, formats, date ordering) is judged in the LAUNDERING, which is what stands between
a model's answer and this class."*

**Link-promotion (`promote_link_id`) died with `POST /relations`, and the code says so rather than
leaving a silent hole:**

```php
// NO PROMOTION BRANCH. Promoting a machine suggestion into a hard relation was a person's
// action, through `POST /relations`, and that endpoint no longer exists — so every relation
// written here comes from an accepted proposal and is logged as an ordinary `create`.
```

`KnowledgeRelationOrigin::PROMOTED` and `::HUMAN` are therefore historical values only — see "Typed
relations" → `origin` in Concepts, above. **The matching `KnowledgeRelationEvent::OP_PROMOTE` constant
was DELETED** in the ADR-0043–0050 debt sweep: it had been kept "because historical rows carry it", and
they do not — nothing has ever emitted it in a shipped build, and the only database that has run this
module holds `create` events exclusively. The two `origin` cases are kept on the opposite reasoning
(they are an Eloquent cast on a stored column and a published API value, so removing a case makes the
row that carries it throw on read); the constant was a write-side name with no writer, which sends the
next reader hunting for one.

**What the composer may still do, mediated entirely through `graph_ops`/`accept` (documented in full
under "The AI composer" below) — `create`, `update`, `end`, and `supersede` reached indirectly through a
bound `create … replaces`.** `retract` and the hard `DELETE` are reachable by **nobody** in the shipped
product: `KnowledgeGraphOps` drops `delete`/`retract`/`remove`/`destroy` unconditionally from a model's
proposed operations however well-formed (ADR-0047 D5), and `KnowledgeGraphOpsApplier` never calls
`retract()`. `delete()` survives as a service method reachable from exactly one place: the
`knowledge:purge-subject` erasure command ("Data erasure" below), which needs hard removal on a
subject's behalf.

**The six `422` refusal codes this endpoint used to answer directly are unchanged in NAME and MEANING,
but no longer arrive as an HTTP `422` from a request a person sent.** They now surface as
`{code: 'refused', reason: '<name below>', ...context}` inside `POST .../accept`'s `skipped[]` (a
proposal the base declines at acceptance time — see "The AI composer" below) or, earlier and more often,
as a `graph_ops.rejected[]` entry the reviewer never gets to select in the first place (see "notes[]"/
"graph_ops" in Concepts, above):

| Reason / code | Meaning |
|---|---|
| `knowledge_relation_duplicate` | An identical ACTIVE relation already exists (same pair, verb, `valid_from`). |
| `knowledge_relation_cap_reached` | Either entry is already at `knowledge.relations.max_relations_per_entry` (**200** — see "Typed relations" above for why a subject-shaped graph needs the higher cap). History does not count — closed episodes are born `ended`. |
| `knowledge_relation_pair_refused` | Both ends are TYPED and this verb does not join those types — the ONE hard matrix refusal. |
| `knowledge_relation_type_not_allowed` | The base's own `relation_types` allow-list excludes this verb. |
| `knowledge_relation_property_refused` | `properties` carries a key this verb does not declare. |
| `knowledge_relation_dates_reversed` | `valid_to` is before `valid_from`. Checked on `create` only — never on `end`, for the same reason stated in "graph_ops" above (a bad closing date means the START was wrong, not the ending). Enforced in the SERVICE (`KnowledgeRelationService::assertDates()`), the one place both the (withdrawn) HTTP path and the composer's write path always shared. |

The two entries and the verb of an existing relation are still never editable through `update` (changing
either would make the row an assertion about something else while keeping its id and its audit trail);
the honest path for that remains `supersede`, reachable exactly as described above.

---

## The AI composer — `/api/knowledge/...`

Full design rationale: [ADR-0046](../decisions/ADR-0046-knowledge-ai-composer.md). Since the owner's
AI-only pivot, this is how every entry in the product comes into existence — the frontend routes every
"new entry" affordance here; the plain `POST /knowledge/bases/{base}/entries` documented above is
unchanged and is what the composer's own acceptance step calls, never a deprecated seam.

**This is no longer only a UI convention — read ADR-0046's own D11 as historical.** ADR-0046 originally
described AI-only authorship as "a UI policy, not a server invariant a script or a future consumer must
obey," because at that time a script or another consumer COULD still call `POST`/`PATCH /entries`
directly; the door was merely not shown in the UI. [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)
closes that door at the server: the route is gone and the policy denies, for every caller, script or
person alike. "This module is read-only for humans" (above) is the current, authoritative statement of
the rule.

**The composer writes SUBJECTS, not narratives, since [ADR-0048](../decisions/ADR-0048-knowledge-subject-paradigm.md).**
An entry's title now has to name something that goes on existing after the material — a person, a
place, an organisation, a named event — never an episode ("Trip to Paris"): the episode becomes a dated
line inside the subjects it happened to, plus a relation between them. Every draft carries a `ref`
(`N<n>`) and, on a `create`, an `entry_type` (see "Typed relations" above), and the composer states
relations between its own subjects (`graph_updates`) on EVERY run, empty base included — see the ADR for
why this used to be impossible on a fresh base and for the two independent defects that made the graph
look broken (a stale queue worker, and a cold-start deadlock in the model contract).

A DRAFT is an ordinary `KnowledgeEntry` row carrying `draft_session_id` — invisible everywhere in the
product through a global model scope (`WithoutDraftsScope`) except the routes below, all of which
explicitly opt back in (`withDrafts()`). Gated on `create` ability for `KnowledgeEntry` throughout — the
composer produces entries reviewed by a human before anything becomes visible, so it needs no ability
beyond what typing the same entries by hand would need.

**"Invisible" means invisible to ordinary PRODUCT READS (the entry list, search, the graph, the trash,
a bot's context) — a drafting session is NOT private to the person who started it.** Every composer
endpoint below is gated the same way ordinary entry mutation is (see Authorization below): any
authenticated workspace member who can `view` the base can open, poll, refine, accept, reject, rebase,
or abandon **any other member's** session, exactly as any member may already edit any other member's
published entry (ADR-0043 D7). None of the FormRequests behind these routes check who created the
session — `StoreKnowledgeDraftSessionRequest`, `RefineKnowledgeDraftSessionRequest`,
`AcceptKnowledgeDraftsRequest`, `RebaseKnowledgeDraftRequest` and `ExpandKnowledgeDraftContextRequest`
all authorize on `$this->user()?->can('create', KnowledgeEntry::class)` plus the route model resolving,
never on session ownership. A colleague seeing an open composer tab (or its deep link) can pick up
where another member left off — reviewing, revising, or discarding it — which is a deliberate
extension of the module's existing collaborative-editing stance, not an oversight.

| Method & path | Purpose |
|---|---|
| `GET /knowledge/bases/{base}/compose-availability` | Whether the composer can run at all, asked BEFORE the source form renders. |
| `POST /knowledge/bases/{base}/draft-sessions` | Open a session on raw material; queues the first composition. **201**, session already `generating`. |
| `GET /knowledge/draft-sessions/{session}` | The poll — same shape throughout the session's life. |
| `POST /knowledge/draft-sessions/{session}/refine` | Ask for a revision of the WHOLE set, from one instruction. |
| `POST /knowledge/draft-sessions/{session}/accept` | Publish the chosen drafts. **Partial** — 200 either way, with a `conflicts` array alongside `accepted`. |
| `POST /knowledge/draft-sessions/{session}/rebase` | Re-point a shadow at its target's CURRENT revision. No AI call. |
| `GET /knowledge/draft-sessions/{session}/relations` | The proposal's relation preview (see "Draft relations response" above). |
| `POST /knowledge/draft-sessions/{session}/expand-context` | Retrieve context again — SPENDS one embedding. |
| `DELETE /knowledge/draft-sessions/{session}` | Abandon: every draft destroyed, then the session row. `204`. |
| `DELETE /knowledge/entries/{entry}/draft` | Reject ONE draft (soft-deletes it; it can come back while the session lives). `204`. |
| `GET /knowledge/entries/{entry}/draft-diff` | The two texts a draft diff compares (see "Draft diff response" above). |

### `GET .../compose-availability`

```json
{
  "can_compose": false,
  "reason": "disabled | ai_budget_exceeded | null",
  "budget": {
    "cost_used": 12.4, "cost_cap": 50, "cost_remaining": 37.6,
    "warn_reached": false, "blocked": false,
    "period": { "month": "2026-08", "resets_at": "2026-09-01T00:00:00.000000Z" }
  },
  "limits": {
    "source_max_chars": 20000,
    "prompt_max_chars": 2000,
    "max_entries_per_session": 16
  }
}
```

- **`reason` has exactly two non-null values: `disabled` (the module kill switch,
  `knowledge.index.enabled === false`) and `ai_budget_exceeded` (the workspace is at or over its
  effective monthly cap).** There is no third "no vector support" reason on the wire — the composer
  degrades on individual paths (retrieval, relations) rather than refusing to open at all when the
  connection lacks pgvector; a session still opens and composes create-only.
- **`budget` is the SAME shape `AiUsageService::summary()` returns for the workspace's whole AI-usage
  page** (`docs/backend/workspace-ai-usage-api.md`), narrowed to the fields the composer's own UI
  reads — `budget.period.resets_at` is what a "renews on {date}" line formats. This is deliberate: the
  gate that decides `can_compose` and the summary the AI-usage page shows are the SAME read, so this
  endpoint's answer and a `POST .../draft-sessions` refusal can never drift apart.
- **`limits` is the server's own input bounds** — the frontend's textarea counter and its start-button
  gate read `source_max_chars` from here rather than hardcoding the spec's 20 000, so a config change
  never lets the client promise a length the `POST` then refuses.
- **No per-operation cost ESTIMATE is on this wire, deliberately absent rather than a rough guess.**
  Neither this endpoint nor any session response carries a "this run/refinement will cost approximately
  $X" figure — a language model's own reply length is not knowable before it runs. The frontend renders
  the workspace's real budget-warning state instead of inventing a number (see
  `resources/js/next/docs/pages/KnowledgePage.vue`).

### `POST .../draft-sessions` (start) and `.../refine`

Body: `{ "source_text": "string, required, max: limits.source_max_chars" }`, plus for `store` only,
optionally `{ "seed_slug": "string", "seed_title": "string" }` — carrying a red (unresolved) wikilink's
slug into the session, so a composer opened from a ghost link is REQUIRED to produce an entry at that
exact slug (`seed_missed` failure otherwise — see ADR-0046 D11). `refine`'s body is
`{ "instruction": "string, required, max: limits.prompt_max_chars" }`; the instruction JOINS the
session's history (refinement is cumulative — "shorter", then "add examples", means both) and the
composer re-derives the WHOLE set again, not a patch.

If a run is already in flight (`status: generating`), `refine` is a **no-op that still answers 200**
with the session's current (unchanged) state — never a 409 the client would have to translate back into
"keep polling".

### `POST .../accept` — publish, PARTIALLY, and apply the SELECTED graph operations in the same act

Body: `{ "entry_ids": ["uuid", …], "status": "approved | draft", "graph_op_keys"?: ["graph:0", …] }` —
`status` is narrowed to those two values on purpose: `proposed` is what a draft already effectively is,
and `archived` on publish is not an action anybody wants. `entry_ids` must be PRESENT but may be
`[]` (a graph-only accept, publishing no drafts, is a legitimate call).

**`graph_op_keys` is THREE-VALUED, and the three values mean different things:**

| Value | Meaning |
|---|---|
| absent (key not sent) | Apply **every** proposed relation operation — the compatible default. |
| `[]` | Apply **none** of them, while still publishing the selected drafts. |
| `["graph:0", "graph:2", …]` | Apply **exactly** these; every other proposed operation is reported in `skipped[]` as `not_selected`, never silently absent. |

**`[]` also means NO declared entity (`N<n>` handle) is created — genuinely none, which was not always
true.** An entity is created only when a SELECTED relation operation NAMES it — this is derived from the
selection rather than a second axis of choice, matching what the review screen shows (a relation row
names the entities it `depends_on`; an entity is the consequence of choosing an operation that needs it,
not a row a client selects on its own). There is deliberately **no `entity:<n>` key on the wire** — the
ledger records `entity:<n>` internally, but exposing it as something a client could select would let a
reviewer pick a relation while refusing the person it needs, a combination whose only possible outcome
is a skip. `graph_op_keys: []` previously still created every declared entity as a real, approved entry
regardless of selection — a reviewer refusing the ENTIRE graph proposal (`entry_ids: []` +
`graph_op_keys: []`) had no way to say "no" to the entities it declared. Fixed: an entity nothing
SELECTED points at is not created under an explicit (non-null) selection; under the compatible `null`
default (no selection sent at all) every declared entity still creates as before.

Keys are `graph:<n>`, the ORDINAL POSITION of the operation in the session's current `graph_ops.graph_updates`
— never a hash — because that column is rewritten WHOLESALE on every run and never patched in place, so
an ordinal is stable for exactly as long as a reviewer is looking at it. **A refinement that renumbers
the operations invalidates a preview the client is still holding**: a stale key 422s
(`knowledge.relations.unknown_op_key`) rather than silently applying the wrong operation. Two more
`422`s guard the same field: `duplicate_op_key` (the same key sent twice) and `inseparable_ops` — a
`create`/`end` pair bound by `replaces` (see `pair_with` in the relations preview below) must be
selected or refused TOGETHER; splitting the selection would either assert the subject works in two
places at once or works nowhere, so it is refused rather than auto-completed (auto-including the
missing half would write something the reviewer never ticked).

```json
{
  "accepted": [ /* KnowledgeEntryResource[] */ ],
  "relations": [ /* KnowledgeRelationResource[] */ ],
  "conflicts": [
    { "entry_id": "uuid", "targets_entry_id": "uuid", "current_revision_id": "uuid" }
  ],
  "skipped": [
    { "op": "graph:1", "code": "dependency_not_accepted", "from": "N1", "to": "E3" }
  ]
}
```

**Exactly these four keys — there is no `updated[]`.** An earlier revision of this response carried one,
reserved for "entries the graph half changed"; it was always empty (nothing in
`KnowledgeGraphOpsApplier` ever wrote to it — a new entity created alongside a relation was folded into
that same accept's relation resolution but never surfaced through it) and was removed rather than kept
as a field with nothing to say. See "Publishing a shadow" below for where an amendment's target actually
does surface.

**200 either way.** A SHADOW whose target was edited after the composer read it cannot be applied — its
frozen `target_revision_id` no longer matches the target's `current_revision_id`, so publishing it would
be exactly the silent overwrite the optimistic lock (`409 knowledge_stale_write`, see above) exists to
refuse. Rather than failing the WHOLE request, that one draft is reported in `conflicts`, and every
other requested draft that DID publish is reported in `accepted` — a reviewer who accepted five
proposals is never told none of them happened because one target moved. Each accepted draft runs in its
own transaction, precisely so one conflict cannot roll back four successes; the SELECTED graph
operations, by contrast, run together in ONE transaction, because half of a chosen change is a lie (a
new entity without the relation that needed it is a stub nobody asked for).

- **Publishing a plain draft is ONE column write** (`draft_session_id = null` + the requested `status`),
  which is what fires the ordinary entry observer and queues indexing exactly like any other save (see
  ADR-0046 D3).
- **Publishing a shadow republishes through the ordinary `KnowledgeEntryService::update()`** — appending
  a revision, re-syncing links, re-queuing indexing — with the shadow's frozen `target_revision_id`
  replayed as `expected_revision_id`. **`accepted[]` for that draft id carries the LIVE TARGET entry —
  not the shadow** (`publishAmendment()` returns the entry `update()` just saved) — so a client reading
  `accepted[]` back always gets real, addressable entries, whether the draft it requested was a plain
  create or an amendment. The shadow row is then destroyed (it was a proposal; it has been applied). If
  the target was deleted entirely while the proposal sat on the table, the shadow is purged and reported
  as a conflict with `current_revision_id: null`.
- **A new entity a `graph_updates` operation creates (`graph_ops.entities`, `N<n>` handles) does NOT
  appear in `accepted[]` either — it currently surfaces to the caller only indirectly, through any
  `relations[]` entry naming it.** There is no dedicated field for it (see the note on `updated[]` above).
- **`relations[]` is every `KnowledgeRelationResource` this call actually wrote or changed** — new
  relations from `create` operations, plus any existing relation an `update`/`end` touched.
- **`skipped[]` — operations that did NOT apply, each with a reason. Never silent.** A reviewer who
  approved six operations and got four has to be told which two and why:

  | `code` | Meaning |
  |---|---|
  | `not_selected` | `graph_op_keys` was present and did not name this operation. |
  | `already_applied` | A previous accept of this session already applied it (idempotent by ledger — a double click, a redelivered job, a retried POST all replay safely). |
  | `dependency_not_accepted` | The relation names an entity (`N<n>`) whose OWN draft was not accepted in this batch. Not an error — the reviewer accepted a subset, and the preview told them this relation depended on the rest (`depends_on_draft` — see the relations preview below). |
  | `relation_gone` | The named relation (`R<n>`) was ended, retracted or deleted between composition and the click — somebody got there first; the outcome the reviewer wanted is already true. |
  | `refused` | The base declined it NOW — a duplicate asserted since, a cap reached, a type the owner removed from the vocabulary. Since the AI-only authoring pivot ([ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)) this is the ONLY place these refusals surface as a direct answer to a click — there is no more `POST .../relations` for a person to hit them on directly — `context` carries the same fields as the matching `knowledge_relation_*` code under "Typed relations" above. |

  **There is no `entity_gone` or `unknown_handle` skip code.** `KnowledgeGraphOpsApplier` carries an
  explicit comment stating so: a target that vanished between composition and acceptance surfaces as a
  `conflicts[]` entry through the ordinary shadow-draft path instead (see `KnowledgeGraphApplyTest`),
  and a dependency on an unaccepted entity is exactly `dependency_not_accepted` above — a third,
  overlapping code was never needed and was removed.

### `POST .../rebase` — free, no AI

Body: `{ "entry_id": "uuid" }` (the shadow draft's own id — resolved through the session's own
`whereNotNull('targets_entry_id')` relation, so an id naming a plain draft, another session's draft, or
a real entry simply is not found). Re-points the shadow's `target_revision_id` at the target's CURRENT
revision — the proposal's TEXT is untouched. This is the free half of the "two-price" conflict
resolution: rebasing costs nothing and lets the reviewer see a fresh diff against what the target
actually says now; asking the composer to `refine` with the new target content in view is the other
route, and it spends one more metered generation. The choice between the two is the one place in this
module where a UI decision has a direct AI-cost consequence.

### `POST .../expand-context` — the ONE place a session spends twice

No body — the session already holds the source text. **429 / 422 / 422, three distinguishable
refusals**, never conflated into one generic error:

| Status | Code | Meaning |
|---|---|---|
| `429` | `ai_budget_exceeded` (`KnowledgeDraftBudgetExceeded`) | The workspace is over its cap, or the module kill switch is off. Same shape every other composer entry point uses. |
| `422` | `knowledge_context_already_expanded` (`KnowledgeContextAlreadyExpanded`) | This session's context was already widened this round; a `refine` clears the flag and re-opens the action. See ADR-0046 D7. |
| `422` | ordinary validation | Malformed/oversized input on the OTHER composer endpoints (`store`/`refine`'s FormRequest rules) — listed here only to make the three-way distinction explicit: a budget refusal is never a 422, and an already-expanded refusal is never a 429. |

A successful call returns the session with `retrieval_set` re-derived and `context_expanded_at`
stamped — read by `KnowledgeDraftSessionResource` (see above), which is what disables the button for
EVERY tab looking at the session, not only the one that clicked it.

### `DELETE .../draft-sessions/{session}` and `DELETE .../entries/{entry}/draft`

Session `DELETE` (`abandon`) hard-purges every draft (through the entry service's own cascade —
revisions, chunks, links all go with them) and then the session row itself; nothing is kept, because an
unaccepted draft is machine output nobody chose and the session holds the user's raw pasted material,
which is not something to retain for its own sake. `ReapAbandonedDraftSessionsCommand`
(`knowledge:reap-draft-sessions`) runs the identical path automatically for any session untouched for
`knowledge.drafting.abandon_after_days` (14), scheduled `daily()` — see "Scheduled commands" below.

Rejecting ONE draft (`DELETE /entries/{entry}/draft`) is an ordinary SOFT delete through
`KnowledgeEntryService` — the draft can come back while the session lives, it is simply excluded from
`accept`.

### Realtime settle — `knowledge-draft-session.updated`

A composition run takes tens of seconds; this module settles on a broadcast, **never a polling loop**
(a standing, explicit project requirement — see ADR-0046 D10).

| | |
|---|---|
| Channel | Private, **per-workspace**: `knowledge.workspace.{workspaceId}` |
| Auth | Central workspace membership (`routes/channels.php`) — identical posture to every other private channel in the app |
| Event | `knowledge-draft-session.updated` (Echo listener name: `.knowledge-draft-session.updated`) |
| Payload | `{ "id": "<session-uuid>", "status": "ready \| failed" }` — **status only, never the drafts** |

The payload is deliberately status-only: the channel fans out to every composer open in the workspace,
and the drafts are the user's own material turned into text — fetching them goes through the
authenticated, workspace-scoped `GET .../draft-sessions/{session}` endpoint, which is where the real
per-session authorization decision belongs. On a match, the client makes exactly ONE follow-up fetch.
`GENERATING` is never broadcast — only the two terminal states.

---

## Bot binding (`Bot → Knowledge`)

Full contract in `docs/backend/bots-api.md` → "Knowledge module (B6)". Summary of the cross-module
surface:

| Method & path | Purpose |
|---|---|
| `PUT /api/bots/{bot}/knowledge-binding` | Body `{ knowledge_base_id, mode }` (mode ∈ `inline\|rag\|auto`). Upsert — re-binding the same pair only changes the mode. A foreign/trashed base 404s. Returns the whole `BotResource`. |
| `DELETE /api/bots/{bot}/knowledge-binding` | Unbind. Idempotent. The bot falls back to its own legacy `knowledge` module, unchanged. Returns `BotResource`. |
| `POST /api/bots/{bot}/knowledge/migrate` | Lift the bot's legacy `knowledge.entries` into a real base (all-or-nothing), bind it (`auto` mode). **201 — the ONE exception to the data-wrapping rule above**: a flat body, `{ "knowledge_base_id", "name", "entries_count", "mode" }`, no `data` key. |

**`BotResource.knowledge_binding` is `null` when the request carries no `X-Workspace-Id` header — even
for a bot that HAS a binding.** `bots` (index/show), `bots/{id}/restore` and `bots/{bot}/status` are
deliberately declared OUTSIDE `RequireWorkspace`, and `ResolveWorkspace` no-ops without the header,
leaving `WorkspaceScope` inert; reading the binding there would run an UNSCOPED query for a
workspace-owned row (a 500 risk on an own-database tenant not yet provisioned). `null` is the same
honest answer an unbound bot already gives, so a client cannot distinguish "no header sent" from
"nothing bound" from this field alone — every route that WRITES a binding sits inside
`RequireWorkspace`, so nothing is hidden from a caller actually positioned to change it.

**Precedence: a binding wins OUTRIGHT.** Once a `KnowledgeBinding` exists for a bot, its legacy
`knowledge` column is never consulted again — even if `knowledge.enabled` is still `true` on the
bot's own record. The two are not merged. See
[ADR-0045](../decisions/ADR-0045-knowledge-consumption-data-erasure.md) D8. The legacy `knowledge`
column itself is **not** deprecated by this batch — it remains a fully-supported, independent module
(entries injected into the run context whenever `knowledge.enabled` is true and no binding exists),
with a documented eventual wind-down planned for a future stage (see
`docs/product/plan-dzialania.md`).

`POST .../knowledge/migrate` **422**s (`errors.knowledge`, a single message naming the offending
entry titles) when any legacy entry carries directive-guard syntax (the bot's own `knowledge` column
was never guarded — migrating without re-checking would be the one door that walks unguarded content
into the guarded store), or when there is nothing to migrate at all.

---

## Data erasure — `php artisan knowledge:purge-subject`

Full design rationale: [ADR-0045](../decisions/ADR-0045-knowledge-consumption-data-erasure.md).

```
php artisan knowledge:purge-subject {workspace} {phrase}* [--base=] [--apply] [--force] [--json]
```

| Argument/option | Meaning |
|---|---|
| `workspace` | Workspace uuid to purge within (required). |
| `phrase` (repeatable) | One or more literal phrases, matched case-insensitively, no stemming. Supply inflected variants yourself (`"Kowalska" "Kowalskiej" "Kowalską"`). |
| `--base=` | Restrict the scan to one knowledge base id. |
| `--apply` | **Actually delete.** Omitted → dry run (report only, nothing deleted). |
| `--force` | Skip the typed confirmation AND the breadth cap. |
| `--json` | Emit exactly one JSON document on stdout and nothing else. |

### Boundary — this module ONLY

Covers **only** the Knowledge module: entries (published AND unaccepted AI drafts — see below), their
append-only revision history, their embedded chunks, the graph edges that name a subject, since the AI
composer batch (ADR-0046) drafting sessions and the raw material a person pasted into one, since the
typed-relations batch (ADR-0047) typed relations whose OWN `description`/`properties` text names the
subject (independent of whether either entry the relation joins mentions them at all) **and their audit
trail** (`knowledge_relation_events`, its own category — see below), and — most recently — knowledge
BASE CHARTERS that name the subject, **found and reported but never deleted** (see "The report-only
category" below). Does **not** touch tasks, forms/submissions, approvals, Disk files, bot/generation
output, workflow runs, or comments — a complete right-to-be-forgotten across every module is separate,
larger work. An operator archiving this command's report is archiving proof about the knowledge base
only.

**`knowledge_entry_chunks.content` is DELIBERATELY not scanned, and this is the one surface where
that stays a stated, open gap rather than a resolved category.** A chunk's text is DERIVED from the
entry's current content and cascades with it: purging the entry (or deleting the one revision that
carried the phrase) already removes every chunk cut from that text, and the ones that remain are
already VISIBLE in the report — `totals.cascaded_chunks` counts exactly this, so the gap does not read
as silence. The one residual case nothing here reaches is a chunk that has not been re-indexed since an
edit that removed the phrase — a stale chunk sitting on otherwise-clean current text — and that gap is
real and currently open, not a false negative this scan claims to close.

### The report-only category — base charters

**A knowledge base whose `charter` names the subject is FOUND and REPORTED, and is the only category in
this command with no delete counterpart at all — not `end`, not `retract`, nothing.** This replaces an
earlier, different resolution: charter text used to be excluded from the scan altogether ("deliberately
not scanned, with a stated reason"). That was a DIFFERENT decision with a different justification, and
it did not hold up — a charter is not dormant policy text sitting beside the data, it is the most ACTIVE
copy of it in the whole system: `KnowledgeCompiler` prepends the charter to the compiled knowledge block
on **every single generation** made against that base, so a name in a charter is sent to the AI provider
continuously. Leaving it out of the scan meant an operator could read "0 records," certify the erasure
as complete, and have the name keep going out to the provider indefinitely — the exact false all-clear
this command exists to prevent.

**It is still never deleted, and that half of the original reasoning stands.** A charter is the base's
own editorial policy ("what this base is for, its tone, its scope"), not a record about the subject —
deleting the BASE would destroy every entry in it, and blanking the CHARTER would silently change how
every future generation against that base behaves, which is a product decision this command does not
get to make on an operator's behalf. Removing one sentence while keeping the rest of the paragraph
coherent needs a human who can read it. So the command names the base, says a charter matched, and
stops — the operator edits it by hand.

**Why a charter match is excluded from the breadth cap — the decision is not obvious and is worth
stating explicitly.** `totalMatches()` (the number the cap compares against `knowledge.purge.max_entries`
and the number an operator retypes to confirm `--apply`) does two jobs, and both are about DESTRUCTION:
it confirms a delete, and it trips the cap that refuses one. A charter is never destroyed by this
command, so counting it would let a report-only finding — something this command cannot act on either
way — refuse an `--apply` it has no bearing on. The only way past a tripped cap is `--force`, which
**also skips the typed confirmation** — so counting charters toward the cap would, in practice, push an
operator into weakening the safety guard over the categories that DO get deleted, for a reason having
nothing to do with them. The "operator should still see the whole picture" concern is real and is
answered elsewhere instead: the charter section prints in every report regardless of the cap, and
`hasCharters()` is what stops the command from ever saying "nothing matched" while one is outstanding
(see "Operator procedure" below).

**Two standing limitations, reported by whoever built this, not discovered later:**

- **A charter match never expires on its own — the ONLY confirmation that an operator's manual edit
  actually landed is re-running the identical scan.** There is no flag, no timestamp, nothing analogous
  to a relation's `state` or an entry's `stale_at` that could mark a charter "handled." The workflow is
  entirely re-run-to-verify: edit the base's charter through its own settings, then run the same
  `knowledge:purge-subject` command again with the same phrase, and an empty `charters[]` on that second
  run is the only proof the edit actually removed the name (rather than, say, rewording around it while
  leaving the phrase intact).
- **`--base` narrows the charter scan exactly like every other category, so a `--base`-scoped run is NOT
  the complete charter picture for the workspace — only an unscoped, whole-workspace run is.** An
  operator investigating one base with `--base=<uuid>` will only ever see that base's own charter in
  `charters[]`, never another base's, even though the subject could be named in several. The full,
  authoritative answer to "does any charter in this workspace still name this person" requires running
  the command without `--base` at all.
### Exit codes and refusals

| Situation | Exit | Notes |
|---|---|---|
| Success (dry run or applied) | `0` (`Command::SUCCESS`) | A successful `--apply` with an outstanding charter match still exits `0` — the exit code answers "did the command run," not "is the request fully resolved." See `hasCharters()` below for how the report itself keeps that distinction visible. |
| Unknown/malformed workspace, unknown base, no usable phrase | `1` (`Command::FAILURE`) | Refused before any scan. |
| Breadth cap exceeded (`knowledge.purge.max_entries`, default 200) without `--force` | `1` | **Counted over `totalMatches()` — every category below, not entries alone, and NOT charters** (see the note under `totals.matches`). The report IS still printed — the operator needs to see what dragged the phrase in. The refusal message itself says "matches N **records**", not "N entries" — an existing runbook or script that greps for "entries" in this message, or that assumed the cap only ever counted entries, will see a REFUSAL now where an identical phrase previously sailed through (a phrase matching zero entries but a large number of relation-audit lines, say, used to pass the cap silently and no longer does). |
| Typed confirmation did not match, or refused on a non-interactive terminal without `--force` | `1` | An `--apply` run on a pipe with no `--force` is refused outright rather than auto-confirmed. |

### The JSON report shape (`--json`, or the human-readable equivalent)

```json
{
  "command": "knowledge:purge-subject",
  "scope": "knowledge",
  "generated_at": "ISO 8601",
  "workspace_id": "uuid",
  "knowledge_base_id": "uuid | null",
  "phrases": ["Kowalska", "Kowalskiej"],
  "mode": "dry-run | applied",
  "refused": "too_broad | not_confirmed | null",
  "totals": {
    "matches": 7,
    "entries_purged": 2,
    "draft_entries_purged": 1,
    "revisions_deleted": 1,
    "ghost_links_deleted": 2,
    "draft_sessions_purged": 1,
    "relations_deleted": 1,
    "relation_events_deleted": 1,
    "charters_flagged": 1,
    "cascaded_revisions": 6,
    "cascaded_chunks": 14,
    "cascaded_session_drafts": 3
  },
  "entries": [{ "id": "uuid", "knowledge_base_id": "uuid", "slug": "string", "title": "string", "trashed": false, "matched_in": ["title", "content"], "revisions": 3, "chunks": 7, "incoming_links": 1, "is_draft": false }],
  "revisions": [{ "id": "uuid", "knowledge_entry_id": "uuid", "entry_slug": "string", "created_at": "ISO 8601 | null", "matched_in": ["content"] }],
  "ghost_links": [{ "id": "uuid", "knowledge_base_id": "uuid", "from_entry_id": "uuid", "target_slug": "string", "source": "wikilink" }],
  "draft_sessions": [{ "id": "uuid", "knowledge_base_id": "uuid", "status": "ready", "matched_in": ["source_text"], "drafts": 3 }],
  "relations": [{ "id": "uuid", "knowledge_base_id": "uuid", "from_entry_id": "uuid", "to_entry_id": "uuid", "relation_type": "works_on", "matched_in": ["description"] }],
  "relation_events": [{ "id": "uuid", "relation_id": "uuid", "operation": "update", "created_at": "ISO 8601 | null", "matched_in": ["before"] }],
  "charters": [{ "knowledge_base_id": "uuid", "knowledge_base_name": "string", "action_required": "manual_edit", "deleted": false }],

  "generation_session_snapshots": 0
}
```

- **`totals.matches` INCLUDES matched drafting sessions, matched relations AND matched relation-audit
  lines — and deliberately EXCLUDES matched charters.** It is the exact number the operator retypes to
  confirm an `--apply` (`SubjectPurgeReport::totalMatches()` sums `entries + revisions + ghost_links +
  sessions + relations + relation_events`, **six** categories, charters never among them). A report that
  matched two entries and one session shows `"matches": 3` even though only two of the (up to) six
  counted categories moved. **This is also what the breadth cap (`knowledge.purge.max_entries`) now
  counts** — see "Exit codes and refusals" above for the operator-facing consequence, and see
  `charters_flagged` below for why charters specifically sit outside this number.
- **`relation_events` is a NEW category (this batch), the audit-trail counterpart of `relations` — the
  same asymmetry as entries vs. revisions, one table further out.** `knowledge_relation_events` stores a
  `before`/`after` snapshot of a relation on every change, and both copy `description` and `properties`
  VERBATIM. So the ordinary, RESPONSIBLE remedy — a person edits a relation's description to take a name
  out — leaves that exact name sitting in the `before` snapshot of the very event that recorded the
  removal: the relation is clean, every entry is clean, and the name is still in the database. This
  table has **no foreign key to the relation and no API that can rewrite a line** — both deliberately, so
  that deleting a relation cannot delete the record of its own deletion, and so nothing else in the
  product can silently scrub a line — which is precisely why nothing but this command can reach it.
  Deleted at the granularity of the individual EVENT (like a lone revision): the relation keeps the rest
  of its history, only the lines carrying the name go. **Excluded from this category are events whose
  OWN relation also matched** — those are destroyed by the relation's own cascade (see below) and
  counted once, under `relations_deleted`, not twice.
- **`charters[]` / `totals.charters_flagged` — the ONE report-only category, and the naming says so on
  purpose.** Every other counter in `totals` ends `_deleted`/`_purged`; this one ends **`_flagged`**,
  deliberately not matching the pattern, because a reader skimming an archived report months later must
  not be able to mistake "flagged" for "done." Each item is
  `{ knowledge_base_id, knowledge_base_name, action_required: "manual_edit", deleted: false }` —
  `action_required` and `deleted` are IN THE PAYLOAD, not only in prose around it, for the same reason:
  the archived JSON is read by people who never saw the terminal output that explained it. See "The
  report-only category" above for why this exists and why it is never auto-resolved. `charters[]` is
  carried through `asApplied()` UNCHANGED — an `--apply` does not clear it, because the charters are
  still outstanding after the erasure ran, and the archived "applied" report has to keep saying so.
- **`relations` is a category from the typed-relations batch (ADR-0047), almost always zero.** A typed
  relation's `description` and `properties` are free text a person or the AI composer wrote ONTO THE
  EDGE — "Wprowadzona przez Annę Kowalską", `{"role": "asystentka Anny Kowalskiej"}` — and neither entry
  the relation joins need mention the subject at all, so purging both entries would not reach it and an
  erasure could certify itself complete with the name still on the edge. **The remedy is a hard
  `DELETE`, never `end` or `retract`** — those two are lifecycle states that deliberately KEEP the row
  (see "Typed relations" above), which is correct everywhere except here, where remembering is the very
  thing being erased. The relation's own audit-trail events are deleted ALONGSIDE it as part of the same
  cascade (not reported separately — see the exclusion note above). `matched_in` names `description`
  and/or `properties`. Scanned across EVERY state (`active`/`ended`/`retracted`), not `active` only — an
  ended relation holds the same sentence, and "nobody is shown it by default" is not the same claim as
  "it is not there."
- **Three columns join the scan that were not scanned before, all reached through the ENTRY match, and
  all worth naming individually because each closes a genuine, separately-discovered gap:**
  - **`knowledge_entries.aliases`** — an alias is BY DEFINITION another name for the same thing (a
    maiden name, a nickname, initials); an erasure that matched only the title while an alias survived
    would leave the mention scanner drawing edges from the erased name forever after.
  - **`knowledge_entries.slug`** — a slug does **not** follow a rename (see Concepts): an entry
    retitled "A. K." keeps the slug `anna-kowalska` for good, because a slug is an address and moving it
    would break every inbound link. Matched against BOTH the raw phrase and its slugified form.
  - **`knowledge_entry_revisions.change_note`** — a person's own sentence about an edit ("usunięto dane
    Anny Kowalskiej") names people in exactly the situation this command exists for, and being the
    operator's own note it is the field most likely to spell a name out in full.
- **A drafting session's scan surface is now SIX jsonb/text surfaces, up from four at the composer
  batch and five after `notes` was added** — `prompt_history`, `retrieval_set`, `resolution_set`,
  `graph_ops`, `notes`, and now **`relations_cache`** (the proposed-relations panel's cache, which
  carries entry TITLES and SLUGS the same way `notes` does), plus two plain string columns matched as
  text/slug respectively: **`seed_title`** and **`seed_slug`** ("write me the entry this red link points
  at" seeds a session with the missing entry's NAME — for a person, that name is the whole subject of
  the erasure request, sitting in a plain column nothing else reaches).
- **`totals.draft_entries_purged` and `totals.cascaded_session_drafts` are SUBSETS, not additions.**
  `draft_entries_purged` counts how many of the entries in `totals.entries_purged` are unaccepted AI
  drafts (`entries[].is_draft: true`) — reported separately because an operator is certifying the
  destruction of two different kinds of thing (published knowledge someone wrote, and machine output
  nobody approved) and a single figure would hide the second inside the first.
  `cascaded_session_drafts` counts drafts destroyed as a SIDE EFFECT of abandoning a matched session
  (`draft_sessions[].drafts`, summed) — separate from any draft that was ALSO matched directly on its
  own text and is already counted inside `entries_purged`/`draft_entries_purged`.
- **`entries[].is_draft` is new on this shape.** The scan runs `KnowledgeEntry::withTrashed()->
  withDrafts()`, so an unaccepted draft's CURRENT text is reachable exactly like a published entry's —
  the flag tells the operator which is which. (This closes a real asymmetry an earlier version of the
  scan had: it lifted the soft-delete filter but not the draft scope, so a draft's append-only
  revisions were reachable and purged while its own live, name-carrying row stayed invisible and
  untouched — see ADR-0046 D8.)
- **The `draft_sessions` section is entirely NEW territory an erasure request could otherwise miss.** A
  session's `source_text` (the raw material a person pasted) and `prompt_history` (a refinement
  instruction can itself name a person) are reachable from NOTHING else — never chunked, never
  indexed, and unaffected by however many entries get purged elsewhere in the same run.
  `matched_in` names which of the two fields carried the phrase (`source_text` and/or
  `prompt_history`); `drafts` is the session's draft count, i.e. the blast radius of abandoning it — a
  matched session is always abandoned WHOLE (`KnowledgeDraftSessionService::abandon()`, the identical
  path the user's own "discard" button takes), never edited in place, because the source text is an
  opaque blob with no field-level surgery to perform on it.
- **`generation_session_snapshots: 0` is a deliberate placeholder, not a bug.** A generation session
  will eventually persist knowledge text verbatim in its own frozen snapshot; when that ships, this
  field — and the command's actual erasure coverage — **must** be extended, and its test suite
  extended alongside it, before that change can be considered complete. Treat this line as an open
  obligation, not decoration.
- **Five different remedies for five different kinds of match — two of them the same shape one table
  further apart (entry/revision, relation/relation-event) — and a SIXTH category with no remedy at
  all.** An entry whose **current** text matches is purged whole (with its cascade, which takes its OWN
  relation-audit lines with it); a revision whose **history only** matches is deleted alone, leaving its
  (clean) entry standing; a matched drafting session is abandoned WHOLE (its source text is an opaque
  blob nothing else references, with no field-level surgery to perform on it); a matched relation is
  hard **`DELETE`d outright** (never `end`ed, never `retract`ed — see above); a matched relation-audit
  LINE whose relation does **not** also match is deleted alone, leaving the rest of that relation's
  trail standing — the audit-trail mirror of the lone-revision remedy. **A matched base charter is the
  exception: found, reported, and left exactly as it was** — see "The report-only category" above.

### Order of application

Applied in ONE transaction, in this order, because the order is what keeps the counts in the report
honest rather than double-counted or silently short: ghost links, then relations (whose own audit trail
is destroyed WITH them, in the same step), then the lone relation-audit lines left over (belonging to
relations that were not themselves matched), then lone revisions, then entry purges, then — last, because
abandoning a session destroys its drafts too — session abandonment. Earlier steps are ordered before
later steps specifically so that a row a later step's cascade would also have removed is never listed
(and never re-deleted) twice. **`charters[]` has no step here at all** — nothing in `apply()` touches a
`KnowledgeBase` row, at any point in the transaction or outside it; the category exists purely to be
scanned and reported.

### Operator procedure

1. **Dry run** (no `--apply`): review the printed/`--json` report — who/what it found, and via which
   field (`matched_in`).
2. **Archive the report** — `--json` output redirected to a file, or the human-readable text, kept as
   evidence the request was reviewed before anything was touched. This is the ONLY artefact that ever
   carries the phrase; the application log never does (see below).
3. **`--apply`**, and on an interactive terminal, retype the number shown (`totalMatches()`) when
   prompted — the plain-text report enumerates every category the confirmation number is built from,
   drafting sessions included (see below). `--force` is for scripted use and also bypasses the breadth
   cap — use deliberately.
4. **If any base charter was flagged, edit it by hand and RE-RUN the same command to confirm it is
   clear.** Nothing automated tells the operator the edit landed — re-running the identical scan is the
   only confirmation this command offers, and it is a genuine, standing limitation (see below), not a
   missing convenience.

**Completion is two different questions, and the report answers both separately —
`isEmpty()`/`hasCharters()` — because collapsing them produces a false all-clear.**
`SubjectPurgeReport::isEmpty()` ("nothing for THIS COMMAND to destroy," i.e. `totalMatches() === 0`) and
`hasCharters()` ("a charter is still waiting on a human") are independent: a run can find nothing to
delete while a charter is still open, and the command's own messaging branches on both rather than
collapsing to "nothing matched." A dry run with `isEmpty()` true and `hasCharters()` true prints
"Nothing to erase automatically — but the base charters above still name the subject and need editing
by hand," never the plain "nothing matched" a charter-blind report would show. **After a successful
`--apply`, if `hasCharters()` is still true (charters are carried through an apply unchanged — see
above), the command prints an extra warning naming the request unfinished:**
`NOT FINISHED: the base charters listed above still name the subject. Edit them by hand, then re-run to
confirm.` — surfaced at the exact moment the operator is deciding whether the request is done, not
buried earlier in the report.

**The human-readable (non-`--json`) text output DOES list every matched category, by name, one line per
match — including base charters, the newest.** `PurgeKnowledgeSubjectCommand::text()` prints a line
each for matched entries, matched revisions, matched ghost links, a `"Drafting sessions whose pasted
material matches (session abandoned, drafts purged): N"` line followed by one line per matched session
(`id`, base id, status, `matched_in`, draft count), a `"Relations whose description or properties match
(deleted): N"` line followed by one line per matched relation (`relation_type`, `from_entry_id`,
`to_entry_id`, `matched_in`, `id`), a `"Relation audit lines matching HISTORY ONLY (line deleted,
relation kept): N"` line followed by one line per matched event (`operation`, `relation_id`,
`matched_in`, `id`), and — when `hasCharters()` — a
`"BASE CHARTERS naming the subject — NOT DELETED, MANUAL EDIT REQUIRED: N"` section explaining that a
charter is prepended to the knowledge block on every generation against its base (so the text is still
being sent to the AI provider), that the command does not edit it because the charter belongs to the
base and removing one sentence while keeping the policy coherent needs a person, and an instruction to
edit each base by hand and re-run to confirm — followed by one line per flagged base
(`- base {name} ({id}) — edit its charter`). This mirrors `--json`'s `draft_sessions[]`/`relations[]`/
`relation_events[]`/`charters[]` sections exactly, and is consistent with `totals.matches`/
`totalMatches()` **including every category except `charters`** (see above): the number an operator
retypes on `--apply` is fully accounted for by what the plain-text report shows above it, MINUS the
charter section, which is printed but never adds to that number. The matched TEXT itself (`source_text`,
a relation's `description`/`properties`, an event's `before`/`after`, a charter's own text) is **never**
printed in either mode — only ids, names, type, and which field (`matched_in`) carried the phrase, for
the same reason nothing else in this report ever prints the phrase or a slug/title that would reproduce
it. Consistent with that, `charters[]` has no `matched_in` at all — a charter match is a single text
column, so there is nothing to disambiguate.

### Never logged

The phrase — the personal data the erasure request concerns — reaches exactly one place: the
operator's own report. `Log::info('Knowledge subject purge applied.', …)` records **ids and counts
only** (workspace id, base id, phrase COUNT, entry/revision/ghost-link/draft-session/relation ids, and
every cascade total shown in `totals` above, including `draft_entries_purged`/`draft_sessions_purged`/
`cascaded_session_drafts`/`relations_deleted`) — never the phrase itself, never a slug or title (a slug
like `anna-kowalska` would reproduce the phrase exactly), never a relation's `description`/`properties`
or an event's `before`/`after`, and never a session's `source_text` or `prompt_history`. Pinned by a
test that scans every log line the command emits. **`relation_events_deleted` and the individual event
ids are NOT among the fields this log line carries today** — every other new-this-batch total
(`relations_deleted`) has a counterpart in the log entry, `relation_events_deleted` does not; the
operator's own archived report (which does carry it, per the JSON shape above) is the record of that
count until the log entry is extended to match. **Charters carry no entry here at all, and that is
consistent rather than another gap** — the log records what the command DID, and a charter match is
never applied, so there is nothing for `record()` to report; the archived JSON report (`charters[]`,
`totals.charters_flagged`) is the only artefact that ever states it, dry run or apply alike.

---

## Operational

### The kill switch — `knowledge.index.enabled`

The module's single switch for **every AI spend it can incur** — embedding (indexing) and query
embedding (search's/retrieval's vector legs) both sit behind it. Honoured as the FIRST statement of
`IndexKnowledgeEntryJob::handle()`, before tenancy is even restored, so flipping it off cannot leave
a half-run behind. When off: writes still succeed (an entry simply stays `pending`), search degrades
to keyword-only (`vector_search_reason: "disabled"`), and retrieval degrades to the free inline
compiler.

### Scheduled commands

| Command | Schedule | Purpose |
|---|---|---|
| `knowledge:sweep-index` | `everyTenMinutes()`, `withoutOverlapping()` | Dispatches an indexing job for every entry whose `index_digest != indexed_digest` and is not already `indexing`. Catches: a config change (chunker version / embedding model bump) with no entry write anywhere, a workspace whose AI cap was raised since a `pending_budget`/`partial` run, a dropped queue job. Batched per workspace (`knowledge.index.sweep_batch`, 100) and gated per-workspace before dispatch (an over-cap workspace is skipped entirely, not queued-then-refused). Walks every workspace (shared DB rows + each own-database tenant), one broken tenant logged and skipped. |
| `knowledge:reap-stale-index` | `everyFiveMinutes()`, `withoutOverlapping()` | Releases an entry stranded in `indexing` past `knowledge.index.stale_after` (900s) back to `pending` — a worker killed by SIGKILL/OOM never runs the job's `failed()` hook, and the sweep deliberately skips `indexing` rows (it cannot tell a live run from a dead one), so without this reaper an abandoned claim permanently removes an entry from the catch-up path. |
| `knowledge:reap-draft-sessions` | `daily()`, `withoutOverlapping()` | Purges abandoned AI composer sessions (see ADR-0046 / "DELETE .../draft-sessions" above) past `knowledge.drafting.abandon_after_days` (14). Daily rather than the five-minute cadence of its sibling reapers: those *recover* stranded claims where minutes of delay are user-visible, while this one *deletes* on a fourteen-day window (same reasoning as `disk:prune-temp-files`, one step further out). Registered in `routes/console.php` alongside the module's other commands. The whole class of "documented retention window with no schedule line" is now pinned by `ScheduledMaintenanceCommandsTest`, which derives the required set from registered `App\Modules\` commands named `reap`/`sweep`/`prune` — a future reaper is covered the moment it is named like one. |
| `knowledge:purge-subject` | **Not scheduled** — operator-invoked only, by design (an irreversible erasure command must never run unattended). See Data erasure above. |

### Test flags (guard real DDL / heavy fixtures — do not run by default)

| Env var | Gates | Purpose |
|---|---|---|
| `TENANT_DB_TESTS=1` | `KnowledgeTenantSchemaTest`, `KnowledgeTenantIndexingTest`, `KnowledgeTenantSubjectPurgeTest`, `KnowledgeTenantComposerTest` (and, outside this module, `WorkspaceProvisioningIntegrationTest`) | Real `CREATE DATABASE`/`DROP DATABASE` against an own-database tenant — the ONLY check that the `vector` extension and the chunk schema actually provision on a fresh tenant database (which inherits nothing from the central one), and the only place the composer's queued write path is exercised against a connection it re-derives from a workspace id. |
| `KNOWLEDGE_HEAVY_TESTS=1` | `KnowledgeSearchTest::test_the_php_fallback_bounded_by_its_candidate_cap_still_answers_and_says_so` | Seeds 5000+ chunks to exercise the PHP-fallback similarity search's candidate cap under real memory pressure — needs headroom above the project's 128 MB `memory_limit`; gated for memory, not speed (~8s alone). |

Neither flag is set by the default `php artisan test --filter=Knowledge` run; both must be
explicitly opted into.

#### A file behind a flag ROTS, and nothing tells you

`KnowledgeTenantComposerTest` was found scripting a composer contract that had moved on. It had been
wrong for some time and no run was ever red, because the file only executes when somebody remembers to
set `TENANT_DB_TESTS=1` — and the tests it duplicates in shared mode kept passing, so every visible
signal said the module was fine. **A test nobody runs is not weaker coverage; it is a claim in the repo
that nobody is checking.** The failure is silent in both directions: the file can go stale against the
code, and the code can lose a tenancy guarantee with the only test of it sitting unexecuted.

Until the repository has CI (**it has none today** — no `.github/workflows`, no `.gitlab-ci.yml`), the
guard is a habit, so it is written down rather than assumed:

```bash
# ~15s, needs the pgsql connection and permission to CREATE/DROP DATABASE
TENANT_DB_TESTS=1 php artisan test --filter=Tenant
```

Run it **by hand**:

- before committing a change to anything the composer's queued path touches — `KnowledgeDraftService`,
  `GenerateKnowledgeDraftsJob`, the agents' reply contract, `KnowledgeGraphOpsApplier`,
  `KnowledgeRelationService`;
- before committing a change to tenancy plumbing — `TenantManager`, `WorkspaceProvisioner`, the tenant
  migration tree, or any `TenantAware` model's table;
- whenever a test in the shared-mode suite has to be edited to follow a contract change, because its
  own-database twin scripts the same contract and will not tell you it disagrees;
- before merging the module.

**When CI exists, this belongs in a nightly job** (one container, a handful of seconds:
`TENANT_DB_TESTS=1 php artisan test --filter=Tenant`), not in the per-push run — the cost is the DDL
permission and the database churn, not the clock. That job is **still open**; nothing here replaces it,
and the habit above is what stands in for it meanwhile.

### Running the module's tests as one command — `memory_limit` and the shared test database

`php artisan test --filter=Knowledge` runs the module's whole test suite — ~438 tests as of the AI
composer batch — **in one process, in one command**. This depends on two facts pinned in `phpunit.xml`
rather than on anything a contributor has to remember:

- **`<ini name="memory_limit" value="512M"/>`** (set project-wide, not Knowledge-specific). PHPUnit runs
  every test in a single process, so Laravel's container, the Eloquent model registry and the
  route/view/config caches accumulate across the whole run; PHP's stock CLI default (128M) is exhausted
  partway through a suite this size, and the failure mode is an "Allowed memory size exhausted" storm
  that reads like a broken suite rather than a resource limit. At 512M the FULL suite (~2750 tests)
  completes in one command (~8 min), and the Knowledge module's own slice completes in ~3 min — both
  previously had to be split by hand. This is a test-harness setting only; nothing in the application is
  allowed to depend on it.
- **One test process at a time.** Every suite (this module's included) shares the single `test`
  database configured in `phpunit.xml`; two concurrent `php artisan test` invocations migrate and
  truncate under each other and produce dozens of unrelated failures that have nothing to do with either
  change under test. There is no per-run isolation beyond `RefreshDatabase`'s own transaction — running
  this module's tests alongside any other suite against the same database is not supported.

### Deterministic ranking — a total tie-break shared by BOTH similarity backends

`PgVectorSimilaritySearch` and `PhpCosineSimilaritySearch` (D1 of ADR-0044) apply the **identical**
secondary sort after their primary similarity/distance ranking:
`similarity DESC, then [entry_id, ordinal] ASC`. Exactly-tied cosine similarity is not a hypothetical
edge case here — an embedding is a pure function of text, so two passages with byte-identical content
(a document duplicated into two entries, a shared boilerplate section, an entry copied to be edited)
produce byte-identical vectors and therefore an identical distance to every query. Every caller that
ranks chunks consumes the result as an ORDERED list whose order is load-bearing (hybrid search fuses
ranks by reciprocal rank, where an arbitrary swap between two tied passages can flip the top result;
retrieval packs passages into a character budget in arrival order; the draft composer's relation
preview draws edges in it) — so a search returning a different "first" result for the identical query
would break the one thing a ranked list has to offer: the ability to go back and find what was just
seen. The pgvector implementation applies the tie-break in PHP over the (already `LIMIT`-bounded) rows
it fetched, deliberately NOT as a secondary `ORDER BY` clause in SQL — a secondary SQL sort key would
force the query planner to sort on top of the HNSW approximate-nearest-neighbour scan, defeating the
whole point of the index doing the ranking.

### Production caveat — pgvector needs a privileged role to install

`CREATE EXTENSION vector` requires a superuser (or an explicitly-granted role) on a database whose
migrating role does not already have it — pgvector is an **untrusted** extension. The module's own
first migration issues `CREATE EXTENSION IF NOT EXISTS vector` and is sufficient wherever the
migrating role already has the privilege, but is **not** sufficient on a host where it does not — and
a freshly provisioned own-database tenant workspace is the sharpest case, since its database inherits
nothing from `template1` and this migration is the only place the extension is ever installed for it.
**Before the first production deploy, a DBA must confirm the migrating role can install `vector`** —
by pre-installing it into `template1`, or by granting the role the privilege. See
[ADR-0044](../decisions/ADR-0044-knowledge-index.md) D1 for the full caveat; this is an open
operational item, not resolved by any code in this module.

---

## Authorization

`KnowledgeBasePolicy` / `KnowledgeEntryPolicy` / `KnowledgeLinkPolicy` / `KnowledgeRelationPolicy` —
workspace membership is enforced upstream, so these gate ownership/governance for mutation. **Since the
AI-only authoring pivot** ([ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)) **every
entry/relation authoring ability denies unconditionally**, kept as a written `false` rather than deleted
(a missing policy method is a gate fallthrough, not a refusal) — the second of the module's two barriers,
the first being that the route itself no longer exists:

| Ability | Rule |
|---|---|
| Base `viewAny` / `view` / `create` | Any authenticated member. |
| Base `update` (name/description/language) | Any member — a shared asset must not decay when its author is away. |
| Base `manage` (charter, metadata schema) | Base creator **or** the active workspace's owner (the ADR-0015 wider rule — a workspace owner must be able to govern a base a colleague created). **The charter stays editable by design** — see "This module is read-only for humans" above. |
| Base `delete` / `restore` / `forceDelete` | Same as `manage`. Base-level trash/restore/purge are untouched by this batch — only ENTRY-level trash/restore/purge are withdrawn. |
| Entry `viewAny` / `view` | Any authenticated member. |
| Entry `create` / `update` / `reorder` / `delete` (soft) / `restore` / `forceDelete` | **Denied for everyone**, unconditionally. See "This module is read-only for humans" above. |
| Entry `compose` | Any authenticated member — replaces the borrowed use of `create` for every AI-composer endpoint (see below). |
| Entry `retryIndex` | Any authenticated member — replaces the borrowed use of `update` for `POST .../entries/{entry}/retry-index`. |
| Entry `rejectDraft` | Any authenticated member, **and only when the target `isDraft()`** — replaces the borrowed use of `delete` for `DELETE .../entries/{entry}/draft`; refused for a published entry so this can never become a back door to `delete`. |
| Link `dismiss` | Any authenticated member — a wrong machine suggestion has to be a one-click fix for whoever is looking at it; rejecting a suggestion is not authorship. Untouched by this batch. |
| Relation `viewAny` / `view` | Any authenticated member. |
| Relation `create` / `update` / `end` / `delete` | **Denied for everyone**, unconditionally. `end`/`retract` denied along with the rest is not an oversight about severity — deciding a base should stop asserting something is exactly as much an editorial act as deciding it should start. |
| AI composer — every draft-session endpoint (open, poll, refine, accept, rebase, expand-context, abandon) | Any authenticated member who can `view` the base **and `compose` a `KnowledgeEntry`** — **not gated by who started the session.** No FormRequest behind these routes checks session creatorship; a session is a shared, workspace-visible piece of in-progress work, not the starting member's private draft. See "The AI composer" above. |
| AI composer — reject ONE draft (`DELETE .../entries/{entry}/draft`) | The new `rejectDraft` ability above, not ordinary Entry `delete` (which now denies everyone). |

---

## Related files

- `app/modules/Knowledge/routes/api.php` — route order, `whereUuid`, literal-before-wildcard
- `app/modules/Knowledge/Http/Controllers/{KnowledgeBase,KnowledgeEntry,KnowledgeEntryRevision,KnowledgeGraph,KnowledgeLink,KnowledgeRelation,KnowledgeSearch,KnowledgeDraftSession}Controller.php`
- `app/modules/Knowledge/Http/Requests/{StoreKnowledgeDraftSession,RefineKnowledgeDraftSession,AcceptKnowledgeDrafts,RebaseKnowledgeDraft,ExpandKnowledgeDraftContext}Request.php` — the composer's FormRequests
- `app/modules/Knowledge/Http/Requests/IndexKnowledgeRelationsRequest.php`, `Http\Requests\KnowledgeGraphRequest.php` — the only relation-facing FormRequests left; `Store`/`Update`/`End`/`DestroyKnowledgeRelationRequest` and `Store`/`UpdateKnowledgeEntryRequest`/`ReorderKnowledgeEntriesRequest`/`RestoreKnowledgeRevisionRequest` (8 total) were deleted with the routes they guarded — see [ADR-0049](../decisions/ADR-0049-knowledge-authorship-withdrawn.md)
- `app/modules/Knowledge/Http/Resources/{KnowledgeDraftSessionResource,KnowledgeRelationResource,KnowledgeGraphResource}.php`
- `app/modules/Knowledge/Services/{KnowledgeBase,KnowledgeEntry,KnowledgeLink,KnowledgeMetadataValidator,KnowledgeChunker,KnowledgeIndexService,KnowledgeSearchService,KnowledgeGraphService,KnowledgeGraphOpsApplier,KnowledgeRelationService,KnowledgeCompiler,KnowledgeRetrievalService,KnowledgeBindingService,KnowledgeSimilarityLinker,KnowledgeSubjectPurgeService,KnowledgeDraftSessionService,KnowledgeDraftService,KnowledgeDraftRetrievalService,KnowledgeDraftRelationService,KnowledgeEntityResolutionService}.php`
- `app/modules/Knowledge/Agents/KnowledgeDraftAgent.php` — the composer's system prompt/doctrine
- `app/modules/Knowledge/Support/{WikilinkParser,TemplateDirectiveGuard,KnowledgeFence,ChunkVector,KnowledgeDigest,KnowledgeSnippet,SubjectPhrases,ChunkCandidates,LaravelAiEmbedder,FakeKnowledgeEmbedder,PgVectorSimilaritySearch,PhpCosineSimilaritySearch,ShadowSlug,SimilarityThreshold,EntryAliases,MentionScanner,RelationVocabulary,RelationVerdict,KnowledgeGraphOps,SectionAppender}.php`
- `app/modules/Knowledge/Contracts/{KnowledgeEmbedder,KnowledgeSimilaritySearch}.php`
- `app/modules/Knowledge/Models/{KnowledgeBase,KnowledgeEntry,KnowledgeEntryRevision,KnowledgeLink,KnowledgeEntryChunk,KnowledgeBinding,KnowledgeDraftSession,KnowledgeRelation,KnowledgeRelationEvent}.php`, `Models/Scopes/WithoutDraftsScope.php`
- `app/modules/Knowledge/Enums/{KnowledgeRelationType,KnowledgeEntryType,KnowledgeRelationState,KnowledgeRelationOrigin}.php`
- `app/modules/Knowledge/Policies/{KnowledgeBase,KnowledgeEntry,KnowledgeLink,KnowledgeRelation}Policy.php`
- `app/modules/Knowledge/Jobs/{IndexKnowledgeEntryJob,GenerateKnowledgeDraftsJob}.php`, `Observers/KnowledgeEntryObserver.php`
- `app/modules/Knowledge/Events/KnowledgeDraftSessionUpdated.php` (+ `routes/channels.php` → `knowledge.workspace.{workspaceId}`)
- `app/modules/Knowledge/Exceptions/{KnowledgeDraftBudgetExceeded,KnowledgeContextAlreadyExpanded,StaleKnowledgeWriteException,KnowledgeSlugConflictException,KnowledgeChunkOverflowException,KnowledgeRelationRefused}.php`
- `app/modules/Knowledge/Console/{SweepKnowledgeIndexCommand,ReapStaleKnowledgeIndexCommand,PurgeKnowledgeSubjectCommand,ReapAbandonedDraftSessionsCommand}.php`
- `app/modules/Knowledge/KnowledgeModuleServiceProvider.php`
- `app/Http/Middleware/SetUserLocale.php` — app-wide (not Knowledge-specific); reads `users.locale`, found and fixed during this batch — see ADR-0047 "Defects found and fixed along the way," (f)
- `app/Support/Ai/FencedBlock.php` — the shared prompt-injection fence (reused, not forked)
- `app/modules/Variables/Services/AiTextGenerationService.php` — `generateWith()`'s optional `$channel` parameter, added for the composer batch and byte-preserving for every pre-existing caller
- `config/knowledge.php` — every tunable referenced above, including the whole `drafting` and `relations` blocks (`max_relations_per_entry`, `max_ops_per_session`)
- `database/migrations/2026_08_07_00000{0..9}_*.php`, `_00001{0..4}_*.php`, and `2026_08_08_000000_make_users_locale_nullable.php` (+ `database/migrations/tenant/0001_01_01_0000{57..73}_*.php`) — the vector extension, bases/entries/revisions/links/chunks/bindings/draft-sessions schema, the shadow-draft columns + `CHECK` constraint, `context_expanded_at`, `knowledge_relations`/`knowledge_relation_events`, `entry_type`/`aliases` on entries, both trees
- `docs/decisions/ADR-0043-knowledge-module-design.md`, `ADR-0044-knowledge-index.md`,
  `ADR-0045-knowledge-consumption-data-erasure.md`, `ADR-0046-knowledge-ai-composer.md`,
  `ADR-0047-knowledge-typed-relations.md`, `ADR-0048-knowledge-subject-paradigm.md`,
  `ADR-0049-knowledge-authorship-withdrawn.md`
- `docs/backend/bots-api.md` → "Knowledge module (B6)" — the one consumer wired today (unaffected by
  this batch — a bot's binding reads entries and their derived links exactly as before; it does not read
  typed relations)
- `resources/js/next/docs/pages/KnowledgePage.vue` — in-app component/contract docs
- `docs/next/knowledge-uxui-spec.md` §27 + its errata sections — the UX/UI spec for the relations layer,
  the subject-paradigm graph, and (most recently) the screens withdrawn by the AI-only authoring pivot
  and where the shipped code differs from its pre-implementation assumptions
- Tests: `php artisan test --filter=Knowledge` — **621 tests** (7 skipped) in ONE command (see "Running
  the module's tests as one command" above), spanning `tests/Feature/Knowledge*.php` (aggregates,
  revisions, wikilinks, chunking, indexing, search, graph, links, subject-purge, tenant provisioning,
  module boundary, the domain rules that survive the authoring withdrawal
  (`KnowledgeEntryRulesTest.php`, which replaced the deleted `KnowledgeEntryCrudTest.php`) and the
  withdrawal itself, pinned as a negative (`KnowledgeAuthoringWithdrawnTest.php`), plus the composer's
  own `KnowledgeDraftSessionTest.php`, `KnowledgeDraftInvisibilityTest.php`,
  `KnowledgeDraftRelationTest.php`, `KnowledgeShadowDraftTest.php`, `KnowledgeShadowLifecycleTest.php`,
  `KnowledgeComposeCycleTest.php`, `KnowledgeSubjectParadigmTest.php`, and the typed-relations batch's
  own `KnowledgeRelationTest.php`, `KnowledgeGraphApplyTest.php`, `KnowledgeGraphOpsFlowTest.php`,
  `KnowledgeComposeGraphEndToEndTest.php`), `tests/Unit/Knowledge/` (`KnowledgeChunkerTest.php` — the
  chunker's determinism guarantee, `KnowledgeContentEdgeInputTest.php`),
  `tests/Feature/BotKnowledgeTest.php` (the Bot → Knowledge binding/migration edge, unaffected by this
  batch), and `tests/Feature/BotModuleBoundaryTest.php`.
