/**
 * Variable operations registry
 * Операции для переменных - определяет только метаданные, обработка делается на сервере
 */

import type { VariableOperation } from '../types';

export const operationsRegistry: Record<string, VariableOperation> = {
  // ===== TEXT OPERATIONS =====
  length: {
    name: 'editor.operations.text.length.name',
    description: 'editor.operations.text.length.description',
    supportedTypes: ['text'],
    returnType: 'number',
    args: [],
  },
  uppercase: {
    name: 'editor.operations.text.uppercase.name',
    description: 'editor.operations.text.uppercase.description',
    supportedTypes: ['text'],
    returnType: 'text',
    args: [],
  },
  lowercase: {
    name: 'editor.operations.text.lowercase.name',
    description: 'editor.operations.text.lowercase.description',
    supportedTypes: ['text'],
    returnType: 'text',
    args: [],
  },
  trim: {
    name: 'editor.operations.text.trim.name',
    description: 'editor.operations.text.trim.description',
    supportedTypes: ['text'],
    returnType: 'text',
    args: [],
  },
  prefix: {
    name: 'editor.operations.text.prefix.name',
    description: 'editor.operations.text.prefix.description',
    supportedTypes: ['text'],
    returnType: 'text',
    args: [
      {
        key: 'prefix',
        label: 'editor.operations.text.prefix.args.prefix.label',
        description: 'editor.operations.text.prefix.args.prefix.description',
        type: 'text',
      },
    ],
  },
  suffix: {
    name: 'editor.operations.text.suffix.name',
    description: 'editor.operations.text.suffix.description',
    supportedTypes: ['text'],
    returnType: 'text',
    args: [
      {
        key: 'suffix',
        label: 'editor.operations.text.suffix.args.suffix.label',
        description: 'editor.operations.text.suffix.args.suffix.description',
        type: 'text',
      },
    ],
  },
  slice: {
    name: 'editor.operations.text.slice.name',
    description: 'editor.operations.text.slice.description',
    supportedTypes: ['text'],
    returnType: 'text',
    args: [
      {
        key: 'start',
        label: 'editor.operations.text.slice.args.start.label',
        description: 'editor.operations.text.slice.args.start.description',
        type: 'number',
      },
      {
        key: 'end',
        label: 'editor.operations.text.slice.args.end.label',
        description: 'editor.operations.text.slice.args.end.description',
        type: 'number',
      },
    ],
  },
  truncate: {
    name: 'editor.operations.text.truncate.name',
    description: 'editor.operations.text.truncate.description',
    supportedTypes: ['text'],
    returnType: 'text',
    args: [
      {
        key: 'length',
        label: 'editor.operations.text.truncate.args.length.label',
        description: 'editor.operations.text.truncate.args.length.description',
        type: 'number',
      },
    ],
  },
  includes: {
    name: 'editor.operations.text.includes.name',
    description: 'editor.operations.text.includes.description',
    supportedTypes: ['text'],
    returnType: 'boolean',
    args: [
      {
        key: 'substring',
        label: 'editor.operations.text.includes.args.substring.label',
        description: 'editor.operations.text.includes.args.substring.description',
        type: 'text',
      },
    ],
  },

  // ===== NUMBER OPERATIONS =====
  add: {
    name: 'editor.operations.number.add.name',
    description: 'editor.operations.number.add.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.add.args.value.label',
        description: 'editor.operations.number.add.args.value.description',
        type: 'number',
      },
    ],
  },
  subtract: {
    name: 'editor.operations.number.subtract.name',
    description: 'editor.operations.number.subtract.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.subtract.args.value.label',
        description: 'editor.operations.number.subtract.args.value.description',
        type: 'number',
      },
    ],
  },
  multiply: {
    name: 'editor.operations.number.multiply.name',
    description: 'editor.operations.number.multiply.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.multiply.args.value.label',
        description: 'editor.operations.number.multiply.args.value.description',
        type: 'number',
      },
    ],
  },
  divide: {
    name: 'editor.operations.number.divide.name',
    description: 'editor.operations.number.divide.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.divide.args.value.label',
        description: 'editor.operations.number.divide.args.value.description',
        type: 'number',
      },
    ],
  },
  modulo: {
    name: 'editor.operations.number.modulo.name',
    description: 'editor.operations.number.modulo.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.modulo.args.value.label',
        description: 'editor.operations.number.modulo.args.value.description',
        type: 'number',
      },
    ],
  },
  floor: {
    name: 'editor.operations.number.floor.name',
    description: 'editor.operations.number.floor.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [],
  },
  ceil: {
    name: 'editor.operations.number.ceil.name',
    description: 'editor.operations.number.ceil.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [],
  },
  round: {
    name: 'editor.operations.number.round.name',
    description: 'editor.operations.number.round.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [],
  },
  abs: {
    name: 'editor.operations.number.abs.name',
    description: 'editor.operations.number.abs.description',
    supportedTypes: ['number'],
    returnType: 'number',
    args: [],
  },
  gt: {
    name: 'editor.operations.number.gt.name',
    description: 'editor.operations.number.gt.description',
    supportedTypes: ['number'],
    returnType: 'boolean',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.gt.args.value.label',
        description: 'editor.operations.number.gt.args.value.description',
        type: 'number',
      },
    ],
  },
  lt: {
    name: 'editor.operations.number.lt.name',
    description: 'editor.operations.number.lt.description',
    supportedTypes: ['number'],
    returnType: 'boolean',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.lt.args.value.label',
        description: 'editor.operations.number.lt.args.value.description',
        type: 'number',
      },
    ],
  },
  equals: {
    name: 'editor.operations.number.equals.name',
    description: 'editor.operations.number.equals.description',
    supportedTypes: ['number'],
    returnType: 'boolean',
    args: [
      {
        key: 'value',
        label: 'editor.operations.number.equals.args.value.label',
        description: 'editor.operations.number.equals.args.value.description',
        type: 'number',
      },
    ],
  },

  // ===== BOOLEAN OPERATIONS =====
  negate: {
    name: 'editor.operations.boolean.negate.name',
    description: 'editor.operations.boolean.negate.description',
    supportedTypes: ['boolean'],
    returnType: 'boolean',
    args: [],
  },
  toText: {
    name: 'editor.operations.boolean.toText.name',
    description: 'editor.operations.boolean.toText.description',
    supportedTypes: ['boolean'],
    returnType: 'text',
    args: [],
  },

  // ===== DATE OPERATIONS =====
  format: {
    name: 'editor.operations.date.format.name',
    description: 'editor.operations.date.format.description',
    supportedTypes: ['date'],
    returnType: 'text',
    args: [
      {
        key: 'format',
        label: 'editor.operations.date.format.args.format.label',
        description: 'editor.operations.date.format.args.format.description',
        type: 'text',
      },
    ],
  },
  addDays: {
    name: 'editor.operations.date.addDays.name',
    description: 'editor.operations.date.addDays.description',
    supportedTypes: ['date'],
    returnType: 'date',
    args: [
      {
        key: 'days',
        label: 'editor.operations.date.addDays.args.days.label',
        description: 'editor.operations.date.addDays.args.days.description',
        type: 'number',
      },
    ],
  },
  isFuture: {
    name: 'editor.operations.date.isFuture.name',
    description: 'editor.operations.date.isFuture.description',
    supportedTypes: ['date'],
    returnType: 'boolean',
    args: [],
  },
  isPast: {
    name: 'editor.operations.date.isPast.name',
    description: 'editor.operations.date.isPast.description',
    supportedTypes: ['date'],
    returnType: 'boolean',
    args: [],
  },
};

/**
 * Get operation by name
 */
export function getOperation(name: string): VariableOperation | null {
  return operationsRegistry[name] || null;
}

/**
 * Get all operations for a specific variable type
 */
export function getOperationsForType(variableType: string): Array<{ key: string; operation: VariableOperation }> {
  return Object.entries(operationsRegistry)
    .filter(([_, op]) => op.supportedTypes.includes(variableType as any))
    .map(([key, operation]) => ({ key, operation }));
}

/**
 * Calculate the result type after applying a series of operations
 */
export function getResultType(inputType: string, operations?: Array<{ op: string; args?: any[] }> | null): string {
  if (!operations || operations.length === 0) {
    return inputType;
  }

  let currentType = inputType;

  for (const op of operations) {
    const operation = getOperation(op.op);
    if (operation) {
      currentType = operation.returnType;
    }
  }

  return currentType;
}

/**
 * Dla kompatybilności z istniejącym kodem
 */
export function getAllOperations(): VariableOperation[] {
  return Object.values(operationsRegistry);
}

/**
 * Rejestracja operacji (stara funkcja - nie jest już potrzebna)
 * Pozostajemy dla kompatybilności, ale nie robi nic
 */
export function registerDefaultOperations() {
  // Operations are now defined in operationsRegistry directly
}

/**
 * Parse operations from string format: "op:add("5")|op:multiply("2")"
 * Returns array of {op: string, args?: string[]}
 */
export function parseOperationsFromString(opsStr: string): Array<{ op: string; args?: any[] }> {
  if (!opsStr) return [];

  const operations: Array<{ op: string; args?: any[] }> = [];
  const parts = opsStr.split('|');

  for (const part of parts) {
    const match = part.match(/op:(\w+)(?:\(([^)]*)\))?/);
    if (!match) continue;

    const opName = match[1];
    const argsStr = match[2];

    const args: any[] = [];
    if (argsStr) {
      // Sparsuj argumenty oddzielone przecinkami, usuń cudzysłowy
      const argMatches = argsStr.match(/"([^"]*)"|'([^']*)'|([^,]+)/g);
      if (argMatches) {
        for (const arg of argMatches) {
          const cleaned = arg.replace(/^["']|["']$/g, '').trim();
          if (cleaned) {
            args.push(cleaned);
          }
        }
      }
    }

    operations.push({
      op: opName,
      args: args.length > 0 ? args : undefined,
    });
  }

  return operations;
}

/**
 * Render variable value for preview
 * Since operations are executed server-side, this just returns the raw variable value
 * In the preview, operations cannot be executed on the client
 */
export function renderVariable(
  variables: any[],
  varId: string,
  ops?: Array<{ op: string; args?: any[] }>
): string {
  const variable = variables.find((v) => v.id === varId);
  if (!variable) {
    return `[${varId}]`;
  }

  let value = variable.value;

  // Operations are processed server-side, so we just return the raw value
  // In a real implementation, you might show a placeholder or fetch the processed value from the server
  if (ops && ops.length > 0) {
    // Show that operations will be applied
    const opNames = ops.map((o) => o.op).join(' → ');
    return `${value} (with: ${opNames})`;
  }

  return String(value);
}
