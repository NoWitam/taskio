<script setup lang="ts">
// KnowledgeGraphView — the base's link graph: orchestration, URL and state. The picture itself is
// `graph/KnowledgeGraphCanvas.vue`, the positions are `graph/knowledgeGraphLayout.ts` (a pure
// function), and the accessible surface is `graph/KnowledgeGraphNeighbourList.vue`.
//
// ── TWO MODES, ONE ROUTE ─────────────────────────────────────────────────────
// `…/graph`        → OVERVIEW: the base's most connected entries, topped up with recently updated
//                    isolated ones so a young base is not a blank screen.
// `…/graph/:slug`  → EGO: that entry's neighbourhood, 1 or 2 hops (`?depth=`).
//
// Addressed by SLUG, exactly like the reader, because a slug is the only entry handle derivable
// from an entry's own text (`[[wikilinks]]` carry slugs). The API centres by UUID, so the slug has
// to be resolved — and the CHEAP path is the common one: re-centring from a node already knows the
// id and records it, so only a COLD deep link (a refresh, a pasted URL) pays for a lookup, and it
// pays once.
//
// ── WHAT THE CHIPS DO ────────────────────────────────────────────────────────
// The edge-kind chips filter CLIENT-SIDE. The request always asks for all three server sources, so
// toggling a kind is instant, cannot re-shuffle the picture, and lets a chip state how many edges
// of that kind EXIST rather than how many survived the last request. (`?sources=` would also drop
// the nodes those edges discovered, which is a different — and much more confusing — answer.)
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Surface from '../../ui/layout/Surface.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import SegmentedControl from '../../ui/forms/SegmentedControl.vue';
import Select from '../../ui/forms/Select.vue';
import Switch from '../../ui/forms/Switch.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import KnowledgeGraphCanvas from './graph/KnowledgeGraphCanvas.vue';
import KnowledgeGraphLegend from './graph/KnowledgeGraphLegend.vue';
import KnowledgeGraphNeighbourList from './graph/KnowledgeGraphNeighbourList.vue';
import KnowledgeEntryPreview from './reader/KnowledgeEntryPreview.vue';
import {
  GRAPH_EDGE_KINDS,
  layoutKnowledgeGraph,
  type GraphEdgeKind,
  type GraphNodePlacement,
} from './graph/knowledgeGraphLayout';
import { neighboursOf } from './graph/graphNeighbours';
import { composeLocation } from './composeSeed';
import { entryStatusMap } from './statusMaps';
import { baseIndexState } from './baseMeta';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import {
  KNOWLEDGE_LINK_SOURCES,
  type KnowledgeEntryListItem,
  type KnowledgeGraphData,
  type KnowledgeGraphDepth,
} from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useKnowledgeStore();
const toast = useToast();

const baseId = computed(() => String(route.params.baseId ?? ''));
const slug = computed(() => (route.params.slug ? String(route.params.slug) : null));
const base = computed(() => (store.openBase?.id === baseId.value ? store.openBase : null));

const statusMap = computed(() => entryStatusMap(t));
/** Status → icon, taken from the SAME map the badges use, so the canvas cannot drift from them. */
const statusIcons = computed(() =>
  Object.fromEntries(
    Object.entries(statusMap.value).map(([status, descriptor]) => [status, descriptor.icon]),
  ),
);

// --- Depth (URL-owned) ------------------------------------------------------
const depth = computed<KnowledgeGraphDepth>(() => (String(route.query.depth) === '2' ? 2 : 1));

function setDepth(value: KnowledgeGraphDepth): void {
  if (value === depth.value) return;
  const query = { ...route.query };
  if (value === 1) delete query.depth;
  else query.depth = '2';
  void router.replace({ name: route.name as string, params: route.params, query });
}

const depthOptions = computed(() => [
  { value: 1 as const, label: t('knowledge.graph.depth1') },
  { value: 2 as const, label: t('knowledge.graph.depth2') },
]);

// --- The layer filter (client-side) -----------------------------------------
//
// ONE LAYER AT A TIME. This was a multi-select row of toggles, and the reason it changed is what
// multi-select could not answer: with four independent switches there are sixteen states and the
// picture never says which one it is in. A graph is read by comparing shapes, and comparing them
// means holding one variable at a time.
//
// `all` IS ONE OF THE CHOICES, and keeping it was a deliberate call against the narrower reading of
// "one kind at a time". Without it the complete picture — today's default, and the only view that
// shows a base's actual shape — becomes unreachable: a reader would have to select each layer in
// turn and union them in their head, which is strictly worse than the multi-select being removed.
// It costs nothing structurally, because `all` is still a single choice among N, and one-of-N is
// what the owner asked for. It stays the default.
//
// `manual` is absent by design. Nothing writes that source — the enum exists and the mention scanner
// still READS it, so the type keeps the member — but a filter offering a layer that is always empty
// teaches a user that the base has none of something it cannot have.
type GraphLayerFilter = 'all' | 'relation' | 'wikilink' | 'similarity' | 'mention';

const layer = ref<GraphLayerFilter>('all');

/**
 * Ghosts are a SUB-KIND of wikilinks, not a layer of their own: a red link IS a wikilink whose
 * target was never written. Promoting them to a peer put an entry's unwritten references on the
 * same footing as its real ones.
 */
type GhostMode = 'with' | 'without' | 'only';

const ghostMode = ref<GhostMode>('with');

/** What the layout and the neighbour list are actually allowed to draw. */
const kinds = computed<GraphEdgeKind[]>(() => {
  if (layer.value === 'wikilink') {
    if (ghostMode.value === 'only') return ['ghost'];

    return ghostMode.value === 'without' ? ['wikilink'] : ['wikilink', 'ghost'];
  }
  if (layer.value === 'all') return ['relation', 'wikilink', 'similarity', 'mention', 'ghost'];

  return [layer.value];
});

const layerOptions = computed(() => [
  { value: 'all' as const, label: t('knowledge.graph.layer.all') },
  { value: 'relation' as const, label: t('knowledge.graph.edge.relation') },
  { value: 'wikilink' as const, label: t('knowledge.graph.edge.wikilink') },
  { value: 'similarity' as const, label: t('knowledge.graph.edge.similarity') },
  { value: 'mention' as const, label: t('knowledge.graph.edge.mention') },
]);

/**
 * The ghost sub-filter as a `Select`, not a second `SegmentedControl`.
 *
 * `SegmentedControl` renders CARDS with radio indicators — the right weight for the primary choice
 * and the wrong one for something subordinate to it: two card rows side by side read as two equal
 * questions, and the reader has to work out which governs which. A `Select` is visually a different
 * class of control, so the hierarchy is legible at a glance, and it still names its current state on
 * its face rather than hiding it behind an icon.
 */
const ghostOptions = computed(() => [
  { value: 'with', label: t('knowledge.graph.ghosts.with') },
  { value: 'without', label: t('knowledge.graph.ghosts.without') },
  { value: 'only', label: t('knowledge.graph.ghosts.only') },
]);

/** How many edges of each kind the RESPONSE holds — not how many a chip is letting through. */
const kindCounts = computed<Partial<Record<GraphEdgeKind, number>>>(() => {
  const data = store.graph;
  const counts: Partial<Record<GraphEdgeKind, number>> = {
    relation: 0,
    wikilink: 0,
    similarity: 0,
    mention: 0,
    ghost: data?.ghosts?.length ?? 0,
  };
  for (const edge of data?.edges ?? []) {
    // The server's DISCRIMINATOR first: a relation carries a null `source`, so falling straight to
    // `?? 'manual'` would file every relation in the base under the manual chip.
    const kind: GraphEdgeKind =
      edge.kind === 'relation' ? 'relation' : ((edge.source ?? 'manual') as GraphEdgeKind);
    counts[kind] = (counts[kind] ?? 0) + 1;
  }
  return counts;
});

// --- Selection --------------------------------------------------------------
// Declared before the loaders: a re-centre clears the selection, and the watcher below runs
// immediately, i.e. before anything further down this file has been initialised.
const selectedId = ref<string | null>(null);
const panelOpen = ref(false);

// --- Slug → entry id --------------------------------------------------------
/**
 * Everything we have learned about slug → id, filled from every graph payload seen. A re-centre
 * therefore never needs a lookup: the node that was double-clicked taught us its id first.
 */
const slugIndex = ref<Record<string, string>>({});
const centerEntryId = ref<string | null>(null);
const resolvingCenter = ref(false);
/** The URL names a slug this base does not have (renamed or deleted entry). */
const unknownSlug = ref(false);

async function syncCenter(): Promise<void> {
  const target = slug.value;
  unknownSlug.value = false;
  if (!target) {
    centerEntryId.value = null;
    return;
  }

  const known = slugIndex.value[target] ?? store.entriesBySlug.get(target)?.id ?? null;
  if (known) {
    centerEntryId.value = known;
    return;
  }

  // COLD deep link only. The entry list is the one place a slug can be resolved (the graph
  // endpoint centres by id), so it is drained once here rather than on every graph visit.
  resolvingCenter.value = true;
  try {
    await store.fetchEntries(baseId.value, {}, { reset: true, drainAll: true });
    const found = store.entriesBySlug.get(target)?.id ?? null;
    centerEntryId.value = found;
    unknownSlug.value = found === null;
  } finally {
    resolvingCenter.value = false;
  }
}

// --- Loading ----------------------------------------------------------------
async function load({ refresh = false }: { refresh?: boolean } = {}): Promise<void> {
  if (!baseId.value) return;
  if (unknownSlug.value) return; // nothing to centre on — the empty state explains it
  await store.fetchGraph(
    baseId.value,
    // Always all three server sources: the chips filter what is DRAWN, never what is fetched.
    { entry: centerEntryId.value, depth: depth.value, sources: KNOWLEDGE_LINK_SOURCES },
    { refresh },
  );
}

watch(
  [baseId, slug],
  async () => {
    selectedId.value = null;
    await syncCenter();
    await load({ refresh: !!store.graph });
  },
  { immediate: true },
);

watch(depth, () => void load({ refresh: true }));

// Learn every slug the graph shows us, so re-centring stays a single request.
watch(
  () => store.graph,
  (data) => {
    if (!data) return;
    const next = { ...slugIndex.value };
    for (const node of data.nodes) next[node.slug] = node.id;
    slugIndex.value = next;
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  store.resetGraph();
  store.resetEntries();
  store.resetEntry();
});

// --- The picture ------------------------------------------------------------
/**
 * The payload the canvas is laid out from — the server's, minus any ended relations while the
 * historical switch is off.
 *
 * Filtered HERE rather than by refetching without them: the count under the canvas has to say how
 * many are being withheld, and a client that had not fetched them could not say. It is also
 * instant, where a round trip per toggle would not be.
 */
const graphData = computed<KnowledgeGraphData>(() => {
  const empty: KnowledgeGraphData = {
    center: null,
    nodes: [],
    edges: [],
    ghosts: [],
    truncated: { hidden_nodes: 0, hidden_edges: 0 },
  };
  const data = store.graph ?? empty;
  if (showHistorical.value) return data;

  return {
    ...data,
    edges: data.edges.filter(
      (edge) => edge.kind !== 'relation' || edge.state == null || edge.state === 'active',
    ),
  };
});

const layout = computed(() => layoutKnowledgeGraph(graphData.value, { kinds: kinds.value }));

/**
 * The list always describes the graph's CENTRE (or, in the overview, everything drawn) — NOT the
 * current selection. Rewriting the whole list every time a dot is clicked would move the ground
 * under a keyboard user mid-scan; re-centring rewrites it, and re-centring also changes the URL,
 * so the change is one the user asked for.
 */
const neighbours = computed(() => neighboursOf(layout.value, layout.value.centerId));

/**
 * The entry the neighbourhood is ABOUT — the subject of every relation sentence in the list.
 *
 * A neighbour row knows its own end and not the other one, so the sentence has to be told who it
 * is about. Null in the overview, which has no centre; relation rows only exist under a focus, so
 * that case never renders a subject-less sentence.
 */
const centerNode = computed<GraphNodePlacement | null>(
  () => layout.value.nodes.find((node) => node.id === layout.value.centerId) ?? null,
);

/**
 * Ended relations are OUT of the picture unless asked for.
 *
 * A graph that draws every statement that was ever true is a graph of a base's history, not of its
 * present, and at the 60-node cap the historical edges crowd out the live ones. Hidden is not the
 * same as dropped, though: the count is reported under the canvas whenever any are being withheld.
 */
const showHistorical = ref(false);

const historicalRelationCount = computed(
  () =>
    (store.graph?.edges ?? []).filter(
      (edge) => edge.kind === 'relation' && edge.state != null && edge.state !== 'active',
    ).length,
);

const hasHistoricalRelations = computed(() => historicalRelationCount.value > 0);
const hiddenHistoricalCount = computed(() =>
  showHistorical.value ? 0 : historicalRelationCount.value,
);

const selectedNode = computed<GraphNodePlacement | null>(
  () => layout.value.nodes.find((node) => node.id === selectedId.value) ?? null,
);

/**
 * The DERIVED edge between the centre and the selection — what "dismiss" acts on.
 *
 * Reads the SERVER's `can_be_dismissed`, which B12 added to the graph resource's edges. The
 * client-side mirror of `isDismissable()` that stood in for it while the flag was missing is
 * deleted: one authority, on the wire, where a new edge kind changes nothing here.
 */
const dismissable = computed(() => {
  const row = neighbours.value.find((n) => n.id === selectedId.value && n.canDismiss && n.linkId);
  return row?.linkId ? { linkId: row.linkId, kind: row.group as 'similarity' | 'mention' } : null;
});

function onSelect(id: string): void {
  selectedId.value = id;
  panelOpen.value = true;
}

/** Re-centre: a ghost has no entry to centre on, so it becomes the invitation to write it. */
function onActivate(id: string): void {
  const node = layout.value.nodes.find((n) => n.id === id);
  if (!node) return;
  if (node.kind === 'ghost') {
    createGhost(node.slug);
    return;
  }
  if (node.entryId) slugIndex.value = { ...slugIndex.value, [node.slug]: node.entryId };
  void router.push({
    name: 'next.knowledge.base.graph',
    params: { baseId: baseId.value, slug: node.slug },
    query: route.query,
  });
}

function openEntry(target: string): void {
  void router.push({
    name: 'next.knowledge.base.reader',
    params: { baseId: baseId.value, slug: target },
  });
}

/** The reader, at whatever it opens on — the way out of the "too few links" empty state. */
function openReader(): void {
  void router.push({
    name: 'next.knowledge.base.reader',
    params: slug.value ? { baseId: baseId.value, slug: slug.value } : { baseId: baseId.value },
  });
}

function clearSelection(): void {
  selectedId.value = null;
  panelOpen.value = false;
}

/**
 * A red link is an invitation — to the COMPOSER, seeded with the slug (spec §25.4). The location is
 * built by the shared helper: this function was a verbatim copy of the reader's (R17), and the
 * pivot is exactly the kind of change that would have updated one and missed the other.
 */
function createGhost(ghostSlug: string): void {
  void router.push(composeLocation(baseId.value, ghostSlug));
}

// --- The side panel ---------------------------------------------------------
/**
 * The preview's entry. A graph node is minimal by contract (no excerpt, no content), so the card
 * renders from the NODE immediately — no spinner, no flicker — and is swapped for the real entry
 * as soon as a background read lands. Fields the graph does not carry stay null/false; nothing in
 * the preview renders them, and inventing values for them would be worse than leaving them empty.
 */
const previewEntry = computed<KnowledgeEntryListItem | null>(() => {
  const node = selectedNode.value;
  if (!node || node.kind === 'ghost') return null;

  if (store.entry && store.entry.id === node.entryId) {
    return store.entry as unknown as KnowledgeEntryListItem;
  }
  const row = store.entriesBySlug.get(node.slug);
  if (row) return row;

  return {
    id: node.entryId ?? node.id,
    knowledge_base_id: baseId.value,
    title: node.title,
    slug: node.slug,
    excerpt: '',
    metadata: {},
    status: node.status,
    stale_at: null,
    is_stale: node.isStale,
    position: 0,
    current_revision_id: null,
    index: { status: null, chunks_count: null, needs_indexing: false },
    is_owner: false,
    can_be_edited: false,
    can_be_deleted: false,
    can_be_purged: false,
    created_at: null,
    updated_at: null,
    deleted_at: null,
  };
});

// Enrich the selection in the background. A failure is silent on purpose: the node-derived card is
// already correct, and a retry button inside a preview turns a glance into a chore.
watch(selectedId, () => {
  const node = selectedNode.value;
  if (!node?.entryId) return;
  if (store.entry?.id === node.entryId) return;
  if (store.entriesBySlug.has(node.slug)) return;
  void store.fetchEntry(node.entryId);
});

// --- Derived-edge dismiss / undo (similarity + mention) --------------------
const busyLink = ref(false);

async function onDismiss(linkId: string, kind: 'similarity' | 'mention'): Promise<void> {
  busyLink.value = true;
  try {
    await store.dismissLink(linkId);
    // The line leaves the canvas, the NODE stays: a dismissal rejects a suggested relation, not
    // the entry at the other end.
    store.removeGraphEdge(linkId);
    toast.success(t(kind === 'mention' ? 'knowledge.mentions.dismissed' : 'knowledge.similar.dismissed'), {
      action: { label: t('knowledge.common.undo'), onClick: () => void onUndo(linkId) },
    });
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  } finally {
    busyLink.value = false;
  }
}

async function onUndo(linkId: string): Promise<void> {
  busyLink.value = true;
  try {
    await store.undismissLink(linkId);
    await load({ refresh: true });
  } catch {
    toast.danger(t('knowledge.common.saveError'));
  } finally {
    busyLink.value = false;
  }
}

// --- Narrow screens ---------------------------------------------------------
/**
 * Below `next-lg` the LIST is the default and the canvas is opt-in (D9): a graph on a phone is
 * neither readable nor operable, and defaulting to it would hide the content behind a gesture.
 */
const smallMode = ref<'list' | 'graph'>('list');
const smallModeOptions = computed(() => [
  { value: 'list' as const, label: t('knowledge.graph.mode.list'), icon: 'list' as const },
  { value: 'graph' as const, label: t('knowledge.graph.mode.graph'), icon: 'network' as const },
]);

// --- Canvas controls --------------------------------------------------------
const canvasRef = ref<InstanceType<typeof KnowledgeGraphCanvas> | null>(null);

/**
 * Whether the canvas is withholding edge labels because there are too many to read.
 *
 * The RULE lives in the canvas (it is about what is drawn); the EXPLANATION lives here (it is a
 * line of prose under the picture). Read off the canvas's own exposed state rather than
 * recomputed, so the two can never disagree about whether labels are showing.
 */
const edgeLabelsSuppressed = computed(() => canvasRef.value?.edgeLabelsSuppressed === true);

// --- View states ------------------------------------------------------------
const firstLoad = computed(() => (store.graphLoading || resolvingCenter.value) && !store.graph);
const indexState = computed(() => (base.value ? baseIndexState(base.value, t) : null));
const indexNotReady = computed(() => !!indexState.value && indexState.value.status !== 'indexed');

const drawnEntries = computed(() => layout.value.nodes.filter((node) => node.kind === 'entry').length);
const hiddenNodes = computed(() => store.graph?.truncated?.hidden_nodes ?? 0);
const totalEntries = computed(() => drawnEntries.value + hiddenNodes.value);

/**
 * What the cap cost, in the language of the mode that is actually on screen. The overview's
 * "most-connected entries" would be a false claim about an ego graph, whose `hidden_nodes` counts
 * the NEIGHBOURHOOD the walk found and could not draw.
 */
const cappedLabel = computed(() =>
  t(layout.value.mode === 'ego' ? 'knowledge.graph.cappedEgo' : 'knowledge.graph.capped', '', {
    shown: drawnEntries.value,
    total: totalEntries.value,
  }),
);

/**
 * The CHOSEN LAYER is empty, while the base has something to show.
 *
 * This used to be "every toggle is off", a state one-of-N cannot reach — there is always exactly
 * one layer selected. The situation it guards against is real all the same and now easier to hit:
 * pick "Relations" on a base that has none and the canvas goes blank with nothing to explain it.
 * The way out is the same as before, only its meaning changed: go back to the whole picture.
 */
const layerIsEmpty = computed(() => {
  if (layer.value === 'all') return false;
  const drawn = layout.value;

  return drawn.edges.length === 0 && drawn.nodes.length > 0;
});
/** A cloud of unconnected dots is not a graph — say so, and say what to do about it. */
const nothingToDraw = computed(() => {
  const data = store.graph;
  if (!data) return false;
  return data.nodes.length === 0 || (data.edges.length === 0 && data.ghosts.length === 0);
});

function openTable(): void {
  void router.push({ name: 'next.knowledge.base.table', params: { baseId: baseId.value } });
}
</script>

<template>
  <div class="flex min-h-0 flex-col gap-next-4">
    <PageHeader
      :title="t('knowledge.graph.title')"
      :description="t('knowledge.graph.subtitle')"
      icon="network"
      :level="1"
    >
      <template #actions>
        <SegmentedControl
          :model-value="depth"
          :options="depthOptions"
          size="sm"
          :aria-label="t('knowledge.graph.depth')"
          @update:model-value="(value: unknown) => setDepth(value as KnowledgeGraphDepth)"
        />
        <Button variant="outline" size="sm" leading-icon="crop" @click="canvasRef?.fit()">
          {{ t('knowledge.graph.fit') }}
        </Button>
      </template>
    </PageHeader>

    <!--
      THE LAYER FILTER, one of N, on the same `SegmentedControl` this screen already uses for depth
      and for list/graph mode.

      It used to be a row of `Button`s with `aria-pressed`, and the comment here argued FOR that:
      multi-select is not one-of-N, which is what a SegmentedControl announces. The premise was
      right and the conclusion is now the other way round — the filter itself became one-of-N, so
      the component that announces one-of-N is the correct one and the hand-rolled `aria-pressed`
      scaffold comes off rather than being layered on top of it.

      COUNTS MOVED TO THE LEGEND. They were on the toggles, which no longer works: a one-of-N card
      shows what you can pick, and only the count of the layer you already chose would stay visible.
      The legend lists every kind at once, so "Relations: 7 · Wikilinks: 19" is readable without
      changing the filter — and the legend keeps its real job of explaining the line patterns.
    -->
    <Surface bg="card" border radius="lg" class="flex flex-wrap items-center gap-next-3 px-next-4 py-next-3">
      <SegmentedControl
        :model-value="layer"
        :options="layerOptions"
        size="sm"
        :aria-label="t('knowledge.graph.layer.label')"
        data-layer-filter
        @update:model-value="(value: unknown) => (layer = value as GraphLayerFilter)"
      />

      <!--
        The ghost sub-filter, present ONLY under wikilinks. A red link is a wikilink whose target
        was never written, so it belongs to that layer and nowhere else — and a control that stayed
        on screen while it governed nothing would be a dead affordance the reader has to test to
        understand.
      -->
      <Select
        v-if="layer === 'wikilink'"
        :model-value="ghostMode"
        :options="ghostOptions"
        size="sm"
        leading-icon="unlink"
        :aria-label="t('knowledge.graph.ghosts.label')"
        class="w-56"
        data-ghost-filter
        @update:model-value="(value: string) => (ghostMode = value as GhostMode)"
      />

      <!--
        Ended relations, OFF by default. Only offered when the base actually has some: a switch for
        a state that cannot occur is a control that teaches nothing and costs a scan.
      -->
      <Switch
        v-if="hasHistoricalRelations"
        v-model="showHistorical"
        size="sm"
        :label="t('knowledge.relations.showHistorical')"
        data-historical-toggle
      />

      <div class="ms-auto">
        <KnowledgeGraphLegend :counts="kindCounts" />
      </div>
    </Surface>

    <!--
      Two things the picture cannot say about itself. Both are `Text variant="caption"` rather than
      alerts, because neither is a fault: one is a filter the user set, the other is a density rule.
      What matters is that neither omission is SILENT — a canvas that quietly drops content is a
      canvas the reader has no reason to trust.
    -->
    <Text v-if="hiddenHistoricalCount > 0" variant="caption" tone="muted" data-hidden-historical>
      {{ t('knowledge.relations.hiddenHistorical', '', { count: hiddenHistoricalCount }) }}
    </Text>
    <Text v-if="edgeLabelsSuppressed" variant="caption" tone="muted" data-labels-hidden>
      {{ t('knowledge.relations.labelsHidden') }}
    </Text>

    <!-- Indexing is not finished: the graph still draws every wikilink, so this is a note, not an
         error, and the canvas stays exactly where it is. -->
    <Alert v-if="indexNotReady" variant="info" size="sm">
      {{ t('knowledge.graph.indexNotice') }}
    </Alert>

    <!-- The URL names a slug this base does not have. -->
    <EmptyState
      v-if="unknownSlug && slug"
      variant="search"
      :title="t('knowledge.reader.notFound.title')"
      :description="t('knowledge.reader.notFound.description')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="plus" @click="createGhost(slug)">
          {{ t('knowledge.ghosts.create') }}
        </Button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="store.graphErrored"
      variant="error"
      :title="t('knowledge.common.loadError')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load()">
          {{ t('knowledge.common.retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Loading: dots on a circle, the shape of the real thing. Deterministic positions (the same
         even placement the layout uses), so the skeleton does not dance between mounts. -->
    <div
      v-else-if="firstLoad"
      class="relative min-h-[28rem] flex-1 rounded-next-lg border border-next-border bg-next-card"
      role="status"
      :aria-label="t('knowledge.graph.loadingLabel')"
    >
      <div class="absolute inset-0 flex items-center justify-center">
        <div class="relative h-64 w-64">
          <Skeleton
            v-for="i in 12"
            :key="`dot-${i}`"
            variant="circle"
            :diameter="`${10 + (i % 5) * 2}px`"
            class="absolute"
            :style="{
              left: `${50 + 42 * Math.cos((i / 12) * 2 * Math.PI - Math.PI / 2)}%`,
              top: `${50 + 42 * Math.sin((i / 12) * 2 * Math.PI - Math.PI / 2)}%`,
            }"
          />
          <Skeleton
            v-for="i in 3"
            :key="`line-${i}`"
            variant="rect"
            width="60%"
            height="1px"
            class="absolute left-[20%]"
            :style="{ top: `${35 + i * 12}%` }"
          />
        </div>
      </div>
    </div>

    <EmptyState
      v-else-if="layerIsEmpty"
      variant="search"
      :title="t('knowledge.graph.emptyFiltered.title')"
    >
      <template #action>
        <Button variant="outline" size="sm" @click="layer = 'all'">
          {{ t('knowledge.graph.emptyFiltered.action') }}
        </Button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="nothingToDraw"
      icon="network"
      :title="t('knowledge.graph.empty.title')"
      :description="t('knowledge.graph.empty.description')"
    >
      <template #action>
        <Button size="sm" leading-icon="book-open" @click="openReader">
          {{ t('knowledge.graph.empty.action') }}
        </Button>
      </template>
    </EmptyState>

    <template v-else>
      <!-- The cap receipt: what is shown, out of what exists, with the way to the rest. -->
      <div v-if="hiddenNodes > 0" class="flex flex-wrap items-center gap-next-2">
        <Text variant="caption" tone="muted">{{ cappedLabel }}</Text>
        <Button variant="link" size="xs" @click="openTable">
          {{ t('knowledge.graph.showMore', '', { count: hiddenNodes }) }}
        </Button>
      </div>

      <!-- Below next-lg the LIST is the default and the canvas is opt-in (D9). -->
      <div class="next-lg:hidden">
        <SegmentedControl
          v-model="smallMode"
          :options="smallModeOptions"
          size="sm"
          :aria-label="t('knowledge.graph.mode.label')"
        />
      </div>

      <div class="flex min-h-0 flex-col gap-next-4 next-lg:flex-row">
        <!-- Canvas. Hidden (not merely visually) below next-lg unless asked for, so its pointer
             handlers never compete with the page's own scrolling on a phone. -->
        <div
          class="relative min-h-[28rem] flex-1"
          :class="smallMode === 'graph' ? '' : 'hidden next-lg:block'"
        >
          <KnowledgeGraphCanvas
            ref="canvasRef"
            :layout="layout"
            :selected-id="selectedId"
            :status-icons="statusIcons"
            :busy="store.graphRefreshing"
            @select="onSelect"
            @activate="onActivate"
          />

          <!-- Real buttons, outside the SVG. The canvas is never the only way to do anything. -->
          <div class="absolute end-next-3 top-next-3 flex flex-col gap-next-1">
            <Button
              variant="outline"
              size="icon-sm"
              leading-icon="plus"
              :aria-label="t('knowledge.graph.zoomIn')"
              @click="canvasRef?.zoomIn()"
            />
            <Button
              variant="outline"
              size="icon-sm"
              leading-icon="minus"
              :aria-label="t('knowledge.graph.zoomOut')"
              @click="canvasRef?.zoomOut()"
            />
          </div>

          <Text variant="caption" tone="muted" class="mt-next-2 block">
            {{ t('knowledge.graph.canvasHidden') }}
          </Text>
        </div>

        <!-- The accessible surface + the side panel. -->
        <div
          class="flex min-h-0 w-full flex-col gap-next-4 next-lg:w-80 next-lg:shrink-0"
          :class="smallMode === 'graph' ? 'hidden next-lg:flex' : ''"
        >
          <Surface bg="card" border radius="lg" class="flex min-h-0 flex-col gap-next-2 p-next-3">
            <Text variant="ui" class="font-next-medium">{{ t('knowledge.graph.neighbours') }}</Text>
            <KnowledgeGraphNeighbourList
              :rows="neighbours"
              :focus-title="centerNode?.title"
              :selected-id="selectedId"
              :status-map="statusMap"
              :loading="store.graphRefreshing && neighbours.length === 0"
              @select="onSelect"
              @activate="onActivate"
              @clear="clearSelection"
            />
          </Surface>

          <!-- The selected node, in the SAME card the reader's hover popover uses — with actions
               turned on, because this panel is a real, reachable surface. -->
          <Surface
            v-if="selectedNode && panelOpen"
            bg="card"
            border
            radius="lg"
            class="flex flex-col gap-next-2 p-next-3"
          >
            <KnowledgeEntryPreview
              :entry="previewEntry"
              :slug="selectedNode.slug"
              :ghost="selectedNode.kind === 'ghost'"
              actions
            >
              <template #actions>
                <template v-if="selectedNode.kind === 'ghost'">
                  <Button size="sm" leading-icon="plus" @click="createGhost(selectedNode.slug)">
                    {{ t('knowledge.ghosts.create') }}
                  </Button>
                </template>
                <template v-else>
                  <Button size="sm" leading-icon="book-open" @click="openEntry(selectedNode.slug)">
                    {{ t('knowledge.graph.openEntry') }}
                  </Button>
                  <Button
                    v-if="selectedNode.id !== layout.centerId"
                    variant="outline"
                    size="sm"
                    leading-icon="network"
                    @click="onActivate(selectedNode.id)"
                  >
                    {{ t('knowledge.graph.center') }}
                  </Button>
                  <!-- Dismiss the DERIVED relation to the centre — a proposal (similarity) or a
                       name found in the text (mention). The label names which. -->
                  <Button
                    v-if="dismissable"
                    variant="ghost"
                    size="icon-sm"
                    leading-icon="x"
                    :disabled="busyLink"
                    :aria-label="
                      t(
                        dismissable.kind === 'mention' ? 'knowledge.mentions.dismiss' : 'knowledge.similar.dismiss',
                        '',
                        { title: selectedNode.title },
                      )
                    "
                    @click="onDismiss(dismissable.linkId, dismissable.kind)"
                  />
                </template>
              </template>
            </KnowledgeEntryPreview>
          </Surface>
        </div>
      </div>
    </template>
  </div>
</template>
