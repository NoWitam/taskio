<script setup lang="ts">
// KnowledgeDraftBoard — the drafts, the bulk action, and the relations preview (spec §24.4).
//
// An ORDERED LIST (`<ol>` of `<li>` cards) under an `<h2>`, so the heading run is h1 (the composer)
// → h2 (this board) → h3 (each draft) with no skipped level.
//
// ACCEPTANCE IS NEVER AUTOMATIC AND NEVER IMPLICIT (DC8). There is no "accept all and close": the
// bulk action works only on what is CHECKED, says how many in its own label, and confirms with the
// count and the resulting status. A high-overlap draft confirms again, by name, because at that
// point the likely outcome is two entries saying the same thing.
import { computed, onBeforeUnmount, ref } from 'vue';
import Button from '../../../ui/primitives/Button.vue';
import Checkbox from '../../../ui/forms/Checkbox.vue';
import Text from '../../../ui/primitives/Text.vue';
import Progress from '../../../ui/feedback/Progress.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import Accordion from '../../../ui/disclosure/Accordion.vue';
import AccordionItem from '../../../ui/disclosure/AccordionItem.vue';
import KnowledgeDraftCard from './KnowledgeDraftCard.vue';
import type { ReportLine } from '../relations/graphOpsReport';
import KnowledgeDraftRelationsPanel from './KnowledgeDraftRelationsPanel.vue';
import { useI18n } from '../../../app/i18n';
import type { KnowledgeBase, KnowledgeDraftDuplicate, KnowledgeDraftEntry } from '../types';

const props = withDefaults(
  defineProps<{
    sessionId: string;
    drafts: KnowledgeDraftEntry[];
    base: KnowledgeBase | null;
    /** Duplicate warnings by DRAFT id, straight from the session payload. */
    duplicates?: Record<string, KnowledgeDraftDuplicate>;
    /** The run notes for each amendment target, keyed by TARGET SLUG and already worded. */
    notes?: Record<string, ReportLine[]>;
    /**
     * How many GRAPH OPERATIONS are ticked in the panel below.
     *
     * The board owns the only accept button, but it is no longer the only thing that can be
     * accepted: a run that proposes "Anna left Acme in July" touches two existing entries and
     * creates no draft at all. That is the commonest incremental update there is, and gating the
     * button on drafts alone made it unreachable — the reviewer saw relations they had no way to
     * approve.
     */
    graphOpCount?: number;
    /** Draft ids already published in this session. */
    acceptedIds?: Set<string>;
    /** Per-draft server refusals, already turned into sentences by the view. */
    errors?: Record<string, string>;
    /** Draft ids whose acceptance CONFLICTED (the target moved) — they get the two-route panel. */
    conflictedIds?: Set<string>;
    /** The draft a rebase is running for. */
    rebasingId?: string | null;
    /** The context was widened and no revision has consumed it yet — the action goes spent. */
    contextExpanded?: boolean;
    expandingContext?: boolean;
    busyId?: string | null;
    bulkBusy?: boolean;
    /** How far a sequential bulk acceptance has got (determinate progress). */
    bulkProgress?: { done: number; total: number } | null;
    hasPreviousIteration?: boolean;
  }>(),
  {
    duplicates: () => ({}),
    notes: () => ({}),
    graphOpCount: 0,
    acceptedIds: () => new Set<string>(),
    errors: () => ({}),
    conflictedIds: () => new Set<string>(),
    rebasingId: null,
    contextExpanded: false,
    expandingContext: false,
    busyId: null,
    bulkBusy: false,
    bulkProgress: null,
    hasPreviousIteration: false,
  },
);

const emit = defineEmits<{
  (e: 'accept', draft: KnowledgeDraftEntry, status: 'approved' | 'draft'): void;
  (e: 'accept-selected', ids: string[]): void;
  (e: 'reject', draft: KnowledgeDraftEntry): void;
  (e: 'refine-draft', draft: KnowledgeDraftEntry): void;
  (e: 'rebase', draft: KnowledgeDraftEntry): void;
  (e: 'expand-context'): void;
  (e: 'open-entry', slug: string): void;
}>();

const { t } = useI18n();

const selected = ref<Set<string>>(new Set());

/** Only UNRESOLVED drafts can be selected — an accepted card has nothing left to act on. */
const selectable = computed(() => props.drafts.filter((d) => !props.acceptedIds.has(d.id)));

const selectedIds = computed(() => selectable.value.filter((d) => selected.value.has(d.id)).map((d) => d.id));

/**
 * Everything the accept button will commit — drafts AND graph operations.
 *
 * A graph-only acceptance is a real and common outcome, not a degenerate one, and the server takes
 * `entry_ids: []` for exactly that. Counting only drafts left the most ordinary incremental run
 * ("Anna left Acme in July": two existing entries, no new entry) with proposals on screen and no
 * way at all to approve them.
 */
const acceptCount = computed(() => selectedIds.value.length + props.graphOpCount);
const nothingSelected = computed(() => acceptCount.value === 0);

const allSelected = computed(
  () => selectable.value.length > 0 && selectedIds.value.length === selectable.value.length,
);

function toggleOne(id: string, value: boolean): void {
  const next = new Set(selected.value);
  if (value) next.add(id);
  else next.delete(id);
  selected.value = next;
}

function toggleAll(value: boolean): void {
  selected.value = value ? new Set(selectable.value.map((d) => d.id)) : new Set();
}

/**
 * Which panels are open. Controlled (rather than `defaultValue`) purely so the relations preview
 * can be mounted lazily — see the guard in the template.
 */
const openPanels = ref<string[]>([]);
const relationsOpen = computed(() => openPanels.value.includes('relations'));

/**
 * The card the relations panel just pointed at.
 *
 * A pending amendment is drawn as an ANNOTATION on the target's node (never its own circle — see
 * the relations service), so "show me the proposal" cannot be a navigation: the proposal is a card
 * on this very board. Scroll to it and ring it for two seconds. The highlight clears itself, so
 * nothing has to remember to turn it off.
 */
const highlightedId = ref<string | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | undefined;

function focusDraft(draftId: string): void {
  const card = document.querySelector<HTMLElement>(`[data-draft-id="${draftId}"]`);
  card?.scrollIntoView({ block: 'center', behavior: 'smooth' });

  highlightedId.value = draftId;
  if (highlightTimer) clearTimeout(highlightTimer);
  highlightTimer = setTimeout(() => (highlightedId.value = null), 2000);
}

onBeforeUnmount(() => {
  if (highlightTimer) clearTimeout(highlightTimer);
});

// Exposed so the fact checklist can point at a card too. A draft is not a page yet — it has no
// entry to navigate to — so "go to the entry that covered this" is the same scroll-and-ring the
// relations panel already uses, rather than a route that would 404 on a shadow's reserved slug.
defineExpose({ focusDraft });
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <!-- The board's own heading: the level between the page title and each draft. -->
    <div class="flex flex-wrap items-center gap-next-3">
      <h2 class="text-next-lg font-next-semibold text-next-fg">
        {{ t('knowledge.compose.boardTitle', '', { count: drafts.length }) }}
      </h2>

      <div class="ms-auto flex flex-wrap items-center gap-next-3">
        <Checkbox
          v-if="selectable.length > 0"
          :model-value="allSelected"
          :aria-label="t('knowledge.compose.selectAll')"
          :disabled="bulkBusy"
          @update:model-value="toggleAll"
        />

        <!-- Disabled with a REASON in the accessible name: a dead button with no explanation is
             what D15 exists to prevent. -->
        <Button
          size="sm"
          :disabled="nothingSelected || bulkBusy"
          :loading="bulkBusy"
          :aria-label="
            nothingSelected
              ? t('knowledge.compose.acceptSelectedNone')
              : t('knowledge.compose.acceptSelected', '', { count: acceptCount })
          "
          data-accept-selected
          @click="emit('accept-selected', selectedIds)"
        >
          {{ t('knowledge.compose.acceptSelected', '', { count: acceptCount }) }}
        </Button>
      </div>
    </div>

    <!-- Sequential, determinate: a bulk acceptance stops at the first refusal and leaves the
         details on that card, so nothing is lost and the user knows where it stopped. -->
    <Progress
      v-if="bulkBusy && bulkProgress"
      :value="bulkProgress.total > 0 ? Math.round((bulkProgress.done / bulkProgress.total) * 100) : 0"
      size="sm"
      tone="primary"
      :aria-label="t('knowledge.compose.acceptSelected', '', { count: bulkProgress.total })"
    />

    <!--
      "No proposals" ONLY when the run really proposed nothing. With graph operations waiting in
      the panel below, this said "the agent produced nothing" directly above a list of things the
      agent produced — a contradiction the reviewer has to resolve, and they resolve it by trusting
      neither. A graph-only run is a SUCCESS with no drafts, not an empty result.
    -->
    <EmptyState
      v-if="drafts.length === 0 && graphOpCount === 0"
      variant="search"
      :title="t('knowledge.compose.noDrafts.title')"
      :description="t('knowledge.compose.noDrafts.description')"
    />

    <!-- Drafts empty but the graph half is not: say what DID happen, in one quiet line. -->
    <Text v-else-if="drafts.length === 0" variant="caption" tone="muted" data-graph-only>
      {{ t('knowledge.compose.graphOnly', '', { count: graphOpCount }) }}
    </Text>

    <ol v-else class="flex flex-col gap-next-4">
      <KnowledgeDraftCard
        v-for="draft in drafts"
        :key="draft.id"
        :draft="draft"
        :base="base"
        :selected="selected.has(draft.id)"
        :duplicate="duplicates[draft.id] ?? null"
        :notes="notes[draft.targets_entry?.slug ?? ''] ?? []"
        :accepted="acceptedIds.has(draft.id)"
        :error="errors[draft.id] ?? null"
        :conflicted="conflictedIds.has(draft.id)"
        :busy="busyId === draft.id || bulkBusy"
        :rebasing="rebasingId === draft.id"
        :highlighted="highlightedId === draft.id"
        :has-previous-iteration="hasPreviousIteration"
        @update:selected="(v: boolean) => toggleOne(draft.id, v)"
        @accept="(status: 'approved' | 'draft') => emit('accept', draft, status)"
        @reject="emit('reject', draft)"
        @refine="emit('refine-draft', draft)"
        @rebase="emit('rebase', draft)"
        @open-duplicate="(slug: string) => emit('open-entry', slug)"
        @open-entry="(slug: string) => emit('open-entry', slug)"
      />
    </ol>

    <!-- Relations are an ADDITION to the review, so they are opt-in — and the panel is MOUNTED
         only once opened. `AccordionItem` always renders its slot (it animates with grid rows), so
         without this guard the preview would fetch, and possibly spend an embedding, for every
         reviewer who never looked at it. -->
    <Accordion v-if="drafts.length > 0" v-model="openPanels" type="multiple" class="flex flex-col">
      <AccordionItem value="relations" icon="network" :title="t('knowledge.compose.relations')">
        <KnowledgeDraftRelationsPanel
          v-if="relationsOpen"
          :session-id="sessionId"
          :context-expanded="contextExpanded"
          :expanding="expandingContext"
          @focus-draft="focusDraft"
          @expand-context="emit('expand-context')"
        />
      </AccordionItem>
    </Accordion>
  </div>
</template>
