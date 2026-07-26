// arrayTransformOps.spec — the array-transform WAVE 2 FE mirror (map / filter / sort / reduce +
// element-pipeline terminal gating). Pins the FE↔BE op contract by id/shape and the "terminal by
// construction" rule the backend write-validator also enforces:
//   • the 4 ops mirror the backend ids/args (map/filter/sort → one `elementPipeline` arg; reduce →
//     a `reduceSeed` seed + an `elementPipeline` reducer), all `inputTypes:['multi']`;
//   • they are OFFERED on an ARRAY (the flat multi slot) and NEVER on a scalar;
//   • output typing: map→array<terminal>, filter/sort→the input array unchanged, reduce→the seed base;
//   • terminal gating (via `pipelineSatisfies`): a wrong element-pipeline terminal is INVALID —
//     filter must end boolean, sort number, reduce the seed type, map any non-array base.
import { describe, it, expect, beforeAll } from 'vitest';
import { setLocale } from '../../../app/i18n';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import {
  buildDefaultArgs,
  descriptorFromType,
  elementPipelineTerminalTypes,
  operationsForType,
  pipelineSatisfies,
  reduceSeedType,
  resolveDescriptor,
  resolveType,
} from '../extensions/operationHelpers';
import { BACKEND_WORKFLOW_OPERATION_IDS } from '../extensions/__tests__/backendWorkflowOperationIds';
import type { VariableOption, VariablePipelineStep, VariablePrimitive } from '../extensions/types';

const OPTIONS: VariableOption[] = [
  { label: 'Open', value: 'open' },
  { label: 'Done', value: 'done' },
];

/** A pipeline step over an ELEMENT pipeline (map/filter/sort) — the `pipeline` arg is the wire. */
function arrayStep(operationId: string, args: Record<string, unknown>): VariablePipelineStep {
  return { stepId: operationId, operationId, args, outputType: 'multi' };
}

describe('array-transform ops (wave 2 — map/filter/sort/reduce)', () => {
  beforeAll(() => setLocale('en'));
  const catalog = () => standardOperationsCatalog();

  it('mirrors the backend ids exactly (map/filter/sort/reduce present both sides)', () => {
    const ids = new Set(catalog().map((op) => op.id));
    for (const id of ['array_map', 'array_filter', 'array_sort', 'array_reduce']) {
      expect(ids.has(id), id).toBe(true);
      expect(BACKEND_WORKFLOW_OPERATION_IDS, id).toContain(id);
    }
  });

  it('mirrors the backend catalog by input/args (map/filter/sort: one elementPipeline; reduce: seed + reducer)', () => {
    const argShape = (id: string) =>
      (catalog().find((op) => op.id === id)?.args ?? []).map((a) => ({ id: a.id, type: a.type }));

    for (const id of ['array_map', 'array_filter', 'array_sort']) {
      const op = catalog().find((o) => o.id === id)!;
      expect(op.inputTypes, id).toEqual(['multi']);
      expect(argShape(id), id).toEqual([{ id: 'pipeline', type: 'elementPipeline' }]);
    }
    const reduce = catalog().find((o) => o.id === 'array_reduce')!;
    expect(reduce.inputTypes).toEqual(['multi']);
    expect(argShape('array_reduce')).toEqual([
      { id: 'seed', type: 'reduceSeed' },
      { id: 'reducer', type: 'elementPipeline' },
    ]);
  });

  it('OFFERS map/filter/sort/reduce on an ARRAY and never on a scalar', () => {
    const onMulti = operationsForType(catalog(), 'multi').map((op) => op.id);
    for (const id of ['array_map', 'array_filter', 'array_sort', 'array_reduce']) {
      expect(onMulti, id).toContain(id);
    }
    for (const scalar of ['text', 'number', 'boolean', 'date', 'enum'] as VariablePrimitive[]) {
      const offered = operationsForType(catalog(), scalar).map((op) => op.id);
      for (const id of ['array_map', 'array_filter', 'array_sort', 'array_reduce']) {
        expect(offered, `${id} on ${scalar}`).not.toContain(id);
      }
    }
  });

  it('buildDefaultArgs: elementPipeline → [], reduceSeed → a numeric 0 seed', () => {
    const mapDefaults = buildDefaultArgs(catalog().find((o) => o.id === 'array_map')!.args);
    expect(mapDefaults.pipeline).toEqual([]);

    const reduceDefaults = buildDefaultArgs(catalog().find((o) => o.id === 'array_reduce')!.args);
    expect(reduceDefaults.seed).toEqual({ type: 'number', value: 0 });
    expect(reduceDefaults.reducer).toEqual([]);
  });

  // --- Output typing (map→array<terminal>, filter/sort→input, reduce→seed) --------------------

  it('map yields array<terminal> — the element pipeline terminal wrapped in an array', () => {
    // multi(enum) -> map(enum_to_number per element) -> array<number>
    const pipeline = [arrayStep('array_map', { pipeline: [{ op: 'enum_to_number', args: { mapping: {} } }] })];
    const desc = resolveDescriptor(catalog(), descriptorFromType('multi', OPTIONS), pipeline);
    expect(desc.array).toBe(true);
    expect(desc.base).toBe('number');
    // Flat wire still degrades to the multi slot.
    expect(resolveType(catalog(), 'multi', pipeline)).toBe('multi');
  });

  it('filter / sort leave the INPUT array unchanged', () => {
    const filter = [arrayStep('array_filter', { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] })];
    const fDesc = resolveDescriptor(catalog(), descriptorFromType('multi', OPTIONS), filter);
    expect(fDesc.array).toBe(true);
    expect(fDesc.base).toBe('enum');

    const sort = [arrayStep('array_sort', { pipeline: [{ op: 'enum_to_number', args: { mapping: {} } }] })];
    const sDesc = resolveDescriptor(catalog(), descriptorFromType('multi', OPTIONS), sort);
    expect(sDesc.array).toBe(true);
    expect(sDesc.base).toBe('enum');
  });

  it('reduce yields the SEED base (a single non-array value)', () => {
    const numberSeed = [arrayStep('array_reduce', { seed: { type: 'number', value: 0 }, reducer: [] })];
    expect(resolveType(catalog(), 'multi', numberSeed)).toBe('number');
    const textSeed = [arrayStep('array_reduce', { seed: { type: 'text', value: '' }, reducer: [] })];
    expect(resolveType(catalog(), 'multi', textSeed)).toBe('text');
  });

  // --- Terminal gating (terminal by construction) ---------------------------------------------

  it('elementPipelineTerminalTypes fixes the required terminal per host op', () => {
    expect(elementPipelineTerminalTypes('array_filter', {})).toEqual(['boolean']);
    expect(elementPipelineTerminalTypes('array_sort', {})).toEqual(['number']);
    expect(elementPipelineTerminalTypes('array_reduce', { seed: { type: 'date', value: '2026-01-01' } })).toEqual(['date']);
    // map = any NON-array base — must never accept an array (multi) terminal.
    expect(elementPipelineTerminalTypes('array_map', {})).not.toContain('multi');
  });

  it('reduceSeedType reads the seed type (defaults to number)', () => {
    expect(reduceSeedType({ type: 'text', value: '' })).toBe('text');
    expect(reduceSeedType({ type: 'boolean', value: true })).toBe('boolean');
    expect(reduceSeedType(undefined)).toBe('number');
    expect(reduceSeedType({ type: 'bogus' })).toBe('number');
  });

  it('rejects a filter element pipeline that does NOT end boolean; accepts one that does', () => {
    const notBoolean = [arrayStep('array_filter', { pipeline: [{ op: 'enum_to_number', args: { mapping: {} } }] })];
    expect(pipelineSatisfies(catalog(), 'multi', notBoolean, [])).toBe(false);

    const boolean = [arrayStep('array_filter', { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] })];
    expect(pipelineSatisfies(catalog(), 'multi', boolean, [])).toBe(true);

    // An EMPTY filter pipeline returns the element (enum), not boolean → invalid.
    const empty = [arrayStep('array_filter', { pipeline: [] })];
    expect(pipelineSatisfies(catalog(), 'multi', empty, [])).toBe(false);
  });

  it('rejects a sort element pipeline that does NOT end number; accepts one that does', () => {
    const notNumber = [arrayStep('array_sort', { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] })];
    expect(pipelineSatisfies(catalog(), 'multi', notNumber, [])).toBe(false);

    const number = [arrayStep('array_sort', { pipeline: [{ op: 'enum_to_number', args: { mapping: {} } }] })];
    expect(pipelineSatisfies(catalog(), 'multi', number, [])).toBe(true);
  });

  it('reduce reducer must end on the SEED type — the seed roots the reducer terminal', () => {
    // number seed + a reducer that ends TEXT → invalid.
    const wrong = [
      arrayStep('array_reduce', {
        seed: { type: 'number', value: 0 },
        reducer: [{ op: 'num_to_text', args: {} }],
      }),
    ];
    expect(pipelineSatisfies(catalog(), 'multi', wrong, [])).toBe(false);

    // number seed + a reducer that ends NUMBER → valid.
    const right = [
      arrayStep('array_reduce', {
        seed: { type: 'number', value: 0 },
        reducer: [{ op: 'num_add', args: { value: 1 } }],
      }),
    ];
    expect(pipelineSatisfies(catalog(), 'multi', right, [])).toBe(true);

    // Changing the seed to TEXT re-roots the reducer terminal: num_add is no longer valid there,
    // but an empty reducer (identity over the text seed) is.
    const textSeed = [
      arrayStep('array_reduce', { seed: { type: 'text', value: '' }, reducer: [] }),
    ];
    expect(pipelineSatisfies(catalog(), 'multi', textSeed, [])).toBe(true);
  });
});
