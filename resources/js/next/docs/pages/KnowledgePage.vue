<script setup lang="ts">
// Gallery: Knowledge module — module overview, the base/entry data model, wikilinks + ghosts,
// the metadata schema builder, markdown rendering rules (wikilinks, highlight offsets), the
// deterministic graph layout contract, the PATCH-diff discipline every write in this module
// follows, and the AI composer (source → drafts → relations/diff → accept/amend). Documents the
// IMPLEMENTED behavior of app/modules/Knowledge/ + resources/js/next/pages/knowledge/ — not
// planned behavior. Full backend contract: docs/backend/knowledge-api.md. Design record:
// docs/decisions/ADR-0043/0044/0045/0046.
//
// Sections:
//   1. Module overview & concepts
//   2. Key components (cards, forms, schema builder, picker)
//   3. MarkdownViewer wikilinks + MarkdownEditor wikilinks feature
//   4. KnowledgeEntryPreview — one card, two hosts
//   5. The graph layout contract (knowledgeGraphLayout)
//   6. Write discipline: PATCH diffing, optimistic locking, retry-without-refetch
//   7. Highlight offsets — never HTML
//   8. The AI composer — source → drafts → relations/diff → accept/amend
//   9. Typed relations — the fifth graph layer (server owns the ontology, client owns the prose)
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Badge from '../../ui/primitives/Badge.vue';

// ── Concepts ────────────────────────────────────────────────────────────────
const conceptRows: ApiRow[] = [
  { name: 'Knowledge base', type: 'KnowledgeBase', description: 'A hard container: charter (free prose — what belongs here) + a typed metadata schema every entry validates against. An entry cannot exist outside a base.' },
  { name: 'Entry', type: 'KnowledgeEntry', description: 'One durable topic. title + content (≤40 000 chars) + aliases (≤10 × ≤120 chars — other surface forms/inflections/synonyms) + typed metadata + status + an orthogonal stale_at review flag + a manual position. aliases feed ONLY the mention layer (KnowledgeMentionLinker scans title + aliases for a match) — they NEVER resolve [[wikilinks]]; the slug is the one and only address a link can point at.' },
  { name: 'Slug', type: 'string', description: 'Minted once from the first title, NEVER follows a rename. Wikilinks address by slug — a slug that tracked the title would break every inbound link on a heading typo fix.' },
  { name: 'Revision', type: 'KnowledgeEntryRevision', description: 'Append-only snapshot of authored state, written on every save that changes title/content/metadata. Restoring an old one APPENDS a new one (history moves forward, never rewinds).' },
  { name: 'Wikilink', type: '[[slug]] | [[slug|label]]', description: "Parsed from an entry's content into a KnowledgeLink row. An unresolved target is stored as a GHOST (to_entry_id: null) and adopted automatically the moment a matching slug appears." },
  { name: 'Chunk', type: 'KnowledgeEntryChunk', description: 'One embedded passage — the unit of retrieval. Carries the raw pgvector column; nothing outside Support/ChunkVector.php reads or writes it directly.' },
  { name: 'Binding', type: 'KnowledgeBinding', description: "Which base a consumer (a bot today) reads, and in which mode (inline | rag | auto). Addressed by primitives (morph alias + uuid) — Knowledge never imports the consumer's model." },
];

const editorialStatusRows: ApiRow[] = [
  { name: 'draft', type: '', description: 'Being written; not authoritative yet.' },
  { name: 'proposed', type: '', description: 'Finished, awaiting a human blessing.' },
  { name: 'approved', type: '', description: 'The workspace stands behind it. The ONLY status an AI consumer reads (see §6 below).' },
  { name: 'archived', type: '', description: 'Deliberately no longer current. Excluded from the human search by default too (ask for it explicitly).' },
];

const indexStatusRows: ApiRow[] = [
  { name: 'pending', type: 'can_retry: false', description: 'New, or the text changed; nothing embedded yet — already queued.' },
  { name: 'indexing', type: 'can_retry: false', description: 'A worker holds it (released by the stale-claim reaper if the worker died).' },
  { name: 'indexed', type: 'can_retry: false', description: 'Every chunk carries a current embedding.' },
  { name: 'partial', type: 'can_retry: TRUE', description: 'Some chunks embedded, some did not. Genuinely, incompletely retrievable — distinct from failed because paid-for work is kept.' },
  { name: 'pending_budget', type: 'can_retry: TRUE', description: "Refused BEFORE spending: workspace at its AI cap. Nothing broken — raising the cap is the real fix, but a retry re-queues once it has." },
  { name: 'failed', type: 'can_retry: TRUE', description: 'The run could not produce anything usable.' },
];

// ── Key components ─────────────────────────────────────────────────────────
const componentRows: ApiRow[] = [
  { name: 'KnowledgeBaseCard.vue', type: 'pages/knowledge/', description: "One base in the Bases list. Renders index_summary + ghost_links_count directly from the list response (both ALWAYS present on KnowledgeBaseResource, per-entry served since B2b) — no follow-up request per card. Opens the reader; Settings stays a kebab item." },
  { name: 'KnowledgeBaseForm.vue', type: 'pages/knowledge/', description: 'The ONE form body for create (drawer) and edit (settings page). Gates the charter + metadata schema behind can_be_managed, separately from name/description/language (can_be_edited) — never merged into one "edit" affordance. See §6 for the PATCH-diff discipline this form depends on.' },
  { name: 'DescriptorSchemaBuilder.vue', type: 'pages/knowledge/', description: "The metadata schema editor. Row model is the shared descriptor vocabulary (base + nullable + array), lifted from ConstantEditorDrawer's Type section. OFFERS text|number|boolean|date|enum only — object is deliberately absent even though the backend accepts it (entry metadata must stay flat to filter/tabulate). A field the builder cannot express renders read-only and is re-emitted untouched." },
  { name: 'KnowledgeBaseSelect.vue', type: 'ui/forms/', description: 'A GLOBAL, reusable base picker (mirrors BotSelect.vue: async cursor pages, off-page seed cache, search). Talks to the API directly, never through the knowledge store — a picker mounted over the Bases screen must not clobber the list state that screen renders.' },
  { name: 'KnowledgeMetadataForm.vue', type: 'pages/knowledge/', description: "An entry's typed metadata editor, driven by the base's schema — same TypedLiteralInput composition pattern as the Generator's SlotValuesForm object branch." },
  { name: 'knowledgeGraphLayout.ts', type: 'pages/knowledge/graph/', description: 'A pure, deterministic layout function — see §5.' },
];

// ── MarkdownViewer / MarkdownEditor wikilinks ──────────────────────────────
const wikilinkOptionRows: ApiRow[] = [
  { name: 'wikilinks', type: 'WikilinkOptions | undefined', description: 'Opt-in, additive prop on MarkdownViewer.vue. Undefined → the wikilink transform step never runs at all, and output is byte-identical to before this prop existed (pinned by a regression test).' },
  { name: 'wikilinks.resolve(slug)', type: '(slug: string) => { href, title } | null', description: 'Returns the target for a slug, or null for a ghost (unresolved).' },
];

// ── The graph layout contract ──────────────────────────────────────────────
const graphLayoutRows: ApiRow[] = [
  { name: 'layoutKnowledgeGraph(data, options?)', type: 'GraphLayout', description: 'The one entry point a screen calls — branches on data.center (ego vs. overview) so the caller never has to keep its own mode in sync with the request.' },
  { name: 'mode', type: "'ego' | 'overview'", description: '' },
  { name: 'nodes[]', type: 'GraphNodePlacement[]', description: 'x/y/r/angle already computed, rounded to 2 decimals (snapshot-stable + immune to last-bit float differences across engines).' },
  { name: 'edges[]', type: 'GraphEdgePlacement[]', description: 'Endpoints already trimmed to the circles they connect; every edge references two ids present in nodes[].' },
  { name: 'overflow', type: 'number', description: "Nodes the CLIENT-SIDE cap dropped — reported separately from the SERVER's own truncated.hidden_nodes." },
  { name: 'fit', type: '{ x, y, width, height }', description: 'The tight bounding box + 8% margin, aspect-normalized — exactly what "fit to screen" sets the SVG viewBox to.' },
];

const layoutDegeneracyRows: ApiRow[] = [
  { name: 'Radius: FROZEN per ring, never touched by a barycentric pass', type: 'rotateRingToBarycentres()', description: 'Barycentric iteration with no fixed frame converges to a point or a line (Tutte 1963) — the classic cause of the old vertical-stripe collapse on a small overview. Pinning the ring radius IS the fixed frame; only the angle is optimized by the two barycentric passes.' },
  { name: 'Golden-angle seed, ONE running counter over the whole ordering', type: 'seed += 1 across all rings, not per-ring', description: 'A per-ring index would restart at zero on every ring and hand the first node of each ring the same angle — collinear by construction. A single counter incremented across the total (degree → slug → id) order makes every seed angle distinct by an irrational fraction of a turn, so no two nodes ever land opposite each other.' },
  { name: 'MIN_RING_SLOTS = 3', type: 'even spacing floor', description: "A ring is divided into at least 3 angular slots however few nodes sit on it — the smallest count that makes an antipodal (180°) pair impossible. For n ≥ 3 this changes nothing." },
  { name: 'Labels: an occupancy GRID, not a degree threshold', type: 'KnowledgeGraphCanvas.vue', description: 'The old "degree ≥ 3" rule had no equivalent in any researched graph tool and hid roughly half a small graph\'s labels exactly when there was room to show every one. Replaced by a screen-space budget: 40×18 WORLD-unit cells (world, not pixel — the whole picture is one scaled SVG viewBox, so occupancy has to be zoom-invariant), candidates sorted forced → drawn degree → slug (a total, deterministic order — the slug tie-break is what makes this snapshot-testable), granted a label until their cells collide with an already-labelled node\'s. A graph small enough to fit gets every label, with no special case.' },
];

const graphNotUsedRows: ApiRow[] = [
  { name: 'updatedAt (server timestamp)', type: 'deliberately absent', description: 'KnowledgeGraphResource carries NO timestamp on the wire (minimal by design). The overview ring order is degree ⇣ → slug → id; recency already decided WHICH isolated entries the server included, it cannot also decide where they land on the canvas.' },
  { name: "node.degree (server's whole-response count)", type: 'not used for radius', description: 'The layout computes its OWN drawn degree (edges actually rendered after the client kind-filter chips + the client node cap). A dot labelled with a degree it does not visibly have is a dot the reader stops believing.' },
];

// ── Write discipline ───────────────────────────────────────────────────────
const writeDisciplineRows: ApiRow[] = [
  { name: 'PATCH is a DIFF, always', type: 'KnowledgeBaseForm.vue, updateBase()/updateEntry() (stores/knowledge.ts)', description: "The frontend NEVER echoes back an untouched field. Absent means unchanged server-side — merely INCLUDING charter/metadata_schema with an unchanged value would escalate a base PATCH from the update ability to the stricter manage ability, 403ing an ordinary member's plain rename. Same reasoning for entries: including the full 40 000-char content on a status-only change would re-index a paragraph nobody touched." },
  { name: 'expected_revision_id → 409 knowledge_stale_write', type: 'the optimistic-lock token', description: 'Send the current_revision_id read from the last fetch; a stale save 409s instead of silently overwriting a concurrent edit. current_revision_id is echoed on every entry response specifically to be sent back.' },
  { name: 'retryIndex() renders from the RESPONSE, never refetches', type: 'stores/knowledge.ts', description: 'The retry endpoint returns the full entry with index.status already moved to pending and can_retry already false — the caller renders that payload directly. A refetch would race the background worker and could show the index state moving BACKWARDS on screen.' },
];

// ── Highlight offsets ───────────────────────────────────────────────────────
const highlightRows: ApiRow[] = [
  { name: 'matched_chunk.char_start / char_length', type: 'integers', description: "CHARACTER offsets into the entry's OWN content. mb_substr(entry.content, char_start, char_length) === matched_chunk.snippet holds exactly." },
  { name: 'matched_chunk.highlights', type: '[number, number][]', description: 'Absolute [start, length] pairs, each a sub-range of the snippet window. NEVER HTML — the server does not return <mark> or any markup; injecting server-side markup into user-authored text would be a sanitization seam created purely for rendering convenience.' },
];

// ── The AI composer ─────────────────────────────────────────────────────────
const composerFlowRows: ApiRow[] = [
  { name: '1. Source', type: 'KnowledgeComposeSourceForm.vue', description: 'One textarea (≤ limits.source_max_chars from compose-availability). Optionally seeded from a red wikilink (?seed=slug) or an "amend this" entry point (?amend=entryId). No title/slug/metadata fields — those are the agent\'s OUTPUT, never its input.' },
  { name: '2. Generating', type: 'GenerateKnowledgeDraftsJob (async)', description: 'POST opens the session already `generating`. Skeleton cards, never a spinner. Settles on the realtime broadcast (see useComposeSettle below), never a poll.' },
  { name: '3. Draft board', type: 'KnowledgeDraftBoard.vue + KnowledgeDraftCard.vue', description: 'One card per draft — an ORDINARY KnowledgeEntryResource under the hood, so it carries content/metadata/current_revision_id for free. A shadow (amendment) card shows the TARGET\'s own title/slug in its header, never an invented one.' },
  { name: '4. Relations + diff (optional, per card)', type: 'KnowledgeDraftRelationsPanel.vue + KnowledgeDraftDiffPanel.vue', description: 'Relations reuse the base graph canvas 1:1 (same layoutKnowledgeGraph, same KnowledgeGraphCanvas/NeighbourList/Legend) — the composer never grows a second visualization. Diff renders TextDiffView against a server-picked baseline (see below).' },
  { name: '5. Accept / amend', type: 'POST …/accept (partial)', description: 'Per-draft or bulk-selected. 200 either way: a shadow whose target moved is reported as a CONFLICT alongside every draft that DID publish — never a whole-batch failure for one stale target.' },
];

const amendmentFlowRows: ApiRow[] = [
  { name: 'Propose', type: 'action: "update" in the model\'s reply', description: 'The composer is shown a FROZEN retrieval set (existing entries the pasted material may belong to) as its only valid amendment targets — a shadow naming anything else degrades to a plain "create" rather than being dropped.' },
  { name: 'Inspect', type: 'KnowledgeDraftDiffPanel.vue, baseline="target" (default for a shadow)', description: 'The default comparison for an amendment is the STORED (live) version of the target, not the draft\'s own history — "what would this do to the entry that already exists" is the question a reviewer of an amendment actually asks.' },
  { name: 'Stale?', type: 'target_revision_stale (server-computed, on every fetch)', description: 'True when the target was edited after the composer read it. Surfaced as a badge BEFORE an accept attempt — the client never diffs revision ids itself.' },
  { name: 'Two routes on stale', type: 'Rebase (free) vs. Refine (spends)', description: 'See "The two-price rule" below.' },
  { name: 'Re-accept', type: 'POST …/accept', description: 'Publishes through the ordinary KnowledgeEntryService::update() — appends a revision, re-syncs links, re-queues indexing — exactly like a human edit. The shadow row is then destroyed; it was a proposal, now applied.' },
];

const composeSettleRows: ApiRow[] = [
  { name: 'useComposeSettle(id)', type: 'pages/knowledge/compose/useComposeSettle.ts', description: 'A 1:1 clone of the Generator\'s useSessionSettle.ts against this module\'s own channel/event/store — cloned deliberately rather than shared, because the three differ in exactly those three facts and a parameterized abstraction was judged harder to read than the ~60 duplicated lines.' },
  { name: 'ZERO polling, anywhere', type: 'standing project requirement', description: 'Every wait ends in exactly ONE fetch: on the matching broadcast event, on the safety-timeout expiry, or — Reverb not configured — after one delayed retry. There is no loop in any of the three paths.' },
  { name: 'Channel / event', type: 'knowledge.workspace.{workspaceId} / knowledge-draft-session.updated', description: 'Private, per-WORKSPACE (not per-session) — every open composer subscribes once and filters by id. Payload is {id, status} only, never the drafts (those come from the authenticated REST fetch this composable triggers).' },
];

const textDiffRows: ApiRow[] = [
  { name: 'old / current', type: 'string / string', description: 'The baseline and what it became. old === current renders a centered empty state instead of an empty box.' },
  { name: 'maxHeight', type: 'string, default "60vh"', description: 'A CSS length — the host\'s layout decision (the Disk editor matches its textarea; the composer\'s in-card panel uses 24rem). Compared LITERALLY against the string \'60vh\' to decide the empty state\'s min-height — not computed, so an unusual value gets a plain empty box with no special sizing.' },
  { name: 'emptyLabel', type: 'string, optional', description: 'Host-specific copy for the "nothing changed" state (the composer\'s own diffUnchanged string) — falls back to the generic textDiff.noChanges when absent.' },
  { name: 'MAX_PAIR_CELLS = 40 000', type: 'ui/data/textDiff.ts (internal)', description: 'A SEPARATE guard from the whole-document coarse fallback (below): above this many del×add cell pairs in ONE replace hunk, lines are paired by INDEX instead of by content-similarity — word-level highlighting still happens, just aligned by position. At the knowledge module\'s 40 000-char entry cap this is reachable on a heavily rewritten entry.' },
  { name: 'TEXT_DIFF_MAX_LINES = 3000 (coarse fallback)', type: 'ui/data/textDiff.ts (exported)', description: 'Above this many lines on EITHER side, the whole document collapses to one del block + one add block (a sticky banner says so, textDiff.coarse) — the O(n·m) line DP would otherwise hang the tab. Exported specifically so the component reads the SAME condition the engine applies, rather than guessing from the output shape (a one-line total replacement produces the identical two-row result either way).' },
];

// ── Typed relations — the fifth graph layer ────────────────────────────────
const relationConceptRows: ApiRow[] = [
  { name: 'KnowledgeRelation', type: 'own table, never knowledge_links', description: 'A TYPED, APPROVED statement about two entries — asserted by a person or proposed by the AI composer and accepted. Never rebuilt by the link sweep. Full contract: docs/backend/knowledge-api.md → "Typed relations". Design record: docs/decisions/ADR-0047-knowledge-typed-relations.md.' },
  { name: '15 relation types, 8 entry types + null', type: 'KnowledgeRelationType / KnowledgeEntryType', description: 'A closed vocabulary fixed in backend code, published on the base as relation_vocabulary — {id, label, inverse_label, symmetric, property_keys, from_types, to_types} per type. The client never keeps its own copy.' },
  { name: 'state: active | ended | retracted', type: 'lifecycle', description: 'Ending and retracting both KEEP the row (only the irreversible hard DELETE removes it, restricted to the creator/workspace owner). retract ≠ delete — see relationLabels.ts and the ADR for why the UXUI spec\'s own confirmation-dialog copy had this backwards before it was verified against shipped code.' },
];

const relationOntologyRows: ApiRow[] = [
  { name: 'predicateLabel() / relationPredicate()', type: 'relationLabels.ts', description: 'Translates a verb id CLIENT-side (server labels render in the server\'s own locale, not the browser\'s chosen one), falling back to the server\'s own label/inverse_label string for a verb this catalog has not learned yet — degraded, never broken, for a future 16th verb.' },
  { name: 'groupedVocabulary()', type: 'relationLabels.ts', description: 'The type picker\'s groups are PRESENTATION only (the server has no opinion on them) and are filtered to the verbs the BASE actually allows (relation_vocabulary ∩ the base\'s own subset) — never a hardcoded 15-item list. A verb the backend adds lands in a trailing "other" group rather than vanishing.' },
  { name: 'pairingVerdict()', type: 'relationLabels.ts', description: 'Mirrors the server\'s advisory pair check (RelationVocabulary::checkTypes()) using the from_types/to_types the base already sent — returns null ("no opinion") for an untyped or other end, exactly like the server, never a duplicated ontology guessing at the same rule.' },
];

const relationGraphLayerRows: ApiRow[] = [
  { name: 'edges[].kind: \'relation\' — FIRST in GRAPH_EDGE_KINDS', type: 'graph/graphNeighbours.ts, knowledgeGraphLayout.ts', description: 'The only layer whose meaning a human authored (the other four — wikilink/similarity/mention/manual — are the machine\'s reading of text). Ordered first because the most deliberate edge reads first.' },
  { name: 'Shape carries KIND, colour carries STATE, label carries MEANING', type: 'the module\'s own rule (§27.2 G8)', description: 'The four derived layers each have exactly one meaning, so a line style is enough. A relation has 15 possible meanings — without a text label on the edge it would say only "there is some relation here," which is useless. Colour (active vs. historical, opacity .5 + a "(until {year})" label suffix) never carries kind, and kind never carries state.' },
  { name: 'NO neighbour-row collapsing for relations — the one deliberate exception', type: 'graphNeighbours.ts, betterRelation()', description: 'Every other edge kind collapses to ONE row per neighbour (the strongest edge wins — "similar AND mentioned" is still one neighbourhood). A relation is keyed PER EDGE instead: Anna may works_on AND created the same project at once, and collapsing to one row would silently hide the second fact. Pinned by a snapshot test specifically because a future betterRelation refactor would otherwise "tidy" this away without anyone noticing.' },
  { name: 'KnowledgeRelationSentence.vue — the ONE place a relation is worded', type: 'pages/knowledge/relations/', description: 'Used in the reader panel, the graph neighbour list, the graph side panel, and every confirmation dialog. The accessible name is the FULL sentence ("Anna is a member of Acme, from January 2024"); the type badge and direction arrow are aria-hidden — the canvas itself is aria-hidden, so the relation layer is the only one whose meaning has to reach a screen reader through this component or not at all.' },
];

const sharedBudgetRows: ApiRow[] = [
  { name: 'ui/patterns/AiBudgetBanner.vue', type: 'promoted from pages/generator/session/SessionBudgetBanner.vue', description: 'Route-agnostic ({ summary, canManage, dismissible? } props, dismiss/manage emits — never navigates itself). Renders the danger Alert with the role-appropriate CTA (an owner gets "raise the limit"; a member gets "ask the owner", never a control that would 403). Knowledge is the THIRD module to need this (after Generator, Bots) — the promotion point.' },
  { name: 'app/lib/aiBudget.ts', type: 'promoted from app/stores/sessions.ts', description: 'isBudgetError(err) / AI_BUDGET_ERROR_CODE — recognizes a 429 OR a body carrying the typed code, so a budget stop reads as a STATE with a reset date rather than a failure to retry. Knowledge may not import the Generator\'s store (module boundary), so this lives in shared app/lib rather than being copied — a copy would be two definitions of "out of money" drifting apart.' },
];
</script>

<template>
  <StoryPage
    title="Knowledge module"
    description="Bases, entries, wikilinks/ghosts, chunking + retrieval, and the reader/editor/graph UI. Documents implemented behavior only. Backend: app/modules/Knowledge/. Full API contract: docs/backend/knowledge-api.md."
  >

    <!-- 1. Module overview -->
    <StorySection title="Module overview">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <strong>knowledge base</strong> is a workspace-scoped, hard container for durable facts —
          not a folder, not a tag. It carries a <strong>charter</strong> (what it is for) and a
          <strong>typed metadata schema</strong> every entry's metadata is validated against, reusing
          the exact descriptor vocabulary the Variables module uses for a constant's type. A low-layer
          module (same tier as Variables): it may depend on Variables + App\Support and imports
          <strong>nothing</strong> from the modules that consume it (Bot today) — pinned by
          <code class="font-next-mono">KnowledgeModuleBoundaryTest</code>.
        </p>

        <ApiTable title="Core concepts" :rows="conceptRows" />

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-2 font-next-semibold text-next-fg">Editorial status (4 values)</p>
            <p class="mb-next-2 text-next-xs text-next-muted-foreground">
              A single line from "being written" to "no longer true," no parallel branches.
              <code class="font-next-mono">stale_at</code> is a SEPARATE, orthogonal review-due flag —
              an <code class="font-next-mono">approved</code> entry can be stale, a
              <code class="font-next-mono">draft</code> can be current.
            </p>
            <ApiTable title="KnowledgeEntryStatus" :rows="editorialStatusRows" />
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-2 font-next-semibold text-next-fg">Index status (6 values)</p>
            <p class="mb-next-2 text-next-xs text-next-muted-foreground">
              Orthogonal to editorial status. Whether an entry NEEDS work is never read off this
              column alone — the authority is a digest comparison, so a worker that died holding
              <code class="font-next-mono">indexing</code> can never make a stale entry look current.
            </p>
            <ApiTable title="KnowledgeIndexStatus" :rows="indexStatusRows" />
          </div>
        </div>

        <Alert variant="info" size="sm">
          Retrieval for an AI consumer (a bot) is <strong>approved-only</strong>, stricter than the
          human search (everything but <code class="font-next-mono">archived</code> by default) —
          text handed to a model is quoted as FACT, so a half-written draft is worse than silence.
          See <code class="font-next-mono">docs/backend/knowledge-api.md</code> → "Consumption:
          inline / rag / auto."
        </Alert>
      </div>
    </StorySection>

    <!-- 2. Key components -->
    <StorySection title="Key components">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="Components this module introduces / extends" :rows="componentRows" type-header="Location" />
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">DescriptorSchemaBuilder — a deliberately NARROWER surface than the backend</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">KnowledgeMetadataValidator</code> (backend) accepts
            <code class="font-next-mono">object</code> as a schema field base. The frontend builder
            does not offer it — entry metadata has to stay FLAT to be filterable and to fit a table
            column. This is a frontend product decision layered on top of a wider backend contract,
            not a backend limitation; a field written some other way still round-trips read-only.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 3. Markdown wikilinks -->
    <StorySection title="MarkdownViewer wikilinks + MarkdownEditor wikilinks feature">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <code class="font-next-mono">[[slug]]</code> / <code class="font-next-mono">[[slug|label]]</code>
          stays PLAIN TEXT inside the Tiptap document — there is no atomic wikilink node, no schema
          registration. The backend already parses <code class="font-next-mono">[[…]]</code> to build
          the graph edges; a second, client-side notion of "what is a link" would eventually disagree
          with it. <code class="font-next-mono">MarkdownEditor.vue</code>'s optional
          <code class="font-next-mono">wikilinks</code> feature is autocomplete only (a
          <code class="font-next-mono">[[</code>-triggered picker that inserts plain text) — it adds
          no node and no schema, so a document serializes identically whether the feature is on or off.
        </p>

        <ApiTable title="MarkdownViewer.vue — wikilinks prop" :rows="wikilinkOptionRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Transform runs AFTER DOMPurify.sanitize — never before</p>
          <p class="text-next-xs text-next-muted-foreground">
            The wikilink transform walks the SANITIZED DOM's text nodes (skipping
            <code class="font-next-mono">code</code>/<code class="font-next-mono">pre</code>/<code class="font-next-mono">a</code>)
            and replaces a matched <code class="font-next-mono">[[…]]</code> span with a
            <strong>programmatically built</strong> anchor — <code class="font-next-mono">document.createElement('a')</code>
            + <code class="font-next-mono">textContent</code>, never markup injection. The sanitizer's
            own config (<code class="font-next-mono">ALLOWED_ATTR</code> excludes
            <code class="font-next-mono">class</code>/<code class="font-next-mono">data-*</code>) is
            untouched — a <code class="font-next-mono">&lt;a data-wikilink&gt;</code> injected BEFORE
            sanitization could never survive it anyway, which is exactly why building the anchor after
            is safe rather than merely convenient.
          </p>
        </div>

        <Alert variant="warning" size="sm">
          <strong>A plain markdown link to an internal path does NOT work as a link.</strong>
          <code class="font-next-mono">SAFE_LINK = /^(https?:\/\/|mailto:)/i</code> is the only
          protocol allow-list for markdown-authored <code class="font-next-mono">&lt;a href&gt;</code>
          tags; anything else — including a hand-written relative link to another entry — has its
          <code class="font-next-mono">href</code> attribute stripped during sanitization and renders
          as plain, non-interactive text. The <strong>only</strong> supported syntax for an internal
          link inside entry content is a wikilink. Wikilink anchors themselves use a separate, trusted
          builder (<code class="font-next-mono">safeWikilinkHref</code>) that additionally accepts a
          single-leading-slash relative path — that allowance does not extend to ordinary markdown
          links.
        </Alert>
      </div>
    </StorySection>

    <!-- 4. KnowledgeEntryPreview -->
    <StorySection title="KnowledgeEntryPreview — one component, two hosts">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Renders CONTENT ONLY — no positioning, no teleport, no fetching — so it can back both the
          reader's hover/focus popover over a wikilink AND the graph's docked side panel without two
          copies drifting. The host decides interactivity via the <code class="font-next-mono">actions</code>
          prop.
        </p>
        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">actions = false (the hover popover)</p>
            <p class="text-next-xs text-next-muted-foreground">
              No <code class="font-next-mono">#actions</code> slot content is revealed — a tooltip a
              user could focus INTO is a trap. Pure hint.
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">actions = true (the graph side panel)</p>
            <p class="text-next-xs text-next-muted-foreground">
              Reveals <code class="font-next-mono">#actions</code> ("Open entry", "Centre here",
              "Create this entry"). <strong>Available in BOTH the ready AND the ghost state</strong> —
              a ghost's whole point is the "create this entry" invitation, so burying actions behind a
              state that has none would make a red link in the graph a dead end. Loading and error
              states stay actionless (a skeleton with buttons is a lie; an error hint should not offer
              a retry it cannot back).
            </p>
          </div>
        </div>
      </div>
    </StorySection>

    <!-- 5. Graph layout contract -->
    <StorySection title="The graph layout contract (knowledgeGraphLayout.ts)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A PURE, deterministic function over the graph API payload — no
          <code class="font-next-mono">Math.random</code>, no <code class="font-next-mono">Date</code>,
          no physics simulation, no DOM access. Determinism is a UX requirement: the same base must
          look the same after a refresh, or a user loses the mental map that is the whole reason to
          draw a graph. Every ordering decision is a TOTAL order (final tie-break: the slug, unique
          within a base) — the server's own <code class="font-next-mono">nodes[]</code> array order is
          NOT trusted (the overview's hub ranking is a <code class="font-next-mono">group by</code>
          with no <code class="font-next-mono">order by</code>).
        </p>
        <ApiTable title="GraphLayout (return shape)" :rows="graphLayoutRows" />
        <ApiTable title="Deliberately NOT used from the server payload" :rows="graphNotUsedRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Small-graph degeneracy fix + label budget (post external-research pass)</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            The overview layout used to collapse a small base into a straight line, and labels were
            gated on a <code class="font-next-mono">degree ≥ 3</code> threshold that hid roughly half a
            small graph's names. Both were replaced after an external research pass (see
            <code class="font-next-mono">docs/ai/reference-links.md</code> → Knowledge module, entries
            1–2) confirmed neither had any precedent in a researched graph tool.
          </p>
          <ApiTable title="What changed, and why it is still a pure function" :rows="layoutDegeneracyRows" />
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            The decision NOT to port a physics simulation (a seeded, offline d3-force pass stays the
            recorded Plan B if this is ever still ugly at some future scale) is written out in full in
            <code class="font-next-mono">knowledgeGraphLayout.ts</code>'s own
            <code class="font-next-mono">"DECISION — no physics, and why"</code> comment, immediately
            above <code class="font-next-mono">DEFAULTS</code> — read that comment before reopening this
            question rather than re-deriving it from scratch.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 6. Write discipline -->
    <StorySection title="Write discipline: PATCH diffing, optimistic locking, retry-without-refetch">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Three rules apply everywhere a save touches a base or an entry — breaking any one of them
          produces either a wrong 403 or a lost concurrent edit, neither of which is visible in normal
          testing (both need two actors).
        </p>
        <ApiTable title="Rules" :rows="writeDisciplineRows" />
      </div>
    </StorySection>

    <!-- 7. Highlight offsets -->
    <StorySection title="Search highlights are offsets, never HTML">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A search result's <code class="font-next-mono">matched_chunk</code> is the ONE place
          highlighting is described, and it is described as positions into plain text, not markup —
          see <code class="font-next-mono">docs/backend/knowledge-api.md</code> → "KnowledgeSearchResultResource."
        </p>
        <ApiTable title="matched_chunk fields the client draws highlights from" :rows="highlightRows" />
        <Alert variant="info" size="sm">
          A client renders a highlight by slicing the snippet string at the given offsets and wrapping
          that slice itself — never by trusting a server-supplied
          <code class="font-next-mono">&lt;mark&gt;</code> tag, because the entry body is
          user-authored and any server-side markup injected into it would need to be
          re-sanitized downstream by code that can no longer tell the server's markup from the
          author's own text.
        </Alert>
      </div>
    </StorySection>

    <!-- 8. The AI composer -->
    <StorySection title="The AI composer — pages/knowledge/compose/**">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Since the owner's AI-only pivot, this is how every entry comes into existence — every
          "new entry" affordance in the app (<code class="font-next-mono">KnowledgeReaderView.vue</code>'s
          empty state, the entries table header, a red wikilink, the graph's ghost panel) routes
          here. The manual editor (<code class="font-next-mono">KnowledgeEntryEditorView.vue</code>)
          keeps editing an <strong>existing</strong> entry only — see §6 above. Full backend contract:
          <code class="font-next-mono">docs/backend/knowledge-api.md</code> → "The AI composer".
          Design record: <code class="font-next-mono">docs/decisions/ADR-0046-knowledge-ai-composer.md</code>.
        </p>

        <ApiTable title="The flow: source → drafts → relations/diff → accept" :rows="composerFlowRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">The amendment (shadow) lifecycle: propose → inspect → stale? → rebase/refine → re-accept</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            A SHADOW draft proposes a change to an entry that already exists, rather than a new one —
            it renders as the SAME <code class="font-next-mono">KnowledgeDraftCard.vue</code>, with a
            <code class="font-next-mono">Badge variant="modified"</code> and the TARGET's own title in
            the header. It draws no node of its own in the relations preview and no edge to its
            target — the pending change is an annotation (<code class="font-next-mono">amended_by</code>)
            on the target's own node instead, because an edge would need an endpoint the shadow
            itself, which the preview never draws as a circle.
          </p>
          <ApiTable title="Steps" :rows="amendmentFlowRows" />
        </div>

        <Alert variant="warning" size="sm">
          <strong>The two-price rule at 409 — the ONE place in this module where a UI choice has a
          direct monetary consequence.</strong> When a shadow's target moved after the composer read
          it, the reviewer sees two routes: <strong>"Show diff against the new version" (rebase)</strong>
          costs <em>nothing</em> — it only re-points the frozen <code class="font-next-mono">target_revision_id</code>,
          no model call — and <strong>"Revise again" (refine)</strong> spends one more metered
          generation. Every other choice in the composer either always costs (start, refine,
          expand-context) or never does (rebase, reject, accept) — this is the one fork where the
          same visible action ("fix the conflict") has two different prices depending which button is
          pressed, so both buttons state their cost explicitly rather than looking identical.
        </Alert>

        <ApiTable title="useComposeSettle — zero-poll realtime settle" :rows="composeSettleRows" />

        <ApiTable title="TextDiffView (ui/data/TextDiffView.vue) — the design-system diff, reused not forked" :rows="textDiffRows" />

        <ApiTable title="Shared AI-budget primitives (promoted out of the Generator in this batch)" :rows="sharedBudgetRows" />

        <Alert variant="info" size="sm">
          <strong>No per-operation cost estimate is ever rendered.</strong> The composer's source form
          documents this in its own module comment: the verified contract carries the workspace's
          BUDGET STATE (used/cap/warn/reset-date) but never a "this run will cost ~$X" figure, because
          a model's reply length is not knowable in advance — "a made-up number is worse than no
          number." Only the budget-state chip renders, and only when there is something to warn about.
        </Alert>
      </div>
    </StorySection>

    <!-- 9. Typed relations -->
    <StorySection title="Typed relations — the fifth graph layer">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <code class="font-next-mono">KnowledgeLink</code> is DERIVED — parsed from prose or
          measured by a vector, rebuilt on every save. A <code class="font-next-mono">KnowledgeRelation</code>
          is the opposite kind of thing: a TYPED STATEMENT a person approved, asserted by hand or
          proposed by the AI composer and accepted, that no sweep may ever remove. Full backend
          contract: <code class="font-next-mono">docs/backend/knowledge-api.md</code> → "Typed
          relations". Design record:
          <code class="font-next-mono">docs/decisions/ADR-0047-knowledge-typed-relations.md</code>.
        </p>

        <ApiTable title="Core concepts" :rows="relationConceptRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">The server owns the ontology, the client owns only the phrasing</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">relation_vocabulary</code> — every one of the 15 types' both
            labels, symmetry, allowed property keys, and the entry-type pair matrix — is sent WITH the
            base. Nothing in the frontend keeps a second copy of that ontology; three call sites in
            <code class="font-next-mono">relationLabels.ts</code> read it instead of hardcoding it.
          </p>
          <ApiTable title="relationLabels.ts — the three enforcement points" :rows="relationOntologyRows" />
        </div>

        <ApiTable title="The graph layer" :rows="relationGraphLayerRows" />

        <Alert variant="info" size="sm">
          <strong>Machine-facing contract has no <code class="font-next-mono">retract</code>, no hard
          delete.</strong> The AI composer may propose <code class="font-next-mono">create</code>/
          <code class="font-next-mono">update</code>/<code class="font-next-mono">end</code> only —
          both <code class="font-next-mono">retract</code> ("this was never true," the row survives with
          <code class="font-next-mono">state: retracted</code>) and the irreversible
          <code class="font-next-mono">DELETE</code> are reachable ONLY from the relation panel's own
          human endpoints. An AI able to retract statements is an AI able to quietly empty a base.
        </Alert>
      </div>
    </StorySection>

    <!-- Related docs -->
    <StorySection title="Related documentation">
      <div class="flex flex-col gap-next-3 text-next-xs text-next-muted-foreground">
        <p><code class="font-next-mono">docs/backend/knowledge-api.md</code> — the full backend API contract (endpoints, resource shapes, authorization, data erasure, operational notes, and "The AI composer" section).</p>
        <p><code class="font-next-mono">docs/decisions/ADR-0043-knowledge-module-design.md</code> — bases, entries, wikilinks, the write-side contract.</p>
        <p><code class="font-next-mono">docs/decisions/ADR-0044-knowledge-index.md</code> — chunking, differential embedding, hybrid search.</p>
        <p><code class="font-next-mono">docs/decisions/ADR-0045-knowledge-consumption-data-erasure.md</code> — the Bot → Knowledge read edge and the subject-erasure command.</p>
        <p><code class="font-next-mono">docs/decisions/ADR-0046-knowledge-ai-composer.md</code> — draft sessions, shadow amendments, and the erasure extension covering drafting sessions.</p>
        <p><code class="font-next-mono">docs/decisions/ADR-0047-knowledge-typed-relations.md</code> — typed relations: the separate table + audit log, the closed vocabulary, the advisory pair matrix (a named departure from this module's usual fail-closed posture), the human-only retract/delete split, and the defects found and fixed while building the write path.</p>
        <p><code class="font-next-mono">docs/next/knowledge-uxui-spec.md</code> — the UX/UI specification, including a §23 "Erraty po implementacji", a §26 "Errata B15a/B15b", and a §28 "Errata G9" documenting where the built relations code deviated from §27's pre-implementation spec (field names, the retract≠delete split, the rejection/warning code vocabulary, and more).</p>
        <p><code class="font-next-mono">docs/backend/bots-api.md</code> → "Knowledge module (B6)" — the one consumer wired today, and the binding-precedence rule. Unchanged by the composer batch.</p>
      </div>
    </StorySection>
  </StoryPage>
</template>
