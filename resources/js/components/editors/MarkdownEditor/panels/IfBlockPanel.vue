<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import type { JSONContent } from '@tiptap/core';
import Button from '@/components/ui/Button.vue';
import Icon from '@/components/ui/Icon.vue';
import Badge from '@/components/ui/Badge.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import SelectInput from '@/components/ui/inputs/SelectInput.vue';
import TextInput from '@/components/ui/inputs/TextInput.vue';
import NumberInput from '@/components/ui/inputs/NumberInput.vue';
import SwitchInput from '@/components/ui/inputs/SwitchInput.vue';
import type {
  EditorConfig,
  IfBlockState,
  IfBranchState,
  IfBranchKind,
  IfConditionState,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
  VariableOperationDefinition,
  VariablePipelineStep,
  VariablePrimitive,
  VariableDefinition,
} from '../types/editor';
import { parseMarkdown } from '../utils/parse';
import { serializeDocument } from '../utils/serialize';
import { getVariableIconLabel, getVariableIconName } from '../utils/variableIcons';
import { buildDefaultArgs, formatArgsLabel, getArgumentIconName } from '../utils/operationHelpers';

const MarkdownEditor = defineAsyncComponent(() => import('../MarkdownEditor.vue'));

const props = defineProps<{
  state: IfBlockState;
  feature?: IfBlockFeatureConfig;
  editorConfig: EditorConfig;
  variableFeature?: VariableFeatureConfig;
}>();

const emit = defineEmits<{
  (e: 'save', value: IfBlockState): void;
}>();

const localState = ref<IfBlockState>(cloneBlockState(props.state));
const branchDrafts = ref<Record<string, string>>(buildDrafts(localState.value.branches));
const saveError = ref<string | null>(null);

watch(
  () => props.state,
  (value) => {
    localState.value = cloneBlockState(value);
    branchDrafts.value = buildDrafts(localState.value.branches);
  },
  { deep: true }
);

const mergedVariableFeature = computed<VariableFeatureConfig | undefined>(
  () => props.variableFeature || props.editorConfig.features.variables
);
const variableDefinitions = computed<VariableDefinition[]>(
  () => mergedVariableFeature.value?.variables || []
);
const operationsCatalog = computed<VariableOperationDefinition[]>(
  () => mergedVariableFeature.value?.operationsCatalog || []
);
const variableOptions = computed(() =>
  variableDefinitions.value.map((variable) => ({
    label: variable.name,
    value: variable.id,
    type: variable.type,
  }))
);
const elseIfCount = computed(() => localState.value.branches.filter((branch) => branch.kind === 'else-if').length);
const hasElseBranch = computed(() => localState.value.branches.some((branch) => branch.kind === 'else'));
const maxElseIf = computed(() => props.feature?.maxElseIf ?? Number.POSITIVE_INFINITY);
const canAddElseIf = computed(() => elseIfCount.value < maxElseIf.value);
const canAddElse = computed(() => !hasElseBranch.value);
const hasVariableSupport = computed(() => Boolean(variableOptions.value.length));

function cloneBlockState(state: IfBlockState): IfBlockState {
  return {
    id: state.id,
    branches: state.branches.map(cloneBranch),
  };
}

function cloneBranch(branch: IfBranchState): IfBranchState {
  return {
    id: branch.id,
    kind: branch.kind,
    condition: branch.condition
      ? {
          variableId: branch.condition.variableId,
          pipeline: branch.condition.pipeline.map((step) => ({
            ...step,
            args: { ...step.args },
          })),
          resultType: 'boolean',
        }
      : undefined,
    content: branch.content ? cloneContent(branch.content) : createEmptyContent(),
  };
}

function cloneContent(content: JSONContent) {
  return JSON.parse(JSON.stringify(content));
}

function createEmptyContent(): JSONContent {
  return { type: 'paragraph', content: [] };
}

function buildDrafts(branches: IfBranchState[]) {
  return branches.reduce<Record<string, string>>((acc, branch) => {
    acc[branch.id] = serializeDocument(branch.content || createEmptyContent());
    return acc;
  }, {});
}

function branchLabel(kind: IfBranchKind) {
  if (kind === 'if') return 'Sekcja IF';
  if (kind === 'else-if') return 'Sekcja ELSE IF';
  return 'Sekcja ELSE';
}

function handleBranchContentChange(branchId: string, value: string) {
  branchDrafts.value[branchId] = value;
  const target = localState.value.branches.find((branch) => branch.id === branchId);
  if (!target) return;
  target.content = parseMarkdown(value);
}

function handleVariableChange(branch: IfBranchState, variableId: string | null) {
  if (branch.kind === 'else') return;
  if (!variableId) {
    branch.condition = undefined;
    return;
  }
  branch.condition = {
    variableId,
    pipeline: [],
    resultType: 'boolean',
  };
}

function createDefaultCondition(): IfConditionState | undefined {
  const chosen = variableDefinitions.value.find((variable) => variable.type === 'boolean') || variableDefinitions.value[0];
  if (!chosen) return undefined;
  return {
    variableId: chosen.id,
    pipeline: [],
    resultType: 'boolean',
  };
}

function addElseIfBranch() {
  if (!canAddElseIf.value) return;
  const branch: IfBranchState = {
    id: generateBranchId('elseif'),
    kind: 'else-if',
    content: createEmptyContent(),
  };
  const condition = createDefaultCondition();
  if (condition) branch.condition = condition;
  insertBeforeElse(branch);
  branchDrafts.value[branch.id] = serializeDocument(branch.content);
}

function addElseBranch() {
  if (!canAddElse.value) return;
  const branch: IfBranchState = {
    id: generateBranchId('else'),
    kind: 'else',
    content: createEmptyContent(),
  };
  localState.value.branches.push(branch);
  branchDrafts.value[branch.id] = serializeDocument(branch.content);
}

function insertBeforeElse(branch: IfBranchState) {
  const elseIndex = localState.value.branches.findIndex((item) => item.kind === 'else');
  if (elseIndex === -1) {
    localState.value.branches.push(branch);
  } else {
    localState.value.branches.splice(elseIndex, 0, branch);
  }
}

function handleRemoveBranch(branchId: string) {
  const index = localState.value.branches.findIndex((branch) => branch.id === branchId);
  if (index < 0) return;
  if (localState.value.branches[index].kind === 'if') return;
  localState.value.branches.splice(index, 1);
  delete branchDrafts.value[branchId];
}

function generateBranchId(prefix: string) {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
}

function getVariableType(branch: IfBranchState): VariablePrimitive | null {
  if (!branch.condition?.variableId) return null;
  const variable = variableDefinitions.value.find((definition) => definition.id === branch.condition?.variableId);
  return variable?.type || null;
}

function computeInputType(branch: IfBranchState, stepIndex: number): VariablePrimitive | null {
  const baseType = getVariableType(branch);
  if (!baseType) return null;
  let current: VariablePrimitive = baseType;
  if (!branch.condition) return current;
  for (let i = 0; i < stepIndex; i += 1) {
    const step = branch.condition.pipeline[i];
    if (!step) break;
    const op = operationsCatalog.value.find((item) => item.id === step.operationId);
    current = op?.outputType || current;
  }
  return current;
}

function branchResultType(branch: IfBranchState): VariablePrimitive | null {
  if (branch.kind === 'else') return 'boolean';
  if (!branch.condition) return null;
  const inputType = computeInputType(branch, branch.condition.pipeline.length);
  return inputType;
}

function addableOperations(branch: IfBranchState) {
  if (branch.kind === 'else') return [] as VariableOperationDefinition[];
  const condition = branch.condition;
  if (!condition) return [] as VariableOperationDefinition[];
  const input = computeInputType(branch, condition.pipeline.length);
  if (!input) return [] as VariableOperationDefinition[];
  return operationsCatalog.value.filter((op) => op.inputTypes.includes(input));
}

function createStep(operation: VariableOperationDefinition): VariablePipelineStep {
  return {
    stepId: generateBranchId('step'),
    operationId: operation.id,
    args: buildDefaultArgs(operation.args),
    outputType: operation.outputType,
  };
}

function handleAddOperation(branch: IfBranchState, operation: VariableOperationDefinition, closeMenu?: () => void) {
  if (branch.kind === 'else') return;
  if (!branch.condition) return;
  branch.condition.pipeline.push(createStep(operation));
  closeMenu?.();
}

function removeStep(branch: IfBranchState, stepId: string) {
  if (branch.kind === 'else' || !branch.condition) return;
  branch.condition.pipeline = branch.condition.pipeline.filter((step) => step.stepId !== stepId);
}

function updateOperation(branch: IfBranchState, step: VariablePipelineStep, opId: string) {
  if (!branch.condition) return;
  const definition = operationsCatalog.value.find((op) => op.id === opId);
  if (!definition) return;
  step.operationId = definition.id;
  step.args = buildDefaultArgs(definition.args);
  step.outputType = definition.outputType;
}

function handleArgChange(step: VariablePipelineStep, argId: string, value: string | number | boolean) {
  step.args[argId] = value;
}

function operationOptions(branch: IfBranchState, stepIndex: number) {
  if (branch.kind === 'else') return [];
  const inputType = computeInputType(branch, stepIndex);
  if (!inputType) return [];
  return operationsCatalog.value
    .filter((op) => op.inputTypes.includes(inputType))
    .map((op) => ({
      label: op.label,
      value: op.id,
      outputType: op.outputType,
      args: op.args || [],
    }));
}

function canAddOperations(branch: IfBranchState) {
  if (branch.kind === 'else') return false;
  if (!branch.condition?.variableId) return false;
  return Boolean(addableOperations(branch).length);
}

function isBranchValid(branch: IfBranchState) {
  if (branch.kind === 'else') return true;
  if (!branch.condition?.variableId) return false;
  const result = branchResultType(branch);
  return result === 'boolean';
}

function handleSave() {
  saveError.value = null;
  for (const branch of localState.value.branches) {
    if (branch.kind === 'else') continue;
    if (!branch.condition?.variableId) {
      saveError.value = 'Uzupełnij zmienną dla każdej sekcji IF oraz ELSE IF.';
      return;
    }
    if (branchResultType(branch) !== 'boolean') {
      saveError.value = 'Każdy warunek musi zwracać typ boolean.';
      return;
    }
    branch.condition.resultType = 'boolean';
  }
  emit('save', cloneBlockState(localState.value));
}

const conditionHelperText = computed(() => {
  if (!hasVariableSupport.value) return 'Dodaj zmienne, aby tworzyć warunki.';
  return 'Warunek musi kończyć się typem boolean.';
});

function branchStatusIcon(branch: IfBranchState) {
  return isBranchValid(branch) ? 'check-circle-2' : 'alert-triangle';
}

function branchStatusTone(branch: IfBranchState) {
  return isBranchValid(branch) ? 'text-emerald-600' : 'text-amber-600';
}

defineExpose({
  submit: handleSave,
});
</script>

<template>
  <div class="space-y-6">
    <div class="rounded-xl border border-border/70 bg-muted/40 p-4 space-y-3">
      <div class="flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" variant="secondary" :disabled="!canAddElseIf" @click="addElseIfBranch">
          + Dodaj ELSE IF
        </Button>
        <Button type="button" size="sm" variant="secondary" :disabled="!canAddElse" @click="addElseBranch">
          + Dodaj ELSE
        </Button>
      </div>
      <p class="text-xs text-muted-foreground">
        Blok musi posiadać jedną sekcję IF, może mieć wiele ELSE IF oraz maksymalnie jedną sekcję ELSE.
      </p>
    </div>

    <div v-for="(branch, index) in localState.branches" :key="branch.id" class="rounded-2xl border border-border/70 bg-background p-4 space-y-5">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-primary/80">{{ branchLabel(branch.kind) }}</p>
          <p class="text-[11px] text-muted-foreground">Sekcja {{ index + 1 }}</p>
        </div>
        <Button
          v-if="branch.kind !== 'if'"
          type="button"
          variant="ghost"
          size="sm"
          class="h-9"
          @click="handleRemoveBranch(branch.id)"
        >
          Usuń sekcję
        </Button>
      </div>

      <div v-if="branch.kind !== 'else'" class="space-y-4">
        <div class="space-y-2">
          <label class="text-xs font-semibold uppercase tracking-wide text-foreground/70">Warunek</label>
          <SelectInput
            :model-value="branch.condition?.variableId || null"
            :options="variableOptions"
            placeholder="Wybierz zmienną"
            :disabled="!hasVariableSupport"
            @update:model-value="(value) => handleVariableChange(branch, value as string | null)"
          >
            <template #selected="{ item }">
              <div class="flex items-center gap-2">
                <span v-if="item" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-secondary/60 text-foreground" :title="getVariableIconLabel(item?.type)">
                  <Icon :name="getVariableIconName(item?.type || 'boolean')" size="sm" />
                </span>
                <span class="text-sm font-semibold text-foreground">{{ item?.label || 'Wybierz zmienną' }}</span>
              </div>
            </template>
            <template #item="{ item }">
              <div class="flex w-full items-center justify-between gap-3">
                <span class="truncate text-sm font-semibold text-foreground">{{ item.label }}</span>
                <span class="text-primary" :title="getVariableIconLabel(item.type)">
                  <Icon :name="getVariableIconName(item.type)" size="sm" />
                </span>
              </div>
            </template>
          </SelectInput>
          <p class="text-xs text-muted-foreground">{{ conditionHelperText }}</p>
        </div>

        <div class="space-y-3 rounded-xl border border-border/60 p-4">
          <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold text-foreground">Pipeline warunku</p>
            <DropdownMenu>
              <template #activator="{ toggle }">
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  :disabled="!canAddOperations(branch)"
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
                      :title="getVariableIconLabel(computeInputType(branch, branch.condition?.pipeline.length || 0) || 'boolean')"
                    >
                      <Icon :name="getVariableIconName(computeInputType(branch, branch.condition?.pipeline.length || 0) || 'boolean')" size="sm" />
                    </span>
                  </p>
                  <div v-if="!addableOperations(branch).length" class="px-2 py-4 text-sm text-muted-foreground">
                    Brak operacji dla aktualnego typu.
                  </div>
                  <div v-else class="space-y-1">
                    <button
                      v-for="operation in addableOperations(branch)"
                      :key="operation.id"
                      type="button"
                      class="w-full rounded-lg border border-border/70 px-3 py-2 text-left hover:border-primary hover:bg-primary/5"
                      @click="handleAddOperation(branch, operation, closeMenu)"
                    >
                      <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                          <p class="truncate text-sm font-semibold text-foreground">{{ operation.label }}</p>
                          <p class="text-[11px] text-muted-foreground">{{ formatArgsLabel(operation.args) }}</p>
                        </div>
                        <span class="text-primary" :title="getVariableIconLabel(operation.outputType)">
                          <Icon :name="getVariableIconName(operation.outputType)" size="sm" />
                        </span>
                      </div>
                    </button>
                  </div>
                </div>
              </template>
            </DropdownMenu>
          </div>

          <div v-if="!branch.condition?.pipeline.length" class="rounded-lg border border-dashed border-border/80 p-4 text-sm text-muted-foreground">
            Brak operacji. Dodaj transformację aby otrzymać wartość boolean.
          </div>

          <div v-else class="space-y-3">
            <div
              v-for="(step, stepIndex) in branch.condition?.pipeline"
              :key="step.stepId"
              class="rounded-xl border border-border/70 bg-background p-4 space-y-4"
            >
              <div class="flex items-center justify-between gap-4">
                <p class="text-xs font-medium text-muted-foreground">Krok {{ stepIndex + 1 }}</p>
                <Button type="button" variant="ghost" size="sm" class="h-9 w-9 !p-0" @click="removeStep(branch, step.stepId)">
                  <Icon name="trash" :size="16" />
                  <span class="sr-only">Usuń krok</span>
                </Button>
              </div>

              <SelectInput
                :model-value="step.operationId"
                :options="operationOptions(branch, stepIndex)"
                placeholder="Wybierz operację"
                @update:model-value="(opId) => opId && updateOperation(branch, step, opId as string)"
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
                    <span class="text-primary" :title="getVariableIconLabel(item?.outputType || step.outputType)">
                      <Icon :name="getVariableIconName(item?.outputType || step.outputType)" size="sm" />
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
                    <span class="text-primary" :title="getVariableIconLabel(item.outputType)">
                      <Icon :name="getVariableIconName(item.outputType)" size="sm" />
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

        <div class="rounded-lg border border-border/70 bg-muted/30 p-3 flex items-center gap-3">
          <Icon :name="branchStatusIcon(branch)" size="sm" :class="branchStatusTone(branch)" />
          <div class="text-xs text-muted-foreground">
            <p v-if="isBranchValid(branch)">Warunek zwraca boolean.</p>
            <p v-else>Uzyskaj wynik boolean poprzez konfigurację zmiennej i operacji.</p>
          </div>
        </div>
      </div>

      <div class="space-y-2">
        <label class="text-xs font-semibold uppercase tracking-wide text-foreground/70">Treść sekcji</label>
        <MarkdownEditor
          :model-value="branchDrafts[branch.id]"
          :config="props.editorConfig"
          placeholder="Wstaw treść dla tej gałęzi..."
          class="border border-border rounded-xl"
          @update:model-value="(value: string) => handleBranchContentChange(branch.id, value)"
        />
        <p class="text-xs text-muted-foreground">Edytor posiada identyczną konfigurację jak główny dokument.</p>
      </div>
    </div>

    <p v-if="saveError" class="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
      {{ saveError }}
    </p>
  </div>
</template>
