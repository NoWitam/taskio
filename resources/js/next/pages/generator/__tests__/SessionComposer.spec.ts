// @vitest-environment happy-dom
// SessionComposer.spec — the bottom refinement bar, LIVE in R2 sub-stage 2d. Asserts it TARGETS a produced
// part (defaulting to the first/primary), emits `send({partKey, instruction})`, disables with a hint when
// there is nothing to refine yet, and disables SEND (while keeping the draft) while the session is generating.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

import SessionComposer from '../session/SessionComposer.vue';

function mountComposer(props: Record<string, unknown> = {}) {
  return mount(SessionComposer, { props, attachTo: document.body });
}

describe('SessionComposer (2d, live)', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('with no targets: the field is disabled and a hint explains why', () => {
    const wrapper = mountComposer({ targets: [] });
    expect(wrapper.find('textarea').attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('Generate a post first');
    wrapper.unmount();
  });

  it('shows the single target label (no selector) when there is exactly one', () => {
    const wrapper = mountComposer({ targets: [{ key: 'body', label: 'Post body' }] });
    expect(wrapper.text()).toContain('Refines:');
    expect(wrapper.text()).toContain('Post body');
    expect(wrapper.find('[role="combobox"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('defaults to the FIRST (primary) target and emits send({partKey, instruction})', async () => {
    const wrapper = mountComposer({
      targets: [
        { key: 'body', label: 'Post body' },
        { key: 'image', label: 'Image' },
      ],
    });
    // With more than one target a selector renders, and the primary (first) is preselected.
    expect(wrapper.find('[role="combobox"]').exists()).toBe(true);

    await wrapper.find('textarea').setValue('make it shorter');
    await wrapper.find('button[aria-label="Send"]').trigger('click');

    expect(wrapper.emitted('send')?.[0]).toEqual([{ partKey: 'body', instruction: 'make it shorter' }]);
    wrapper.unmount();
  });

  it('does not send a blank instruction', async () => {
    const wrapper = mountComposer({ targets: [{ key: 'body', label: 'Post body' }] });
    await wrapper.find('textarea').setValue('   ');
    // Send is disabled for a whitespace-only draft.
    expect(wrapper.find('button[aria-label="Send"]').attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('disables SEND while generating but preserves the draft (queued)', async () => {
    const wrapper = mountComposer({ targets: [{ key: 'body', label: 'Post body' }], disabled: true });
    await wrapper.find('textarea').setValue('later please');

    expect(wrapper.find('button[aria-label="Send"]').attributes('disabled')).toBeDefined();
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('later please');
    expect(wrapper.text()).toContain('once the current run finishes');
    wrapper.unmount();
  });
});
