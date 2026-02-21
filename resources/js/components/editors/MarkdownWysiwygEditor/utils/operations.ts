/**
 * Variable operations - definiuje i wykonuje operacje na zmiennych
 * Każda operacja ma typ wejścia (supportedTypes) i tip wyjścia (returnType)
 */

import type { VariableOperation, VariableDef } from '../types';

// Registry operacji
const operationsRegistry: Map<string, VariableOperation> = new Map();

/**
 * Rejestracja domyślnych operacji z typowaniem
 */
export function registerDefaultOperations() {
  // ===== TEXT OPERATIONS =====
  registerOperation('length', {
    name: 'length',
    description: 'Zwraca liczbę znaków tekstu',
    supportedTypes: ['text'],
    returnType: 'number',
    argsCount: 0,
    handler: (value: any) => {
      return String(value).length;
    },
  });

  registerOperation('uppercase', {
    name: 'uppercase',
    description: 'Konwertuje na wielkie litery',
    supportedTypes: ['text'],
    returnType: 'text',
    argsCount: 0,
    handler: (value: any) => {
      return String(value).toUpperCase();
    },
  });

  registerOperation('lowercase', {
    name: 'lowercase',
    description: 'Konwertuje na małe litery',
    supportedTypes: ['text'],
    returnType: 'text',
    argsCount: 0,
    handler: (value: any) => {
      return String(value).toLowerCase();
    },
  });

  registerOperation('trim', {
    name: 'trim',
    description: 'Usuwa spacje z obu końców',
    supportedTypes: ['text'],
    returnType: 'text',
    argsCount: 0,
    handler: (value: any) => {
      return String(value).trim();
    },
  });

  registerOperation('prefix', {
    name: 'prefix',
    description: 'Dodaje prefiks do tekstu',
    supportedTypes: ['text'],
    returnType: 'text',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      const prefix = args?.[0] ?? '';
      return prefix + String(value);
    },
  });

  registerOperation('suffix', {
    name: 'suffix',
    description: 'Dodaje sufiks do tekstu',
    supportedTypes: ['text'],
    returnType: 'text',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      const suffix = args?.[0] ?? '';
      return String(value) + suffix;
    },
  });

  registerOperation('slice', {
    name: 'slice',
    description: 'Wyciąga część tekstu (start, koniec)',
    supportedTypes: ['text'],
    returnType: 'text',
    argsCount: 2,
    handler: (value: any, args?: any[]) => {
      const start = args?.[0] ?? 0;
      const end = args?.[1];
      return String(value).slice(Number(start), end ? Number(end) : undefined);
    },
  });

  registerOperation('truncate', {
    name: 'truncate',
    description: 'Skraca tekst do N znaków',
    supportedTypes: ['text'],
    returnType: 'text',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      const length = args?.[0] ?? 50;
      const text = String(value);
      const maxLen = Number(length);
      return text.length > maxLen ? text.substring(0, maxLen) + '...' : text;
    },
  });

  registerOperation('includes', {
    name: 'includes',
    description: 'Sprawdza czy zawiera substring',
    supportedTypes: ['text'],
    returnType: 'boolean',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      return String(value).includes(String(args?.[0] ?? ''));
    },
  });

  // ===== NUMBER OPERATIONS =====
  registerOperation('add', {
    name: 'add',
    description: 'Dodaje liczbę',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      return Number(value) + Number(args?.[0] ?? 0);
    },
  });

  registerOperation('subtract', {
    name: 'subtract',
    description: 'Odejmuje liczbę',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      return Number(value) - Number(args?.[0] ?? 0);
    },
  });

  registerOperation('multiply', {
    name: 'multiply',
    description: 'Mnoży przez liczbę',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      return Number(value) * Number(args?.[0] ?? 1);
    },
  });

  registerOperation('divide', {
    name: 'divide',
    description: 'Dzieli przez liczbę',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      const divisor = Number(args?.[0] ?? 1);
      return divisor !== 0 ? Number(value) / divisor : 0;
    },
  });

  registerOperation('modulo', {
    name: 'modulo',
    description: 'Zwraca resztę z dzielenia',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      const divisor = Number(args?.[0] ?? 1);
      return divisor !== 0 ? Number(value) % divisor : 0;
    },
  });

  registerOperation('floor', {
    name: 'floor',
    description: 'Zaokrągla w dół',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 0,
    handler: (value: any) => {
      return Math.floor(Number(value));
    },
  });

  registerOperation('ceil', {
    name: 'ceil',
    description: 'Zaokrągla w górę',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 0,
    handler: (value: any) => {
      return Math.ceil(Number(value));
    },
  });

  registerOperation('round', {
    name: 'round',
    description: 'Zaokrągla do najbliższej liczby całkowitej',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 0,
    handler: (value: any) => {
      return Math.round(Number(value));
    },
  });

  registerOperation('abs', {
    name: 'abs',
    description: 'Zwraca wartość bezwzględną',
    supportedTypes: ['number'],
    returnType: 'number',
    argsCount: 0,
    handler: (value: any) => {
      return Math.abs(Number(value));
    },
  });

  registerOperation('gt', {
    name: 'gt',
    description: 'Czy jest większa niż',
    supportedTypes: ['number'],
    returnType: 'boolean',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      return Number(value) > Number(args?.[0] ?? 0);
    },
  });

  registerOperation('lt', {
    name: 'lt',
    description: 'Czy jest mniejsza niż',
    supportedTypes: ['number'],
    returnType: 'boolean',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      return Number(value) < Number(args?.[0] ?? 0);
    },
  });

  registerOperation('equals', {
    name: 'equals',
    description: 'Czy równa się',
    supportedTypes: ['number'],
    returnType: 'boolean',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      return Number(value) === Number(args?.[0] ?? 0);
    },
  });

  // ===== BOOLEAN OPERATIONS =====
  registerOperation('negate', {
    name: 'negate',
    description: 'Neguje wartość logiczną',
    supportedTypes: ['boolean'],
    returnType: 'boolean',
    argsCount: 0,
    handler: (value: any) => {
      return !Boolean(value);
    },
  });

  registerOperation('toText', {
    name: 'toText',
    description: 'Konwertuje na tekst (true/false)',
    supportedTypes: ['boolean'],
    returnType: 'text',
    argsCount: 0,
    handler: (value: any) => {
      return Boolean(value) ? 'true' : 'false';
    },
  });

  // ===== DATE OPERATIONS =====
  registerOperation('format', {
    name: 'format',
    description: 'Formatuje datę (YYYY-MM-DD)',
    supportedTypes: ['date'],
    returnType: 'text',
    argsCount: 0,
    handler: (value: any) => {
      try {
        const date = new Date(value);
        return date.toISOString().split('T')[0];
      } catch {
        return String(value);
      }
    },
  });

  registerOperation('addDays', {
    name: 'addDays',
    description: 'Dodaje dni do daty',
    supportedTypes: ['date'],
    returnType: 'date',
    argsCount: 1,
    handler: (value: any, args?: any[]) => {
      try {
        const date = new Date(value);
        const days = Number(args?.[0] ?? 0);
        date.setDate(date.getDate() + days);
        return date.toISOString();
      } catch {
        return value;
      }
    },
  });

  registerOperation('isFuture', {
    name: 'isFuture',
    description: 'Czy data jest w przyszłości',
    supportedTypes: ['date'],
    returnType: 'boolean',
    argsCount: 0,
    handler: (value: any) => {
      try {
        const date = new Date(value);
        return date > new Date();
      } catch {
        return false;
      }
    },
  });

  registerOperation('isPast', {
    name: 'isPast',
    description: 'Czy data jest w przeszłości',
    supportedTypes: ['date'],
    returnType: 'boolean',
    argsCount: 0,
    handler: (value: any) => {
      try {
        const date = new Date(value);
        return date < new Date();
      } catch {
        return false;
      }
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
 * Pobierz operacje dostępne dla danego typu zmiennej
 */
export function getOperationsForType(varType: string): VariableOperation[] {
  return getAllOperations().filter((op) => op.supportedTypes.includes(varType as any));
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
 * Oblicz typ wynikowy operacji
 */
export function getResultType(
  varType: string,
  operations: Array<{ op: string }> = []
): string {
  let currentType = varType;

  for (const opDef of operations) {
    const operation = getOperation(opDef.op);
    if (operation) {
      currentType = operation.returnType;
    }
  }

  return currentType;
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
