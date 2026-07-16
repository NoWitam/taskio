<script setup lang="ts">
// BotActionTimeline — the bot's action-history feed (next, Batch 2).
//
// Reads cursor-paginated actions from the botActions store
// (`GET /bots/{id}/actions?cursor=&type=`) and renders them through the shared
// Timeline. Each entry = a localized action-type label + icon, a link to the
// touched task (when `task_id` is set), the timestamp, and the error/status when
// an execution FAILED. An optional `type` Select filters the feed server-side.
//
// All four states: loading skeletons (Timeline), error + retry, empty (Timeline),
// success + load-more (infinite-scroll sentinel + a failed-append retry).
//
// No legacy imports; namespaced tokens; i18n + a11y throughout.
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Timeline, { type TimelineEntry } from '../../ui/patterns/Timeline.vue';
import TimelineItem from '../../ui/patterns/TimelineItem.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import { useBotActionsStore } from '../../app/stores/botActions';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';
import { botActionMeta, botActionEntryText, BOT_ACTION_TYPES } from './botActionMeta';
import type { BotActionType } from './types';

const props = defineProps<{ botId: string }>();

const { t, currentLocale } = useI18n();
const router = useRouter();
const store = useBotActionsStore();

const actions = computed(() => store.botActions);
const loading = computed(() => store.botLoading);
const error = computed(() => store.botError);
const hasMore = computed(() => store.botHasMore);

// Optional server-side `type` filter (null = all types).
const typeFilter = ref<BotActionType | null>(null);
const typeOptions = computed<SelectOption[]>(() => [
  ...BOT_ACTION_TYPES.map((type) => ({
    value: type,
    label: t(botActionMeta(type).i18nKey, type),
    icon: botActionMeta(type).icon,
  })),
]);

function load(): void {
  void store.fetchBotActions(props.botId, typeFilter.value, { reset: true });
}

onMounted(load);
watch(() => props.botId, () => load());
// Re-fetch from the server when the type filter changes (it is a server param).
watch(typeFilter, () => load());

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(currentLocale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(d);
}

interface ActionEntry extends TimelineEntry {
  taskId: string | null;
}

// Map each bot action → a Timeline entry (with the task link carried alongside).
// `botActionEntryText` builds the title (+ run/trigger meta), the description (a
// question's text, a failure's error, or a tool's payload), and an optional
// per-tool icon override — all tolerating a missing payload.
const entries = computed<ActionEntry[]>(() =>
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
      taskId: a.task_id,
    } satisfies ActionEntry;
  }),
);

function openTask(taskId: string | null): void {
  if (!taskId) return;
  // Deep-link the task detail Drawer via the Tasks route query.
  void router.push({ name: 'next.tasks', query: { task: taskId } });
}

const scrollRef = ref<HTMLElement | null>(null);
const { sentinelRef } = useInfiniteScroll({
  root: scrollRef,
  onLoadMore: () => void store.loadMoreBotActions(props.botId),
  canLoadMore: () =>
    store.botHasMore &&
    !store.botLoading &&
    !store.botLoadingMore &&
    !store.botLoadMoreErrored &&
    !store.botError,
});
</script>

<template>
  <div class="flex min-h-0 flex-col gap-next-3">
    <!-- Type filter (server-side). -->
    <div class="flex items-center justify-end">
      <div class="w-full next-sm:w-64">
        <Select
          v-model="typeFilter"
          :options="typeOptions"
          size="sm"
          leading-icon="sparkles"
          :placeholder="t('bots.actions.filterAll')"
          :aria-label="t('bots.actions.filterLabel')"
        />
      </div>
    </div>

    <div ref="scrollRef" class="min-h-0 flex-1 overflow-y-auto">
      <!-- Error (first page) + retry. -->
      <Alert v-if="error && !actions.length" variant="danger" size="sm">
        <div class="flex items-center justify-between gap-next-2">
          <span>{{ t('bots.actions.loadError') }}</span>
          <Button size="sm" variant="outline" leading-icon="rotate-ccw" @click="load">
            {{ t('bots.errors.retry') }}
          </Button>
        </div>
      </Alert>

      <template v-else>
        <!-- Loading / empty handled by Timeline; success composes linked items. -->
        <Timeline
          v-if="loading || !entries.length"
          :loading="loading"
          :loading-count="4"
          :items="loading ? undefined : []"
          :aria-label="t('bots.actions.title')"
          :empty-title="t('bots.actions.empty')"
          :empty-description="t('bots.actions.emptyDescription')"
        />
        <Timeline v-else :aria-label="t('bots.actions.title')">
          <TimelineItem
            v-for="(entry, i) in entries"
            :key="entry.id ?? i"
            :title="entry.title"
            :icon="entry.icon"
            :tone="entry.tone"
            :time="entry.time"
            :datetime="entry.datetime"
            :description="entry.description"
            :clamp-lines="3"
            :last="i === entries.length - 1"
          >
            <template v-if="entry.taskId" #actions>
              <Button
                size="icon-xs"
                variant="ghost"
                :aria-label="t('bots.actions.openTask')"
                @click="openTask(entry.taskId)"
              >
                <Icon name="external-link" />
              </Button>
            </template>
          </TimelineItem>
        </Timeline>

        <!-- Load-more sentinel + failed-append retry. -->
        <div
          v-if="hasMore && !loading && !store.botLoadMoreErrored"
          ref="sentinelRef"
          class="h-px w-full"
          aria-hidden="true"
        />
        <div
          v-if="store.botLoadingMore"
          class="flex justify-center py-next-2 text-next-muted-foreground"
        >
          <Icon name="loader" class="animate-spin" />
        </div>
        <div v-else-if="store.botLoadMoreErrored" class="flex justify-center py-next-2">
          <Button
            size="sm"
            variant="ghost"
            leading-icon="rotate-ccw"
            @click="store.retryLoadMoreBotActions(props.botId)"
          >
            {{ t('bots.errors.retry') }}
          </Button>
        </div>
      </template>
    </div>
  </div>
</template>
