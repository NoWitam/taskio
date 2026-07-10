<script setup lang="ts">
// WorkflowRunsView — the Runs section of a workflow detail (next, §5). A
// detail-nested list (NOT a top-level module list), so it deliberately carries
// NO FilterBar / Saved Views (the §5.1 exemption, mirroring the Approvals Queue +
// Bot inbox nested lists).
//
// Filters are two SegmentedControls — state + origin (§5.2) — driving `?state=` /
// `?origin=` on GET /workflows/{id}/runs, plus a manual Refresh Button (the honest
// MVP polling affordance; no invented websockets). The list is cursor-paginated
// (15/page) through the workflowRuns store; all four states are covered
// (multi-skeleton rows / error + retry / empty / success) with load-more via
// useInfiniteScroll + a retryable append (module convention).
//
// A run row opens the run-detail DRAWER, hosted HERE and driven by the
// `?run_detail=<runId>` query key on the detail route (§5.4), so the Runs list
// stays mounted behind it.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import WorkflowRunRow from './WorkflowRunRow.vue';
import WorkflowRunTimeline from './WorkflowRunTimeline.vue';
import { runStateIcon, originIcon } from './workflowMeta';
import { useWorkflowRunsStore } from '../../app/stores/workflowRuns';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';
import {
  RUN_STATES,
  RUN_ORIGINS,
  type WorkflowRun,
  type WorkflowRunFilters,
  type WorkflowRunOrigin,
  type WorkflowRunState,
} from './types';

const props = defineProps<{ workflowId: string }>();

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useWorkflowRunsStore();

// --- Filter state (URL-synced) --------------------------------------------
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));

/** `''` = the "All" pseudo-value the SegmentedControl uses for no filter. */
const stateFilter = computed<WorkflowRunState | '' >({
  get: () => (str(route.query.state) as WorkflowRunState) || '',
  set: (value) => setQuery('state', value),
});
const originFilter = computed<WorkflowRunOrigin | ''>({
  get: () => (str(route.query.origin) as WorkflowRunOrigin) || '',
  set: (value) => setQuery('origin', value),
});

function setQuery(key: 'state' | 'origin', value: string): void {
  const query = { ...route.query };
  if (value) query[key] = value;
  else delete query[key];
  void router.replace({ query });
}

const filters = computed<WorkflowRunFilters>(() => {
  const f: WorkflowRunFilters = {};
  if (stateFilter.value) f.state = stateFilter.value;
  if (originFilter.value) f.origin = originFilter.value;
  return f;
});

// --- SegmentedControl options (All + each enum, icon + label) --------------
const stateOptions = computed<SegmentOption<string>[]>(() => [
  { value: '', label: t('workflows.runs.filters.allStates') },
  ...RUN_STATES.map((s) => ({
    value: s,
    label: t(`workflows.runs.state.${s}`),
    icon: runStateIcon(s),
  })),
]);
const originOptions = computed<SegmentOption<string>[]>(() => [
  { value: '', label: t('workflows.runs.filters.allOrigins') },
  ...RUN_ORIGINS.map((o) => ({
    value: o,
    label: t(`workflows.runs.origin.${o}`),
    icon: originIcon(o),
  })),
]);

// --- Fetch orchestration ---------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const hasActiveFilters = computed(() => !!stateFilter.value || !!originFilter.value);
const isEmpty = computed(
  () =>
    !store.loading &&
    !store.loadingMore &&
    !store.errored &&
    items.value.length === 0,
);

function load(): void {
  void store.fetchRuns(props.workflowId, filters.value, { reset: true });
}

function refresh(): void {
  load();
}

onMounted(load);
watch(
  () => props.workflowId,
  () => {
    store.resetAll();
    load();
  },
);
// Re-fetch when either filter changes.
watch(filters, () => load(), { deep: true });
onUnmounted(() => store.resetAll());

// --- Infinite scroll -------------------------------------------------------
const scrollRef = ref<HTMLElement | null>(null);
const { sentinelRef } = useInfiniteScroll({
  root: scrollRef,
  onLoadMore: () => void store.loadMore(props.workflowId, filters.value),
  canLoadMore: () =>
    store.hasMore &&
    !store.loading &&
    !store.loadingMore &&
    !store.loadMoreErrored &&
    !store.errored,
});

// --- Run detail drawer (`?run_detail=<runId>`, §5.4) -----------------------
const runDetailId = computed<string | null>(() => str(route.query.run_detail) || null);
const detailOpen = computed<boolean>({
  get: () => runDetailId.value !== null,
  set: (open) => {
    if (!open) {
      const query = { ...route.query };
      delete query.run_detail;
      void router.replace({ query });
    }
  },
});

function openRun(run: WorkflowRun): void {
  void router.push({ query: { ...route.query, run_detail: run.id } });
}
</script>

<template>
  <Surface bg="card" border elevation="sm" radius="lg" class="flex min-h-0 flex-col gap-next-4 p-next-6">
    <!-- Header: title + manual refresh. -->
    <header class="flex items-center justify-between gap-next-3">
      <div class="flex items-center gap-next-2">
        <span
          class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-muted text-next-muted-foreground"
          aria-hidden="true"
        >
          <Icon name="clock" />
        </span>
        <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.detail.tabRuns') }}</h2>
      </div>
      <Button
        variant="outline"
        size="sm"
        leading-icon="rotate-ccw"
        :loading="store.loading"
        @click="refresh"
      >
        {{ t('workflows.runs.refresh') }}
      </Button>
    </header>

    <!-- Filters: state + origin segmented controls (no FilterBar — §5.1). -->
    <div class="flex flex-col gap-next-3">
      <div class="flex flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.stateLabel') }}</span>
        <div class="overflow-x-auto">
          <SegmentedControl
            v-model="stateFilter"
            :options="stateOptions"
            size="sm"
            :aria-label="t('workflows.runs.filters.stateLabel')"
          />
        </div>
      </div>
      <div class="flex flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.originLabel') }}</span>
        <div class="overflow-x-auto">
          <SegmentedControl
            v-model="originFilter"
            :options="originOptions"
            size="sm"
            :aria-label="t('workflows.runs.filters.originLabel')"
          />
        </div>
      </div>
    </div>

    <!-- Scroll region: the runs list for the active filters. -->
    <div ref="scrollRef" class="min-h-0 flex-1 overflow-y-auto">
      <!-- Error (first page) + retry. -->
      <Alert v-if="store.errored && !items.length" variant="danger" size="sm">
        <div class="flex items-center justify-between gap-next-2">
          <span>{{ t('workflows.runs.loadError') }}</span>
          <Button size="sm" variant="outline" leading-icon="rotate-ccw" @click="load">
            {{ t('workflows.errors.retry') }}
          </Button>
        </div>
      </Alert>

      <!-- Initial loading: several row-shaped skeletons (state badge + lines). -->
      <ul v-else-if="initialLoading" class="flex flex-col gap-next-2" aria-hidden="true">
        <li
          v-for="n in 5"
          :key="n"
          class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
        >
          <div class="flex items-center gap-next-2">
            <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
            <Skeleton variant="rect" width="4rem" height="1.25rem" radius="full" />
          </div>
          <Skeleton variant="text" width="55%" />
        </li>
      </ul>

      <!-- Empty: per-filter message when a filter is active, else first-run. -->
      <EmptyState
        v-else-if="isEmpty"
        size="sm"
        :variant="hasActiveFilters ? 'search' : 'default'"
        :icon="hasActiveFilters ? 'search' : 'clock'"
        :title="hasActiveFilters ? t('workflows.runs.empty.filteredTitle') : t('workflows.runs.empty.title')"
        :description="hasActiveFilters ? t('workflows.runs.empty.filteredDescription') : t('workflows.runs.empty.description')"
      />

      <!-- Success: the run rows + load-more sentinel + retryable append. -->
      <template v-else>
        <ul class="flex flex-col gap-next-2" :aria-label="t('workflows.runs.listLabel')">
          <li v-for="run in items" :key="run.id">
            <WorkflowRunRow :run="run" @open="openRun" />
          </li>
        </ul>

        <!-- Load-more sentinel (paused while a failed append awaits retry). -->
        <div
          v-if="store.hasMore && !store.loadMoreErrored"
          ref="sentinelRef"
          class="h-px w-full"
          aria-hidden="true"
        />
        <div
          v-if="store.loadingMore"
          class="flex justify-center py-next-2 text-next-muted-foreground"
        >
          <Icon name="loader" class="animate-spin" aria-hidden="true" />
        </div>
        <div v-else-if="store.loadMoreErrored" class="flex justify-center py-next-2">
          <Button size="sm" variant="ghost" leading-icon="rotate-ccw" @click="store.retryLoadMore(props.workflowId, filters)">
            {{ t('workflows.errors.retry') }}
          </Button>
        </div>
      </template>
    </div>
  </Surface>

  <!-- Run detail drawer (`?run_detail=<runId>`, §5.4). The list stays behind it. -->
  <Drawer
    v-model:open="detailOpen"
    side="right"
    size="lg"
    :show-close="false"
    :aria-label="t('workflows.runs.detail.title')"
  >
    <WorkflowRunTimeline
      v-if="runDetailId"
      :key="runDetailId"
      :workflow-id="workflowId"
      :run-id="runDetailId"
      @close="detailOpen = false"
    />
  </Drawer>
</template>
