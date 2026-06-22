<script setup lang="ts">
// FormsModuleLayout — the Forms module shell (next): a left inner sub-navigation
// + a content area that renders the list or a form's sub-module.
//
// Legacy-inspired: the sidebar always offers "All forms" (back to the list); when
// a form is open (route has `:id`) it also shows the form's info + its sub-nav
// (Preview · Submissions · Reports[soon]). The selected form is fetched ONCE here
// and provided to the child views via FORM_MODULE_CTX so they don't re-fetch it.
// The page never scrolls — the sidebar and the content scroll independently.
import { computed, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import FormBuilderView from './builder/FormBuilderView.vue';
import FormFillView from './FormFillView.vue';
import { useFormsStore } from '../../app/stores/forms';
import { useI18n } from '../../app/i18n';
import { iconOf } from './builder/elements';
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

interface SubNavItem {
  key: string;
  label: string;
  icon: 'eye' | 'inbox' | 'file-text';
  to?: { name: string; params: { id: string } };
  soon?: boolean;
}
const subNav = computed<SubNavItem[]>(() => {
  const id = formId.value;
  if (!id) return [];
  return [
    { key: 'preview', label: t('forms.preview'), icon: 'eye', to: { name: 'next.forms.preview', params: { id } } },
    { key: 'submissions', label: t('forms.submissions.title'), icon: 'inbox', to: { name: 'next.forms.submissions', params: { id } } },
    { key: 'reports', label: t('forms.module.reports'), icon: 'file-text', to: { name: 'next.forms.reports', params: { id } } },
  ];
});

function isActive(name?: string): boolean {
  return !!name && route.name === name;
}

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
    <!-- Inner sub-navigation (hidden on narrow screens; content stays usable). -->
    <Surface
      as="aside"
      bg="card"
      border
      elevation="sm"
      radius="lg"
      class="hidden w-64 shrink-0 min-h-0 flex-col overflow-y-auto next-lg:flex"
    >
      <!-- Selected form: back-to-list + info + sub-nav. -->
      <template v-if="formId">
        <RouterLink
          :to="{ name: 'next.forms' }"
          class="flex items-center gap-next-2 border-b border-next-border px-next-4 py-next-3 text-next-sm font-next-medium text-next-fg transition-colors hover:text-next-primary"
        >
          <Icon name="arrow-left" class="shrink-0" />
          {{ t('forms.module.allForms') }}
        </RouterLink>

        <div class="flex items-start gap-next-3 border-b border-next-border p-next-4">
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg"
            :class="form?.is_enabled ? 'bg-next-primary text-next-primary-foreground' : 'bg-next-muted text-next-muted-foreground'"
            aria-hidden="true"
          >
            <Icon :name="form ? iconOf('section') : 'file-text'" class="text-next-lg" />
          </span>
          <div class="min-w-0">
            <h3 class="truncate text-next-sm font-next-semibold text-next-fg">
              {{ form?.name ?? '…' }}
            </h3>
            <span class="mt-next-1 inline-flex items-center gap-next-1 text-next-xs text-next-muted-foreground">
              <Icon :name="form?.is_enabled ? 'check-circle' : 'file-text'" class="text-next-sm" />
              {{ form?.is_enabled ? t('forms.status.enabled') : t('forms.status.draft') }}
            </span>
          </div>
        </div>

        <nav class="flex flex-col gap-next-0_5 p-next-2">
          <template v-for="item in subNav" :key="item.key">
            <RouterLink
              v-if="item.to && !item.soon"
              :to="item.to"
              class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm transition-colors"
              :class="isActive(item.to.name)
                ? 'bg-next-primary-subtle text-next-primary-subtle-foreground font-next-medium'
                : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'"
            >
              <Icon :name="item.icon" class="shrink-0" />
              {{ item.label }}
            </RouterLink>
            <span
              v-else
              class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm text-next-muted-foreground/60"
            >
              <Icon :name="item.icon" class="shrink-0" />
              <span class="flex-1">{{ item.label }}</span>
              <span class="text-next-2xs uppercase tracking-next-wide">{{ t('nav.comingSoon') }}</span>
            </span>
          </template>
        </nav>
      </template>

      <!-- No form selected (on the list). -->
      <p v-else class="p-next-4 text-next-xs text-next-muted-foreground">
        {{ t('forms.module.selectHint') }}
      </p>
    </Surface>

    <!-- Content: the list, or the open form's sub-view. -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
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
