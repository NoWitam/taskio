// operationHelpers.ts — pure helpers for the variable operations pipeline
// (ported from the legacy editor's `utils/operationHelpers.ts` +
// `utils/variableIcons.ts`, mapped to the "next" Icon registry). Shared by the
// VariablePipelineEditor (used in both the VariablePanel and the IF condition
// editor) so the type-flow logic lives in ONE place.
import type { IconName } from '../../primitives/Icon.vue';
import { translate } from '../../../app/i18n';
import type {
  VariableOperationArgumentDefinition,
  VariableOperationArgumentType,
  VariableOperationDefinition,
  VariablePipelineStep,
  VariablePrimitive,
} from './types';

// Icon per primitive. Labels are NOT stored here — they resolve through i18n at
// call time (see `getVariableIconLabel`) so they follow the active UI language.
const VARIABLE_TYPE_ICON: Record<VariablePrimitive, IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
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
    select: 'list',
  };
  return map[type] ?? 'help-circle';
}

/** Build the default arg map for an operation (legacy parity). */
export function buildDefaultArgs(
  args?: VariableOperationArgumentDefinition[],
): Record<string, string | number | boolean> {
  if (!args) return {};
  return args.reduce<Record<string, string | number | boolean>>((acc, arg) => {
    if (arg.defaultValue !== undefined) acc[arg.id] = arg.defaultValue;
    else acc[arg.id] = arg.type === 'boolean' ? false : '';
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
