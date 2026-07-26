// arrayOperations.spec — the array-transform wave 1 FE mirror (descriptor-tracking walker
// + array_count + array_at). Pins the two invariants the FE↔BE op contract rests on:
//   • array_count / array_at are OFFERED on an ARRAY (the flat `multi` running type) and NOT
//     on a scalar, mirroring the backend `descriptor.array === true` gate;
//   • array_count yields a plain number, while array_at yields the input's ELEMENT type (a
//     MULTI's element is a single choice → enum) so a FOLLOWING op sees the element and chains.
// Descriptor tracking must stay a NO-OP for every legacy op — the resolveType/computeInputType
// values for the standard catalog are unchanged (covered here + in pipeline.spec / standardOperations.spec).
import { describe, it, expect, beforeAll } from 'vitest';
import { setLocale } from '../../../app/i18n';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import {
  computeInputType,
  operationsForType,
  resolveType,
} from '../extensions/operationHelpers';
import type { VariablePipelineStep, VariablePrimitive } from '../extensions/types';
import { BACKEND_WORKFLOW_OPERATION_IDS } from '../extensions/__tests__/backendWorkflowOperationIds';

function step(operationId: string, outputType: VariablePrimitive): VariablePipelineStep {
  return { stepId: operationId, operationId, args: {}, outputType };
}

describe('array-transform ops (wave 1)', () => {
  beforeAll(() => setLocale('en'));
  const catalog = () => standardOperationsCatalog();

  it('mirrors the backend ids exactly (array_count, array_at present both sides)', () => {
    const ids = new Set(catalog().map((op) => op.id));
    expect(ids.has('array_count')).toBe(true);
    expect(ids.has('array_at')).toBe(true);
    expect(BACKEND_WORKFLOW_OPERATION_IDS).toContain('array_count');
    expect(BACKEND_WORKFLOW_OPERATION_IDS).toContain('array_at');
  });

  it('mirrors the backend catalog by input/output/args (count: multi→number no args; at: multi, one number `index`)', () => {
    const count = catalog().find((op) => op.id === 'array_count')!;
    expect(count.inputTypes).toEqual(['multi']);
    expect(count.outputType).toBe('number');
    expect(count.args ?? []).toEqual([]);

    const at = catalog().find((op) => op.id === 'array_at')!;
    expect(at.inputTypes).toEqual(['multi']);
    // F4: array_at now also declares a typed `default` arg (the value substituted for an empty element).
    expect((at.args ?? []).map((a) => a.id)).toEqual(['index', 'default']);
    expect((at.args ?? [])[0].type).toBe('number');
    expect((at.args ?? [])[1].type).toBe('elementDefault');
  });

  it('OFFERS array_count / array_at on an ARRAY (the flat multi running type)', () => {
    const offered = operationsForType(catalog(), 'multi').map((op) => op.id);
    expect(offered).toContain('array_count');
    expect(offered).toContain('array_at');
  });

  it('does NOT offer the array ops on a scalar running type', () => {
    for (const scalar of ['text', 'number', 'boolean', 'date', 'enum'] as VariablePrimitive[]) {
      const offered = operationsForType(catalog(), scalar).map((op) => op.id);
      expect(offered, scalar).not.toContain('array_count');
      expect(offered, scalar).not.toContain('array_at');
    }
  });

  it('array_count yields a number from a multi', () => {
    expect(resolveType(catalog(), 'multi', [step('array_count', 'number')])).toBe('number');
  });

  it('array_at yields the ELEMENT type (a multi element = enum) and chains into an enum op', () => {
    // multi -> at -> enum
    expect(resolveType(catalog(), 'multi', [step('array_at', 'text')])).toBe('enum');
    // the NEXT step's input is the element type, so enum ops are offered after `at`.
    const pipeline = [step('array_at', 'text')];
    expect(computeInputType(catalog(), 'multi', pipeline, 1)).toBe('enum');
    const afterAt = operationsForType(catalog(), computeInputType(catalog(), 'multi', pipeline, 1)).map(
      (op) => op.id,
    );
    expect(afterAt).toContain('enum_is');
    // multi -> at(enum) -> enum_is(boolean) type-flows end to end.
    expect(resolveType(catalog(), 'multi', [step('array_at', 'text'), step('enum_is', 'boolean')])).toBe(
      'boolean',
    );
  });

  it('descriptor tracking is a NO-OP for legacy scalar ops (unchanged type flow)', () => {
    // A representative legacy chain resolves exactly as its static outputTypes dictate.
    expect(resolveType(catalog(), 'text', [step('text_length', 'number'), step('num_gt', 'boolean')])).toBe(
      'boolean',
    );
    expect(resolveType(catalog(), 'multi', [step('multi_to_text', 'text')])).toBe('text');
    expect(resolveType(catalog(), 'multi', [step('multi_count', 'number')])).toBe('number');
  });
});
