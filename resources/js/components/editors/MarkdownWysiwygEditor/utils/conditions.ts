/**
 * Conditions - evaluator dla IF/FOR/SWITCH DSL
 */

import type { VariableDef } from '../types';
import { executeOperations, getOperation, parseOperationsFromString } from './operations';

/**
 * Evaluuj condition wyrażenie do boolean
 * Obsługuje:
 * - Proste zmienne (truthy/falsey)
 * - Zmienne ze pipeline operacji: "varId|op:equals('value')|op:gt(5)"
 */
export function evaluateCondition(
  condition: string,
  variables: VariableDef[]
): boolean {
  try {
    // Sparsuj "varId|op:equals('value')|op:gt(5)"
    const parts = condition.split('|');
    const varId = parts[0];
    const opsStr = parts.slice(1).join('|');

    const variable = variables.find((v) => v.id === varId);
    if (!variable) {
      console.warn(`Variable "${varId}" not found in condition`);
      return false;
    }

    let value = variable.value;

    // Jeśli są operacje, wykonaj je
    if (opsStr) {
      const ops = parseOperationsFromString(opsStr);
      value = executeOperations(value, ops);
    }

    // Ostateczna konwersja na boolean
    return Boolean(value);
  } catch (err) {
    console.error('Error evaluating condition:', err);
    return false;
  }
}

/**
 * Renderuj FOR loop
 */
export function renderForLoop(
  varId: string,
  variables: VariableDef[],
  template: string,
  processor: (item: any, index: number, content: string) => string
): string {
  const variable = variables.find((v) => v.id === varId);
  if (!variable || !Array.isArray(variable.value)) {
    return '';
  }

  return variable.value
    .map((item, index) => {
      return processor(item, index, template);
    })
    .join('');
}

/**
 * Evaluuj SWITCH
 */
export function evaluateSwitch(
  varId: string,
  variables: VariableDef[],
  cases: Record<string, string>,
  defaultContent?: string
): string {
  const variable = variables.find((v) => v.id === varId);
  if (!variable) {
    return defaultContent ?? '';
  }

  const value = String(variable.value);
  return cases[value] ?? defaultContent ?? '';
}

/**
 * Sparsuj condition blok: {{#if condition:"expr"}}...{{#else}}...{{/if}}
 */
export function parseIfBlock(content: string): {
  condition: string;
  ifContent: string;
  elseContent?: string;
} | null {
  const match = content.match(
    /\{\{#if\s+condition:"([^"]+)"\}\}([\s\S]*?)(?:\{\{#else\}\}([\s\S]*?))?\{\{\/if\}\}/
  );
  if (!match) return null;

  return {
    condition: match[1],
    ifContent: match[2],
    elseContent: match[3],
  };
}

/**
 * Sparsuj FOR blok: {{#for item:"VAR_ID"}}...{{/for}}
 */
export function parseForBlock(content: string): {
  varId: string;
  content: string;
} | null {
  const match = content.match(/\{\{#for\s+item:"([^"]+)"\}\}([\s\S]*?)\{\{\/for\}\}/);
  if (!match) return null;

  return {
    varId: match[1],
    content: match[2],
  };
}

/**
 * Sparsuj SWITCH blok: {{#switch expr:"VAR_ID"}}{{#case value:"a"}}...{{/case}}...{{#default}}...{{/default}}{{/switch}}
 */
export function parseSwitchBlock(content: string): {
  varId: string;
  cases: Record<string, string>;
  defaultContent?: string;
} | null {
  const switchMatch = content.match(
    /\{\{#switch\s+expr:"([^"]+)"\}\}([\s\S]*?)\{\{\/switch\}\}/
  );
  if (!switchMatch) return null;

  const varId = switchMatch[1];
  const bodyContent = switchMatch[2];

  const cases: Record<string, string> = {};
  let defaultContent = '';

  // Sparsuj case bloki
  const caseMatches = bodyContent.matchAll(
    /\{\{#case\s+value:"([^"]+)"\}\}([\s\S]*?)\{\{\/case\}\}/g
  );
  for (const match of caseMatches) {
    cases[match[1]] = match[2];
  }

  // Sparsuj default blok
  const defaultMatch = bodyContent.match(
    /\{\{#default\}\}([\s\S]*?)\{\{\/default\}\}/
  );
  if (defaultMatch) {
    defaultContent = defaultMatch[1];
  }

  return { varId, cases, defaultContent };
}

/**
 * Oblicz zagnieżdżenie bloków AI
 */
export function calculateAiNestingLevel(content: string): number {
  const aiBlocks = (content.match(/\{\{ai:/g) || []).length;
  return aiBlocks;
}
