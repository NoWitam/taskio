// @vitest-environment happy-dom
// LabelSelectLocked.spec.ts — the `locked` prop (F3): a folder-enforced label renders a LOCK
// instead of the remove ✕, so it cannot be detached by hand and stays in the model. Manual
// labels keep their removable ✕.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

vi.mock('../../app/lib/api', () => ({
  api: { get: vi.fn().mockResolvedValue({ data: [], meta: { next_cursor: null } }), post: vi.fn() },
}));

import LabelSelect from '../LabelSelect.vue';

describe('LabelSelect locked', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('shows a lock (no remove control) for a locked label and a removable ✕ for a manual one', () => {
    const wrapper = mount(LabelSelect, {
      props: {
        modelValue: ['enforced', 'manual'],
        seed: [
          { id: 'enforced', name: 'Enforced' },
          { id: 'manual', name: 'Manual' },
        ],
        locked: ['enforced'],
      },
    });

    // The locked chip carries the "enforced by a folder" affordance…
    expect(wrapper.html()).toContain('Enforced by a folder');
    // …the ENFORCED label exposes NO remove control, while the MANUAL one does. (Select renders
    // each chip twice — once visible, once in a hidden overflow-measuring row — so assert by which
    // label the remove targets rather than a raw count.)
    expect(wrapper.findAll('button[aria-label="Remove Enforced"]')).toHaveLength(0);
    expect(wrapper.findAll('button[aria-label="Remove Manual"]').length).toBeGreaterThan(0);
  });
});
