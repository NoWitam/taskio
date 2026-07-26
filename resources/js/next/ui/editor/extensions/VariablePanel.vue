<script setup lang="ts">
// VariablePanel — the full edit panel for a `variable` node, hosted inside the next `Modal`
// (user requirement: the variable panel is a MODAL).
//
// ── B4: THIS FILE IS AN ADAPTER ────────────────────────────────────────────────
// Everything below the display-name row is now the SHARED `VariableReferenceEditor`
// (`ui/variables`) — the same body the step field's operations modal uses:
//   source header → DEFAULT WHEN EMPTY (nullable-gated + TYPED) → pipeline (with arg-variables)
//   → live "Returns: <type>" status → change-source (confirming when a pipeline would be dropped).
//
// That closes three reported defects on this surface at once:
//   1. the default was an UNCONDITIONAL plain TEXT input — it now renders only for a NULLABLE
//      variable and as the control its base deserves (number / date / enum / tri-state boolean),
//   2. the source variable was READ-ONLY — it can now be changed, through the shared browser,
//   3. an operation ARGUMENT could not be supplied by a variable — it now can, wherever the host
//      injects its value-or-variable field (`argVariableField`; `ui/**` must not import a page).
//
// WHAT STAYS HERE is exactly the markdown-only part: the DISPLAY NAME + its LOCK (a concept that
// exists on no other variable surface — the chip renders this name, so an author may rename a
// reference and pin that name against later edits), and the `VariableNodeAttrs` ⇄ `VariableRefDraft`
// mapping. The directive wire is IDENTITY-ONLY (`id` === the variable path, no `source` key), so
// the draft's `source` is carried for the shared editor's benefit and simply not serialized.
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import Modal from '../../overlay/Modal.vue';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import TextInput from '../../forms/TextInput.vue';
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
import {
  type OperationTypeDescriptor,
  type VariableNodeAttrs,
  type VariableOperationDefinition,
  type VariablePrimitive,
} from './types';

const props = withDefaults(
  defineProps<{
    /** The variable attrs being edited. */
    state: VariableNodeAttrs;
    /**
     * The offered variable TREE (built by the chip from the editor's live feed). It resolves the
     * referenced variable — its label, glyph, `?`/`[]` markers, TRUE type, enum options and
     * NULLABILITY (which is what gates the default block) — and is what the change-source
     * affordance browses.
     */
    nodes?: VariableNode[];
    /** Operations catalog for the pipeline editor. */
    catalog?: VariableOperationDefinition[];
    /** The pool an op ARGUMENT inside the pipeline may reference (the same feed as `nodes`). */
    argVariables?: VariableSourceVar[];
    /** The host-injected value-or-variable control for ONE argument (absent ⇒ literal-only). */
    argVariableField?: Component;
  }>(),
  { nodes: () => [], catalog: () => [], argVariables: () => [] },
);

const emit = defineEmits<{
  (e: 'save', attrs: VariableNodeAttrs): void;
  (e: 'remove'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const name = ref('');
const locked = ref(false);
/** The normalised reference being edited — the shared editor's whole vocabulary. */
const draft = ref<VariableRefDraft | null>(null);

/**
 * The ROOT source for the draft. The directive never stores one (identity-only), so it is taken
 * from the tree node when the path resolves and otherwise derived from the path root — it is only
 * ever handed BACK to the shared editor, never serialized.
 */
function sourceForPath(path: string): VariableSource {
  const node = findNodeByPath(props.nodes, path);
  if (node) return node.source;
  if (path.startsWith('steps.')) return 'steps';
  if (path.startsWith('globals.')) return 'globals';
  return 'trigger';
}

/** Seed the local draft from the node attrs whenever the modal opens (Cancel therefore discards). */
function hydrate(): void {
  name.value = props.state.name ?? '';
  locked.value = props.state.locked ?? false;
  draft.value = {
    source: sourceForPath(props.state.id),
    path: props.state.id,
    type: props.state.type,
    pipeline: (props.state.pipeline ?? []).map((s) => ({ ...s, args: { ...s.args } })),
    // `null` (never '') is the ONE "no default" value, so the emit-or-omit rule has a single test.
    default: props.state.default ?? null,
  };
}
// A fresh open re-seeds from the node attrs, so Cancel discards every edit (legacy parity).
watch(open, (isOpen) => isOpen && hydrate(), { immediate: true });

/** The referenced variable, resolved from the tree (null for an off-catalog / stale path). */
const referenced = computed<VariableNode | null>(() => findNodeByPath(props.nodes, draft.value?.path));

/** The pipeline's base (source) type — the referenced variable's TRUE type, else the stored one. */
const baseType = computed<VariablePrimitive>(
  () => (referenced.value?.type ?? draft.value?.type ?? props.state.type) as VariablePrimitive,
);

/**
 * The referenced variable's FULL source descriptor when it is an object/file ARRAY (F1) — a repeater /
 * file array degrades its flat type to `text`, so only the descriptor tells the pipeline it is an array
 * of a structured element (offering its array ops + element subfields). Looked up in `argVariables` (the
 * same feed the panel already carries), mirroring `ValueOrVariableField`. Undefined for every scalar/enum
 * source, so the flat `baseType` behaviour is byte-identical there.
 */
const baseDescriptor = computed<OperationTypeDescriptor | undefined>(() => {
  const descriptor = props.argVariables.find((variable) => variable.path === draft.value?.path)?.descriptor;
  if (descriptor?.array && (descriptor.base === 'object' || descriptor.base === 'file')) {
    return descriptorToOperation(descriptor);
  }
  return undefined;
});

/** The recomputed terminal type (the same pure helper the shared editor's status strip uses). */
const resultType = computed<VariablePrimitive>(() =>
  resolveType(props.catalog, baseType.value, draft.value?.pipeline ?? [], baseDescriptor.value),
);

/**
 * Whether the pipeline may be SAVED (F4): the markdown surface has no terminal-type gate, so this only
 * fails when a nested element pipeline / a non-terminal `array_at` is invalid — blocking Save exactly
 * where the backend would reject the reference at write time.
 */
const canSave = computed(() =>
  pipelineSatisfies(props.catalog, baseType.value, draft.value?.pipeline ?? [], [], [], baseDescriptor.value),
);

/** The referenced variable's own name — the fallback display name when the field is blank. */
const sourceName = computed(() => referenced.value?.label ?? props.state.name);

function toggleLock(): void {
  locked.value = !locked.value;
}

/**
 * Apply a draft change. Changing the SOURCE re-seeds the (unlocked) display name from the new
 * variable: the name defaults to the variable's own, and keeping the previous variable's name on a
 * different reference would be actively misleading. A LOCKED name is the author's explicit "keep
 * exactly this", so it survives.
 */
function onDraftUpdate(next: VariableRefDraft | null): void {
  const previous = draft.value;
  draft.value = next;
  if (!next || !previous || next.path === previous.path || locked.value) return;
  name.value = findNodeByPath(props.nodes, next.path)?.label ?? name.value;
}

function save(): void {
  const current = draft.value;
  if (!current) return;
  emit('save', {
    id: current.path,
    name: name.value.trim() || sourceName.value,
    type: baseType.value,
    locked: locked.value,
    pipeline: current.pipeline,
    resultType: resultType.value,
    // Emit-or-OMIT: an unset default is null, which the directive omits entirely. A TYPED default
    // (number / boolean / date / enum key) rides through as it is — no stringification.
    default: current.default,
  });
  open.value = false;
}
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="t('editor.variable.editTitle', 'Edit variable')">
    <template #title>{{ t('editor.variable.editTitle', 'Edit variable') }}</template>

    <div class="flex flex-col gap-next-5">
      <!-- Display name + lock — the ONE markdown-only concept (the chip renders this name). -->
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

      <!-- The SHARED reference body: source header → typed, nullable-gated default → pipeline
           (with arg-variables when the host injected a field) → terminal status → change source. -->
      <VariableReferenceEditor
        :model-value="draft"
        :nodes="nodes"
        :base-descriptor="baseDescriptor"
        :operations-catalog="catalog"
        :arg-variables="argVariables"
        @update:model-value="onDraftUpdate"
      >
        <!-- ONE operation ARGUMENT as a value-or-variable control. The pipeline editor decides
             WHETHER to offer it (within the depth cap), the reference editor decides WHAT it may be
             (the arg's pool / coercion catalog / terminal gate / toggle label), and the HOST
             supplies the control itself — so this shared editor never imports a page component. -->
        <template v-if="argVariableField" #argVariable="{ setValue, ...argProps }">
          <component :is="argVariableField" v-bind="argProps" @update:value="setValue" />
        </template>
      </VariableReferenceEditor>
    </div>

    <template #footer>
      <Button variant="ghost" type="button" @click="emit('remove')">{{ t('editor.variable.remove', 'Delete') }}</Button>
      <span class="flex-1" />
      <Button variant="outline" type="button" @click="open = false">{{ t('editor.variable.cancel', 'Cancel') }}</Button>
      <Button variant="primary" type="button" :disabled="!canSave" @click="save">{{ t('editor.variable.save', 'Save') }}</Button>
    </template>
  </Modal>
</template>
