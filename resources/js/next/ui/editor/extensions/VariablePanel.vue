<script setup lang="ts">
// VariablePanel — the full edit panel for a `variable` node, hosted inside the
// next `Modal` (user requirement: the variable panel is a MODAL). It ports the
// legacy VariablePanel behavior:
//   • display name + lock toggle,
//   • the source variable (read-only, with its type icon),
//   • the shared VariablePipelineEditor (add-operation pipeline + per-arg inputs),
//   • a computed resultType (output of the last op) recomputed from the catalog.
//
// Emits `save` (full attrs, with recomputed resultType) and `remove`.
import { computed, ref, watch } from 'vue';
import Modal from '../../overlay/Modal.vue';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import TextInput from '../../forms/TextInput.vue';
import VariablePipelineEditor from './VariablePipelineEditor.vue';
import {
  getVariableIconLabel,
  getVariableIconName,
  resolveType,
} from './operationHelpers';
import { useI18n } from '../../../app/i18n';
import {
  type VariableDefinition,
  type VariableNodeAttrs,
  type VariableOperationDefinition,
  type VariablePipelineStep,
  type VariablePrimitive,
} from './types';

const props = defineProps<{
  /** The variable attrs being edited. */
  state: VariableNodeAttrs;
  /** Predefined variables (used to resolve the source variable + base type). */
  definitions: VariableDefinition[];
  /** Operations catalog for the pipeline editor. */
  catalog: VariableOperationDefinition[];
}>();

const emit = defineEmits<{
  (e: 'save', attrs: VariableNodeAttrs): void;
  (e: 'remove'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const name = ref('');
const locked = ref(false);
const pipeline = ref<VariablePipelineStep[]>([]);

// The source definition (by id) gives the authoritative base type + name; fall
// back to the node's own attrs when the variable isn't in the predefined list.
const definition = computed<VariableDefinition | undefined>(() =>
  props.definitions.find((d) => d.id === props.state.id),
);
const baseType = computed<VariablePrimitive>(
  () => definition.value?.type ?? props.state.type,
);
const sourceName = computed(() => definition.value?.name ?? props.state.name);

watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    name.value = props.state.name ?? '';
    locked.value = props.state.locked ?? false;
    pipeline.value = (props.state.pipeline ?? []).map((s) => ({ ...s, args: { ...s.args } }));
  },
  { immediate: true },
);

const resultType = computed<VariablePrimitive>(() =>
  resolveType(props.catalog, baseType.value, pipeline.value),
);

function toggleLock(): void {
  locked.value = !locked.value;
}

function save(): void {
  emit('save', {
    id: props.state.id,
    name: name.value.trim() || sourceName.value,
    type: baseType.value,
    locked: locked.value,
    pipeline: pipeline.value,
    resultType: resultType.value,
  });
  open.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="t('editor.variable.editTitle', 'Edit variable')">
    <template #title>{{ t('editor.variable.editTitle', 'Edit variable') }}</template>

    <div class="flex flex-col gap-next-5">
      <!-- Display name + lock -->
      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg" for="next-var-name">{{ t('editor.variable.displayName', 'Display name') }}</label>
        <TextInput id="next-var-name" v-model="name" :readonly="locked" :placeholder="t('editor.variable.namePlaceholder', 'Variable name')">
          <template #trailing>
            <button
              type="button"
              class="flex items-center rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg"
              :aria-pressed="locked"
              :aria-label="locked ? t('editor.variable.unlockName', 'Unlock name') : t('editor.variable.lockName', 'Lock name')"
              @click="toggleLock"
            >
              <Icon :name="locked ? 'lock' : 'lock-open'" />
            </button>
          </template>
        </TextInput>
      </div>

      <!-- Source variable -->
      <div class="flex items-center justify-between gap-next-3 rounded-next-lg border border-next-border bg-next-muted px-next-3 py-next-3">
        <div class="min-w-0">
          <p class="text-next-xs font-next-semibold text-next-muted-foreground">{{ t('editor.variable.sourceVariable', 'Source variable') }}</p>
          <p class="truncate text-next-sm font-next-medium text-next-fg">{{ sourceName }}</p>
        </div>
        <span class="inline-flex items-center gap-next-1 text-next-primary" :title="getVariableIconLabel(baseType)">
          <Icon :name="getVariableIconName(baseType)" />
          <span class="sr-only">{{ getVariableIconLabel(baseType) }}</span>
        </span>
      </div>

      <!-- Pipeline -->
      <VariablePipelineEditor v-model="pipeline" :base-type="baseType" :catalog="catalog" />
    </div>

    <template #footer>
      <Button variant="ghost" type="button" @click="emit('remove')">{{ t('editor.variable.remove', 'Delete') }}</Button>
      <span class="flex-1" />
      <Button variant="outline" type="button" @click="open = false">{{ t('editor.variable.cancel', 'Cancel') }}</Button>
      <Button variant="primary" type="button" @click="save">{{ t('editor.variable.save', 'Save') }}</Button>
    </template>
  </Modal>
</template>
