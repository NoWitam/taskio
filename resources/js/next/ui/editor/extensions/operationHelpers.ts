// operationHelpers.ts — pure helpers for the variable operations pipeline
// (ported from the legacy editor's `utils/operationHelpers.ts` +
// `utils/variableIcons.ts`, mapped to the "next" Icon registry). Shared by the
// VariablePipelineEditor (used in both the VariablePanel and the IF condition
// editor) so the type-flow logic lives in ONE place.
import type { IconName } from '../../primitives/Icon.vue';
import { translate } from '../../../app/i18n';
import type {
  ArgEntryValue,
  ArgVariableValue,
  ChoiceRule,
  OperationTypeDescriptor,
  ReduceSeedValue,
  VariableOperationArgumentDefinition,
  VariableOperationArgumentType,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';
import type { VariableDescriptor, VariableLiteralBase, VariableSourceVar } from '../../variables/types';

/** The WIRE shape of a pipeline step — what an element pipeline / reduce reducer stores. */
type WireStep = { op: string; args: Record<string, unknown> };

/**
 * Maximum ARG-VARIABLE nesting depth (phase-4b). An operation argument may itself be a variable
 * (a value-or-variable union) whose own pipeline may carry another variable argument … and so on;
 * this caps how deep the author may build that tree so the FE never produces a config the backend
 * would 422.
 *
 * MUST MATCH the backend `ConditionTreeLimits::MAX_ARG_VARIABLE_DEPTH` (currently 3). The pipeline a
 * value-or-variable field hosts is depth 0; each nested arg-variable's own pipeline increments the
 * depth. A pipeline at depth `d` may still offer arg-variables while `d < MAX_ARG_VARIABLE_DEPTH`
 * (the arg it hosts sits at depth `d + 1`); at depth ≥ MAX it renders LITERAL-ONLY — exactly where
 * the backend rejects (`argDepth + 1 > MAX`).
 */
export const MAX_ARG_VARIABLE_DEPTH = 3;

/**
 * The per-arg-control policy for accepting a VARIABLE-supplied operation argument (phase-4b) — the FE
 * mirror of the backend `WorkflowOperationArgType::argVariablePolicy()` (→ `ArgVariablePolicy`). This
 * is the SINGLE FE source for the two decisions the editor makes per arg control, so the FE can never
 * offer a config the write-validator would 422:
 *   1. WHETHER the control accepts a WHOLE-arg variable — every NON-structural control does. A
 *      STRUCTURAL control (sourceMap / choiceRules) NEVER does (Defect-3): the flat variable type
 *      cannot express a `{option: target}` map / a `{when, then}` rule list, so instead each ENTRY of
 *      the structure is its own value-or-variable (validated per entry by the backend). `argVariablePolicy`
 *      is therefore only consulted for NON-structural controls (including the SYNTHETIC leaf arg an entry
 *      hosts — text/number/date/choiceFallback); the structural arms below are unreachable placeholders.
 *   2. The `refTypes` TYPE FILTER — the variable types the picker offers AND the terminal type the
 *      (optional) coercion pipeline must produce.
 *
 * MUST MATCH the backend `WorkflowOperationArgType::argVariablePolicy()` case-for-case (see
 * `app/modules/Workflows/Enums/WorkflowOperationArgType.php` + `DTOs/ArgVariablePolicy.php`). The
 * backend's second facet — `coerceTo` (the runtime coercion target) — is a RESOLVER concern with no
 * FE equivalent, so only `refTypes` is mirrored here.
 */
export interface ArgVariablePolicy {
  /** The variable types this control accepts (the picker filter + the pipeline terminal gate). */
  refTypes: VariablePrimitive[];
}

/**
 * The `ArgVariablePolicy` for one arg control — mirrors the backend match case-for-case:
 *   - VALUE (text/number/boolean/date)            strict single type.
 *   - single OPTION (select/sourceOption/          `enum` OR `text` (a value that stringifies to an
 *     choiceFallback)                              option key); membership is a RUNTIME concern.
 *   - multi OPTION (sourceOptions)                 `multi` (an array of option values).
 *   - STRUCTURAL (sourceMap/choiceRules)           UNREACHABLE — a structural arg is never a whole-arg
 *                                                  variable; its ENTRIES are typed per entry (Defect-3).
 */
export function argVariablePolicy(type: VariableOperationArgumentType): ArgVariablePolicy {
  switch (type) {
    case 'text':
      return { refTypes: ['text'] };
    case 'number':
      return { refTypes: ['number'] };
    case 'boolean':
      return { refTypes: ['boolean'] };
    case 'date':
      return { refTypes: ['date'] };
    case 'select':
    case 'sourceOption':
    case 'choiceFallback':
      return { refTypes: ['enum', 'text'] };
    case 'sourceOptions':
      return { refTypes: ['multi'] };
    case 'sourceMap':
    case 'choiceRules':
    case 'elementPipeline':
    case 'reduceSeed':
    case 'elementDefault':
      // Unreachable: a structural / pipeline / seed / element-default container is never offered as a
      // whole-arg variable (Defect-3 + array-transform wave 2/3). Kept for switch exhaustiveness.
      return { refTypes: [] };
  }
}

/**
 * Whether an arg control is STRUCTURAL — a container the whole-arg variable toggle must NOT wrap
 * (its value is built inline, never supplied whole by one variable ref): a `{option:target}` map,
 * a `{when,then}` rule list, an element `{op,args}[]` pipeline, or a reduce `{type,value}` seed.
 */
export function isStructuralArg(type: VariableOperationArgumentType): boolean {
  return (
    type === 'sourceMap' ||
    type === 'choiceRules' ||
    type === 'elementPipeline' ||
    type === 'reduceSeed' ||
    type === 'elementDefault'
  );
}

/**
 * The four PRESENCE-family operation ids — the FE mirror of the backend `WorkflowOperation::isPresenceOp`.
 * They special-case the null gate ("require a value", "fallback when empty", "has a value", "has no value")
 * and are OFFERED on every text-running surface. A reference surface (markdown VariablePanel + the step
 * ValueOrVariableField, both via VariableReferenceEditor) now carries its OWN typed, nullable-gated
 * "default when empty", so these are unwanted THERE — `offerable()` drops them when `hidePresenceOps` is
 * set. The direct-pipeline CONDITION surfaces keep them (`is_present`/`is_null` are their boolean terminal).
 */
const PRESENCE_OP_IDS = ['assert_present', 'coalesce', 'is_present', 'is_null'];

/** Whether an operation is a presence-family op (see `PRESENCE_OP_IDS`). */
export function isPresenceOp(op: VariableOperationDefinition | undefined): boolean {
  return !!op && PRESENCE_OP_IDS.includes(op.id);
}

/**
 * The four option-MEMBERSHIP multi operation ids. They test the running list against the SOURCE
 * variable's OPTION set — `multi_includes` / `multi_excludes` take a single `sourceOption`,
 * `multi_includes_any` / `multi_includes_all` take a `sourceOptions` multi. They are meaningful ONLY
 * on a REAL enum MULTI (a checklist whose element carries options). On an `array<object>` /
 * `array<file>` running value the element has NO options, so their option Select is empty and would
 * build an always-open `multi_excludes('')` gate — `offerable()` drops them there (offer-only; a saved
 * pipeline still renders + executes, and the backend adds the write-time backstop). `multi_count` /
 * `multi_is_empty` take no options and stay offered on every array.
 */
const OPTION_MEMBERSHIP_OP_IDS = ['multi_includes', 'multi_excludes', 'multi_includes_any', 'multi_includes_all'];

/** Whether an operation is an option-membership multi op (see `OPTION_MEMBERSHIP_OP_IDS`). */
export function isOptionMembershipOp(op: VariableOperationDefinition | undefined): boolean {
  return !!op && OPTION_MEMBERSHIP_OP_IDS.includes(op.id);
}

// Icon per primitive. Labels are NOT stored here — they resolve through i18n at
// call time (see `getVariableIconLabel`) so they follow the active UI language.
const VARIABLE_TYPE_ICON: Record<VariablePrimitive, IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
  // The extended vocabulary mirrors the workflow add-on chips (§7.5) so a type
  // reads with the SAME glyph everywhere.
  date: 'calendar',
  enum: 'list',
  multi: 'list-checks',
  file: 'file-text',
};

export function getVariableIconName(type: VariablePrimitive): IconName {
  return VARIABLE_TYPE_ICON[type] ?? 'type';
}

/**
 * Localized human label for a variable primitive. Calls the singleton `translate`
 * so the value is reactive in templates/computeds (it reads the shared locale ref)
 * and follows the active UI language.
 */
export function getVariableIconLabel(type: VariablePrimitive): string {
  switch (type) {
    case 'number':
      return translate('editor.types.number', 'Number');
    case 'boolean':
      return translate('editor.types.boolean', 'Condition');
    case 'date':
      return translate('editor.types.date', 'Date');
    case 'enum':
      return translate('editor.types.enum', 'Choice');
    case 'multi':
      return translate('editor.types.multi', 'Multi-choice');
    case 'file':
      return translate('editor.types.file', 'File');
    case 'text':
    default:
      return translate('editor.types.text', 'Text');
  }
}

export function getArgumentIconName(type: VariableOperationArgumentType): IconName {
  const map: Record<VariableOperationArgumentType, IconName> = {
    text: 'type',
    number: 'hash',
    boolean: 'check-circle',
    date: 'calendar',
    select: 'list',
    sourceOption: 'list',
    sourceOptions: 'list-checks',
    sourceMap: 'arrow-right',
    choiceRules: 'list-checks',
    choiceFallback: 'list',
    elementPipeline: 'list-checks',
    reduceSeed: 'hash',
    elementDefault: 'undo',
  };
  return map[type] ?? 'help-circle';
}

/**
 * Whether an operation PRODUCES a destination choice — the FE mirror of the backend's
 * `producesChoice()` terminal rule. A choice-producing op targets a specific option
 * set: it carries a `choiceRules` / `choiceFallback` arg, or a `sourceMap` arg whose
 * `mapType` is `enum`. Such ops are only offered when a `targetOptions` set exists
 * (a value-or-variable field over a "choice"/enum destination), never in the
 * conditions editor or markdown variable builder.
 */
export function isChoiceProducingOp(op: VariableOperationDefinition | undefined): boolean {
  if (!op?.args) return false;
  return op.args.some(
    (arg) =>
      arg.type === 'choiceRules' ||
      arg.type === 'choiceFallback' ||
      (arg.type === 'sourceMap' && arg.mapType === 'enum'),
  );
}

type ArgValue =
  | string
  | number
  | boolean
  | string[]
  | Record<string, ArgEntryValue>
  | ChoiceRule[]
  | WireStep[]
  | ReduceSeedValue;

/** The default `array_reduce` seed — a numeric 0 (the canonical fold accumulator). */
export function defaultReduceSeed(): ReduceSeedValue {
  return { type: 'number', value: 0 };
}

/**
 * Build the default arg map for an operation (sourceOptions / choiceRules / elementPipeline → [],
 * sourceMap → {}, reduceSeed → a numeric 0 seed, everything else → '' unless a `defaultValue` is
 * declared).
 */
export function buildDefaultArgs(
  args?: VariableOperationArgumentDefinition[],
): Record<string, ArgValue> {
  if (!args) return {};
  return args.reduce<Record<string, ArgValue>>((acc, arg) => {
    // `elementDefault` (F4) is OMITTED: it is authored on the first edit, so a fresh (terminal)
    // `array_at` stays wire-clean — no `default` key until the author sets one.
    if (arg.type === 'elementDefault') return acc;
    if (arg.defaultValue !== undefined) acc[arg.id] = arg.defaultValue;
    else if (arg.type === 'boolean') acc[arg.id] = false;
    else if (arg.type === 'sourceOptions') acc[arg.id] = [];
    else if (arg.type === 'choiceRules') acc[arg.id] = [];
    else if (arg.type === 'elementPipeline') acc[arg.id] = [];
    else if (arg.type === 'reduceSeed') acc[arg.id] = defaultReduceSeed();
    else if (arg.type === 'sourceMap') acc[arg.id] = {};
    else acc[arg.id] = '';
    return acc;
  }, {});
}

export function formatArgsLabel(args?: VariableOperationArgumentDefinition[]): string {
  if (!args || !args.length) return translate('editor.types.noArguments', 'No arguments');
  return args.map((arg) => `${arg.label} (${arg.type})`).join(', ');
}

// --- Descriptor tracking (array-transform wave, Extension #1) ----------------
// The type-flow tracks a running DESCRIPTOR (`{base, array, nullable?, options?,
// elementDescriptor?}`) rather than a bare `VariablePrimitive`, so an ARRAY op can gate on
// `array === true` and derive its output from the INPUT's ELEMENT type. The public helpers
// (`resolveType` / `computeInputType`) keep returning the flat `VariablePrimitive` via the
// bridge below, so NO caller changes and every scalar/legacy op is byte-identical.

/**
 * A flat wire type → its full descriptor. The flat `multi` slot carries any scalar/enum ARRAY,
 * so it expands to `{base:'enum', array:true}` with a single-`enum` `elementDescriptor` (this is
 * what lets `array_at` chain to the element type). Every other flat type is a non-array scalar.
 */
export function descriptorFromType(
  type: VariablePrimitive,
  options?: VariableOption[],
): OperationTypeDescriptor {
  if (type === 'multi') {
    return {
      base: 'enum',
      array: true,
      options,
      elementDescriptor: { base: 'enum', array: false, options },
    };
  }
  return { base: type, array: false, options };
}

/**
 * A descriptor → its flat wire type (mirrors the backend `WorkflowVariableType::fromDescriptor`):
 * any ARRAY (scalar/enum, or an `array<object>`/`array<file>` since wave 3) collapses to the `multi`
 * slot — which is why an ARRAY op is offered on a repeater too; a non-array descriptor is its own
 * `base`, with the structural `object` base degrading to `text` exactly like the backend flat wire.
 */
export function typeFromDescriptor(descriptor: OperationTypeDescriptor): VariablePrimitive {
  if (descriptor.array) return 'multi';
  if (descriptor.base === 'object') return 'text';
  return descriptor.base;
}

/**
 * The ELEMENT descriptor of an array. A `multi`'s element is a single `enum`; an `array<object>`
 * (repeater) / `array<file>` element carries its `fields` (wave 3) so the element pipeline can
 * expand `Element` into `element.<field>` subfields.
 */
export function elementDescriptorOf(descriptor: OperationTypeDescriptor): OperationTypeDescriptor {
  if (descriptor.elementDescriptor) return descriptor.elementDescriptor;
  return { base: descriptor.base, array: false, options: descriptor.options, fields: descriptor.fields };
}

/** Whether an element descriptor is a STRUCTURAL container (an `object` repeater row / a `file`). */
export function isStructuralElement(descriptor: OperationTypeDescriptor): boolean {
  return descriptor.base === 'object' || descriptor.base === 'file';
}

// --- array_at typed default (F4) ---------------------------------------------
// `array_at` is nullable-by-default: an out-of-range / empty pick yields null. A NON-TERMINAL
// `array_at` (a following op would consume that null) therefore REQUIRES a typed default — a
// `{type, value}` literal whose type is LOCKED to the array's ELEMENT base — substituted before the
// next op. These two helpers are the SINGLE FE source for "what base does the control author" and
// "is the stored default valid", so the control, the Save gate and the output resolver all agree.

/**
 * The `VariableLiteralBase` a `TypedLiteralInput` renders for an `array_at` default, from the array's
 * ELEMENT descriptor: `enum` (a Select over the element options), the four scalars themselves, and
 * `object`/`file`/`time` degraded to `text` (they are not authorable as a single literal). This is
 * BOTH the control's `:base` and the type the stored `{type, value}` must carry (they are kept in
 * lock-step by using this one function on both sides).
 */
export function elementDefaultBase(element: OperationTypeDescriptor): VariableLiteralBase {
  switch (element.base) {
    case 'enum':
      return 'enum';
    case 'number':
    case 'boolean':
    case 'date':
      return element.base;
    default:
      // text / object / file / time → a text literal control (object/file are not authorable).
      return 'text';
  }
}

/**
 * Whether a stored `array_at` default is a VALID `{type, value}` for the array's ELEMENT descriptor:
 * the `type` must equal `elementDefaultBase(element)`, the `value` must be present (a non-empty
 * scalar — `false` / `0` count as present), and an `enum` value must be one of the element options.
 * The FE mirror of the backend's write-time default type-check.
 */
export function arrayAtDefaultSatisfies(value: unknown, element: OperationTypeDescriptor): boolean {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  const seed = value as { type?: string; value?: unknown };
  if (seed.type !== elementDefaultBase(element)) return false;
  if (seed.value === '' || seed.value == null) return false; // '' / null are "unset"; false / 0 pass
  if (elementDefaultBase(element) === 'enum') {
    // Membership is enforced ONLY when the option list is known here. A HOST Save gate roots a `multi`
    // pipeline via `descriptorFromType` (no source options threaded), so it cannot check membership —
    // the enum Select already restricts the author to valid options, and the BACKEND remains the
    // authoritative membership check. Where the options ARE known (the editor control, a map subfield),
    // an off-list value is still rejected.
    const options = element.options ?? [];
    return options.length === 0 || options.some((option) => option.value === seed.value);
  }
  return true;
}

// --- Descriptor bridges (array-transform wave 3) -----------------------------
// A repeater / file array degrades its flat wire type to `text`, so the flat `baseType` alone can
// never tell the pipeline it is an ARRAY of a structured element. These convert the shared-model
// `VariableDescriptor` (which the catalog carries, `fields` included) to the pipeline's running
// `OperationTypeDescriptor` and back, so the picked variable's TRUE structure can root the pipeline.

/** A shared-model `VariableDescriptor` (with `fields`/`options`) → the pipeline's descriptor. */
export function descriptorToOperation(descriptor: VariableDescriptor): OperationTypeDescriptor {
  const array = descriptor.array === true;
  const base = (descriptor.base === 'time' ? 'text' : descriptor.base) as OperationTypeDescriptor['base'];
  const options = descriptor.options?.length
    ? descriptor.options.map((o) => ({ value: o.key, label: o.label }))
    : undefined;
  const fields = descriptor.fields?.length
    ? descriptor.fields.map((f) => ({ key: f.key, label: f.label, descriptor: descriptorToOperation(f.descriptor) }))
    : undefined;
  const out: OperationTypeDescriptor = { base, array, nullable: !!descriptor.nullable, options, fields };
  if (array) out.elementDescriptor = { base, array: false, options, fields };
  return out;
}

/** The inverse: a pipeline descriptor → a shared-model `VariableDescriptor` (for a scope-var feed). */
export function operationToDescriptor(descriptor: OperationTypeDescriptor): VariableDescriptor {
  const options = descriptor.options?.length
    ? descriptor.options.map((o) => ({ key: o.value, label: o.label }))
    : undefined;
  const fields = descriptor.fields?.length
    ? descriptor.fields.map((f) => ({ key: f.key, label: f.label, descriptor: operationToDescriptor(f.descriptor) }))
    : undefined;
  const out: VariableDescriptor = {
    base: descriptor.base,
    nullable: !!descriptor.nullable,
    array: descriptor.array === true,
  };
  if (options) out.options = options;
  if (fields) out.fields = fields;
  return out;
}

/**
 * The `element.<field>` SCOPE subfield variables an object/file element pipeline exposes (wave 3) —
 * the FE mirror of the backend `elementScopeSubfields`. Each subfield rides under the synthetic
 * `element` root, typed from the element descriptor's `fields`; a scalar/enum element yields none.
 * `labelFor` localizes a subfield key (a file's system subfields); it defaults to the field label.
 */
export function elementScopeSubfieldVars(
  element: OperationTypeDescriptor,
  labelFor?: (key: string, fallback: string) => string,
): VariableSourceVar[] {
  if (!isStructuralElement(element) || !element.fields?.length) return [];
  return element.fields.map((field) => ({
    source: 'scope' as const,
    path: `element.${field.key}`,
    name: labelFor ? labelFor(field.key, field.label) : field.label,
    type: typeFromDescriptor(field.descriptor),
    descriptor: operationToDescriptor(field.descriptor),
  }));
}

/** The running descriptor AFTER one op — the op's `resolveOutput`, or its static `outputType`. */
function outputDescriptorOf(
  def: VariableOperationDefinition,
  input: OperationTypeDescriptor,
  args: Record<string, unknown>,
  catalog?: VariableOperationDefinition[],
): OperationTypeDescriptor {
  // `array_map`'s output is the array of its ELEMENT PIPELINE's terminal, so its resolver needs the
  // catalog to walk that inner pipeline; the flat callers pass none and array_count/at ignore it.
  if (def.resolveOutput) return def.resolveOutput(input, args, catalog ? { catalog } : undefined);
  // Default arm — ALL legacy/scalar ops: the flat static output, provably no behavior change.
  return descriptorFromType(def.outputType);
}

/**
 * Walk a WIRE element pipeline (`{op,args}[]`) from a ROOT element descriptor and return its
 * TERMINAL descriptor — the array-transform wave-2 mirror of the backend's per-element walk.
 * `array_map` uses it to compute `array<terminal>`; the element-pipeline validity check uses it
 * to know the running element type when recursing. Unknown ops leave the running type unchanged.
 */
export function resolveElementTerminalWire(
  catalog: VariableOperationDefinition[],
  root: OperationTypeDescriptor,
  wire: WireStep[],
): OperationTypeDescriptor {
  let current = root;
  for (const step of Array.isArray(wire) ? wire : []) {
    const def = catalog.find((op) => op.id === step.op);
    if (!def) continue;
    current = outputDescriptorOf(def, current, step.args ?? {}, catalog);
  }
  return current;
}

/**
 * Walk a pipeline from a start DESCRIPTOR, threading each op's output descriptor into the next.
 * Unknown ops are skipped (running descriptor unchanged), exactly as before.
 */
export function resolveDescriptor(
  catalog: VariableOperationDefinition[],
  startDescriptor: OperationTypeDescriptor,
  pipeline: VariablePipelineStep[],
): OperationTypeDescriptor {
  let current = startDescriptor;
  for (const step of pipeline) {
    const def = catalog.find((op) => op.id === step.operationId);
    if (!def) continue;
    current = outputDescriptorOf(def, current, step.args ?? {}, catalog);
  }
  return current;
}

/**
 * Resolve the FINAL output type of a pipeline starting from `startType`. Each step's operation
 * output becomes the next step's input. Unknown ops are skipped. Descriptor-aware internally
 * (so `array_at` yields its element type), but returns the flat `VariablePrimitive` as before.
 */
export function resolveType(
  catalog: VariableOperationDefinition[],
  startType: VariablePrimitive,
  pipeline: VariablePipelineStep[],
  baseDescriptor?: OperationTypeDescriptor,
): VariablePrimitive {
  return typeFromDescriptor(
    resolveDescriptor(catalog, baseDescriptor ?? descriptorFromType(startType), pipeline),
  );
}

/**
 * Whether a pipeline SATISFIES a value-or-variable field's terminal contract — the
 * FE mirror of the backend's terminal rule. Two gates:
 *   1. the pipeline's resolved result type is one of `resultTypes` (empty ⇒ no gate);
 *   2. when a `targetOptions` destination set exists (a "choice"/enum field like task
 *      priority), the pipeline must ALSO be NON-empty and END on a CHOICE-producing op
 *      (`producesChoice()` — a `choiceRules`/`choiceFallback` arg or a `sourceMap` arg
 *      with `mapType:'enum'`). This rejects an identity ref or a plain enum terminal
 *      that never targeted the destination's option set.
 */
export function pipelineSatisfies(
  catalog: VariableOperationDefinition[],
  baseType: VariablePrimitive,
  pipeline: VariablePipelineStep[],
  resultTypes: VariablePrimitive[],
  targetOptions?: VariableOption[],
  baseDescriptor?: OperationTypeDescriptor,
): boolean {
  return pipelineSatisfiesFrom(
    catalog,
    baseDescriptor ?? descriptorFromType(baseType),
    pipeline,
    resultTypes,
    targetOptions,
  );
}

/**
 * The descriptor-rooted core of `pipelineSatisfies` (array-transform wave 3): roots the type-flow at a
 * full `OperationTypeDescriptor` (with `array`/`fields`) rather than a flat `baseType`, so a repeater /
 * file array picked as the source gates its array ops correctly and its element pipelines validate
 * against the true element structure.
 */
export function pipelineSatisfiesFrom(
  catalog: VariableOperationDefinition[],
  rootDescriptor: OperationTypeDescriptor,
  pipeline: VariablePipelineStep[],
  resultTypes: VariablePrimitive[],
  targetOptions?: VariableOption[],
  lastStepIsTerminal = true,
): boolean {
  const typeOk =
    resultTypes.length === 0 ||
    resultTypes.includes(typeFromDescriptor(resolveDescriptor(catalog, rootDescriptor, pipeline)));
  if (!typeOk) return false;
  // Array-transform wave 2/3: a step carrying an element pipeline (map/filter/sort/reduce) must have
  // an inner pipeline that hits its REQUIRED terminal — filter=boolean, sort=number, reduce=seed
  // type, map=any base (not an array). This makes a wrong terminal impossible to complete/save,
  // exactly where the backend write-validator would reject. A NO-OP for every array-op-free pipeline.
  // `lastStepIsTerminal` (F4) is false for a MAP element pipeline (map forces nullable:false on its
  // per-element output), so a trailing `array_at` there still REQUIRES its typed default.
  if (!elementPipelinesValid(catalog, rootDescriptor, pipeline, lastStepIsTerminal)) return false;
  if ((targetOptions ?? []).length) {
    if (pipeline.length === 0) return false;
    const lastOp = catalog.find((op) => op.id === pipeline[pipeline.length - 1].operationId);
    if (!isChoiceProducingOp(lastOp)) return false;
  }
  return true;
}

// --- Element-pipeline terminal gating (array-transform wave 2) ----------------
// The FE mirror of the backend "terminal by construction" rule: an element pipeline's inner
// terminal is FIXED by its host op, so a wrong terminal is unbuildable/unsavable.

/** The ids of the ops that host an element pipeline (map/filter/sort/reduce). */
const ELEMENT_PIPELINE_OP_IDS = ['array_map', 'array_filter', 'array_sort', 'array_reduce'];

/** Whether an op hosts an element pipeline arg (map/filter/sort/reduce). */
export function isElementPipelineOp(op: VariableOperationDefinition | undefined): boolean {
  return !!op && ELEMENT_PIPELINE_OP_IDS.includes(op.id);
}

/** The primitive bases an element-pipeline terminal may be for `array_map` — any NON-array base. */
export const NON_ARRAY_TERMINALS: VariablePrimitive[] = ['text', 'number', 'boolean', 'date', 'enum', 'file'];

/** The reduce SEED's base type (defensive; defaults to number). */
export function reduceSeedType(seed: unknown): VariablePrimitive {
  const type =
    seed && typeof seed === 'object' && !Array.isArray(seed)
      ? (seed as { type?: string }).type
      : undefined;
  return type === 'text' || type === 'number' || type === 'boolean' || type === 'date' ? type : 'number';
}

/**
 * The REQUIRED terminal type(s) for a host array op's element pipeline — the gate a wrong terminal
 * cannot pass: filter→boolean, sort→number, reduce→the seed base, map→any non-array base.
 */
export function elementPipelineTerminalTypes(
  opId: string,
  args: Record<string, unknown>,
): VariablePrimitive[] {
  switch (opId) {
    case 'array_filter':
      return ['boolean'];
    case 'array_sort':
      return ['number'];
    case 'array_reduce':
      return [reduceSeedType(args.seed)];
    case 'array_map':
      return NON_ARRAY_TERMINALS;
    default:
      return [];
  }
}

/** The BASE descriptor an element pipeline runs FROM for a host op — the seed type for reduce,
 * else the array's element descriptor. */
export function elementPipelineBaseDescriptor(
  opId: string,
  args: Record<string, unknown>,
  inputDescriptor: OperationTypeDescriptor,
): OperationTypeDescriptor {
  if (opId === 'array_reduce') return descriptorFromType(reduceSeedType(args.seed));
  return elementDescriptorOf(inputDescriptor);
}

/** Read the WIRE element pipeline arg of a host op (`reducer` for reduce, else `pipeline`). */
export function readElementPipelineArg(
  opId: string,
  args: Record<string, unknown>,
): WireStep[] {
  const raw = opId === 'array_reduce' ? args.reducer : args.pipeline;
  return Array.isArray(raw) ? (raw as WireStep[]) : [];
}

// --- Scope-rooted UNION element pipeline (array-transform wave 3) --------------
// A map/filter/sort over an `array<object>`/`array<file>` cannot root a bare pipeline at the whole
// object element (no op consumes an object). Its `pipeline` arg is instead a value-or-variable UNION
// rooted at a scope `element.<field>` subfield: `{kind:'variable', ref:{source:'scope',
// path:'element.<field>', type}, pipeline:[…]}`. reduce keeps the bare reducer (its ops reference
// `element.<field>` as scope arg-variables). These helpers read/type that union, mirroring the
// backend `validateScopeRootedElementPipeline`.

/** Whether an element-pipeline arg value is the scope-rooted UNION (object/file map/filter/sort). */
export function isElementScopeUnion(value: unknown): value is ArgVariableValue {
  return (
    !!value &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    (value as { kind?: string }).kind === 'variable'
  );
}

/**
 * The descriptor of a scope `element.<field>` subfield within an element descriptor's `fields`,
 * or null when the path is not a known subfield. `element` itself is the whole element descriptor.
 */
export function elementSubfieldDescriptor(
  element: OperationTypeDescriptor,
  path: string | undefined,
): OperationTypeDescriptor | null {
  if (!path) return null;
  if (path === 'element') return element;
  const key = path.startsWith('element.') ? path.slice('element.'.length) : null;
  if (!key) return null;
  const field = element.fields?.find((f) => f.key === key);
  return field ? field.descriptor : null;
}

/**
 * The TERMINAL descriptor of a host op's element-pipeline arg — handles BOTH the bare-list pipeline
 * (scalar/enum element + reduce reducer) AND the scope-rooted UNION (object/file map/filter/sort).
 * Used by `array_map`'s output resolver and the terminal-gating validity check so both mirror the BE.
 */
export function elementPipelineTerminalDescriptor(
  catalog: VariableOperationDefinition[],
  opId: string,
  args: Record<string, unknown>,
  inputDescriptor: OperationTypeDescriptor,
): OperationTypeDescriptor {
  const raw = opId === 'array_reduce' ? args.reducer : args.pipeline;
  if (opId !== 'array_reduce' && isElementScopeUnion(raw)) {
    const element = elementDescriptorOf(inputDescriptor);
    const rootBySubfield = elementSubfieldDescriptor(element, raw.ref?.path);
    const root = rootBySubfield ?? descriptorFromType((raw.ref?.type ?? 'text') as VariablePrimitive);
    const wire = Array.isArray(raw.pipeline) ? (raw.pipeline as WireStep[]) : [];
    return resolveElementTerminalWire(catalog, root, wire);
  }
  const baseDesc = elementPipelineBaseDescriptor(opId, args, inputDescriptor);
  const wire = Array.isArray(raw) ? (raw as WireStep[]) : [];
  return resolveElementTerminalWire(catalog, baseDesc, wire);
}

/** Project a WIRE element pipeline onto editor steps (for the shared type-flow helpers). */
function wireToEditorSteps(
  catalog: VariableOperationDefinition[],
  wire: WireStep[],
): VariablePipelineStep[] {
  return (Array.isArray(wire) ? wire : []).map((w, i) => ({
    stepId: `el-${i}`,
    operationId: w.op,
    args: (w.args ?? {}) as VariablePipelineStep['args'],
    outputType: (catalog.find((op) => op.id === w.op)?.outputType ?? 'text') as VariablePrimitive,
  }));
}

/**
 * Whether EVERY element pipeline nested inside `pipeline` hits its required terminal — recursively.
 * Walks the running descriptor so each map/filter/sort/reduce sees the correct element type, then
 * checks its inner pipeline via `pipelineSatisfies` (which recurses back here for nested element
 * pipelines, depth-bounded by structure). A NO-OP for a pipeline with no array-transform ops.
 */
export function elementPipelinesValid(
  catalog: VariableOperationDefinition[],
  startDescriptor: OperationTypeDescriptor,
  pipeline: VariablePipelineStep[],
  lastStepIsTerminal = true,
): boolean {
  let current = startDescriptor;
  for (let i = 0; i < pipeline.length; i += 1) {
    const step = pipeline[i];
    const def = catalog.find((op) => op.id === step.operationId);
    if (!def) continue;

    // F4: a NON-TERMINAL `array_at` (a following op consumes its nullable element output, OR its
    // pipeline forces a non-null terminal — a MAP element pipeline) MUST carry a valid typed default.
    // A TERMINAL `array_at` (the last step of an ordinary pipeline) stays legal without one.
    if (def.id === 'array_at') {
      const isLast = i === pipeline.length - 1;
      if (
        (!isLast || !lastStepIsTerminal) &&
        !arrayAtDefaultSatisfies((step.args ?? {}).default, elementDescriptorOf(current))
      ) {
        return false;
      }
    }

    if (isElementPipelineOp(def)) {
      const args = (step.args ?? {}) as Record<string, unknown>;
      const required = elementPipelineTerminalTypes(def.id, args);
      const rawArg = def.id === 'array_reduce' ? args.reducer : args.pipeline;
      // A MAP element pipeline forces a non-null per-element output → its inner pipeline's LAST step
      // is treated as non-terminal (a trailing `array_at` there requires a default). filter/sort/reduce
      // keep the normal terminal rule (their gate already fixes a specific scalar terminal).
      const innerLastTerminal = def.id !== 'array_map';
      if (def.id !== 'array_reduce' && isStructuralElement(elementDescriptorOf(current))) {
        // OBJECT/FILE map/filter/sort: the arg MUST be the scope-rooted union (a bare pipeline over an
        // object element is unsatisfiable — no op consumes it). Root the terminal at the picked subfield.
        if (!isElementScopeUnion(rawArg)) return false;
        const element = elementDescriptorOf(current);
        const root = elementSubfieldDescriptor(element, rawArg.ref?.path);
        if (!root) return false; // the union roots at an unknown scope subfield
        const inner = wireToEditorSteps(catalog, Array.isArray(rawArg.pipeline) ? rawArg.pipeline : []);
        if (!pipelineSatisfiesFrom(catalog, root, inner, required, undefined, innerLastTerminal)) return false;
      } else {
        const wire = readElementPipelineArg(def.id, args);
        const inner = wireToEditorSteps(catalog, wire);
        const baseDesc = elementPipelineBaseDescriptor(def.id, args, current);
        if (!pipelineSatisfiesFrom(catalog, baseDesc, inner, required, undefined, innerLastTerminal)) return false;
      }
    }
    current = outputDescriptorOf(def, current, step.args ?? {}, catalog);
  }
  return true;
}

/**
 * The INPUT type for the step at `stepIndex` (i.e. output of all prior steps). Descriptor-aware
 * (an `array_at` earlier in the chain surfaces the element type here), flat `VariablePrimitive` out.
 */
export function computeInputType(
  catalog: VariableOperationDefinition[],
  startType: VariablePrimitive,
  pipeline: VariablePipelineStep[],
  stepIndex: number,
  baseDescriptor?: OperationTypeDescriptor,
): VariablePrimitive {
  const prefix = pipeline.slice(0, Math.max(0, stepIndex));
  return typeFromDescriptor(
    resolveDescriptor(catalog, baseDescriptor ?? descriptorFromType(startType), prefix),
  );
}

/**
 * Operations addable at a given input type. An op is offered when its `inputTypes` includes the
 * running flat type. ARRAY ops (`array_count` / `array_at`) declare `inputTypes:['multi']`, so
 * they are offered exactly when the running value is an ARRAY (the flat `multi` slot) — and never
 * on a scalar — with no special casing needed here.
 */
export function operationsForType(
  catalog: VariableOperationDefinition[],
  inputType: VariablePrimitive,
): VariableOperationDefinition[] {
  return catalog.filter((op) => op.inputTypes.includes(inputType));
}

export function createPipelineStep(
  operation: VariableOperationDefinition,
): VariablePipelineStep {
  return {
    stepId: `step_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
    operationId: operation.id,
    args: buildDefaultArgs(operation.args),
    outputType: operation.outputType,
  };
}
