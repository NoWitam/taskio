<script setup lang="ts">
// FormsModuleLayout — the Forms module shell (next): the shared two-level
// ModuleAside (≥ next-lg) + ModuleTabs (below) around a content area that
// renders the list or a form's sub-module.
//
// The aside carries the module block (+ "All forms") and the form RESOURCE
// section: a pick-a-form placeholder linking to the list when nothing is open,
// or the selected form's identity (icon + name + enabled/draft status +
// description) above the sub-view nav (Preview · Submissions · Reports) once a
// form is open (route has `:id`). The page's PageHeader describes the PAGE —
// identity lives here. The selected form is fetched ONCE here and provided to
// the child views via FORM_MODULE_CTX so they don't re-fetch it. The page never
// scrolls — the sidebar and the content scroll independently.
import { computed, onBeforeUnmount, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ModuleAside, { type ModuleNavItem, type ModuleResource } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import FormBuilderView from './builder/FormBuilderView.vue';
import FormFillView from './FormFillView.vue';
import { useFormsStore } from '../../app/stores/forms';
import { useI18n } from '../../app/i18n';
import { setPageContextLabel } from '../../app/lib/pageContext';
import { resolveFormIcon } from '../../ui/forms/formIcon';
import { FORM_MODULE_CTX } from './formContext';
import type { FormDetail } from './types';

const route = useRoute();
const router = useRouter();
const store = useFormsStore();
const { t } = useI18n();

const formId = computed(() => (route.params.id ? String(route.params.id) : null));
const form = ref<FormDetail | null>(null);
const loading = ref(false);

provide(FORM_MODULE_CTX, { form, loading });

watch(
  formId,
  async (id) => {
    if (!id) {
      form.value = null;
      return;
    }
    // Reuse an already-open form (navigating between its sub-tabs), or the one the
    // list PREFETCHED before navigating (store.detail) — so the page renders
    // immediately with no loading flash. Fetch only as a fallback (deep link).
    if (form.value?.id === id) return;
    if (store.detail && store.detail.id === id) {
      form.value = store.detail;
      return;
    }
    loading.value = true;
    form.value = await store.fetchForm(id);
    loading.value = false;
  },
  { immediate: true },
);

// The open form's name feeds the Navbar breadcrumb (Batch 2 consumes it).
// Clear in onBeforeUnmount (synchronous, runs BEFORE the incoming layout's
// setup) — onUnmounted is post-flush and would wipe the label the next module
// layout just set for its cached detail.
watch(
  () => (formId.value ? form.value?.name ?? null : null),
  (name) => setPageContextLabel(name),
  { immediate: true },
);
onBeforeUnmount(() => setPageContextLabel(null));

// --- Section nav (child-route driven) --------------------------------------
const moduleItems = computed<ModuleNavItem[]>(() => [
  { key: 'list', label: t('forms.module.allForms'), icon: 'file-text', to: { name: 'next.forms' } },
]);
const resourceItems = computed<ModuleNavItem[]>(() => {
  const id = formId.value;
  return [
    { key: 'preview', label: t('forms.preview'), icon: 'eye', ...(id ? { to: { name: 'next.forms.preview', params: { id } } } : {}) },
    { key: 'submissions', label: t('forms.submissions.title'), icon: 'inbox', ...(id ? { to: { name: 'next.forms.submissions', params: { id } } } : {}) },
    { key: 'reports', label: t('forms.module.reports'), icon: 'file-text', ...(id ? { to: { name: 'next.forms.reports', params: { id } } } : {}) },
  ];
});

// The selected-form identity block ('…' while the deep-linked form loads). The
// icon is the form's OWN (legacy IconEnum → next glyph; file-text fallback).
const resource = computed<ModuleResource | null>(() =>
  formId.value
    ? {
        icon: resolveFormIcon(form.value?.icon),
        name: form.value?.name ?? '…',
        description: form.value?.description ?? null,
      }
    : null,
);

function isItemActive(item: ModuleNavItem): boolean {
  if (item.key === 'list') return route.name === 'next.forms';
  const targetName = (item.to as { name?: string } | undefined)?.name;
  return !!targetName && route.name === targetName;
}

// Small screens show ONE tab row: the sub-view sections when a form is open,
// otherwise the module pages.
const tabItems = computed<ModuleNavItem[]>(() => (formId.value ? resourceItems.value : moduleItems.value));

// --- Create / edit / fill DRAWERS (query-driven overlays) -----------------
// `?create=1` → new-form builder · `?edit=<id>` → edit builder · `?fill=<id>` →
// fill. The list / sub-view stays mounted behind the drawer.
function dropQuery(keys: string[]): void {
  const query = { ...route.query };
  keys.forEach((k) => delete query[k]);
  void router.replace({ query });
}

const createOpen = computed(() => route.query.create === '1');
const editId = computed(() => (route.query.edit ? String(route.query.edit) : null));
const fillId = computed(() => (route.query.fill ? String(route.query.fill) : null));

const builderOpen = computed<boolean>({
  get: () => createOpen.value || editId.value !== null,
  set: (open) => {
    if (!open) dropQuery(['create', 'edit']);
  },
});
const fillOpen = computed<boolean>({
  get: () => fillId.value !== null,
  set: (open) => {
    if (!open) dropQuery(['fill']);
  },
});

function onBuilderSaved(detail: FormDetail): void {
  // Keep the open form (sidebar + preview) fresh after an edit; the store already
  // reconciled the list. Creating a new form just prepends it to the list.
  if (form.value && form.value.id === detail.id) form.value = detail;
  builderOpen.value = false;
}
function onFillSubmitted(): void {
  // The store prepended the new submission to its bucket; just close.
  fillOpen.value = false;
}
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- Two-level section nav (≥ next-lg): module block + form resource section. -->
    <ModuleAside
      module-icon="file-text"
      :module-title="t('forms.title')"
      :module-hint="t('forms.module.selectHint')"
      :module-items="moduleItems"
      :resource-items="resourceItems"
      :resource="resource"
      :resource-placeholder="{
        icon: 'file-text',
        label: t('forms.module.placeholderLabel'),
        hint: t('forms.module.placeholderHint'),
        to: { name: 'next.forms' },
      }"
      :resource-back="{ label: t('forms.module.allForms'), to: { name: 'next.forms' } }"
      :resource-nav-label="t('forms.module.resourceNav')"
      :active-match="isItemActive"
    >
      <template #resource-meta>
        <!-- Enabled/draft status line (StatusBadge-free — mirrors the list card). -->
        <span
          v-if="form"
          class="inline-flex items-center gap-next-1 text-next-xs text-next-muted-foreground"
        >
          <Icon :name="form.is_enabled ? 'check-circle' : 'file-text'" class="text-next-sm" />
          {{ form.is_enabled ? t('forms.status.enabled') : t('forms.status.draft') }}
        </span>
      </template>
    </ModuleAside>

    <!-- Content: the small-screen section tabs + the list, or the open form's sub-view. -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs
        :items="tabItems"
        :active-match="isItemActive"
        :aria-label="formId ? t('forms.module.resourceNav') : undefined"
      />
      <RouterView />
    </div>

    <!-- Create / edit builder (a large drawer; the builder owns its own header
         + Save/Cancel, so the drawer adds no chrome). -->
    <Drawer
      v-model:open="builderOpen"
      side="right"
      size="cover"
      :scroll-body="false"
      :show-close="false"
      :aria-label="editId ? t('forms.builder.editTitle') : t('forms.builder.createTitle')"
    >
      <FormBuilderView
        :key="editId ?? 'new'"
        :form-id="editId"
        @close="builderOpen = false"
        @saved="onBuilderSaved"
      />
    </Drawer>

    <!-- Fill form (a drawer with its own title + close). -->
    <Drawer v-model:open="fillOpen" side="right" size="xl" :aria-label="t('forms.fill.title')">
      <template #title>{{ t('forms.fill.title') }}</template>
      <FormFillView
        v-if="fillId"
        :key="fillId"
        :form-id="fillId"
        @close="fillOpen = false"
        @submitted="onFillSubmitted"
      />
    </Drawer>
  </div>
</template>
