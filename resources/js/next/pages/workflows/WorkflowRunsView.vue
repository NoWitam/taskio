<script setup lang="ts">
// WorkflowRunsView — the Runs section of a workflow detail (next, §5). A
// detail-nested list (NOT a top-level module list), so it deliberately carries
// NO FilterBar / Saved Views (the §5.1 exemption, mirroring the Approvals Queue +
// Bot inbox nested lists).
//
// Filters (B4): two `SegmentedControl multiple` — state + source (origin) — plus one
// shared DateRangeFilter, driving `?state[]=` / `?origin[]=` / `date_from` / `date_to` /
// `date_preset` on GET /workflows/{id}/runs, plus a manual Refresh Button (the honest
// MVP polling affordance; no invented websockets). When the workflow's trigger is
// `form_submitted` the SOURCE filter drops the "schedule" option (a form workflow
// never has a schedule run). The list is cursor-paginated (15/page) through the
// workflowRuns store (per-workflow scope); all four states are covered (multi-skeleton
// rows / error + retry / empty / success) with load-more via useInfiniteScroll + a
// retryable append (module convention).
//
// A run row opens the run-detail DRAWER, hosted HERE and driven by the
// `?run_detail=<runId>` query key on the detail route (§5.4), so the Runs list
// stays mounted behind it.
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import DateRangeFilter, {
  type DateRangeFilterPreset,
  type DateRangeFilterValue,
} from '../../ui/forms/DateRangeFilter.vue';
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
  type WorkflowTriggerType,
} from './types';

const props = defineProps<{
  workflowId: string;
  /**
   * The workflow's trigger type (threaded down so the SOURCE filter can drop the
   * "schedule" option for a `form_submitted` workflow). Null until the detail loads.
   */
  triggerType?: WorkflowTriggerType | null;
}>();

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useWorkflowRunsStore();

const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
const toArr = (v: unknown): string[] =>
  Array.isArray(v) ? v.map(String) : v != null && v !== '' ? [String(v)] : [];

// --- Filter state (URL-synced local refs) ---------------------------------
const stateFilter = ref<string[]>([]);
const originFilter = ref<string[]>([]);
const dateRange = ref<DateRangeFilterValue>({ preset: '', from: null, to: null, hide_without_deadline: false });

// --- Segmented options (each enum, icon + label; no "All" — empty = all) ----
const stateOptions = computed<SegmentOption[]>(() =>
  RUN_STATES.map((s) => ({ value: s, label: t(`workflows.runs.state.${s}`), icon: runStateIcon(s) })),
);
// A form_submitted workflow never produces a schedule run → drop that option.
const originOptions = computed<SegmentOption[]>(() => {
  const origins = props.triggerType === 'form_submitted'
    ? RUN_ORIGINS.filter((o) => o !== 'schedule')
    : RUN_ORIGINS;
  return origins.map((o) => ({ value: o, label: t(`workflows.runs.origin.${o}`), icon: originIcon(o) }));
});
const datePresets = computed<DateRangeFilterPreset[]>(() =>
  (['today', 'this_week', 'last_week', 'this_month'] as const).map((p) => ({ id: p, label: t(`tasks.datePresets.${p}`) })),
);

// --- Aggregated filter object (mirrors the query params) -------------------
const filters = computed<WorkflowRunFilters>(() => {
  const f: WorkflowRunFilters = {};
  if (stateFilter.value.length) f.state = [...stateFilter.value];
  if (originFilter.value.length) f.origin = [...originFilter.value];
  if (dateRange.value.from) f.date_from = dateRange.value.from;
  if (dateRange.value.to) f.date_to = dateRange.value.to;
  if (dateRange.value.preset) f.date_preset = dateRange.value.preset;
  return f;
});

// --- Fetch orchestration ---------------------------------------------------
const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const hasActiveFilters = computed(
  () =>
    stateFilter.value.length > 0 ||
    originFilter.value.length > 0 ||
    !!dateRange.value.preset ||
    !!dateRange.value.from ||
    !!dateRange.value.to,
);
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

// --- URL sync (state[]/origin[]/date_*; preserve the ?run_detail overlay key) -
let hydrating = false;

function hydrateFromQuery(): void {
  hydrating = true;
  stateFilter.value = toArr(route.query.state);
  originFilter.value = toArr(route.query.origin);
  dateRange.value = {
    preset: str(route.query.date_preset),
    from: str(route.query.date_from) || null,
    to: str(route.query.date_to) || null,
    hide_without_deadline: false,
  };
  hydrating = false;
}

function syncQuery(): void {
  if (hydrating) return;
  const query: Record<string, string | string[]> = {};
  const rd = route.query.run_detail;
  if (rd != null && rd !== '') query.run_detail = str(rd);
  if (stateFilter.value.length) query.state = [...stateFilter.value];
  if (originFilter.value.length) query.origin = [...originFilter.value];
  if (dateRange.value.from) query.date_from = dateRange.value.from;
  if (dateRange.value.to) query.date_to = dateRange.value.to;
  if (dateRange.value.preset) query.date_preset = dateRange.value.preset;
  void router.replace({ query });
}

// `ready` gates the filters watch so the hydrate-induced change during mount doesn't
// double-fetch: the pending watch job flushes (skipped) inside the nextTick BEFORE we
// arm it, so only genuine user filter changes refetch + sync the URL afterwards.
let ready = false;
onMounted(async () => {
  hydrateFromQuery();
  load();
  await nextTick();
  ready = true;
});
watch(
  () => props.workflowId,
  () => {
    store.resetAll();
    load();
  },
);
// Re-fetch + mirror to the URL when any filter changes (after mount).
watch(
  filters,
  () => {
    if (!ready) return;
    syncQuery();
    load();
  },
  { deep: true },
);
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

/**
 * A FAILED run was retried (WorkflowRunTimeline `@retried`) → a NEW run on THIS workflow.
 * Refresh the list so it surfaces, and swap the drawer to the new run (`?run_detail=`); the
 * timeline re-keys on the id and fetches the pending run's detail.
 */
function onRetried(newRun: WorkflowRun): void {
  load();
  void router.replace({ query: { ...route.query, run_detail: newRun.id } });
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

    <!-- Filters: state + source segmented multi-select + a date range (no FilterBar — §5.1). -->
    <div class="flex flex-col gap-next-3 next-sm:flex-row next-sm:flex-wrap">
      <div class="flex min-w-0 flex-1 basis-48 flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.stateLabel') }}</span>
        <SegmentedControl
          v-model="stateFilter"
          multiple
          size="sm"
          :options="stateOptions"
          :aria-label="t('workflows.runs.filters.stateLabel')"
        />
      </div>
      <div class="flex min-w-0 flex-1 basis-48 flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.originLabel') }}</span>
        <SegmentedControl
          v-model="originFilter"
          multiple
          size="sm"
          :options="originOptions"
          :aria-label="t('workflows.runs.filters.originLabel')"
        />
      </div>
      <div class="flex min-w-0 flex-1 basis-48 flex-col gap-next-1_5">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('workflows.runs.filters.dateLabel') }}</span>
        <DateRangeFilter
          v-model="dateRange"
          size="sm"
          :presets="datePresets"
          :placeholder="t('workflows.runs.filters.dateAny')"
          :aria-label="t('workflows.runs.filters.dateLabel')"
        />
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
        <!-- Appending: a few row-shaped skeletons (never a spinner). -->
        <ul v-if="store.loadingMore" class="flex flex-col gap-next-2" aria-hidden="true">
          <li
            v-for="n in 3"
            :key="`more-sk-${n}`"
            class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
          >
            <div class="flex items-center gap-next-2">
              <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
              <Skeleton variant="rect" width="4rem" height="1.25rem" radius="full" />
            </div>
            <Skeleton variant="text" width="55%" />
          </li>
        </ul>
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
      @retried="onRetried"
    />
  </Drawer>
</template>
