<script setup lang="ts">
// TaskBotActions — a compact timeline of THIS task's bot activity (next, Batch 2).
//
// Reads cursor-paginated actions from the botActions store
// (`GET /tasks/{id}/bot-actions`) and renders them through the shared Timeline:
// each entry = a localized action-type label + icon, an optional link to the task
// it touched (omitted here — it IS this task), the timestamp, and the error/status
// when an execution FAILED. Covers all four states (loading skeletons, error +
// retry, empty, success) + load-more via the infinite-scroll sentinel.
//
// Only mounted by the drawer when the task has/had a bot assignee or any actions —
// it never fabricates data; an EmptyState shows when the feed is empty.
//
// All design-system components; no legacy imports; namespaced tokens; i18n + a11y.
import { computed, onMounted, ref, watch } from 'vue';
import Timeline, { type TimelineEntry } from '../../ui/patterns/Timeline.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { useBotActionsStore } from '../../app/stores/botActions';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';
import { botActionMeta, botActionEntryText } from '../bots/botActionMeta';

const props = defineProps<{ taskId: string | number }>();

const { t, currentLocale } = useI18n();
const store = useBotActionsStore();

const actions = computed(() => store.taskActions);
const loading = computed(() => store.taskLoading);
const error = computed(() => store.taskError);
const hasMore = computed(() => store.taskHasMore);

function load(): void {
  void store.fetchTaskBotActions(props.taskId, { reset: true });
}

onMounted(load);
watch(
  () => props.taskId,
  () => load(),
);

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(currentLocale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(d);
}

// Map each bot action → a Timeline entry. `botActionEntryText` builds the title
// (+ run/trigger meta), the description (a question's text, a failure's error, or
// a tool's payload), and an optional per-tool icon override — all tolerating a
// missing payload (Batch 4 + Batch 5).
const entries = computed<TimelineEntry[]>(() =>
  actions.value.map((a) => {
    const meta = botActionMeta(a.type);
    const { title, description, icon } = botActionEntryText(a, t);
    return {
      id: a.id,
      title,
      icon: icon ?? meta.icon,
      tone: meta.tone,
      time: formatDateTime(a.created_at),
      datetime: a.created_at ?? undefined,
      description,
    } satisfies TimelineEntry;
  }),
);

const scrollRef = ref<HTMLElement | null>(null);
const { sentinelRef } = useInfiniteScroll({
  root: scrollRef,
  onLoadMore: () => void store.loadMoreTaskBotActions(props.taskId),
  canLoadMore: () =>
    store.taskHasMore &&
    !store.taskLoading &&
    !store.taskLoadingMore &&
    !store.taskLoadMoreErrored &&
    !store.taskError,
});
</script>

<template>
  <div ref="scrollRef" class="min-h-0 flex-1 overflow-y-auto">
    <!-- Error (first page). -->
    <Alert v-if="error && !actions.length" variant="danger" size="sm">
      <div class="flex items-center justify-between gap-next-2">
        <span>{{ t('bots.actions.loadError') }}</span>
        <Button size="sm" variant="outline" leading-icon="rotate-ccw" @click="load">
          {{ t('bots.errors.retry') }}
        </Button>
      </div>
    </Alert>

    <template v-else>
      <Timeline
        :items="entries"
        :loading="loading"
        :loading-count="3"
        :clamp-lines="3"
        compact
        :aria-label="t('tasks.detail.botActivity.title')"
        :empty-title="t('tasks.detail.botActivity.empty')"
        :empty-description="t('tasks.detail.botActivity.emptyDescription')"
      />

      <!-- Load-more sentinel + a failed-append retry. -->
      <div
        v-if="hasMore && !loading && !store.taskLoadMoreErrored"
        ref="sentinelRef"
        class="h-px w-full"
        aria-hidden="true"
      />
      <div
        v-if="store.taskLoadingMore"
        class="flex justify-center py-next-2 text-next-muted-foreground"
      >
        <Icon name="loader" class="animate-spin" />
      </div>
      <div v-else-if="store.taskLoadMoreErrored" class="flex justify-center py-next-2">
        <Button
          size="sm"
          variant="ghost"
          leading-icon="rotate-ccw"
          @click="store.retryLoadMoreTaskBotActions(props.taskId)"
        >
          {{ t('bots.errors.retry') }}
        </Button>
      </div>
    </template>
  </div>
</template>
