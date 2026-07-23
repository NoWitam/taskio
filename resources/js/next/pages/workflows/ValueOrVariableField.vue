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
// OPERATIONS MODAL (mirrors WorkflowConditionModal — a read-only source header, the
// shared VariablePipelineEditor, a live "Returns: <type>" status, and a Save that is
// blocked until the pipeline's terminal type satisfies the field's required types).
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
// RECURSIVE: it fills the pipeline editor's `argVariable` slot with ITSELF (bound to the arg via the
// arg↔union adapters below), one `:depth` deeper each level. The pipeline editor enforces the depth
// cap (`MAX_ARG_VARIABLE_DEPTH`), so beyond it an arg is literal-only — the FE can never build past
// what the backend accepts. A literal arg still serializes byte-identically (no `{kind}` wrapper).
import { computed, ref, watch } from 'vue';
import Select from '../../ui/forms/Select.vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import VariablePipelineEditor from '../../ui/editor/extensions/VariablePipelineEditor.vue';
import PipelineArgLiteralInput from '../../ui/editor/extensions/PipelineArgLiteralInput.vue';
import VariableTypeIcon from '../../ui/editor/extensions/VariableTypeIcon.vue';
import VariableTreePicker from './VariableTreePicker.vue';
import WorkflowGlobalValueField from './WorkflowGlobalValueField.vue';
import {
  argVariablePolicy,
  getVariableIconLabel,
  pipelineSatisfies,
  resolveType,
} from '../../ui/editor/extensions/operationHelpers';
import { useI18n } from '../../app/i18n';
import {
  flattenPickerNodes,
  variableIcon,
  variableNodeIcon,
  variableOptionList,
  variablePickerTree,
} from './workflowVariables';
import { CONDITION_LIMITS } from './workflowConditions';
import type {
  CatalogVariable,
  WorkflowFieldPipelineStep,
  WorkflowFieldValue,
  WorkflowGlobalScalarBase,
  WorkflowVariableRef,
  WorkflowVariableType,
} from './types';
import type {
  ArgVariableValue,
  ChoiceRule,
  VariableArgValue,
  VariableOperationArgumentDefinition,
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
 * Every pickable variable the tree offers — the host's flat list PLUS the composed children an object
 * container expands to (a file subfield / object-global field). Used to resolve a saved ref (which may
 * be a composed `<parent>.<key>` path) back to its full CatalogVariable for the chip + default control.
 */
const pickerVariables = computed<CatalogVariable[]>(() =>
  flattenPickerNodes(variablePickerTree(props.variables)).map((n) => n.variable),
);

/** The full catalog variable for the picked ref (for the chip name/icon/markers + default + options). */
const pickedVariable = computed<CatalogVariable | null>(() => {
  const ref = pickedRef.value;
  if (!ref) return null;
  return (
    pickerVariables.value.find((v) => v.path === ref.path) ??
    props.variables.find((v) => v.path === ref.path) ??
    null
  );
});

/** The picked variable's structured descriptor (drives the default control + type-icon markers). */
const pickedDescriptor = computed(() => pickedVariable.value?.descriptor ?? null);

/** The chip label: the catalog name, or the raw path when the var is off-list. */
const pickedLabel = computed(() => pickedVariable.value?.name ?? pickedRef.value?.path ?? '');

/** The chip icon: the TRUE workflow type's glyph (object base → braces, §7.5 / §refinement 5). */
const pickedIcon = computed(() =>
  pickedVariable.value
    ? variableNodeIcon(pickedVariable.value)
    : variableIcon(pickedRef.value?.type ?? 'text'),
);

/** The literal value exposed to the default slot (null in variable mode). */
const literalValue = computed(() =>
  model.value?.kind === 'literal' ? model.value.value : null,
);

/** Pick a variable from the tree picker: a fresh ref RESETS any pipeline (its base type changes). */
function pickVariable(variable: CatalogVariable): void {
  model.value = {
    kind: 'variable',
    ref: { source: variable.source, path: variable.path, type: variable.type },
  };
}

/** Update the LITERAL value from the slot control, keeping the union normalized. */
function setLiteral(value: unknown): void {
  model.value = { kind: 'literal', value };
}

// The mode reflects EITHER an active variable ref OR the explicit "I want variable
// mode but haven't picked yet" intent (so the picker shows before a selection exists).
// Any concrete literal value resets the intent to value mode.
const intendVariable = ref(model.value?.kind === 'variable');
watch(
  () => model.value?.kind,
  (kind) => {
    if (kind) intendVariable.value = kind === 'variable';
  },
);

/** The active mode ('value' | 'variable') driving the toggle + which body renders. */
const mode = computed<FieldMode>(() => (isVariable.value || intendVariable.value ? 'variable' : 'value'));

/** Flip modes. Value mode restores a literal; variable opens the picker. */
function setMode(next: FieldMode): void {
  if (props.disabled || next === mode.value) return;
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

// --- Per-reference DEFAULT (phase-1b → §refinement 1) ------------------------
// An OPTIONAL literal the backend substitutes when the referenced value resolves empty (null/''). It
// now lives ONLY inside the operations modal (not on the field surface), shows ONLY for a NULLABLE
// variable, and is TYPED to the variable's base (reusing WorkflowGlobalValueField). Empty ⇒ the
// `default` key is OMITTED, so a ref without a default stays byte-identical. Boolean is a TRI-STATE (no
// default / yes / no) so "no default" never silently serializes `false`.
const DEFAULT_BASES: WorkflowGlobalScalarBase[] = ['text', 'number', 'boolean', 'date'];

/** The typed-control base for the default, or null when the picked variable has none / is unsupported. */
const defaultBase = computed<WorkflowGlobalScalarBase | 'enum' | null>(() => {
  const base = pickedDescriptor.value?.base;
  if (base === 'enum') return 'enum';
  if (base && (DEFAULT_BASES as string[]).includes(base)) return base as WorkflowGlobalScalarBase;
  return null;
});

/** Whether to offer the default control at all: the variable must be NULLABLE + a typed-able base. */
const showDefault = computed(
  () => pickedDescriptor.value?.nullable === true && defaultBase.value !== null,
);

/** The modal's WORKING default value (seeded on open); null / '' ⇒ no default (the key is omitted). */
const modalDefault = ref<unknown>(null);

/** Whether a working default value counts as "unset" (omit the key). `false` / `0` are meaningful. */
function defaultIsEmpty(value: unknown): boolean {
  return value == null || value === '';
}

/** Tri-state boolean default binding (''=no default, 'true'/'false') over `modalDefault`. */
const booleanDefaultValue = computed<string>({
  get: () => (modalDefault.value === true ? 'true' : modalDefault.value === false ? 'false' : ''),
  set: (v) => (modalDefault.value = v === 'true' ? true : v === 'false' ? false : null),
});
const booleanDefaultOptions = computed(() => [
  { value: '', label: t('workflows.field.defaultNone') },
  { value: 'true', label: t('common.yes') },
  { value: 'false', label: t('common.no') },
]);

// --- Pipeline base (the picked variable's TRUE type + enum options) ----------
/** Whether an operations modal may be offered at all (needs a catalog). */
const pipelineEnabled = computed(() => props.operationsCatalog.length > 0);

/** The picked variable's TRUE type — the pipeline's base (source) type. */
const baseType = computed<VariablePrimitive>(
  () => (pickedVariable.value?.type ?? pickedRef.value?.type ?? 'text') as VariablePrimitive,
);

/**
 * The picked variable's enum options mapped to the source-option arg choices — PREFERS
 * the structured `descriptor.options` (human `label`, `key` stays the wire value) and
 * falls back to the flat `enumOptions` when no descriptor is present.
 */
const sourceOptions = computed<VariableOption[]>(() =>
  (pickedVariable.value ? variableOptionList(pickedVariable.value) : undefined) ?? [],
);

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

// --- Operations MODAL (SF3.5) ------------------------------------------------
// A LOCAL editing clone the modal mutates, so Cancel discards edits (legacy parity).
const modalOpen = ref(false);
const modalPipeline = ref<VariablePipelineStep[]>([]);

/** Open the operations modal, seeding a clone from the current wire pipeline. */
function openModal(): void {
  if (props.disabled || !pickedRef.value || !pipelineEnabled.value) return;
  const wire = model.value?.kind === 'variable' ? model.value.pipeline ?? [] : [];
  modalPipeline.value = wire.map(fromWireStep);
  // Seed the working default from the saved model (null ⇒ no default set).
  modalDefault.value = (model.value?.kind === 'variable' ? model.value.default : undefined) ?? null;
  modalOpen.value = true;
}

/** The modal pipeline's final output type (the base type when there are no ops). */
const modalResultType = computed<VariablePrimitive>(() =>
  resolveType(props.operationsCatalog, baseType.value, modalPipeline.value),
);
/**
 * Whether the modal's pipeline satisfies the field's terminal contract — the SAME
 * predicate the field-level gate uses, so the modal Save now ALSO blocks an identity
 * enum / a plain terminal for a choice target (it must end on a choice-producing op).
 */
const modalTypeSatisfied = computed(() =>
  pipelineSatisfies(
    props.operationsCatalog,
    baseType.value,
    modalPipeline.value,
    resultTypesPrimitive.value,
    props.targetOptions,
  ),
);

/**
 * Whether the modal mismatch is specifically the CHOICE-target rule (the result type
 * already matches, but the pipeline does not END on a choice-producing op). Drives a
 * clearer strip hint than the generic "expected {type}" (which would read "expected
 * Choice" for a value that already returns Choice).
 */
const modalNeedsChoiceOp = computed(
  () =>
    !modalTypeSatisfied.value &&
    (props.targetOptions?.length ?? 0) > 0 &&
    resultTypesPrimitive.value.includes(modalResultType.value),
);

const modalTitle = computed(() =>
  t('workflows.field.operations', '', { name: pickedLabel.value }),
);

/**
 * Commit the modal's pipeline onto the union (omit when empty) + close. PRESERVES the
 * per-reference `default` (the ops modal never edits it) — omit-or-emit keeps a ref with
 * no default byte-identical.
 */
function saveModal(): void {
  const ref = pickedRef.value;
  if (!ref || !modalTypeSatisfied.value) return;
  const wire = modalPipeline.value.map(toWireStep);
  const next: WorkflowFieldValue = { kind: 'variable', ref };
  if (wire.length) next.pipeline = wire;
  // Commit the modal's typed default (omit when empty ⇒ byte-identical to a ref without one).
  if (!defaultIsEmpty(modalDefault.value)) next.default = modalDefault.value;
  model.value = next;
  modalOpen.value = false;
}

// --- Op ARGUMENT value-or-variable adapter (phase-4b) ------------------------
// A pipeline op's value-typed argument is stored as EITHER a bare literal (as today) OR the SAME
// {kind:'variable', ref, pipeline?, default?} union this field emits. The modal's pipeline editor
// hosts each such arg through its `argVariable` slot (template below), which renders a RECURSIVE
// ValueOrVariableField whose v-model is the WorkflowFieldValue union. These adapters translate
// between that union and the raw arg storage so a LITERAL arg serializes BYTE-IDENTICALLY (no wrapper)
// while a variable arg rides the union straight through.

/** Whether a raw arg value is a variable union rather than a literal. */
function isArgVariable(value: unknown): value is ArgVariableValue {
  return !!value && typeof value === 'object' && !Array.isArray(value) && (value as { kind?: string }).kind === 'variable';
}

/** The empty literal for an arg toggled back to VALUE mode (matches buildDefaultArgs so it stays valid). */
function emptyArgLiteral(arg: VariableOperationArgumentDefinition): VariableArgValue {
  switch (arg.type) {
    case 'number':
      return 0;
    case 'boolean':
      return false;
    case 'sourceOptions':
      return [] as string[];
    case 'choiceRules':
      return [] as ChoiceRule[];
    case 'sourceMap':
      return {} as Record<string, string | number>;
    default: // text | date | select | sourceOption | choiceFallback
      return '';
  }
}

/**
 * The accepted terminal type(s) for an arg-variable's pipeline = the arg's policy `refTypes`
 * (value→its type; option→enum|text; sourceOptions→multi). STRUCTURAL args (sourceMap/choiceRules)
 * have no flat terminal ⇒ `[]` (no type gate; shape is deferred to runtime fail-soft).
 */
function argResultTypes(arg: VariableOperationArgumentDefinition): WorkflowVariableType[] {
  return (argVariablePolicy(arg.type).refTypes ?? []) as WorkflowVariableType[];
}

/**
 * The variables offered in an arg-variable's picker: the FULL `argVariables` pool for EVERY arg —
 * identical to the field-level `pickerVariables` (show-all). Type-appropriateness is NOT enforced by
 * hiding variables but by `argResultTypes` (the terminal gate) + the mismatch skin, so the author can
 * pick ANY variable and coerce it to the arg's accepted type via the arg's own pipeline (structural
 * args get no pipeline — any variable is a raw whole-structure ref whose shape is runtime-checked).
 */
function argPickerVariables(_arg: VariableOperationArgumentDefinition): CatalogVariable[] {
  return props.argVariables;
}

/**
 * The operations catalog offered for an arg-variable's coercion pipeline. STRUCTURAL args get NONE:
 * the backend never type-flows / runs a structural arg-variable's sub-pipeline (the whole structure IS
 * the reference), so the recursive field becomes a plain pick-a-variable chip with no ops modal.
 */
function argOperationsCatalog(arg: VariableOperationArgumentDefinition): VariableOperationDefinition[] {
  return argVariablePolicy(arg.type).refTypes === null ? [] : props.operationsCatalog;
}

/** The "Variable" toggle label for a STRUCTURAL arg — clarifies "the WHOLE map/rules from a variable". */
function argVariableModeLabel(arg: VariableOperationArgumentDefinition): string | undefined {
  if (arg.type === 'sourceMap') return t('workflows.field.argVariableMapping');
  if (arg.type === 'choiceRules') return t('workflows.field.argVariableRules');
  return undefined;
}

/** Project a raw arg value onto the field union the recursive value-or-variable field edits. */
function argToUnion(raw: VariableArgValue | undefined): WorkflowFieldValue | null {
  if (isArgVariable(raw)) return raw as unknown as WorkflowFieldValue;
  return { kind: 'literal', value: raw ?? null };
}

/**
 * Project the recursive field's union back onto raw arg storage. A variable arm passes straight
 * through (`{kind:'variable', …}`); a literal is UNWRAPPED to its bare value (empty ⇒ the arg's typed
 * empty), so a literal arg is byte-identical to today (no `{kind}` wrapper ever reaches the wire).
 */
function unionToArg(union: WorkflowFieldValue | null, arg: VariableOperationArgumentDefinition): VariableArgValue {
  if (union?.kind === 'variable') return union as unknown as ArgVariableValue;
  const value = union?.kind === 'literal' ? union.value : null;
  return (value ?? emptyArgLiteral(arg)) as VariableArgValue;
}
</script>

<template>
  <div class="next-vov-field">
    <!-- The ONE input-like box: a compact mode toggle (leading) + the body. When a
         picked variable's SAVED pipeline does not satisfy the field, the box takes a
         danger skin (mirrors the focus-within ring) — the field itself reads "action
         required", not just the modal. -->
    <div class="next-vov" :class="{ 'is-disabled': disabled, 'is-error': pickedRef && !fieldSatisfied }">
      <!-- Compact mode toggle: two icon buttons (aria-pressed), NOT full cards. -->
      <div class="next-vov__toggle" role="group" :aria-label="t('workflows.field.modeLabel')">
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
                :nullable="pickedDescriptor?.nullable"
                :array="pickedDescriptor?.array"
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
            <VariableTreePicker
              :variables="variables"
              :disabled="disabled"
              :placeholder="pickerPlaceholder ?? t('workflows.field.pickVariable')"
              :label="pickerLabel ?? t('workflows.field.pickVariable')"
              @pick="pickVariable"
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

    <!-- Operations modal: the pipeline builder + the type gate (SF3.5). Only mounted
         for a picked variable; teleports itself to <body>. -->
    <Modal v-if="pickedRef" v-model:open="modalOpen" size="lg" :aria-label="modalTitle">
      <template #title>{{ modalTitle }}</template>

      <div class="flex flex-col gap-next-5">
        <!-- Read-only source: the variable name + its TRUE type (with nullable/array markers). -->
        <div class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-muted px-next-3 py-next-2">
          <VariableTypeIcon
            :icon="pickedIcon"
            :nullable="pickedDescriptor?.nullable"
            :array="pickedDescriptor?.array"
            class="shrink-0 text-next-muted-foreground"
          />
          <span class="min-w-0 flex-1 truncate font-next-medium text-next-fg">{{ pickedLabel }}</span>
          <Badge variant="neutral" tone="subtle" size="sm">{{ getVariableIconLabel(baseType) }}</Badge>
        </div>

        <!-- Default when empty (§refinement 1): a TYPED literal the backend substitutes when the
             referenced value resolves empty. Shown ONLY for a NULLABLE variable, typed to its base;
             empty ⇒ the `default` key is omitted (byte-identical to a ref without one). Boolean is a
             tri-state (no default / yes / no) so "no default" never silently serializes `false`. -->
        <div v-if="showDefault" class="flex flex-col gap-next-1_5" data-vov-default>
          <span class="text-next-sm font-next-medium text-next-fg">{{ t('workflows.field.defaultLabel') }}</span>
          <div class="[&>*]:w-full">
            <Select
              v-if="defaultBase === 'boolean'"
              v-model="booleanDefaultValue"
              :options="booleanDefaultOptions"
              :disabled="disabled"
              :aria-label="t('workflows.field.defaultLabel')"
            />
            <WorkflowGlobalValueField
              v-else
              v-model="modalDefault"
              :base="(defaultBase as WorkflowGlobalScalarBase | 'enum')"
              :options="pickedDescriptor?.options"
              :disabled="disabled"
              :aria-label="t('workflows.field.defaultLabel')"
            />
          </div>
          <p class="text-next-xs text-next-muted-foreground">{{ t('workflows.field.defaultHint') }}</p>
        </div>

        <VariablePipelineEditor
          v-model="modalPipeline"
          :base-type="baseType"
          :catalog="operationsCatalog"
          :source-options="sourceOptions"
          :target-options="targetOptions"
          :max-steps="maxOperations"
          :depth="depth"
        >
          <!-- ARG-VARIABLE (phase-4b): ANY op argument may itself be a variable. The editor decides
               WHETHER to offer this (within the depth cap); we supply the SAME value-or-variable field
               RECURSIVELY, parameterized by the arg's policy:
                 • picker = argVariables FILTERED to the policy refTypes (value→its type; option→
                   enum|text; sourceOptions→multi) or UNFILTERED for a structural arg;
                 • ops catalog = the coercion catalog (NONE for a structural arg — no sub-pipeline);
                 • result-types = the policy terminal gate ([] for a structural arg);
                 • the arg's literal control (PipelineArgLiteralInput) fills its VALUE slot, fed the
                   running source/target options so an option/map/rules editor has its choices.
               NO `target-options` is threaded to the recursive field: an arg-variable's own pipeline
               terminates on its policy types, never a choice target (mirrors the backend). -->
          <template
            #argVariable="{
              arg,
              value,
              depth: hostedDepth,
              setValue,
              disabled: argDisabled,
              sourceOptions: argSourceOptions,
              targetOptions: argTargetOptions,
            }"
          >
            <ValueOrVariableField
              :model-value="argToUnion(value)"
              :variables="argPickerVariables(arg)"
              :arg-variables="argVariables"
              :operations-catalog="argOperationsCatalog(arg)"
              :result-types="argResultTypes(arg)"
              :depth="hostedDepth"
              :disabled="argDisabled"
              :picker-label="arg.label"
              :variable-mode-label="argVariableModeLabel(arg)"
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
        </VariablePipelineEditor>

        <!-- Type gate: green when the pipeline returns an accepted type, a warning
             (with the expected type) when it does not. -->
        <div
          class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1 rounded-next-md border px-next-3 py-next-2 text-next-sm"
          :class="modalTypeSatisfied
            ? 'border-next-success/40 bg-next-success-subtle'
            : 'border-next-warning/40 bg-next-warning-subtle'"
          :data-type-satisfied="modalTypeSatisfied ? 'true' : 'false'"
          aria-live="polite"
        >
          <Icon
            :name="modalTypeSatisfied ? 'check-circle' : 'alert-triangle'"
            :class="modalTypeSatisfied ? 'text-next-success' : 'text-next-warning'"
            aria-hidden="true"
          />
          <span class="text-next-fg">
            {{ t('workflows.field.returns') }}
            <span class="font-next-medium">{{ getVariableIconLabel(modalResultType) }}</span>
          </span>
          <!-- Choice-target mismatch: the type matches but it must END on a choice op. -->
          <span v-if="modalNeedsChoiceOp" class="text-next-muted-foreground">
            · {{ t('workflows.field.needsChoice') }}
          </span>
          <span v-else-if="!modalTypeSatisfied && expectedTypesLabel" class="text-next-muted-foreground">
            · {{ t('workflows.field.expected', '', { types: expectedTypesLabel }) }}
          </span>
        </div>
      </div>

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
