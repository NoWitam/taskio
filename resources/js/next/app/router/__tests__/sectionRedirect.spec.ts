// sectionRedirect.spec.ts — legacy `?section=` deep links → child-route targets:
// known section mapping, full query preservation (minus `section` itself), the
// unknown/missing-section fallback, array query values, and 1:1 params pass-through.
import { describe, it, expect } from 'vitest';
import { sectionRedirect } from '../sectionRedirect';

const SECTIONS = ['overview', 'runs'] as const;

describe('sectionRedirect', () => {
  it('maps a known section and preserves every other query key', () => {
    const target = sectionRedirect(
      {
        params: { id: 'wf-1' },
        query: { section: 'runs', run_detail: 'X', state: 'failed', origin: 'manual' },
      },
      'next.workflows.detail.',
      SECTIONS,
      'overview',
    );
    expect(target.name).toBe('next.workflows.detail.runs');
    expect(target.query).toEqual({ run_detail: 'X', state: 'failed', origin: 'manual' });
  });

  it('falls back to the default section when none is present, leaving the query untouched', () => {
    const target = sectionRedirect(
      { params: { id: 'wf-1' }, query: { workflow: 'wf-2' } },
      'next.workflows.detail.',
      SECTIONS,
      'overview',
    );
    expect(target.name).toBe('next.workflows.detail.overview');
    expect(target.query).toEqual({ workflow: 'wf-2' });
  });

  it('falls back on an unknown section and still strips the key', () => {
    const target = sectionRedirect(
      { params: { id: 'b-1' }, query: { section: 'foo', bot: 'b-2' } },
      'next.bots.detail.',
      ['inbox', 'activity', 'config'],
      'inbox',
    );
    expect(target.name).toBe('next.bots.detail.inbox');
    expect(target.query).toEqual({ bot: 'b-2' });
  });

  it('reads the first entry of an array section value', () => {
    const target = sectionRedirect(
      { params: { id: 'wf-1' }, query: { section: ['runs', 'overview'] } },
      'next.workflows.detail.',
      SECTIONS,
      'overview',
    );
    expect(target.name).toBe('next.workflows.detail.runs');
    expect(target.query).toEqual({});
  });

  it('passes route params through 1:1', () => {
    const params = { id: 'wf-42' };
    const target = sectionRedirect(
      { params, query: {} },
      'next.workflows.detail.',
      SECTIONS,
      'overview',
    );
    expect(target.params).toBe(params);
  });
});
