<script setup lang="ts">
// BotInbox — a bot's OPERATIONAL view of its tasks, bucketed by execution state
// (next, Batch 7). Replaces the plain BotTasksList in the detail's "Inbox" tab.
//
// Layout:
//   • Header stat — `runs_this_month` as a subtle usage/cost-proxy chip.
//   • Bucket bar — one selectable chip per execution bucket (fixed order) + "All",
//     each with icon + label + count + a per-bucket tone; the active one is marked
//     via aria-pressed (NOT color-only). Zero-count buckets still show (greyed) so
//     the IA is visible. Selecting a bucket sets `?state=` and refetches.
//   • Task list — cursor-paginated rows for the selected bucket (or all). Each row:
//     title + priority + assignee + deadline + an inbox_state badge; clicking opens
//     the task detail (`?task=<id>` on the tasks route). A `failed` row shows Retry.
//
// All four states (loading skeletons, error + retry, per-bucket empty, success) +
// load-more via useInfiniteScroll and a retryable append. State lives in the
// botInbox store (request-token guarded). No legacy imports; namespaced tokens;
// i18n + a11y throughout.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import BotIdentity from './BotIdentity.vue';
import { useBotInboxStore } from '../../app/stores/botInbox';
import { useToast } from '../../app/composables/useToast';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';
import { botInboxMeta, ALL_BUCKET_META } from './botInboxMeta';
import { resolveAssignee } from '../tasks/assignee';
import { priorityMeta, type TaskListItem } from '../tasks/types';
import { BOT_INBOX_STATES, type BotInboxState, type BotInboxTask } from './types';

const props = defineProps<{ botId: string }>();

const { t } = useI18n();
const router = useRouter();
const store = useBotInboxStore();
const toast = useToast();

const items = computed(() => store.items);
const loading = computed(() => store.loading);
const error = computed(() => store.error);
const hasMore = computed(() => store.hasMore);
const activeState = computed(() => store.state);

// --- Bucket bar model -----------------------------------------------------
interface BucketChip {
  /** null = the "All" pseudo-bucket. */
  state: BotInboxState | null;
  label: string;
  icon: string;
  tone: string;
  count: number;
  active: boolean;
}

const allCount = computed(() =>
  BOT_INBOX_STATES.reduce((sum, s) => sum + (store.buckets[s] ?? 0), 0),
);

const bucketChips = computed<BucketChip[]>(() => {
  const chips: BucketChip[] = [
    {
      state: null,
      label: t(ALL_BUCKET_META.i18nKey),
      icon: ALL_BUCKET_META.icon,
      tone: ALL_BUCKET_META.tone,
      count: allCount.value,
      active: activeState.value === null,
    },
  ];
  // Fixed contract order.
  for (const s of BOT_INBOX_STATES) {
    const meta = botInboxMeta(s);
    chips.push({
      state: s,
      label: t(meta.i18nKey),
      icon: meta.icon,
      tone: meta.tone,
      count: store.buckets[s] ?? 0,
      active: activeState.value === s,
    });
  }
  return chips;
});

// A tone → chip class map (active vs idle; zero-count buckets read greyed).
function chipClass(chip: BucketChip): string {
  const base =
    'inline-flex shrink-0 items-center gap-next-1_5 rounded-next-full border px-next-3 py-next-1_5 text-next-xs font-next-medium transition-colors';
  if (chip.active) {
    return `${base} border-next-primary bg-next-primary-subtle text-next-primary-subtle-foreground`;
  }
  const dimmed = chip.count === 0 ? 'opacity-60' : '';
  return `${base} border-next-border bg-next-card text-next-fg hover:bg-next-accent ${dimmed}`;
}

// --- Per-row state badge --------------------------------------------------
function stateBadgeVariant(state: BotInboxState): 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  return botInboxMeta(state).tone;
}

// --- Row assignee ---------------------------------------------------------
function assigneeOf(task: TaskListItem) {
  return resolveAssignee(task);
}

// --- Load / refetch -------------------------------------------------------
function load(): void {
  void store.fetchInbox(props.botId, { reset: true });
}

onMounted(load);
watch(
  () => props.botId,
  () => {
    store.reset();
    load();
  },
);
onUnmounted(() => store.reset());

async function selectBucket(state: BotInboxState | null): Promise<void> {
  await store.setState(props.botId, state);
}

function openTask(id: string | number): void {
  void router.push({ name: 'next.tasks', query: { task: String(id) } });
}

// --- Retry (failed rows only) ---------------------------------------------
async function onRetry(task: BotInboxTask): Promise<void> {
  const id = String(task.id);
  const outcome = await store.retryTask(props.botId, id);
  if (outcome === 'retried') {
    toast.success(t('bots.inbox.retry.toastRetried'));
  } else if (outcome === 'stale') {
    toast.info(t('bots.inbox.retry.toastStale'));
  } else {
    toast.danger(t('bots.inbox.retry.toastError'));
  }
}

// --- Infinite scroll ------------------------------------------------------
const scrollRef = ref<HTMLElement | null>(null);
const { sentinelRef } = useInfiniteScroll({
  root: scrollRef,
  onLoadMore: () => void store.loadMore(props.botId),
  canLoadMore: () =>
    store.hasMore &&
    !store.loading &&
    !store.loadingMore &&
    !store.loadMoreErrored &&
    !store.error,
});
</script>

<template>
  <div class="flex min-h-0 flex-col gap-next-4">
    <!-- Header stat: runs this month (usage / cost proxy). -->
    <div class="flex items-center justify-end">
      <Badge variant="neutral" tone="subtle" size="sm" icon="calendar">
        {{ t('bots.inbox.runsThisMonth', '', { count: store.runsThisMonth }) }}
      </Badge>
    </div>

    <!-- Bucket bar: selectable chips (icon + label + count), fixed order + All. -->
    <div
      v-if="loading && !items.length"
      class="flex flex-wrap gap-next-2"
      role="status"
      :aria-label="t('common.loading')"
    >
      <Skeleton v-for="n in 8" :key="n" variant="rect" width="6rem" height="2rem" radius="full" />
    </div>
    <div
      v-else
      class="flex flex-wrap gap-next-2"
      role="group"
      :aria-label="t('bots.inbox.bucketBarLabel')"
    >
      <button
        v-for="chip in bucketChips"
        :key="chip.state ?? 'all'"
        type="button"
        :class="chipClass(chip)"
        :aria-pressed="chip.active"
        @click="selectBucket(chip.state)"
      >
        <Icon :name="(chip.icon as any)" class="shrink-0" aria-hidden="true" />
        <span>{{ chip.label }}</span>
        <span
          class="inline-flex min-w-[1.25rem] items-center justify-center rounded-next-full bg-next-fg/10 px-next-1 text-next-2xs font-next-semibold"
          aria-hidden="true"
        >
          {{ chip.count }}
        </span>
        <span class="sr-only">{{ t('bots.inbox.bucketCount', '', { count: chip.count }) }}</span>
      </button>
    </div>

    <!-- Scroll region: the task list for the active bucket (or all). -->
    <div ref="scrollRef" class="min-h-0 flex-1 overflow-y-auto">
      <!-- Error (first page) + retry. -->
      <Alert v-if="error && !items.length" variant="danger" size="sm">
        <div class="flex items-center justify-between gap-next-2">
          <span>{{ t('bots.inbox.loadError') }}</span>
          <Button size="sm" variant="outline" leading-icon="rotate-ccw" @click="load">
            {{ t('bots.errors.retry') }}
          </Button>
        </div>
      </Alert>

      <!-- Loading skeleton rows (mirror a compact inbox row). -->
      <ul v-else-if="loading" class="flex flex-col gap-next-2" aria-hidden="true">
        <li
          v-for="n in 4"
          :key="n"
          class="flex items-center gap-next-3 rounded-next-md border border-next-border p-next-3"
        >
          <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
          <Skeleton variant="text" width="45%" />
          <Skeleton variant="circle" diameter="1.5rem" />
        </li>
      </ul>

      <!-- Empty (per-bucket message). -->
      <EmptyState
        v-else-if="!items.length"
        size="sm"
        :icon="activeState ? (botInboxMeta(activeState).icon as any) : 'inbox'"
        :title="t('bots.inbox.empty.title')"
        :description="
          activeState
            ? t('bots.inbox.empty.bucketDescription', '', { bucket: t(botInboxMeta(activeState).i18nKey) })
            : t('bots.inbox.empty.allDescription')
        "
      />

      <!-- Success: bucketed, linkable rows. -->
      <template v-else>
        <ul class="flex flex-col gap-next-2" :aria-label="t('bots.inbox.listLabel')">
          <li v-for="task in items" :key="task.id">
            <Surface
              bg="card"
              border
              radius="md"
              class="flex w-full items-center gap-next-3 p-next-3"
            >
              <!-- Clicking the main area opens the task detail. -->
              <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-next-3 text-left"
                :aria-label="t('bots.inbox.openTask', '', { title: task.title })"
                @click="openTask(task.id)"
              >
                <!-- inbox_state badge (icon + label, not color-only). -->
                <Badge
                  :variant="stateBadgeVariant(task.inbox_state)"
                  tone="subtle"
                  size="sm"
                  :icon="(botInboxMeta(task.inbox_state).icon as any)"
                  class="shrink-0"
                >
                  {{ t(botInboxMeta(task.inbox_state).i18nKey) }}
                </Badge>

                <span class="min-w-0 flex-1 truncate text-next-sm font-next-medium text-next-fg">
                  {{ task.title }}
                </span>

                <!-- Priority chip. -->
                <Badge
                  :variant="priorityMeta(task.priority).tone"
                  tone="subtle"
                  size="sm"
                  :icon="priorityMeta(task.priority).icon"
                  class="hidden shrink-0 next-sm:inline-flex"
                >
                  {{ t(priorityMeta(task.priority).i18nKey) }}
                </Badge>

                <!-- Assignee: bot identity glyph for a bot, avatar for a user. -->
                <template v-if="assigneeOf(task)">
                  <BotIdentity
                    v-if="assigneeOf(task)!.isBot"
                    :name="assigneeOf(task)!.name"
                    size="xs"
                    glyph-only
                    class="shrink-0"
                  />
                  <Avatar
                    v-else
                    :name="assigneeOf(task)!.name ?? undefined"
                    :src="assigneeOf(task)!.avatar ?? undefined"
                    size="xs"
                    class="shrink-0"
                  />
                </template>

                <!-- Deadline. -->
                <span
                  v-if="task.deadline"
                  class="hidden shrink-0 text-next-xs next-sm:inline"
                  :class="task.is_overdue ? 'text-next-danger' : 'text-next-muted-foreground'"
                >
                  {{ task.deadline }}
                </span>
              </button>

              <!-- Retry (failed rows only). -->
              <Button
                v-if="task.inbox_state === 'failed'"
                size="sm"
                variant="outline"
                leading-icon="rotate-ccw"
                :loading="store.isRetrying(String(task.id))"
                :disabled="store.isRetrying(String(task.id))"
                class="shrink-0"
                @click="onRetry(task)"
              >
                {{ t('bots.inbox.retry.button') }}
              </Button>
              <Icon v-else name="chevron-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
            </Surface>
          </li>
        </ul>

        <!-- Load-more sentinel + failed-append retry. -->
        <div
          v-if="hasMore && !loading && !store.loadMoreErrored"
          ref="sentinelRef"
          class="h-px w-full"
          aria-hidden="true"
        />
        <div
          v-if="store.loadingMore"
          class="flex justify-center py-next-2 text-next-muted-foreground"
        >
          <Icon name="loader" class="animate-spin" />
        </div>
        <div v-else-if="store.loadMoreErrored" class="flex justify-center py-next-2">
          <Button size="sm" variant="ghost" leading-icon="rotate-ccw" @click="store.retryLoadMore(props.botId)">
            {{ t('bots.errors.retry') }}
          </Button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
