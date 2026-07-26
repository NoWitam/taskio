// Unit tests for the PURE custom-function helpers (pages/variables/functions.ts): the arg
// validation that mirrors the backend (required / safe-identifier / reserved / distinct), the
// {input, <argName>…} SCOPE-VAR feed the body pipeline editor runs on (all `source:'scope'`,
// NEVER globals), the arg draft round-trip, and the `fn:<uuid>` op-id helper.
import { describe, expect, it } from 'vitest';
import {
  FUNCTION_OP_PREFIX,
  argToDraft,
  draftToArg,
  emptyArgDraft,
  functionOpId,
  functionScopeVars,
  isSafeArgName,
  validateArgDrafts,
  type FunctionArgDraft,
} from '../functions';

function arg(name: string, type: FunctionArgDraft['type'] = 'text', description = ''): FunctionArgDraft {
  return { id: `a-${name}-${Math.random()}`, name, description, type };
}

describe('validateArgDrafts (mirrors the backend arg rules)', () => {
  it('accepts distinct safe identifiers', () => {
    expect(validateArgDrafts([arg('factor'), arg('label')])).toEqual({});
  });

  it('flags a required (empty) name', () => {
    expect(validateArgDrafts([arg('')])).toEqual({ 0: 'nameRequired' });
  });

  it('flags an invalid identifier (leading digit / punctuation)', () => {
    expect(validateArgDrafts([arg('1bad')])[0]).toBe('nameInvalid');
    expect(validateArgDrafts([arg('has space')])[0]).toBe('nameInvalid');
  });

  it('rejects the reserved scope names input / element / index', () => {
    expect(validateArgDrafts([arg('input')])[0]).toBe('nameReserved');
    expect(validateArgDrafts([arg('element')])[0]).toBe('nameReserved');
    expect(validateArgDrafts([arg('index')])[0]).toBe('nameReserved');
  });

  it('flags a duplicate name on the SECOND occurrence', () => {
    expect(validateArgDrafts([arg('factor'), arg('factor')])).toEqual({ 1: 'nameDuplicate' });
  });
});

describe('isSafeArgName', () => {
  it('mirrors the backend SAFE_NAME regex', () => {
    expect(isSafeArgName('factor')).toBe(true);
    expect(isSafeArgName('_x1')).toBe(true);
    expect(isSafeArgName('1x')).toBe(false);
    expect(isSafeArgName('a-b')).toBe(false);
    expect(isSafeArgName('')).toBe(false);
  });
});

describe('functionScopeVars — the body editor scope feed', () => {
  it('exposes the input + each named arg as source:scope vars (NEVER globals)', () => {
    const vars = functionScopeVars(
      'number',
      [arg('factor', 'number'), arg('label', 'text')],
      'Input',
    );

    // input leads, then the args, in order.
    expect(vars.map((v) => v.path)).toEqual(['input', 'factor', 'label']);
    expect(vars[0]).toMatchObject({ source: 'scope', path: 'input', name: 'Input', type: 'number' });
    expect(vars[1]).toMatchObject({ source: 'scope', path: 'factor', type: 'number' });
    expect(vars[2]).toMatchObject({ source: 'scope', path: 'label', type: 'text' });
    // EVERY var is scope-sourced — none is a global.
    expect(vars.every((v) => v.source === 'scope')).toBe(true);
    expect(vars.some((v) => v.source === 'globals')).toBe(false);
  });

  it('skips a half-typed (empty-name) arg row', () => {
    const vars = functionScopeVars('text', [arg(''), arg('ok')], 'Input');
    expect(vars.map((v) => v.path)).toEqual(['input', 'ok']);
  });
});

describe('arg draft round-trip', () => {
  it('argToDraft ← → draftToArg preserves name/type + trims/omits an empty description', () => {
    expect(draftToArg({ id: 'x', name: '  factor ', description: '  ', type: 'number' })).toEqual({
      name: 'factor',
      type: 'number',
    });
    expect(draftToArg({ id: 'x', name: 'factor', description: ' doubles ', type: 'number' })).toEqual({
      name: 'factor',
      type: 'number',
      description: 'doubles',
    });
    const draft = argToDraft({ name: 'factor', description: 'doubles', type: 'number' });
    expect(draft).toMatchObject({ name: 'factor', description: 'doubles', type: 'number' });
    expect(draft.id).toBeTruthy();
  });

  it('emptyArgDraft mints a unique id + default text type', () => {
    const a = emptyArgDraft();
    const b = emptyArgDraft();
    expect(a.id).not.toBe(b.id);
    expect(a.type).toBe('text');
  });
});

describe('functionOpId', () => {
  it('prefixes the uuid with the reserved fn: prefix', () => {
    expect(FUNCTION_OP_PREFIX).toBe('fn:');
    expect(functionOpId('abc-123')).toBe('fn:abc-123');
  });
});
