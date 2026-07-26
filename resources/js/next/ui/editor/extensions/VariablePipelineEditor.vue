<script setup lang="ts">
// VariablePipelineEditor — the SHARED operations-pipeline builder. Given a base
// (source) primitive type + an operations catalog, it renders:
//   • an "add operation" DropdownMenu filtered by the CURRENT (running) type,
//   • a list of steps, each a Select of operations valid for THAT step's input
//     type + per-arg inputs (text / number / boolean / select) + a remove button,
//   • and reports the computed resultType (output of the last op) to the parent.
//
// This is the DRY core used by BOTH the VariablePanel and the IF condition editor
// (the legacy editor duplicated this logic across VariablePanel + IfBlockPanel).
//
// ARG-VARIABLE slot (phase-4b): EVERY arg control — value (text/number/boolean/date), option
// (select/sourceOption/sourceOptions/choiceFallback) AND structural (sourceMap/choiceRules) — may be
// supplied by a VARIABLE rather than built inline. This component owns the DECISION (the host provided
// the `argVariable` slot AND we are within the `depth` cap) but NOT the value-or-variable UI — the host
// (ValueOrVariableField) fills the `argVariable` slot with a recursive value-or-variable field whose
// VALUE mode is the arg's literal control (PipelineArgLiteralInput), so this shared editor keeps NO
// dependency on the workflow page. When the slot is absent (conditions / markdown builders) or the
// depth cap is reached, the arg renders its literal control DIRECTLY (PipelineArgLiteralInput — the
// SAME control, byte-identical to before). The editor passes the running source/target options through
// the slot so the recursive field's literal control (an option/map/rules editor) has its choices.
//
// v-model is the `pipeline` array. The parent owns the base type + catalog. The
// component is presentation + type-flow only; it never serializes.
import { computed, ref, useSlots } from 'vue';
import Button from '../../primitives/Button.vue';
import Icon from '../../primitives/Icon.vue';
import Badge from '../../primitives/Badge.vue';
import Select from '../../forms/Select.vue';
import DropdownMenu from '../../overlay/DropdownMenu.vue';
import PipelineArgLiteralInput from './PipelineArgLiteralInput.vue';
import ChoiceRuleWhenField from './ChoiceRuleWhenField.vue';
import TypedLiteralInput from '../../variables/TypedLiteralInput.vue';
import {
  arrayAtDefaultSatisfies,
  buildDefaultArgs,
  computeInputType,
  createPipelineStep,
  descriptorFromType,
  elementDefaultBase,
  elementDescriptorOf,
  elementPipelineBaseDescriptor,
  elementPipelinesValid,
  elementPipelineTerminalDescriptor,
  elementPipelineTerminalTypes,
  elementScopeSubfieldVars,
  elementSubfieldDescriptor,
  getArgumentIconName,
  getVariableIconLabel,
  getVariableIconName,
  isChoiceProducingOp,
  isElementScopeUnion,
  isOptionMembershipOp,
  isPresenceOp,
  isStructuralArg,
  isStructuralElement,
  MAX_ARG_VARIABLE_DEPTH,
  operationsForType,
  operationToDescriptor,
  pipelineSatisfies,
  resolveDescriptor,
  resolveType,
  typeFromDescriptor,
} from './operationHelpers';
import { useI18n, translate } from '../../../app/i18n';
import type {
  ArgEntryValue,
  ArgVariableValue,
  ChoiceRule,
  OperationTypeDescriptor,
  VariableArgValue,
  VariableOperationArgumentDefinition,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';
import type {
  VariableDescriptor,
  VariableDescriptorOption,
  VariableLiteralBase,
  VariableSourceVar,
} from '../../variables/types';

const props = defineProps<{
  /** Source primitive type the pipeline starts from. */
  baseType: VariablePrimitive;
  /**
   * The FULL source DESCRIPTOR (array-transform wave 3), when the flat `baseType` cannot express the
   * source's structure — a repeater (`array<object>`) / file array degrades its flat type to `text`,
   * so only the descriptor (`array:true` + `fields`) can offer its array ops + element subfield access.
   * When provided it roots the type-flow instead of `descriptorFromType(baseType)`; absent ⇒ the
   * flat behaviour, byte-identical for every scalar/enum source.
   */
  baseDescriptor?: OperationTypeDescriptor;
  /** Operations catalog (all available transforms). */
  catalog: VariableOperationDefinition[];
  /**
   * The SOURCE variable's selectable options (enum/multi sources) — they feed the
   * `sourceOption` / `sourceOptions` operation args, whose choices depend on the
   * picked variable rather than the operation definition.
   */
  sourceOptions?: VariableOption[];
  /**
   * The DESTINATION field's selectable options (a "choice"/enum value-or-variable
   * field, e.g. task priority). They feed the CHOICE-producing arg kinds
   * (`sourceMap` with `mapType:'enum'`, `choiceRules`, `choiceFallback`), whose
   * choices come from the destination field rather than the source variable. When
   * empty (the default) the choice-producing operations are NOT offered — so they
   * never appear in the conditions editor or the markdown variable builder.
   */
  targetOptions?: VariableOption[];
  /**
   * Optional cap on the number of pipeline steps. When set and reached, the
   * "add operation" trigger is disabled (mirrors the host's backend limit — the
   * workflow condition builder passes 10). Undefined = no limit (default).
   */
  maxSteps?: number;
  /**
   * The ARG-VARIABLE nesting depth of THIS pipeline (phase-4b). A top-level value-or-variable
   * pipeline is depth 0; each nested arg-variable's own pipeline increments it. A value-typed arg
   * offers the value/variable toggle (the `argVariable` slot) ONLY while `depth < MAX_ARG_VARIABLE_
   * DEPTH` — mirroring the backend write cap so the author can never build a config that 422s.
   * Default 0.
   */
  depth?: number;
  /**
   * Drop the PRESENCE family (assert_present / coalesce / is_present / is_null) from the add menu +
   * per-step operation Select. Set by the reference surfaces (markdown VariablePanel + step field, via
   * VariableReferenceEditor) which carry their own typed "default when empty"; left false/absent on the
   * direct-pipeline CONDITION surfaces (WorkflowConditionModal / IfConditionPanel) which need
   * is_present / is_null for their boolean terminal. Saved pipelines still RENDER these ops — this only
   * stops OFFERING them.
   */
  hidePresenceOps?: boolean;
  /**
   * F4: this pipeline's LAST step must resolve to a NON-NULL value. Set true when the editor is a MAP
   * element pipeline (map forces `nullable:false` on its per-element output), so a trailing `array_at`
   * still REQUIRES its typed default here. Default false ⇒ the last step is a legal nullable terminal.
   */
  forceTerminalValue?: boolean;
}>();

const pipeline = defineModel<VariablePipelineStep[]>({ default: () => [] });

const slots = useSlots();
const { t } = useI18n();

/** This pipeline's arg-variable nesting depth (0 = a top-level value-or-variable pipeline). */
const argDepth = computed(() => props.depth ?? 0);

/**
 * Whether an arg may become a variable here (phase-4b: ANY arg control): the host must provide the
 * `argVariable` slot (the value-or-variable field does; the conditions / markdown builders do NOT →
 * literal-only) AND we must be within the depth cap. At/over the cap the arg renders LITERAL-ONLY —
 * exactly where the backend rejects a deeper nesting.
 */
const canOfferArgVariable = computed(() => !!slots.argVariable && argDepth.value < MAX_ARG_VARIABLE_DEPTH);

/** Whether an arg value is a variable union rather than a literal. */
function isArgVariable(value: unknown): value is ArgVariableValue {
  return !!value && typeof value === 'object' && !Array.isArray(value) && (value as { kind?: string }).kind === 'variable';
}

/** The last dotted segment of a path (for a variable arg's compact chip echo). */
function lastPathSegment(path: string): string {
  const parts = path.split('.');
  return parts[parts.length - 1] || path;
}

/**
 * The pipeline's ROOT descriptor (array-transform wave 3): the host-supplied `baseDescriptor` when it
 * carries the source's true structure (a repeater / file array), else the flat `baseType` reconstruction.
 * This is what lets a repeater offer its array ops (its array collapses to the `multi` gate) + expose
 * its element subfields.
 */
const rootDescriptor = computed<OperationTypeDescriptor>(
  () => props.baseDescriptor ?? descriptorFromType(props.baseType, props.sourceOptions),
);

const resultType = computed<VariablePrimitive>(() =>
  resolveType(props.catalog, props.baseType, pipeline.value, rootDescriptor.value),
);
const nextInputType = computed<VariablePrimitive>(() =>
  computeInputType(props.catalog, props.baseType, pipeline.value, pipeline.value.length, rootDescriptor.value),
);
/**
 * The running DESCRIPTOR the add menu sees (output of all prior steps). Its `array` flag gates the
 * ARRAY-only offer rules (F3): while the value is still a LIST the choice terminal + `multi_to_text`
 * are dropped, so a list can only reach them AFTER an array op reduces it to a scalar.
 */
const nextInputDescriptor = computed<OperationTypeDescriptor>(() =>
  resolveDescriptor(props.catalog, rootDescriptor.value, pipeline.value),
);

/**
 * Filter the OFFERED operations:
 *   • CHOICE-producing ops are dropped unless a `targetOptions` destination set exists — they target a
 *     specific option set only a "choice" value-or-variable field provides (keeps them out of the
 *     conditions editor + markdown builder, which pass no `targetOptions`).
 *   • PRESENCE-family ops are dropped when `hidePresenceOps` is set (the reference surfaces — they have
 *     their own typed "default when empty"). Saved pipelines still render them; only the OFFER stops.
 */
function offerable(
  ops: VariableOperationDefinition[],
  running?: OperationTypeDescriptor,
): VariableOperationDefinition[] {
  let result = ops;
  if (!(props.targetOptions ?? []).length) result = result.filter((op) => !isChoiceProducingOp(op));
  if (props.hidePresenceOps) result = result.filter((op) => !isPresenceOp(op));
  if (running?.array) {
    // F3: `multi_to_text` degrades a LIST straight to text — an element/flat-type op leaking onto an
    // ARRAY. Drop it from the OFFER while the running value is a list (the array ops replace it); a saved
    // pipeline that already carries it still renders + executes (offer-only, like the presence family).
    result = result.filter((op) => op.id !== 'multi_to_text');
    // The option-MEMBERSHIP multi ops (includes / excludes / includes_any / includes_all) test the list
    // against the SOURCE variable's OPTION set. On an `array<object>` / `array<file>` the element carries
    // NO options, so their option Select is empty and would build an always-open `multi_excludes('')`
    // gate — drop them there. `multi_count` / `multi_is_empty` need no options and stay. A REAL enum
    // MULTI (checklist — enum element) keeps them all. Offer-only; a saved pipeline still renders.
    if (isStructuralElement(elementDescriptorOf(running))) {
      result = result.filter((op) => !isOptionMembershipOp(op));
    }
  }
  return result;
}

/**
 * The ONE-STEP converter from a base type to text — so ANY running type can reach the text-input choice
 * terminal (`match_to_choice`). Enum reaches the choice terminal directly (`enum_to_choice`), so it needs
 * no bridge (null). MUST stay in sync with the catalog's converter ids.
 */
const TO_TEXT_CONVERTER: Record<VariablePrimitive, string | null> = {
  text: null,
  number: 'num_to_text',
  boolean: 'bool_to_text',
  date: 'date_to_text',
  enum: null,
  multi: 'multi_to_text',
  file: 'file_name',
};

/**
 * The choice TERMINAL reachable from the current running type when this field targets a destination
 * option set (`targetOptions`): `enum_to_choice` when the value is already an enum, else `match_to_choice`
 * (reached from any type via a one-step converter to text). Null when this is not a choice field.
 *
 * FIX (Defect 4): the plain `operationsForType` add menu only surfaces ops whose input equals the
 * running type, so from a number/boolean/date/multi/file value the choice terminal never appeared — the
 * author saw "no operation to make this a priority". We ALWAYS surface the reachable terminal here and
 * AUTO-INSERT its bridge on add (see `addOperation`).
 */
const choiceTerminalId = computed<string | null>(() => {
  if (!(props.targetOptions ?? []).length) return null;
  // F3: the choice terminal (`enum_to_choice` / `match_to_choice`) and its `multi_to_text` auto-bridge
  // are only reachable once the value is SCALAR. While it is still a LIST the author must reduce it
  // first (array_at / array_count / array_reduce) — otherwise the bridge would smuggle `multi_to_text`
  // back onto an array. The bridge is untouched for scalars.
  if (nextInputDescriptor.value.array) return null;
  return nextInputType.value === 'enum' ? 'enum_to_choice' : 'match_to_choice';
});

const addableOperations = computed(() => {
  const ops = offerable(operationsForType(props.catalog, nextInputType.value), nextInputDescriptor.value);
  const terminalId = choiceTerminalId.value;
  if (terminalId && !ops.some((op) => op.id === terminalId)) {
    const def = findOp(terminalId);
    if (def) return [...ops, def];
  }
  return ops;
});
/** True once the pipeline reached the host's step cap (disables the add trigger). */
const atMaxSteps = computed(
  () => props.maxSteps != null && pipeline.value.length >= props.maxSteps,
);

defineExpose({ resultType });

function stepInputType(stepIndex: number): VariablePrimitive {
  return computeInputType(props.catalog, props.baseType, pipeline.value, stepIndex, rootDescriptor.value);
}

function operationOptionsForStep(stepIndex: number) {
  const inputDescriptor = resolveDescriptor(props.catalog, rootDescriptor.value, pipeline.value.slice(0, stepIndex));
  return offerable(operationsForType(props.catalog, stepInputType(stepIndex)), inputDescriptor).map((op) => ({
    value: op.id,
    label: op.label,
  }));
}

function findOp(id: string): VariableOperationDefinition | undefined {
  return props.catalog.find((op) => op.id === id);
}

// Only ONE step is expanded into its full config at a time; everything else is a
// compact chip. A freshly added step opens in edit mode so its op/args can be set.
const editingStepId = ref<string | null>(null);

function addOperation(op: VariableOperationDefinition, close?: () => void): void {
  const running = nextInputType.value;
  // AUTO-BRIDGE (Defect 4): the only op offered that may NOT accept the running type is the choice
  // terminal we always surface for a choice field. Insert its one-step converter to text first, so the
  // author reaches the priority choice from any value type without hand-building the bridge.
  if (op.inputTypes.length && !op.inputTypes.includes(running)) {
    const bridgeId = TO_TEXT_CONVERTER[running];
    const bridgeDef = bridgeId ? findOp(bridgeId) : undefined;
    if (bridgeDef) pipeline.value = [...pipeline.value, createPipelineStep(bridgeDef)];
  }
  const step = createPipelineStep(op);
  pipeline.value = [...pipeline.value, step];
  editingStepId.value = step.stepId;
  close?.();
}

function removeStep(stepId: string): void {
  pipeline.value = pipeline.value.filter((s) => s.stepId !== stepId);
  if (editingStepId.value === stepId) editingStepId.value = null;
}

// --- Chip (collapsed) summary helpers -------------------------------------
function opLabel(step: VariablePipelineStep): string {
  return findOp(step.operationId)?.label ?? t('editor.pipeline.selectOperation', 'Select an operation');
}
function opArgs(step: VariablePipelineStep) {
  return findOp(step.operationId)?.args ?? [];
}
function stepOutputType(step: VariablePipelineStep): VariablePrimitive {
  return findOp(step.operationId)?.outputType ?? step.outputType;
}
/** The source option whose VALUE matches, for label echo (sourceOption/sourceOptions). */
function sourceOptionLabel(value: string): string {
  return (props.sourceOptions ?? []).find((o) => o.value === value)?.label ?? value;
}

function formatArgValue(step: VariablePipelineStep, argId: string): string {
  const arg = opArgs(step).find((a) => a.id === argId);
  const raw = step.args[argId];
  // A value-typed arg supplied by a VARIABLE (phase-4b): echo the referenced variable's path tail
  // (checked FIRST so a boolean/date variable is not mis-read as a literal by the branches below).
  if (isArgVariable(raw)) {
    const path = raw.ref?.path ?? '';
    return path ? lastPathSegment(path) : t('editor.pipeline.argVariable', 'variable');
  }
  if (arg?.type === 'boolean') {
    return raw ? t('editor.pipeline.booleanYes', 'yes') : t('editor.pipeline.booleanNo', 'no');
  }
  if (arg?.type === 'select') {
    const opt = (arg.options ?? []).find((o) => o.value === String(raw));
    return opt?.label ?? (raw === '' || raw == null ? '—' : String(raw));
  }
  if (arg?.type === 'sourceOption') {
    return raw === '' || raw == null ? '—' : sourceOptionLabel(String(raw));
  }
  if (arg?.type === 'sourceOptions') {
    const values = Array.isArray(raw) ? (raw as string[]) : [];
    return values.length ? values.map(sourceOptionLabel).join(', ') : '—';
  }
  if (arg?.type === 'sourceMap') {
    // Entries may be a literal scalar OR a value-or-variable union (an object) — both count as "mapped".
    const map = raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, ArgEntryValue>) : {};
    const total = (props.sourceOptions ?? []).length;
    const done = Object.values(map).filter((v) => v !== '' && v != null).length;
    return t('editor.pipeline.mappedCount', 'Mapped {done}/{total}', { done, total });
  }
  if (arg?.type === 'choiceRules') {
    const rules = Array.isArray(raw) ? (raw as ChoiceRule[]) : [];
    return t('editor.pipeline.rulesCount', '{count} rules', { count: rules.length });
  }
  if (arg?.type === 'elementPipeline') {
    const steps = Array.isArray(raw) ? (raw as unknown[]) : [];
    return t('editor.pipeline.opsCount', '{count} op.', { count: steps.length });
  }
  if (arg?.type === 'reduceSeed' || arg?.type === 'elementDefault') {
    const seed = raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as { value?: unknown }) : {};
    return seed.value === '' || seed.value == null ? '—' : String(seed.value);
  }
  if (arg?.type === 'choiceFallback') {
    return raw === '' || raw == null ? '—' : targetOptionLabel(String(raw));
  }
  if (Array.isArray(raw)) return raw.length ? raw.join(', ') : '—';
  if (raw && typeof raw === 'object') return '—';
  return raw === '' || raw == null ? '—' : String(raw);
}

function updateOperation(stepId: string, opId: string): void {
  const def = findOp(opId);
  if (!def) return;
  pipeline.value = pipeline.value.map((s) =>
    s.stepId === stepId
      ? { ...s, operationId: def.id, args: buildDefaultArgs(def.args), outputType: def.outputType }
      : s,
  );
}

function updateArg(
  stepId: string,
  argId: string,
  value: VariableArgValue,
): void {
  pipeline.value = pipeline.value.map((s) =>
    s.stepId === stepId ? { ...s, args: { ...s.args, [argId]: value } } : s,
  );
}

// --- Target (destination) options — chip echo for CHOICE-producing args ------
// The per-arg literal controls (option / map / rules editors) live in PipelineArgLiteralInput; only
// the COLLAPSED chip summary (formatArgValue) stays here, so just the label echo helper remains.
/** The target option whose VALUE matches, for the chip label echo. */
function targetOptionLabel(value: string): string {
  return (props.targetOptions ?? []).find((o) => o.value === value)?.label ?? value;
}

// --- Element pipeline (array-transform wave 2) -------------------------------
// A map/filter/sort/reduce step hosts an ELEMENT PIPELINE arg: a nested pipeline rooted at the
// array's ELEMENT type (the seed type for reduce's reducer), fed two synthetic scope variables
// (Element/Indeks) into its arg-variable browser, and TERMINAL-GATED per host op so a wrong terminal
// cannot be completed/saved. This editor owns the derivation (input descriptor → element descriptor →
// base type / scope vars / required terminal); the nested editor + status strip render in the template.

/** The running DESCRIPTOR feeding a step (output of all prior steps), from the source descriptor. */
function stepInputDescriptor(step: VariablePipelineStep): OperationTypeDescriptor {
  const idx = pipeline.value.findIndex((s) => s.stepId === step.stepId);
  const prefix = pipeline.value.slice(0, Math.max(0, idx));
  return resolveDescriptor(props.catalog, rootDescriptor.value, prefix);
}

/** The array's ELEMENT descriptor at a step (a MULTI's element is a single enum). */
function elementDescriptorForStep(step: VariablePipelineStep): OperationTypeDescriptor {
  return elementDescriptorOf(stepInputDescriptor(step));
}

/** The BASE (source) type the element pipeline runs FROM — the seed type for reduce, else element. */
function elementBaseTypeForArg(step: VariablePipelineStep): VariablePrimitive {
  const args = (step.args ?? {}) as Record<string, unknown>;
  return typeFromDescriptor(
    elementPipelineBaseDescriptor(step.operationId, args, stepInputDescriptor(step)),
  );
}

/** The element pipeline's source options (enum element choices; none for a reduce scalar seed). */
function elementSourceOptionsForArg(step: VariablePipelineStep, arg: VariableOperationArgumentDefinition): VariableOption[] {
  if (step.operationId === 'array_reduce' && arg.id === 'reducer') return [];
  return elementDescriptorForStep(step).options ?? [];
}

/** The REQUIRED terminal type(s) the element pipeline must hit (filter=boolean, sort=number, …). */
function elementResultTypesForArg(step: VariablePipelineStep): VariablePrimitive[] {
  return elementPipelineTerminalTypes(step.operationId, (step.args ?? {}) as Record<string, unknown>);
}

/**
 * The two synthetic SCOPE variables an element pipeline exposes — `Element` (the array's element
 * type) and `Indeks` (number, 1-based) — injected into the nested editor's arg-variable browser
 * ONLY (contextual, never global). Typed from the element descriptor so an enum element carries its
 * options.
 */
function scopeVarsForStep(step: VariablePipelineStep): VariableSourceVar[] {
  const el = elementDescriptorForStep(step);
  return [
    {
      source: 'scope',
      path: 'element',
      name: t('editor.pipeline.scopeElement', 'Element'),
      type: typeFromDescriptor(el),
      // Wave 3: an object/file element carries its subfields, so `Element` EXPANDS into
      // `element.<field>` pickables in the browser exactly like a section/file composite; a
      // scalar/enum element has no fields and stays a single leaf (wave-2 behaviour).
      descriptor: elementScopeDescriptor(el),
    },
    {
      source: 'scope',
      path: 'index',
      name: t('editor.pipeline.scopeIndex', 'Index'),
      type: 'number',
      descriptor: { base: 'number', nullable: false, array: false },
    },
  ];
}

/**
 * The `Element` scope descriptor from an element descriptor — `operationToDescriptor` carries the
 * object/file `fields` (so the browser expands them); a FILE element's system subfield labels
 * (id/name/type/size/url) are localized here (parity with the file-composite rendering everywhere).
 */
function elementScopeDescriptor(el: OperationTypeDescriptor): VariableDescriptor {
  const descriptor = operationToDescriptor(el);
  if (el.base === 'file' && descriptor.fields) {
    descriptor.fields = descriptor.fields.map((field) => ({
      ...field,
      label: translate(`workflows.variable.fileSubfield.${field.key}`, field.label),
    }));
  }
  return descriptor;
}

/** Whether an object/file map/filter/sort element pipeline uses the scope-rooted UNION control (wave 3). */
function useScopeUnion(step: VariablePipelineStep): boolean {
  return (
    step.operationId !== 'array_reduce' &&
    ['array_map', 'array_filter', 'array_sort'].includes(step.operationId) &&
    isStructuralElement(elementDescriptorForStep(step))
  );
}

/** The `element.<field>` scope subfield variables the union control's subfield picker offers (wave 3). */
function elementSubfieldVars(step: VariablePipelineStep): VariableSourceVar[] {
  const el = elementDescriptorForStep(step);
  const labelFor =
    el.base === 'file'
      ? (key: string, fallback: string) => translate(`workflows.variable.fileSubfield.${key}`, fallback)
      : undefined;
  return elementScopeSubfieldVars(el, labelFor);
}

/** The current scope-rooted UNION value of an object/file map/filter/sort arg (null until picked). */
function scopeUnionValue(step: VariablePipelineStep, arg: VariableOperationArgumentDefinition): unknown {
  const raw = (step.args ?? {})[arg.id];
  return isElementScopeUnion(raw) ? raw : null;
}

/** Write the scope-rooted UNION back (an empty/removed value resets to the invalid `[]` default). */
function setScopeUnion(step: VariablePipelineStep, arg: VariableOperationArgumentDefinition, value: unknown): void {
  updateArg(step.stepId, arg.id, (isElementScopeUnion(value) ? value : []) as VariableArgValue);
}

/** Whether the scope-rooted UNION currently hits its required terminal (drives its status strip). */
function scopeUnionSatisfied(step: VariablePipelineStep): boolean {
  return elementPipelinesValid(props.catalog, stepInputDescriptor(step), [step]);
}

/** The scope-rooted UNION's return type (for its status strip). */
function scopeUnionReturn(step: VariablePipelineStep): VariablePrimitive {
  return typeFromDescriptor(
    elementPipelineTerminalDescriptor(
      props.catalog,
      step.operationId,
      (step.args ?? {}) as Record<string, unknown>,
      stepInputDescriptor(step),
    ),
  );
}

/** Whether the element pipeline currently hits its required terminal (drives the status strip). */
function elementPipelineSatisfied(step: VariablePipelineStep, steps: VariablePipelineStep[]): boolean {
  return pipelineSatisfies(
    props.catalog,
    elementBaseTypeForArg(step),
    steps,
    elementResultTypesForArg(step),
  );
}

/** The element pipeline's actual return type (for the status strip). */
function elementPipelineReturn(step: VariablePipelineStep, steps: VariablePipelineStep[]): VariablePrimitive {
  return resolveType(props.catalog, elementBaseTypeForArg(step), steps);
}

/** The human expected-terminal label; empty for `array_map` (any single value — no narrow gate). */
function elementExpectedLabel(step: VariablePipelineStep): string {
  const types = elementResultTypesForArg(step);
  if (types.length === 0 || types.length > 2) return '';
  return types.map((type) => getVariableIconLabel(type)).join(' / ');
}

/** Merge this step's scope vars with any already threaded from an outer element pipeline (nesting). */
function mergedScopeVars(step: VariablePipelineStep, inbound: unknown): VariableSourceVar[] {
  const outer = Array.isArray(inbound) ? (inbound as VariableSourceVar[]) : [];
  return [...scopeVarsForStep(step), ...outer];
}

// --- Object/file element pipeline INLINE builder (F5) ------------------------
// An object/file map/filter/sort element pipeline (`useScopeUnion`) is a scope-rooted value-or-variable
// UNION: `{kind:'variable', ref:{source:'scope', path:'element.<field>', type}, pipeline}`. This USED to
// route through a host-injected `#elementScopeUnion` slot (a nested VARIABLE-ONLY value-or-variable field
// — a subfield PICKER, dead on surfaces that did not fill it). It is now a SELF-CONTAINED inline control:
// a labelled subfield Select (row 1) + the SAME nested VariablePipelineEditor scalar elements use, rooted
// at the picked subfield — so it reads like the choiceRules rules box and works on EVERY surface with no
// injection point. The emitted wire union is BYTE-IDENTICAL to before (an empty pipeline is omitted).

/** The `element.<field>` subfield Select options (label = the field label; value = the scope path). */
function elementSubfieldSelectOptions(step: VariablePipelineStep): { value: string; label: string }[] {
  return elementSubfieldVars(step).map((variable) => ({ value: variable.path, label: variable.name }));
}

/** The currently-picked `element.<field>` path of a scope union arg (empty until one is picked). */
function scopeUnionPath(step: VariablePipelineStep, arg: VariableOperationArgumentDefinition): string {
  const raw = scopeUnionValue(step, arg);
  return isElementScopeUnion(raw) ? raw.ref?.path ?? '' : '';
}

/** The picked subfield's DESCRIPTOR (roots the nested editor; undefined until a field is picked). */
function scopeUnionSubDescriptor(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
): OperationTypeDescriptor | undefined {
  const path = scopeUnionPath(step, arg);
  if (!path) return undefined;
  return elementSubfieldDescriptor(elementDescriptorForStep(step), path) ?? undefined;
}

/** The picked subfield's flat base type (roots the nested editor; text until one is picked). */
function scopeUnionBaseType(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
): VariablePrimitive {
  const descriptor = scopeUnionSubDescriptor(step, arg);
  return descriptor ? typeFromDescriptor(descriptor) : 'text';
}

/** The picked subfield's enum options (feed the nested editor's source-option args). */
function scopeUnionSourceOptions(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
): VariableOption[] {
  return scopeUnionSubDescriptor(step, arg)?.options ?? [];
}

/** Write the scope union, OMITTING an empty pipeline so the wire stays byte-identical to before. */
function writeScopeUnion(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
  path: string,
  type: VariablePrimitive,
  wire: Array<{ op: string; args: Record<string, unknown> }>,
): void {
  const union: ArgVariableValue = { kind: 'variable', ref: { source: 'scope', path, type } };
  if (wire.length) union.pipeline = wire;
  updateArg(step.stepId, arg.id, union as VariableArgValue);
}

/** Pick an `element.<field>` subfield: keep a type-COMPATIBLE pipeline, else reset it (fail-safe). */
function pickElementSubfield(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
  path: string,
): void {
  if (!path) {
    // Cleared → reset to the invalid `[]` default so the terminal strip reads "action required".
    setScopeUnion(step, arg, []);
    return;
  }
  const subDescriptor = elementSubfieldDescriptor(elementDescriptorForStep(step), path);
  const type = (subDescriptor ? typeFromDescriptor(subDescriptor) : 'text') as VariablePrimitive;
  const raw = scopeUnionValue(step, arg);
  const prev = isElementScopeUnion(raw) ? raw : null;
  const keepPipeline =
    prev && prev.ref?.type === type && Array.isArray(prev.pipeline) ? prev.pipeline : [];
  writeScopeUnion(step, arg, path, type, keepPipeline);
}

/** The picked subfield pipeline's WIRE steps (fed to the nested editor's ChoiceRuleWhenField projector). */
function scopeUnionWire(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
): Array<{ op: string; args: Record<string, unknown> }> {
  const raw = scopeUnionValue(step, arg);
  return isElementScopeUnion(raw) && Array.isArray(raw.pipeline) ? raw.pipeline : [];
}

/** Write the nested editor's WIRE steps back into the union, keeping the picked subfield ref. */
function setScopeUnionWire(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
  wire: Array<{ op: string; args: Record<string, unknown> }>,
): void {
  const raw = scopeUnionValue(step, arg);
  if (!isElementScopeUnion(raw) || !raw.ref) return;
  writeScopeUnion(step, arg, raw.ref.path, raw.ref.type, wire);
}

// --- array_at typed default control (F4) -------------------------------------
// `array_at`'s `default` arg renders a dedicated element-typed literal (its type LOCKED to the array's
// ELEMENT base). It is REQUIRED when the step is not the pipeline's terminal (a following op consumes the
// nullable element) OR when this editor is a MAP element pipeline (`forceTerminalValue`).

/** The stored default's VALUE (null until authored) — feeds TypedLiteralInput. */
function elementDefaultValue(step: VariablePipelineStep, arg: VariableOperationArgumentDefinition): unknown {
  const raw = (step.args ?? {})[arg.id];
  if (raw && typeof raw === 'object' && !Array.isArray(raw) && 'value' in raw) {
    return (raw as { value?: unknown }).value ?? null;
  }
  return null;
}

/** The element base the default's TypedLiteralInput renders (LOCKED — no free type select). */
function elementDefaultBaseFor(step: VariablePipelineStep): VariableLiteralBase {
  return elementDefaultBase(elementDescriptorForStep(step));
}

/** The element's enum options as descriptor options (`{key,label}`) for an enum default Select. */
function elementDefaultOptions(step: VariablePipelineStep): VariableDescriptorOption[] {
  return (elementDescriptorForStep(step).options ?? []).map((option) => ({
    key: option.value,
    label: option.label,
  }));
}

/** Write (or CLEAR) the typed default; a cleared value removes the key so a terminal array_at stays clean. */
function setElementDefault(
  step: VariablePipelineStep,
  arg: VariableOperationArgumentDefinition,
  value: unknown,
): void {
  if (value === '' || value == null) {
    pipeline.value = pipeline.value.map((entry) => {
      if (entry.stepId !== step.stepId) return entry;
      const nextArgs = { ...entry.args };
      delete nextArgs[arg.id];
      return { ...entry, args: nextArgs };
    });
    return;
  }
  updateArg(step.stepId, arg.id, {
    type: elementDefaultBaseFor(step),
    value,
  } as unknown as VariableArgValue);
}

/** Whether this array_at step's typed default is REQUIRED (non-terminal, or a MAP element terminal). */
function elementDefaultRequired(step: VariablePipelineStep, index: number): boolean {
  if (step.operationId !== 'array_at') return false;
  const isLast = index === pipeline.value.length - 1;
  return !isLast || (props.forceTerminalValue ?? false);
}

/** Whether this array_at step carries a VALID typed default (drives the required-marker + invalid skin). */
function elementDefaultValid(step: VariablePipelineStep): boolean {
  return arrayAtDefaultSatisfies((step.args ?? {}).default, elementDescriptorForStep(step));
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <div class="flex items-center justify-between gap-next-2">
      <p class="text-next-sm font-next-semibold text-next-fg">{{ t('editor.pipeline.title', 'Operations pipeline') }}</p>
      <DropdownMenu :aria-label="t('editor.pipeline.addOperation', 'Add operation')" placement="bottom-end">
        <!-- The Popover trigger wrapper handles the click; do NOT also bind
             @click="toggle" or it toggles twice (open→close) and never opens. -->
        <template #trigger="{ props: triggerProps }">
          <Button
            size="sm"
            variant="secondary"
            type="button"
            leading-icon="plus"
            :disabled="!addableOperations.length || atMaxSteps"
            :title="atMaxSteps ? t('editor.pipeline.maxSteps', 'You reached the maximum number of operations.') : undefined"
            v-bind="triggerProps"
          >
            {{ t('editor.pipeline.addOperation', 'Add operation') }}
          </Button>
        </template>
        <template #default="{ close }">
          <div class="w-72 max-w-[min(92vw,20rem)] p-next-1">
            <p class="flex items-center gap-next-2 px-next-2 pb-next-1 text-next-xs text-next-muted-foreground">
              {{ t('editor.pipeline.availableForType', 'Available for type') }}
              <span
                class="inline-flex h-6 w-6 items-center justify-center rounded-next-full bg-next-muted text-next-fg"
                :title="getVariableIconLabel(nextInputType)"
              >
                <Icon :name="getVariableIconName(nextInputType)" />
                <span class="sr-only">{{ getVariableIconLabel(nextInputType) }}</span>
              </span>
            </p>
            <p v-if="!addableOperations.length" class="px-next-2 py-next-2 text-next-sm text-next-muted-foreground">
              {{ t('editor.pipeline.noOperationsForType', 'No operations for this type.') }}
            </p>
            <div v-else class="flex flex-col gap-next-1">
              <button
                v-for="op in addableOperations"
                :key="op.id"
                type="button"
                class="flex items-center justify-between gap-next-2 rounded-next-md border border-next-border px-next-2 py-next-1_5 text-left hover:border-next-primary hover:bg-next-primary-subtle"
                @click="addOperation(op, close)"
              >
                <span class="min-w-0">
                  <span class="block truncate text-next-sm font-next-medium text-next-fg">{{ op.label }}</span>
                  <span class="block text-next-2xs text-next-muted-foreground">{{ getVariableIconLabel(op.outputType) }}</span>
                  <!-- Disambiguating description (e.g. "To number" vs "Length"). -->
                  <span v-if="op.description" class="block text-next-2xs text-next-muted-foreground">{{ op.description }}</span>
                </span>
                <span class="text-next-primary" :title="getVariableIconLabel(op.outputType)">
                  <Icon :name="getVariableIconName(op.outputType)" />
                </span>
              </button>
            </div>
          </div>
        </template>
      </DropdownMenu>
    </div>

    <!-- Empty -->
    <p
      v-if="!pipeline.length"
      class="rounded-next-md border border-dashed border-next-border px-next-3 py-next-3 text-next-sm text-next-muted-foreground"
    >
      {{ t('editor.pipeline.empty', 'No operations. Add the first transformation to reshape the value.') }}
    </p>

    <!-- Steps: compact chips by default; the full config shows only for the step
         currently being added/edited. -->
    <ol v-else class="flex flex-col gap-next-2">
      <li v-for="(step, index) in pipeline" :key="step.stepId">
        <!-- EDIT MODE: full operation config -->
        <div
          v-if="editingStepId === step.stepId"
          class="flex flex-col gap-next-3 rounded-next-lg border border-next-primary bg-next-card p-next-3"
        >
          <div class="flex items-center justify-between gap-next-2">
            <p class="text-next-xs font-next-medium text-next-muted-foreground">{{ t('editor.pipeline.step', 'Step {index}', { index: index + 1 }) }}</p>
            <div class="flex items-center gap-next-1">
              <Button size="sm" variant="secondary" type="button" leading-icon="check" @click="editingStepId = null">
                {{ t('editor.pipeline.done', 'Done') }}
              </Button>
              <Button size="icon" variant="ghost" type="button" :aria-label="t('editor.pipeline.removeStep', 'Remove step')" @click="removeStep(step.stepId)">
                <Icon name="trash" />
              </Button>
            </div>
          </div>

          <Select
            :model-value="step.operationId"
            :options="operationOptionsForStep(index)"
            :placeholder="t('editor.pipeline.selectOperation', 'Select an operation')"
            :aria-label="t('editor.pipeline.operation', 'Operation')"
            @update:model-value="(id) => id && updateOperation(step.stepId, id as string)"
          />

          <!-- Per-arg inputs -->
          <div v-if="opArgs(step).length" class="flex flex-col gap-next-3">
            <div
              v-for="arg in opArgs(step)"
              :key="arg.id"
              class="flex flex-col gap-next-1"
            >
              <label class="flex items-center gap-next-1 text-next-xs font-next-medium text-next-fg">
                {{ arg.label }}
                <Badge variant="neutral" size="sm" :icon="getArgumentIconName(arg.type)">{{ arg.type }}</Badge>
              </label>
              <!-- F4: `array_at`'s typed default — an element-typed literal (its type LOCKED to the array's
                   ELEMENT base: an enum Select over the element options, else a scalar control). Marked
                   REQUIRED (invalid skin + a danger note) when the step is non-terminal, since the next op
                   would otherwise consume a null element. -->
              <template v-if="arg.type === 'elementDefault'">
                <TypedLiteralInput
                  :model-value="elementDefaultValue(step, arg)"
                  :base="elementDefaultBaseFor(step)"
                  :options="elementDefaultOptions(step)"
                  :invalid="elementDefaultRequired(step, index) && !elementDefaultValid(step)"
                  :aria-label="arg.label"
                  @update:model-value="(v) => setElementDefault(step, arg, v)"
                />
                <p class="text-next-xs text-next-muted-foreground">
                  {{ t('editor.pipeline.elementDefaultHint', 'Used instead of an empty item before the next operation runs.') }}
                </p>
                <p
                  v-if="elementDefaultRequired(step, index) && !elementDefaultValid(step)"
                  class="text-next-xs text-next-danger"
                  data-element-default-required
                >
                  {{ t('editor.pipeline.elementDefaultRequired', 'A value is required here — a following operation cannot use an empty item.') }}
                </p>
              </template>
              <!-- A NON-STRUCTURAL arg (value / option) can be supplied WHOLE by a VARIABLE when the
                   host enables it (provides the `argVariable` slot) AND we are within the depth cap. -->
              <slot
                v-else-if="canOfferArgVariable && !isStructuralArg(arg.type)"
                name="argVariable"
                :arg="arg"
                :value="step.args[arg.id]"
                :depth="argDepth + 1"
                :source-options="sourceOptions ?? []"
                :target-options="targetOptions ?? []"
                :set-value="(v: VariableArgValue) => updateArg(step.stepId, arg.id, v)"
                :disabled="false"
              />
              <!-- Otherwise the literal control renders DIRECTLY — BYTE-IDENTICAL to before. For a
                   STRUCTURAL container (sourceMap / choiceRules) with the feature on, each ENTRY (map
                   target / rule `then`) is its OWN value-or-variable (Defect-3): the `#entry` slot below
                   forwards each entry to the host's `argVariable` slot, typed to the entry's target type
                   (its `resultTypes` + narrowed `targetOptions` come from the literal control). When the
                   feature is off / at the depth cap the `#entry` slot is absent → inline literal entries. -->
              <PipelineArgLiteralInput
                v-else
                :arg="arg"
                :value="step.args[arg.id]"
                :source-options="sourceOptions ?? []"
                :target-options="targetOptions ?? []"
                :catalog="catalog"
                :depth="argDepth"
                @update:value="(v) => updateArg(step.stepId, arg.id, v)"
              >
                <template
                  v-if="canOfferArgVariable && isStructuralArg(arg.type)"
                  #entry="entry"
                >
                  <slot
                    name="argVariable"
                    :arg="entry.entryArg"
                    :value="entry.value"
                    :depth="argDepth + 1"
                    :source-options="entry.sourceOptions"
                    :target-options="entry.targetOptions"
                    :result-types="entry.resultTypes"
                    :set-value="(v: VariableArgValue) => entry.setValue(v as ArgEntryValue)"
                    :disabled="entry.disabled"
                  />
                </template>
                <!-- The choiceRules rule LHS `when`: a boolean-terminal pipeline over the op's own TEXT
                     input. RECURSE this SAME editor rooted at TEXT with NO variable picker and NO
                     targetOptions (so choice-producing ops are not offered — `offerable()` drops them);
                     `hidePresenceOps` stays false so is_present / is_null are available as the boolean
                     terminal. Its ops' args may themselves be arg-variables via the SAME `argVariable`
                     chain (forwarded to the host below). -->
                <template #when="when">
                  <VariablePipelineEditor
                    :model-value="when.steps"
                    base-type="text"
                    :catalog="catalog"
                    :depth="when.depth"
                    :hide-presence-ops="false"
                    @update:model-value="when.onSteps"
                  >
                    <template v-if="canOfferArgVariable" #argVariable="whenArg">
                      <slot name="argVariable" v-bind="whenArg" />
                    </template>
                  </VariablePipelineEditor>
                </template>
                <!-- The map/filter/sort/reduce ELEMENT PIPELINE: RECURSE this SAME editor rooted at the
                     array's ELEMENT type (the SEED type for reduce's reducer), fed the enum element's
                     options. The synthetic Element/Indeks scope variables are threaded into the
                     arg-variable browser via `#argVariable` (never global). The status strip below marks
                     the required terminal (filter=boolean, sort=number, reduce=seed, map=any) so a wrong
                     terminal reads as "action required" AND blocks the host's Save (`pipelineSatisfies`). -->
                <template #elementPipeline="ep">
                  <!-- OBJECT/FILE element (wave 3, F5): map/filter/sort cannot root a bare pipeline at
                       the whole object element, so the arg is a scope-rooted value-or-variable UNION.
                       This is now a SELF-CONTAINED inline builder (NO host slot): a labelled subfield
                       Select picks `element.<field>`, then the SAME nested pipeline editor scalar
                       elements use transforms it — so it reads like the choiceRules rules box and works
                       on EVERY surface. reduce keeps the bare reducer below (its ops reference
                       `element.<field>` as scope arg-vars). The emitted wire union is byte-unchanged. -->
                  <div
                    v-if="useScopeUnion(step)"
                    class="flex flex-col gap-next-3 rounded-next-md border border-next-border bg-next-muted p-next-3"
                  >
                    <!-- Row 1: the element-field Select (label = the subfield's field label). -->
                    <div class="flex flex-col gap-next-1">
                      <label class="text-next-xs font-next-medium text-next-fg">{{ t('editor.pipeline.elementField', 'Item field') }}</label>
                      <Select
                        :model-value="scopeUnionPath(step, arg)"
                        :options="elementSubfieldSelectOptions(step)"
                        :placeholder="t('workflows.field.pickElementField', 'Pick an item field')"
                        :aria-label="t('editor.pipeline.elementField', 'Item field')"
                        @update:model-value="(v) => pickElementSubfield(step, arg, (v as string) ?? '')"
                      />
                    </div>
                    <!-- After a field is picked: the SAME nested pipeline editor rooted at the subfield.
                         ChoiceRuleWhenField projects the union's WIRE pipeline ↔ editor steps (stable
                         ids) so building stays live; the Element/Indeks scope vars thread through. -->
                    <ChoiceRuleWhenField
                      v-if="scopeUnionPath(step, arg)"
                      :model-value="scopeUnionWire(step, arg)"
                      :catalog="catalog"
                      @update:model-value="(wire) => setScopeUnionWire(step, arg, wire)"
                    >
                      <template #default="{ steps: subSteps, onSteps: subOnSteps }">
                        <VariablePipelineEditor
                          :model-value="subSteps"
                          :base-type="scopeUnionBaseType(step, arg)"
                          :base-descriptor="scopeUnionSubDescriptor(step, arg)"
                          :catalog="catalog"
                          :source-options="scopeUnionSourceOptions(step, arg)"
                          :depth="ep.depth"
                          :force-terminal-value="step.operationId === 'array_map'"
                          :hide-presence-ops="false"
                          @update:model-value="subOnSteps"
                        >
                          <template v-if="slots.argVariable" #argVariable="epArg">
                            <slot
                              name="argVariable"
                              v-bind="epArg"
                              :scope-variables="mergedScopeVars(step, epArg.scopeVariables)"
                            />
                          </template>
                        </VariablePipelineEditor>
                      </template>
                    </ChoiceRuleWhenField>
                    <!-- Terminal-gating status: green when the picked field's transform hits the terminal. -->
                    <div
                      class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1 rounded-next-md border px-next-3 py-next-1_5 text-next-xs"
                      :class="scopeUnionSatisfied(step)
                        ? 'border-next-success/40 bg-next-success-subtle'
                        : 'border-next-warning/40 bg-next-warning-subtle'"
                      :data-element-terminal-satisfied="scopeUnionSatisfied(step) ? 'true' : 'false'"
                      aria-live="polite"
                    >
                      <Icon
                        :name="scopeUnionSatisfied(step) ? 'check-circle' : 'alert-triangle'"
                        :class="scopeUnionSatisfied(step) ? 'text-next-success' : 'text-next-warning'"
                        aria-hidden="true"
                      />
                      <span class="text-next-fg">
                        {{ t('editor.pipeline.eachItemReturns', 'Each item returns') }}
                        <span class="font-next-medium">{{ getVariableIconLabel(scopeUnionReturn(step)) }}</span>
                      </span>
                      <span v-if="!scopeUnionSatisfied(step) && elementExpectedLabel(step)" class="text-next-muted-foreground">
                        · {{ t('workflows.field.expected', '', { types: elementExpectedLabel(step) }) }}
                      </span>
                    </div>
                  </div>
                  <div v-else class="flex flex-col gap-next-2">
                    <VariablePipelineEditor
                      :model-value="ep.steps"
                      :base-type="elementBaseTypeForArg(step)"
                      :catalog="catalog"
                      :source-options="elementSourceOptionsForArg(step, arg)"
                      :depth="ep.depth"
                      :force-terminal-value="step.operationId === 'array_map'"
                      :hide-presence-ops="false"
                      @update:model-value="ep.onSteps"
                    >
                      <template v-if="slots.argVariable" #argVariable="epArg">
                        <slot
                          name="argVariable"
                          v-bind="epArg"
                          :scope-variables="mergedScopeVars(step, epArg.scopeVariables)"
                        />
                      </template>
                    </VariablePipelineEditor>
                    <!-- Terminal-gating status: green when the item pipeline hits the required terminal. -->
                    <div
                      class="flex flex-wrap items-center gap-x-next-2 gap-y-next-1 rounded-next-md border px-next-3 py-next-1_5 text-next-xs"
                      :class="elementPipelineSatisfied(step, ep.steps)
                        ? 'border-next-success/40 bg-next-success-subtle'
                        : 'border-next-warning/40 bg-next-warning-subtle'"
                      :data-element-terminal-satisfied="elementPipelineSatisfied(step, ep.steps) ? 'true' : 'false'"
                      aria-live="polite"
                    >
                      <Icon
                        :name="elementPipelineSatisfied(step, ep.steps) ? 'check-circle' : 'alert-triangle'"
                        :class="elementPipelineSatisfied(step, ep.steps) ? 'text-next-success' : 'text-next-warning'"
                        aria-hidden="true"
                      />
                      <span class="text-next-fg">
                        {{ t('editor.pipeline.eachItemReturns', 'Each item returns') }}
                        <span class="font-next-medium">{{ getVariableIconLabel(elementPipelineReturn(step, ep.steps)) }}</span>
                      </span>
                      <span v-if="!elementPipelineSatisfied(step, ep.steps) && elementExpectedLabel(step)" class="text-next-muted-foreground">
                        · {{ t('workflows.field.expected', '', { types: elementExpectedLabel(step) }) }}
                      </span>
                    </div>
                  </div>
                </template>
              </PipelineArgLiteralInput>
              <!-- Optional persistent hint (e.g. the date_format safe tokens). -->
              <p v-if="arg.hint" class="text-next-xs text-next-muted-foreground">{{ arg.hint }}</p>
            </div>
          </div>
        </div>

        <!-- CHIP MODE: compact summary (op name, result type, args name/type/value) -->
        <div
          v-else
          class="flex items-start gap-next-2 rounded-next-lg border border-next-border bg-next-card px-next-2_5 py-next-2"
        >
          <button
            type="button"
            class="flex min-w-0 flex-1 flex-wrap items-center gap-next-2 text-left"
            :aria-label="t('editor.pipeline.editStep', 'Edit step {index}: {label}', { index: index + 1, label: opLabel(step) })"
            @click="editingStepId = step.stepId"
          >
            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-next-full bg-next-muted text-next-2xs font-next-semibold text-next-muted-foreground">{{ index + 1 }}</span>
            <span class="text-next-sm font-next-semibold text-next-fg">{{ opLabel(step) }}</span>
            <span class="inline-flex items-center gap-next-1" :title="getVariableIconLabel(stepOutputType(step))">
              <Icon name="arrow-right" class="text-next-muted-foreground" />
              <Icon :name="getVariableIconName(stepOutputType(step))" class="text-next-primary" />
              <span class="text-next-xs text-next-muted-foreground">{{ getVariableIconLabel(stepOutputType(step)) }}</span>
            </span>
            <Badge
              v-for="arg in opArgs(step)"
              :key="arg.id"
              variant="neutral"
              size="sm"
              :icon="getArgumentIconName(arg.type)"
            >
              {{ arg.label }}: {{ formatArgValue(step, arg.id) }}
            </Badge>
          </button>
          <button
            type="button"
            class="shrink-0 rounded-next-sm p-next-1 text-next-muted-foreground transition-colors duration-[var(--duration-next-fast)] hover:text-next-danger"
            :aria-label="t('editor.pipeline.removeStep', 'Remove step')"
            @click="removeStep(step.stepId)"
          >
            <Icon name="x" />
          </button>
        </div>
      </li>
    </ol>

    <!-- Result type readout -->
    <div class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-muted px-next-3 py-next-2 text-next-sm">
      <span class="text-next-muted-foreground">{{ t('editor.pipeline.resultType', 'Result type:') }}</span>
      <span class="inline-flex items-center gap-next-1 font-next-medium text-next-fg">
        <Icon :name="getVariableIconName(resultType)" />
        {{ getVariableIconLabel(resultType) }}
      </span>
    </div>
  </div>
</template>
