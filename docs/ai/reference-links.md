# AI / UX / Architecture Reference Links

This file stores external references the agents may use as inspiration. Each reference must be converted into concrete Taskio rules only after checking fit with the project.

## Skills / Agent Workflow

- https://github.com/multica-ai/andrej-karpathy-skills/tree/main
  - Use as inspiration for skill-style workflows: small, precise, repeatable procedures.
  - Do not copy blindly. Convert only useful workflow ideas into `.claude/skills/*/SKILL.md`.

## UX/UI References

- Dark mode color tinting:
  - https://uxmovement.medium.com/the-magic-of-dark-mode-color-tinting-9b62b0b93cb9
- Jointed fields / two-column forms:
  - https://uxmovement.medium.com/jointed-fields-how-to-simplify-2-column-forms-1a2279926d4e
- Mobile complex data tables:
  - https://uxmovement.medium.com/the-best-mobile-layout-for-complex-data-tables-e3ced21ce425
- Bento-box menus:
  - https://uxmovement.medium.com/bento-box-menus-a-better-way-to-navigate-lists-8bb761320985
- Table row actions:
  - https://uxmovement.medium.com/how-to-fit-8-action-buttons-in-a-table-row-8a6bceecd0e0
- Table status badges:
  - https://uxmovement.medium.com/the-right-way-to-design-table-status-badges-31f65a927dab
- Card metadata footers:
  - https://uxmovement.medium.com/why-your-cards-need-a-footer-for-metadata-c0a19cc5154d
- Better data display than field/value:
  - https://uxmovement.medium.com/a-better-way-to-display-data-than-field-value-e041cca9a6a9
- Data cards:
  - https://uxmovement.medium.com/data-design-tips-for-better-ui-cards-8d6a913df58d
- Data tables:
  - https://uxmovement.medium.com/10-design-tips-for-a-better-data-table-interface-8d6705e56be2
- Filter UI:
  - https://uxmovement.medium.com/a-guide-to-designing-better-filter-ui-components-8091ff975115
- Modals:
  - https://uxmovement.medium.com/anatomy-of-an-optimally-designed-modal-1fcf4070b8b7
- Loading buttons:
  - https://uxmovement.medium.com/when-you-need-to-show-a-buttons-loading-state-41fc4d5e3c65
- Disabled buttons:
  - https://uxmovement.medium.com/why-you-shouldnt-gray-out-disabled-buttons-f72fcd119127
- Dashboard icons:
  - https://uxmovement.medium.com/how-to-optimize-dashboard-icons-for-a-fast-visual-search-2a7dd53d358e

## RAG / Knowledge References

- Graph RAG:
  - https://medium.com/data-science/how-to-implement-graph-rag-using-knowledge-graphs-and-vector-databases-60bb69a22759
- Four levels of RAG:
  - https://cobusgreyling.medium.com/four-levels-of-rag-research-from-microsoft-fdc54388f0ff

Use these later only if Taskio needs knowledge-base/search architecture.

## Claude Code / Output References

- Claude Code HTML output:
  - https://medium.com/nginity/claude-code-html-output-why-markdown-lost-and-how-to-switch-63f483868242
- Premium startup prompts:
  - https://medium.com/vibe-coding/these-premium-leaked-startup-prompts-became-my-secret-weapon-92aa4e9cde2f

Treat prompt articles critically. Extract durable principles only.

## Laravel

- Laravel best practices Polish:
  - https://github.com/alexeymezenin/laravel-best-practices/blob/master/polish.md#inne-dobre-praktyki

Use as secondary guidance. Taskio project rules and current code style take precedence.

## Knowledge module — graph layout, labels, auto-linking, LLM relation extraction

Accepted by the owner 2026-08-04, after an external research pass (four lenses: PKM graph tools,
layout algorithms, auto-linking practice, AI-relation extraction/composition) plus an internal
critique pass that resolved contradictions and flagged unresolved gaps. Each entry below states the
concrete principle, the sources it came from, and how — or whether — it was adapted to Taskio's actual
implementation (`app/modules/Knowledge/**`, `resources/js/next/pages/knowledge/graph/**`). Cross-linked
from `docs/decisions/ADR-0044-knowledge-index.md` where a decision it bears on already exists.

1. **Graph layout: a barycentric pass with no fixed frame collapses to a point/line — pin the radius,
   optimize only the angle, and seed with the golden angle.**
   - Source: Nevron Barycenter Layout docs (fixed vs. free vertices, the classic Tutte 1963 result) —
     https://helpdotnetvision.nevron.com/UsersGuide_Layouts_Barycenter_Layout.html · Cytoscape's own
     circle/concentric guards (`dTheta = sweep / max(1, n−1)`, radius derived from the minimum angular
     spacing `rMin`, explicit `n=1` case) —
     https://raw.githubusercontent.com/cytoscape/cytoscape.js/master/src/extensions/layout/circle.mjs ,
     https://raw.githubusercontent.com/cytoscape/cytoscape.js/master/src/extensions/layout/concentric.mjs
     · golden-angle seeding as the standard deterministic, never-collinear init — d3-force's own
     phyllotaxis default — https://d3js.org/d3-force/simulation ,
     https://observablehq.com/@d3/force-layout-phyllotaxis , and the underlying math —
     https://www.nature.com/articles/srep15358 · determinism-as-a-feature evidence: years of Obsidian
     complaints about randomized graph positions —
     https://forum.obsidian.md/t/thoughts-on-the-graph-s-inconsistent-layout/13881 ,
     https://forum.obsidian.md/t/stop-randomizing-graph-view/32201 ,
     https://forum.obsidian.md/t/can-i-fix-the-nodes-position-in-graph-views-layout/3024 — versus
     TheBrain marketing its deterministic Plex layout as a selling point —
     https://thebrain.com/blog/navigating-beyond-the-plex .
   - Principle: a purely ordinal barycentric layout (no repulsion, no fixed frame) is mathematically
     guaranteed to degenerate; the standard remedy is NOT "add physics" but pin what a barycentric pass
     is allowed to move (radius stays fixed per ring; only angle is optimized) and seed angles with the
     golden angle so no two nodes ever start collinear.
   - Fit for Taskio: **adopted, adapted.** `knowledgeGraphLayout.ts` already commits to a deterministic
     ring/radial layout (see `resources/js/next/docs/pages/KnowledgePage.vue` §5) — this research
     confirms that choice is the industry's actual answer to "small graphs collapse," not a compromise
     against it, and supplies the concrete guards (`max(1, n−1)`, `rMin`, golden-angle seeding, the
     `n=1` special case) to close the small-n degeneracy without giving up the pure-function,
     zero-dependency contract the layout already has. **A seeded d3-force pass computed offline
     (`simulation.stop()` + fixed `tick(n)` count) is recorded as Plan B** if the pinned-radius fix is
     not enough — it is genuinely deterministic (fixed-seed LCG + phyllotaxis init, not "physics vs.
     determinism" as a false dichotomy) and still renders through the existing SVG, but was not chosen
     first because it is a larger diff (porting a physics step) for a cap of 60 nodes.

2. **Label visibility: no researched tool gates labels on a hard degree threshold — the standard is a
   screen-space budget (a grid) or a zoom-linked fade.**
   - Source: Logseq's occupancy grid (screen divided into 132×24px cells, nodes sorted
     `pinned → kind → degree`, labels assigned until the cell/180-label cap, deterministic) —
     https://raw.githubusercontent.com/logseq/logseq/master/src/main/frontend/extensions/graph/pixi/logic.cljs
     · Sigma.js's `LabelGrid` (labels-per-cell scales with zoom via `ratio`, deterministic tie-break by
     key) — https://github.com/jacomyal/sigma.js/blob/main/packages/sigma/src/core/labels.ts ,
     exposed params (`label_grid_cell_size`, `label_density`, `forceLabel`) —
     https://pypi.org/project/ipysigma/ · Foam's zoom-linked opacity fade (`scaleLinear`, domain
     `[1.2, 2.0]`) — https://raw.githubusercontent.com/foambubble/foam/main/packages/foam-graph/src/components/graph-canvas.ts
     · Obsidian's own zoom-fade slider (no degree gate at all) —
     https://github.com/obsidianmd/obsidian-help/blob/master/en/Plugins/Graph%20view.md .
   - Principle: label visibility is a rendering/readability budget, not a topological property of a
     node — a hard `degree >= N` cutoff is an invented rule with no precedent, and its failure mode is
     exactly what it produces on a small graph: most nodes silently lose their label for no reason a
     user can see.
   - Fit for Taskio: **adopted (grid variant chosen over fade).** A deterministic screen-cell budget
     (sort by degree, deterministic tie-break by slug — mirroring the existing total-order convention
     `knowledgeGraphLayout.ts` already uses elsewhere) was chosen over a continuous zoom fade because it
     is **snapshot-testable** the same way the rest of the layout is; a continuous fade is not. On a
     graph at or under the label cap every node keeps its label automatically, with no small-graph
     special case needed.

3. **SVG is adequate to roughly hundreds of nodes; canvas matters around ~3k, WebGL past ~10k+ — at a
   cap of 60, migrating rendering backends is a premature optimization.**
   - Source: a comparative benchmark (60 FPS held at 2361 nodes/7182 edges; drop below 30 FPS only past
     ~20k nodes/56k edges) — https://pmc.ncbi.nlm.nih.gov/articles/PMC12061801/ · practical community
     guidance ("SVG is fine below ~500 nodes") —
     https://graphaware.com/blog/scale-up-your-d3-graph-visualisation-webgl-canvas-with-pixi-js/ · Pixi/
     WebGL throughput past 10k nodes — https://dianaow.com/posts/pixijs-d3-graph .
   - Principle: rendering-backend migration is a scale decision with a known threshold, not a
     readiness/quality one; below that threshold it buys nothing and costs a real dependency.
   - Fit for Taskio: **adopted as a rejection.** `knowledge.graph.max_nodes` is 60 — one to two orders
     of magnitude below where SVG's own practical ceiling sits. No rendering-backend change is planned;
     this is recorded so a future "should we move to canvas" question has a documented, sourced answer
     rather than being re-litigated from scratch (revisit only if the cap itself is raised by an order
     of magnitude).

4. **Auto-linking: the automaton proposes, a human approves — no researched tool or community writes
   links into a document unsupervised.**
   - Source: Obsidian's "Unlinked mentions" panel — a suggestion surfaced for a manual "Link" click, not
     an auto-write — https://obsidian.md/help/plugins/backlinks , https://obsidian.md/help/aliases ·
     Virtual Linker's deliberate non-mutating overlay (links rendered in the editor, never written to
     the Markdown file) — https://github.com/vschroeter/obsidian-virtual-linker/blob/master/README.md ·
     Wikipedia's bot policy explicitly banning unsupervised "context-sensitive changes"
     (`WP:CONTEXTBOT`) for semantic reasons — the canonical example is "Dr. Suess" being either a typo
     for Dr. Seuss or the correct name of a different, real person Hans Suess — plus `MoS:Linking`
     treating overlinking itself as a quality regression —
     https://en.wikipedia.org/wiki/Wikipedia:Bot_policy ,
     https://en.wikipedia.org/wiki/Wikipedia:Manual_of_Style/Linking · Wikipedia's actual semi-automated
     compromise, Edward Betts's Find Link tool, which surfaces candidates for a human to approve —
     https://github.com/EdwardBetts/find_link .
   - Principle: mention detection belongs OUTSIDE the authored text, as a proposal a human (or an
     authoring agent — see #6) explicitly acts on — never as a background process that edits someone
     else's prose based on a string match, because homonymy and overlinking are semantic failure modes
     no confidence score reliably screens out.
   - Fit for Taskio: **already the adopted pattern — confirmed, not changed.** `mention` edges
     (`KnowledgeMentionLinker`, ADR-0044 D9) are exactly this: metadata beside the text
     (`KnowledgeLink` rows with `source: mention`), dismissible, never a text mutation. This research
     is recorded as external confirmation that the module's existing design is the field's actual
     consensus, not merely a defensible in-house choice — no code change follows from this entry.

5. **Polish inflection: per-entry aliases are the field's actual answer, not stem-heuristic matching;
   dictionary lemmatization and multi-pattern automata are the scale-up path, not the starting point.**
   - Source: Obsidian's frontmatter `aliases` participating in unlinked-mention matching (added v0.9.16)
     — https://obsidian.md/help/aliases ,
     https://forum.obsidian.md/t/include-aliases-in-the-unlinked-mentions/11422 — with inflection
     explicitly left to the user (a "partial aliases with placeholders" request for inflected languages
     stayed an open feature request, never shipped) —
     https://forum.obsidian.md/t/partial-aliases-with-placeholders-to-show-inflections-of-words-in-unlinked-mentions/32928
     · Glossary Linker's alternative — Porter/Snowball stemming for 6 languages plus **learning aliases
     from links a human already made** — https://community.obsidian.md/plugins/glossary-linker · the
     dictionary-lemmatization alternative for Polish specifically, `Morfologik` (used as an
     Elasticsearch analyzer by Allegro) — https://github.com/allegro/elasticsearch-analysis-morfologik ,
     and spaCy's `PhraseMatcher` comparing both sides in lemma space —
     https://spacy.io/api/phrasematcher · Aho-Corasick as the standard multi-pattern-over-one-text-pass
     algorithm, used for exactly this shape of problem (name-gazetteer entity linking) —
     https://en.wikipedia.org/wiki/Aho%E2%80%93Corasick_algorithm , https://arxiv.org/pdf/1509.01865 .
   - Principle: no serious tool regex-matches word stems for inflection; the two real answers are a
     dictionary of surface forms (aliases, authored or learned) or dictionary-backed lemmatization
     (both sides of a comparison reduced to the same lemma) — and multi-title scanning at scale is done
     with one automaton pass (Aho-Corasick), not a loop per title.
   - Fit for Taskio: **adopted as a documented direction, with an honest feasibility caveat.**
     `KnowledgeMentionLinker`'s current stem-truncation heuristic (ADR-0044 D9) stays as-is for this
     batch — this is not a code change. Recorded as the accepted DIRECTION for its evolution: (a) a
     per-entry `aliases` field (author- or agent-fed) is the cheapest, most field-tested fix for Polish
     inflection and is the natural next increment; (b) dictionary lemmatization (Morfologik-equivalent)
     is recorded as a longer-term option **explicitly flagged as unverified in this stack** — Morfologik
     itself is a Java/Elasticsearch tool, and no PHP/Laravel-native equivalent was confirmed to exist or
     perform adequately, so this must not be treated as a ready recipe; (c) Aho-Corasick is recorded as
     the correct algorithm to move to if/when the base is large enough that per-title scanning becomes a
     measured cost, not a default to adopt pre-emptively.

6. **An authoring agent writing wikilinks into content it is itself generating is NOT the same act
   `WP:CONTEXTBOT` bans — the ban is about unsupervised post-hoc mutation of someone else's text.**
   - Source: Reflect's "Decorate my writing with backlinks" — an LLM rewrites a user's own selection,
     inserting links inline — https://reflect.app/blog/automatically-add-backlinks-using-ai — kept
     deliberately separate from Reflect's own independent, client-side-embedding "similar notes" panel,
     which stays a suggestion — https://reflect.app/blog/ai-search · GitBook's Docs Agent, which gates
     any AI-authored or AI-modified document behind a change request with a full diff for human review
     before merge — https://www.gitbook.com/blog/introducing-docs-agent ,
     https://github.com/orgs/GitbookIO/discussions/1115 · Various Complements, which writes links at the
     moment of authoring (autocomplete), not as a later pass —
     https://tadashi-aikawa.github.io/docs-obsidian-various-complements-plugin/1.%20Features/Internal%20link%20complement/ .
   - Principle: the apparent contradiction with #4 resolves on WHO is being edited and WHEN: an author
     (human or agent) writing `[[links]]` into text they are originating is ordinary authorship, exactly
     like a human typing `[[wikilink]]` by hand; the WP:CONTEXTBOT prohibition targets a bot silently
     rewriting an EXISTING document it did not write, which is a different act with a different failure
     mode (homonymy in someone else's finished prose). The size of a change decides the gate: filling one
     field is direct-write-plus-undo (Notion/Tana precedent, not separately adopted here), generating or
     changing a whole document is always draft + diff + explicit approval (GitBook).
   - Fit for Taskio: **already the adopted pattern — confirmed, not changed.** `KnowledgeDraftAgent`
     already writes `[[slug]]` wikilinks into the entries it drafts (see
     `docs/decisions/ADR-0046-knowledge-ai-composer.md`), and every draft it produces is gated behind
     the composer's own draft session + human accept/reject step — this is exactly the "agent-as-author"
     + "draft/diff gate" combination this research describes, arrived at independently. Recorded here so
     the apparent tension with principle #4 (and with `KnowledgeMentionLinker` never writing into text)
     has a documented resolution rather than looking like an inconsistency on a future read.

7. **LLM relation extraction — implemented in ADR-0047: every serious framework uses a closed type
   vocabulary plus a hard POST-HOC filter in code — none trusts a model's own confidence score.**
   - Source: LlamaIndex's `SchemaLLMPathExtractor` — pydantic-model validation built from an enum
     schema; in `strict=True` mode a triplet outside the schema is silently dropped in code
     (`if (subject_type, relation, obj_type) not in kg_validation_schema[...]: continue`), and so is a
     self-loop (`subject.lower() == obj.lower()`) —
     https://github.com/run-llama/llama_index/blob/main/llama-index-core/llama_index/core/indices/property_graph/transformations/schema_llm.py
     , https://developers.llamaindex.ai/python/framework/module_guides/indexing/lpg_index_guide/ — with
     a documented failure mode even under strict mode (framework-generated artifact nodes leaking
     through) — https://github.com/run-llama/llama_index/issues/17549 · LangChain's
     `LLMGraphTransformer`, `strict_mode=True` **by default**, identical code-side filtering —
     https://reference.langchain.com/javascript/classes/_langchain_community.experimental_graph_transformers_llm.LLMGraphTransformer.html
     · Microsoft GraphRAG's pattern of carrying a **verbal description** alongside the relation type,
     not just a type label — https://microsoft.github.io/graphrag/index/default_dataflow/ · GraphRAG's
     own cost being the field's biggest documented criticism ($20–500 per corpus vs. $2–5 for plain
     vector RAG; LazyGraphRAG/LightRAG built specifically to answer it) —
     https://blog.premai.io/graphrag-implementation-guide-entity-extraction-query-routing-when-it-beats-vector-rag-2026/
     , https://eliteaiadvantage.com/blog/build-graphrag-lightrag-cheaper-microsoft · Neo4j's LLM Graph
     Builder as a second, independent confirmation of the same schema-plus-code-filter shape —
     https://github.com/neo4j-labs/llm-graph-builder .
   - Principle: an LLM's structured output is a SUGGESTION, and the enforcement of "what is even a
     legal relation" belongs entirely in code against a closed, human-authored vocabulary — never in
     the prompt alone and never gated on a confidence number the model reports about itself. A verbal
     relation description carries information a bare type label loses. Full GraphRAG's cost (gleanings +
     LLM-summarized entity/relation descriptions + community detection + community reports on every
     document) is disproportionate to a per-workspace mini-wiki.
   - Fit for Taskio: **implemented (`docs/decisions/ADR-0047-knowledge-typed-relations.md`), with one
     named departure from the principle above.** What shipped matches the field's practice at three
     independent implementations almost exactly: a closed, code-fixed enum of 15 relation types
     (`KnowledgeRelationType`), a hard POST-HOC filter in code (`KnowledgeGraphOps`, `RelationVocabulary`)
     rather than trust in the model's own confidence, a verbal `description` stored alongside every
     relation's type (not the type alone — the concrete refinement this entry recommended), and an
     explicit self-loop guard (`REJECT_SELF_LOOP`, `from === to` refused before anything else runs).
     **The departure:** the type-PAIR check (which entity types a verb may join) is ADVISORY rather than
     a hard filter when either end's type is unknown — `RelationVocabulary::check()` returns
     `UNKNOWN_TYPES`, not a refusal, and only a REFUSED verdict (both ends typed, pair not in the matrix)
     blocks a write. This is a deliberate, reasoned exception, not a drift from the principle: every
     entry in every base written before `entry_type` existed carries `null`, and the field's own
     "closed vocabulary, hard filter" recipe assumes a corpus the model typed as it went — refusing on a
     guess here would reject correct relations across a whole base's pre-existing history with total
     confidence. See ADR-0047 D4 for the full reasoning. **Full GraphRAG (community summarization,
     hierarchical Leiden reports) remains explicitly rejected** as disproportionate to this module's
     scale, and no LLM-graded relation judgment exists anywhere in the shipped code — every accepted
     relation is either drawn by a person or laundered against the closed vocabulary in code, exactly as
     this entry recommended; plain Leiden clustering (no LLM calls) stays recorded in reserve for a
     future browse/cluster mode, independent of the extraction question and still not built.

8. **Similarity thresholds are model-dependent and empirically tuned everywhere researched — no
   published precedent exists for Taskio's own length-aware calibration; the cheaper lever elsewhere is
   what gets embedded, not the threshold itself.**
   - Source: a from-deployment calibration (0.70–0.80 = "good balance," >0.90 = "almost no matches,"
     <0.60 = "too many irrelevant matches"; embedding title+description+tags+preview outperformed
     embedding full body text — "frontmatter captures the intent, the body contains noise") —
     https://dotzlaw.com/insights/obsidian-notes-03/ · OpenAI community consensus that a workable
     threshold must be tuned per model and per dataset (no universal number; ~0.45 cited as reasonable
     for `text-embedding-3-*`, vs. 0.7+ for older models) —
     https://community.openai.com/t/rule-of-thumb-cosine-similarity-thresholds/693670 ,
     https://www.s-anand.net/blog/embeddings-similarity-threshold/ · Neo4j's LLM Graph Builder materializing
     a separate `SIMILAR` layer via KNN with `KNN_MIN_SCORE` defaulting to 0.8, and entity-deduplication
     specifically combining cosine similarity (`DUPLICATE_SCORE_VALUE`, default **0.97**) with edit
     distance (`DUPLICATE_TEXT_DISTANCE`, default **5**) — with the actual merge always performed by a
     human in the UI — https://github.com/neo4j-labs/llm-graph-builder ,
     https://neo4j.com/docs/neo4j-graphrag-python/current/user_guide_kg_builder.html .
   - Principle: do not chase a "correct" universal similarity number — none exists, published or
     otherwise, and every serious implementation tunes it per model/dataset instead. The more durable
     lever is choosing WHAT gets embedded (a compact, intent-carrying representation beats the raw
     body) and bounding result count/edges per node, both of which are cheaper to reason about than
     threshold algebra.
   - Fit for Taskio: **adopted as external confirmation, not a source of new numbers.**
     `SimilarityThreshold`'s length-aware pair (0.86 long-form / 0.60 short-form, ADR-0044 D8/D8b) has
     **no published external precedent** for its specific length-aware shape — this research honestly
     could not find one, and the entry exists precisely to record that gap rather than imply a
     borrowed number. The two actionable, adopted takeaways are: (a) treat the current thresholds as
     Taskio's own calibration, not a copy of anyone else's; (b) if the graph's similarity edges are
     ever revisited, prefer tuning what gets embedded (and `knowledge.similarity.max_links`'s existing
     per-entry cap) over inventing further threshold tiers — this is a cheaper, better-precedented lever
     than a third or fourth bar. A future entity-deduplication concern (if the module ever needs one) has
     a concrete, sourced starting recipe: embedding similarity ~0.97 + edit distance, human-confirmed
     merge — not adopted now because Knowledge has no entity-deduplication problem today.

9. **No researched product treats the graph as the primary interface for consuming relations — a
   per-entry "related" panel is the first channel everywhere.**
   - Source: Obsidian Smart Connections' per-note "Connections view," ranked by score, workflow
     "scan → confirm → act" —
     https://github.com/brianpetro/obsidian-smart-connections , https://smartconnections.app/smart-connections/
     · Mem's Collections + "related notes" sidebar, similarity as suggestion —
     https://get.mem.ai/blog/organize-your-notes-with-ai-using-collections ,
     https://get.mem.ai/blog/mem-2-0-dev-update-mem-copilot · Reflect's independent embeddings-based
     "similar notes," separate from its graph — https://reflect.app/blog/ai-search · Neo4j's own UI
     keeping the graph as one togglable layer among several rather than a mandatory default view —
     https://neo4j.com/developer/genai-ecosystem/llm-graph-builder-features/ .
   - Principle: a full graph visualization is, everywhere researched, a secondary/exploratory surface;
     the workhorse surface for "what does this relate to" is a ranked list attached to the thing being
     read.
   - Fit for Taskio: **adopted as confirmation of the module's existing UI priority — no change.** The
     reader's own relation rail/panel (`KnowledgeEntryRail.vue`, `KnowledgeSimilarRow.vue`,
     `KnowledgeMentionRow.vue`) is already the primary surface, with the full graph
     (`KnowledgeGraphView.vue`) as a separate, secondary screen — this research is recorded as
     independent field validation that this ordering of investment (panel-first) is correct, and as a
     standing argument for prioritizing panel refinements over further graph-visualization investment
     if the two ever compete for the same batch.

10. **Knowledge freshness, at products that do it well, is a social workflow — a verifier, an interval,
    and a visible badge that degrades without ever hiding the content — not an automatic timestamp
    check.**
    - Source: Guru's per-card verifier (AI-suggested from the author/last editor) plus a review
      interval, after which a card becomes "Unverified" but stays fully visible and searchable, with an
      auto-archive queue reserved for cards that are BOTH unverified AND unused —
      https://www.getguru.com/features/verification — and its duplicate-detection batch, gated on
      workspace size (>100 published cards) rather than run on every save —
      https://www.getguru.com/features/duplicate-detection · Glean's three explicit states
      (verified/unverified/deprecated), a visible badge on verified results, and a "request
      re-verification from the owner" action any reader can trigger —
      https://docs.glean.com/user-guide/knowledge/verification/how-verification-works .
    - Principle: freshness is a governance workflow with an owner and a visible signal, and degrading
      status must never mean hiding the content — a stale answer shown with a clear "this needs review"
      badge is strictly better than either silently trusting it or silently removing it.
    - Fit for Taskio: **recorded as an accepted FUTURE direction only — nothing implemented now.**
      `KnowledgeEntryStatus`/`stale_at` today is exactly what ADR-0043 D4 designed it to be: a bare,
      orthogonal review-due flag with no workflow attached (no verifier, no interval, no queue). This
      entry is the accepted north star for evolving it later — a per-entry verifier (defaulting to the
      creator/last editor), a configurable interval, and a visible degrade-never-hide badge — explicitly
      NOT scheduled against any current batch. Any future work on this should read this entry before
      inventing its own shape.
