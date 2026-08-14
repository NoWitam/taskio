// @vitest-environment happy-dom
// TruncationNotices.spec — three kinds × {count known, count unknown} = six sentences.
//
// The headline assertion is the negative one: NOWHERE in any of the six may the digit 0
// appear. `omitted_occurrences` / `affected_items` are `int | null`, and null means
// genuinely unknown — the backend merges counts with a poisoning rule precisely so it
// never reports a known part as a whole. `count ?? 0` would turn "we do not know" into
// "nothing is missing", which is data-shaped and false, and is the single easiest mistake
// to make in this component.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import TruncationNotices from '../TruncationNotices.vue';
import { setLocale } from '../../../app/i18n';
import type { CalendarTruncation } from '../types';

const KINDS = ['window_trimmed', 'items_dropped', 'item_densified'] as const;

function mountNotices(truncations: CalendarTruncation[]) {
  return mount(TruncationNotices, {
    props: {
      truncations,
      // Server prose — the component must render it, never translate it.
      sourceLabelOf: (id: string) => `Label:${id}`,
    },
  });
}

function row(kind: (typeof KINDS)[number], count: number | null): CalendarTruncation {
  return {
    source: 'workflow_schedule',
    kind,
    omitted_occurrences: kind === 'window_trimmed' ? count : null,
    affected_items: kind === 'window_trimmed' ? null : count,
  };
}

describe('TruncationNotices', () => {
  beforeEach(() => setLocale('en'));
  afterEach(() => {
    setLocale('en');
    document.body.innerHTML = '';
  });

  it('renders nothing at all when there is no loss to report', () => {
    const wrapper = mountNotices([]);
    expect(wrapper.text()).toBe('');
    wrapper.unmount();
  });

  it.each(KINDS)('words %s differently with a count and without one', (kind) => {
    const withCount = mountNotices([row(kind, 4)]);
    const unknown = mountNotices([row(kind, null)]);

    const a = withCount.text();
    const b = unknown.text();

    expect(a).toContain('4');
    expect(a).not.toBe(b);
    // The unknown variant is a full sentence in its own right, not the other one with a
    // hole where the number was.
    expect(b.length).toBeGreaterThan(40);

    withCount.unmount();
    unknown.unmount();
  });

  it.each(KINDS)('NEVER prints a zero for an unknown count (%s)', (kind) => {
    for (const locale of ['en', 'pl'] as const) {
      setLocale(locale);
      const wrapper = mountNotices([row(kind, null)]);
      expect(wrapper.text()).not.toMatch(/\b0\b/);
      // Nor an empty pair of brackets / a dangling colon where a number was meant to be.
      expect(wrapper.text()).not.toContain('()');
      wrapper.unmount();
    }
  });

  it('renders ONE row per (source, kind) and names each source with SERVER prose', () => {
    const wrapper = mountNotices([
      { source: 'workflow_schedule', kind: 'item_densified', omitted_occurrences: null, affected_items: 2 },
      { source: 'task', kind: 'window_trimmed', omitted_occurrences: 9, affected_items: null },
    ]);
    expect(wrapper.findAll('li')).toHaveLength(2);
    expect(wrapper.text()).toContain('Label:workflow_schedule');
    expect(wrapper.text()).toContain('Label:task');
    wrapper.unmount();
  });

  it('says the thing about the CLIFF for a densified series — the empty days are not idle', () => {
    // The budget fills forwards from an anchor, so a minute-cadence automation draws a wall
    // in one day and nothing afterwards. Without this sentence the emptiness reads as "the
    // automation stopped".
    const wrapper = mountNotices([row('item_densified', 3)]);
    expect(wrapper.text()).toMatch(/empty days/i);
    wrapper.unmount();
  });

  it('says items_dropped means ABSENT, not shortened', () => {
    const wrapper = mountNotices([row('items_dropped', null)]);
    expect(wrapper.text()).toMatch(/ENTIRELY|absent/i);
    wrapper.unmount();
  });

  it('offers "search by name" for dropped items and "narrow the filters" otherwise', async () => {
    const dropped = mountNotices([row('items_dropped', null)]);
    await dropped.find('button').trigger('click');
    expect(dropped.emitted('search-by-name')).toBeTruthy();
    expect(dropped.emitted('narrow-filters')).toBeFalsy();
    dropped.unmount();

    const trimmed = mountNotices([row('window_trimmed', 3)]);
    await trimmed.find('button').trigger('click');
    expect(trimmed.emitted('narrow-filters')).toBeTruthy();
    trimmed.unmount();
  });

  it('drops a row of an UNKNOWN kind rather than wording it wrongly', () => {
    // A future `kind` must degrade to silence here, not to a sentence about the wrong loss.
    const wrapper = mountNotices([
      { source: 'task', kind: 'something_new' as CalendarTruncation['kind'], omitted_occurrences: 3, affected_items: null },
    ]);
    expect(wrapper.findAll('li')).toHaveLength(0);
    wrapper.unmount();
  });

  it('is announced: a loss report is an alert, not decoration', () => {
    const wrapper = mountNotices([row('window_trimmed', 2)]);
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    wrapper.unmount();
  });
});
