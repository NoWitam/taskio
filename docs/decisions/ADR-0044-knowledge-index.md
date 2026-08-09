# ADR-0044 — Knowledge index: chunking, differential embedding, and hybrid search

**Date:** 2026-08-01 (created)
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`Services\KnowledgeChunker`, `Services\KnowledgeIndexService`,
`Services\KnowledgeSearchService`, `Services\KnowledgeSimilarityLinker`, `Support\ChunkVector`,
`Support\KnowledgeDigest`, `Support\PgVectorSimilaritySearch`, `Support\PhpCosineSimilaritySearch`,
`Support\LaravelAiEmbedder`, `Contracts\KnowledgeEmbedder`, `Contracts\KnowledgeSimilaritySearch`,
`Jobs\IndexKnowledgeEntryJob`, `Console\SweepKnowledgeIndexCommand`, `Console\ReapStaleKnowledgeIndexCommand`),
migrations `2026_08_07_000000_create_vector_extension.php` (+ tenant mirror
`0001_01_01_000057_create_vector_extension.php`), `2026_08_07_000005_create_knowledge_entry_chunks_table.php`
(+ tenant mirror)
**Relates to:** ADR-0043 (the module this indexing layer belongs to; D9 there covers the vector column's fixed
width), ADR-0045 (the retrieval consumer of this index), ADR-0033/ADR-0037 (the shared `MeteredAiCall` /
`ai_embedding`-style channel gating this module's spend rides)

---

## Context

A knowledge base has to be retrievable by MEANING, not only by exact keyword: "ile mamy na zwroty" should find
an entry that says "polityka reklamacji." That requires embeddings, and embeddings cost money and can fail —
which meant the indexing layer had to answer three questions before any code was written: which vector backend,
how to keep re-indexing cheap as entries are edited, and how a search stays useful when the AI leg is degraded
or unavailable.

**A B0 spike (GO/NO-GO) verified pgvector 0.8.0 was available on every target environment** before any of this
was built (the module's memory record: "B0 done (spike GO)").

## Decisions

**D1 — pgvector is the production vector backend, behind a contract with a PHP fallback.** `KnowledgeSimilaritySearch`
is bound per-resolution (never a singleton) to `PgVectorSimilaritySearch` by default, or
`PhpCosineSimilaritySearch` when `knowledge.vector_store` is set to `php`. The pgvector implementation ranks
INSIDE Postgres against an HNSW index (`vector_cosine_ops`, `m=16`, `ef_construction=64`) and never materializes
a vector into PHP; the PHP fallback is an explicit escape hatch that reads every candidate row and is bounded by
a hard candidate cap — it does not scale past a small base and exists so the module is not hard-down on a
non-pgsql connection or in an environment where the extension cannot be installed.

> **Production caveat — pgvector requires a superuser (or an explicitly-granted role) to install.**
> PostgreSQL treats `vector` as an **untrusted** extension (unlike a handful of extensions PG13+ marks
> `trusted`), so `CREATE EXTENSION vector` fails for an ordinary application role without `CREATEROLE`/superuser
> privileges — or without the extension already being present in the target database. The module's own first
> migration (`create_vector_extension.php`, both the central copy and the tenant mirror) issues `CREATE
> EXTENSION IF NOT EXISTS vector` itself, which is sufficient in every environment where the migrating role
> already has the privilege (local dev, most managed Postgres offerings that pre-authorize it for the app role).
> It is **not** sufficient on a host where the app's database role is deliberately unprivileged. A freshly
> provisioned own-database (own-DB) tenant workspace is the sharpest case: `WorkspaceProvisioner` creates the
> tenant database from scratch and runs the full tenant migration tree against it immediately — there is no
> `template1` pre-seeding step, so the tenant's own `create_vector_extension.php` migration is the *only* place
> the extension is installed for that database. **Before the first production deploy, a DBA must confirm the
> migrating role can create the extension** — either by pre-installing `vector` into `template1` (so every
> newly created database inherits it and the migration's `IF NOT EXISTS` becomes a no-op) or by granting the
> migrating role the privilege. This is an open operational item, not resolved by this ADR or by any code in
> the module — it is the kind of gap that passes every environment this project has touched so far and then
> fails, opaquely, the first time provisioning runs against infrastructure nobody configured for it.

**D2 — the chunker is a deterministic, pure function, versioned separately from the embedding model.**
`KnowledgeChunker::chunk()` takes only `(title, content, config)` — no AI, no database, no clock, no
randomness — and its output feeds a per-chunk `digest`. Determinism is a cost guarantee: identical input must
always produce identical chunks, or a splitter that drifted between two runs over the same text would re-buy
every vector in the base each time it ran (pinned by a determinism test). The cascade is structural-first:
headings (`#`..`######`, carrying a heading trail rooted at the entry title) → paragraphs → sentences (with a
Polish-abbreviation-aware boundary detector, `np.`/`m.in.`/`itd.` etc., so a list of examples does not shatter
into fragments) → a hard character cut as the last resort. Undersized adjacent sections are merged (taking their
common-ancestor heading path) before splitting; overlap is applied ONLY at a hard cut, never across a heading or
paragraph boundary, because overlap exists to repair a cut that severed a thought, which only a hard cut does by
construction. `chunking.version` is folded into the digest, so bumping it marks every entry in the workspace
stale at once with no write anywhere.

**D3 — indexing is DIFFERENTIAL, keyed on a digest MULTISET match, never a full re-embed.** Every stored chunk
carries a digest over its heading path + text; a re-index pairs the freshly-chunked drafts against the stored
rows by digest — a match REUSES the vector (only its address, i.e. ordinal/heading-path/offsets, is refreshed),
a new digest gets embedded, and a stored row whose digest no longer appears anywhere is deleted. Pairing is done
as multisets (not a map) specifically because an entry may legitimately repeat the same sentence under the same
heading, and map-based pairing would thrash (delete-then-reinsert an identical row on every run). The practical
consequence: editing one paragraph of a 25-chunk entry costs ONE embedding call, not twenty-five; re-saving an
entry unchanged costs zero (the run returns before the chunker is even invoked, because
`index_digest == indexed_digest`). `index_params` (a short hash of chunker version + embedding model + width) is
stored alongside the digest specifically so a config change — no entry write anywhere — is still detected by the
sweep in SQL, without re-hashing every entry's text to notice.

**D4 — one batch of embeddings per entry, capped, and the cap is enforced before the write, not after.**
`knowledge.chunking.max_chunks_per_entry` (default 50) bounds an entry's total spend regardless of how it is
written — 40 000 characters as forty short sections under forty headings produces more chunks than the same
length as three long ones, so the character cap alone does not imply the chunk cap. `embed_batch` defaults to
that same number, which is what makes a whole entry — however long — cost exactly one provider round-trip in the
common case. The entry FormRequest DRY-RUNS the real chunker over the submitted text and refuses (422,
`too_many_chunks`) an entry that would overflow the cap at the door, rather than letting the save succeed and
the background job fail invisibly hours later where the only signal is a status the writer has no reason to
check.

**D5 — indexing degrades to `partial`/`pending_budget`, and a save is NEVER blocked by it.** The index run sits
entirely behind the shared `MeteredAiCall` gate on the `ai_embedding` channel — asserted once up front (an
over-cap workspace does no work at all) and again around every embedding batch. A batch that succeeded is KEPT
even when a later one in the same run is refused or errors; the entry settles as `partial` (some passages
embedded — genuinely, incompletely retrievable) rather than `failed` (which would discard work already paid
for), or `pending_budget` (refused before spending anything — nothing broken, no retry will help until the cap
moves). Crucially, none of this happens synchronously with a write: the entry is committed by
`KnowledgeEntryService` long before `IndexKnowledgeEntryJob` runs, dispatched via `DB::afterCommit()` from
`KnowledgeEntryObserver`. A workspace that has hit its AI cap can still write and edit every entry in its base;
only the semantic index — not the text, not the base — waits.

**D6 — hybrid search fuses a keyword leg and a vector leg by RECIPROCAL RANK, never by summing scores.** The two
legs fail in opposite, non-overlapping ways: vector search cannot find text it has not embedded yet (a
just-saved entry, a budget-refused one) and is weak on exact tokens (a product code, a surname); keyword search
cannot find a paraphrase. The two legs' scores are not comparable quantities (`[0,1]` cosine similarity vs. a
bare rank with no score at all), so RRF (`1 / (K + rank)`, `K = 60`, the TREC-standard constant) was chosen over
inventing a normalization — any such invention becomes a hidden tuning parameter that silently decides which leg
wins and drifts the moment the embedding model changes. The lexical leg is also the search's DEGRADED MODE:
every reason the vector leg can be unavailable (AI cap, kill switch, provider outage, a non-pgsql connection)
leaves it working, and the response says so explicitly (`vector_search_skipped` + a reason) rather than either
erroring or silently returning fewer results with no explanation — an ordinary, expected cost event must never
read as an outage, and "no results" must never be mistaken for "nothing is written about this."

**D7 — a new embedding channel, and `deriveTokens` widened to carry real usage.** Embedding spend is metered on
`ai_embedding` (a sibling of the AI cost meter's existing channels — see ADR-0033/ADR-0037), and the meter's
token-derivation path was extended to read the REAL token count off `EmbeddingsResponse->tokens` rather than
estimating — an embedding call's cost is priced by input tokens and a workspace's AI-usage report would
otherwise silently under- or over-count this channel relative to every text/image channel it already meters
correctly. One consequence worth flagging in review: `estimated_cost` is stored as `decimal(10,4)`, and a single
embedding call is frequently cheap enough that its cost rounds to `0.0000` in the ledger — this is a genuine,
accepted precision limit of the existing money column, not a bug specific to this channel; a workspace's
aggregate spend across many calls is still correct, only the per-call line item under-reports for the smallest
individual charges.

**D8 — similarity edges are MATERIALIZED, not computed on read, and a dismissal is permanent until undone.**
`KnowledgeSimilarityLinker` runs on every re-index (gated on the run having actually rebuilt something, so a
sweep pass over an already-current base costs nothing beyond the digest comparison) and writes `similarity`
edges for passages above `knowledge.similarity.threshold` (0.86 — deliberately high, because a false suggestion
costs a reader's trust in every subsequent one while a missed suggestion costs nothing visible). The pass
REPLACES an entry's live similarity edges (delete + insert, not a diff — mirroring the wikilink sync's own
reasoning) but explicitly skips re-proposing any target a human has DISMISSED (`KnowledgeLink.dismissed_at`),
enforced by the unique key `[from_entry_id, target_slug, source]` colliding rather than silently resurrecting
a rejected suggestion. This is why the graph, the reader's "related" panel, and the base overview are all
answerable without a single embedding call on page load — the cost is paid once, at index time, not on every
view.

**D8a (B10) — `similarity.min_chunk_chars` is a FRAGMENT floor, and a whole-entry passage is exempt.** The
300-character floor exists to stop a two-line passage of a long document from matching every other two-line
passage on form rather than meaning. That argument does not transfer to an entry that is short *in its
entirety*: a dictionary-style note is a complete unit of meaning, and the text that gets embedded carries the
entry's title besides. Applying the floor to those was found on dev to make short entries invisible to the
graph — a base of two short notes drew nothing at all, with no signal on screen explaining why. So a chunk
participates when `char_length >= floor` **or** it is its entry's only chunk, exempted at both ends (source
and candidate) for the same symmetry reason the floor itself has. The threshold is untouched: the exemption
decides who may be COMPARED, never how close they must be.

**D8b (B10.1) — the similarity bar is LENGTH-AWARE: a second threshold, not a lower one.** Cosine is not
comparable across lengths. A long passage averages many sentences into its vector, so two documents about
one subject land high; a one-sentence note has almost nothing to average, so the SAME relation lands far
lower — the 0.2-0.5 / 0.85+ calibration behind 0.86 was measured on long prose and does not describe short
text. Measured on dev: two entries any reader calls obviously related scored **0.6498**, drawing nothing.
A pair is therefore judged against `similarity.threshold_short` (0.60) when EITHER end is shorter than
`similarity.short_chunk_chars` (300), and against `similarity.threshold` (0.86) otherwise. Either end
suffices — requiring both to be short would leave a note and the article about it permanently unlinkable.
A second bar rather than a lower single one, because dropping 0.86 globally buys short-text recall by
wrecking precision on exactly the passages the number was set for. Consequence worth stating: the graph's
default `min_score` had to follow the LOWEST bar that can produce an edge, or short-pair edges would be
written to the database and filtered straight back out of the default view.

External patterns: see `docs/ai/reference-links.md` → Knowledge module, entry 8 — no other researched
product publishes a length-aware calibration like this pair, so 0.86/0.60 are confirmed as Taskio's own;
the cheaper lever other implementations reach for instead (what gets embedded, plus a per-entry edge cap)
is already covered by `similarity.max_links`.

**D9 (B10) — `mention` edges: the graph derives itself from prose, deterministically and for free.** A graph
built only from `[[wikilinks]]` depends on a discipline nobody keeps — people write "wieży Eiffla", not
`[[wieza-eiffla]]` — so in practice it stays empty and then gets blamed for being useless.
`KnowledgeMentionLinker` therefore scans an entry's resolved content for the TITLES of other live entries in
the base (`Support\MentionScanner`) and writes `source = mention` edges: no AI call, no configuration, no
per-language dictionary, so it can run on every index pass forever.

The heuristic is deliberately dumb and stated exactly: both sides are tokenized and normalized through the
module's own slug transliteration; a title matches when ALL of its words appear CONSECUTIVELY in the content;
a content word matches a title word by stem (title word minus up to 3 trailing characters, minimum 4 — Polish
inflection makes an exact-match rule useless), and title words under 4 characters must match exactly. DATE
TOKENS are stripped from the title first (B10.1: bare numbers, Polish month names in both case forms), because
real titles are dated and prose never repeats a date stamp — one stray "2026" made such a title permanently
unmatchable, which is what the layer did on the first real data it met. What survives must still be a name: a
title reducing to nothing or to one word under 5 characters is not scanned for, and subset matching of single
title words was rejected outright (it would connect every note containing "podróż" to everything). It runs
in BOTH directions on every index: forward (this entry names others) and reverse (older entries that already
name this one, pre-filtered by one indexed `like` on the title's longest stem and capped by
`links.reverse_scan`). The reverse pass is not an optimisation — without it a newly created entry arrives with
no inbound edges and stays unreachable until every older note happens to be re-saved.

A mention DEFERS to authored edges (no mention where a `wikilink` or `manual` edge already points — the
author's explicit commitment is the stronger statement), draws no ghosts (it can only name something that
exists), is capped at `links.max_mentions` per entry, and honours `dismissed_at` exactly as similarity does.
Accepted cost: truncation stemming over-matches, so a title that is an ordinary noun will be found inside
longer words built from it. That is tolerable because a mention is a *dismissible suggestion* distinguishable
by `source`, and the alternative — an empty graph — is the failure that was actually observed.

External patterns: see `docs/ai/reference-links.md` → Knowledge module, entries 4–5 — the
propose-not-mutate shape here matches Obsidian/Virtual Linker/`WP:CONTEXTBOT` exactly (independent field
confirmation, not a change); the stem-truncation heuristic's accepted future direction is per-entry
aliases first, dictionary lemmatization second (flagged there as unverified in a PHP stack), and
Aho-Corasick if/when per-title scanning cost is measured to matter.

**D10 (B10) — `links.version` is folded into the entry digest, so a link-heuristic change is deployable.**
Bumping it moves every entry's `index_digest` and `index_params`, so the sweep re-runs the whole base — but
the CHUNK digests are unchanged, so every stored embedding is reused and the re-run buys nothing. Improving a
link heuristic is therefore a one-line config change plus a sweep, not a migration that re-embeds a workspace.
Pinned by a test asserting zero embedder calls across a version-bump sweep.

## Alternatives considered

- **A managed vector database (Pinecone, Weaviate, etc.) instead of pgvector.** Rejected — the app already runs
  Postgres per-workspace (including per-tenant own-database workspaces), and a separate vector store would mean
  a second system to provision, secure, and keep in sync per tenant, for no capability this product needs yet
  (pgvector's HNSW index is adequate at the scale of a workspace's knowledge base).
- **tsvector for the "lexical" leg.** Rejected — Postgres' built-in full-text search stems primarily for English;
  this is a Polish-first product, and the existing shared `Searchable` scope (plain `ILIKE`) was judged a more
  honest baseline than a stemmer that would silently misbehave on Polish morphology. The lexical leg therefore
  rides the same scope every other list search in the app uses.
- **Exposing pgvector's raw cosine DISTANCE (`<=>`) instead of converting to similarity.** Rejected — the
  conversion (`1 - distance`) happens exactly once, inside the similarity-search implementation, so every
  downstream reader (the API's `matched_chunk.score`, the similarity linker's `threshold`) shares a single,
  comparable definition of "how alike" in the intuitive `[-1, 1]` direction, rather than each caller having to
  remember which way round the number runs.
- **Estimating embedding token cost from input length rather than reading the provider's own count.** Rejected
  once the response was confirmed to carry `tokens` — an estimate would drift from the provider's actual pricing
  basis for no benefit.

## Consequences

- Retrieval quality (see ADR-0045) is bounded by what has actually been indexed; an entry that is `pending`,
  `partial`, or `pending_budget` is silently less findable by meaning until the sweep or a retry catches up
  (`knowledge:sweep-index`, every 10 minutes; `knowledge:reap-stale-index`, every 5 minutes, releasing a claim
  a dead worker abandoned). The keyword leg and the inline-compilation fallback (ADR-0045) are what keep this
  from ever being a hard outage.
- A model or chunker-version change is a config edit that silently re-indexes the whole workspace over the next
  several sweep passes — an operator changing `knowledge.embedding.model` should expect a real, metered
  re-indexing bill, not a silent no-op.
- The pgvector superuser caveat (D1) is an open, unresolved operational item that must be closed before the
  first production deploy onto infrastructure the team does not already control the database role for.
- `App\Modules\Knowledge\Support\ChunkVector` remains the module's one, documented exception to "prefer Eloquent
  over `DB::`" — any future change to how the vector column is read or written must go through it, never a new
  raw query elsewhere in the module.
