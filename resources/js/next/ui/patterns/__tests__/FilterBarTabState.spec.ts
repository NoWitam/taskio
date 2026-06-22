// @vitest-environment happy-dom
// FilterBarTabState.spec.ts — the additive Saved-Views (Stage 2) chip decoration
// contract on the FilterBar:
//   • tab-active  → renders like the baseline (neutral, removable),
//   • extra       → added on top of the view (primary tint, `plus` icon, removable),
//   • tab-disabled→ removed from the current state: NOT removable, struck-through +
//                   dashed (a non-color affordance) + a "restore" trailing action,
//                   and clicking it emits `restore-filter` with the chip key.
//   • back-compat → chips WITHOUT any tabState render exactly as before
//                   (neutral/subtle removable; remove-filter still fires).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import FilterBar, { type ActiveFilter } from '../FilterBar.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

function mountBar(activeFilters: ActiveFilter[]) {
  return mount(FilterBar, {
    props: { searchable: false, activeFilters },
  });
}

describe('FilterBar saved-view tab states', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en'); // deterministic chip aria-labels
  });
  afterEach(() => restoreBrowserMocks());

  it('renders the three variants with distinct, non-color affordances', () => {
    const wrapper = mountBar([
      { key: 'a', label: 'Active one', tabState: 'tab-active' },
      { key: 'b', label: 'Extra one', tabState: 'extra' },
      { key: 'c', label: 'Disabled one', tabState: 'tab-disabled' },
    ]);

    const badges = wrapper.findAll('.next-badge');
    expect(badges).toHaveLength(3);

    // tab-active → baseline removable chip (a ✕ remover).
    expect(wrapper.find('[aria-label="Remove filter: Active one"]').exists()).toBe(true);

    // extra → removable AND carries the `plus` leading icon (non-color signal).
    const extra = badges.find((b) => b.text().includes('Extra one'))!;
    expect(extra.find('[aria-label="Remove filter: Extra one"]').exists()).toBe(true);
    expect(extra.classes().join(' ')).toContain('ring-1');

    // tab-disabled → NOT removable; struck-through + dashed (pattern, not color)
    // + a "restore" trailing affordance.
    const disabled = badges.find((b) => b.text().includes('Disabled one'))!;
    expect(disabled.find('[aria-label="Remove filter: Disabled one"]').exists()).toBe(false);
    expect(disabled.classes().join(' ')).toContain('line-through');
    expect(disabled.classes().join(' ')).toContain('border-dashed');
    expect(wrapper.find('[aria-label="Restore filter: Disabled one"]').exists()).toBe(true);
  });

  it('emits restore-filter (and NOT remove-filter) when the restore affordance is clicked', async () => {
    const wrapper = mountBar([{ key: 'c', label: 'Gone', tabState: 'tab-disabled' }]);

    await wrapper.get('[aria-label="Restore filter: Gone"]').trigger('click');

    expect(wrapper.emitted('restore-filter')?.[0]).toEqual(['c']);
    expect(wrapper.emitted('remove-filter')).toBeFalsy();
  });

  it('propagates a per-VALUE tabState inside a multi-value group', () => {
    const wrapper = mountBar([
      {
        key: 'labels',
        values: [
          { key: 'labels:1', label: 'Keep', tabState: 'tab-active' },
          { key: 'labels:2', label: 'Removed', tabState: 'tab-disabled' },
        ],
      },
    ]);

    // The removed value gets the restore affordance; the kept one stays removable.
    expect(wrapper.find('[aria-label="Restore filter: Removed"]').exists()).toBe(true);
    expect(wrapper.find('[aria-label="Remove filter: Keep"]').exists()).toBe(true);
    expect(wrapper.find('[aria-label="Remove filter: Removed"]').exists()).toBe(false);
  });

  // --- Back-compat regression: NO tabState ⇒ identical to the legacy chip. -----
  it('renders chips WITHOUT tabState exactly like before (neutral removable, remove-filter fires)', async () => {
    const wrapper = mountBar([
      { key: 'priority', label: 'Priority: High' },
      { key: 'status', label: 'Status: Open' },
    ]);

    const badges = wrapper.findAll('.next-badge');
    expect(badges).toHaveLength(2);
    // No restore affordance, no struck/dashed styling anywhere.
    expect(wrapper.find('[aria-label^="Restore filter:"]').exists()).toBe(false);
    for (const b of badges) {
      const cls = b.classes().join(' ');
      expect(cls).not.toContain('line-through');
      expect(cls).not.toContain('border-dashed');
      expect(cls).not.toContain('ring-1');
    }

    // remove still works and emits the right key.
    await wrapper.get('[aria-label="Remove filter: Priority: High"]').trigger('click');
    expect(wrapper.emitted('remove-filter')?.[0]).toEqual(['priority']);
  });
});
