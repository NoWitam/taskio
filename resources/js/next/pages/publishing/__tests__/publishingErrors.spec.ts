// publishingErrors.spec — the refusal readers.
//
// The rule these encode: `message` arrives ALREADY TRANSLATED from the server, and the
// frontend branches on `code` only to choose a VESSEL. So these readers must recognise a
// refusal STRUCTURALLY — 422 plus a known code — and must never inspect the sentence. A
// reader that matched on prose would break the first time somebody switched language.
import { describe, it, expect } from 'vitest';
import {
  fieldErrorsOf,
  isLostRace,
  isUnderReview,
  prohibitedFieldsIn,
  serverMessageOf,
  statusOf,
  throttleSecondsOf,
  transitionRefusalOf,
} from '../publishingErrors';

function axiosError(status: number, data: unknown, headers: Record<string, unknown> = {}): unknown {
  return { response: { status, data, headers } };
}

describe('transitionRefusalOf', () => {
  it('recognises each of the five transition codes', () => {
    for (const code of [
      'publication_reconcile_before_retry',
      'publication_blocked_holds',
      'publication_terminal',
      'publication_transition_not_allowed',
      'publication_transition_lost_race',
    ]) {
      const refusal = transitionRefusalOf(
        axiosError(422, { code, message: 'Server prose.', context: { from: 'draft', to: 'scheduled' } }),
      );
      expect(refusal?.code, code).toBe(code);
    }
  });

  it('recognises the B6 review hold, which travels in the same envelope', () => {
    const refusal = transitionRefusalOf(
      axiosError(422, {
        code: 'publication_under_review',
        message: 'This publication is with an approver…',
        context: { status: 'draft' },
      }),
    );
    expect(isUnderReview(refusal)).toBe(true);
  });

  it('hands back the server’s sentence UNCHANGED', () => {
    const message = 'Coś zajęło się już tą publikacją — jest teraz w stanie „publishing".';
    const refusal = transitionRefusalOf(
      axiosError(422, { code: 'publication_transition_lost_race', message, context: { from: 'publishing' } }),
    );
    expect(refusal?.message).toBe(message);
    expect(refusal?.context).toEqual({ from: 'publishing' });
  });

  it('is null for anything that is not one of these refusals', () => {
    expect(transitionRefusalOf(axiosError(422, { message: 'The given data was invalid.' }))).toBeNull();
    expect(transitionRefusalOf(axiosError(403, { code: 'publication_terminal' }))).toBeNull();
    expect(transitionRefusalOf(axiosError(422, { code: 'something_else' }))).toBeNull();
    expect(transitionRefusalOf(new Error('network'))).toBeNull();
    expect(transitionRefusalOf(null)).toBeNull();
  });
});

describe('isLostRace', () => {
  it('is true only for the compare-and-swap miss', () => {
    expect(isLostRace({ code: 'publication_transition_lost_race', message: '', context: {} })).toBe(true);
    expect(isLostRace({ code: 'publication_terminal', message: '', context: {} })).toBe(false);
    expect(isLostRace(null)).toBe(false);
  });
});

describe('throttleSecondsOf', () => {
  it('reads `Retry-After`', () => {
    expect(throttleSecondsOf(axiosError(429, {}, { 'retry-after': '42' }))).toBe(42);
    expect(throttleSecondsOf(axiosError(429, {}, { 'Retry-After': 7 }))).toBe(7);
  });

  it('falls back to a minute when the header is absent or nonsense', () => {
    expect(throttleSecondsOf(axiosError(429, {}, {}))).toBe(60);
    expect(throttleSecondsOf(axiosError(429, {}, { 'retry-after': 'soon' }))).toBe(60);
    expect(throttleSecondsOf(axiosError(429, {}, { 'retry-after': '-5' }))).toBe(60);
  });

  it('rounds a fractional header UP, so the button never returns early', () => {
    expect(throttleSecondsOf(axiosError(429, {}, { 'retry-after': '1.2' }))).toBe(2);
  });

  it('ignores a header on a response that is not a throttle', () => {
    expect(throttleSecondsOf(axiosError(500, {}, { 'retry-after': '42' }))).toBe(60);
  });
});

describe('fieldErrorsOf', () => {
  it('flattens Laravel’s per-field arrays to the first message', () => {
    const errors = fieldErrorsOf(
      axiosError(422, {
        message: 'The given data was invalid.',
        errors: {
          scheduled_at: ['That moment has already passed.', 'second message'],
          title: ['The title field is required.'],
        },
      }),
    );
    expect(errors).toEqual({
      scheduled_at: 'That moment has already passed.',
      title: 'The title field is required.',
    });
  });

  it('is empty for anything that is not a validation failure', () => {
    expect(fieldErrorsOf(axiosError(403, {}))).toEqual({});
    expect(fieldErrorsOf(axiosError(422, { code: 'publication_terminal' }))).toEqual({});
    expect(fieldErrorsOf(undefined)).toEqual({});
  });
});

describe('prohibitedFieldsIn', () => {
  it('names a CLIENT defect rather than a user error', () => {
    // A 422 on one of these means the frontend sent a column it must never send. There is no
    // field on the form to attach the message to, so the caller has to be told it is ours.
    expect(prohibitedFieldsIn({ status: 'not accepted', title: 'required' })).toEqual(['status']);
    expect(prohibitedFieldsIn({ remote_id: 'x', attempts: 'y' })).toEqual(['remote_id', 'attempts']);
    expect(prohibitedFieldsIn({ title: 'required' })).toEqual([]);
  });
});

describe('statusOf / serverMessageOf', () => {
  it('reads what is there and nothing more', () => {
    expect(statusOf(axiosError(404, {}))).toBe(404);
    expect(statusOf(new Error('offline'))).toBeNull();
    expect(serverMessageOf(axiosError(400, { message: 'No active workspace.' }))).toBe(
      'No active workspace.',
    );
    expect(serverMessageOf(axiosError(500, {}))).toBeNull();
    expect(serverMessageOf(axiosError(500, { message: '' }))).toBeNull();
  });
});
