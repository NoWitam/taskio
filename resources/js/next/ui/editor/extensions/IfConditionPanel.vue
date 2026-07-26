<script setup lang="ts">
// IfConditionPanel — the Modal for editing an IF / ELSE-IF branch condition.
//
// ── B5: THIS FILE IS AN ADAPTER ────────────────────────────────────────────────
// The body is now the SHARED `VariableReferenceEditor` (`ui/variables`) — the SAME experience the
// step field, the markdown chip and the flow-condition modal use. So the if-block branch condition
// gets, with no bespoke logic:
//   • SOURCE PICKER  the inline ARIA TREE (`VariableBrowserPopover`) fed by the same live variable
//                    feed the `{` popup + `VariablePanel` browse — type glyphs, `?`/`[]` markers and
//                    expand-not-select objects. The flat `Select` is gone.
//   • PIPELINE       the shared `VariablePipelineEditor` with `resultTypes: ['boolean']` (a branch
//                    condition MUST terminate boolean) and the PRESENCE family KEPT
//                    (`hidePresenceOps: false`) — a boolean gate legitimately ends on `is_present` /
//                    `is_null`. Operation ARGUMENTS may themselves be variables (the `#argVariable`
//                    slot, wired exactly as `VariablePanel`), drawn from the same live pool.
//
// THE DEFAULT IS SUPPRESSED (`hideDefault`). The if-branch runtime path
// (`WorkflowVariableResolver::evaluateBranchCondition`) reads only `variableId` + `pipeline` and
// NEVER applies a branch `default`, so offering one would persist an INERT value. It is hidden here
// until the backend applies it — see the report's backend follow-up.
//
// IT KNOWS NO WIRE FORMAT beyond the mapping below: the branch condition wire
// (`IfConditionState {variableId, pipeline, resultType:'boolean'}`) ⇄ the editor's `VariableRefDraft`.
// The panel BLOCKS saving unless the pipeline resolves to boolean (legacy `isBranchValid` parity),
// and a condition that uses NONE of the new capabilities round-trips byte-identically through
// `ifBlock.ts` (the draft's `default` is always null → never emitted).
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import Modal from '../../overlay/Modal.vue';
import Button from '../../primitives/Button.vue';
import VariableReferenceEditor from '../../variables/VariableReferenceEditor.vue';
import { findNodeByPath } from '../../variables/variableTree';
import { descriptorToOperation, pipelineSatisfies, resolveType } from './operationHelpers';
import { useI18n } from '../../../app/i18n';
import type {
  VariableNode,
  VariableRefDraft,
  VariableSource,
  VariableSourceVar,
} from '../../variables/types';
import type {
  IfConditionState,
  OperationTypeDescriptor,
  VariableOperationDefinition,
  VariablePrimitive,
} from './types';

const props = withDefaults(
  defineProps<{
    /** Current condition (or null for a fresh one). */
    condition: IfConditionState | null;
    /**
     * The offered variable TREE (built by the host branch view from the editor's live feed). It
     * resolves the referenced variable — label, glyph, `?`/`[]` markers, TRUE type — and is what the
     * source picker browses.
     */
    nodes?: VariableNode[];
    /** The operations catalog the pipeline builder runs on. */
    catalog?: VariableOperationDefinition[];
    /** The pool an op ARGUMENT inside the pipeline may reference (the same live feed as `nodes`). */
    argVariables?: VariableSourceVar[];
    /** The host-injected value-or-variable control for ONE argument (absent ⇒ literal-only). */
    argVariableField?: Component;
  }>(),
  { nodes: () => [], catalog: () => [], argVariables: () => [] },
);

const emit = defineEmits<{
  (e: 'save', condition: IfConditionState): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

/** The normalised reference being edited — the shared editor's whole vocabulary. `null` ⇒ unpicked. */
const draft = ref<VariableRefDraft | null>(null);

/**
 * The ROOT source for a condition path. The wire stores only `variableId` (no source), so it is
 * taken from the resolved tree node and otherwise derived from the path root — it is only ever handed
 * to the shared editor, never serialized back.
 */
function sourceForPath(path: string): VariableSource {
  const node = findNodeByPath(props.nodes, path);
  if (node) return node.source;
  if (path.startsWith('steps.')) return 'steps';
  if (path.startsWith('globals.')) return 'globals';
  return 'trigger';
}

/** The referenced variable's TRUE type, else boolean (the condition's terminal is boolean anyway). */
function typeForPath(path: string): VariablePrimitive {
  return findNodeByPath(props.nodes, path)?.type ?? 'boolean';
}

/** Seed the draft from the condition whenever the modal opens (Cancel therefore discards). */
watch(
  open,
  (isOpen) => {
    if (!isOpen) return;
    const c = props.condition;
    draft.value = c?.variableId
      ? {
          source: sourceForPath(c.variableId),
          path: c.variableId,
          type: typeForPath(c.variableId),
          pipeline: (c.pipeline ?? []).map((s) => ({ ...s, args: { ...s.args } })),
          // The if-branch runtime never applies a default; keep it null so the wire is unchanged.
          default: null,
        }
      : null;
  },
  { immediate: true },
);

// --- Validity (legacy isBranchValid parity: a boolean terminal is required) --

const picked = computed<VariableNode | null>(() => findNodeByPath(props.nodes, draft.value?.path));

const baseType = computed<VariablePrimitive>(
  () => (picked.value?.type ?? draft.value?.type ?? 'text') as VariablePrimitive,
);

/**
 * The referenced variable's FULL source descriptor when it is an object/file ARRAY (F1) — looked up in
 * `argVariables` (mirroring `ValueOrVariableField`), so a repeater / file-array condition source offers
 * its array ops + element subfields. Undefined for every scalar/enum source.
 */
const baseDescriptor = computed<OperationTypeDescriptor | undefined>(() => {
  const descriptor = props.argVariables.find((variable) => variable.path === draft.value?.path)?.descriptor;
  if (descriptor?.array && (descriptor.base === 'object' || descriptor.base === 'file')) {
    return descriptorToOperation(descriptor);
  }
  return undefined;
});

const resultType = computed<VariablePrimitive>(() =>
  draft.value ? resolveType(props.catalog, baseType.value, draft.value.pipeline, baseDescriptor.value) : 'text',
);

/**
 * Valid when a source is picked AND its pipeline satisfies the boolean-terminal contract — the SAME
 * shared `pipelineSatisfies` the status strip uses, so it ALSO blocks Save on an invalid element
 * pipeline / a non-terminal `array_at` missing its typed default (F4), not just a non-boolean terminal.
 */
const isValid = computed(
  () =>
    Boolean(draft.value?.path) &&
    pipelineSatisfies(props.catalog, baseType.value, draft.value?.pipeline ?? [], ['boolean'], [], baseDescriptor.value),
);

function save(): void {
  const d = draft.value;
  if (!isValid.value || !d) return;
  emit('save', {
    variableId: d.path,
    pipeline: d.pipeline,
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
        <p v-if="!nodes.length" class="text-next-xs text-next-muted-foreground">
          {{ t('editor.ifCondition.noVariables', 'Add variables to build conditions.') }}
        </p>
        <p v-else class="text-next-xs text-next-muted-foreground">
          {{ t('editor.ifCondition.mustBeBoolean', 'The condition must end with a boolean type.') }}
        </p>
      </div>

      <!-- The SHARED reference body: source header + inline TREE picker → pipeline (boolean terminal,
           presence ops kept, arg-variables offered) → live "Returns: <type>" status. The typed
           "default when empty" is SUPPRESSED — the if-branch runtime never applies it. -->
      <VariableReferenceEditor
        v-model="draft"
        :nodes="nodes"
        :base-descriptor="baseDescriptor"
        :operations-catalog="catalog"
        :arg-variables="argVariables"
        :result-types="['boolean']"
        :hide-presence-ops="false"
        hide-default
      >
        <!-- ONE operation ARGUMENT as a value-or-variable control. The pipeline editor decides
             WHETHER to offer it (within the depth cap), the reference editor decides WHAT it may be,
             and the HOST supplies the control itself — so this shared editor never imports a page. -->
        <template v-if="argVariableField" #argVariable="{ setValue, ...argProps }">
          <component :is="argVariableField" v-bind="argProps" @update:value="setValue" />
        </template>
      </VariableReferenceEditor>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">{{ t('editor.ifCondition.cancel', 'Cancel') }}</Button>
      <Button variant="primary" type="button" :disabled="!isValid" @click="save">{{ t('editor.ifCondition.saveCondition', 'Save condition') }}</Button>
    </template>
  </Modal>
</template>
