<script setup lang="ts">
// TemplatePickerModal — "Nowa sesja": pick a TEMPLATE to start a generation session from.
//
// A Modal listing the workspace templates (reusing the templates store) with a debounced search; picking
// one emits its id, and the caller creates the session (POST /generator/sessions {template_id}) and
// routes to the chat. States: loading skeletons / error+retry / no-results / the list. The user fills the
// slots inside the chat's Setup turn, so this only needs to choose the recipe.
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import { contentTypeIcon, contentTypeLabel } from './templateMeta';
import { useTemplatesStore } from '../../app/stores/templates';
import { useDebounce } from '../../app/composables/useDebounce';
import { useI18n } from '../../app/i18n';
import type { Template } from './types';

const props = defineProps<{ submitting?: boolean }>();

const emit = defineEmits<{ select: [string] }>();

const { t } = useI18n();
const store = useTemplatesStore();

const open = defineModel<boolean>('open', { default: false });

const search = ref('');
const filters = computed(() => ({ search: search.value || undefined }));

function refetch(): void {
  void store.fetchTemplates(filters.value, { reset: true });
}
const debouncedRefetch = useDebounce(refetch, 350);
watch(search, () => debouncedRefetch());

// (Re)load the list whenever the modal opens.
watch(open, (isOpen) => {
  if (isOpen) {
    search.value = '';
    refetch();
  }
});

const items = computed(() => store.items);
const initialLoading = computed(() => store.loading && items.value.length === 0);
const isEmpty = computed(() => !store.loading && !store.errored && items.value.length === 0);
const hasSearch = computed(() => !!search.value);
const skeletonKeys = Array.from({ length: 4 }, (_, i) => i);

function pick(template: Template): void {
  if (props.submitting) return;
  emit('select', template.id);
}
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="t('generator.sessions.picker.title')">
    <template #title>{{ t('generator.sessions.picker.title') }}</template>
    <template #description>{{ t('generator.sessions.picker.subtitle') }}</template>

    <div class="flex flex-col gap-next-3">
      <TextInput
        v-model="search"
        type="search"
        leading-icon="search"
        :placeholder="t('generator.templates.filters.search')"
        :aria-label="t('generator.templates.filters.search')"
      />

      <!-- Error + retry. -->
      <EmptyState
        v-if="store.errored && items.length === 0"
        variant="error"
        :title="t('generator.templates.errors.title')"
        :description="t('generator.templates.errors.description')"
      >
        <template #action>
          <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="refetch">
            {{ t('generator.sessions.errors.retry') }}
          </Button>
        </template>
      </EmptyState>

      <!-- Loading skeletons. -->
      <ul v-else-if="initialLoading" class="flex flex-col gap-next-2" aria-hidden="true">
        <li
          v-for="n in skeletonKeys"
          :key="`sk-${n}`"
          class="flex items-center gap-next-3 rounded-next-lg border border-next-border p-next-3"
        >
          <Skeleton variant="rect" width="2.25rem" height="2.25rem" radius="md" />
          <div class="flex flex-1 flex-col gap-next-1">
            <Skeleton variant="text" width="40%" />
            <Skeleton variant="text" width="25%" />
          </div>
        </li>
      </ul>

      <!-- Empty (no templates / no results). -->
      <EmptyState
        v-else-if="isEmpty"
        :variant="hasSearch ? 'search' : 'default'"
        :icon="hasSearch ? 'search' : 'sparkles'"
        :title="hasSearch ? t('generator.templates.empty.searchTitle') : t('generator.templates.empty.title')"
        :description="hasSearch ? t('generator.templates.empty.searchDescription') : t('generator.templates.empty.description')"
      />

      <!-- The templates. -->
      <ul v-else class="flex max-h-[50vh] flex-col gap-next-2 overflow-y-auto">
        <li v-for="template in items" :key="template.id">
          <button
            type="button"
            class="flex w-full items-center gap-next-3 rounded-next-lg border border-next-border bg-next-card p-next-3 text-left transition-colors hover:border-next-primary hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            :disabled="submitting"
            @click="pick(template)"
          >
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-md bg-next-primary text-next-primary-foreground"
              aria-hidden="true"
            >
              <Icon :name="contentTypeIcon(template.content_type)" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col">
              <span class="truncate font-next-medium text-next-fg">{{ template.name }}</span>
              <span class="truncate text-next-xs text-next-muted-foreground">
                {{ contentTypeLabel(template.content_type, t) }}
                <template v-if="template.description"> · {{ template.description }}</template>
              </span>
            </span>
            <Icon name="chevron-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
          </button>
        </li>
      </ul>
    </div>
  </Modal>
</template>
