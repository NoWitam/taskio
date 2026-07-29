// @vitest-environment happy-dom
// BotAuthorChip.spec — the ADDITIVE "Authored by {bot}" chip on a delegated session (R2 sub-stage 3).
// Asserts it renders the localized "Authored by {name}" text (icon + text, never color-only) in both locales.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

import BotAuthorChip from '../session/BotAuthorChip.vue';

describe('BotAuthorChip', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the localized "Authored by {name}" label (en)', () => {
    const wrapper = mount(BotAuthorChip, { props: { author: { id: 'b1', name: 'Copy Bot', icon: null } } });
    expect(wrapper.text()).toBe('Authored by Copy Bot');
    // The glyph is decorative (icon + text, not color-only).
    expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('translates the label in pl', () => {
    setLocale('pl');
    const wrapper = mount(BotAuthorChip, { props: { author: { id: 'b1', name: 'Copy Bot' } } });
    expect(wrapper.text()).toBe('Autor: Copy Bot');
    wrapper.unmount();
  });
});
