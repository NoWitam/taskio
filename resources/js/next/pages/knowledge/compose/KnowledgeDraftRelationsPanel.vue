<script setup lang="ts">
// KnowledgeDraftRelationsPanel — what this proposal would connect to (spec §24.6 / DC5).
//
// A REUSE of the base graph, not a second visualisation: the same `layoutKnowledgeGraph`, the same
// canvas, the same neighbour list, the same legend. That is only possible because the endpoint
// returns the base graph's exact shape (verified: `KnowledgeDraftRelationService::payload`), which
// was the precondition K2 asked for. Two canvases would have drifted within a batch.
//
// Two node facts are added rather than a new edge kind, so every exhaustive `switch` over kinds in
// the layout stays exhaustive: `is_draft` (this circle is a proposal) and `amended_by` (this
// existing entry has a pending amendment).
//
// `amended_by` is an ANNOTATION and not an edge for a reason the drawing cannot show: a shadow
// draft is never a node, so a line to it would have an endpoint outside `nodes[]` — the one
// invariant the layout relies on. Hence a glyph on the target plus a worded row that points at the
// proposal's CARD, which is where the proposal actually lives.
//
// The list is the accessible surface and the small-screen default, exactly as in the base graph
// (D9): the canvas is `aria-hidden` and cannot be the only carrier of the content.
import { computed, ref, watch } from 'vue';
import SegmentedControl from '../../../ui/forms/SegmentedControl.vue';
import Alert from '../../../ui/feedback/Alert.vue';
import Text from '../../../ui/primitives/Text.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import Button from '../../../ui/primitives/Button.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import KnowledgeGraphCanvas from '../graph/KnowledgeGraphCanvas.vue';
import KnowledgeGraphNeighbourList from '../graph/KnowledgeGraphNeighbourList.vue';
import KnowledgeGraphLegend from '../graph/KnowledgeGraphLegend.vue';
import { layoutKnowledgeGraph } from '../graph/knowledgeGraphLayout';
import { neighboursOf } from '../graph/graphNeighbours';
import { entryStatusMap } from '../statusMaps';
import { useKnowledgeStore } from '../../../app/stores/knowledge';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeDraftRelations } from '../types';

const props = withDefaults(
  defineProps<{
    sessionId: string;
    /**
     * The context has ALREADY been widened and no revision has used it yet. The action goes spent
     * rather than staying live: its effect is invisible until the next revision, so a second click
     * would buy the same widening twice.
     */
    contextExpanded?: boolean;
    expanding?: boolean;
  }>(),
  { contextExpanded: false, expanding: false },
);

const emit = defineEmits<{
  /** "Show me the proposal against this entry" — the shadow card is on the board, not elsewhere. */
  (e: 'focus-draft', draftId: string): void;
  /** Retrieve more context for the next run. SPENDS one metered embedding. */
  (e: 'expand-context'): void;
}>();

const { t } = useI18n();
const store = useKnowledgeStore();

const relations = ref<KnowledgeDraftRelations | null>(null);
const loading = ref(false);
const errored = ref(false);

async function load(): Promise<void> {
  loading.value = true;
  errored.value = false;
  try {
    relations.value = await store.fetchDraftRelations(props.sessionId);
  } catch {
    relations.value = null;
    errored.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => props.sessionId, () => void load(), { immediate: true });

const statusMap = computed(() => entryStatusMap(t));
const statusIcons = computed(() =>
  Object.fromEntries(Object.entries(statusMap.value).map(([status, d]) => [status, d.icon])),
);

/** The SAME pure layout the base graph uses — no fork, no second set of coordinates. */
const layout = computed(() =>
  layoutKnowledgeGraph(
    relations.value ?? { center: null, nodes: [], edges: [], ghosts: [], truncated: { hidden_nodes: 0, hidden_edges: 0 } },
  ),
);

/** Which placement ids are PROPOSALS — the canvas draws them with a doubled outline, not a colour. */
const draftIds = computed(
  () => new Set((relations.value?.nodes ?? []).filter((n) => n.is_draft).map((n) => n.id)),
);

/**
 * Existing entries with a PENDING AMENDMENT against them.
 *
 * A shadow draft is never its own node and never an edge (verified in the relations service): an
 * edge to it would have an endpoint outside `nodes[]`, breaking the invariant the canvas lays
 * itself out on. So the pending change is an ANNOTATION on the target — which is also how it
 * should be read: the entry is about to change, not to be joined to something.
 */
const amendedIds = computed(
  () => new Set((relations.value?.nodes ?? []).filter((n) => (n.amended_by?.length ?? 0) > 0).map((n) => n.id)),
);

/** node id → the draft proposing the change, so a row can point at the card that holds it. */
const amendingDraft = computed<Record<string, string>>(() => {
  const map: Record<string, string> = {};
  for (const node of relations.value?.nodes ?? []) {
    const first = node.amended_by?.[0]?.draft_id;
    if (first) map[node.id] = first;
  }
  return map;
});

const neighbours = computed(() => neighboursOf(layout.value, null));

/** Empty is NORMAL here, and says so — unlike the base graph, this is not a problem to fix. */
const isEmpty = computed(() => (relations.value?.nodes.length ?? 0) === 0);

const mode = ref<'list' | 'graph'>('list');
const modeOptions = computed(() => [
  { value: 'list' as const, label: t('knowledge.graph.mode.list'), icon: 'list' as const },
  { value: 'graph' as const, label: t('knowledge.graph.mode.graph'), icon: 'network' as const },
]);

/**
 * The semantic legs did not run, so the preview is deterministic edges only. A quiet inline NOTE,
 * not an error: what is drawn is true, there is simply less of it.
 */
const vectorSkipped = computed(() => relations.value?.vector_skipped ?? null);
</script>

<template>
  <div class="flex flex-col gap-next-3" data-relations-panel>
    <div v-if="loading" class="flex flex-col gap-next-2" role="status" :aria-label="t('knowledge.common.loadingLabel')">
      <Skeleton variant="text" width="35%" />
      <Skeleton variant="rect" height="8rem" radius="md" />
    </div>

    <!-- Relations are an ADDITION, never a condition of accepting: a failure here leaves the rest
         of the card working and offers a retry. -->
    <Alert v-else-if="errored" variant="danger" size="sm">
      <div class="flex items-center justify-between gap-next-2">
        <span>{{ t('knowledge.compose.relationsError') }}</span>
        <Button variant="ghost" size="xs" leading-icon="rotate-ccw" @click="load">
          {{ t('knowledge.common.retry') }}
        </Button>
      </div>
    </Alert>

    <EmptyState
      v-else-if="isEmpty"
      size="sm"
      icon="network"
      :title="t('knowledge.compose.relationsEmpty')"
      :description="t('knowledge.compose.relationsEmptyHint')"
    />

    <template v-else>
      <Text v-if="vectorSkipped" variant="caption" tone="muted">
        {{ t(`knowledge.compose.vectorSkipped.${vectorSkipped}`, t('knowledge.compose.vectorSkipped.error')) }}
      </Text>

      <div class="next-lg:hidden">
        <SegmentedControl v-model="mode" :options="modeOptions" size="sm" :aria-label="t('knowledge.graph.mode.label')" />
      </div>

      <div class="flex flex-col gap-next-3 next-lg:flex-row">
        <div class="min-h-[18rem] flex-1" :class="mode === 'graph' ? '' : 'hidden next-lg:block'">
          <KnowledgeGraphCanvas
            :layout="layout"
            :status-icons="statusIcons"
            :draft-ids="draftIds"
            :amended-ids="amendedIds"
          />
        </div>

        <div
          class="flex min-h-0 w-full flex-col gap-next-2 next-lg:w-72 next-lg:shrink-0"
          :class="mode === 'graph' ? 'hidden next-lg:flex' : ''"
        >
          <KnowledgeGraphNeighbourList
            :rows="neighbours"
            :status-map="statusMap"
            :draft-ids="draftIds"
            :amended-ids="amendedIds"
            :label="t('knowledge.compose.relations')"
            @open-amendment="(id: string) => emit('focus-draft', amendingDraft[id] ?? '')"
          />
        </div>
      </div>

      <KnowledgeGraphLegend :draft-shape="true" :amended-shape="true" />

      <!-- One more metered embedding, so it is an EXPLICIT action with its price stated — never
           something a refinement does quietly on the user's behalf. -->
      <div class="flex flex-wrap items-center gap-next-2 border-t border-next-border pt-next-3">
        <!-- SPENT: already widened, and nothing has used it yet. The button is out, and the reason
             is right there — an action that looks available but changes nothing on the second
             click is how a user pays twice for one widening. -->
        <template v-if="contextExpanded">
          <Button
            variant="outline"
            size="sm"
            leading-icon="check-circle"
            disabled
            data-expand-context
            :aria-label="`${t('knowledge.compose.expandContext')} — ${t('knowledge.compose.expandContextReady')}`"
          >
            {{ t('knowledge.compose.expandContext') }}
          </Button>
          <!-- Icon + words, so "done" is not carried by colour alone. -->
          <span class="flex items-center gap-next-1_5 text-next-xs text-next-muted-foreground" data-expand-context-done>
            <Icon name="check-circle" class="shrink-0" aria-hidden="true" />
            {{ t('knowledge.compose.expandContextReady') }}
          </span>
        </template>

        <template v-else>
          <Button
            variant="outline"
            size="sm"
            leading-icon="sparkles"
            :loading="expanding"
            :disabled="expanding"
            data-expand-context
            @click="emit('expand-context')"
          >
            {{ t('knowledge.compose.expandContext') }}
          </Button>
          <Text variant="caption" tone="muted">{{ t('knowledge.compose.expandContextHint') }}</Text>
        </template>
      </div>
    </template>
  </div>
</template>
