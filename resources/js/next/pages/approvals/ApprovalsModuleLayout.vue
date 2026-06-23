<script setup lang="ts">
// ApprovalsModuleLayout — the Approvals module shell (next): a LEFT inner sub-nav
// + a content area that renders the module's pages (Queue + Pipelines). It HOSTS
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
import Surface from '../../ui/layout/Surface.vue';
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
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

interface SubNavItem {
  key: string;
  label: string;
  icon: IconName;
  to: { name: string };
}
const subNav = computed<SubNavItem[]>(() => [
  // Queue is the primary daily surface — it leads the sub-nav.
  { key: 'queue', label: t('approvals.module.queue'), icon: 'inbox', to: { name: 'next.approvals.queue' } },
  { key: 'pipelines', label: t('approvals.module.pipelines'), icon: 'git-branch', to: { name: 'next.approvals.pipelines' } },
]);

function isActive(name?: string): boolean {
  return !!name && route.name === name;
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
    <!-- Inner sub-navigation (hidden on narrow screens; content stays usable). -->
    <Surface
      as="aside"
      bg="card"
      border
      elevation="sm"
      radius="lg"
      class="hidden w-64 shrink-0 min-h-0 flex-col overflow-y-auto next-lg:flex"
    >
      <div class="flex items-start gap-next-3 border-b border-next-border p-next-4">
        <span
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
          aria-hidden="true"
        >
          <Icon name="check-circle" class="text-next-lg" />
        </span>
        <div class="min-w-0">
          <h2 class="truncate text-next-sm font-next-semibold text-next-fg">
            {{ t('approvals.title') }}
          </h2>
          <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">
            {{ t('approvals.module.selectHint') }}
          </p>
        </div>
      </div>

      <nav class="flex flex-col gap-next-0_5 p-next-2">
        <RouterLink
          v-for="item in subNav"
          :key="item.key"
          :to="item.to"
          class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm transition-colors"
          :class="isActive(item.to.name)
            ? 'bg-next-primary-subtle text-next-primary-subtle-foreground font-next-medium'
            : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'"
        >
          <Icon :name="item.icon" class="shrink-0" />
          {{ item.label }}
        </RouterLink>
      </nav>
    </Surface>

    <!-- Content: the list (or a future sub-view). -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
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
