<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import Button from '@/components/ui/Button.vue';
import Icon from '@/components/ui/Icon.vue';
import Badge from '@/components/ui/Badge.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import NumberInput from '@/components/ui/inputs/NumberInput.vue';
import SwitchInput from '@/components/ui/inputs/SwitchInput.vue';
import { getVariableIconName, getVariableIconLabel } from '../utils/variableIcons';
import { buildDefaultArgs, formatArgsLabel, getArgumentIconName } from '../utils/operationHelpers';
import type {
  VariableDefinition,
  VariableFeatureConfig,
  VariableOperationDefinition,
  VariablePipelineStep,
  VariableState,
  VariablePrimitive,
} from '../types/editor';

const props = defineProps<{
  state: VariableState;
  definition?: VariableDefinition;
  feature?: VariableFeatureConfig;
}>();

const emit = defineEmits<{
  (e: 'save', value: VariableState): void;
  (e: 'remove'): void;
}>();

const localState = ref<VariableState>(cloneState(props.state));

watch(
  () => props.state,
  (value) => {
    localState.value = cloneState(value);
  },
  { deep: true }
);

const baseType = computed<VariablePrimitive>(() => props.definition?.type || props.state.type);
const initialName = computed(() => props.definition?.name || props.state.name);
const initialId = computed(() => props.definition?.id || props.state.id);
const operationsCatalog = computed<VariableOperationDefinition[]>(() => props.feature?.operationsCatalog || []);

const resultType = computed<VariablePrimitive>(() =>
  resolveType(operationsCatalog.value, baseType.value, localState.value.pipeline)
);
const nextInputType = computed<VariablePrimitive>(() => computeInputType(localState.value.pipeline.length));
const addableOperations = computed(() =>
  operationsCatalog.value.filter((op) => op.inputTypes.includes(nextInputType.value))
);

function cloneState(state: VariableState): VariableState {
  return {
    id: state.id,
    name: state.name,
    type: state.type,
    locked: state.locked,
    resultType: state.resultType,
    pipeline: state.pipeline ? state.pipeline.map((step) => ({ ...step, args: { ...step.args } })) : [],
  };
}

function resolveType(
  catalog: VariableOperationDefinition[],
  startType: VariablePrimitive,
  pipeline: VariablePipelineStep[]
): VariablePrimitive {
  let current = startType;
  for (const step of pipeline) {
    const def = catalog.find((op) => op.id === step.operationId);
    if (!def) continue;
    current = def.outputType;
  }
  return current;
}

function filteredOperations(stepIndex: number) {
  const inputType = computeInputType(stepIndex);
  return operationsCatalog.value.filter((op) => op.inputTypes.includes(inputType));
}

function computeInputType(stepIndex: number): VariablePrimitive {
  let current = baseType.value;
  for (let i = 0; i < stepIndex; i += 1) {
    const op = operationsCatalog.value.find((item) => item.id === localState.value.pipeline[i].operationId);
    current = op?.outputType || current;
  }
  return current;
}

function createStep(operation: VariableOperationDefinition): VariablePipelineStep {
  return {
    stepId: generateStepId(),
    operationId: operation.id,
    args: buildDefaultArgs(operation.args),
    outputType: operation.outputType,
  };
}

function handleAddOperation(operation: VariableOperationDefinition, closeMenu?: () => void) {
  if (!operation) return;
  localState.value.pipeline.push(createStep(operation));
  closeMenu?.();
}

function generateStepId() {
  return `step_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
}

function removeStep(stepId: string) {
  localState.value.pipeline = localState.value.pipeline.filter((step) => step.stepId !== stepId);
}

function updateOperation(step: VariablePipelineStep, opId: string) {
  const definition = operationsCatalog.value.find((op) => op.id === opId);
  if (!definition) return;
  step.operationId = definition.id;
  step.args = buildDefaultArgs(definition.args);
  step.outputType = definition.outputType;
}

function operationOptions(stepIndex: number) {
  return filteredOperations(stepIndex).map((op) => ({
    label: op.label,
    value: op.id,
    outputType: op.outputType,
    args: op.args || [],
  }));
}

function handleArgChange(step: VariablePipelineStep, argId: string, value: string | number | boolean) {
  step.args[argId] = value;
}

function toggleLock() {
  localState.value.locked = !localState.value.locked;
}

function handleSave() {
  emit('save', {
    ...localState.value,
    resultType: resultType.value,
  });
}

function requestRemove() {
  emit('remove');
}

defineExpose({
  submit: handleSave,
  requestRemove,
});
</script>

<template>
  <div class="space-y-6">
    <div>
      <label class="text-sm font-semibold text-foreground">Wyświetlana nazwa</label>
      <div class="mt-2">
        <TextInput
          v-model="localState.name"
          :disabled="localState.locked"
          class="flex-1"
        >
          <template #right>
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-full text-foreground/70 transition hover:text-foreground"
              @click="toggleLock"
            >
              <Icon :name="localState.locked ? 'lock' : 'lock-open'" size="sm" />
              <span class="sr-only">{{ localState.locked ? 'Odblokuj nazwę' : 'Zablokuj nazwę' }}</span>
            </button>
          </template>
        </TextInput>
      </div>
    </div>

    <div class="rounded-xl border border-border/80 bg-muted/40 p-4 space-y-3">
      <p class="text-xs font-semibold text-foreground/70">Zmienna źródłowa</p>
      <div class="flex items-center gap-3">
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-foreground truncate">{{ initialName }}</p>
        </div>
        <div class="text-primary">
          <Icon :name="getVariableIconName(baseType)" size="sm" />
        </div>
      </div>
    </div>

    <div class="space-y-3">
      <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-semibold text-foreground">Pipeline operacji</p>
        <DropdownMenu>
          <template #activator="{ toggle }">
            <Button
              type="button"
              size="sm"
              variant="secondary"
              :disabled="!addableOperations.length"
              @click.stop="toggle"
            >
              Dodaj operację
            </Button>
          </template>
          <template #default="{ closeMenu }">
            <div class="w-80 max-h-96 overflow-y-auto p-2">
              <p class="px-2 pb-2 text-xs text-muted-foreground flex items-center gap-2">
                Dostępne dla typu
                <span
                  class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-secondary/60 text-foreground"
                  :title="getVariableIconLabel(nextInputType)"
                >
                  <Icon :name="getVariableIconName(nextInputType)" size="sm" />
                  <span class="sr-only">{{ getVariableIconLabel(nextInputType) }}</span>
                </span>
              </p>
              <div v-if="!addableOperations.length" class="px-2 py-4 text-sm text-muted-foreground">
                Brak operacji dla tego typu.
              </div>
              <div v-else class="space-y-1">
                <button
                  v-for="operation in addableOperations"
                  :key="operation.id"
                  type="button"
                  class="w-full rounded-lg border border-border/70 px-3 py-2 text-left hover:border-primary hover:bg-primary/5"
                  @click="handleAddOperation(operation, closeMenu)"
                >
                  <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                      <p class="truncate text-sm font-semibold text-foreground">{{ operation.label }}</p>
                      <p class="text-[11px] text-muted-foreground">{{ formatArgsLabel(operation.args) }}</p>
                    </div>
                    <span
                      class="text-primary"
                      :title="getVariableIconLabel(operation.outputType)"
                    >
                      <Icon :name="getVariableIconName(operation.outputType)" size="sm" />
                      <span class="sr-only">{{ getVariableIconLabel(operation.outputType) }}</span>
                    </span>
                  </div>
                </button>
              </div>
            </div>
          </template>
        </DropdownMenu>
      </div>

      <div v-if="!localState.pipeline.length" class="rounded-lg border border-dashed border-border/80 p-4 text-sm text-muted-foreground">
        Brak operacji. Dodaj pierwszą transformację, aby przekształcić wartość zmiennej.
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="(step, index) in localState.pipeline"
          :key="step.stepId"
          class="rounded-xl border border-border/70 bg-background p-4 space-y-4"
        >
          <div class="flex items-center justify-between gap-4">
            <p class="text-xs font-medium text-muted-foreground">Krok {{ index + 1 }}</p>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              class="h-9 w-9 !p-0"
              @click="removeStep(step.stepId)"
            >
              <Icon name="trash" :size="16" />
              <span class="sr-only">Usuń krok</span>
            </Button>
          </div>

          <SelectInput
            :model-value="step.operationId"
            :options="operationOptions(index)"
            placeholder="Wybierz operację"
            @update:model-value="(opId) => opId && updateOperation(step, opId as string)"
          >
            <template #selected="{ item }">
              <div class="flex w-full items-center justify-between gap-3">
                <div class="min-w-0 truncate text-sm font-semibold text-foreground">
                  <span class="flex items-center gap-2">
                    {{ item?.label || 'Wybierz operację' }}
                    <template v-for="arg in item?.args ?? []" :key="arg?.id">
                      <Badge tone="primary" class="flex items-center gap-1 text-[11px]">
                        {{ arg?.label }}
                        <Icon :name="getArgumentIconName(arg?.type)" size="xs" />
                      </Badge>
                    </template>
                  </span>
                </div>
                <span
                  class="text-primary"
                  :title="getVariableIconLabel(item?.outputType || step.outputType)"
                >
                  <Icon :name="getVariableIconName(item?.outputType || step.outputType)" size="sm" />
                  <span class="sr-only">{{ getVariableIconLabel(item?.outputType || step.outputType) }}</span>
                </span>
              </div>
            </template>
            <template #item="{ item }">
              <div class="flex w-full items-center justify-between gap-3">
                <div class="min-w-0 truncate text-sm font-semibold text-foreground">
                  <span class="flex items-center gap-2">
                    {{ item.label }}
                    <template v-for="arg in item.args ?? []" :key="arg.id">
                      <Badge tone="secondary" class="flex items-center gap-1 text-[11px]">
                        {{ arg.label }}
                        <Icon :name="getArgumentIconName(arg.type)" size="xs" />
                      </Badge>
                    </template>
                    <span v-if="!item.args?.length" class="text-[11px] text-muted-foreground">Brak argumentów</span>
                  </span>
                </div>
                <span
                  class="text-primary"
                  :title="getVariableIconLabel(item.outputType)"
                >
                  <Icon :name="getVariableIconName(item.outputType)" size="sm" />
                  <span class="sr-only">{{ getVariableIconLabel(item.outputType) }}</span>
                </span>
              </div>
            </template>
          </SelectInput>

          <div v-if="operationsCatalog.find((op) => op.id === step.operationId)?.args?.length" class="space-y-3">
            <div
              v-for="arg in operationsCatalog.find((op) => op.id === step.operationId)?.args || []"
              :key="arg.id"
              class="space-y-2"
            >
              <label class="text-xs font-medium text-foreground/80">{{ arg.label }}</label>
              <template v-if="arg.type === 'text'">
                <TextInput
                  v-model="step.args[arg.id]"
                  :placeholder="arg.placeholder"
                  @update:model-value="(value: string) => handleArgChange(step, arg.id, value)"
                />
              </template>
              <template v-else-if="arg.type === 'number'">
                <NumberInput
                  v-model="step.args[arg.id]"
                  :placeholder="arg.placeholder"
                  @update:model-value="(value: number) => handleArgChange(step, arg.id, value)"
                />
              </template>
              <template v-else-if="arg.type === 'boolean'">
                <SwitchInput
                  v-model="step.args[arg.id]"
                  @update:model-value="(value: boolean) => handleArgChange(step, arg.id, value)"
                />
              </template>
              <template v-else-if="arg.type === 'select'">
                <SelectInput
                  v-model="step.args[arg.id]"
                  :options="(arg.options || []).map((option) => ({ label: option.label, value: option.value }))"
                  :placeholder="arg.placeholder"
                  @update:model-value="(value: string) => handleArgChange(step, arg.id, value)"
                />
              </template>
              <template v-else>
                <TextInput
                  v-model="step.args[arg.id]"
                  :placeholder="arg.placeholder"
                  @update:model-value="(value: string) => handleArgChange(step, arg.id, value)"
                />
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>
