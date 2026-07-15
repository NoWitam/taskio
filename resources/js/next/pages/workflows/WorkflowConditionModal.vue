<script setup lang="ts">
// WorkflowConditionModal — edit ONE tree condition in a Modal, mirroring the markdown
// editor's IF-condition editor (IfConditionPanel), but sourced from FORM FIELDS.
//
// A condition is a form field (`source`) run through the SHARED VariablePipelineEditor;
// it is INVALID unless the pipeline resolves to boolean (the same rule as the IF
// editor). Layout:
//   1. Field  Select over the catalog fields (label = field label, icon = type glyph).
//   2. Pipeline VariablePipelineEditor with the field's type as its base type and the
//      field's enum options mapped to the source-option args.
//   3. Status  a boolean → ready badge, else a keep-going warning; Save is blocked
//      until the result is boolean AND a field is chosen.
// Changing the source field RESETS the pipeline (its base type changes) — with a
// ConfirmDialog when the pipeline isn't empty (a safe, reversible switch).
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import VariablePipelineEditor from '../../ui/editor/extensions/VariablePipelineEditor.vue';
import { resolveType } from '../../ui/editor/extensions/operationHelpers';
import { useI18n } from '../../app/i18n';
import { variableIcon } from './workflowVariables';
import { CONDITION_LIMITS, type ConditionDraftPayload, type DraftCondition } from './workflowConditions';
import type { CatalogField, WorkflowVariableType } from './types';
import type {
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';

const props = defineProps<{
  /** The condition being edited, or null for a fresh one. */
  condition: DraftCondition | null;
  /** The catalog's condition FIELDS (the source picker). */
  fields: CatalogField[];
  /** The merged operations catalog (labels attached). */
  catalog: VariableOperationDefinition[];
}>();

const emit = defineEmits<{
  (e: 'save', payload: ConditionDraftPayload): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const source = ref<string | null>(null);
const pipeline = ref<VariablePipelineStep[]>([]);

// Field-change confirm state (declared BEFORE the seed watcher, which resets them).
const pendingField = ref<string | null>(null);
const resetConfirmOpen = ref(false);

// (Re)seed on open — a fresh clone so cancel discards edits (legacy parity).
watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    source.value = props.condition?.source || null;
    pipeline.value = (props.condition?.pipeline ?? []).map((s) => ({ ...s, args: { ...s.args } }));
    resetConfirmOpen.value = false;
    pendingField.value = null;
  },
  { immediate: true },
);

const fieldOptions = computed<SelectOption[]>(() =>
  props.fields.map((f) => ({ value: f.path, label: f.label, icon: variableIcon(f.type) })),
);

function fieldByPath(path: string | null): CatalogField | undefined {
  return path ? props.fields.find((f) => f.path === path) : undefined;
}

/** The pipeline's base (source) primitive — the field's TRUE type. */
const baseType = computed<VariablePrimitive>(() => (fieldByPath(source.value)?.type ?? 'text') as VariablePrimitive);

/** The field's enum options mapped to the source-option arg choices ({label,value}). */
const sourceOptions = computed<VariableOption[]>(() =>
  (fieldByPath(source.value)?.enumOptions ?? []).map((value) => ({ label: value, value })),
);

const resultType = computed<VariablePrimitive>(() =>
  source.value ? resolveType(props.catalog, baseType.value, pipeline.value) : 'text',
);

const isReady = computed(() => Boolean(source.value) && resultType.value === 'boolean');

// --- Field change (reset the pipeline; confirm when it isn't empty) ----------
function onFieldChange(value: string | null): void {
  if (!value || value === source.value) return;
  if (pipeline.value.length > 0) {
    // A non-empty pipeline is tied to the old base type — confirm before dropping it.
    pendingField.value = value;
    resetConfirmOpen.value = true;
    return;
  }
  applyField(value);
}

function applyField(value: string): void {
  source.value = value;
  pipeline.value = [];
}

function confirmFieldChange(): void {
  if (pendingField.value) applyField(pendingField.value);
  pendingField.value = null;
  resetConfirmOpen.value = false;
}

function cancelFieldChange(): void {
  pendingField.value = null;
  resetConfirmOpen.value = false;
}

const title = computed(() =>
  props.condition
    ? t('workflows.condition.modal.editTitle')
    : t('workflows.condition.modal.createTitle'),
);

function save(): void {
  if (!isReady.value || !source.value) return;
  emit('save', {
    source: source.value,
    sourceType: baseType.value as WorkflowVariableType,
    pipeline: pipeline.value,
  });
  open.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="title">
    <template #title>{{ title }}</template>

    <div class="flex flex-col gap-next-5">
      <div class="flex flex-col gap-next-1_5">
        <label class="text-next-sm font-next-medium text-next-fg">{{ t('workflows.condition.modal.fieldLabel') }}</label>
        <Select
          :model-value="source"
          :options="fieldOptions"
          :placeholder="t('workflows.condition.modal.fieldPlaceholder')"
          :disabled="!fieldOptions.length"
          :aria-label="t('workflows.condition.modal.fieldLabel')"
          @update:model-value="(v) => onFieldChange(v as string | null)"
        />
        <p v-if="!fieldOptions.length" class="text-next-xs text-next-muted-foreground">
          {{ t('workflows.condition.modal.noFields') }}
        </p>
        <p v-else class="text-next-xs text-next-muted-foreground">
          {{ t('workflows.condition.modal.mustBeBoolean') }}
        </p>
      </div>

      <VariablePipelineEditor
        v-if="source"
        v-model="pipeline"
        :base-type="baseType"
        :catalog="catalog"
        :source-options="sourceOptions"
        :max-steps="CONDITION_LIMITS.maxPipelineSteps"
      />

      <!-- Validation status (boolean → ready, else keep going). -->
      <div
        class="flex items-center gap-next-2 rounded-next-md border px-next-3 py-next-2 text-next-sm"
        :class="isReady ? 'border-next-success/40 bg-next-success-subtle' : 'border-next-warning/40 bg-next-warning-subtle'"
      >
        <Icon
          :name="isReady ? 'check-circle' : 'alert-triangle'"
          :class="isReady ? 'text-next-success' : 'text-next-warning'"
        />
        <span class="text-next-fg">
          {{ isReady ? t('workflows.condition.modal.ready') : t('workflows.condition.modal.notReady') }}
        </span>
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">{{ t('workflows.condition.modal.cancel') }}</Button>
      <Button variant="primary" type="button" :disabled="!isReady" @click="save">{{ t('workflows.condition.modal.save') }}</Button>
    </template>

    <!-- Field-switch confirm (only when a non-empty pipeline would be dropped). -->
    <ConfirmDialog
      v-model:open="resetConfirmOpen"
      :title="t('workflows.condition.modal.resetTitle')"
      :message="t('workflows.condition.modal.resetMessage')"
      :confirm-label="t('workflows.condition.modal.resetConfirm')"
      :cancel-label="t('workflows.condition.modal.resetCancel')"
      @confirm="confirmFieldChange"
      @cancel="cancelFieldChange"
    />
  </Modal>
</template>
