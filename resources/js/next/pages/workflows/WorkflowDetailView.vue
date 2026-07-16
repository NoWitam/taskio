<script setup lang="ts">
// WorkflowDetailView — the workflow detail SHELL (§3). A sub-view of
// WorkflowsModuleLayout: this shell owns the detail fetch (loading / error
// states) + the PageHeader whose h1 names the active SECTION's purpose
// (uniform header scale app-wide — the workflow's identity lives in the module
// aside's selected block) and the workflow's actions, and renders the section
// through its own <RouterView> — the child routes
// `next.workflows.detail.overview` (default) and `next.workflows.detail.runs`
// (WorkflowRunsSection; Runs has its own lifecycle). The Overview panels live
// in WorkflowOverviewView.
//
// Reads the workflow from the store's detail cache when the user arrived via a
// card prefetch; on a DEEP LINK (no cache) it fetches by id and shows skeletons /
// an error state. The section children only render once the workflow is loaded,
// so they read the same cache without fetching.
//
// Actions (§3.1): back, Run now (when `can_run`, opens the `?run=<id>` run-now
// modal), Activate/Deactivate (when `can_change_status`), Edit (when
// `can_be_edited`, opens `?workflow=<id>`).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Button from '../../ui/primitives/Button.vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useWorkflowsStore();
const toast = useToast();

const workflowId = computed(() => String(route.params.id));

// The active section (child route name suffix) drives the PageHeader: the h1
// names the PAGE's purpose (the workflow's identity lives in the module aside's
// selected block).
const section = computed(() => {
  const name = String(route.name ?? '');
  const prefix = 'next.workflows.detail.';
  return name.startsWith(prefix) ? name.slice(prefix.length) : 'overview';
});
const SECTION_META: Record<string, { icon: IconName; titleKey: string; descriptionKey: string }> = {
  overview: { icon: 'layout-dashboard', titleKey: 'workflows.detail.tabOverview', descriptionKey: 'workflows.detail.sectionDescriptions.overview' },
  runs: { icon: 'clock', titleKey: 'workflows.detail.tabRuns', descriptionKey: 'workflows.detail.sectionDescriptions.runs' },
};
const sectionMeta = computed(() => SECTION_META[section.value] ?? SECTION_META.overview);

// The cached detail (when it matches the route id), else null until fetched.
const workflow = computed(() =>
  store.detail && store.detail.id === workflowId.value ? store.detail : null,
);

const loading = ref(false);
const loadError = ref(false);

async function load(): Promise<void> {
  if (workflow.value) return; // prefetched by the list card → no fetch flash
  loading.value = true;
  loadError.value = false;
  const result = await store.fetchWorkflow(workflowId.value);
  loading.value = false;
  if (!result) loadError.value = true;
}

onMounted(load);
watch(workflowId, () => {
  loadError.value = false;
  void load();
});

// --- Capability-gated actions ---------------------------------------------
const canRun = computed(() => workflow.value?.can_run === true);
const canChangeStatus = computed(() => workflow.value?.can_change_status === true);
const canEdit = computed(() => workflow.value?.can_be_edited === true);
const isActive = computed(() => workflow.value?.status === 'active');

const togglingStatus = ref(false);
async function onToggleStatus(): Promise<void> {
  if (!workflow.value || togglingStatus.value) return;
  const next = isActive.value ? 'inactive' : 'active';
  togglingStatus.value = true;
  try {
    await store.setStatus(workflow.value.id, next);
    toast.success(
      next === 'active'
        ? t('workflows.statusAction.activated')
        : t('workflows.statusAction.deactivated'),
    );
  } catch {
    toast.danger(t('workflows.statusAction.error'));
  } finally {
    togglingStatus.value = false;
  }
}

function onRun(): void {
  if (workflow.value) void router.push({ query: { ...route.query, run: workflow.value.id } });
}
function onEdit(): void {
  if (workflow.value) void router.push({ query: { ...route.query, workflow: workflow.value.id } });
}
function onBack(): void {
  void router.push({ name: 'next.workflows' });
}
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <!-- Deep-link / fetch error → a clear error state with retry + back. -->
    <EmptyState
      v-if="loadError && !workflow"
      variant="error"
      :title="t('workflows.detail.errorTitle')"
      :description="t('workflows.detail.errorDescription')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load">
          {{ t('workflows.errors.retry') }}
        </Button>
      </template>
      <template #secondary>
        <Button variant="ghost" size="sm" leading-icon="arrow-left" @click="onBack">
          {{ t('workflows.detail.back') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Loading (deep-link without a prefetch): geometry-mimicking skeletons. -->
    <div v-else-if="loading && !workflow" class="flex flex-col gap-next-6">
      <div class="flex items-center gap-next-3">
        <Skeleton variant="circle" diameter="2.75rem" />
        <div class="flex flex-1 flex-col gap-next-2">
          <Skeleton variant="text" width="30%" />
          <Skeleton variant="text" width="50%" />
        </div>
      </div>
      <Skeleton variant="rect" height="9rem" />
      <Skeleton variant="rect" height="6rem" />
    </div>

    <template v-else-if="workflow">
      <!-- Page header: the h1 names the SECTION's purpose (uniform size across
           the app); the workflow's identity lives in the module aside's
           selected block. Back-navigation lives in the aside/breadcrumb, so the
           actions carry only the workflow's own operations. -->
      <PageHeader
        :title="t(sectionMeta.titleKey)"
        :icon="sectionMeta.icon"
        :description="t(sectionMeta.descriptionKey)"
      >
        <template #actions>
          <Button v-if="canRun" leading-icon="arrow-right" @click="onRun">
            {{ t('workflows.actions.run') }}
          </Button>
          <Button
            v-if="canChangeStatus"
            :variant="isActive ? 'outline' : 'primary'"
            :leading-icon="isActive ? 'circle' : 'check-circle'"
            :loading="togglingStatus"
            :disabled="togglingStatus"
            @click="onToggleStatus"
          >
            {{ isActive ? t('workflows.actions.deactivate') : t('workflows.actions.activate') }}
          </Button>
          <Button v-if="canEdit" leading-icon="pencil" @click="onEdit">
            {{ t('workflows.actions.edit') }}
          </Button>
        </template>
      </PageHeader>

      <!-- The active SECTION child route (Overview | Runs). -->
      <RouterView />
    </template>
  </div>
</template>
