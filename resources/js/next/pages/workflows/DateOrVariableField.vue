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
import type { VariableOperationDefinition } from '../../ui/editor/extensions/types';

/** Date fields accept a pipeline that MUST terminate on `date` (§4.9). */
const DATE_RESULT_TYPES = ['date'] as const;

withDefaults(
  defineProps<{
    /** The date-typed catalog variables (host filters via `variablesOfType(..,'date')`). */
    variables: CatalogVariable[];
    /** The merged operations catalog for the variable-mode pipeline (empty ⇒ no pipeline). */
    operationsCatalog?: VariableOperationDefinition[];
    /**
     * The show-all pool an OP ARGUMENT inside the (date) pipeline may reference (phase-4b) —
     * forwarded to the inner ValueOrVariableField so a value-typed op arg gains the value/variable
     * toggle. Empty (the default) ⇒ op args stay literal-only.
     */
    argVariables?: CatalogVariable[];
    /** aria-label for the variable-mode picker. */
    pickerLabel?: string;
    /** aria-label / placeholder for the literal DatePicker. */
    dateLabel?: string;
    datePlaceholder?: string;
    /**
     * Forwarded to ValueOrVariableField: when the host already shows a server/validation
     * error for this field, suppress the inner field's inline type-error line (finding 4).
     */
    externalErrorPresent?: boolean;
    disabled?: boolean;
  }>(),
  { operationsCatalog: () => [], argVariables: () => [], externalErrorPresent: false, disabled: false },
);

const model = defineModel<WorkflowFieldValue<string> | null>({ default: null });

/**
 * Re-emitted from the inner ValueOrVariableField: `{expected}` when the picked date
 * variable's saved pipeline does not terminate on `date`, else `null`. The host routes
 * this into its per-field error channel (same contract as the value field).
 */
const emit = defineEmits<{ 'update:typeError': [null | { expected: string }] }>();

const { t } = useI18n();
</script>

<template>
  <ValueOrVariableField
    v-model="model"
    :variables="variables"
    :operations-catalog="operationsCatalog"
    :arg-variables="argVariables"
    :result-types="[...DATE_RESULT_TYPES]"
    :external-error-present="externalErrorPresent"
    :picker-label="pickerLabel ?? t('workflows.field.pickVariable')"
    :disabled="disabled"
    @update:type-error="(p) => emit('update:typeError', p)"
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
