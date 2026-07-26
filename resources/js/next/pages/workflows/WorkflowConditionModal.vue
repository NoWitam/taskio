<script setup lang="ts">
// WorkflowConditionModal — edit ONE tree condition in a Modal, now on the SHARED reference editor.
//
// A condition points at a SOURCE (a form field OR a workspace global, B6) run through the shared
// operations pipeline; it is INVALID unless the pipeline resolves to boolean (the same rule the IF
// editor uses). Until B7 this modal drove `VariablePipelineEditor` directly and had no default / no
// arg-variables. It is now a thin ADAPTER over `ui/variables/VariableReferenceEditor` — the SAME body
// every other reference surface (the step field, the markdown chip) uses — so the condition surface
// gains, for free and identically:
//   1. SOURCE HEADER + inline TREE picker (sections expand, `array<object>` skipped, globals grouped
//      under one "Globals" node; a whole object is never selectable).
//   2. DEFAULT WHEN EMPTY — the nullable-gated, typed `VariableDefaultField`, shown ONLY for a nullable
//      source and typed to the source's own base.
//   3. PIPELINE with ARG-VARIABLES — an op argument may itself be a variable. The pool is the condition
//      SOURCES that resolve at GATE time: the trigger's variables + workspace globals, and NEVER
//      `steps.*` (no step has run when a gate is evaluated — the backend rejects `steps.*` arg refs).
//   4. TERMINAL STATUS — the live "Returns: <type>" strip; here the accepted terminal is boolean.
//
// The editor speaks the wire-free `VariableRefDraft` (`{source, path, type, pipeline, default}`); THIS
// file maps that ⇄ the condition wire (`{source, sourceType, pipeline, default}`). A condition using
// none of the new capabilities round-trips byte-identically (an empty pipeline / null default are
// dropped). `hide-presence-ops` is OFF here (unlike the value surfaces): a condition still needs
// `is_present`/`is_null` as boolean terminals.
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import PipelineArgLiteralInput from '../../ui/editor/extensions/PipelineArgLiteralInput.vue';
import VariableReferenceEditor from '../../ui/variables/VariableReferenceEditor.vue';
import ValueOrVariableField from './ValueOrVariableField.vue';
import { descriptorToOperation, pipelineSatisfies } from '../../ui/editor/extensions/operationHelpers';
import { useI18n } from '../../app/i18n';
import { conditionSourceVariables } from './workflowVariables';
import { buildVariableTree, findNodeByPath } from '../../ui/variables/variableTree';
import { argToUnion, unionToArg } from './argVariableAdapters';
import { CONDITION_LIMITS, type ConditionDraftPayload, type DraftCondition } from './workflowConditions';
import type { CatalogField, CatalogVariable, WorkflowFieldValue, WorkflowVariableType } from './types';
import type {
  VariableLiteral,
  VariableNode,
  VariableRefDraft,
  VariableSource,
} from '../../ui/variables/types';
import type {
  OperationTypeDescriptor,
  VariableArgValue,
  VariableOperationDefinition,
  VariableOption,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /** The condition being edited, or null for a fresh one. */
    condition: DraftCondition | null;
    /** The catalog's condition SOURCES — the accepted `source` paths (form fields + globals, B6). */
    fields: CatalogField[];
    /**
     * The catalog's VARIABLES, used to borrow each source's structured descriptor (type markers +
     * grouping) by path. Omitted / empty ⇒ the picker renders the plain flat list of sources.
     */
    variables?: CatalogVariable[];
    /** The merged operations catalog (labels attached). */
    catalog: VariableOperationDefinition[];
    /**
     * The show-all pool an op ARGUMENT in the condition pipeline may reference (B6). It is the
     * gate-time sources — the trigger's variables + workspace globals, NEVER `steps.*` (nothing has
     * run at gate time). Empty (the default) ⇒ op args stay literal-only.
     */
    argVariables?: CatalogVariable[];
  }>(),
  { variables: () => [], argVariables: () => [] },
);

const emit = defineEmits<{
  (e: 'save', payload: ConditionDraftPayload): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

/** The reference being edited (wire-free). `null` ⇒ no source chosen yet (picker only). */
const draft = ref<VariableRefDraft | null>(null);

/** The picker feed: the condition sources as tree variables (descriptors borrowed by path). */
const sourceVariables = computed<CatalogVariable[]>(() =>
  conditionSourceVariables(props.fields, props.variables),
);

/**
 * The BROWSED tree over that feed. `conditionSourceVariables` already emits ONLY the accepted source
 * paths (`fields.<id>` / `globals.<key>[.<sub>]`) plus the non-selectable section / "Globals" group
 * containers they nest under, so every selectable node is a valid condition `source`.
 */
const sourceTree = computed<VariableNode[]>(() => buildVariableTree(sourceVariables.value, {}));

/** The condition SOURCE root a path resolves under (globals vs the form's trigger). */
function rootSourceOf(path: string): VariableSource {
  return path.startsWith('globals.') || path === 'globals' ? 'globals' : 'trigger';
}

// (Re)seed on open — a fresh clone so cancel discards edits (legacy parity). Maps the condition wire
// onto the editor's normalized draft; a source-less condition seeds `null` (picker only).
watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    const condition = props.condition;
    if (!condition || !condition.source) {
      draft.value = null;
      return;
    }
    const node = findNodeByPath(sourceTree.value, condition.source);
    draft.value = {
      // The ROOT source is carried from the picked node (correct even for a globals source); a
      // stale / off-catalog path falls back to a path-derived root.
      source: node?.source ?? rootSourceOf(condition.source),
      path: condition.source,
      type: condition.sourceType,
      pipeline: (condition.pipeline ?? []).map((step) => ({ ...step, args: { ...step.args } })),
      default: condition.default ?? null,
    };
  },
  { immediate: true },
);

/** The pipeline's base (source) type — the referenced variable's TRUE type. */
const baseType = computed<VariablePrimitive>(() => (draft.value?.type ?? 'text') as VariablePrimitive);

/**
 * The picked source's FULL descriptor when it is an object/file ARRAY (F1) — a repeater / file-array
 * condition source degrades its flat type to `text`, so only the descriptor tells the pipeline it is an
 * array (offering array ops + element subfields). Looked up in the SAME condition-source feed the picker
 * uses, mirroring `ValueOrVariableField`. Undefined for every scalar/enum source.
 */
const baseDescriptor = computed<OperationTypeDescriptor | undefined>(() => {
  const descriptor = sourceVariables.value.find((variable) => variable.path === draft.value?.path)?.descriptor;
  if (descriptor?.array && (descriptor.base === 'object' || descriptor.base === 'file')) {
    return descriptorToOperation(descriptor);
  }
  return undefined;
});

/** The accepted pipeline terminal for a condition: always boolean. */
const RESULT_TYPES: VariablePrimitive[] = ['boolean'];

/**
 * The Save gate: a source is chosen AND its pipeline satisfies the boolean-terminal contract (the SAME
 * `pipelineSatisfies` predicate the editor's status strip uses). Threading `baseDescriptor` roots the
 * gate at a repeater / file array (F1) and blocks Save on an invalid element pipeline / a non-terminal
 * `array_at` missing its typed default (F4).
 */
const isReady = computed(() => {
  const d = draft.value;
  if (!d || !d.path) return false;
  return pipelineSatisfies(props.catalog, baseType.value, d.pipeline, RESULT_TYPES, [], baseDescriptor.value);
});

const title = computed(() =>
  props.condition
    ? t('workflows.condition.modal.editTitle')
    : t('workflows.condition.modal.createTitle'),
);

/** Whether a drafted default counts as "unset" (omit the key). `false` / `0` are meaningful. */
function defaultIsEmpty(value: VariableLiteral): boolean {
  return value == null || value === '';
}

function save(): void {
  const d = draft.value;
  if (!isReady.value || !d) return;
  const payload: ConditionDraftPayload = {
    source: d.path,
    sourceType: d.type as WorkflowVariableType,
    pipeline: d.pipeline,
  };
  // Emit-or-OMIT the typed default so a condition without one round-trips byte-identically.
  if (!defaultIsEmpty(d.default)) payload.default = d.default;
  emit('save', payload);
  open.value = false;
}

/**
 * STRUCTURAL ENTRIES (Defect-3): a CHOICE entry (its `resultTypes` is exactly `['enum']`) must map INTO
 * the destination options, so it threads `targetOptions` to the nested field; other entries thread none.
 */
function isChoiceEntryTypes(types: unknown): boolean {
  return Array.isArray(types) && types.length === 1 && types[0] === 'enum';
}
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="title">
    <template #title>{{ title }}</template>

    <div class="flex flex-col gap-next-5">
      <!-- The SHARED reference editor: source header + inline TREE picker + nullable-gated typed
           default + boolean-terminal pipeline (with arg-variables) + a live "Returns" strip. Presence
           ops stay offered here (a condition's is_present / is_null boolean terminal). -->
      <VariableReferenceEditor
        v-model="draft"
        :nodes="sourceTree"
        :change-source="true"
        :base-descriptor="baseDescriptor"
        :operations-catalog="catalog"
        :result-types="RESULT_TYPES"
        :max-steps="CONDITION_LIMITS.maxPipelineSteps"
        :arg-variables="argVariables"
        :hide-presence-ops="false"
      >
        <!-- ARG-VARIABLE (B6): ANY op argument may itself be a variable. The pipeline editor decides
             WHETHER (within the depth cap) and the reference editor decides WHAT (the arg's pool — the
             gate-time trigger + globals sources — its coercion catalog, its terminal gate and toggle
             label). We supply the SAME value-or-variable field RECURSIVELY, its literal control
             (PipelineArgLiteralInput) filling the VALUE slot. `target-options` rides only for a CHOICE
             structural ENTRY (Defect-3). -->
        <template
          #argVariable="{
            arg,
            value,
            depth: hostedDepth,
            setValue,
            disabled: argDisabled,
            sourceOptions: argSourceOptions,
            targetOptions: argTargetOptions,
            variables: argPool,
            operationsCatalog: argCatalog,
            resultTypes: argTypes,
          }"
        >
          <ValueOrVariableField
            :model-value="argToUnion(value as VariableArgValue)"
            :variables="(argPool as CatalogVariable[])"
            :arg-variables="argVariables"
            :operations-catalog="argCatalog"
            :result-types="(argTypes as WorkflowVariableType[])"
            :target-options="isChoiceEntryTypes(argTypes) ? (argTargetOptions as VariableOption[]) : []"
            :depth="hostedDepth"
            :disabled="argDisabled"
            :picker-label="arg.label"
            @update:model-value="(u: WorkflowFieldValue | null) => setValue(unionToArg(u, arg))"
          >
            <template #default="{ value: litValue, setValue: setLit, disabled: litDisabled }">
              <PipelineArgLiteralInput
                :arg="arg"
                :value="litValue"
                :source-options="argSourceOptions"
                :target-options="argTargetOptions"
                :disabled="litDisabled"
                @update:value="setLit"
              />
            </template>
          </ValueOrVariableField>
        </template>
      </VariableReferenceEditor>

      <!-- Empty catalog: no conditionable source at all. -->
      <p v-if="!sourceVariables.length" class="text-next-xs text-next-muted-foreground">
        {{ t('workflows.condition.modal.noFields') }}
      </p>
      <p v-else class="text-next-xs text-next-muted-foreground">
        {{ t('workflows.condition.modal.mustBeBoolean') }}
      </p>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">{{ t('workflows.condition.modal.cancel') }}</Button>
      <Button variant="primary" type="button" :disabled="!isReady" @click="save">{{ t('workflows.condition.modal.save') }}</Button>
    </template>
  </Modal>
</template>
