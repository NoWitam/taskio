<script setup lang="ts">
// VariableReferenceEditor — the SHARED body of "edit this variable reference".
//
// Every surface that lets a user point at a variable eventually needs the SAME five things,
// in the same order, with the same rules. Until B3 exactly one of them (the step-field's
// operations modal, inside ValueOrVariableField) had them all; this component is that
// experience extracted so the markdown chip, the if-block condition and the condition builder
// can be moved onto it without re-deriving anything:
//
//   1. SOURCE HEADER      the referenced variable's glyph (with its nullable `?` / array `[]`
//                         markers), its name, and its TRUE type as a badge.
//   2. CHANGE SOURCE      an optional VariableBrowserPopover. Changing the source while a
//                         NON-EMPTY pipeline exists ALWAYS asks for confirmation first (the
//                         pipeline is tied to the old base type and must be dropped) — until
//                         B3 only the condition modal did this; it is now the shared rule.
//   3. DEFAULT WHEN EMPTY VariableDefaultField (nullable-gated, typed, tri-state boolean).
//   4. PIPELINE           VariablePipelineEditor, including the RECURSIVE arg-variable wiring:
//                         this component derives each op argument's variable policy (its pool,
//                         its coercion catalog, its terminal gate, its clarifying toggle label)
//                         and re-exposes it through its own `argVariable` slot, so a host only
//                         supplies the leaf control and gets arg-variables for free.
//   5. TERMINAL STATUS    a live "Returns: <type>" strip, warning when the pipeline does not
//                         satisfy the host's accepted terminals / choice-target rule.
//
// IT KNOWS NO WIRE FORMAT. v-model is the normalised `VariableRefDraft`
// (`{source, path, type, pipeline, default}`); mapping that onto a `{kind:'variable', ref, …}`
// union, a directive's node attrs or a condition leaf is the HOST's job — as is owning the
// Modal/Drawer around this body and gating its Save (with the same shared `pipelineSatisfies`
// predicate this component's strip uses).
import { computed, ref } from 'vue';
import Badge from '../primitives/Badge.vue';
import Icon from '../primitives/Icon.vue';
import type { IconName } from '../primitives/icons';
import ConfirmDialog from '../overlay/ConfirmDialog.vue';
import VariablePipelineEditor from '../editor/extensions/VariablePipelineEditor.vue';
import VariableTypeIcon from '../editor/extensions/VariableTypeIcon.vue';
import VariableBrowserPopover from './VariableBrowserPopover.vue';
import VariableDefaultField from './VariableDefaultField.vue';
import { findNodeByPath, nodeIcon } from './variableTree';
import {
  argVariablePolicy,
  getVariableIconLabel,
  pipelineSatisfies,
  resolveType,
} from '../editor/extensions/operationHelpers';
import { useI18n } from '../../app/i18n';
import type { VariableLiteral, VariableNode, VariableRefDraft, VariableSourceVar } from './types';
import type {
  OperationTypeDescriptor,
  VariableOperationArgumentDefinition,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from '../editor/extensions/types';

const props = withDefaults(
  defineProps<{
    /**
     * The tree the CHANGE-SOURCE affordance browses (already built + policy-applied by the
     * host). It is ALSO how the header resolves the referenced variable's name / glyph /
     * markers / enum options, so a host should feed the same tree its picker offers.
     */
    nodes?: VariableNode[];
    /**
     * Whether to offer the change-source affordance INSIDE this body. A host whose picker
     * lives elsewhere (the step field puts it on the field surface, behind the chip's ✕)
     * passes false and renders a read-only header.
     */
    changeSource?: boolean;
    /**
     * The referenced variable's FULL source descriptor (array-transform wave 3) — threaded to the
     * pipeline editor so a repeater / file array (whose flat type degrades to `text`) still offers its
     * array ops + element subfield access. Absent ⇒ the flat `baseType` behaviour. The host derives it
     * from the picked node's catalog descriptor.
     */
    baseDescriptor?: OperationTypeDescriptor;
    /** The merged operations catalog the pipeline builder runs on. */
    operationsCatalog?: VariableOperationDefinition[];
    /** The types the pipeline MUST terminate on (empty ⇒ no type gate). */
    resultTypes?: VariablePrimitive[];
    /**
     * The DESTINATION field's option set for a "choice"/enum target. When present the pipeline
     * must END on a CHOICE-producing op that targets THESE values.
     */
    targetOptions?: VariableOption[];
    /** Client cap on pipeline length. */
    maxSteps?: number;
    /** The ARG-VARIABLE nesting depth of the pipeline this body hosts (top level = 0). */
    depth?: number;
    /** The show-all pool an op ARGUMENT inside the pipeline may reference. */
    argVariables?: VariableSourceVar[];
    /**
     * Drop the PRESENCE family (assert_present / coalesce / is_present / is_null) from the pipeline's
     * add menu. TRUE by default — a reference surface (the step field, the markdown chip) carries its
     * OWN typed "default when empty", so those ops are unwanted there. The CONDITION surface passes
     * FALSE: a gate still needs `is_present` / `is_null` as its boolean terminal.
     */
    hidePresenceOps?: boolean;
    /**
     * SUPPRESS the "default when empty" field entirely. FALSE by default, so every existing surface
     * keeps its typed, nullable-gated default. The IF-BRANCH condition passes TRUE: its runtime path
     * (`WorkflowVariableResolver::evaluateBranchCondition`) reads only `variableId` + `pipeline` and
     * NEVER applies a branch `default`, so offering one would store an INERT value — worse than
     * absent. Flip this back to false once the backend applies a branch-condition default.
     */
    hideDefault?: boolean;
    disabled?: boolean;
  }>(),
  {
    nodes: () => [],
    changeSource: true,
    operationsCatalog: () => [],
    resultTypes: () => [],
    targetOptions: () => [],
    depth: 0,
    argVariables: () => [],
    hidePresenceOps: true,
    hideDefault: false,
    disabled: false,
  },
);

/** The reference being edited. `null` ⇒ nothing is referenced yet (picker only). */
const draft = defineModel<VariableRefDraft | null>({ default: null });

const { t } = useI18n();

// --- The referenced variable, resolved from the tree -------------------------

/**
 * The node the draft points at, or null for an off-catalog / stale path. EVERYTHING the header
 * and the default block need comes from here — the label, the glyph, the nullable/array
 * markers, the enum options and the TRUE type — so there is no second lookup path to drift.
 */
const picked = computed<VariableNode | null>(() => findNodeByPath(props.nodes, draft.value?.path));

/** The header label: the catalog name, or the raw path when the variable is off-list. */
const sourceLabel = computed(() => picked.value?.label ?? draft.value?.path ?? '');

/** The header glyph: the TRUE type's icon (an object base reads as a container). */
const sourceIcon = computed<IconName>(() =>
  picked.value ? nodeIcon(picked.value) : nodeIcon({ type: draft.value?.type ?? 'text', base: 'text' }),
);

/** The pipeline's base (source) type — the referenced variable's TRUE type. */
const baseType = computed<VariablePrimitive>(
  () => (picked.value?.type ?? draft.value?.type ?? 'text') as VariablePrimitive,
);

/**
 * The referenced variable's choices as the source-option arg vocabulary (`{label,value}`) —
 * the node already prefers the structured `{key,label}` descriptor options over the flat ones.
 */
const sourceOptions = computed<VariableOption[]>(() =>
  (picked.value?.options ?? []).map((option) => ({ label: option.label, value: option.key })),
);

// --- Pipeline + default (projections over the draft) -------------------------

const pipelineSteps = computed<VariablePipelineStep[]>({
  get: () => draft.value?.pipeline ?? [],
  set: (steps) => {
    if (draft.value) draft.value = { ...draft.value, pipeline: steps };
  },
});

const defaultValue = computed<VariableLiteral>({
  get: () => draft.value?.default ?? null,
  set: (value) => {
    if (draft.value) draft.value = { ...draft.value, default: value };
  },
});

// --- Change source (always confirm when a pipeline would be dropped) ---------

const pendingNode = ref<VariableNode | null>(null);
const resetConfirmOpen = ref(false);

/**
 * Apply a new source: the pipeline was type-flowed from the OLD base type and the default was
 * typed to the OLD base, so both reset — exactly what picking a fresh variable always did.
 */
function applyNode(node: VariableNode): void {
  draft.value = { source: node.source, path: node.path, type: node.type, pipeline: [], default: null };
}

function onPick(node: VariableNode): void {
  if (props.disabled || node.path === draft.value?.path) return;
  // THE PROMOTED RULE (B3): dropping a non-empty pipeline is never silent, on any surface.
  if ((draft.value?.pipeline.length ?? 0) > 0) {
    pendingNode.value = node;
    resetConfirmOpen.value = true;
    return;
  }
  applyNode(node);
}

function confirmChange(): void {
  if (pendingNode.value) applyNode(pendingNode.value);
  pendingNode.value = null;
  resetConfirmOpen.value = false;
}

function cancelChange(): void {
  pendingNode.value = null;
  resetConfirmOpen.value = false;
}

// --- Terminal status ---------------------------------------------------------

/** The pipeline's final output type (the base type when there are no operations). */
const resultTypeLabel = computed<VariablePrimitive>(() =>
  resolveType(props.operationsCatalog, baseType.value, pipelineSteps.value, props.baseDescriptor),
);

/** Whether the pipeline satisfies the host's terminal + choice-target contract. */
const satisfied = computed(() =>
  pipelineSatisfies(
    props.operationsCatalog,
    baseType.value,
    pipelineSteps.value,
    props.resultTypes,
    props.targetOptions,
    props.baseDescriptor,
  ),
);

/**
 * Whether the mismatch is specifically the CHOICE-target rule (the result type already matches
 * but the pipeline does not END on a choice-producing op) — a clearer hint than "expected
 * Choice" for a value that already returns Choice.
 */
const needsChoiceOp = computed(
  () =>
    !satisfied.value &&
    (props.targetOptions?.length ?? 0) > 0 &&
    props.resultTypes.includes(resultTypeLabel.value),
);

/** The human list of accepted terminals, for the mismatch hint. */
const expectedTypesLabel = computed(() =>
  props.resultTypes.map((type) => getVariableIconLabel(type)).join(' / '),
);

// --- ARG-VARIABLE policy (derived once, re-exposed through the slot) ---------
// The pipeline editor decides WHETHER an argument may be a variable (the host provided the
// slot AND we are within the depth cap); this component decides WHAT such a variable may be,
// from the shared `argVariablePolicy` mirror of the backend rule — so every host that fills
// the `argVariable` slot gets identical, backend-safe parameters with zero duplication.

/**
 * The accepted terminal type(s) for a WHOLE-arg arg-variable's own pipeline: value→its type;
 * option→enum|text; sourceOptions→multi. STRUCTURAL args never reach the whole-arg slot (Defect-3);
 * their per-ENTRY fields carry the entry's own `resultTypes` through the slot instead (used verbatim
 * when present — see the template's `slotProps.resultTypes ?? …`).
 */
function argResultTypes(arg: VariableOperationArgumentDefinition): VariablePrimitive[] {
  return argVariablePolicy(arg.type).refTypes;
}

/**
 * The SCOPE variables (Element/Indeks) an element-pipeline arg threads through the slot props
 * (array-transform wave 2). Present only for an arg INSIDE an element pipeline; absent everywhere
 * else, so the whole-pool prefix is a no-op on every ordinary surface.
 */
function scopeVariablesOf(slotProps: Record<string, unknown>): VariableSourceVar[] {
  const scope = slotProps.scopeVariables;
  return Array.isArray(scope) ? (scope as VariableSourceVar[]) : [];
}

defineExpose({ satisfied, resultType: resultTypeLabel });
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <div v-if="changeSource || draft" class="flex flex-col gap-next-1_5">
      <!-- 1. SOURCE HEADER — the referenced variable, read-only (with its type markers). -->
      <div
        v-if="draft"
        class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-muted px-next-3 py-next-2"
        data-variable-source
      >
        <VariableTypeIcon
          :icon="sourceIcon"
          :nullable="picked?.nullable"
          :array="picked?.array"
          class="shrink-0 text-next-muted-foreground"
        />
        <span class="min-w-0 flex-1 truncate font-next-medium text-next-fg">{{ sourceLabel }}</span>
        <Badge variant="neutral" tone="subtle" size="sm">{{ getVariableIconLabel(baseType) }}</Badge>
      </div>

      <!-- 2. CHANGE SOURCE — the shared inline tree. Picking while a pipeline exists confirms. -->
      <VariableBrowserPopover
        v-if="changeSource"
        :nodes="nodes"
        :selected-path="draft?.path ?? null"
        :disabled="disabled || !nodes.length"
        :label="t('variableBrowser.label')"
        :placeholder="draft ? t('variableBrowser.changeSource') : t('variableBrowser.trigger')"
        @select="onPick"
      />
    </div>

    <template v-if="draft">
      <!-- 3. DEFAULT WHEN EMPTY — renders itself only for a nullable, typeable variable.
           SUPPRESSED entirely on a surface that would never apply it (the if-branch condition — see
           `hideDefault`), so the engine can never store an inert default. -->
      <VariableDefaultField
        v-if="!hideDefault"
        v-model="defaultValue"
        :base="picked?.base ?? null"
        :nullable="picked?.nullable ?? false"
        :options="picked?.options"
        :disabled="disabled"
      />

      <!-- 4. PIPELINE — with the arg-variable slot forwarded, enriched with each arg's policy. -->
      <VariablePipelineEditor
        v-model="pipelineSteps"
        :base-type="baseType"
        :base-descriptor="baseDescriptor"
        :catalog="operationsCatalog"
        :source-options="sourceOptions"
        :target-options="targetOptions"
        :max-steps="maxSteps"
        :depth="depth"
        :hide-presence-ops="hidePresenceOps"
      >
        <!-- The presence family (assert_present / coalesce / is_present / is_null) is dropped when
             `hidePresenceOps` (default): a reference surface has its OWN typed "default when empty"
             above, so those ops are unwanted. The condition surface passes false to keep is_present /
             is_null as its boolean terminal. -->
        <template v-if="$slots.argVariable" #argVariable="slotProps">
          <!-- WHOLE-arg (value / option) uses this component's derived policy; a STRUCTURAL container's
               per-ENTRY field carries its own `resultTypes` + narrowed `targetOptions` through the slot
               (Defect-3), used verbatim when present. The coercion catalog is always the full one.
               SCOPE variables (Element/Indeks — array-transform wave 2): an arg INSIDE an element
               pipeline threads them via `slotProps.scopeVariables`; they PREFIX the pool so the picker
               offers them (contextual, only inside that pipeline). Absent everywhere else → no change. -->
          <slot
            name="argVariable"
            v-bind="slotProps"
            :variables="[...(scopeVariablesOf(slotProps) ?? []), ...argVariables]"
            :operations-catalog="operationsCatalog"
            :result-types="slotProps.resultTypes ?? argResultTypes(slotProps.arg)"
          />
        </template>
        <!-- The object/file element pipeline (map/filter/sort over a repeater / file array, wave 3) is
             now a SELF-CONTAINED inline builder inside VariablePipelineEditor (F5) — a subfield Select +
             the same nested pipeline editor — so no `#elementScopeUnion` slot is threaded here any more. -->
      </VariablePipelineEditor>

      <!-- 5. TERMINAL STATUS — green when the pipeline returns an accepted type. -->
      <div
        class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1 rounded-next-md border px-next-3 py-next-2 text-next-sm"
        :class="satisfied
          ? 'border-next-success/40 bg-next-success-subtle'
          : 'border-next-warning/40 bg-next-warning-subtle'"
        :data-type-satisfied="satisfied ? 'true' : 'false'"
        aria-live="polite"
      >
        <Icon
          :name="satisfied ? 'check-circle' : 'alert-triangle'"
          :class="satisfied ? 'text-next-success' : 'text-next-warning'"
          aria-hidden="true"
        />
        <span class="text-next-fg">
          {{ t('workflows.field.returns') }}
          <span class="font-next-medium">{{ getVariableIconLabel(resultTypeLabel) }}</span>
        </span>
        <!-- Choice-target mismatch: the type matches but it must END on a choice op. -->
        <span v-if="needsChoiceOp" class="text-next-muted-foreground">
          · {{ t('workflows.field.needsChoice') }}
        </span>
        <span v-else-if="!satisfied && expectedTypesLabel" class="text-next-muted-foreground">
          · {{ t('workflows.field.expected', '', { types: expectedTypesLabel }) }}
        </span>
      </div>
    </template>

    <!-- Source-switch confirm (only when a non-empty pipeline would be dropped). -->
    <ConfirmDialog
      v-model:open="resetConfirmOpen"
      :title="t('variableBrowser.resetTitle')"
      :message="t('variableBrowser.resetMessage')"
      :confirm-label="t('variableBrowser.resetConfirm')"
      :cancel-label="t('variableBrowser.resetCancel')"
      @confirm="confirmChange"
      @cancel="cancelChange"
    />
  </div>
</template>
