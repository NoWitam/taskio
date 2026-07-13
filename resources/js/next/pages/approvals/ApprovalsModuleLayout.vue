<script setup lang="ts">
// ApprovalsModuleLayout — the Approvals module shell (next): the shared
// ModuleAside (≥ next-lg) + ModuleTabs (below) section nav around a content
// area that renders the module's pages (Queue + Pipelines). It HOSTS
// two query-driven DRAWERS, the same pattern as the Forms module layout:
//   • the pipeline builder (`?pipeline=new` · `?pipeline=<id>`), and
//   • the approval review drawer (`?review=<processId>` — Batch 2).
// The list / sub-view stays mounted behind whichever drawer is open, and the
// query-sync helpers PRESERVE both overlay keys so opening one never drops the
// other.
//
// Queue is the primary daily surface, so it leads the sub-nav.
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ModuleAside, { type ModuleNavItem } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import PipelineBuilderDrawer from './PipelineBuilderDrawer.vue';
import ApprovalReviewDrawer from './ApprovalReviewDrawer.vue';
import { useApprovalQueueStore } from '../../app/stores/approvalQueue';
import { useI18n } from '../../app/i18n';
import type { ApprovalPipeline } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const queueStore = useApprovalQueueStore();

// Approvals has NO resource-scoped pages — the aside is just the module block +
// its two pages (no resource section / placeholder).
const moduleItems = computed<ModuleNavItem[]>(() => [
  // Queue is the primary daily surface — it leads the sub-nav.
  { key: 'queue', label: t('approvals.module.queue'), icon: 'inbox', to: { name: 'next.approvals.queue' } },
  { key: 'pipelines', label: t('approvals.module.pipelines'), icon: 'git-branch', to: { name: 'next.approvals.pipelines' } },
]);

function isItemActive(item: ModuleNavItem): boolean {
  return route.name === (item.to as { name?: string } | undefined)?.name;
}

// Warm the nav-badge count on mount (Batch 3 renders the badge UI; cheap to fetch
// now so the count is ready). Best-effort — a failure is silent.
onMounted(() => {
  // The app shell already warms the count on its own mount; only fetch here if it
  // hasn't been captured yet (avoids a duplicate request on the first navigation).
  if (queueStore.count === null) void queueStore.fetchCount().catch(() => undefined);
});

// --- Builder DRAWER (query-driven overlay) --------------------------------
// `?pipeline=new` → new-pipeline builder · `?pipeline=<id>` → edit builder.
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
const pipelineParam = computed<string | null>(() => str(route.query.pipeline) || null);
const isNew = computed(() => pipelineParam.value === 'new');
const editId = computed(() => (pipelineParam.value && !isNew.value ? pipelineParam.value : null));

function dropQuery(keys: string[]): void {
  const query = { ...route.query };
  keys.forEach((k) => delete query[k]);
  void router.replace({ query });
}

const builderOpen = computed<boolean>({
  get: () => pipelineParam.value !== null,
  set: (open) => {
    if (!open) dropQuery(['pipeline']);
  },
});

function onBuilderSaved(_pipeline: ApprovalPipeline): void {
  // The store already reconciled the list (prepend on create, replace on update);
  // just close the drawer.
  builderOpen.value = false;
}

// --- Review DRAWER (query-driven overlay) ---------------------------------
// `?review=<processId>` → the approval review drawer. `dropQuery` only removes
// the `review` key, so the `?pipeline` overlay (and any other query) survives.
const reviewProcessId = computed<string | null>(() => str(route.query.review) || null);

const reviewOpen = computed<boolean>({
  get: () => reviewProcessId.value !== null,
  set: (open) => {
    if (!open) dropQuery(['review']);
  },
});

function onReviewClosed(): void {
  reviewOpen.value = false;
}
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- Section nav (≥ next-lg): module block + Queue / Pipelines. -->
    <ModuleAside
      module-icon="check-circle"
      :module-title="t('approvals.title')"
      :module-hint="t('approvals.module.selectHint')"
      :module-items="moduleItems"
      :active-match="isItemActive"
    />

    <!-- Content: the small-screen section tabs + the list (or a future sub-view). -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs :items="moduleItems" :active-match="isItemActive" />
      <RouterView />
    </div>

    <!-- Pipeline builder (a large drawer; the builder owns its own header +
         Save/Cancel, so the drawer adds no chrome). -->
    <Drawer
      v-model:open="builderOpen"
      side="right"
      size="2xl"
      :scroll-body="false"
      :show-close="false"
      :aria-label="editId ? t('approvals.builder.editTitle') : t('approvals.builder.createTitle')"
    >
      <PipelineBuilderDrawer
        :key="editId ?? 'new'"
        :pipeline-id="editId"
        @close="builderOpen = false"
        @saved="onBuilderSaved"
      />
    </Drawer>

    <!-- Approval review (a large drawer; the review owns its own header + close,
         so the drawer adds no chrome and manages its own scroll regions). -->
    <Drawer
      v-model:open="reviewOpen"
      side="right"
      size="cover"
      :scroll-body="false"
      :show-close="false"
      :aria-label="t('approvals.review.title')"
    >
      <ApprovalReviewDrawer
        v-if="reviewProcessId"
        :key="reviewProcessId"
        :process-id="reviewProcessId"
        @close="onReviewClosed"
      />
    </Drawer>
  </div>
</template>
