<script setup lang="ts">
// FormPreviewView — read-only preview of a form (`/forms/:id/preview`).
//
// A sub-view of FormsModuleLayout: renders the open form (injected via
// FORM_MODULE_CTX) through FormViewer in preview mode, with quick actions to fill
// it out or edit it. All strings via i18n.
import { computed, inject } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from '../../ui/primitives/Button.vue';
import Surface from '../../ui/layout/Surface.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import FormViewer from './FormViewer.vue';
import { FORM_MODULE_CTX } from './formContext';
import { useI18n } from '../../app/i18n';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const ctx = inject(FORM_MODULE_CTX);

const form = computed(() => ctx?.form.value ?? null);
const loading = computed(() => ctx?.loading.value ?? false);

// Open the builder / fill DRAWERS (owned by the module layout) via query.
function fill(): void {
  if (form.value) void router.push({ query: { ...route.query, fill: form.value.id } });
}
function edit(): void {
  if (form.value) void router.push({ query: { ...route.query, edit: form.value.id } });
}
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <!-- Sub-view header (uniform scale app-wide): the h1 is the SECTION label —
         the form's identity lives in the module aside's selected block. -->
    <PageHeader icon="eye" :title="t('forms.preview')">
      <template #actions>
        <Button v-if="form?.can_be_edited" variant="outline" leading-icon="pencil" @click="edit">
          {{ t('forms.actions.edit') }}
        </Button>
        <Button v-if="form?.can_be_filled" leading-icon="check-circle" @click="fill">
          {{ t('forms.actions.fill') }}
        </Button>
      </template>
    </PageHeader>

    <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-5 p-next-6">
      <div v-if="loading" class="flex flex-col gap-next-4">
        <Skeleton variant="text" width="40%" />
        <Skeleton variant="rect" height="2.5rem" />
        <Skeleton variant="rect" height="2.5rem" />
      </div>
      <template v-else>
        <p v-if="form?.description" class="text-next-sm text-next-muted-foreground">{{ form.description }}</p>
        <FormViewer :content="form?.content ?? []" mode="preview" />
      </template>
    </Surface>
  </div>
</template>
