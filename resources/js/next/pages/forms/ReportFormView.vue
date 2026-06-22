<script setup lang="ts">
// ReportFormView — create a report. Hosted in a DRAWER by FormReportsView: a
// prop-driven body that collects name / guidelines / sources / date range and
// POSTs a report (the backend then generates it asynchronously). Emits
// `submitted` on success. Mirrors StoreFormReportRequest 1:1.
import { computed, ref } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import Button from '../../ui/primitives/Button.vue';
import { useFormsStore } from '../../app/stores/forms';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';

const props = defineProps<{
  formId: string;
  /** The form's created_at (ISO) — the earliest a submission could exist. */
  createdAt?: string | null;
}>();
const emit = defineEmits<{ (e: 'submitted'): void }>();

const store = useFormsStore();
const toast = useToast();
const { t } = useI18n();

// Date bounds (mirrors the legacy dialog): the range can't start before the form
// existed nor end in the future, and from ≤ to. Defaults: from = created date,
// to = today. The pickers aren't clearable, so the dates are never empty.
const today = new Date().toISOString().slice(0, 10);
const minDate = computed<string | undefined>(() => (props.createdAt ? props.createdAt.slice(0, 10) : undefined));

const name = ref('');
const guidelines = ref('');
const sources = ref<string[]>([]);
const from = ref<string | null>(minDate.value ?? today);
const to = ref<string | null>(today);
const saving = ref(false);
const nameError = ref<string | null>(null);
const dateError = ref<string | null>(null);

const sourceOptions: SelectOption[] = [
  { value: 'form', label: t('forms.submissions.sourceForm') },
  { value: 'task', label: t('forms.submissions.sourceTask') },
];

async function submit(): Promise<void> {
  nameError.value = null;
  dateError.value = null;
  if (!name.value.trim()) {
    nameError.value = t('forms.reports.nameRequired');
    return;
  }
  if (!from.value || !to.value) {
    dateError.value = t('forms.reports.dateRequired');
    return;
  }
  if (from.value > to.value) {
    dateError.value = t('forms.reports.dateRangeInvalid');
    return;
  }
  saving.value = true;
  try {
    await store.createReport({
      form_id: props.formId,
      name: name.value.trim(),
      guidelines: guidelines.value.trim() || null,
      sources: sources.value.length ? sources.value : undefined,
      submissions_from: from.value,
      submissions_to: to.value,
    });
    toast.success(t('forms.reports.created'));
    emit('submitted');
  } catch (err: unknown) {
    const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
    const fieldErrors = e.response?.data?.errors;
    if (fieldErrors?.name?.length) nameError.value = fieldErrors.name[0];
    else toast.danger(e.response?.data?.message ?? t('forms.reports.createError'));
  } finally {
    saving.value = false;
  }
}

defineExpose({ submit, saving });
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <p class="text-next-sm text-next-muted-foreground">{{ t('forms.reports.createHint') }}</p>

    <FormField :label="t('common.name')" required :error="nameError ?? undefined">
      <TextInput v-model="name" :placeholder="t('forms.reports.namePlaceholder')" />
    </FormField>

    <FormField :label="t('forms.reports.guidelines')" :description="t('forms.reports.guidelinesHint')">
      <Textarea v-model="guidelines" :rows="4" :placeholder="t('forms.reports.guidelinesPlaceholder')" />
    </FormField>

    <FormField :label="t('forms.reports.sources')" :description="t('forms.reports.sourcesHint')">
      <Select v-model:values="sources" multiple :options="sourceOptions" leading-icon="inbox" :placeholder="t('forms.reports.sourcesAll')" />
    </FormField>

    <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
      <FormField :label="t('forms.reports.from')" required :error="dateError ?? undefined">
        <DatePicker v-model="from" :min="minDate" :max="to ?? today" />
      </FormField>
      <FormField :label="t('forms.reports.to')" required>
        <DatePicker v-model="to" :min="from ?? minDate" :max="today" />
      </FormField>
    </div>

    <div class="flex justify-end border-t border-next-border pt-next-4">
      <Button leading-icon="check" :loading="saving" @click="submit">{{ t('forms.reports.create') }}</Button>
    </div>
  </div>
</template>
