<script setup lang="ts">
// WorkflowConditionsEditor — the TYPED, schema-driven conditions builder (§4.8).
//
// Shown only for `form_submitted` (the host hides it for `schedule`) and FORM-GATED:
// when no form is selected it renders a header + an info Alert
// (`workflows.condition.needsForm`) and a disabled "Add condition" button — no rows.
// When a form is selected the host passes the catalog's `fields[]`; each condition
// row is three controls that cascade:
//   1. Field  Select over `fields` (label = field label, value = field `path`).
//   2. Operator Select scoped to THAT field's `operators` (its type's allow-list).
//   3. Value input per (field_type, operator): text→TextInput, number→NumberInput,
//      date→DatePicker (between → two DatePickers), enum→Select (in → multi Select),
//      multi→multi/single Select, boolean→NO value input.
// Picking a field sets `field_type` from the descriptor and resets operator + value.
//
// v-model is the typed `WorkflowCondition[]` ({field, field_type, operator, value});
// the component always emits the normalized typed array. An empty list is valid
// ("always runs"). Clear-on-form-change is the DRAWER's job (B7d) — this component
// only renders for the given catalog. A subtle missing-path note (§4.8) sits once
// per section, not per row.
import { computed, ref, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import NumberInput from '../../ui/forms/NumberInput.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import { useI18n } from '../../app/i18n';
import type {
  CatalogField,
  WorkflowCondition,
  WorkflowConditionOperator,
  WorkflowVariableType,
} from './types';

const props = withDefaults(
  defineProps<{
    /** The catalog condition fields (null/empty ⇒ form-gated disabled state). */
    fields?: CatalogField[] | null;
    /** Whether a form is selected (drives the gate independently of `fields`). */
    formSelected?: boolean;
    /** Server 422 errors keyed by `conditions.<i>.<field>`. */
    errors?: Record<string, string>;
  }>(),
  { fields: null, formSelected: false, errors: () => ({}) },
);

const model = defineModel<WorkflowCondition[]>({ default: () => [] });

const { t } = useI18n();

const gated = computed(() => !props.formSelected);
const fieldList = computed<CatalogField[]>(() => props.fields ?? []);

// --- Stable row keys (B7 review) --------------------------------------------
// The wire type (WorkflowCondition) carries no uid, so a parallel uid list keeps
// v-for keys STABLE across mid-list removals (index keys would reuse DOM nodes and
// drop focus). Our own add/remove keep it in sync; the watcher reconciles EXTERNAL
// replacements (clear-on-form-change → truncate, edit seed → grow).
let uidSeq = 0;
const nextRowUid = (): number => ++uidSeq;
const rowUids = ref<number[]>(model.value.map(() => nextRowUid()));

watch(
  () => model.value.length,
  (len) => {
    while (rowUids.value.length < len) rowUids.value.push(nextRowUid());
    if (rowUids.value.length > len) rowUids.value.splice(len);
  },
);

// --- Field / operator / value option builders -------------------------------
const fieldOptions = computed<SelectOption[]>(() =>
  fieldList.value.map((f) => ({ value: f.path, label: f.label })),
);

function fieldByPath(path: string): CatalogField | undefined {
  return fieldList.value.find((f) => f.path === path);
}

/** The operator options for a row, scoped to its field's type allow-list. */
function operatorOptions(condition: WorkflowCondition): SelectOption[] {
  const field = fieldByPath(condition.field);
  const ops = field?.operators ?? [];
  return ops.map((op) => ({
    value: op,
    label: t(`workflows.condition.operator.${op}`),
  }));
}

/** The enum options for a row's value control (from the field's enumOptions). */
function enumOptions(condition: WorkflowCondition): SelectOption[] {
  const field = fieldByPath(condition.field);
  return (field?.enumOptions ?? []).map((o) => ({ value: o, label: o }));
}

// --- Row mutation (immutable emits so v-model stays clean) -------------------
function replaceAt(index: number, next: WorkflowCondition): void {
  const rows = [...model.value];
  rows[index] = next;
  model.value = rows;
}

function addCondition(): void {
  if (gated.value) return;
  // Seed from the first field so the row is well-typed immediately.
  const first = fieldList.value[0];
  if (!first) return;
  model.value = [
    ...model.value,
    {
      field: first.path,
      field_type: first.type,
      operator: (first.operators[0] as WorkflowConditionOperator) ?? 'equals',
      value: undefined,
    },
  ];
}

function removeCondition(index: number): void {
  // Splice the uid FIRST so the length watcher (which only truncates at the tail)
  // never mismatches which row kept which key.
  rowUids.value.splice(index, 1);
  model.value = model.value.filter((_, i) => i !== index);
}

/** Picking a field resets field_type + operator + value (a cascade). */
function onFieldChange(index: number, path: string | null): void {
  if (!path) return;
  const field = fieldByPath(path);
  if (!field) return;
  replaceAt(index, {
    field: field.path,
    field_type: field.type,
    operator: (field.operators[0] as WorkflowConditionOperator) ?? 'equals',
    value: undefined,
  });
}

/** Picking an operator resets the value (the value shape depends on the operator). */
function onOperatorChange(index: number, op: string | null): void {
  const condition = model.value[index];
  if (!condition || !op) return;
  replaceAt(index, {
    ...condition,
    operator: op as WorkflowConditionOperator,
    value: undefined,
  });
}

function setValue(index: number, value: unknown): void {
  const condition = model.value[index];
  if (!condition) return;
  replaceAt(index, { ...condition, value });
}

// --- Value-control kind resolution (field_type × operator) ------------------
type ValueKind = 'text' | 'number' | 'date' | 'dateBetween' | 'enumSingle' | 'enumMulti' | 'multiSingle' | 'none';

function valueKind(condition: WorkflowCondition): ValueKind {
  const type: WorkflowVariableType = condition.field_type;
  const op = condition.operator;
  switch (type) {
    case 'text':
      return 'text';
    case 'number':
      return 'number';
    case 'date':
      return op === 'between' ? 'dateBetween' : 'date';
    case 'enum':
      return op === 'in' ? 'enumMulti' : 'enumSingle';
    case 'multi':
      return 'multiSingle';
    case 'boolean':
      return 'none';
    default:
      return 'text';
  }
}

// Typed helpers reading a row's value for each control kind.
function textValue(c: WorkflowCondition): string {
  return typeof c.value === 'string' ? c.value : '';
}
function numberValue(c: WorkflowCondition): number | null {
  return typeof c.value === 'number' ? c.value : null;
}
function dateValue(c: WorkflowCondition): string | null {
  return typeof c.value === 'string' && c.value !== '' ? c.value : null;
}
function betweenValue(c: WorkflowCondition, i: 0 | 1): string | null {
  return Array.isArray(c.value) ? ((c.value[i] as string | null) ?? null) : null;
}
function setBetween(index: number, i: 0 | 1, value: string | null): void {
  const c = model.value[index];
  const pair: [string | null, string | null] = Array.isArray(c.value)
    ? [c.value[0] ?? null, c.value[1] ?? null]
    : [null, null];
  pair[i] = value;
  setValue(index, pair);
}
function multiValue(c: WorkflowCondition): string[] {
  return Array.isArray(c.value) ? (c.value as string[]) : [];
}
</script>

<template>
  <section class="flex flex-col gap-next-3">
    <div class="flex items-baseline justify-between gap-next-3">
      <div>
        <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.editor.sections.conditions') }}</h3>
        <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('workflows.editor.sections.conditionsHint') }}</p>
      </div>
      <Button
        variant="outline"
        size="sm"
        leading-icon="plus"
        :disabled="gated || fieldList.length === 0"
        @click="addCondition"
      >
        {{ t('workflows.condition.addCondition') }}
      </Button>
    </div>

    <!-- Form-gated disabled state (§4.4a / §4.8). -->
    <Alert v-if="gated" variant="info" size="sm">
      {{ t('workflows.condition.needsForm') }}
    </Alert>

    <template v-else>
      <!-- Empty list is valid ("always runs"). -->
      <p
        v-if="model.length === 0"
        class="rounded-next-md bg-next-muted px-next-3 py-next-2 text-next-xs text-next-muted-foreground"
      >
        {{ t('workflows.condition.emptyHint') }}
      </p>

      <ul v-else class="flex flex-col gap-next-3">
        <li
          v-for="(cond, index) in model"
          :key="rowUids[index] ?? index"
          class="rounded-next-lg border border-next-border bg-next-card p-next-3"
        >
          <div class="flex flex-col gap-next-3 next-sm:flex-row next-sm:items-start">
            <!-- 1. Field -->
            <FormField
              :label="t('workflows.condition.fieldLabel')"
              class="min-w-0 flex-1"
              :error="errors[`conditions.${index}.field`]"
            >
              <Select
                :model-value="cond.field"
                :options="fieldOptions"
                :placeholder="t('workflows.condition.fieldPlaceholder')"
                :aria-label="t('workflows.condition.fieldLabel')"
                @update:model-value="(v) => onFieldChange(index, v)"
              />
            </FormField>

            <!-- 2. Operator (scoped to the field type) -->
            <FormField
              :label="t('workflows.condition.operatorLabel')"
              class="w-full shrink-0 next-sm:w-44"
              :error="errors[`conditions.${index}.operator`]"
            >
              <Select
                :model-value="cond.operator"
                :options="operatorOptions(cond)"
                :aria-label="t('workflows.condition.operatorLabel')"
                @update:model-value="(v) => onOperatorChange(index, v)"
              />
            </FormField>

            <!-- 3. Value (per field_type × operator) -->
            <FormField
              :label="t('workflows.condition.valueLabel')"
              class="min-w-0 flex-1"
              :error="errors[`conditions.${index}.value`]"
            >
              <template v-if="valueKind(cond) === 'text'">
                <TextInput
                  :model-value="textValue(cond)"
                  :placeholder="t('workflows.condition.valuePlaceholder')"
                  :aria-label="t('workflows.condition.valueLabel')"
                  @update:model-value="(v) => setValue(index, v)"
                />
              </template>
              <template v-else-if="valueKind(cond) === 'number'">
                <NumberInput
                  :model-value="numberValue(cond)"
                  :aria-label="t('workflows.condition.valueLabel')"
                  @update:model-value="(v) => setValue(index, v)"
                />
              </template>
              <template v-else-if="valueKind(cond) === 'date'">
                <DatePicker
                  :model-value="dateValue(cond)"
                  :aria-label="t('workflows.condition.valueLabel')"
                  @update:model-value="(v) => setValue(index, v)"
                />
              </template>
              <template v-else-if="valueKind(cond) === 'dateBetween'">
                <div class="flex items-center gap-next-2">
                  <DatePicker
                    :model-value="betweenValue(cond, 0)"
                    :aria-label="t('workflows.condition.betweenFrom')"
                    @update:model-value="(v) => setBetween(index, 0, v)"
                  />
                  <span class="text-next-xs text-next-muted-foreground">{{ t('workflows.condition.betweenTo') }}</span>
                  <DatePicker
                    :model-value="betweenValue(cond, 1)"
                    :aria-label="t('workflows.condition.betweenTo')"
                    @update:model-value="(v) => setBetween(index, 1, v)"
                  />
                </div>
              </template>
              <template v-else-if="valueKind(cond) === 'enumSingle' || valueKind(cond) === 'multiSingle'">
                <Select
                  :model-value="dateValue(cond)"
                  :options="enumOptions(cond)"
                  :placeholder="t('workflows.condition.valuePlaceholder')"
                  :aria-label="t('workflows.condition.valueLabel')"
                  @update:model-value="(v) => setValue(index, v)"
                />
              </template>
              <template v-else-if="valueKind(cond) === 'enumMulti'">
                <Select
                  multiple
                  :values="multiValue(cond)"
                  :options="enumOptions(cond)"
                  :placeholder="t('workflows.condition.valuePlaceholder')"
                  :aria-label="t('workflows.condition.valueLabel')"
                  @update:values="(v) => setValue(index, v)"
                />
              </template>
              <!-- boolean: no value control. A short note keeps the row legible. -->
              <p v-else class="text-next-xs text-next-muted-foreground">
                {{ t('workflows.condition.booleanNote') }}
              </p>
            </FormField>

            <div class="flex shrink-0 items-end next-sm:pt-next-6">
              <Button
                variant="ghost"
                size="icon-xs"
                leading-icon="x"
                :aria-label="t('workflows.condition.removeCondition')"
                @click="removeCondition(index)"
              />
            </div>
          </div>
        </li>
      </ul>

      <!-- Missing-path hint: one subtle note per section (§4.8). -->
      <p v-if="model.length > 0" class="text-next-xs text-next-muted-foreground">
        {{ t('workflows.condition.missingPathHint') }}
      </p>
    </template>
  </section>
</template>
