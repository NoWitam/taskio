// Unit tests for the run-now 422-bag mapper (§6.3) + the run formatting helpers.
//
// 5.1: the run 422 bag has EXACTLY two keys. The mapper is purely KEY-based (never
// message prose — the backend messages are Polish and free to change): `target_id`
// → targetNotFound, `workflow` → capReached. The REV 1 `approval_process` /
// `noConcludedApproval` arm is DELETED (approval_finished triggers are gone). Both
// remaining keys are inspected; "targetRequired" is client-side only (an empty
// required target never submits). The REAL Polish backend messages appear below to
// prove the mapping is language-independent.
import { describe, expect, it } from 'vitest';
import { mapRunNowError, extractErrorBag } from '../runNowErrors';
import { formatDuration, formatTimestamp, truncateError } from '../runFormat';

describe('mapRunNowError (§6.3 key-based bag — two keys only)', () => {
  it('target_id → targetNotFound regardless of the (Polish) server message', () => {
    const r = mapRunNowError({
      target_id: ['Wskazane zgłoszenie nie istnieje w tej przestrzeni roboczej.'],
    });
    expect(r).toEqual({ key: 'workflows.run.errors.targetNotFound', field: 'target_id' });
  });

  it('target_id with an empty/absent message still maps by key alone', () => {
    expect(mapRunNowError({ target_id: [] }).key).toBe('workflows.run.errors.targetNotFound');
    expect(mapRunNowError({ target_id: '' }).key).toBe('workflows.run.errors.targetNotFound');
  });

  it('workflow key → capReached regardless of the (Polish) server message', () => {
    const r = mapRunNowError({
      workflow: ['Ten workflow osiągnął limit uruchomień na ten miesiąc. Uruchomienie zostało zablokowane.'],
    });
    expect(r).toEqual({ key: 'workflows.run.errors.capReached', field: 'workflow' });
  });

  it('the deleted approval_process key is NOT recognized → generic fallback', () => {
    // approval_finished triggers are gone, so the backend never emits this key; if a
    // stale one somehow arrived, it must NOT resurrect the removed noConcludedApproval.
    expect(mapRunNowError({ approval_process: ['stale'] }).key).toBe('workflows.run.errors.generic');
  });

  it('precedence: target_id beats workflow when both are present', () => {
    expect(
      mapRunNowError({ target_id: ['y'], workflow: ['z'] }).key,
    ).toBe('workflows.run.errors.targetNotFound');
  });

  it('an unrecognized / empty bag → the generic fallback', () => {
    expect(mapRunNowError({ something_else: ['nope'] }).key).toBe('workflows.run.errors.generic');
    expect(mapRunNowError({}).key).toBe('workflows.run.errors.generic');
    expect(mapRunNowError(null).key).toBe('workflows.run.errors.generic');
    expect(mapRunNowError(undefined).key).toBe('workflows.run.errors.generic');
  });
});

describe('extractErrorBag', () => {
  it('pulls errors out of a 422 response', () => {
    const bag = extractErrorBag({ response: { status: 422, data: { errors: { target_id: ['x'] } } } });
    expect(bag).toEqual({ target_id: ['x'] });
  });

  it('returns null for a non-422 / shape mismatch', () => {
    expect(extractErrorBag({ response: { status: 500, data: {} } })).toBeNull();
    expect(extractErrorBag({ response: { status: 422, data: {} } })).toBeNull();
    expect(extractErrorBag(new Error('network'))).toBeNull();
    expect(extractErrorBag(null)).toBeNull();
  });

  it('end-to-end: a 422 cap-reached error maps to capReached', () => {
    const err = { response: { status: 422, data: { errors: { workflow: ['cap reached'] } } } };
    const bag = extractErrorBag(err);
    expect(mapRunNowError(bag).key).toBe('workflows.run.errors.capReached');
  });
});

describe('formatDuration', () => {
  it('returns null for an unfinished run (null/undefined/NaN)', () => {
    expect(formatDuration(null)).toBeNull();
    expect(formatDuration(undefined)).toBeNull();
    expect(formatDuration(Number.NaN)).toBeNull();
  });

  it('formats sub-minute as seconds', () => {
    expect(formatDuration(0)).toBe('0s');
    expect(formatDuration(45)).toBe('45s');
    expect(formatDuration(59)).toBe('59s');
  });

  it('formats minutes + seconds under an hour', () => {
    expect(formatDuration(60)).toBe('1m 0s');
    expect(formatDuration(90)).toBe('1m 30s');
    expect(formatDuration(3599)).toBe('59m 59s');
  });

  it('formats hours + minutes past an hour (seconds dropped)', () => {
    expect(formatDuration(3600)).toBe('1h 0m');
    expect(formatDuration(3661)).toBe('1h 1m');
    expect(formatDuration(7325)).toBe('2h 2m');
  });

  it('clamps negatives to zero', () => {
    expect(formatDuration(-5)).toBe('0s');
  });
});

describe('formatTimestamp', () => {
  it('returns "" for a null/empty value', () => {
    expect(formatTimestamp(null)).toBe('');
    expect(formatTimestamp(undefined)).toBe('');
    expect(formatTimestamp('')).toBe('');
  });

  it('returns the raw string when unparseable', () => {
    expect(formatTimestamp('not-a-date')).toBe('not-a-date');
  });

  it('formats a valid ISO timestamp to a non-empty locale string', () => {
    expect(formatTimestamp('2026-01-01T00:00:00Z').length).toBeGreaterThan(0);
  });
});

describe('truncateError', () => {
  it('returns "" for null/empty', () => {
    expect(truncateError(null)).toBe('');
    expect(truncateError('')).toBe('');
  });

  it('collapses whitespace/newlines to a single line', () => {
    expect(truncateError('line one\n  line two')).toBe('line one line two');
  });

  it('clamps to max chars with an ellipsis', () => {
    const long = 'x'.repeat(200);
    const out = truncateError(long, 50);
    expect(out.length).toBe(50);
    expect(out.endsWith('…')).toBe(true);
  });
});
