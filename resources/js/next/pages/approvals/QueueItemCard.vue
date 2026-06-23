<script setup lang="ts">
// QueueItemCard — one row in the Approvals → Queue list (next): a pending item
// waiting for MY decision. Built on EntityCard, mirroring PipelineCard's geometry.
//
// Shows the approvable ENTITY (its type label + name), the pipeline + current
// stage it is sitting at, and the created date — plus a pending StatusBadge
// (icon + text, never color-only). The card click opens the review drawer; the
// page PREFETCHES the process + run history first (no loading flash).
//
// The entity may be NULL (the underlying model doesn't implement Approvable) —
// we render a neutral placeholder label rather than breaking.
//
// NOTE: entity / stage / pipeline icons are legacy IconEnum strings (NOT `next`
// IconName values), so the leading bubble uses a stable `next` icon instead of
// trying to map them. All strings are localized.
import { computed } from 'vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import StatusBadge, { type StatusMap } from '../../ui/data/StatusBadge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Spinner from '../../ui/primitives/Spinner.vue';
import { useI18n } from '../../app/i18n';
import { approvalStatusMap } from './approvalStatus';
import { entityDescriptionToText } from './entityDescription';
import type { ApprovalQueueItem } from './queue-types';

const props = defineProps<{
  item: ApprovalQueueItem;
  /** This card is prefetching its process before opening the review drawer. */
  opening?: boolean;
}>();

const emit = defineEmits<{
  (e: 'open', item: ApprovalQueueItem): void;
}>();

const { t } = useI18n();

const statusMap = computed<StatusMap>(() => approvalStatusMap(t));

// The entity name (or a neutral placeholder when the entity is unavailable).
const entityName = computed(() => props.item.entity?.name ?? t('approvals.queue.card.unknownEntity'));
const typeLabel = computed(() => props.item.entity?.type_label ?? t('approvals.queue.card.unknownType'));

// The entity description as readable text (the backend may send a ProseMirror doc
// object); fall back to the type label when there's nothing meaningful to show.
const subtitle = computed(() => entityDescriptionToText(props.item.entity?.description) || typeLabel.value);

const meta = computed<EntityMetaItem[]>(() => {
  const out: EntityMetaItem[] = [];
  if (props.item.pipeline.name) {
    out.push({ icon: 'git-branch', label: t('approvals.queue.card.pipeline'), value: props.item.pipeline.name });
  }
  if (props.item.stage.name) {
    out.push({ icon: 'clock', label: t('approvals.queue.card.stage'), value: props.item.stage.name });
  }
  if (props.item.process.created_at) {
    out.push({ icon: 'calendar', label: formatDate(props.item.process.created_at) });
  }
  return out;
});

// ISO timestamp → `dd.mm.yyyy` (locale-agnostic; the surrounding labels are i18n).
function formatDate(iso: string): string {
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

function onOpen(): void {
  emit('open', props.item);
}
</script>

<template>
  <EntityCard
    :title="entityName"
    :subtitle="subtitle"
    :disabled="opening"
    :action-label="t('approvals.queue.card.review', '', { name: entityName })"
    @click="onOpen"
  >
    <template #leading>
      <span
        class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
        aria-hidden="true"
      >
        <Spinner v-if="opening" size="sm" tone="muted" decorative />
        <Icon v-else name="inbox" class="text-next-lg" />
      </span>
    </template>

    <template #status>
      <StatusBadge
        :status="item.process.status"
        :status-map="statusMap"
        size="sm"
      />
    </template>

    <template #meta>
      <span
        v-for="(entry, i) in meta"
        :key="i"
        class="inline-flex min-w-0 items-center gap-next-1"
      >
        <Icon v-if="entry.icon" :name="entry.icon" class="shrink-0 text-next-sm" />
        <span class="truncate">{{ entry.label }}</span>
        <span v-if="entry.value !== undefined" class="truncate font-next-medium text-next-fg">
          {{ entry.value }}
        </span>
      </span>
    </template>
  </EntityCard>
</template>
