// arrayObjectOps.spec — array-transform WAVE 3 FE mirror: map/filter/sort/reduce over an
// `array<object>` (repeater) / `array<file>` element. Pins the descriptor spine + the scope-rooted
// UNION wire shape + terminal-by-construction gating over a STRUCTURED element, mirroring the backend
// (`elementScopeSubfields` + `validateScopeRootedElementPipeline`):
//   • a repeater/file array's descriptor drives the ELEMENT subfields (`element.<field>`);
//   • array ops are offered on it (its array collapses to the `multi` gate) even though its flat type
//     degrades to `text`;
//   • map/filter/sort emit a value-or-variable UNION rooted at `element.<field>`; reduce stays a bare
//     reducer; a BARE pipeline over an object element is unsatisfiable (must be the union);
//   • map yields array<fieldType>; filter must end boolean, sort number — a wrong terminal is invalid.
import { describe, it, expect, beforeAll } from 'vitest';
import { setLocale } from '../../../app/i18n';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import {
  descriptorToOperation,
  elementDescriptorOf,
  elementScopeSubfieldVars,
  pipelineSatisfiesFrom,
  resolveDescriptor,
  typeFromDescriptor,
} from '../extensions/operationHelpers';
import type { OperationTypeDescriptor, VariablePipelineStep } from '../extensions/types';
import type { VariableDescriptor } from '../../variables/types';

/** A repeater `array<object>` with a numeric `price` + text `name` element field. */
const REPEATER: VariableDescriptor = {
  base: 'object',
  nullable: false,
  array: true,
  fields: [
    { key: 'price', label: 'Price', descriptor: { base: 'number', nullable: false, array: false } },
    { key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } },
  ],
};

/** An `array<file>` — its element carries the fixed {id,name,type,size,url} subfields. */
const FILE_ARRAY: VariableDescriptor = {
  base: 'file',
  nullable: false,
  array: true,
  fields: [
    { key: 'id', label: 'id', descriptor: { base: 'text', nullable: false, array: false } },
    { key: 'name', label: 'name', descriptor: { base: 'text', nullable: false, array: false } },
    { key: 'type', label: 'type', descriptor: { base: 'text', nullable: false, array: false } },
    { key: 'size', label: 'size', descriptor: { base: 'number', nullable: false, array: false } },
    { key: 'url', label: 'url', descriptor: { base: 'text', nullable: false, array: false } },
  ],
};

/** The value-or-variable UNION an object/file map/filter/sort arg carries (scope-rooted at a subfield). */
function union(path: string, type: string, pipeline: Array<{ op: string; args: Record<string, unknown> }>) {
  return { kind: 'variable', ref: { source: 'scope', path, type }, pipeline };
}

function arrayStep(operationId: string, args: Record<string, unknown>): VariablePipelineStep {
  return { stepId: operationId, operationId, args, outputType: 'multi' };
}

describe('array-transform ops over array<object>/array<file> (wave 3)', () => {
  beforeAll(() => setLocale('en'));
  const catalog = () => standardOperationsCatalog();
  const repeaterDesc = (): OperationTypeDescriptor => descriptorToOperation(REPEATER);

  it('descriptorToOperation carries the array + the element fields (repeater)', () => {
    const desc = repeaterDesc();
    expect(desc.array).toBe(true);
    expect(desc.base).toBe('object');
    expect(desc.fields?.map((f) => f.key)).toEqual(['price', 'name']);
    // The element descriptor is the non-array object, fields intact.
    const element = elementDescriptorOf(desc);
    expect(element.array).toBe(false);
    expect(element.base).toBe('object');
    expect(element.fields?.map((f) => f.key)).toEqual(['price', 'name']);
  });

  it('the array collapses to the `multi` gate (array ops are offered) while the element degrades to text', () => {
    expect(typeFromDescriptor(repeaterDesc())).toBe('multi'); // array<object> → multi gate
    expect(typeFromDescriptor(elementDescriptorOf(repeaterDesc()))).toBe('text'); // object degrades
  });

  it('elementScopeSubfieldVars exposes `element.<field>` for object/file, NONE for a scalar element', () => {
    const objectSubs = elementScopeSubfieldVars(elementDescriptorOf(repeaterDesc()));
    expect(objectSubs.map((v) => v.path)).toEqual(['element.price', 'element.name']);
    expect(objectSubs.every((v) => v.source === 'scope')).toBe(true);
    expect(objectSubs.map((v) => v.type)).toEqual(['number', 'text']);

    const fileSubs = elementScopeSubfieldVars(elementDescriptorOf(descriptorToOperation(FILE_ARRAY)));
    expect(fileSubs.map((v) => v.path)).toEqual([
      'element.id',
      'element.name',
      'element.type',
      'element.size',
      'element.url',
    ]);
    // `size` is a number subfield; the rest are text.
    expect(fileSubs.find((v) => v.path === 'element.size')?.type).toBe('number');

    // A scalar/enum element array has no subfields.
    const scalarElement: OperationTypeDescriptor = { base: 'number', array: false };
    expect(elementScopeSubfieldVars(scalarElement)).toEqual([]);
  });

  it('map to `element.<field>` types as array<fieldType>', () => {
    const toPrice = [arrayStep('array_map', { pipeline: union('element.price', 'number', []) })];
    const priceDesc = resolveDescriptor(catalog(), repeaterDesc(), toPrice);
    expect(priceDesc.array).toBe(true);
    expect(priceDesc.base).toBe('number');

    // map element.price → num_to_text ⇒ array<text>.
    const toText = [
      arrayStep('array_map', { pipeline: union('element.price', 'number', [{ op: 'num_to_text', args: {} }]) }),
    ];
    const textDesc = resolveDescriptor(catalog(), repeaterDesc(), toText);
    expect(textDesc.array).toBe(true);
    expect(textDesc.base).toBe('text');
  });

  it('filter over a repeater: the union must end boolean (a wrong / missing terminal is invalid)', () => {
    const boolean = [arrayStep('array_filter', { pipeline: union('element.price', 'number', [{ op: 'num_gt', args: { value: 18 } }]) })];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), boolean, [])).toBe(true);

    // identity (number) → not boolean.
    const identity = [arrayStep('array_filter', { pipeline: union('element.price', 'number', []) })];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), identity, [])).toBe(false);

    // A BARE list over an object element is unsatisfiable — no op consumes the whole object.
    const bare = [arrayStep('array_filter', { pipeline: [{ op: 'num_gt', args: { value: 1 } }] })];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), bare, [])).toBe(false);

    // A union rooted at an UNKNOWN scope subfield is rejected.
    const unknownRoot = [arrayStep('array_filter', { pipeline: union('element.bogus', 'number', [{ op: 'num_gt', args: { value: 1 } }]) })];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), unknownRoot, [])).toBe(false);
  });

  it('sort over a repeater: the union must end number (a text field alone is invalid)', () => {
    const numeric = [arrayStep('array_sort', { pipeline: union('element.price', 'number', []) })];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), numeric, [])).toBe(true);

    const textKey = [arrayStep('array_sort', { pipeline: union('element.name', 'text', []) })];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), textKey, [])).toBe(false);
  });

  it('reduce over a repeater: the reducer stays a bare pipeline gated on the seed type', () => {
    // number seed + a reducer that adds → valid (its ops may reference element.<field> scope vars).
    const right = [
      arrayStep('array_reduce', {
        seed: { type: 'number', value: 0 },
        reducer: [{ op: 'num_add', args: { value: { kind: 'variable', ref: { source: 'scope', path: 'element.price', type: 'number' } } } }],
      }),
    ];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), right, [])).toBe(true);
    // reduce yields the SEED base (a single number).
    expect(typeFromDescriptor(resolveDescriptor(catalog(), repeaterDesc(), right))).toBe('number');

    // number seed + a reducer ending TEXT → invalid.
    const wrong = [
      arrayStep('array_reduce', {
        seed: { type: 'number', value: 0 },
        reducer: [{ op: 'num_to_text', args: {} }],
      }),
    ];
    expect(pipelineSatisfiesFrom(catalog(), repeaterDesc(), wrong, [])).toBe(false);
  });
});
