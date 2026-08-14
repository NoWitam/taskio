<script setup lang="ts">
// DateOrVariableField — the date value-or-variable add-on (§4.9.2).
//
// The SAME interaction as ValueOrVariableField, with the literal control fixed to a
// date picker and the variable picker filtered (by the host) to `date`-typed
// variables (`trigger.scheduled_at`, `trigger.submitted_at`, any date form field).
// Used for `create_task.deadline`, the `create_form_report` submission windows and the
// three `create_event` date fields.
//
// Rather than re-author the braces-toggle / chip / picker machinery, this composes
// `ValueOrVariableField` and supplies the picker through its literal slot — one
// value-or-variable pattern, parameterized by its literal control (spec §4.9.3).
//
// ── `withTime`: WHICH LITERAL CONTROL, AND WHY IT IS THE HOST'S CALL ─────────────────
// A field's granularity is a fact about the FIELD, not about this component, and getting
// it wrong is silent: a day-only control under a label that promises a moment cannot
// express an hour at all, and the day it does emit is read as midnight — which, for every
// workspace west of Greenwich, is the PREVIOUS day on the grid the value will be drawn on.
// So the host says which it needs and the two are never interchangeable:
//
//   withTime = false (default) → `DatePicker`,     literal arm = `yyyy-mm-dd`
//   withTime = true            → `DateTimePicker`, literal arm = `yyyy-mm-ddTHH:mm`
//
// A DAY field must keep the day control. `create_event.start_date` is an all-day event's
// date: an all-day event has no zone to convert into and no hour to state, so offering one
// there would be the same mismatch pointing the other way.
//
// NOTHING HERE COMPENSATES FOR A TIMEZONE. The emitted wall clock is exactly what the
// author picked, with no offset attached and no shifting: the server reads a bare time in
// the workspace's zone (an explicit offset, when a variable carries one, wins). A second
// implementation of that rule on this side could only ever disagree with the first.
//
// v-model is the canonical `WorkflowFieldValue<string>` union either way — only the
// literal arm's string granularity changes.
import DatePicker from '../../ui/forms/DatePicker.vue';
import DateTimePicker from '../../ui/forms/DateTimePicker.vue';
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
    /**
     * Whether this field names a MOMENT rather than a day — see the header. `true` swaps the
     * literal control for a `DateTimePicker` (`yyyy-mm-ddTHH:mm`); the default keeps the
     * day-only `DatePicker` (`yyyy-mm-dd`).
     */
    withTime?: boolean;
    /** aria-label for the variable-mode picker. */
    pickerLabel?: string;
    /** aria-label / placeholder for the literal date control. */
    dateLabel?: string;
    datePlaceholder?: string;
    /**
     * Forwarded to ValueOrVariableField: when the host already shows a server/validation
     * error for this field, suppress the inner field's inline type-error line (finding 4).
     */
    externalErrorPresent?: boolean;
    disabled?: boolean;
  }>(),
  {
    operationsCatalog: () => [],
    argVariables: () => [],
    withTime: false,
    externalErrorPresent: false,
    disabled: false,
  },
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
      <!-- Literal mode. Either control's `null` model maps to the union's
           `{kind:'literal', value:null}` (empty) via setValue; the granularity is the
           host's decision (see the header) and the emitted string is the author's own
           wall clock, never a zone-adjusted one. -->
      <DateTimePicker
        v-if="withTime"
        :model-value="(value as string | null) ?? null"
        :disabled="slotDisabled"
        :placeholder="datePlaceholder"
        :aria-label="dateLabel ?? t('workflows.field.pickDateTime')"
        @update:model-value="(v) => setValue(v)"
      />
      <DatePicker
        v-else
        :model-value="(value as string | null) ?? null"
        :disabled="slotDisabled"
        :placeholder="datePlaceholder"
        :aria-label="dateLabel ?? t('workflows.field.pickDate')"
        @update:model-value="(v) => setValue(v)"
      />
    </template>
  </ValueOrVariableField>
</template>
