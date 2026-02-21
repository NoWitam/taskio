/**
 * Variable operations - definicje i wykonywanie operacji na zmiennych
 */

import type { VariableOperation, VariableDef } from '../types';

// Registry operacji
const operationsRegistry: Map<string, VariableOperation> = new Map();

/**
 * Rejestracja domyślnych operacji
 */
export function registerDefaultOperations() {
  registerOperation('length', {
    name: 'length',
    description: 'Zwraca długość tekstu/tablicy',
    handler: (value: any) => {
      if (typeof value === 'string' || Array.isArray(value)) {
        return value.length;
      }
      return 0;
    },
  });

  registerOperation('uppercase', {
    name: 'uppercase',
    description: 'Konwertuje na wielkie litery',
    handler: (value: any) => {
      return String(value).toUpperCase();
    },
  });

  registerOperation('lowercase', {
    name: 'lowercase',
    description: 'Konwertuje na małe litery',
    handler: (value: any) => {
      return String(value).toLowerCase();
    },
  });

  registerOperation('prefix', {
    name: 'prefix',
    description: 'Dodaje prefiks do wartości',
    handler: (value: any, args?: any[]) => {
      const prefix = args?.[0] ?? '';
      return prefix + String(value);
    },
  });

  registerOperation('suffix', {
    name: 'suffix',
    description: 'Dodaje sufiks do wartości',
    handler: (value: any, args?: any[]) => {
      const suffix = args?.[0] ?? '';
      return String(value) + suffix;
    },
  });

  registerOperation('repeat', {
    name: 'repeat',
    description: 'Powtarza wartość N razy',
    handler: (value: any, args?: any[]) => {
      const times = args?.[0] ?? 1;
      return String(value).repeat(Number(times));
    },
  });

  registerOperation('concat', {
    name: 'concat',
    description: 'Łączy z innymi wartościami',
    handler: (value: any, args?: any[]) => {
      return String(value) + (args?.[0] ?? '');
    },
  });

  registerOperation('truncate', {
    name: 'truncate',
    description: 'Skraca tekst do N znaków',
    handler: (value: any, args?: any[]) => {
      const length = args?.[0] ?? 50;
      const text = String(value);
      return text.length > length ? text.substring(0, length) + '...' : text;
    },
  });

  registerOperation('slice', {
    name: 'slice',
    description: 'Wyciąga część tekstu (start, end)',
    handler: (value: any, args?: any[]) => {
      const start = args?.[0] ?? 0;
      const end = args?.[1];
      return String(value).slice(start, end);
    },
  });

  registerOperation('trim', {
    name: 'trim',
    description: 'Usuwa spacje z obu końców',
    handler: (value: any) => {
      return String(value).trim();
    },
  });

  registerOperation('equals', {
    name: 'equals',
    description: 'Sprawdza czy wartość równa się argumentowi',
    handler: (value: any, args?: any[]) => {
      return value === args?.[0];
    },
  });

  registerOperation('gt', {
    name: 'gt',
    description: 'Sprawdza czy wartość > argumentu',
    handler: (value: any, args?: any[]) => {
      return Number(value) > Number(args?.[0]);
    },
  });

  registerOperation('lt', {
    name: 'lt',
    description: 'Sprawdza czy wartość < argumentu',
    handler: (value: any, args?: any[]) => {
      return Number(value) < Number(args?.[0]);
    },
  });

  registerOperation('gte', {
    name: 'gte',
    description: 'Sprawdza czy wartość >= argumentu',
    handler: (value: any, args?: any[]) => {
      return Number(value) >= Number(args?.[0]);
    },
  });

  registerOperation('lte', {
    name: 'lte',
    description: 'Sprawdza czy wartość <= argumentu',
    handler: (value: any, args?: any[]) => {
      return Number(value) <= Number(args?.[0]);
    },
  });

  registerOperation('includes', {
    name: 'includes',
    description: 'Sprawdza czy zawiera substring',
    handler: (value: any, args?: any[]) => {
      return String(value).includes(String(args?.[0]));
    },
  });

  registerOperation('default', {
    name: 'default',
    description: 'Zwraca domyślną wartość jeśli pusta',
    handler: (value: any, args?: any[]) => {
      return !value ? (args?.[0] ?? '') : value;
    },
  });
}

/**
 * Rejestruj operację
 */
export function registerOperation(name: string, operation: VariableOperation) {
  operationsRegistry.set(name, operation);
}

/**
 * Pobierz operację
 */
export function getOperation(name: string): VariableOperation | undefined {
  return operationsRegistry.get(name);
}

/**
 * Pobierz wszystkie operacje
 */
export function getAllOperations(): VariableOperation[] {
  return Array.from(operationsRegistry.values());
}

/**
 * Wykonaj operacje na wartości
 */
export function executeOperations(
  value: any,
  operations: Array<{ op: string; args?: any[] }>
): any {
  let result = value;

  for (const opDef of operations) {
    const operation = getOperation(opDef.op);
    if (!operation) {
      console.warn(`Operation "${opDef.op}" not found`);
      continue;
    }
    try {
      result = operation.handler(result, opDef.args);
    } catch (err) {
      console.error(`Error executing operation "${opDef.op}":`, err);
    }
  }

  return result;
}

/**
 * Renderuj wartość zmiennej z operacjami
 */
export function renderVariable(
  variables: VariableDef[],
  varId: string,
  operations: Array<{ op: string; args?: any[] }> = []
): string {
  const variable = variables.find((v) => v.id === varId);
  if (!variable) {
    return `[VAR NOT FOUND: ${varId}]`;
  }

  try {
    const result = executeOperations(variable.value, operations);
    return String(result);
  } catch (err) {
    return `[ERROR: ${String(err)}]`;
  }
}

/**
 * Sparsuj string operacji z tokenu: "op:length|op:prefix('Hello ')|op:concat(' world')"
 */
export function parseOperationsFromString(
  opsString: string
): Array<{ op: string; args?: any[] }> {
  if (!opsString) return [];

  const operations: Array<{ op: string; args?: any[] }> = [];
  const parts = opsString.split('|');

  for (const part of parts) {
    const match = part.match(/op:(\w+)(?:\(([^)]*)\))?/);
    if (!match) continue;

    const opName = match[1];
    const argsStr = match[2];

    const args = argsStr
      ? argsStr
          .split(',')
          .map((a) => {
            const trimmed = a.trim();
            if (trimmed.startsWith('"') && trimmed.endsWith('"')) {
              return trimmed.slice(1, -1);
            }
            return trimmed;
          })
      : [];

    operations.push({
      op: opName,
      args: args.length > 0 ? args : undefined,
    });
  }

  return operations;
}

// Inicjalizacja
registerDefaultOperations();
