<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | The Knowledge base is retrieved SEMANTICALLY: every indexed entry is embedded once and
    | looked up by vector similarity. These pin the embedding contract.
    |
    | provider    The laravel/ai provider the embedding call targets. Defaults to the app-wide
    |             AI provider so knowledge rides the SAME provider as the rest of the app.
    | model       The embedding model. text-embedding-3-small is the cost/quality default.
    | dimensions  Vector width. LOAD-BEARING and effectively IMMUTABLE once anything is indexed:
    |             it must match the stored `vector(N)` column, so changing it invalidates every
    |             existing embedding and requires a re-index migration. 1536 is the native width
    |             of text-embedding-3-small.
    |
    */

    'embedding' => [
        'provider' => env('KNOWLEDGE_EMBEDDING_PROVIDER', 'openai'),
        'model' => env('KNOWLEDGE_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('KNOWLEDGE_EMBEDDING_DIMENSIONS', 1536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector store
    |--------------------------------------------------------------------------
    |
    | Which similarity backend serves retrieval.
    |
    | pgvector  The default and the only supported production path: a `vector(dimensions)`
    |           column with an HNSW index, ranked by the cosine-distance operator `<=>`.
    |           Verified available (pgvector 0.8.0) on every environment in the B0 spike.
    | php       An in-process fallback that scores candidates in PHP. Escape hatch only —
    |           it reads every candidate row, so it does not scale past a small base.
    |
    */

    'vector_store' => env('KNOWLEDGE_VECTOR_STORE', 'pgvector'), // pgvector|php

    /*
    |--------------------------------------------------------------------------
    | Size caps
    |--------------------------------------------------------------------------
    |
    | entry_max_chars   Length cap on ONE stored knowledge entry (B1). Raised from the original
    |                   8000 to 40000 once CHUNKING landed as the design: an entry is no longer
    |                   embedded whole, it is split into passages that are embedded individually,
    |                   so the old cap was measuring the wrong thing (an embedding call's input)
    |                   against the wrong unit (a document a human writes). 40k characters is a
    |                   long article — enough that a real policy/spec does not have to be split
    |                   across artificial entries — while still bounding one row, one editor
    |                   payload, and (via max_chunks_per_entry) one entry's indexing spend.
    | inline_max_chars  Cap on the TOTAL knowledge text injected into one consumer's context.
    |                   Deliberately the same figure as the bot's existing
    |                   `ai.knowledge_max_chars` (8000) — the two bound the same kind of budget,
    |                   and keeping them equal stops retrieved knowledge from being sized
    |                   differently per consumer. UNCHANGED by the entry raise: what one entry may
    |                   HOLD and what a consumer may be HANDED are different budgets, and coupling
    |                   them is how a single long entry silently eats a whole prompt.
    |
    */

    'entry_max_chars' => 40000,

    'inline_max_chars' => 8000,

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | How an entry is split into the passages that actually get embedded. Declared in B1 (the
    | schema + digest depend on it); the splitter itself lands in B2a.
    |
    | version              Bumped whenever the SPLITTING ALGORITHM changes. It is folded into an
    |                      entry's `index_digest`, so a chunker change marks every entry stale and
    |                      re-indexes it — without this, improving the splitter would silently
    |                      leave old entries chunked the old way forever.
    | target_chars         What the splitter aims for per chunk. ~1800 characters is a few
    |                      paragraphs: big enough to carry an argument, small enough that a match
    |                      points at something specific.
    | max_chars            Hard ceiling per chunk; the splitter may overshoot the target up to here
    |                      to avoid cutting mid-sentence.
    | min_chars            Below this a trailing fragment is merged into its predecessor instead of
    |                      becoming a chunk of its own — a 30-character chunk embeds to noise.
    | overlap_chars        How much of the previous chunk each chunk repeats, so a fact that
    |                      straddles a boundary is retrievable from both sides.
    | max_chunks_per_entry Fan-out cap. This is the per-entry SPEND bound: one entry can never cost
    |                      more than this many embedding calls, however it is written.
    |
    */

    'chunking' => [
        'version' => 1,
        'target_chars' => 1800,
        'max_chars' => 2000,
        'min_chars' => 400,
        'overlap_chars' => 200,
        'max_chunks_per_entry' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexing
    |--------------------------------------------------------------------------
    |
    | enabled      The module's KILL SWITCH for AI spend. False → nothing is embedded and no
    |              provider call is made from this module at all (retrieval then degrades to
    |              whatever non-semantic path B1 defines). Every AI cost this module can incur
    |              must sit behind this flag, so an operator has one switch to stop it. It is
    |              honoured as the FIRST statement of the indexing job, before tenancy is even
    |              restored, so flipping it can never leave a half-run behind.
    | embed_batch  How many chunk texts go into ONE embedding provider call. Defaulted to
    |              `chunking.max_chunks_per_entry`, which is what makes a whole entry — however
    |              long — cost exactly ONE provider round-trip. Lowering it splits an entry across
    |              several INDEPENDENTLY GATED calls, which is also the only way an entry can end
    |              up `partial` inside a single run rather than across two.
    | sweep_batch  Upper bound on how many entries ONE sweep pass dispatches per workspace. The
    |              sweep is a catch-up mechanism, not a stampede: a workspace that just imported a
    |              thousand entries drains over several passes instead of filling the queue (and
    |              burning its whole month's budget) in one minute.
    | stale_after  Seconds an entry may sit in `indexing` before the reaper decides its worker
    |              died and sends it back to `pending`. A worker killed by SIGKILL/OOM never runs
    |              the job's failed() hook, so without this an entry would hold `indexing` forever
    |              and the sweep — which skips claimed entries — would never look at it again.
    |              Must EXCEED the job's own timeout so a slow-but-alive run is never reaped.
    |
    */

    'index' => [
        'enabled' => (bool) env('KNOWLEDGE_INDEX_ENABLED', true),
        'embed_batch' => (int) env('KNOWLEDGE_INDEX_EMBED_BATCH', 50),
        'sweep_batch' => (int) env('KNOWLEDGE_INDEX_SWEEP_BATCH', 100),
        'stale_after' => (int) env('KNOWLEDGE_INDEX_STALE_AFTER', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | The user-facing HYBRID search (B2b): a lexical leg and a vector leg, fused by reciprocal rank.
    |
    | chunk_top_k  How many PASSAGES the vector leg pulls before they are grouped into entries. It is
    |              deliberately several times larger than `max_results`: a single entry can occupy
    |              many of the top passages (a long document is many chunks of one topic), so a K
    |              equal to the result count would routinely return three entries. 40 is the size at
    |              which a page of 25 results is reachable even when the top matches cluster.
    | max_results  How many ENTRIES one search returns. There is no pagination — deliberately. A
    |              relevance-ranked list has no stable cursor (the ordering is recomputed from a fresh
    |              query embedding every time), and a "page 2" of semantic results is not something a
    |              reader wants: past the first screen the scores have flattened and refining the
    |              query beats scrolling. If a caller needs the whole base, the ENTRY LIST endpoint —
    |              which is cursor-paginated and stably ordered — is the right tool.
    |
    */

    'search' => [
        'chunk_top_k' => (int) env('KNOWLEDGE_SEARCH_CHUNK_TOP_K', 40),
        'max_results' => (int) env('KNOWLEDGE_SEARCH_MAX_RESULTS', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval (the AI consumers' read — B6)
    |--------------------------------------------------------------------------
    |
    | What a BOUND CONSUMER (a bot today) is handed when its binding runs in `rag` mode. Deliberately
    | separate from the `search` numbers above even though both rank the same passages: they answer
    | different questions and are tuned against different budgets.
    |
    | chunk_top_k      How many PASSAGES the vector leg pulls before packing. Much smaller than the
    |                  human search's 40, because the consumer is not choosing from a list — every
    |                  passage that fits goes into a prompt, and past a dozen the marginal passage
    |                  crowds out the task's own context while adding nothing a model will read
    |                  carefully. The character budget bounds the block either way; this bounds how
    |                  many rows are considered for it.
    | expansion_limit  How many extra entries the free ONE-HOP expansion may add — the opening passage
    |                  of entries a materialized link connects to whatever matched. It costs no
    |                  embedding (the edges are already stored), so the only thing to bound is prompt
    |                  space. 5 is enough for "there is also a document about this" without letting a
    |                  densely-linked base push the actual matches out of the budget. Set to 0 to
    |                  disable expansion entirely.
    |
    */

    'retrieval' => [
        'chunk_top_k' => (int) env('KNOWLEDGE_RETRIEVAL_CHUNK_TOP_K', 12),
        'expansion_limit' => (int) env('KNOWLEDGE_RETRIEVAL_EXPANSION_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Similarity links
    |--------------------------------------------------------------------------
    |
    | The MATERIALIZED "these two entries look related" edges, recomputed for an entry whenever it is
    | re-indexed. They are stored (rather than computed on read) because the graph and the reader's
    | "related" panel must be answerable without an embedding call — a suggestion nobody pays for on
    | every page view is a suggestion that can be shown everywhere.
    |
    | threshold        Minimum cosine similarity for an edge between two LONG passages. 0.86 is
    |                  deliberately HIGH: a false suggestion costs a reader's trust in every other
    |                  suggestion, while a missed one costs nothing they can see. Modern text
    |                  embeddings put unrelated business prose around 0.2-0.5 and genuinely
    |                  overlapping passages above 0.85, so this sits just inside the "same subject"
    |                  band. Lowering it does NOT rewire existing edges — they are rewritten on the
    |                  next re-index of their source entry, which is what keeps the pass cheap.
    |
    | threshold_short  The same bar for a pair where EITHER end is short. Cosine is not comparable
    |                  across lengths: a long passage averages many sentences into its vector, so two
    |                  documents about one subject land high; a one-sentence note has almost nothing to
    |                  average, so the same relation lands far lower. The 0.2-0.5 / 0.85+ calibration
    |                  above was measured on long prose and simply does not describe short text.
    |
    |                  MEASURED on dev: two entries a reader calls obviously related — "Zoja66 zwiedzała
    |                  wieżę Eiffla" and "Zoja66 była na wycieczce w Paryżu" — score 0.6498. Under the
    |                  long-prose bar they draw nothing, which is exactly the empty graph this whole
    |                  layer exists to prevent. 0.60 sits below that measurement and still far above the
    |                  unrelated band.
    |
    |                  A SECOND threshold rather than a lower single one: dropping 0.86 globally would
    |                  buy short-text recall by wrecking precision on the long passages the number was
    |                  calibrated for, which is the trade nobody wants.
    |
    | short_chunk_chars  What "short" means for the rule above. Deliberately its own key, even though it
    |                  defaults to the same 300 as `min_chunk_chars`: the two govern different decisions
    |                  and only happen to agree today. `min_chunk_chars` answers "may this FRAGMENT take
    |                  part at all"; this one answers "does this passage need a gentler bar". Pointing
    |                  one at the other would make a future change to either silently move both.
    | min_chunk_chars  Floor on the length of a passage that may propose or receive an edge — a
    |                  FRAGMENT floor, not a document floor. A 60-character fragment of a long entry
    |                  ("## Cennik" plus a sentence) is similar to every other short heading in the
    |                  base for reasons that have nothing to do with meaning, and letting those through
    |                  is how a graph turns into a hairball. Applied to BOTH ends, so the rule means the
    |                  same thing in both directions.
    |
    |                  IT DOES NOT APPLY TO A WHOLE-ENTRY PASSAGE (an entry that is a single chunk).
    |                  The anti-boilerplate argument is about fragments: a note that is two lines long
    |                  is a COMPLETE unit of meaning, and what gets embedded carries its title besides.
    |                  Applying the floor to those made short entries invisible to the graph — a base of
    |                  dictionary-style notes drew no edges at all, with nothing on screen to say why.
    |                  Exempted on both ends, for the same symmetry reason the floor itself has.
    | max_links        Most edges one entry may DRAW. A cap, not a target: an entry that genuinely
    |                  relates to twenty others is an entry that should be split, and a node with
    |                  twenty edges is unreadable in the graph either way.
    |
    */

    'similarity' => [
        'threshold' => (float) env('KNOWLEDGE_SIMILARITY_THRESHOLD', 0.86),
        'threshold_short' => (float) env('KNOWLEDGE_SIMILARITY_THRESHOLD_SHORT', 0.60),
        'min_chunk_chars' => (int) env('KNOWLEDGE_SIMILARITY_MIN_CHUNK_CHARS', 300),
        'short_chunk_chars' => (int) env('KNOWLEDGE_SIMILARITY_SHORT_CHUNK_CHARS', 300),
        'max_links' => (int) env('KNOWLEDGE_SIMILARITY_MAX_LINKS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Link derivation (B10)
    |--------------------------------------------------------------------------
    |
    | The MENTION layer: edges found by reading one entry's prose for another entry's TITLE, so a base
    | connects itself without anybody typing `[[wikilinks]]`. Deterministic and free — no AI call — so
    | it runs on every index pass.
    |
    | version       Bumped whenever the DERIVATION changes (the scanner's heuristic, the caps, the
    |               direction rules). It is folded into every entry's `index_digest` exactly as
    |               `chunking.version` is, so a bump marks the whole base stale and the sweep re-derives
    |               its edges. The chunk digests do NOT move, so the re-run REUSES every stored
    |               embedding: recomputing the graph costs database work and nothing else. Without this
    |               a heuristic improvement would only ever reach entries somebody happened to edit
    |               afterwards.
    | max_mentions  Most mention edges one entry may DRAW. Lower than a wikilink's ceiling on purpose:
    |               a mention is inferred, so a long article naming thirty other notes would bury its
    |               three real relations under a fog of incidental ones.
    | reverse_scan  Upper bound on how many EXISTING entries are examined when a newly indexed entry
    |               looks for older entries that mention IT (see KnowledgeMentionLinker — the direction
    |               that stops a new entry from being unreachable until every old one is re-saved). The
    |               pre-filter is one indexed `like` on the title's longest stem; this caps how many of
    |               its hits are then verified in PHP. 200 entries of up to 40 000 characters is a
    |               fraction of a second on a background job, and a base large enough to exceed it is a
    |               base whose sweep will finish the job on the next pass anyway.
    |
    */

    'links' => [
        // 2 — B10.1 taught the mention scanner to strip date tokens from titles.
        // 3 — the scanner now matches an entry's ALIASES as well as its title, so every existing base
        //     has edges it could not have had before. A sweep re-derives them for zero embeddings.
        'version' => (int) env('KNOWLEDGE_LINKS_VERSION', 3),
        'max_mentions' => (int) env('KNOWLEDGE_LINKS_MAX_MENTIONS', 10),
        'reverse_scan' => (int) env('KNOWLEDGE_LINKS_REVERSE_SCAN', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI drafting (the composer)
    |--------------------------------------------------------------------------
    |
    | One SESSION of the AI composer: raw material in, a set of proposed entries out, refined by
    | instruction until a human accepts or abandons it. Drafts are ordinary entries carrying a
    | `draft_session_id`, hidden from everything by a global scope and never indexed.
    |
    | source_max_chars       Cap on the raw material one session may carry. It is re-sent to the model on
    |                        EVERY refinement (the composer re-derives the whole set rather than editing
    |                        its own previous answer), so this bounds the per-refinement prompt, not just
    |                        the paste. 20k characters is a long article or a meeting's notes.
    | max_entries_per_session How many drafts one run may produce. A cap on the MODEL, enforced when its
    |                        answer is laundered: a run that proposes thirty entries has not understood
    |                        the material, and reviewing thirty machine-written drafts is not a thing
    |                        anybody does — they get accepted unread, which is the failure this whole
    |                        review step exists to prevent.
    | max_prompt_history     How many past instructions are replayed into the next prompt. The history is
    |                        what makes refinement cumulative ("shorter" then "add examples" must mean
    |                        both), but it is also unbounded user text going into every later call, so it
    |                        is capped and the OLDEST are dropped — the recent instruction is the one
    |                        being served.
    | prompt_max_chars       Cap on ONE refinement instruction.
    | abandon_after_days     After this long without activity a session is considered abandoned: its
    |                        drafts are purged and the session deleted. Drafts are invisible, so an
    |                        abandoned session is invisible clutter that would otherwise accumulate
    |                        forever — and it holds the user's raw source text, which is not something to
    |                        keep indefinitely for no reason.
    | duplicate_warn_threshold  Cosine above which an accepted draft is flagged as probably duplicating an
    |                        existing entry. A WARNING, never a refusal: the composer cannot know that two
    |                        similar entries are not both wanted. Higher than the similarity-edge
    |                        threshold — "these are related" and "this is the same thing again" are
    |                        different claims.
    |
    */

    /*
    | RETRIEVAL (B11b) — the context that lets the composer propose an AMENDMENT instead of a duplicate.
    |
    | retrieval_k            How many existing entries are retrieved as context when a session opens.
    |                        Small: each one is quoted into every later prompt, and a composer shown
    |                        twenty entries starts amending the loosely-related ones.
    | retrieval_excerpt_chars  How much of a retrieved entry is shown when it is CONTEXT rather than a
    |                        candidate for amendment: enough to judge whether the material belongs in
    |                        it, far short of the whole document.
    | amend_full_chars       How much of an AMENDMENT CANDIDATE is shown. The candidates are the top
    |                        `max_shadow_per_session` of the retrieval set, and they are shown the WHOLE
    |                        entry up to this figure.
    |
    |                        This key exists because of a real defect, and the defect is worth stating
    |                        plainly. The composer is instructed, when it amends, to return the entry's
    |                        COMPLETE new text. Shown a 1500-character excerpt of a 6000-character
    |                        entry it obligingly returns a "complete" body reconstructed from the
    |                        quarter it saw — and accepting that proposal DELETES three quarters of the
    |                        document, behind a diff that looks like an ordinary rewrite. Nothing
    |                        anywhere said so.
    |
    |                        12000 characters is where that stops being a live risk for real writing:
    |                        an entry may hold 40000, but an entry that answers ONE question is a few
    |                        pages and 12k is several of them. Past it the candidate is shown truncated
    |                        AND may only be APPENDED to — a rewrite of text the model has not read is
    |                        refused rather than trusted, which is the half of the fix that does not
    |                        depend on the model cooperating.
    | retrieval_total_chars  Hard ceiling on the whole context block, whatever the numbers above
    |                        multiply out to — the bound that actually protects the prompt. Raised from
    |                        8000 together with `amend_full_chars`: three full candidates and two
    |                        excerpts no longer fit under the old ceiling, and leaving it would have
    |                        silently undone the fix by dropping the very entries it exists to show in
    |                        full. Entries that do not fit are NAMED in an omission marker rather than
    |                        vanishing (the KnowledgeCompiler pattern) — a composer that believes the
    |                        base is silent on a subject writes a duplicate of it.
    | max_shadow_per_session How many AMENDMENTS one run may propose. Lower than the entry cap: a
    |                        reviewer comparing proposed changes against live entries is doing much
    |                        harder work than reading new drafts, and a run that wants to rewrite eight
    |                        existing entries has misread the material.
    | max_new_entities       How many ENTITIES (`N<n>`) one graph answer may declare. Same default as
    |                        the entry cap, because both channels end up creating entries and a reader
    |                        cannot be expected to know which one a page came from. It was hard-coded at
    |                        20 in the launderer while this file said 8, so the looser limit was the one
    |                        with no card of its own — and the overflow was dropped in silence. It is
    |                        reported now (`op_cap_reached`, scope `entities`).
    |
    | The retrieval set is FROZEN at session creation and never recomputed by a refinement — the
    | composer must keep judging its proposals against the same evidence it first saw, and re-retrieving
    | per refinement would spend an embedding on every instruction. `expand-context` is the explicit,
    | metered way to ask for more.
    */

    'drafting' => [
        'source_max_chars' => (int) env('KNOWLEDGE_DRAFT_SOURCE_MAX_CHARS', 20000),
        // 16, not 8 — see the note above the block. A subject-shaped division of one ordinary page of
        // narrative names eight to twelve subjects, and the cap TRUNCATES SILENTLY: the entries past it
        // are dropped in the laundering loop, so the last subjects in the document simply never exist.
        'max_entries_per_session' => (int) env('KNOWLEDGE_DRAFT_MAX_ENTRIES', 16),
        'retrieval_k' => (int) env('KNOWLEDGE_DRAFT_RETRIEVAL_K', 5),
        'retrieval_excerpt_chars' => (int) env('KNOWLEDGE_DRAFT_RETRIEVAL_EXCERPT_CHARS', 1500),
        'amend_full_chars' => (int) env('KNOWLEDGE_DRAFT_AMEND_FULL_CHARS', 12000),
        'retrieval_total_chars' => (int) env('KNOWLEDGE_DRAFT_RETRIEVAL_TOTAL_CHARS', 24000),
        'max_shadow_per_session' => (int) env('KNOWLEDGE_DRAFT_MAX_SHADOW', 3),
        // Kept level with the entry cap. Since the composer stopped declaring entities and the server
        // started synthesising one per create-draft, this can only ever be reached by the entry cap
        // first — a lower number here would silently deny handles to entries that were accepted.
        'max_new_entities' => (int) env('KNOWLEDGE_DRAFT_MAX_NEW_ENTITIES', 16),
        'max_prompt_history' => (int) env('KNOWLEDGE_DRAFT_MAX_PROMPT_HISTORY', 20),
        'prompt_max_chars' => (int) env('KNOWLEDGE_DRAFT_PROMPT_MAX_CHARS', 2000),
        'abandon_after_days' => (int) env('KNOWLEDGE_DRAFT_ABANDON_AFTER_DAYS', 14),
        'duplicate_warn_threshold' => (float) env('KNOWLEDGE_DRAFT_DUPLICATE_WARN', 0.88),
    ],

    /*
    |--------------------------------------------------------------------------
    | Graph extraction (G3) — reading the raw material for ENTITIES
    |--------------------------------------------------------------------------
    |
    | Before the composer writes anything it can be told WHO AND WHAT the material is about, matched
    | against the entries that already exist: "Łukasz" in a pasted note is the entry "Łukasz Barszcz",
    | and here is that entry's current text and its relations.
    |
    | enabled  THE KILL SWITCH for this whole layer, defaulting to FALSE. With it off the composer takes
    |          exactly the path it took before — one call, the same prompt, the same spend — so the
    |          feature can be shipped, measured and switched off again without a deploy. It is NOT a
    |          sub-switch of `index.enabled`: that one is the module's AI kill switch and still governs
    |          this too (it is checked first), but an operator must be able to stop paying for
    |          RESOLUTION without also stopping indexing, search and the composer itself.
    |
    | max_mentions  How many names ONE extraction call may return. A cap on the MODEL, enforced when its
    |          answer is laundered: a run that lists a hundred names has listed every noun in the
    |          document, and resolving a hundred names is a hundred lookups for an answer nobody reads.
    |
    | lexical_scan_limit  How many EXISTING ENTRIES the deterministic (free) matching pass loads. Past
    |          this the pass is INCOMPLETE, and the frozen result SAYS SO rather than pretending
    |          otherwise — a resolution that silently examined the first 2000 of 9000 entries would
    |          report "not found" for names that are plainly in the base, which is the kind of wrong
    |          answer nobody can debug from the outside. 2000 rows of names is a fraction of a second.
    |
    | edit_distance  How many single-character edits a name may be from an entry's own name and still be
    |          taken as that entry. 5 is where the entity-resolution literature converges for person and
    |          organisation names: it absorbs a typo and a missing diacritic without reaching from
    |          "Barszcz" to "Baran".
    |
    | relations_per_entity  Most relations one resolved entity carries into the frozen state. The point
    |          of that state is that a model can READ it; an entity dragging ninety edges into the
    |          prompt crowds out the material the session is actually about.
    |
    | total_chars  Hard ceiling on the whole frozen resolution block, mirroring `drafting`'s own. What
    |          does not fit is NAMED, never dropped in silence — same rule, same reason.
    |
    */

    'graph_extraction' => [
        'enabled' => (bool) env('KNOWLEDGE_GRAPH_EXTRACTION_ENABLED', false),
        'max_mentions' => (int) env('KNOWLEDGE_EXTRACTION_MAX_MENTIONS', 40),
        // How many FACTS one reading may list for the reviewer's checklist. It bounds three things at
        // once: the model's answer, the block the composer is then shown, and the panel a person has to
        // read — and the last is the binding one, because a checklist nobody finishes is a checklist
        // nobody uses. Overflow is truncated and REPORTED, never dropped in silence.
        'max_facts' => (int) env('KNOWLEDGE_EXTRACTION_MAX_FACTS', 30),
    ],

    'resolution' => [
        'lexical_scan_limit' => (int) env('KNOWLEDGE_RESOLUTION_SCAN_LIMIT', 2000),
        'edit_distance' => (int) env('KNOWLEDGE_RESOLUTION_EDIT_DISTANCE', 5),
        'relations_per_entity' => (int) env('KNOWLEDGE_RESOLUTION_RELATIONS_PER_ENTITY', 30),
        'total_chars' => (int) env('KNOWLEDGE_RESOLUTION_TOTAL_CHARS', 24000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Typed relations (the wiki graph)
    |--------------------------------------------------------------------------
    |
    | APPROVED statements between two entries — "Anna works on the refund project" — as opposed to the
    | derived `links` above, which are guesses the machine recomputes on every save.
    |
    | max_relations_per_entry  Most relations ONE entry may have, counting both directions and counting
    |                          only ACTIVE ones. A readability bound before it is a storage one: an
    |                          entry wired to forty others is a node no reader can interpret, and in
    |                          practice it means the entry is a category that should have been split.
    |                          Ended and retracted relations do not count — they are history, and
    |                          letting the past fill the budget would eventually make a long-lived
    |                          entry un-editable.
    | max_ops_per_session      Most relation operations ONE composer run may propose. Reserved for the
    |                          composer stage: a run that wants to rewire twenty edges has not
    |                          understood the material, and a reviewer asked to check twenty graph
    |                          changes approves them unread — which is the failure the review step
    |                          exists to prevent. Declared here with its sibling so the two bounds are
    |                          read together rather than discovered one at a time.
    |
    */

    'relations' => [
        // 200, not 40. A SUBJECT-shaped graph produces hubs by construction — the person a document is
        // about is an end of nearly every relation in it — and the cap is a HARD REFUSAL, so the
        // fortieth fact about the main subject was destroyed rather than queued. 40 was a sensible
        // readability bound for a graph of topics and is the wrong instrument for a graph of people.
        // Closed episodes no longer count towards it either (they are born `ended`), which removes the
        // other half of the pressure.
        'max_relations_per_entry' => (int) env('KNOWLEDGE_MAX_RELATIONS_PER_ENTRY', 200),
        'max_ops_per_session' => (int) env('KNOWLEDGE_MAX_RELATION_OPS_PER_SESSION', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject purge (erasure requests)
    |--------------------------------------------------------------------------
    |
    | The `knowledge:purge-subject` command — "erase everything about person X from the knowledge
    | base" — is irreversible by design, so its only real safety net is a BREADTH bound.
    |
    | max_entries  How many ENTRIES one phrase set may hit before the command refuses to run without
    |              --force. It is a MISTAKE detector, not a capacity limit: a phrase that matches
    |              hundreds of entries is almost always too generic (a first name, a two-letter
    |              string, an accidental `%`), and the failure mode of proceeding is destroying a
    |              workspace's knowledge base while trying to honour one person's request. 200 is
    |              far above any plausible real subject (a person named in a handful of notes) and
    |              far below "the whole base", which is the distinction it has to draw. An operator
    |              who genuinely means it passes --force.
    |
    */

    'purge' => [
        'max_entries' => (int) env('KNOWLEDGE_PURGE_MAX_ENTRIES', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Graph
    |--------------------------------------------------------------------------
    |
    | max_nodes  Hard cap on how many nodes ONE graph response may carry. It is a READABILITY bound
    |            first and a payload bound second: past roughly sixty nodes a force-free layout is a
    |            cloud, not a map, and a user cannot tell a dense region from a rendering artefact.
    |            What is cut is REPORTED (`truncated`) rather than silently dropped, so the UI can say
    |            "and 40 more" instead of pretending it drew everything.
    |
    */

    'graph' => [
        'max_nodes' => (int) env('KNOWLEDGE_GRAPH_MAX_NODES', 60),
    ],

];
