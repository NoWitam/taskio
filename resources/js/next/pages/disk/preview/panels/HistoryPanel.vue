<script setup lang="ts">
// HistoryPanel — the "Historia" tab: the item's changelog rendered as a Timeline with infinite
// scroll (the drawer pattern), for a FILE or FOLDER via the store's module-aware fetch.
// Event titles are LOCALIZED here: the backend sends the raw description key
// (`changelog.updated`, `changelog.contentReplaced`, …) and the new top-level `changelog.*`
// i18n section maps it — which also fixes the raw-key rendering the drawers shipped.
import { computed, ref, watch } from 'vue';
import Timeline, { type TimelineEntry } from '../../../../ui/patterns/Timeline.vue';
import { useDiskStore } from '../../../../app/stores/disk';
import { useInfiniteScroll } from '../../../../app/composables/useInfiniteScroll';
import { useI18n } from '../../../../app/i18n';

const props = defineProps<{
  itemId: string;
  module: 'file' | 'folder';
}>();

const { t, locale } = useI18n();
const store = useDiskStore();

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(d);
}

const historyEntries = computed<TimelineEntry[]>(() =>
  store.changelog.map((entry) => ({
    id: entry.id,
    // event_description is a raw key ('changelog.updated'); fall back to the key itself.
    title: t(entry.event_description, entry.event_description),
    description: entry.causer?.name ?? t('disk.drawer.system', 'System'),
    time: formatDateTime(entry.created_at),
    datetime: entry.created_at,
    icon: 'clock',
    tone: 'neutral',
  })),
);

const historyScroll = ref<HTMLElement | null>(null);
const { sentinelRef: historySentinel } = useInfiniteScroll({
  root: historyScroll,
  onLoadMore: () => void store.fetchChangelog(props.itemId, { reset: false, module: props.module }),
  canLoadMore: () =>
    store.changelogHasMore && !store.changelogLoading && !store.changelogLoadingMore && !store.changelogError,
});

watch(
  () => [props.itemId, props.module] as const,
  () => void store.fetchChangelog(props.itemId, { module: props.module }),
  { immediate: true },
);

/** The shell calls this after a save so the fresh audit entry shows up. */
function refresh(): void {
  void store.fetchChangelog(props.itemId, { module: props.module });
}

defineExpose({ refresh });
</script>

<template>
  <div ref="historyScroll" class="h-full min-h-0 overflow-y-auto pr-next-1">
    <p v-if="store.changelogError" class="text-next-sm text-next-danger" role="alert">
      {{ t('disk.drawer.historyError', 'Could not load the history.') }}
    </p>
    <template v-else>
      <Timeline
        :items="historyEntries"
        :loading="store.changelogLoading"
        compact
        :aria-label="t('disk.drawer.history', 'History')"
        :empty-title="t('disk.drawer.historyEmpty', 'No history yet')"
      />
      <div ref="historySentinel" class="h-px w-full" aria-hidden="true" />
    </template>
  </div>
</template>
