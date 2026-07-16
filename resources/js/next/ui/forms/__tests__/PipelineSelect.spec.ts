// @vitest-environment happy-dom
// PipelineSelect.spec.ts — the global approval-pipeline picker. Asserts (1) the
// seed renders the attached pipeline's name immediately (before any async page),
// and (2) opening the dropdown invokes the injected loader and maps
// `{ id, name, icon }` → options with a resolved leading IconName. The async loader
// is injected via the `fetchOptions` prop so no real HTTP / api singleton is
// touched. Mirrors FormSelect.spec.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PipelineSelect from '../PipelineSelect.vue';
import type { SelectFetchArgs, SelectOption } from '../Select.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// PipelineSelect's own loader maps the API `icon` through resolvePipelineIcon, but
// the INJECTED loader returns already-mapped SelectOptions, so we hand it valid
// IconNames here (an unknown icon is exercised at the resolvePipelineIcon level).
const PIPELINES: SelectOption[] = [
  { value: '1', label: 'Two-step review', icon: 'git-branch' },
  { value: '2', label: 'Manager sign-off', icon: 'git-branch' },
  { value: '3', label: 'AI triage', icon: 'git-branch' },
];

describe('PipelineSelect', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders the seeded pipeline name on the trigger before any async load', () => {
    const wrapper = mount(PipelineSelect, {
      props: {
        modelValue: 'abc',
        seed: [{ id: 'abc', name: 'Seeded pipeline', icon: 'git-branch' }],
      },
    });
    // The single-value trigger shows the seeded label, not the bare id.
    expect(wrapper.text()).toContain('Seeded pipeline');
    expect(wrapper.text()).not.toContain('abc');
  });

  it('fetches + maps options through the injected loader on open', async () => {
    const calls: SelectFetchArgs[] = [];
    const fetchOptions = async (args: SelectFetchArgs) => {
      calls.push(args);
      return { options: PIPELINES, nextCursor: null };
    };
    const wrapper = mount(PipelineSelect, {
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
    expect(document.body.textContent).toContain('Two-step review');
    expect(document.body.textContent).toContain('Manager sign-off');
    expect(document.body.textContent).toContain('AI triage');

    wrapper.unmount();
  });

  it('selecting an option updates the single v-model with the pipeline id', async () => {
    const fetchOptions = async () => ({ options: PIPELINES, nextCursor: null });
    const wrapper = mount(PipelineSelect, {
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
