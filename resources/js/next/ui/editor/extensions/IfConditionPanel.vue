<script setup lang="ts">
// IfConditionPanel — the Modal for editing an IF / ELSE-IF branch condition.
// Ports the legacy IfBlockPanel condition logic: a Select of predefined variables
// + the SHARED VariablePipelineEditor. The condition is INVALID unless the
// pipeline's resultType === 'boolean' — the panel shows validation and BLOCKS
// saving an invalid condition (mirrors legacy `isBranchValid` / save guard).
import { computed, ref, watch } from 'vue';
import Modal from '../../overlay/Modal.vue';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import Select from '../../forms/Select.vue';
import VariablePipelineEditor from './VariablePipelineEditor.vue';
import { resolveType } from './operationHelpers';
import { useI18n } from '../../../app/i18n';
import type {
  IfConditionState,
  VariableDefinition,
  VariableOperationDefinition,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';

const props = defineProps<{
  /** Current condition (or null for a fresh one). */
  condition: IfConditionState | null;
  definitions: VariableDefinition[];
  catalog: VariableOperationDefinition[];
}>();

const emit = defineEmits<{
  (e: 'save', condition: IfConditionState): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const variableId = ref<string | null>(null);
const pipeline = ref<VariablePipelineStep[]>([]);

watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    variableId.value = props.condition?.variableId || null;
    pipeline.value = (props.condition?.pipeline ?? []).map((s) => ({ ...s, args: { ...s.args } }));
  },
  { immediate: true },
);

const variableOptions = computed(() =>
  props.definitions.map((d) => ({ value: d.id, label: d.name })),
);

const baseType = computed<VariablePrimitive>(() => {
  const def = props.definitions.find((d) => d.id === variableId.value);
  return def?.type ?? 'boolean';
});

const resultType = computed<VariablePrimitive>(() =>
  variableId.value ? resolveType(props.catalog, baseType.value, pipeline.value) : 'text',
);

const isValid = computed(() => Boolean(variableId.value) && resultType.value === 'boolean');

function onVariableChange(value: string | null): void {
  variableId.value = value;
  // Reset the pipeline when the source variable changes (legacy parity).
  pipeline.value = [];
}

function save(): void {
  if (!isValid.value || !variableId.value) return;
  emit('save', {
    variableId: variableId.value,
    pipeline: pipeline.value,
    resultType: 'boolean',
  });
  open.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="t('editor.ifCondition.editTitle', 'Edit condition')">
    <template #title>{{ t('editor.ifCondition.editTitle', 'Edit condition') }}</template>

    <div class="flex flex-col gap-next-5">
      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">{{ t('editor.ifCondition.conditionVariable', 'Condition variable') }}</label>
        <Select
          :model-value="variableId"
          :options="variableOptions"
          :placeholder="t('editor.ifCondition.selectVariable', 'Select a variable')"
          :disabled="!variableOptions.length"
          :aria-label="t('editor.ifCondition.conditionVariable', 'Condition variable')"
          @update:model-value="(v) => onVariableChange(v as string | null)"
        />
        <p v-if="!variableOptions.length" class="text-next-xs text-next-muted-foreground">
          {{ t('editor.ifCondition.noVariables', 'Add variables to build conditions.') }}
        </p>
        <p v-else class="text-next-xs text-next-muted-foreground">
          {{ t('editor.ifCondition.mustBeBoolean', 'The condition must end with a boolean type.') }}
        </p>
      </div>

      <VariablePipelineEditor
        v-if="variableId"
        v-model="pipeline"
        :base-type="baseType"
        :catalog="catalog"
        :source-options="definitions.find((d) => d.id === variableId)?.options"
      />

      <!-- Validation status -->
      <div
        class="flex items-center gap-next-2 rounded-next-md border px-next-3 py-next-2 text-next-sm"
        :class="isValid ? 'border-next-success/40 bg-next-success-subtle' : 'border-next-warning/40 bg-next-warning-subtle'"
      >
        <Icon
          :name="isValid ? 'check-circle' : 'alert-triangle'"
          :class="isValid ? 'text-next-success' : 'text-next-warning'"
        />
        <span class="text-next-fg">
          {{ isValid ? t('editor.ifCondition.valid', 'The condition returns a boolean.') : t('editor.ifCondition.invalid', 'Reach a boolean result by choosing a variable and operations.') }}
        </span>
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">{{ t('editor.ifCondition.cancel', 'Cancel') }}</Button>
      <Button variant="primary" type="button" :disabled="!isValid" @click="save">{{ t('editor.ifCondition.saveCondition', 'Save condition') }}</Button>
    </template>
  </Modal>
</template>
