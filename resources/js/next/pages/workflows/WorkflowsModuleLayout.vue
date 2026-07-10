<script setup lang="ts">
// WorkflowsModuleLayout — the Workflows (automation) module shell (next, Etap 5):
// a LEFT inner sub-nav + a content area that renders the module's pages (the list
// + the per-workflow detail). It HOSTS the query-driven overlays as query keys so
// a row action and a detail action share one host and they survive navigation:
//   • `?workflow=new` / `?workflow=<id>` — the editor DRAWER (Batch 6b),
//   • `?run=<id>`                        — the run-now MODAL (Batch 6c).
// Each overlay key is preserved across filter changes + section navigation so a
// row action and a detail action share one host and survive navigation.
//
// Mirrors `BotsModuleLayout.vue` one-to-one: on the list the aside shows the module
// header + a single "All workflows" item; after opening a workflow it shows
// back-to-list + the entity info block + a section sub-nav (Overview / Runs) driven
// by `?section=`. The layout watches the route id and prefetches the detail so the
// aside can render identity immediately.
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import WorkflowEditorDrawer from './WorkflowEditorDrawer.vue';
import TargetPickerModal from './TargetPickerModal.vue';
import { workflowStatusMap } from './workflowStatus';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useI18n } from '../../app/i18n';
import type { WorkflowDetail } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useWorkflowsStore();
const statusMap = computed(() => workflowStatusMap(t));

interface SubNavItem {
  key: string;
  label: string;
  icon: IconName;
  to: { name: string };
}
const subNav = computed<SubNavItem[]>(() => [
  { key: 'list', label: t('workflows.module.allWorkflows'), icon: 'list-checks', to: { name: 'next.workflows' } },
]);

function isActive(name?: string): boolean {
  return !!name && route.name === name;
}

// --- Detail sidebar (the info block + section sub-nav for an open workflow) ---
const workflowId = computed(() =>
  route.name === 'next.workflows.detail' && route.params.id ? String(route.params.id) : null,
);
const activeWorkflow = computed(() =>
  workflowId.value && store.detail?.id === workflowId.value ? store.detail : null,
);
const currentSection = computed(() => {
  const s = route.query.section;
  return (Array.isArray(s) ? s[0] : s) || 'overview';
});

// Ensure the open workflow is loaded so the aside can render its identity.
watch(
  workflowId,
  (id) => {
    if (id && store.detail?.id !== id) void store.fetchWorkflow(id);
  },
  { immediate: true },
);

interface DetailNavItem {
  key: string;
  label: string;
  icon: IconName;
  section: string;
}
const detailNav = computed<DetailNavItem[]>(() => [
  { key: 'overview', label: t('workflows.detail.tabOverview'), icon: 'layout-dashboard', section: 'overview' },
  { key: 'runs', label: t('workflows.detail.tabRuns'), icon: 'clock', section: 'runs' },
]);
function sectionLink(section: string) {
  return { name: 'next.workflows.detail', params: { id: workflowId.value ?? '' }, query: { ...route.query, section } };
}

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
    <!-- Inner sub-navigation (hidden on narrow screens; content stays usable). -->
    <Surface
      as="aside"
      bg="card"
      border
      elevation="sm"
      radius="lg"
      class="hidden w-64 shrink-0 min-h-0 flex-col overflow-y-auto next-lg:flex"
    >
      <!-- A workflow is OPEN: back-to-list + its info + section sub-nav. -->
      <template v-if="workflowId">
        <RouterLink
          :to="{ name: 'next.workflows' }"
          class="flex items-center gap-next-2 border-b border-next-border px-next-4 py-next-3 text-next-sm font-next-medium text-next-fg transition-colors hover:text-next-primary"
        >
          <Icon name="arrow-left" class="shrink-0" />
          {{ t('workflows.module.allWorkflows') }}
        </RouterLink>

        <div class="flex items-start gap-next-3 border-b border-next-border p-next-4">
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
            aria-hidden="true"
          >
            <Icon :name="(activeWorkflow?.icon as IconName) || 'workflow'" class="text-next-lg" />
          </span>
          <div class="min-w-0">
            <h3 class="truncate text-next-sm font-next-semibold text-next-fg">{{ activeWorkflow?.name ?? '…' }}</h3>
            <StatusBadge
              v-if="activeWorkflow"
              :status="activeWorkflow.status"
              :status-map="statusMap"
              size="sm"
              class="mt-next-1"
            />
          </div>
        </div>

        <nav class="flex flex-col gap-next-0_5 p-next-2">
          <RouterLink
            v-for="item in detailNav"
            :key="item.key"
            :to="sectionLink(item.section)"
            class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm transition-colors"
            :class="currentSection === item.section
              ? 'bg-next-primary-subtle text-next-primary-subtle-foreground font-next-medium'
              : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'"
          >
            <Icon :name="item.icon" class="shrink-0" />
            {{ item.label }}
          </RouterLink>
        </nav>
      </template>

      <!-- On the LIST: module header + "All workflows". -->
      <template v-else>
        <div class="flex items-start gap-next-3 border-b border-next-border p-next-4">
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
            aria-hidden="true"
          >
            <Icon name="workflow" class="text-next-lg" />
          </span>
          <div class="min-w-0">
            <h2 class="truncate text-next-sm font-next-semibold text-next-fg">{{ t('workflows.title') }}</h2>
            <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('workflows.module.selectHint') }}</p>
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
      </template>
    </Surface>

    <!-- Content: the list (or the per-workflow detail). -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
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
