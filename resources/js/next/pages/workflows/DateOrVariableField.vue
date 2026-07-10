<script setup lang="ts">
// DateOrVariableField — the date value-or-variable add-on (§4.9.2).
//
// The SAME interaction as ValueOrVariableField, with the literal control fixed to a
// `DatePicker` and the variable picker filtered (by the host) to `date`-typed
// variables (`trigger.scheduled_at`, `trigger.submitted_at`, any date form field).
// Used for `create_task.deadline` and the `create_form_report` submission windows.
//
// Rather than re-author the braces-toggle / chip / picker machinery, this composes
// `ValueOrVariableField` and supplies a `DatePicker` through its literal slot — one
// value-or-variable pattern, parameterized by its literal control (spec §4.9.3).
//
// v-model is the canonical `WorkflowFieldValue<string>` union (the literal arm's
// value is an ISO `yyyy-mm-dd` day string, the DatePicker's model contract).
import DatePicker from '../../ui/forms/DatePicker.vue';
import ValueOrVariableField from './ValueOrVariableField.vue';
import { useI18n } from '../../app/i18n';
import type { CatalogVariable, WorkflowFieldValue } from './types';

withDefaults(
  defineProps<{
    /** The date-typed catalog variables (host filters via `variablesOfType(..,'date')`). */
    variables: CatalogVariable[];
    /** aria-label for the variable-mode picker. */
    pickerLabel?: string;
    /** aria-label / placeholder for the literal DatePicker. */
    dateLabel?: string;
    datePlaceholder?: string;
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const model = defineModel<WorkflowFieldValue<string> | null>({ default: null });

const { t } = useI18n();
</script>

<template>
  <ValueOrVariableField
    v-model="model"
    :variables="variables"
    :picker-label="pickerLabel ?? t('workflows.field.pickVariable')"
    :disabled="disabled"
  >
    <template #default="{ value, setValue, disabled: slotDisabled }">
      <!-- Literal mode: an ISO-day DatePicker. Its `null` model maps to the union's
           `{kind:'literal', value:null}` (empty) via setValue. -->
      <DatePicker
        :model-value="(value as string | null) ?? null"
        :disabled="slotDisabled"
        :placeholder="datePlaceholder"
        :aria-label="dateLabel ?? t('workflows.field.pickDate')"
        @update:model-value="(v) => setValue(v)"
      />
    </template>
  </ValueOrVariableField>
</template>
