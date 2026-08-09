<script setup lang="ts">
// KnowledgeVersionsDrawer — an entry's append-only version history, READ-ONLY, with a DIFF.
//
// RESTORING IS GONE, and the history stayed. The two were worth separating: restoring republishes
// an old body under the current entry, which is authoring its text by another route — but READING
// the history is how anybody audits what the AI wrote and what a human approved, and that need got
// larger, not smaller, when authorship moved to the machine. A log you cannot rewrite is exactly
// what an audit trail is supposed to be.
//
// THE DIFF (B14). It was out of the MVP on the argument that diffing markdown carrying inline
// directives is its own problem — but the Disk's text editor had already solved the part that
// matters (a line/word LCS that treats the text as text), and that engine is now a design-system
// component. So each version compares against the entry's CURRENT content, which is the question
// a history actually answers: "what changed, and when?" Directives are diffed as the literal text
// they are, which is honest — they are text in the stored content too.
//
// Every version, including the newest, is expandable; the newest simply diffs to "no changes",
// which is the correct answer rather than a special case. The toggle is per-version, so opening
// one does not re-render the rest, and only the expanded one runs the diff at all.

import { computed, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Timeline from '../../ui/patterns/Timeline.vue';
import TimelineItem from '../../ui/patterns/TimelineItem.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import TextDiffView from '../../ui/data/TextDiffView.vue';
import SegmentedControl from '../../ui/forms/SegmentedControl.vue';
import CreatorBadge from '../../ui/patterns/CreatorBadge.vue';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { useConfirm } from '../../app/composables/useConfirm';
import { useToast } from '../../app/composables/useToast';

import { formatDate } from './baseMeta';
import { useI18n } from '../../app/i18n';
import { type KnowledgeEntry } from './types';

const props = defineProps<{
  /** The entry whose history this is. Null closes the drawer's data lifecycle. */
  entry: KnowledgeEntry | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const store = useKnowledgeStore();
const confirm = useConfirm();
const toast = useToast();

// Load on OPEN, not on mount: the history is a drawer's worth of data nobody asked for until they
// open it, and an entry can be read all day without it.
watch(
  () => [open.value, props.entry?.id] as const,
  ([isOpen, entryId]) => {
    if (isOpen && entryId) void store.fetchRevisions(entryId);
    if (!isOpen) store.resetRevisions();
  },
  { immediate: true },
);

const revisions = computed(() => store.revisions);

/**
 * Version NUMBERS, counted from the oldest. The API returns newest-first and carries no ordinal, so
 * the number is derived here — and derived from the full list, which is why the history endpoint
 * being unpaginated matters.
 */
function versionNumber(index: number): number {
  return revisions.value.length - index;
}

/** The newest revision is the one the entry currently shows. */
function isCurrent(index: number): boolean {
  return index === 0;
}

// --- Diff / content view ----------------------------------------------------
/**
 * Which revision is expanded, and how it is being read. At most ONE at a time: the diff is a
 * synchronous LCS over up to 40 000 characters (bounded, but not free), and rendering ten of them
 * because a drawer was opened would be work nobody asked for.
 */
const expandedId = ref<string | null>(null);
const viewMode = ref<'diff' | 'content'>('diff');

const viewOptions = computed(() => [
  { value: 'diff' as const, label: t('knowledge.history.view.diff') },
  { value: 'content' as const, label: t('knowledge.history.view.content') },
]);

function toggleExpanded(revisionId: string): void {
  expandedId.value = expandedId.value === revisionId ? null : revisionId;
  viewMode.value = 'diff';
}

/** A version change closes whatever was open — the panel below it no longer describes it. */
watch(revisions, () => {
  expandedId.value = null;
});

</script>

<template>
  <Drawer v-model:open="open" side="right" size="md" :aria-label="t('knowledge.history.title')">
    <template #title>{{ t('knowledge.history.title') }}</template>
    <template #description>{{ entry?.title }}</template>

    <Timeline
      v-if="store.revisionsLoading"
      loading
      :loading-count="4"
      :aria-label="t('knowledge.history.feedLabel')"
    />

    <EmptyState
      v-else-if="store.revisionsError"
      variant="error"
      size="sm"
      :title="t('knowledge.common.loadError')"
    >
      <template #action>
        <Button
          variant="outline"
          size="sm"
          leading-icon="rotate-ccw"
          @click="entry && store.fetchRevisions(entry.id)"
        >
          {{ t('knowledge.common.retry') }}
        </Button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="revisions.length === 0"
      size="sm"
      icon="clock"
      :title="t('knowledge.history.empty')"
    />

    <Timeline v-else :aria-label="t('knowledge.history.feedLabel')">
      <TimelineItem
        v-for="(revision, index) in revisions"
        :key="revision.id"
        :title="t('knowledge.history.version', '', { number: versionNumber(index) })"
        :time="formatDate(revision.created_at)"
        :datetime="revision.created_at ?? undefined"
        :last="index === revisions.length - 1"
        :highlighted="isCurrent(index)"
      >
        <template #node>
          <CreatorBadge :creator="revision.author ?? null" size="sm" glyph-only />
        </template>

        <template #afterTitle>
          <Badge v-if="isCurrent(index)" variant="primary" tone="subtle" size="sm">
            {{ t('knowledge.history.current') }}
          </Badge>
        </template>

        <template #actions>
          <!-- What did this version change? One click, and it answers against the CURRENT text —
               which is the comparison an audit actually needs. -->
          <Button
            variant="ghost"
            size="xs"
            :leading-icon="expandedId === revision.id ? 'chevron-up' : 'chevron-down'"
            :aria-expanded="expandedId === revision.id"
            :aria-label="`${t('knowledge.history.compare')}: ${t('knowledge.history.version', '', { number: versionNumber(index) })}`"
            @click="toggleExpanded(revision.id)"
          >
            {{ t('knowledge.history.compare') }}
          </Button>

        </template>

        <Text v-if="revision.change_note" variant="caption" tone="muted" :clamp="2">
          {{ revision.change_note }}
        </Text>

        <!-- The comparison, only for the expanded version (see `expandedId`). -->
        <div v-if="expandedId === revision.id" class="mt-next-2 flex flex-col gap-next-2">
          <SegmentedControl
            v-model="viewMode"
            :options="viewOptions"
            size="sm"
            :aria-label="t('knowledge.history.view.label')"
          />

          <TextDiffView
            v-if="viewMode === 'diff'"
            :old="revision.content ?? ''"
            :current="entry?.content ?? ''"
            max-height="24rem"
            :empty-label="t('knowledge.history.sameAsCurrent')"
          />

          <!-- The version's own text, verbatim. `whitespace-pre-wrap` because a stored entry is
               markdown source here, not rendered output — the diff beside it shows the same
               characters, and switching between the two must not silently reformat them. -->
          <div
            v-else
            class="max-h-[24rem] overflow-auto rounded-next-lg border border-next-border bg-next-card p-next-3"
          >
            <pre class="whitespace-pre-wrap break-words font-mono text-next-xs text-next-fg">{{
              revision.content
            }}</pre>
          </div>
        </div>
      </TimelineItem>
    </Timeline>
  </Drawer>
</template>
