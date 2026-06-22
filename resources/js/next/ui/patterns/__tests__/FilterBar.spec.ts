// @vitest-environment happy-dom
// FilterBar.spec.ts — the active-filter contract: multi-value groups render one
// removable chip PER value (showing the real label, never "N selected"), an
// operator note appears for ≥2-value groups, single-value filters stay
// back-compatible, and remove/clear-all emit the right keys. All chips are shown
// (the row wraps — there is no "+N" collapse).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import FilterBar, { type ActiveFilter } from '../FilterBar.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

function mountBar(activeFilters: ActiveFilter[]) {
  return mount(FilterBar, {
    props: {
      searchable: false,
      activeFilters,
      clearAllLabel: 'Clear all',
    },
  });
}

/** Removable chips carry an aria-label of "Remove filter: <label>". */
function chipRemovers(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('[aria-label^="Remove filter:"]');
}

describe('FilterBar active filters', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders one chip per value for a multi-value group', () => {
    const wrapper = mountBar([
      {
        key: 'labels',
        values: [
          { key: 'labels:1', label: 'Bug' },
          { key: 'labels:2', label: 'UI' },
        ],
      },
    ]);
    // One removable chip per flattened value — all shown (no +N collapse).
    expect(chipRemovers(wrapper)).toHaveLength(2);
    expect(wrapper.text()).toContain('Bug');
    expect(wrapper.text()).toContain('UI');
  });

  it('shows an operator note only when a group has ≥2 values', () => {
    const one = mountBar([
      { key: 'labels', values: [{ key: 'labels:1', label: 'Bug' }], operatorLabel: 'Labels: Any' },
    ]);
    expect(one.text()).not.toContain('Labels: Any');

    const many = mountBar([
      {
        key: 'labels',
        values: [
          { key: 'labels:1', label: 'Bug' },
          { key: 'labels:2', label: 'UI' },
        ],
        operatorLabel: 'Labels: Any',
      },
    ]);
    expect(many.text()).toContain('Labels: Any');
  });

  it('keeps single-value filters back-compatible (label only)', () => {
    const wrapper = mountBar([{ key: 'priority', label: 'Priority: High' }]);
    expect(chipRemovers(wrapper)).toHaveLength(1);
    expect(wrapper.text()).toContain('Priority: High');
  });

  it('emits remove-filter with the per-value key when a chip is removed', async () => {
    const wrapper = mountBar([
      {
        key: 'user_id',
        values: [
          { key: 'user_id:7', label: 'Alice' },
          { key: 'user_id:9', label: 'Bob' },
        ],
      },
    ]);
    // Visible chips carry the descriptive remove label; click Alice's ✕.
    await wrapper.get('[aria-label="Remove filter: Alice"]').trigger('click');
    expect(wrapper.emitted('remove-filter')?.[0]).toEqual(['user_id:7']);
  });

  it('provides its control size to slotted controls (search defaults to md)', () => {
    const md = mount(FilterBar, { props: { activeFilters: [] } });
    // The built-in search renders through a FieldShell; md ⇒ h-10.
    expect(md.find('.next-field-shell').classes()).toContain('h-10');

    const lg = mount(FilterBar, { props: { activeFilters: [], controlSize: 'lg' } });
    expect(lg.find('.next-field-shell').classes()).toContain('h-12');
  });

  it('shows Clear all only with >1 chip and emits clear-all', async () => {
    const single = mountBar([{ key: 'priority', label: 'High' }]);
    expect(single.text()).not.toContain('Clear all');

    const many = mountBar([
      { key: 'a', label: 'A' },
      { key: 'b', label: 'B' },
    ]);
    const clear = many.findAll('button').find((b) => b.text() === 'Clear all');
    expect(clear).toBeTruthy();
    await clear!.trigger('click');
    expect(many.emitted('clear-all')).toBeTruthy();
  });
});
