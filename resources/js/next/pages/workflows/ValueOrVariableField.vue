<script setup lang="ts">
// ValueOrVariableField — the value-or-variable add-on (§4.9.1), REDESIGNED (SF3.3-5).
//
// The field now reads as ONE input: a COMPACT mode toggle (two small icon buttons,
// pencil = Value / braces = Variable, `aria-pressed`) lives as a leading adornment
// INSIDE the bordered box, and the rest of the box holds:
//   • VALUE mode → the field's native control (a Select / DatePicker, provided by the
//     host via the default scoped slot), flattened so it shares the ONE border.
//   • VARIABLE mode → the compatible-catalog-variable picker (a Select) until a
//     variable is chosen; once chosen, a CHIP token (type icon + name + an optional
//     "N ops" marker + a trailing ✕) rendered AS the field's value.
//
// Operations no longer sit inline under the field: clicking the chip opens an
// OPERATIONS MODAL whose BODY is the shared `VariableReferenceEditor` (a read-only source
// header, the nullable-gated typed default, the shared VariablePipelineEditor and a live
// "Returns: <type>" status), plus a Save that is blocked until the pipeline's terminal type
// satisfies the field's required types.
//
// v-model stays the canonical `WorkflowFieldValue<T>` union
// (`{kind:'literal',value} | {kind:'variable',ref,pipeline?}`); a non-empty pipeline
// rides in the variable arm as the `{op,args}` wire, an empty pipeline is a plain
// identity ref. Hydration is unchanged. The host feeds `:variables` (already filtered
// to the compatible types via `variablesOfType`), the `:operations-catalog`, and the
// `:result-types` the pipeline MUST terminate on (priority → enum|text; date → date).
//
// ARG-VARIABLES (phase-4b): when the host also feeds `:arg-variables` (the show-all pool), a
// VALUE-TYPED op argument in the modal pipeline gains the SAME value/variable toggle — this field is
// RECURSIVE: it fills the reference editor's `argVariable` slot with ITSELF (bound to the arg via the
// arg↔union adapters below), one `:depth` deeper each level. The pipeline editor enforces the depth
// cap (`MAX_ARG_VARIABLE_DEPTH`), so beyond it an arg is literal-only — the FE can never build past
// what the backend accepts. A literal arg still serializes byte-identically (no `{kind}` wrapper).
//
// ── B3: THIS FILE IS AN ADAPTER ────────────────────────────────────────────────
// The modal BODY (source header → default-when-empty → pipeline → terminal status, plus the
// recursive arg-variable wiring) is no longer implemented here: it is the SHARED
// `ui/variables/VariableReferenceEditor`, which speaks the wire-free `VariableRefDraft`
// (`{source, path, type, pipeline, default}`). What stays here is exactly the workflows-specific
// part: the field surface (mode toggle, chip, picker), the `WorkflowFieldValue` union ⇄ draft
// mapping, and the field-level type-error channel. The editor's own change-source affordance is
// turned OFF here because this surface's picker lives on the field (behind the chip's ✕).
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import PipelineArgLiteralInput from '../../ui/editor/extensions/PipelineArgLiteralInput.vue';
import VariableTypeIcon from '../../ui/editor/extensions/VariableTypeIcon.vue';
import VariableBrowserPopover from '../../ui/variables/VariableBrowserPopover.vue';
import VariableReferenceEditor from '../../ui/variables/VariableReferenceEditor.vue';
import {
  descriptorToOperation,
  getVariableIconLabel,
  pipelineSatisfies,
} from '../../ui/editor/extensions/operationHelpers';
import { useI18n } from '../../app/i18n';
import { buildVariableTree, findNodeByPath, nodeIcon } from '../../ui/variables/variableTree';
import type { VariableLiteral, VariableNode, VariableRefDraft } from '../../ui/variables/types';
import { argToUnion, unionToArg } from './argVariableAdapters';
import { CONDITION_LIMITS } from './workflowConditions';
import type {
  CatalogVariable,
  WorkflowFieldPipelineStep,
  WorkflowFieldValue,
  WorkflowVariableRef,
  WorkflowVariableType,
} from './types';
import type {
  OperationTypeDescriptor,
  VariableArgValue,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';

type FieldMode = 'value' | 'variable';

const props = withDefaults(
  defineProps<{
    /**
     * The catalog variables offered in variable mode — ALREADY filtered to the
     * compatible types by the host (`variablesOfType`). Empty ⇒ the toggle stays
     * available but the picker shows its empty state.
     */
    variables: CatalogVariable[];
    /**
     * The merged operations catalog (labels attached) the pipeline builder runs on.
     * Empty (the default) ⇒ NO operations modal is offered — the chip is a plain
     * removable pill (backward compatible for other hosts).
     */
    operationsCatalog?: VariableOperationDefinition[];
    /**
     * The workflow types the (optional) pipeline MUST terminate on for this field
     * (priority → ['enum']; date fields → ['date']). Empty ⇒ no type gate.
     */
    resultTypes?: WorkflowVariableType[];
    /**
     * The DESTINATION field's option set for a "choice"/enum field (task priority →
     * urgent|high|medium|low). When present the pipeline must END on a CHOICE-producing
     * op that targets THESE values (enum_to_choice / match_to_choice) — they are the
     * options fed into those ops. Empty (default) ⇒ not a choice field.
     */
    targetOptions?: VariableOption[];
    /** Client cap on pipeline length (defaults to the backend's 10-step limit). */
    maxOperations?: number;
    /** aria-label for the variable-mode `Select` (the field's own label context). */
    pickerLabel?: string;
    /** Placeholder for the variable-mode `Select`. */
    pickerPlaceholder?: string;
    /**
     * Overrides the "Variable" mode toggle's tooltip + aria-label. Used by a STRUCTURAL op-argument
     * (a sourceMap / choiceRules control) so the toggle reads "Use a variable for the whole mapping /
     * rule set" — making it clear that Variable mode supplies the WHOLE structure from a variable
     * rather than building it inline. Default ⇒ the generic "Variable".
     */
    variableModeLabel?: string;
    /**
     * True when the HOST already renders a server/validation error for this field (its
     * wrapping FormField). The field then SUPPRESSES its own inline client type-error
     * line so only ONE textual message shows (finding 4). The error SKIN / "action
     * required" pill / aria-invalid remain (they carry no duplicate text).
     */
    externalErrorPresent?: boolean;
    /**
     * The show-all pool of variables an OP ARGUMENT inside this field's pipeline may reference
     * (phase-4b). When provided, a value-typed op argument gains the SAME value/variable toggle this
     * field has — recursively, bounded by the depth cap. Empty (the default) ⇒ op args stay
     * literal-only. Usually the SAME `allValueVariables` pool the field's own picker uses.
     */
    argVariables?: CatalogVariable[];
    /**
     * This field's ARG-VARIABLE nesting depth (phase-4b): the depth of the pipeline it hosts in its
     * ops modal. A top-level field = 0; a field rendered AS an arg-variable sits one level deeper
     * (threaded by the parent editor's `argVariable` slot). Passed to the modal's pipeline editor so
     * a value-typed arg offers the toggle only while `depth < MAX_ARG_VARIABLE_DEPTH`.
     */
    depth?: number;
    /**
     * VARIABLE-ONLY mode (array-transform wave 3): the field can ONLY hold a variable reference — the
     * value toggle + literal control are hidden. Used by the scope-rooted element-pipeline UNION control
     * (an object/file map/filter/sort picks an `element.<field>` subfield; there is no literal element).
     */
    variableOnly?: boolean;
    /** Disable the whole field (toggle + controls). */
    disabled?: boolean;
  }>(),
  {
    operationsCatalog: () => [],
    resultTypes: () => [],
    targetOptions: () => [],
    externalErrorPresent: false,
    argVariables: () => [],
    depth: 0,
    variableOnly: false,
    disabled: false,
  },
);

const model = defineModel<WorkflowFieldValue | null>({ default: null });

/**
 * Emitted whenever the SAVED field's type-satisfaction changes: `{expected}` when a
 * variable is picked but its saved pipeline does NOT satisfy the field's terminal
 * contract (the host lights its per-field error channel), or `null` when it does (or
 * the field is a literal). Mirrors the backend's field-level 422.
 */
const emit = defineEmits<{ 'update:typeError': [null | { expected: string }] }>();

const { t } = useI18n();

/** The active mode, derived from the union kind (literal by default). */
const isVariable = computed(() => model.value?.kind === 'variable');

/** The picked variable's ref (variable mode only). */
const pickedRef = computed<WorkflowVariableRef | null>(() =>
  model.value?.kind === 'variable' ? model.value.ref : null,
);

/**
 * The BROWSED tree — the shared model (`ui/variables`) over the host's flat list. No slot
 * policy: this surface accepts every selectable variable it is given (the terminal-type
 * gate lives on the pipeline, not on the picker), so a plain `{}` says exactly that.
 *
 * It is ALSO the single lookup for a SAVED ref: the tree already carries the flat list PLUS
 * the composed children an object container expands to (a file subfield / object-global
 * field), so one `findNodeByPath` resolves the chip's name/glyph/markers and the modal's
 * base type — with no second, drifting resolution path.
 */
const variableTree = computed<VariableNode[]>(() => buildVariableTree(props.variables, {}));

/** The tree node the picked ref points at (null for an off-catalog / stale path). */
const pickedNode = computed<VariableNode | null>(() =>
  findNodeByPath(variableTree.value, pickedRef.value?.path),
);

/** The chip label: the catalog name, or the raw path when the var is off-list. */
const pickedLabel = computed(() => pickedNode.value?.label ?? pickedRef.value?.path ?? '');

/** The chip icon: the TRUE workflow type's glyph (object base → braces, §7.5 / §refinement 5). */
const pickedIcon = computed(() =>
  pickedNode.value
    ? nodeIcon(pickedNode.value)
    : nodeIcon({ type: pickedRef.value?.type ?? 'text', base: 'text' }),
);

/** The literal value exposed to the default slot (null in variable mode). */
const literalValue = computed(() =>
  model.value?.kind === 'literal' ? model.value.value : null,
);

/**
 * Pick a variable from the browser: a fresh ref RESETS any pipeline (its base type changes).
 * The emitted ref is BYTE-IDENTICAL to what the flat/tree pickers emitted before — the node
 * carries the variable's own `{source, path, type}` (a descriptor child inherits its
 * container's source and composes `<parent>.<key>`), nothing is re-derived.
 */
function pickVariable(node: VariableNode): void {
  model.value = {
    kind: 'variable',
    ref: { source: node.source, path: node.path, type: node.type },
  };
}

/** Update the LITERAL value from the slot control, keeping the union normalized. */
function setLiteral(value: unknown): void {
  model.value = { kind: 'literal', value };
}

// The mode reflects EITHER an active variable ref OR the explicit "I want variable
// mode but haven't picked yet" intent (so the picker shows before a selection exists).
// Any concrete literal value resets the intent to value mode.
const intendVariable = ref(props.variableOnly || model.value?.kind === 'variable');
watch(
  () => model.value?.kind,
  (kind) => {
    if (kind) intendVariable.value = kind === 'variable';
  },
);

/**
 * The active mode ('value' | 'variable') driving the toggle + which body renders. In `variableOnly`
 * mode (the scope-rooted element-pipeline union) it is ALWAYS 'variable' — there is no literal element.
 */
const mode = computed<FieldMode>(() =>
  props.variableOnly || isVariable.value || intendVariable.value ? 'variable' : 'value',
);

/** Flip modes. Value mode restores a literal; variable opens the picker. */
function setMode(next: FieldMode): void {
  if (props.disabled || props.variableOnly || next === mode.value) return;
  if (next === 'variable') {
    intendVariable.value = true;
  } else {
    intendVariable.value = false;
    model.value = { kind: 'literal', value: literalValue.value ?? null };
  }
}

/** Remove the picked variable → return to value mode. */
function removeVariable(): void {
  if (props.disabled) return;
  intendVariable.value = false;
  model.value = { kind: 'literal', value: null };
}

// --- Pipeline base (the picked variable's TRUE type) -------------------------
/** Whether an operations modal may be offered at all (needs a catalog). */
const pipelineEnabled = computed(() => props.operationsCatalog.length > 0);

/** The picked variable's TRUE type — the pipeline's base (source) type. */
const baseType = computed<VariablePrimitive>(
  () => (pickedNode.value?.type ?? pickedRef.value?.type ?? 'text') as VariablePrimitive,
);

/** The picked variable's RAW catalog entry (carries the full descriptor, `fields` included). */
const pickedCatalogVariable = computed<CatalogVariable | null>(
  () => props.variables.find((variable) => variable.path === pickedRef.value?.path) ?? null,
);

/**
 * The picked variable's FULL source descriptor when it is an object/file ARRAY (array-transform wave
 * 3) — a repeater / file array degrades its flat type to `text`, so only the descriptor tells the
 * pipeline it is an array of a structured element (offering its array ops + element subfields). For
 * every scalar/enum/multi source it stays undefined and the flat `baseType` behaviour is byte-identical.
 */
const baseDescriptor = computed<OperationTypeDescriptor | undefined>(() => {
  const descriptor = pickedCatalogVariable.value?.descriptor;
  if (descriptor?.array && (descriptor.base === 'object' || descriptor.base === 'file')) {
    return descriptorToOperation(descriptor);
  }
  return undefined;
});

const maxOperations = computed(() => props.maxOperations ?? CONDITION_LIMITS.maxPipelineSteps);

/** The count of operations on the CURRENT saved pipeline (chip marker). */
const operationCount = computed(() =>
  model.value?.kind === 'variable' ? model.value.pipeline?.length ?? 0 : 0,
);

// --- Wire <-> editor step projection (the same shape a condition pipeline uses) --
let pipelineStepSeq = 0;
function nextPipelineStepId(): string {
  pipelineStepSeq += 1;
  return `vov-step-${pipelineStepSeq}`;
}

function toWireStep(step: VariablePipelineStep): WorkflowFieldPipelineStep {
  return { op: step.operationId, args: step.args as Record<string, unknown> };
}

function fromWireStep(wire: WorkflowFieldPipelineStep): VariablePipelineStep {
  return {
    stepId: nextPipelineStepId(),
    operationId: wire.op,
    args: (wire.args ?? {}) as VariablePipelineStep['args'],
    outputType:
      props.operationsCatalog.find((op) => op.id === wire.op)?.outputType ?? 'text',
  };
}

// --- Field-level type satisfaction (SF over the SAVED model) -----------------
// The field's required terminals as editor primitives (WorkflowVariableType ≡
// VariablePrimitive), for both the pure predicate and the human hint.
const resultTypesPrimitive = computed<VariablePrimitive[]>(() =>
  props.resultTypes.map((type) => type as VariablePrimitive),
);
/** The human list of accepted terminals, for the mismatch hint. */
const expectedTypesLabel = computed(() =>
  props.resultTypes.map((type) => getVariableIconLabel(type as VariablePrimitive)).join(' / '),
);
/** The SAVED wire pipeline projected onto editor steps (empty for a literal / identity ref). */
const savedPipeline = computed<VariablePipelineStep[]>(() =>
  model.value?.kind === 'variable' ? (model.value.pipeline ?? []).map(fromWireStep) : [],
);
/**
 * Whether the SAVED model satisfies the field (literal ⇒ always; variable ⇒ its saved
 * pipeline must satisfy the terminal + choice-target contract). Drives the field-level
 * error surfacing OUTSIDE the modal.
 */
const fieldSatisfied = computed(
  () =>
    !pickedRef.value ||
    pipelineSatisfies(
      props.operationsCatalog,
      baseType.value,
      savedPipeline.value,
      resultTypesPrimitive.value,
      props.targetOptions,
      baseDescriptor.value,
    ),
);

// Report the field's type-satisfaction to the host on every change (immediate) so its
// per-field error channel + Save gate stay in sync with the SAVED model.
watch(
  [fieldSatisfied, pickedRef],
  () => {
    emit(
      'update:typeError',
      pickedRef.value && !fieldSatisfied.value ? { expected: expectedTypesLabel.value } : null,
    );
  },
  { immediate: true },
);

// --- Operations MODAL (SF3.5) — a LOCAL draft over the shared reference editor ----
// The modal edits a normalized `VariableRefDraft` CLONE, so Cancel discards edits (legacy
// parity) and the shared editor never sees a wire shape. Save projects it back onto the union.
const modalOpen = ref(false);
const modalDraft = ref<VariableRefDraft | null>(null);

/** Open the operations modal, seeding a draft from the saved union. */
function openModal(): void {
  const ref = pickedRef.value;
  if (props.disabled || !ref || !pipelineEnabled.value) return;
  const saved = model.value?.kind === 'variable' ? model.value : null;
  modalDraft.value = {
    source: ref.source,
    path: ref.path,
    type: ref.type,
    pipeline: (saved?.pipeline ?? []).map(fromWireStep),
    // Seed the working default from the saved model (null ⇒ no default set).
    default: (saved?.default as VariableLiteral) ?? null,
  };
  modalOpen.value = true;
}

/**
 * Whether the DRAFT's pipeline satisfies the field's terminal contract — the SAME predicate
 * the field-level gate (and the editor's own status strip) uses, so the modal Save also blocks
 * an identity enum / a plain terminal for a choice target.
 */
const modalTypeSatisfied = computed(() =>
  pipelineSatisfies(
    props.operationsCatalog,
    baseType.value,
    modalDraft.value?.pipeline ?? [],
    resultTypesPrimitive.value,
    props.targetOptions,
    baseDescriptor.value,
  ),
);

const modalTitle = computed(() =>
  t('workflows.field.operations', '', { name: pickedLabel.value }),
);

/** Whether a drafted default counts as "unset" (omit the key). `false` / `0` are meaningful. */
function defaultIsEmpty(value: VariableLiteral): boolean {
  return value == null || value === '';
}

/**
 * Commit the draft onto the union (an empty pipeline / default are OMITTED, so a plain identity
 * ref stays byte-identical) + close.
 */
function saveModal(): void {
  const draft = modalDraft.value;
  if (!draft || !modalTypeSatisfied.value) return;
  const next: WorkflowFieldValue = {
    kind: 'variable',
    ref: { source: draft.source, path: draft.path, type: draft.type },
  };
  if (draft.pipeline.length) next.pipeline = draft.pipeline.map(toWireStep);
  if (!defaultIsEmpty(draft.default)) next.default = draft.default;
  model.value = next;
  modalOpen.value = false;
}

// --- Op ARGUMENT value-or-variable adapter (phase-4b) ------------------------
// A pipeline op's value-typed argument is stored as EITHER a bare literal (as today) OR the SAME
// {kind:'variable', ref, pipeline?, default?} union this field emits. The shared reference editor
// hosts each such arg through its `argVariable` slot (template below) — already parameterized with
// the arg's variable POLICY (pool / coercion catalog / terminal gate / toggle label) — and we fill
// it with a RECURSIVE ValueOrVariableField whose v-model is the WorkflowFieldValue union.
//
// The union ⇄ raw-arg translation itself lives in `./argVariableAdapters` (B4): the markdown chip
// panel's pipeline needs the identical mapping, so there is exactly ONE copy of it.
//
// STRUCTURAL ENTRIES (Defect-3): a sourceMap target / choiceRules `then` arrives here as a per-ENTRY
// arg-variable (the arg it hosts is the SYNTHETIC leaf arg the literal control built). A CHOICE entry
// (its `resultTypes` is exactly `['enum']`) must map INTO the destination options, so — unlike a whole-arg
// option variable — it threads `targetOptions` to the nested field, enforcing the same choice-op rule the
// backend does per entry. Text/number/date entries thread none.
function isChoiceEntryTypes(types: unknown): boolean {
  return Array.isArray(types) && types.length === 1 && types[0] === 'enum';
}
</script>

<template>
  <div class="next-vov-field">
    <!-- The ONE input-like box: a compact mode toggle (leading) + the body. When a
         picked variable's SAVED pipeline does not satisfy the field, the box takes a
         danger skin (mirrors the focus-within ring) — the field itself reads "action
         required", not just the modal. -->
    <div class="next-vov" :class="{ 'is-disabled': disabled, 'is-error': pickedRef && !fieldSatisfied }">
      <!-- Compact mode toggle: two icon buttons (aria-pressed), NOT full cards. Hidden in
           variable-only mode (the scope-rooted union has no literal alternative). -->
      <div v-if="!variableOnly" class="next-vov__toggle" role="group" :aria-label="t('workflows.field.modeLabel')">
        <Tooltip :label="t('workflows.field.modeValue')">
          <Button
            size="icon-xs"
            leading-icon="pencil"
            :variant="mode === 'value' ? 'secondary' : 'ghost'"
            :aria-pressed="mode === 'value' ? 'true' : 'false'"
            :aria-label="t('workflows.field.modeValue')"
            :disabled="disabled"
            @click="setMode('value')"
          />
        </Tooltip>
        <Tooltip :label="variableModeLabel ?? t('workflows.field.modeVariable')">
          <Button
            size="icon-xs"
            leading-icon="braces"
            :variant="mode === 'variable' ? 'secondary' : 'ghost'"
            :aria-pressed="mode === 'variable' ? 'true' : 'false'"
            :aria-label="variableModeLabel ?? t('workflows.field.modeVariable')"
            :disabled="disabled"
            @click="setMode('variable')"
          />
        </Tooltip>
      </div>

      <!-- Body: the native control (VALUE) or the chip / picker (VARIABLE). -->
      <div class="next-vov__body">
        <!-- VALUE mode: the field's native control (host slot), flattened into the box. -->
        <div v-if="mode === 'value'" class="next-vov__control [&>*]:w-full [&>*]:min-w-0">
          <slot :value="literalValue" :set-value="setLiteral" :disabled="disabled" />
        </div>

        <!-- VARIABLE mode: a chip token once picked (opens the ops modal), else the picker. -->
        <template v-else>
          <span v-if="pickedRef" class="next-vov__token" :class="disabled ? 'opacity-60' : ''">
            <!-- The token body opens the operations modal (aria-labelled to say so). -->
            <button
              type="button"
              class="next-vov__token-main"
              :disabled="disabled"
              :aria-invalid="!fieldSatisfied ? 'true' : undefined"
              :aria-label="t('workflows.field.editOperations', '', { name: pickedLabel })"
              @click="openModal"
            >
              <VariableTypeIcon
                :icon="pickedIcon"
                :nullable="pickedNode?.nullable"
                :array="pickedNode?.array"
                class="next-vov__token-icon"
              />
              <span class="next-vov__token-label">{{ pickedLabel }}</span>
              <!-- A danger pill when the saved pipeline does not satisfy the field (the
                   fix-it CTA — the chip still opens the modal); else a neutral "N ops"
                   marker when the pipeline is non-empty (so ops are discoverable). -->
              <span v-if="!fieldSatisfied" class="next-vov__token-error">
                <Icon name="alert-triangle" class="next-vov__token-error-icon" aria-hidden="true" />
                {{ t('workflows.field.actionRequired') }}
              </span>
              <span v-else-if="operationCount" class="next-vov__token-ops">
                {{ t('workflows.field.operationsCount', '', { count: operationCount }) }}
              </span>
            </button>
            <!-- Trailing ✕ (X-before-nothing trailing rule): removes the variable. -->
            <button
              type="button"
              class="next-vov__token-x"
              :disabled="disabled"
              :aria-label="t('workflows.field.removeVariable')"
              @click="removeVariable"
            >
              <Icon name="x" aria-hidden="true" />
            </button>
          </span>
          <div v-else class="next-vov__control [&>*]:w-full [&>*]:min-w-0">
            <VariableBrowserPopover
              :nodes="variableTree"
              :disabled="disabled"
              :placeholder="pickerPlaceholder ?? t('workflows.field.pickVariable')"
              :label="pickerLabel ?? t('workflows.field.pickVariable')"
              @select="pickVariable"
            />
          </div>
        </template>
      </div>
    </div>

    <!-- Field-level type error (OUTSIDE the modal): the saved variable does not satisfy
         the field yet. The chip body is the fix-it CTA (opens the ops modal). SUPPRESSED
         when the host already shows a server/validation message (finding 4 — no double
         message); the danger skin + "action required" pill still convey the state. -->
    <p v-if="pickedRef && !fieldSatisfied && !externalErrorPresent" class="next-vov__error" role="alert">
      {{ t('workflows.field.typeError', '', { expected: expectedTypesLabel }) }}
    </p>

    <!-- Operations modal: the SHARED reference editor (source header → default → pipeline →
         terminal status) + this field's own Save gate (SF3.5). Only mounted for a picked
         variable; teleports itself to <body>. -->
    <Modal v-if="pickedRef" v-model:open="modalOpen" size="lg" :aria-label="modalTitle">
      <template #title>{{ modalTitle }}</template>

      <VariableReferenceEditor
        v-model="modalDraft"
        :nodes="variableTree"
        :change-source="false"
        :base-descriptor="baseDescriptor"
        :operations-catalog="operationsCatalog"
        :result-types="resultTypesPrimitive"
        :target-options="targetOptions"
        :max-steps="maxOperations"
        :depth="depth"
        :arg-variables="argVariables"
        :disabled="disabled"
      >
        <!-- ARG-VARIABLE (phase-4b): ANY op argument may itself be a variable. The pipeline editor
             decides WHETHER to offer this (within the depth cap) and the reference editor decides
             WHAT it may be (the arg's policy: the show-all pool, the coercion catalog — NONE for a
             structural arg — the terminal gate, and the clarifying toggle label). We supply the SAME
             value-or-variable field RECURSIVELY, with the arg's literal control
             (PipelineArgLiteralInput) filling its VALUE slot, fed the running source/target options
             so an option/map/rules editor has its choices.
             `target-options` is threaded ONLY for a CHOICE structural ENTRY (Defect-3): its pipeline must
             map into the destination options; a whole-arg option variable / value entry threads none. -->
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
            @update:model-value="(u) => setValue(unionToArg(u, arg))"
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
        <!-- The object/file element pipeline (map/filter/sort over a repeater / file array) is now a
             SELF-CONTAINED inline builder inside VariablePipelineEditor (F5) — a subfield Select + the
             same nested pipeline editor — so this surface no longer fills an `#elementScopeUnion` slot. -->
      </VariableReferenceEditor>

      <template #footer>
        <Button variant="outline" type="button" @click="modalOpen = false">
          {{ t('common.cancel') }}
        </Button>
        <Button variant="primary" type="button" :disabled="!modalTypeSatisfied" @click="saveModal">
          {{ t('common.save') }}
        </Button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
/* SF3.3-4 — the ONE input-like box. The OUTER box owns the border + the focus/hover
   line; every NESTED field control (Select / DatePicker each render their own
   FieldShell) is flattened below so the whole thing reads as a single field. */
.next-vov {
  display: flex;
  align-items: stretch;
  width: 100%;
  min-height: 2.5rem;
  border: 1px solid var(--color-next-input);
  border-radius: var(--radius-next-md);
  background-color: var(--color-next-card);
  transition:
    border-color var(--duration-next-fast) var(--ease-next-standard),
    box-shadow var(--duration-next-fast) var(--ease-next-standard);
}
.next-vov:not(.is-disabled):hover {
  border-color: color-mix(in srgb, var(--color-next-fg) 30%, transparent);
}
.next-vov:not(.is-disabled):focus-within {
  border-color: var(--color-next-ring);
  box-shadow: inset 0 0 0 1.5px var(--color-next-ring);
}
/* Error skin: a danger border + inset danger ring (mirrors the focus-within rule) so
   the field itself reads "action required". Focus-within still wins (the ring cue). */
.next-vov.is-error {
  border-color: var(--color-next-danger);
  box-shadow: inset 0 0 0 1.5px var(--color-next-danger);
}
.next-vov.is-error:not(.is-disabled):focus-within {
  border-color: var(--color-next-ring);
  box-shadow: inset 0 0 0 1.5px var(--color-next-ring);
}
.next-vov.is-disabled {
  opacity: 0.6;
  background-color: var(--color-next-muted);
}

/* Field-level error line under the box (mirrors FormField's error styling). */
.next-vov__error {
  margin-top: 0.375rem;
  font-size: 0.8rem;
  line-height: 1.3;
  color: var(--color-next-danger);
}

/* Leading compact toggle: sits inside on the left, divided from the body. */
.next-vov__toggle {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.125rem;
  padding-inline: 0.25rem;
  border-inline-end: 1px solid var(--color-next-border);
}

/* Body fills the rest of the box; the control region lets its inner control fill. */
.next-vov__body {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  min-width: 0;
}
.next-vov__control {
  display: flex;
  flex: 1 1 auto;
  min-width: 0;
}

/* Flatten a nested field control so the outer box owns the single-input look. The
   inner FieldShell keeps its (fixed) height + padding; only its border / ring / fill
   are neutralized. `!important` is required to beat FieldShell's own :focus-within
   ring, which is a high-specificity rule. */
.next-vov__body :deep(.next-field-shell) {
  border-color: transparent !important;
  background-color: transparent !important;
  box-shadow: none !important;
}

/* The variable CHIP token, rendered AS the field's value (accent-tinted, §7.7). */
.next-vov__token {
  display: inline-flex;
  min-width: 0;
  max-width: 100%;
  align-items: center;
  gap: 0.25rem;
  margin-inline: 0.5rem;
  padding: 0.125rem 0.125rem 0.125rem 0.5rem;
  border-radius: var(--radius-next-md);
  background-color: var(--color-next-accent);
  color: var(--color-next-accent-foreground);
  font-weight: var(--font-weight-next-medium);
  font-size: 0.85rem;
  line-height: 1.4;
}
.next-vov__token-main {
  display: inline-flex;
  min-width: 0;
  align-items: center;
  gap: 0.25rem;
  color: inherit;
  outline: none;
  cursor: pointer;
}
.next-vov__token-main:disabled,
.next-vov__token-x:disabled {
  cursor: not-allowed;
}
.next-vov__token-icon {
  flex-shrink: 0;
  opacity: 0.85;
}
.next-vov__token-label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.next-vov__token-ops {
  flex-shrink: 0;
  border-radius: var(--radius-next-full);
  background-color: color-mix(in srgb, var(--color-next-accent-foreground) 18%, transparent);
  padding-inline: 0.375rem;
  font-size: 0.7rem;
  font-weight: var(--font-weight-next-semibold);
}
/* The "action required" danger pill that replaces the neutral ops marker on a type
   mismatch — high-contrast on the accent chip so it reads as a warning, not a count. */
.next-vov__token-error {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.125rem;
  border-radius: var(--radius-next-full);
  background-color: var(--color-next-danger);
  color: var(--color-next-danger-foreground);
  padding-inline: 0.375rem;
  font-size: 0.7rem;
  font-weight: var(--font-weight-next-semibold);
}
.next-vov__token-error-icon {
  width: 0.75rem;
  height: 0.75rem;
}
.next-vov__token-x {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  border-radius: var(--radius-next-sm);
  padding: 0.125rem;
  color: inherit;
  cursor: pointer;
}
.next-vov__token-x:not(:disabled):hover {
  background-color: color-mix(in srgb, var(--color-next-accent-foreground) 15%, transparent);
}
.next-vov__token-main:focus-visible,
.next-vov__token-x:focus-visible {
  outline: 2px solid var(--color-next-ring);
  outline-offset: 1px;
}
</style>
