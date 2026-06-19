<script setup lang="ts">
// TaskList — the per-status list of TaskCards for the "next" Tasks BROWSE
// experience, shared by both the List view (one status, page-scrolled) and each
// Board column (scrollable column body).
//
// Owns: the loading / error / empty / success states for ONE status, plus the
// infinite-scroll sentinel that calls the store's `loadMore`. The store holds the
// data; the parent page owns the filters and triggers the initial fetch.
//
// Skeleton rule: while a cursor page loads, several card-shaped skeletons are
// shown (never a spinner + "Loading"). Initial load fills the list with
// skeletons; "load more" appends a few skeleton cards after the real ones.
import { computed, ref } from 'vue';
import TaskCard from './TaskCard.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Button from '../../ui/primitives/Button.vue';
import { useTasksStore } from '../../app/stores/tasks';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';
import type { TaskFilters, TaskListItem, TaskStatus } from './types';

const props = withDefaults(
  defineProps<{
    status: TaskStatus;
    filters: TaskFilters;
    /** Are any filters active (drives the "no results" vs "empty" copy). */
    hasActiveFilters?: boolean;
    /** Optional scroll container for the IntersectionObserver root (board). */
    scrollRoot?: HTMLElement | null;
    /** Skeleton count to render on initial load. */
    skeletonCount?: number;
    /** Compact empty state (board columns). */
    compactEmpty?: boolean;
  }>(),
  {
    hasActiveFilters: false,
    scrollRoot: null,
    skeletonCount: 4,
    compactEmpty: false,
  },
);

const emit = defineEmits<{ (e: 'select', task: TaskListItem): void }>();

const { t } = useI18n();
const store = useTasksStore();

const items = computed(() => store.itemsFor(props.status));
const loading = computed(() => store.isLoading(props.status));
const errored = computed(() => store.hasError(props.status));
const hasMore = computed(() => store.hasMoreFor(props.status));

// Initial load = loading with nothing yet. Load-more = loading with items.
const initialLoading = computed(() => loading.value && items.value.length === 0);
const loadingMore = computed(() => loading.value && items.value.length > 0);
const isEmpty = computed(
  () => !loading.value && !errored.value && items.value.length === 0,
);

const rootRef = computed(() => props.scrollRoot);

const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => store.loadMore(props.status, props.filters),
  canLoadMore: () => hasMore.value && !loading.value && !errored.value,
  root: rootRef,
});

function retry(): void {
  store.fetchByStatus(props.status, props.filters, { reset: true });
}

const skeletonKeys = computed(() =>
  Array.from({ length: props.skeletonCount }, (_, i) => i),
);
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- Error (initial load failed) with retry. -->
    <EmptyState
      v-if="errored && items.length === 0"
      variant="error"
      :size="compactEmpty ? 'sm' : 'md'"
      :title="t('tasks.error.title', 'Couldn’t load tasks')"
      :description="t('tasks.error.description')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="loader" @click="retry">
          {{ t('tasks.error.retry', 'Retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Initial loading: several card-shaped skeletons. -->
    <template v-else-if="initialLoading">
      <TaskCard v-for="n in skeletonKeys" :key="`sk-${n}`" loading />
    </template>

    <!-- Empty: filtered "no results" vs first-run "nothing here". -->
    <EmptyState
      v-else-if="isEmpty"
      :variant="hasActiveFilters ? 'search' : 'default'"
      :size="compactEmpty ? 'sm' : 'md'"
      :title="
        hasActiveFilters
          ? t('tasks.empty.searchTitle', 'No tasks match your filters')
          : compactEmpty
            ? t('tasks.empty.columnTitle', 'No tasks')
            : t('tasks.empty.title', 'No tasks here')
      "
      :description="
        compactEmpty
          ? undefined
          : hasActiveFilters
            ? t('tasks.empty.searchDescription')
            : t('tasks.empty.description')
      "
    />

    <!-- Success: the cards + (when appending) trailing skeletons + sentinel. -->
    <template v-else>
      <TaskCard
        v-for="task in items"
        :key="task.id"
        :task="task"
        :status="status"
        @select="emit('select', $event)"
      />

      <template v-if="loadingMore">
        <TaskCard v-for="n in 2" :key="`more-${n}`" loading />
      </template>

      <!-- Inline "load more" error with retry (keeps the loaded list visible). -->
      <div
        v-if="errored && items.length > 0"
        class="flex items-center justify-between gap-next-2 rounded-next-md border border-next-border bg-next-card px-next-3 py-next-2 text-next-xs text-next-danger"
        role="alert"
      >
        <span>{{ t('tasks.error.fetch', 'Failed to load tasks.') }}</span>
        <Button variant="ghost" size="xs" @click="store.loadMore(status, filters)">
          {{ t('tasks.error.retry', 'Retry') }}
        </Button>
      </div>

      <!-- Infinite-scroll sentinel (only while more pages remain). -->
      <div
        v-if="hasMore && !errored"
        ref="sentinelRef"
        aria-hidden="true"
        class="h-px w-full"
      />
    </template>
  </div>
</template>
