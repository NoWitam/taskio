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
// v-model is the `pipeline` array. The parent owns the base type + catalog. The
// component is presentation + type-flow only; it never serializes.
import { computed, ref } from 'vue';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import Badge from '../../primitives/Badge.vue';
import Select from '../../forms/Select.vue';
import TextInput from '../../forms/TextInput.vue';
import NumberInput from '../../forms/NumberInput.vue';
import Switch from '../../forms/Switch.vue';
import DropdownMenu from '../../overlay/DropdownMenu.vue';
import {
  buildDefaultArgs,
  computeInputType,
  createPipelineStep,
  getArgumentIconName,
  getVariableIconLabel,
  getVariableIconName,
  operationsForType,
  resolveType,
} from './operationHelpers';
import { useI18n } from '../../../app/i18n';
import type {
  VariableOperationDefinition,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';

const props = defineProps<{
  /** Source primitive type the pipeline starts from. */
  baseType: VariablePrimitive;
  /** Operations catalog (all available transforms). */
  catalog: VariableOperationDefinition[];
}>();

const pipeline = defineModel<VariablePipelineStep[]>({ default: () => [] });

const { t } = useI18n();

const resultType = computed<VariablePrimitive>(() =>
  resolveType(props.catalog, props.baseType, pipeline.value),
);
const nextInputType = computed<VariablePrimitive>(() =>
  computeInputType(props.catalog, props.baseType, pipeline.value, pipeline.value.length),
);
const addableOperations = computed(() =>
  operationsForType(props.catalog, nextInputType.value),
);

defineExpose({ resultType });

function stepInputType(stepIndex: number): VariablePrimitive {
  return computeInputType(props.catalog, props.baseType, pipeline.value, stepIndex);
}

function operationOptionsForStep(stepIndex: number) {
  return operationsForType(props.catalog, stepInputType(stepIndex)).map((op) => ({
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
function formatArgValue(step: VariablePipelineStep, argId: string): string {
  const arg = opArgs(step).find((a) => a.id === argId);
  const raw = step.args[argId];
  if (arg?.type === 'boolean') {
    return raw ? t('editor.pipeline.booleanYes', 'yes') : t('editor.pipeline.booleanNo', 'no');
  }
  if (arg?.type === 'select') {
    const opt = (arg.options ?? []).find((o) => o.value === String(raw));
    return opt?.label ?? (raw === '' || raw == null ? '—' : String(raw));
  }
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

function updateArg(stepId: string, argId: string, value: string | number | boolean): void {
  pipeline.value = pipeline.value.map((s) =>
    s.stepId === stepId ? { ...s, args: { ...s.args, [argId]: value } } : s,
  );
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
            :disabled="!addableOperations.length"
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
              <TextInput
                v-if="arg.type === 'text'"
                :model-value="String(step.args[arg.id] ?? '')"
                :placeholder="arg.placeholder"
                @update:model-value="(v: string) => updateArg(step.stepId, arg.id, v)"
              />
              <NumberInput
                v-else-if="arg.type === 'number'"
                :model-value="step.args[arg.id] === '' || step.args[arg.id] == null ? null : Number(step.args[arg.id])"
                :placeholder="arg.placeholder"
                @update:model-value="(v: number | null) => updateArg(step.stepId, arg.id, v ?? 0)"
              />
              <Switch
                v-else-if="arg.type === 'boolean'"
                :model-value="Boolean(step.args[arg.id])"
                :aria-label="arg.label"
                @update:model-value="(v: boolean) => updateArg(step.stepId, arg.id, v)"
              />
              <Select
                v-else-if="arg.type === 'select'"
                :model-value="String(step.args[arg.id] ?? '')"
                :options="(arg.options || []).map((o) => ({ value: o.value, label: o.label }))"
                :placeholder="arg.placeholder"
                :aria-label="arg.label"
                @update:model-value="(v) => updateArg(step.stepId, arg.id, (v as string) ?? '')"
              />
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
