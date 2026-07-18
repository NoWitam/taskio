// standardOperations.spec — integrity of the CANONICAL operations catalog. The ids
// are a stable wire vocabulary (the backend condition engine mirrors them 1:1), so
// this spec pins the invariants that keep the catalog trustworthy: unique ids, a
// closed type vocabulary, per-type coverage (every type can transform AND terminate
// in boolean), source-driven args only where a source carries options, and labels
// that actually resolved through i18n.
import { describe, it, expect, beforeAll } from 'vitest';
import { setLocale } from '../../../app/i18n';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import { resolveType } from '../extensions/operationHelpers';
import type { VariablePipelineStep, VariablePrimitive } from '../extensions/types';

const TYPES: VariablePrimitive[] = ['text', 'number', 'boolean', 'date', 'enum', 'multi', 'file'];
const ARG_TYPES = [
  'text', 'number', 'boolean', 'date', 'select',
  'sourceOption', 'sourceOptions', 'sourceMap', 'choiceRules', 'choiceFallback',
];

function step(operationId: string, outputType: VariablePrimitive): VariablePipelineStep {
  return { stepId: operationId, operationId, args: {}, outputType };
}

describe('standardOperationsCatalog', () => {
  beforeAll(() => setLocale('en'));

  const catalog = () => standardOperationsCatalog();

  it('has 72 ops with UNIQUE, stable ids', () => {
    const ids = catalog().map((op) => op.id);
    expect(ids.length).toBe(72);
    expect(new Set(ids).size).toBe(ids.length);
  });

  // FE↔BE guard: the EXACT set of 72 ids. The backend condition engine pins the SAME
  // set on its side (WorkflowConditionOperationCatalog) — a drift on either side fails
  // here first. Compared sorted so ordering never matters, only the membership.
  it('pins the EXACT 72-id set the backend mirrors', () => {
    const EXPECTED = [
      // text (17) — incl. the choice-producing match_to_choice
      'text_uppercase', 'text_lowercase', 'text_trim', 'text_substring', 'text_replace',
      'text_append', 'text_prepend', 'text_length', 'text_to_number', 'match_to_choice', 'text_equals',
      'text_not_equals', 'text_contains', 'text_starts_with', 'text_ends_with', 'text_is_empty', 'text_is_not_empty',
      // number (16)
      'num_add', 'num_subtract', 'num_multiply', 'num_divide', 'num_abs', 'num_round',
      'num_floor', 'num_ceil', 'num_to_text', 'num_eq', 'num_neq', 'num_gt', 'num_gte',
      'num_lt', 'num_lte', 'num_between',
      // boolean (3)
      'bool_not', 'bool_to_number', 'bool_to_text',
      // date (18)
      'date_add_days', 'date_subtract_days', 'date_add_months', 'date_add_years',
      'date_start_of_month', 'date_end_of_month', 'date_day', 'date_month', 'date_year',
      'date_weekday', 'date_to_text', 'date_before', 'date_after', 'date_on', 'date_between',
      'date_is_weekend', 'date_is_past', 'date_is_future',
      // enum (7) — incl. the choice-producing enum_to_choice
      'enum_is', 'enum_is_not', 'enum_in', 'enum_to_text', 'enum_to_number', 'enum_to_date', 'enum_to_choice',
      // multi (7)
      'multi_includes', 'multi_excludes', 'multi_includes_any', 'multi_includes_all',
      'multi_count', 'multi_is_empty', 'multi_to_text',
      // file (4) — two boolean terminals + two converters into the text/number vocab
      'file_is_empty', 'file_is_not_empty', 'file_count', 'file_name',
    ];
    expect(EXPECTED.length).toBe(72);
    const ids = catalog().map((op) => op.id);
    expect([...ids].sort()).toEqual([...EXPECTED].sort());
  });

  it('speaks only the closed type vocabulary (inputs, outputs, args)', () => {
    for (const op of catalog()) {
      op.inputTypes.forEach((t) => expect(TYPES).toContain(t));
      expect(TYPES).toContain(op.outputType);
      (op.args ?? []).forEach((a) => expect(ARG_TYPES).toContain(a.type));
    }
  });

  it('every type has ops AND at least one boolean-terminating op (any variable can become a condition)', () => {
    for (const type of TYPES) {
      const ops = catalog().filter((op) => op.inputTypes.includes(type));
      expect(ops.length, `ops for ${type}`).toBeGreaterThan(0);
      expect(
        ops.some((op) => op.outputType === 'boolean'),
        `boolean-terminating op for ${type}`,
      ).toBe(true);
    }
  });

  it('source-driven args appear ONLY on enum/multi input ops (their options come from the source)', () => {
    for (const op of catalog()) {
      const usesSourceArgs = (op.args ?? []).some(
        (a) => a.type === 'sourceOption' || a.type === 'sourceOptions' || a.type === 'sourceMap',
      );
      if (usesSourceArgs) {
        expect(
          op.inputTypes.every((t) => t === 'enum' || t === 'multi'),
          `${op.id} carries source args but inputs ${op.inputTypes.join(',')}`,
        ).toBe(true);
      }
    }
  });

  it('bool conversions take when_true/when_false; enum conversions take a typed per-option mapping', () => {
    const argIds = (id: string) => (catalog().find((o) => o.id === id)?.args ?? []).map((a) => a.id);
    expect(argIds('bool_to_number')).toEqual(['when_true', 'when_false']);
    expect(argIds('bool_to_text')).toEqual(['when_true', 'when_false']);

    const mapTypeOf = (id: string) => {
      const arg = (catalog().find((o) => o.id === id)?.args ?? [])[0];
      expect(arg?.type, id).toBe('sourceMap');
      return arg?.mapType;
    };
    expect(mapTypeOf('enum_to_text')).toBe('text');
    expect(mapTypeOf('enum_to_number')).toBe('number');
    expect(mapTypeOf('enum_to_date')).toBe('date');
  });

  it('labels + arg labels resolve through i18n (never a raw key)', () => {
    for (const op of catalog()) {
      expect(op.label.startsWith('editor.'), op.id).toBe(false);
      (op.args ?? []).forEach((a) => expect(a.label.startsWith('editor.'), `${op.id}.${a.id}`).toBe(false));
    }
  });

  it('cross-type chains type-flow correctly (enum → date → date → boolean)', () => {
    const pipeline = [
      step('enum_to_date', 'date'),
      step('date_add_days', 'date'),
      step('date_is_future', 'boolean'),
    ];
    expect(resolveType(catalog(), 'enum', pipeline)).toBe('boolean');
  });

  it('conversions close the loop between every convertible pair', () => {
    // text→number, number→text, bool→number/text, date→text, enum→text/number/date,
    // multi→text/number — each conversion op exists and lands on its declared type.
    const expectOut = (id: string, out: VariablePrimitive) => {
      const op = catalog().find((o) => o.id === id);
      expect(op, id).toBeTruthy();
      expect(op!.outputType, id).toBe(out);
    };
    expectOut('text_to_number', 'number');
    expectOut('num_to_text', 'text');
    expectOut('bool_to_number', 'number');
    expectOut('bool_to_text', 'text');
    expectOut('date_to_text', 'text');
    expectOut('enum_to_text', 'text');
    expectOut('enum_to_number', 'number');
    expectOut('enum_to_date', 'date');
    expectOut('multi_to_text', 'text');
    expectOut('multi_count', 'number');
  });
});
