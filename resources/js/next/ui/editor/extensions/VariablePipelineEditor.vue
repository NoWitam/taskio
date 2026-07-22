<script setup lang="ts">
// VariablePipelineEditor — the SHARED operations-pipeline builder. Given a base
// (source) primitive type + an operations catalog, it renders:
//   • an "add operation" DropdownMenu filtered by the CURRENT (running) type,
//   • a list of steps, each a Select of operations valid for THAT step's input
//     type + per-arg inputs (text / number / boolean / select) + a remove button,
//   • and reports the computed resultType (output of the last op) to the parent.
//
// This is the DRY core used by BOTH the VariablePanel and the IF condition editor
// (the legacy editor duplicated this logic across VariablePanel + IfBlockPanel).
//
// ARG-VARIABLE slot (phase-4b): a VALUE-TYPED arg (text/number/boolean/date) may be supplied by a
// VARIABLE rather than a constant. This component owns the DECISION (value-typed AND within the
// `depth` cap AND the host provided the `argVariable` slot) but NOT the value-or-variable UI — the
// host (ValueOrVariableField) fills the `argVariable` slot with a recursive value-or-variable field,
// so this shared editor keeps NO dependency on the workflow page. When the slot is absent (conditions
// / markdown builders) or the depth cap is reached, the arg renders its literal control (unchanged).
//
// v-model is the `pipeline` array. The parent owns the base type + catalog. The
// component is presentation + type-flow only; it never serializes.
import { computed, ref, useSlots } from 'vue';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import Badge from '../../primitives/Badge.vue';
import Select from '../../forms/Select.vue';
import TextInput from '../../forms/TextInput.vue';
import NumberInput from '../../forms/NumberInput.vue';
import DatePicker from '../../forms/DatePicker.vue';
import SegmentedControl from '../../forms/SegmentedControl.vue';
import DropdownMenu from '../../overlay/DropdownMenu.vue';
import PipelineArgLiteralInput from './PipelineArgLiteralInput.vue';
import {
  argVariableValueType,
  buildDefaultArgs,
  computeInputType,
  createPipelineStep,
  getArgumentIconName,
  getVariableIconLabel,
  getVariableIconName,
  isChoiceProducingOp,
  MAX_ARG_VARIABLE_DEPTH,
  operationsForType,
  resolveType,
} from './operationHelpers';
import { useI18n } from '../../../app/i18n';
import type {
  ArgVariableValue,
  ChoiceRule,
  VariableArgValue,
  VariableOperationArgumentDefinition,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';

const props = defineProps<{
  /** Source primitive type the pipeline starts from. */
  baseType: VariablePrimitive;
  /** Operations catalog (all available transforms). */
  catalog: VariableOperationDefinition[];
  /**
   * The SOURCE variable's selectable options (enum/multi sources) — they feed the
   * `sourceOption` / `sourceOptions` operation args, whose choices depend on the
   * picked variable rather than the operation definition.
   */
  sourceOptions?: VariableOption[];
  /**
   * The DESTINATION field's selectable options (a "choice"/enum value-or-variable
   * field, e.g. task priority). They feed the CHOICE-producing arg kinds
   * (`sourceMap` with `mapType:'enum'`, `choiceRules`, `choiceFallback`), whose
   * choices come from the destination field rather than the source variable. When
   * empty (the default) the choice-producing operations are NOT offered — so they
   * never appear in the conditions editor or the markdown variable builder.
   */
  targetOptions?: VariableOption[];
  /**
   * Optional cap on the number of pipeline steps. When set and reached, the
   * "add operation" trigger is disabled (mirrors the host's backend limit — the
   * workflow condition builder passes 10). Undefined = no limit (default).
   */
  maxSteps?: number;
  /**
   * The ARG-VARIABLE nesting depth of THIS pipeline (phase-4b). A top-level value-or-variable
   * pipeline is depth 0; each nested arg-variable's own pipeline increments it. A value-typed arg
   * offers the value/variable toggle (the `argVariable` slot) ONLY while `depth < MAX_ARG_VARIABLE_
   * DEPTH` — mirroring the backend write cap so the author can never build a config that 422s.
   * Default 0.
   */
  depth?: number;
}>();

const pipeline = defineModel<VariablePipelineStep[]>({ default: () => [] });

const slots = useSlots();
const { t } = useI18n();

/** This pipeline's arg-variable nesting depth (0 = a top-level value-or-variable pipeline). */
const argDepth = computed(() => props.depth ?? 0);

/**
 * Whether a value-typed arg may become a variable here: the host must provide the `argVariable` slot
 * (the value-or-variable field does; the conditions / markdown builders do NOT → literal-only) AND
 * we must be within the depth cap. At/over the cap the arg renders LITERAL-ONLY — exactly where the
 * backend rejects a deeper nesting.
 */
const canOfferArgVariable = computed(() => !!slots.argVariable && argDepth.value < MAX_ARG_VARIABLE_DEPTH);

/** Whether an arg's control is value-typed (text/number/boolean/date) — i.e. variable-able. */
function isValueTypedArg(arg: VariableOperationArgumentDefinition): boolean {
  return argVariableValueType(arg.type) !== null;
}

/** Whether an arg value is a variable union rather than a literal. */
function isArgVariable(value: unknown): value is ArgVariableValue {
  return !!value && typeof value === 'object' && !Array.isArray(value) && (value as { kind?: string }).kind === 'variable';
}

/** The last dotted segment of a path (for a variable arg's compact chip echo). */
function lastPathSegment(path: string): string {
  const parts = path.split('.');
  return parts[parts.length - 1] || path;
}

const resultType = computed<VariablePrimitive>(() =>
  resolveType(props.catalog, props.baseType, pipeline.value),
);
const nextInputType = computed<VariablePrimitive>(() =>
  computeInputType(props.catalog, props.baseType, pipeline.value, pipeline.value.length),
);

/**
 * Drop the CHOICE-producing operations unless a `targetOptions` destination set
 * exists — they target a specific option set that only a "choice" value-or-variable
 * field provides. This is the ONE gate that keeps them out of the conditions editor
 * and the markdown variable builder (both pass no `targetOptions`).
 */
function offerable(ops: VariableOperationDefinition[]): VariableOperationDefinition[] {
  if ((props.targetOptions ?? []).length) return ops;
  return ops.filter((op) => !isChoiceProducingOp(op));
}

const addableOperations = computed(() =>
  offerable(operationsForType(props.catalog, nextInputType.value)),
);
/** True once the pipeline reached the host's step cap (disables the add trigger). */
const atMaxSteps = computed(
  () => props.maxSteps != null && pipeline.value.length >= props.maxSteps,
);

defineExpose({ resultType });

function stepInputType(stepIndex: number): VariablePrimitive {
  return computeInputType(props.catalog, props.baseType, pipeline.value, stepIndex);
}

function operationOptionsForStep(stepIndex: number) {
  return offerable(operationsForType(props.catalog, stepInputType(stepIndex))).map((op) => ({
    value: op.id,
    label: op.label,
  }));
}

function findOp(id: string): VariableOperationDefinition | undefined {
  return props.catalog.find((op) => op.id === id);
}

// Only ONE step is expanded into its full config at a time; everything else is a
// compact chip. A freshly added step opens in edit mode so its op/args can be set.
const editingStepId = ref<string | null>(null);

function addOperation(op: VariableOperationDefinition, close?: () => void): void {
  const step = createPipelineStep(op);
  pipeline.value = [...pipeline.value, step];
  editingStepId.value = step.stepId;
  close?.();
}

function removeStep(stepId: string): void {
  pipeline.value = pipeline.value.filter((s) => s.stepId !== stepId);
  if (editingStepId.value === stepId) editingStepId.value = null;
}

// --- Chip (collapsed) summary helpers -------------------------------------
function opLabel(step: VariablePipelineStep): string {
  return findOp(step.operationId)?.label ?? t('editor.pipeline.selectOperation', 'Select an operation');
}
function opArgs(step: VariablePipelineStep) {
  return findOp(step.operationId)?.args ?? [];
}
function stepOutputType(step: VariablePipelineStep): VariablePrimitive {
  return findOp(step.operationId)?.outputType ?? step.outputType;
}
/** The source option whose VALUE matches, for label echo (sourceOption/sourceOptions). */
function sourceOptionLabel(value: string): string {
  return (props.sourceOptions ?? []).find((o) => o.value === value)?.label ?? value;
}

function formatArgValue(step: VariablePipelineStep, argId: string): string {
  const arg = opArgs(step).find((a) => a.id === argId);
  const raw = step.args[argId];
  // A value-typed arg supplied by a VARIABLE (phase-4b): echo the referenced variable's path tail
  // (checked FIRST so a boolean/date variable is not mis-read as a literal by the branches below).
  if (isArgVariable(raw)) {
    const path = raw.ref?.path ?? '';
    return path ? lastPathSegment(path) : t('editor.pipeline.argVariable', 'variable');
  }
  if (arg?.type === 'boolean') {
    return raw ? t('editor.pipeline.booleanYes', 'yes') : t('editor.pipeline.booleanNo', 'no');
  }
  if (arg?.type === 'select') {
    const opt = (arg.options ?? []).find((o) => o.value === String(raw));
    return opt?.label ?? (raw === '' || raw == null ? '—' : String(raw));
  }
  if (arg?.type === 'sourceOption') {
    return raw === '' || raw == null ? '—' : sourceOptionLabel(String(raw));
  }
  if (arg?.type === 'sourceOptions') {
    const values = Array.isArray(raw) ? (raw as string[]) : [];
    return values.length ? values.map(sourceOptionLabel).join(', ') : '—';
  }
  if (arg?.type === 'sourceMap') {
    const map = raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, string | number>) : {};
    const total = (props.sourceOptions ?? []).length;
    const done = Object.values(map).filter((v) => v !== '' && v != null).length;
    return t('editor.pipeline.mappedCount', 'Mapped {done}/{total}', { done, total });
  }
  if (arg?.type === 'choiceRules') {
    const rules = Array.isArray(raw) ? (raw as ChoiceRule[]) : [];
    return t('editor.pipeline.rulesCount', '{count} rules', { count: rules.length });
  }
  if (arg?.type === 'choiceFallback') {
    return raw === '' || raw == null ? '—' : targetOptionLabel(String(raw));
  }
  if (Array.isArray(raw)) return raw.length ? raw.join(', ') : '—';
  if (raw && typeof raw === 'object') return '—';
  return raw === '' || raw == null ? '—' : String(raw);
}

function updateOperation(stepId: string, opId: string): void {
  const def = findOp(opId);
  if (!def) return;
  pipeline.value = pipeline.value.map((s) =>
    s.stepId === stepId
      ? { ...s, operationId: def.id, args: buildDefaultArgs(def.args), outputType: def.outputType }
      : s,
  );
}

function updateArg(
  stepId: string,
  argId: string,
  value: VariableArgValue,
): void {
  pipeline.value = pipeline.value.map((s) =>
    s.stepId === stepId ? { ...s, args: { ...s.args, [argId]: value } } : s,
  );
}

/** A `sourceMap` arg's current record (defensive). */
function sourceMapValue(step: VariablePipelineStep, argId: string): Record<string, string | number> {
  const raw = step.args[argId];
  return raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, string | number>) : {};
}

/** Set ONE option's mapped target value inside a `sourceMap` arg. */
function setMapEntry(step: VariablePipelineStep, argId: string, option: string, value: string | number | null): void {
  const next = { ...sourceMapValue(step, argId) };
  if (value === '' || value == null) delete next[option];
  else next[option] = value;
  updateArg(step.stepId, argId, next);
}

/** The source options mapped to Select / selection-card options. */
const sourceSelectOptions = computed(() =>
  (props.sourceOptions ?? []).map((o) => ({ value: o.value, label: o.label })),
);

/** A `sourceOptions` arg's current value as a string[] (defensive). */
function sourceOptionsValue(step: VariablePipelineStep, argId: string): string[] {
  const raw = step.args[argId];
  return Array.isArray(raw) && raw.every((v) => typeof v === 'string') ? (raw as string[]) : [];
}

// --- Target (destination) options — CHOICE-producing args --------------------
/** The destination options mapped to Select options (choiceRules/Fallback + enum map). */
const targetSelectOptions = computed(() =>
  (props.targetOptions ?? []).map((o) => ({ value: o.value, label: o.label })),
);

/** The target option whose VALUE matches, for the chip label echo. */
function targetOptionLabel(value: string): string {
  return (props.targetOptions ?? []).find((o) => o.value === value)?.label ?? value;
}

/** A `choiceRules` arg's current value as a ChoiceRule[] (defensive). */
function choiceRulesValue(step: VariablePipelineStep, argId: string): ChoiceRule[] {
  const raw = step.args[argId];
  if (!Array.isArray(raw)) return [];
  return raw.filter((r): r is ChoiceRule => !!r && typeof r === 'object' && 'when' in r && 'then' in r);
}

/** Append an empty rule row to a `choiceRules` arg. */
function addChoiceRule(step: VariablePipelineStep, argId: string): void {
  updateArg(step.stepId, argId, [...choiceRulesValue(step, argId), { when: '', then: '' }]);
}

/** Remove the rule row at `index` from a `choiceRules` arg. */
function removeChoiceRule(step: VariablePipelineStep, argId: string, index: number): void {
  updateArg(step.stepId, argId, choiceRulesValue(step, argId).filter((_, i) => i !== index));
}

/** Patch ONE field of the rule row at `index` inside a `choiceRules` arg. */
function setChoiceRule(
  step: VariablePipelineStep,
  argId: string,
  index: number,
  key: keyof ChoiceRule,
  value: string,
): void {
  const next = choiceRulesValue(step, argId).map((rule, i) => (i === index ? { ...rule, [key]: value } : rule));
  updateArg(step.stepId, argId, next);
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <div class="flex items-center justify-between gap-next-2">
      <p class="text-next-sm font-next-semibold text-next-fg">{{ t('editor.pipeline.title', 'Operations pipeline') }}</p>
      <DropdownMenu :aria-label="t('editor.pipeline.addOperation', 'Add operation')" placement="bottom-end">
        <!-- The Popover trigger wrapper handles the click; do NOT also bind
             @click="toggle" or it toggles twice (open→close) and never opens. -->
        <template #trigger="{ props: triggerProps }">
          <Button
            size="sm"
            variant="secondary"
            type="button"
            leading-icon="plus"
            :disabled="!addableOperations.length || atMaxSteps"
            :title="atMaxSteps ? t('editor.pipeline.maxSteps', 'You reached the maximum number of operations.') : undefined"
            v-bind="triggerProps"
          >
            {{ t('editor.pipeline.addOperation', 'Add operation') }}
          </Button>
        </template>
        <template #default="{ close }">
          <div class="w-72 max-w-[min(92vw,20rem)] p-next-1">
            <p class="flex items-center gap-next-2 px-next-2 pb-next-1 text-next-xs text-next-muted-foreground">
              {{ t('editor.pipeline.availableForType', 'Available for type') }}
              <span
                class="inline-flex h-6 w-6 items-center justify-center rounded-next-full bg-next-muted text-next-fg"
                :title="getVariableIconLabel(nextInputType)"
              >
                <Icon :name="getVariableIconName(nextInputType)" />
                <span class="sr-only">{{ getVariableIconLabel(nextInputType) }}</span>
              </span>
            </p>
            <p v-if="!addableOperations.length" class="px-next-2 py-next-2 text-next-sm text-next-muted-foreground">
              {{ t('editor.pipeline.noOperationsForType', 'No operations for this type.') }}
            </p>
            <div v-else class="flex flex-col gap-next-1">
              <button
                v-for="op in addableOperations"
                :key="op.id"
                type="button"
                class="flex items-center justify-between gap-next-2 rounded-next-md border border-next-border px-next-2 py-next-1_5 text-left hover:border-next-primary hover:bg-next-primary-subtle"
                @click="addOperation(op, close)"
              >
                <span class="min-w-0">
                  <span class="block truncate text-next-sm font-next-medium text-next-fg">{{ op.label }}</span>
                  <span class="block text-next-2xs text-next-muted-foreground">{{ getVariableIconLabel(op.outputType) }}</span>
                  <!-- Disambiguating description (e.g. "To number" vs "Length"). -->
                  <span v-if="op.description" class="block text-next-2xs text-next-muted-foreground">{{ op.description }}</span>
                </span>
                <span class="text-next-primary" :title="getVariableIconLabel(op.outputType)">
                  <Icon :name="getVariableIconName(op.outputType)" />
                </span>
              </button>
            </div>
          </div>
        </template>
      </DropdownMenu>
    </div>

    <!-- Empty -->
    <p
      v-if="!pipeline.length"
      class="rounded-next-md border border-dashed border-next-border px-next-3 py-next-3 text-next-sm text-next-muted-foreground"
    >
      {{ t('editor.pipeline.empty', 'No operations. Add the first transformation to reshape the value.') }}
    </p>

    <!-- Steps: compact chips by default; the full config shows only for the step
         currently being added/edited. -->
    <ol v-else class="flex flex-col gap-next-2">
      <li v-for="(step, index) in pipeline" :key="step.stepId">
        <!-- EDIT MODE: full operation config -->
        <div
          v-if="editingStepId === step.stepId"
          class="flex flex-col gap-next-3 rounded-next-lg border border-next-primary bg-next-card p-next-3"
        >
          <div class="flex items-center justify-between gap-next-2">
            <p class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('editor.pipeline.step', 'Step {index}', { index: index + 1 }) }}</p>
            <div class="flex items-center gap-next-1">
              <Button size="sm" variant="secondary" type="button" leading-icon="check" @click="editingStepId = null">
                {{ t('editor.pipeline.done', 'Done') }}
              </Button>
              <Button size="icon" variant="ghost" type="button" :aria-label="t('editor.pipeline.removeStep', 'Remove step')" @click="removeStep(step.stepId)">
                <Icon name="trash" />
              </Button>
            </div>
          </div>

          <Select
            :model-value="step.operationId"
            :options="operationOptionsForStep(index)"
            :placeholder="t('editor.pipeline.selectOperation', 'Select an operation')"
            :aria-label="t('editor.pipeline.operation', 'Operation')"
            @update:model-value="(id) => id && updateOperation(step.stepId, id as string)"
          />

          <!-- Per-arg inputs -->
          <div v-if="opArgs(step).length" class="flex flex-col gap-next-3">
            <div
              v-for="arg in opArgs(step)"
              :key="arg.id"
              class="flex flex-col gap-next-1"
            >
              <label class="flex items-center gap-next-1 text-next-xs font-next-medium text-next-fg">
                {{ arg.label }}
                <Badge variant="neutral" size="sm" :icon="getArgumentIconName(arg.type)">{{ arg.type }}</Badge>
              </label>
              <!-- VALUE-TYPED args (text/number/boolean/date): a value-or-variable editor when the
                   host enables it (provides the `argVariable` slot) AND we are within the depth cap,
                   else the literal control — BYTE-IDENTICAL to before (PipelineArgLiteralInput holds
                   the original controls). option/map/rules/select args are NEVER variable-able. -->
              <template v-if="isValueTypedArg(arg)">
                <slot
                  v-if="canOfferArgVariable"
                  name="argVariable"
                  :arg="arg"
                  :value="step.args[arg.id]"
                  :depth="argDepth + 1"
                  :set-value="(v: VariableArgValue) => updateArg(step.stepId, arg.id, v)"
                  :disabled="false"
                />
                <PipelineArgLiteralInput
                  v-else
                  :arg="arg"
                  :value="step.args[arg.id]"
                  @update:value="(v) => updateArg(step.stepId, arg.id, v)"
                />
              </template>
              <Select
                v-else-if="arg.type === 'select'"
                :model-value="String(step.args[arg.id] ?? '')"
                :options="(arg.options || []).map((o) => ({ value: o.value, label: o.label }))"
                :placeholder="arg.placeholder"
                :aria-label="arg.label"
                @update:model-value="(v) => updateArg(step.stepId, arg.id, (v as string) ?? '')"
              />
              <!-- sourceOption — ONE value picked from the SOURCE variable's options. -->
              <Select
                v-else-if="arg.type === 'sourceOption'"
                :model-value="String(step.args[arg.id] ?? '')"
                :options="sourceSelectOptions"
                :placeholder="arg.placeholder"
                :aria-label="arg.label"
                @update:model-value="(v) => updateArg(step.stepId, arg.id, (v as string) ?? '')"
              />
              <!-- sourceOptions — MANY values from the source options (selection cards). -->
              <SegmentedControl
                v-else-if="arg.type === 'sourceOptions'"
                :model-value="sourceOptionsValue(step, arg.id)"
                :options="sourceSelectOptions"
                multiple
                select-all
                :columns="1"
                size="sm"
                :aria-label="arg.label"
                @update:model-value="(v) => updateArg(step.stepId, arg.id, (v as string[]) ?? [])"
              />
              <!-- sourceMap — ONE typed target value PER source option (option → value). -->
              <div
                v-else-if="arg.type === 'sourceMap'"
                class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted p-next-2"
              >
                <div
                  v-for="option in sourceOptions ?? []"
                  :key="option.value"
                  class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1.4fr)] items-center gap-next-2"
                >
                  <span class="truncate text-next-sm text-next-fg" :title="option.label">{{ option.label }}</span>
                  <Icon name="arrow-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
                  <!-- enum — the mapped TARGET is one of the DESTINATION field's choices. -->
                  <Select
                    v-if="arg.mapType === 'enum'"
                    :model-value="String(sourceMapValue(step, arg.id)[option.value] ?? '')"
                    :options="targetSelectOptions"
                    :placeholder="t('editor.pipeline.selectChoice', 'Select a choice')"
                    :aria-label="`${arg.label}: ${option.label}`"
                    @update:model-value="(v) => setMapEntry(step, arg.id, option.value, (v as string) ?? '')"
                  />
                  <NumberInput
                    v-else-if="arg.mapType === 'number'"
                    :model-value="sourceMapValue(step, arg.id)[option.value] == null || sourceMapValue(step, arg.id)[option.value] === '' ? null : Number(sourceMapValue(step, arg.id)[option.value])"
                    :aria-label="`${arg.label}: ${option.label}`"
                    @update:model-value="(v: number | null) => setMapEntry(step, arg.id, option.value, v)"
                  />
                  <div v-else-if="arg.mapType === 'date'" class="[&>div]:w-full">
                    <DatePicker
                      :model-value="typeof sourceMapValue(step, arg.id)[option.value] === 'string' && sourceMapValue(step, arg.id)[option.value] !== '' ? String(sourceMapValue(step, arg.id)[option.value]) : null"
                      :aria-label="`${arg.label}: ${option.label}`"
                      @update:model-value="(v: string | null) => setMapEntry(step, arg.id, option.value, v)"
                    />
                  </div>
                  <TextInput
                    v-else
                    :model-value="String(sourceMapValue(step, arg.id)[option.value] ?? '')"
                    :aria-label="`${arg.label}: ${option.label}`"
                    @update:model-value="(v: string) => setMapEntry(step, arg.id, option.value, v)"
                  />
                </div>
                <p v-if="!(sourceOptions ?? []).length" class="text-next-xs text-next-muted-foreground">
                  {{ t('editor.pipeline.noSourceOptions', 'This variable has no options to map.') }}
                </p>
              </div>
              <!-- choiceRules — a repeatable list of when (text) → then (target choice) rows. -->
              <div
                v-else-if="arg.type === 'choiceRules'"
                class="flex flex-col gap-next-2 rounded-next-md border border-next-border bg-next-muted p-next-2"
              >
                <div
                  v-for="(rule, ruleIndex) in choiceRulesValue(step, arg.id)"
                  :key="ruleIndex"
                  class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1.4fr)_auto] items-center gap-next-2"
                >
                  <TextInput
                    :model-value="rule.when"
                    :placeholder="t('editor.pipeline.choiceWhen', 'When text is…')"
                    :aria-label="t('editor.pipeline.choiceWhenLabel', 'Rule {index}: when', { index: ruleIndex + 1 })"
                    @update:model-value="(v: string) => setChoiceRule(step, arg.id, ruleIndex, 'when', v)"
                  />
                  <Icon name="arrow-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
                  <Select
                    :model-value="rule.then"
                    :options="targetSelectOptions"
                    :placeholder="t('editor.pipeline.selectChoice', 'Select a choice')"
                    :aria-label="t('editor.pipeline.choiceThenLabel', 'Rule {index}: then', { index: ruleIndex + 1 })"
                    @update:model-value="(v) => setChoiceRule(step, arg.id, ruleIndex, 'then', (v as string) ?? '')"
                  />
                  <Button
                    size="icon-xs"
                    variant="ghost"
                    type="button"
                    :aria-label="t('editor.pipeline.removeRule', 'Remove rule')"
                    @click="removeChoiceRule(step, arg.id, ruleIndex)"
                  >
                    <Icon name="x" />
                  </Button>
                </div>
                <div>
                  <Button
                    size="sm"
                    variant="secondary"
                    type="button"
                    leading-icon="plus"
                    @click="addChoiceRule(step, arg.id)"
                  >
                    {{ t('editor.pipeline.addRule', 'Add rule') }}
                  </Button>
                </div>
                <p class="text-next-xs text-next-muted-foreground">
                  {{ t('editor.pipeline.choiceRulesHint', 'Unmatched text uses the fallback choice.') }}
                </p>
              </div>
              <!-- choiceFallback — a single required DESTINATION choice. -->
              <Select
                v-else-if="arg.type === 'choiceFallback'"
                :model-value="String(step.args[arg.id] ?? '')"
                :options="targetSelectOptions"
                :placeholder="t('editor.pipeline.selectChoice', 'Select a choice')"
                :aria-label="arg.label"
                @update:model-value="(v) => updateArg(step.stepId, arg.id, (v as string) ?? '')"
              />
              <!-- Optional persistent hint (e.g. the date_format safe tokens). -->
              <p v-if="arg.hint" class="text-next-xs text-next-muted-foreground">{{ arg.hint }}</p>
            </div>
          </div>
        </div>

        <!-- CHIP MODE: compact summary (op name, result type, args name/type/value) -->
        <div
          v-else
          class="flex items-start gap-next-2 rounded-next-lg border border-next-border bg-next-card px-next-2_5 py-next-2"
        >
          <button
            type="button"
            class="flex min-w-0 flex-1 flex-wrap items-center gap-next-2 text-left"
            :aria-label="t('editor.pipeline.editStep', 'Edit step {index}: {label}', { index: index + 1, label: opLabel(step) })"
            @click="editingStepId = step.stepId"
          >
            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-next-full bg-next-muted text-next-2xs font-next-semibold text-next-muted-foreground">{{ index + 1 }}</span>
            <span class="text-next-sm font-next-semibold text-next-fg">{{ opLabel(step) }}</span>
            <span class="inline-flex items-center gap-next-1" :title="getVariableIconLabel(stepOutputType(step))">
              <Icon name="arrow-right" class="text-next-muted-foreground" />
              <Icon :name="getVariableIconName(stepOutputType(step))" class="text-next-primary" />
              <span class="text-next-xs text-next-muted-foreground">{{ getVariableIconLabel(stepOutputType(step)) }}</span>
            </span>
            <Badge
              v-for="arg in opArgs(step)"
              :key="arg.id"
              variant="neutral"
              size="sm"
              :icon="getArgumentIconName(arg.type)"
            >
              {{ arg.label }}: {{ formatArgValue(step, arg.id) }}
            </Badge>
          </button>
          <button
            type="button"
            class="shrink-0 rounded-next-sm p-next-1 text-next-muted-foreground transition-colors duration-[var(--duration-next-fast)] hover:text-next-danger"
            :aria-label="t('editor.pipeline.removeStep', 'Remove step')"
            @click="removeStep(step.stepId)"
          >
            <Icon name="x" />
          </button>
        </div>
      </li>
    </ol>

    <!-- Result type readout -->
    <div class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-muted px-next-3 py-next-2 text-next-sm">
      <span class="text-next-muted-foreground">{{ t('editor.pipeline.resultType', 'Result type:') }}</span>
      <span class="inline-flex items-center gap-next-1 font-next-medium text-next-fg">
        <Icon :name="getVariableIconName(resultType)" />
        {{ getVariableIconLabel(resultType) }}
      </span>
    </div>
  </div>
</template>
