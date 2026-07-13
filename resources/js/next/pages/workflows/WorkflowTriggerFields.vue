<script setup lang="ts">
// WorkflowTriggerFields — the PER-TYPE trigger fields (§4.3-4.5, 5.1 → B7 wizard).
//
// B7 SPLIT: the trigger-TYPE selector (the two-option SegmentedControl) + all
// type-change side-effects now live in the DRAWER (wizard step 1). This component is
// now PURELY the per-type field set for the type the drawer passes down; it never
// changes the type itself. The drawer still owns conditions (a sibling panel) so this
// component emits `form-change` when the form_id changes for the drawer to react.
//
//   • form_submitted panel:
//       – Form   → FormSelect (single, clearable → form_id | null = "any form").
//       – Source → a two-checkbox group (manual/task); empty ⇒ source null ("any").
//       – Anonymous → a tri-state SegmentedControl (any/only_anonymous/
//         only_non_anonymous → null/true/false), shown whenever a form is selected
//         (the §9-gap-2 PRIMARY fallback — the FE can't cheaply know the form's
//         anonymous flag; the backend accepts anonymous regardless).
//   • schedule panel: WorkflowScheduleBuilder — the three-tab builder (Czas | Dzień |
//     Miesiąc) hosting the summary + preview strip + the AI assist MODAL (opened from
//     the summary); the builder exposes `isValid` for the drawer's step/save gate.
//
// Contract (the drawer owns the whole trigger state upward):
//   • :type                  → WorkflowTriggerType ('form_submitted' | 'schedule') — READ-ONLY.
//   • v-model:formConfig      → FormTriggerDraft  ({form_id, source[], anonymous})
//   • v-model:scheduleDraft   → ScheduleDraft     ({family, params, tz})
// Props: `errors` (wire-keyed 422 map), `formSeed` (FormSelect label on edit),
// `scheduleTz` (the user's active tz, sent to the assist). The schedule builder is
// exposed via `scheduleValid()` so the drawer can gate the step / save.
import { computed, useTemplateRef } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import FormSelect from '../../ui/forms/FormSelect.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Checkbox from '../../ui/forms/Checkbox.vue';
// The AI assist is hosted INSIDE the builder (opened from the summary), so
// WorkflowTriggerFields renders only the builder for a schedule trigger.
import WorkflowScheduleBuilder from './WorkflowScheduleBuilder.vue';
import { useI18n } from '../../app/i18n';
import { type FormTriggerDraft } from './workflowEditorModel';
import type { ScheduleDraft } from './workflowSchedule';
import type { SubmissionSource, WorkflowTriggerType } from './types';

const props = withDefaults(
  defineProps<{
    /** The active trigger type — OWNED by the drawer (wizard step 1); read-only here. */
    type: WorkflowTriggerType;
    /** Server 422 errors keyed by wire path (e.g. `trigger_config.form_id`). */
    errors?: Record<string, string>;
    /** Seed the FormSelect so the currently-selected form's name renders on edit. */
    formSeed?: Array<{ id: string; name: string; icon?: string | null }>;
    /** The user's active timezone, sent to the schedule assist as a hint. */
    scheduleTz?: string | null;
  }>(),
  { errors: () => ({}), formSeed: () => [], scheduleTz: null },
);

const formConfig = defineModel<FormTriggerDraft>('formConfig', { required: true });
const scheduleDraft = defineModel<ScheduleDraft>('scheduleDraft', { required: true });

const emit = defineEmits<{
  /** The form_id changed (cleared / different form) — the drawer reacts to conditions + catalog. */
  (e: 'form-change', formId: string | null): void;
}>();

const { t } = useI18n();

// --- form_submitted: Form (single, clearable) -------------------------------
const formIdModel = computed<string | null>({
  get: () => formConfig.value.form_id,
  set: (id) => {
    const next = id ?? null;
    if (next === formConfig.value.form_id) return;
    formConfig.value = { ...formConfig.value, form_id: next };
    emit('form-change', next);
  },
});

const formSelected = computed(() => formConfig.value.form_id !== null);

// --- form_submitted: Source (two-checkbox group → source[] | null) ----------
const SOURCES: SubmissionSource[] = ['manual', 'task'];

function sourceChecked(source: SubmissionSource): boolean {
  return formConfig.value.source.includes(source);
}
function toggleSource(source: SubmissionSource, checked: boolean): void {
  const set = new Set(formConfig.value.source);
  if (checked) set.add(source);
  else set.delete(source);
  // Preserve the canonical order (manual before task).
  formConfig.value = {
    ...formConfig.value,
    source: SOURCES.filter((s) => set.has(s)),
  };
}

// --- form_submitted: Anonymous (tri-state → null/true/false) ----------------
type AnonymousChoice = 'any' | 'only_anonymous' | 'only_non_anonymous';

const anonymousOptions = computed<SegmentOption<AnonymousChoice>[]>(() => [
  { value: 'any', label: t('workflows.trigger.anonymous.any') },
  { value: 'only_anonymous', label: t('workflows.trigger.anonymous.onlyAnonymous') },
  { value: 'only_non_anonymous', label: t('workflows.trigger.anonymous.onlyNonAnonymous') },
]);

const anonymousModel = computed<AnonymousChoice>({
  get: () => {
    const a = formConfig.value.anonymous;
    if (a === true) return 'only_anonymous';
    if (a === false) return 'only_non_anonymous';
    return 'any';
  },
  set: (choice) => {
    const anonymous = choice === 'only_anonymous' ? true : choice === 'only_non_anonymous' ? false : null;
    formConfig.value = { ...formConfig.value, anonymous };
  },
});

// --- schedule: builder validity exposure ------------------------------------
const builderRef = useTemplateRef<InstanceType<typeof WorkflowScheduleBuilder>>('builder');

/** Expose the schedule builder's validity so the drawer can gate the step / save (§4.10). */
function scheduleValid(): boolean {
  // Fail CLOSED: the drawer only consults this for a schedule trigger, where a missing
  // builder means the schedule cannot have been validated — never treat that as valid.
  return builderRef.value?.isValid ?? false;
}
defineExpose({ scheduleValid });
</script>

<template>
  <section class="flex flex-col gap-next-4">
    <!-- form_submitted panel (§4.4). -->
    <template v-if="type === 'form_submitted'">
      <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
        <FormField
          :label="t('workflows.editor.trigger.formLabel')"
          :description="t('workflows.editor.trigger.formHint')"
          :error="errors['trigger_config.form_id']"
        >
          <FormSelect
            v-model="formIdModel"
            :seed="formSeed"
            :placeholder="t('workflows.editor.trigger.formPlaceholder')"
            :aria-label="t('workflows.editor.trigger.formLabel')"
          />
        </FormField>
      </div>

      <!-- Source — two-checkbox group; empty ⇒ any source. -->
      <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
        <FormField
          :label="t('workflows.editor.trigger.sourceLabel')"
          :description="t('workflows.trigger.source.hint')"
          :error="errors['trigger_config.source.in']"
        >
          <div class="flex flex-col gap-next-2" role="group" :aria-label="t('workflows.editor.trigger.sourceLabel')">
            <Checkbox
              v-for="source in SOURCES"
              :key="source"
              :model-value="sourceChecked(source)"
              :label="t(`workflows.trigger.source.${source}`)"
              @update:model-value="(v: boolean) => toggleSource(source, v)"
            />
          </div>
        </FormField>
      </div>

      <!-- Anonymous — tri-state, shown whenever a form is selected (§4.4). -->
      <div
        v-if="formSelected"
        class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]"
      >
        <FormField :label="t('workflows.editor.trigger.anonymousLabel')" :error="errors['trigger_config.anonymous']">
          <SegmentedControl
            v-model="anonymousModel"
            :options="anonymousOptions"
            equal-width
            :aria-label="t('workflows.editor.trigger.anonymousLabel')"
          />
        </FormField>
      </div>
    </template>

    <!-- schedule panel (§4.5): the builder hosts the summary + preview strip + the
         AI assist (opened from the summary). -->
    <template v-else-if="type === 'schedule'">
      <WorkflowScheduleBuilder ref="builder" v-model="scheduleDraft" :errors="errors" :tz="scheduleTz" />
    </template>
  </section>
</template>
