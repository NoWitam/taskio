// @vitest-environment happy-dom
// BotSelect.spec.ts — the global bot picker. Asserts (1) the seed renders the
// attached bot's name immediately (before any async page), (2) opening the
// dropdown invokes the injected loader and maps `{ id, name, status }` → options,
// and (3) selecting an option emits the bot id on the single v-model. The async
// loader is injected via the `fetchOptions` prop so no real HTTP / api singleton
// is touched.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import BotSelect from '../BotSelect.vue';
import type { SelectFetchArgs, SelectOption } from '../Select.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const BOTS: SelectOption[] = [
  { value: 'b1', label: 'Marketing Maven', status: 'active' } as SelectOption,
  { value: 'b2', label: 'Support Sam', status: 'draft' } as SelectOption,
  { value: 'b3', label: 'Ops Otto', status: 'disabled' } as SelectOption,
];

describe('BotSelect', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders the seeded bot name on the trigger before any async load', () => {
    const wrapper = mount(BotSelect, {
      props: {
        modelValue: 'abc',
        seed: [{ id: 'abc', name: 'Seeded bot', status: 'active' }],
      },
    });
    expect(wrapper.text()).toContain('Seeded bot');
    expect(wrapper.text()).not.toContain('abc');
  });

  it('fetches + maps options through the injected loader on open', async () => {
    const calls: SelectFetchArgs[] = [];
    const fetchOptions = async (args: SelectFetchArgs) => {
      calls.push(args);
      return { options: BOTS, nextCursor: null };
    };
    const wrapper = mount(BotSelect, {
      attachTo: document.body,
      props: { fetchOptions },
    });

    await wrapper.find('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    expect(calls.length).toBeGreaterThanOrEqual(1);
    expect(calls[0].cursor).toBeNull();

    const options = document.body.querySelectorAll('[role="option"]');
    expect(options.length).toBe(3);
    expect(document.body.textContent).toContain('Marketing Maven');
    expect(document.body.textContent).toContain('Support Sam');
    expect(document.body.textContent).toContain('Ops Otto');

    wrapper.unmount();
  });

  it('selecting an option updates the single v-model with the bot id', async () => {
    const fetchOptions = async () => ({ options: BOTS, nextCursor: null });
    const wrapper = mount(BotSelect, {
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
    expect(emitted?.[emitted.length - 1]).toEqual(['b2']);

    wrapper.unmount();
  });
});
