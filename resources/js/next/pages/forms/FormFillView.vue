<script setup lang="ts">
// FormFillView — fill a form + create a submission. Hosted in a DRAWER (the Forms
// module opens it via `?fill=<id>`), so it's a prop-driven body: it loads the
// form, renders FormViewer in `fill` mode, and on submit POSTs a submission then
// emits `submitted`. States: loading skeleton, load error, not-fillable notice.
import { computed, onMounted, ref } from 'vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import FormViewer from './FormViewer.vue';
import { useFormsStore } from '../../app/stores/forms';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { FormDetail, FormSubmission } from './types';

const props = defineProps<{ formId: string }>();
const emit = defineEmits<{
  (e: 'close'): void;
  // The created submission is passed so callers that need its id (e.g. the
  // workflow run-now Create flow) can use it; existing callers ignore the arg.
  (e: 'submitted', submission: FormSubmission): void;
}>();

const store = useFormsStore();
const toast = useToast();
const { t } = useI18n();

const form = ref<FormDetail | null>(null);
const loading = ref(true);
const loadError = ref(false);
const submitting = ref(false);

const canFill = computed(() => form.value?.can_be_filled ?? false);

onMounted(async () => {
  loading.value = true;
  loadError.value = false;
  const detail = await store.fetchForm(props.formId);
  loading.value = false;
  if (!detail) {
    loadError.value = true;
    return;
  }
  form.value = detail;
});

async function onSubmit(data: Record<string, unknown>): Promise<void> {
  submitting.value = true;
  try {
    const submission = await store.createSubmission({ form_id: props.formId, data });
    toast.success(t('forms.fill.submitted'));
    emit('submitted', submission);
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } };
    toast.danger(e.response?.data?.message ?? t('forms.fill.submitError'));
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <div v-if="loading" class="flex flex-col gap-next-4">
      <Skeleton variant="text" width="40%" />
      <Skeleton variant="rect" height="2.5rem" />
      <Skeleton variant="rect" height="2.5rem" />
      <Skeleton variant="rect" height="2.5rem" />
    </div>

    <EmptyState
      v-else-if="loadError"
      variant="error"
      :title="t('forms.fill.loadError')"
      :description="t('forms.error.description')"
    />

    <EmptyState
      v-else-if="!canFill"
      :title="t('forms.fill.notFillableTitle')"
      :description="t('forms.fill.notFillableBody')"
      icon="alert-triangle"
    />

    <template v-else>
      <p v-if="form?.description" class="text-next-sm text-next-muted-foreground">{{ form.description }}</p>
      <FormViewer :content="form?.content ?? []" mode="fill" :submitting="submitting" @submit="onSubmit" />
    </template>
  </div>
</template>
