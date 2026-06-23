// @vitest-environment happy-dom
// FormSelect.spec.ts — the global form picker. Asserts (1) the seed renders the
// attached form's name immediately (before any async page), and (2) opening the
// dropdown invokes the injected loader and maps `{ id, name, icon }` → options
// with a resolved leading IconName. The async loader is injected via the
// `fetchOptions` prop so no real HTTP / api singleton is touched.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import FormSelect from '../FormSelect.vue';
import type { SelectFetchArgs, SelectOption } from '../Select.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// FormSelect's own loader maps the API `icon` through resolveFormIcon, but the
// INJECTED loader returns already-mapped SelectOptions, so we hand it valid
// IconNames here (an unknown icon is exercised at the resolveFormIcon unit level).
const FORMS: SelectOption[] = [
  { value: '1', label: 'Onboarding', icon: 'file-text' },
  { value: '2', label: 'Bug report', icon: 'file-text' },
  { value: '3', label: 'Survey', icon: 'file-text' },
];

describe('FormSelect', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders the seeded form name on the trigger before any async load', () => {
    const wrapper = mount(FormSelect, {
      props: {
        modelValue: 'abc',
        seed: [{ id: 'abc', name: 'Seeded form', icon: 'file-text' }],
      },
    });
    // The single-value trigger shows the seeded label, not the bare id.
    expect(wrapper.text()).toContain('Seeded form');
    expect(wrapper.text()).not.toContain('abc');
  });

  it('fetches + maps options through the injected loader on open', async () => {
    const calls: SelectFetchArgs[] = [];
    const fetchOptions = async (args: SelectFetchArgs) => {
      calls.push(args);
      return { options: FORMS, nextCursor: null };
    };
    const wrapper = mount(FormSelect, {
      attachTo: document.body,
      props: { fetchOptions },
    });

    await wrapper.find('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    // The loader ran once with the first-page args.
    expect(calls.length).toBeGreaterThanOrEqual(1);
    expect(calls[0].cursor).toBeNull();

    // The mapped options render in the teleported listbox.
    const options = document.body.querySelectorAll('[role="option"]');
    expect(options.length).toBe(3);
    expect(document.body.textContent).toContain('Onboarding');
    expect(document.body.textContent).toContain('Bug report');
    expect(document.body.textContent).toContain('Survey');

    wrapper.unmount();
  });

  it('selecting an option updates the single v-model with the form id', async () => {
    const fetchOptions = async () => ({ options: FORMS, nextCursor: null });
    const wrapper = mount(FormSelect, {
      attachTo: document.body,
      props: { modelValue: null, fetchOptions },
    });

    await wrapper.find('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    const options = document.body.querySelectorAll<HTMLElement>('[role="option"]');
    options[1].click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual(['2']);

    wrapper.unmount();
  });
});
