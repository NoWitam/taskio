import type { VariableOperationArgumentDefinition, VariableOperationArgumentType } from '../types/editor';

export function buildDefaultArgs(args?: VariableOperationArgumentDefinition[]) {
  if (!args) return {} as Record<string, string | number | boolean>;
  return args.reduce<Record<string, string | number | boolean>>((acc, arg) => {
    if (arg.defaultValue !== undefined) {
      acc[arg.id] = arg.defaultValue as string | number | boolean;
    } else {
      acc[arg.id] = arg.type === 'boolean' ? false : '';
    }
    return acc;
  }, {});
}

export function formatArgsLabel(args?: VariableOperationArgumentDefinition[]) {
  if (!args || !args.length) return 'Brak argumentów';
  return args.map((arg) => `${arg.label} (${arg.type})`).join(', ');
}

export function getArgumentIconName(type: VariableOperationArgumentType) {
  const map: Record<VariableOperationArgumentType, string> = {
    text: 'signature',
    number: 'hash',
    boolean: 'check-circle',
    select: 'list',
  } as const;
  return map[type] || 'circle-help';
}
