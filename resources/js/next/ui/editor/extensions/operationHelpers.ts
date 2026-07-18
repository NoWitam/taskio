// operationHelpers.ts — pure helpers for the variable operations pipeline
// (ported from the legacy editor's `utils/operationHelpers.ts` +
// `utils/variableIcons.ts`, mapped to the "next" Icon registry). Shared by the
// VariablePipelineEditor (used in both the VariablePanel and the IF condition
// editor) so the type-flow logic lives in ONE place.
import type { IconName } from '../../primitives/Icon.vue';
import { translate } from '../../../app/i18n';
import type {
  ChoiceRule,
  VariableOperationArgumentDefinition,
  VariableOperationArgumentType,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';

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

type ArgValue = string | number | boolean | string[] | Record<string, string | number> | ChoiceRule[];

/**
 * Build the default arg map for an operation (sourceOptions / choiceRules → [],
 * sourceMap → {}, everything else → '' unless a `defaultValue` is declared).
 */
export function buildDefaultArgs(
  args?: VariableOperationArgumentDefinition[],
): Record<string, ArgValue> {
  if (!args) return {};
  return args.reduce<Record<string, ArgValue>>((acc, arg) => {
    if (arg.defaultValue !== undefined) acc[arg.id] = arg.defaultValue;
    else if (arg.type === 'boolean') acc[arg.id] = false;
    else if (arg.type === 'sourceOptions') acc[arg.id] = [];
    else if (arg.type === 'choiceRules') acc[arg.id] = [];
    else if (arg.type === 'sourceMap') acc[arg.id] = {};
    else acc[arg.id] = '';
    return acc;
  }, {});
}

export function formatArgsLabel(args?: VariableOperationArgumentDefinition[]): string {
  if (!args || !args.length) return translate('editor.types.noArguments', 'No arguments');
  return args.map((arg) => `${arg.label} (${arg.type})`).join(', ');
}

/**
 * Resolve the FINAL output type of a pipeline starting from `startType`. Each
 * step's operation output becomes the next step's input. Unknown ops are skipped.
 */
export function resolveType(
  catalog: VariableOperationDefinition[],
  startType: VariablePrimitive,
  pipeline: VariablePipelineStep[],
): VariablePrimitive {
  let current = startType;
  for (const step of pipeline) {
    const def = catalog.find((op) => op.id === step.operationId);
    if (!def) continue;
    current = def.outputType;
  }
  return current;
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
): boolean {
  const typeOk =
    resultTypes.length === 0 || resultTypes.includes(resolveType(catalog, baseType, pipeline));
  if (!typeOk) return false;
  if ((targetOptions ?? []).length) {
    if (pipeline.length === 0) return false;
    const lastOp = catalog.find((op) => op.id === pipeline[pipeline.length - 1].operationId);
    if (!isChoiceProducingOp(lastOp)) return false;
  }
  return true;
}

/** The INPUT type for the step at `stepIndex` (i.e. output of all prior steps). */
export function computeInputType(
  catalog: VariableOperationDefinition[],
  startType: VariablePrimitive,
  pipeline: VariablePipelineStep[],
  stepIndex: number,
): VariablePrimitive {
  let current = startType;
  for (let i = 0; i < stepIndex; i += 1) {
    const op = catalog.find((item) => item.id === pipeline[i]?.operationId);
    current = op?.outputType ?? current;
  }
  return current;
}

/** Operations addable at a given input type. */
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
