// @vitest-environment happy-dom
// SelectLeadingIcon.spec.ts — the `leadingIcon` prop renders a FieldShell leading
// adornment (so every filter control can "name itself" like the search field) and
// trims the trigger's left padding so the icon + text don't double-inset.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Select from '../Select.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const OPTIONS = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
];

describe('Select leadingIcon', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders a leading adornment and trims the trigger padding when set', () => {
    const wrapper = mount(Select, {
      props: { options: OPTIONS, leadingIcon: 'flag', ariaLabel: 'Priority' },
    });
    expect(wrapper.find('.next-field-shell__adornment--leading').exists()).toBe(true);
    const trigger = wrapper.get('button[role="combobox"]');
    expect(trigger.classes()).toContain('pl-next-2');
  });

  it('renders no leading adornment and keeps full padding by default', () => {
    const wrapper = mount(Select, {
      props: { options: OPTIONS, ariaLabel: 'Priority' },
    });
    expect(wrapper.find('.next-field-shell__adornment--leading').exists()).toBe(false);
    const trigger = wrapper.get('button[role="combobox"]');
    expect(trigger.classes()).toContain('pl-next-3');
  });
});
