// pipeline.spec.ts — pure tests for the shared variable operations-pipeline
// helpers (the type-flow logic used by VariablePipelineEditor in both the
// VariablePanel and the IF condition editor). These need no DOM.
import { describe, it, expect } from 'vitest';
import {
  buildDefaultArgs,
  computeInputType,
  createPipelineStep,
  getArgumentIconName,
  getVariableIconName,
  operationsForType,
  resolveType,
} from '../extensions/operationHelpers';
import type {
  VariableOperationDefinition,
  VariablePipelineStep,
} from '../extensions/types';

const CATALOG: VariableOperationDefinition[] = [
  { id: 'uppercase', label: 'Uppercase', inputTypes: ['text'], outputType: 'text' },
  { id: 'length', label: 'Length', inputTypes: ['text'], outputType: 'number' },
  {
    id: 'greaterThan',
    label: 'Greater than',
    inputTypes: ['number'],
    outputType: 'boolean',
    args: [{ id: 'value', label: 'Value', type: 'number', defaultValue: 0 }],
  },
  { id: 'negate', label: 'Negate', inputTypes: ['boolean'], outputType: 'boolean' },
];

function step(operationId: string, outputType: 'text' | 'number' | 'boolean'): VariablePipelineStep {
  return { stepId: operationId, operationId, args: {}, outputType };
}

describe('variable pipeline type flow', () => {
  it('resolveType returns the base type for an empty pipeline', () => {
    expect(resolveType(CATALOG, 'text', [])).toBe('text');
    expect(resolveType(CATALOG, 'number', [])).toBe('number');
  });

  it('resolveType walks the pipeline: text -> length(number) -> greaterThan(boolean)', () => {
    const pipeline = [step('length', 'number'), step('greaterThan', 'boolean')];
    expect(resolveType(CATALOG, 'text', pipeline)).toBe('boolean');
  });

  it('computeInputType returns the running type at each step boundary', () => {
    const pipeline = [step('length', 'number'), step('greaterThan', 'boolean')];
    expect(computeInputType(CATALOG, 'text', pipeline, 0)).toBe('text'); // before length
    expect(computeInputType(CATALOG, 'text', pipeline, 1)).toBe('number'); // before greaterThan
    expect(computeInputType(CATALOG, 'text', pipeline, 2)).toBe('boolean'); // final
  });

  it('operationsForType filters by the current input type', () => {
    expect(operationsForType(CATALOG, 'text').map((o) => o.id)).toEqual(['uppercase', 'length']);
    expect(operationsForType(CATALOG, 'number').map((o) => o.id)).toEqual(['greaterThan']);
    expect(operationsForType(CATALOG, 'boolean').map((o) => o.id)).toEqual(['negate']);
  });

  it('createPipelineStep seeds default args + the op output type', () => {
    const op = CATALOG.find((o) => o.id === 'greaterThan')!;
    const made = createPipelineStep(op);
    expect(made.operationId).toBe('greaterThan');
    expect(made.outputType).toBe('boolean');
    expect(made.args).toEqual({ value: 0 });
  });

  it('an IF condition is boolean-valid only when the pipeline resolves to boolean', () => {
    // number variable, empty pipeline → number → INVALID for IF.
    expect(resolveType(CATALOG, 'number', [])).not.toBe('boolean');
    // number → greaterThan → boolean → VALID.
    expect(resolveType(CATALOG, 'number', [step('greaterThan', 'boolean')])).toBe('boolean');
    // boolean variable, empty pipeline → boolean → VALID directly.
    expect(resolveType(CATALOG, 'boolean', [])).toBe('boolean');
  });
});

// --- The EXTENDED type vocabulary (date / enum / multi) -----------------------
// New primitives flow through the SAME helpers; enum/multi comparisons take their
// choices from the SOURCE variable via the sourceOption(s) arg kinds.
const EXTENDED_CATALOG: VariableOperationDefinition[] = [
  {
    id: 'enum_is',
    label: 'Is',
    inputTypes: ['enum'],
    outputType: 'boolean',
    args: [{ id: 'value', label: 'Value', type: 'sourceOption' }],
  },
  {
    id: 'multi_includes',
    label: 'Includes any of',
    inputTypes: ['multi'],
    outputType: 'boolean',
    args: [{ id: 'values', label: 'Values', type: 'sourceOptions' }],
  },
  {
    id: 'date_between',
    label: 'Between',
    inputTypes: ['date'],
    outputType: 'boolean',
    args: [
      { id: 'from', label: 'From', type: 'date' },
      { id: 'to', label: 'To', type: 'date' },
    ],
  },
];

describe('variable pipeline — extended primitives (date/enum/multi)', () => {
  it('the type flow accepts the new primitives end to end', () => {
    expect(resolveType(EXTENDED_CATALOG, 'enum', [])).toBe('enum');
    expect(resolveType(EXTENDED_CATALOG, 'enum', [step('enum_is', 'boolean')])).toBe('boolean');
    expect(resolveType(EXTENDED_CATALOG, 'multi', [step('multi_includes', 'boolean')])).toBe('boolean');
    expect(resolveType(EXTENDED_CATALOG, 'date', [step('date_between', 'boolean')])).toBe('boolean');
  });

  it('operationsForType filters per new primitive', () => {
    expect(operationsForType(EXTENDED_CATALOG, 'enum').map((o) => o.id)).toEqual(['enum_is']);
    expect(operationsForType(EXTENDED_CATALOG, 'multi').map((o) => o.id)).toEqual(['multi_includes']);
    expect(operationsForType(EXTENDED_CATALOG, 'date').map((o) => o.id)).toEqual(['date_between']);
    expect(operationsForType(EXTENDED_CATALOG, 'text')).toEqual([]);
  });

  it('buildDefaultArgs seeds sourceOptions as an EMPTY ARRAY and date as an empty string', () => {
    const includes = EXTENDED_CATALOG.find((o) => o.id === 'multi_includes')!;
    expect(buildDefaultArgs(includes.args)).toEqual({ values: [] });

    const between = EXTENDED_CATALOG.find((o) => o.id === 'date_between')!;
    expect(buildDefaultArgs(between.args)).toEqual({ from: '', to: '' });
  });

  it('the icon maps cover the new primitives + arg kinds (never the fallback glyph)', () => {
    expect(getVariableIconName('date')).toBe('calendar');
    expect(getVariableIconName('enum')).toBe('list');
    expect(getVariableIconName('multi')).toBe('list-checks');
    expect(getArgumentIconName('date')).toBe('calendar');
    expect(getArgumentIconName('sourceOption')).toBe('list');
    expect(getArgumentIconName('sourceOptions')).toBe('list-checks');
  });
});
