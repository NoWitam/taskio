<script setup lang="ts">
// FormViewer — renders a form's element tree as a real form, on `next` inputs.
//
// Two modes:
//   • preview — read-only (disabled controls); the builder's live preview.
//   • fill    — interactive; validates required + length/range (a client mirror of
//               the backend; the server stays authoritative), then emits `submit`
//               with the NESTED submission payload (see ./submissionData).
//
// `initialData` (a nested submission) hydrates the form to view/edit an existing
// submission. Field values live in a flat map keyed by element id; the boundary
// mapping is handled by ./submissionData. Self-contained; NO legacy import.
import { reactive, ref, watch } from 'vue';
import InputRenderer from './InputRenderer.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import { flattenFormData, structureFormData } from './submissionData';
import type { FormElement } from './types';

const props = withDefaults(
  defineProps<{
    content: FormElement[];
    mode?: 'preview' | 'fill';
    /** Nested submission to view / edit (fill mode). */
    initialData?: Record<string, unknown> | null;
    /** Parent save in flight (disables the submit button). */
    submitting?: boolean;
    /** Hide the built-in submit button (the parent provides its own). */
    hideSubmit?: boolean;
  }>(),
  { mode: 'preview', initialData: null, submitting: false, hideSubmit: false },
);

const emit = defineEmits<{ (e: 'submit', data: Record<string, unknown>): void }>();

const { t } = useI18n();

// Flat field values (keyed by element id; checklist `${id}_${value}`, repeater
// instance child `${childId}_${i}`).
const formData = reactive<Record<string, unknown>>({});
const repeaterInstances = ref<Record<string, number>>({});
const errors = ref<Record<string, string>>({});

function seedRepeaters(els: FormElement[]): void {
  for (const el of els) {
    if (el.type === 'repeater') {
      if (repeaterInstances.value[el.id] == null) repeaterInstances.value[el.id] = el.config.min ?? 1;
      seedRepeaters(el.config.children ?? []);
    } else if (el.type === 'section') {
      seedRepeaters(el.config.children ?? []);
    } else if (el.type === 'grid') {
      for (const col of el.config.columns ?? []) if (col.element) seedRepeaters([col.element]);
    }
  }
}

// (Re)hydrate when the form or the data to edit changes.
watch(
  () => [props.content, props.initialData] as const,
  ([content, initial]) => {
    errors.value = {};
    for (const key of Object.keys(formData)) delete formData[key];
    repeaterInstances.value = {};
    seedRepeaters(content ?? []);
    if (initial && content) {
      const { flat, instances } = flattenFormData(initial, content);
      Object.assign(formData, flat);
      repeaterInstances.value = { ...repeaterInstances.value, ...instances };
    }
  },
  { immediate: true, deep: true },
);

function addRepeater(id: string): void {
  repeaterInstances.value[id] = (repeaterInstances.value[id] ?? 0) + 1;
}
function removeRepeater(id: string): void {
  const current = repeaterInstances.value[id] ?? 0;
  if (current > 0) repeaterInstances.value[id] = current - 1;
}

function getError(id: string): string | undefined {
  return errors.value[id];
}

// --- Validation (client mirror; server is authoritative) ------------------
const INPUT_TYPES = new Set([
  'short_text', 'long_text', 'select', 'image', 'checkbox', 'number', 'date', 'time', 'url', 'checklist',
]);

function isEmpty(value: unknown): boolean {
  return value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0);
}

function validate(): boolean {
  const errs: Record<string, string> = {};

  const walk = (els: FormElement[], suffix: string): void => {
    for (const el of els) {
      const c = el.config;
      const key = suffix ? `${el.id}_${suffix}` : el.id;

      if (el.type === 'section') {
        walk(c.children ?? [], suffix);
        continue;
      }
      if (el.type === 'grid') {
        for (const col of c.columns ?? []) if (col.element) walk([col.element], suffix);
        continue;
      }
      if (el.type === 'repeater') {
        const count = repeaterInstances.value[suffix ? `${el.id}_${suffix}` : el.id] ?? c.min ?? 1;
        for (let i = 1; i <= count; i += 1) walk(c.children ?? [], String(i));
        continue;
      }
      if (!INPUT_TYPES.has(el.type)) continue;

      if (el.type === 'checklist') {
        const checked = (c.options ?? []).some((o) => !!formData[`${key}_${o.value}`]);
        if (c.required && !checked) errs[key] = t('forms.viewer.required', '', { field: c.label ?? '' });
        continue;
      }

      const value = formData[key];
      if (c.required && el.type !== 'checkbox' && isEmpty(value)) {
        errs[key] = t('forms.viewer.required', '', { field: c.label ?? '' });
        continue;
      }
      if ((el.type === 'short_text' || el.type === 'long_text') && typeof value === 'string' && value) {
        if (c.minLength && value.length < c.minLength) {
          errs[key] = t('forms.viewer.minLength', '', { field: c.label ?? '', min: c.minLength });
        } else if (c.maxLength && value.length > c.maxLength) {
          errs[key] = t('forms.viewer.maxLength', '', { field: c.label ?? '', max: c.maxLength });
        }
      }
      if (el.type === 'number' && value != null && value !== '') {
        const n = Number(value);
        if (c.min != null && n < c.min) errs[key] = t('forms.viewer.minValue', '', { field: c.label ?? '', min: c.min });
        else if (c.max != null && n > c.max) errs[key] = t('forms.viewer.maxValue', '', { field: c.label ?? '', max: c.max });
      }
    }
  };

  walk(props.content ?? [], '');
  errors.value = errs;
  return Object.keys(errs).length === 0;
}

function submit(): void {
  if (props.mode !== 'fill') return;
  if (!validate()) return;
  emit('submit', structureFormData(formData, props.content ?? [], repeaterInstances.value));
}

defineExpose({ submit, validate });
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <template v-if="content && content.length > 0">
      <InputRenderer
        v-for="element in content"
        :key="element.id"
        :element="element"
        :mode="mode"
        :form-data="formData"
        :repeater-instances="repeaterInstances"
        :get-error="getError"
        @add-repeater="addRepeater"
        @remove-repeater="removeRepeater"
      />

      <div v-if="mode === 'fill' && !hideSubmit" class="flex justify-end border-t border-next-border pt-next-4">
        <Button leading-icon="check" :loading="submitting" @click="submit">
          {{ t('forms.viewer.submit') }}
        </Button>
      </div>
    </template>

    <EmptyState
      v-else
      :title="t('forms.builder.previewEmptyTitle')"
      :description="t('forms.builder.previewEmptyDescription')"
      icon="file-text"
    />
  </div>
</template>
