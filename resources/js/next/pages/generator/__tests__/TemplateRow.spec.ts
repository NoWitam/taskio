// @vitest-environment happy-dom
// TemplateRow.spec — one row of the templates management list. Asserts the always-available
// "Start session" action renders and EMITS `create-session` with the row's template (the direct
// jump from the list into a new session), alongside the capability-gated Edit / Delete emits.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TemplateRow from '../TemplateRow.vue';
import type { Template } from '../types';

const template = {
  id: 't1',
  name: 'Launch post',
  content_type: 'post',
  description: null,
  slots: [],
  content: {},
  creator: null,
  is_owner: true,
  can_be_edited: true,
  can_be_deleted: true,
  created_at: null,
  updated_at: null,
} as unknown as Template;

function mountRow(overrides: Partial<Template> = {}) {
  return mount(TemplateRow, {
    props: { template: { ...template, ...overrides } as Template },
    attachTo: document.body,
  });
}

describe('TemplateRow', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('emits create-session with the template when Start session is clicked', async () => {
    const wrapper = mountRow();

    const startButton = wrapper.find('button[aria-label="Start session"]');
    expect(startButton.exists()).toBe(true);
    await startButton.trigger('click');

    const events = wrapper.emitted('create-session');
    expect(events).toHaveLength(1);
    expect(events![0][0]).toMatchObject({ id: 't1' });

    wrapper.unmount();
  });

  it('keeps Start session enabled even when the template cannot be edited', () => {
    const wrapper = mountRow({ can_be_edited: false });

    const startButton = wrapper.find('button[aria-label="Start session"]');
    expect(startButton.exists()).toBe(true);
    expect(startButton.attributes('disabled')).toBeUndefined();

    wrapper.unmount();
  });
});
