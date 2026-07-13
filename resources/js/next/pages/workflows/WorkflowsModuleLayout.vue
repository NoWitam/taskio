<script setup lang="ts">
// WorkflowsModuleLayout — the Workflows (automation) module shell (next, Etap 5):
// the shared two-level ModuleAside (≥ next-lg) + ModuleTabs (below) around a
// content area that renders the module's pages (the list + the per-workflow
// detail). It HOSTS the query-driven overlays as query keys so a row action and
// a detail action share one host and they survive navigation:
//   • `?workflow=new` / `?workflow=<id>` — the editor DRAWER (Batch 6b),
//   • `?run=<id>`                        — the run-now MODAL (Batch 6c).
// Each overlay key is preserved across filter changes + section navigation.
//
// Mirrors `BotsModuleLayout.vue` one-to-one: the aside carries the module block
// (+ "All workflows") and the workflow RESOURCE section — a pick-a-workflow
// placeholder linking to the list, or the selected workflow's identity (icon +
// name + StatusBadge + description) above the section nav (Overview / Runs)
// once a `next.workflows.detail.*` child route is active. The page's PageHeader
// describes the PAGE — identity lives here. The layout watches the route id and
// prefetches the detail so the identity block can render immediately.
import { computed, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ModuleAside, { type ModuleNavItem, type ModuleResource } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import WorkflowEditorDrawer from './WorkflowEditorDrawer.vue';
import TargetPickerModal from './TargetPickerModal.vue';
import { workflowStatusMap } from './workflowStatus';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useI18n } from '../../app/i18n';
import { setPageContextLabel } from '../../app/lib/pageContext';
import type { IconName } from '../../ui/primitives/icons';
import type { WorkflowDetail } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useWorkflowsStore();
const statusMap = computed(() => workflowStatusMap(t));

// --- Section nav (route-name driven) ---------------------------------------
// A workflow is open on any `next.workflows.detail*` route (the bare redirect
// record + the section children), so prefix-match the route NAME.
const workflowId = computed(() =>
  String(route.name ?? '').startsWith('next.workflows.detail') && route.params.id
    ? String(route.params.id)
    : null,
);
// The active section is the child route name's suffix (default: overview).
const currentSection = computed(() => {
  const name = String(route.name ?? '');
  const prefix = 'next.workflows.detail.';
  return name.startsWith(prefix) ? name.slice(prefix.length) : 'overview';
});

const activeWorkflow = computed(() =>
  workflowId.value && store.detail?.id === workflowId.value ? store.detail : null,
);

// Ensure the open workflow is loaded so the aside identity block can render.
watch(
  workflowId,
  (id) => {
    if (id && store.detail?.id !== id) void store.fetchWorkflow(id);
  },
  { immediate: true },
);

// The open workflow's name feeds the Navbar breadcrumb (Batch 2 consumes it).
// Clear in onBeforeUnmount (synchronous, runs BEFORE the incoming layout's
// setup) — onUnmounted is post-flush and would wipe the label the next module
// layout just set for its cached detail.
watch(
  () => (workflowId.value && store.detail?.id === workflowId.value ? store.detail.name : null),
  (name) => setPageContextLabel(name),
  { immediate: true },
);
onBeforeUnmount(() => setPageContextLabel(null));

function sectionLink(section: string) {
  // Preserve the query (overlay keys, run filters) but never a legacy `section`
  // key — the child route name carries the section now.
  const query = { ...route.query };
  delete query.section;
  return { name: 'next.workflows.detail.' + section, params: { id: workflowId.value ?? '' }, query };
}

const moduleItems = computed<ModuleNavItem[]>(() => [
  { key: 'list', label: t('workflows.module.allWorkflows'), icon: 'list-checks', to: { name: 'next.workflows' } },
]);
const resourceItems = computed<ModuleNavItem[]>(() => [
  { key: 'overview', label: t('workflows.detail.tabOverview'), icon: 'layout-dashboard', to: sectionLink('overview') },
  { key: 'runs', label: t('workflows.detail.tabRuns'), icon: 'clock', to: sectionLink('runs') },
]);

// The selected-workflow identity block ('…' while the deep-linked detail loads).
const resource = computed<ModuleResource | null>(() =>
  workflowId.value
    ? {
        icon: ((activeWorkflow.value?.icon as IconName) || 'workflow') as IconName,
        name: activeWorkflow.value?.name ?? '…',
        description: activeWorkflow.value?.description ?? null,
      }
    : null,
);

function isItemActive(item: ModuleNavItem): boolean {
  if (item.key === 'list') return route.name === 'next.workflows';
  return workflowId.value !== null && currentSection.value === item.key;
}

// Small screens show ONE tab row: the resource sections when a workflow is
// open, otherwise the module pages.
const tabItems = computed<ModuleNavItem[]>(() =>
  workflowId.value ? resourceItems.value : moduleItems.value,
);

// --- Query-driven overlay HOSTS -------------------------------------------
// `?workflow=` (editor drawer, 6b) and `?run=` (run-now modal, 6c). This slice
// only owns the open/close state + the preserved query keys; the overlay bodies
// render in later batches. The `str` helper normalizes an array/undefined query.
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));

const workflowParam = computed<string | null>(() => str(route.query.workflow) || null);
const runParam = computed<string | null>(() => str(route.query.run) || null);

// `?workflow=new` → new-workflow editor · `?workflow=<id>` → edit editor. Keyed by
// `editId ?? 'new'` in the host so the drawer remounts + re-seeds per workflow.
const isNewWorkflow = computed(() => workflowParam.value === 'new');
const editId = computed(() => (workflowParam.value && !isNewWorkflow.value ? workflowParam.value : null));

function dropQuery(keys: string[]): void {
  const query = { ...route.query };
  keys.forEach((k) => delete query[k]);
  void router.replace({ query });
}

// The editor overlay is OPEN whenever `?workflow=` is present.
const editorOpen = computed<boolean>({
  get: () => workflowParam.value !== null,
  set: (open) => {
    if (!open) dropQuery(['workflow']);
  },
});

// The run-now overlay is OPEN whenever `?run=<id>` is present. `dropQuery` removes
// only the `run` key, so the `?workflow` overlay (and any other query) survives.
const runOpen = computed<boolean>({
  get: () => runParam.value !== null,
  set: (open) => {
    if (!open) dropQuery(['run']);
  },
});

function onEditorSaved(_workflow: WorkflowDetail): void {
  // The store already reconciled the list (prepend on create, replace on update);
  // just close the drawer.
  editorOpen.value = false;
}
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- Two-level section nav (≥ next-lg): module block + workflow resource section. -->
    <ModuleAside
      module-icon="workflow"
      :module-title="t('workflows.title')"
      :module-hint="t('workflows.module.selectHint')"
      :module-items="moduleItems"
      :resource-items="resourceItems"
      :resource="resource"
      :resource-placeholder="{
        icon: 'workflow',
        label: t('workflows.module.placeholderLabel'),
        hint: t('workflows.module.placeholderHint'),
        to: { name: 'next.workflows' },
      }"
      :resource-back="{ label: t('workflows.module.allWorkflows'), to: { name: 'next.workflows' } }"
      :resource-nav-label="t('workflows.module.resourceNav')"
      :active-match="isItemActive"
    >
      <template #resource-meta>
        <StatusBadge
          v-if="activeWorkflow"
          :status="activeWorkflow.status"
          :status-map="statusMap"
          size="sm"
        />
      </template>
    </ModuleAside>

    <!-- Content: the small-screen section tabs + the list (or the detail). -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs
        :items="tabItems"
        :active-match="isItemActive"
        :aria-label="workflowId ? t('workflows.module.resourceNav') : undefined"
      />
      <RouterView />
    </div>

    <!-- Workflow editor (a cover drawer; the editor owns its own header +
         Anuluj/Zapisz, so the drawer adds no chrome). -->
    <Drawer
      v-model:open="editorOpen"
      side="right"
      size="cover"
      :scroll-body="false"
      :show-close="false"
      :aria-label="editId ? t('workflows.editor.editTitle') : t('workflows.editor.createTitle')"
    >
      <WorkflowEditorDrawer
        :key="editId ?? 'new'"
        :workflow-id="editId"
        @close="editorOpen = false"
        @saved="onEditorSaved"
      />
    </Drawer>

    <!-- Run-now target-picker modal (`?run=<id>`). Keyed by the run param so it
         remounts + re-resolves per workflow; owns its own close via v-model. -->
    <TargetPickerModal
      v-if="runParam"
      :key="runParam"
      v-model:open="runOpen"
      :workflow-id="runParam"
      @close="runOpen = false"
    />
  </div>
</template>
