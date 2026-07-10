// @vitest-environment happy-dom
// ValueOrVariableField.spec — the value-or-variable add-on (§4.9.1). Asserts the
// braces toggle FLIPS between literal and variable modes (with the right
// aria-pressed state), that the literal slot control emits the `{kind:'literal'}`
// union, and that picking a catalog variable emits the `{kind:'variable', ref}`
// union with the TRUE workflow type. The literal control is injected via the default
// scoped slot (a bare <input>) so the test drives the union boundary, not a specific
// UI control. Mirrors the PipelineSelect.spec teleport + option-click conventions.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { h } from 'vue';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogVariable } from '../types';

const VARIABLES: CatalogVariable[] = [
  { source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'] },
  { source: 'trigger', path: 'fields.name', name: 'Name', type: 'text' },
];

function mountField(props: Record<string, unknown> = {}) {
  return mount(ValueOrVariableField, {
    attachTo: document.body,
    props: { variables: VARIABLES, ...props },
    slots: {
      // A bare literal control that calls setValue on input.
      default: (slotProps: { value: unknown; setValue: (v: unknown) => void }) =>
        h('input', {
          class: 'literal-input',
          value: (slotProps.value as string) ?? '',
          onInput: (e: Event) => slotProps.setValue((e.target as HTMLInputElement).value),
        }),
    },
  });
}

describe('ValueOrVariableField', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('starts in literal mode and emits a literal union from the slot control', async () => {
    const wrapper = mountField();

    // Literal slot control is shown; the toggle is not pressed.
    const toggle = wrapper.get('button[aria-pressed]');
    expect(toggle.attributes('aria-pressed')).toBe('false');
    expect(wrapper.find('.literal-input').exists()).toBe(true);

    await wrapper.get('.literal-input').setValue('hello');
    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([{ kind: 'literal', value: 'hello' }]);

    wrapper.unmount();
  });

  it('the braces toggle flips to variable mode (aria-pressed true) and shows the picker', async () => {
    const wrapper = mountField();

    await wrapper.get('button[aria-pressed]').trigger('click');
    await nextTick();

    expect(wrapper.get('button[aria-pressed]').attributes('aria-pressed')).toBe('true');
    // The literal slot is replaced by the variable picker (a combobox).
    expect(wrapper.find('.literal-input').exists()).toBe(false);
    expect(wrapper.find('[role="combobox"]').exists()).toBe(true);

    wrapper.unmount();
  });

  it('picking a variable emits a variable union carrying the TRUE workflow type', async () => {
    const wrapper = mountField();

    await wrapper.get('button[aria-pressed]').trigger('click');
    await nextTick();

    // Open the picker + choose the first variable (enum-typed).
    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    const options = document.body.querySelectorAll<HTMLElement>('[role="option"]');
    options[0].click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    ]);

    wrapper.unmount();
  });

  it('renders a chip for a pre-selected variable and removes it back to literal', async () => {
    const wrapper = mountField({
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.name', type: 'text' } },
    });
    await nextTick();

    // The chip shows the catalog name, and there is an ✕ remove button.
    expect(wrapper.text()).toContain('Name');
    const removeBtn = wrapper.get('button[aria-label="Remove variable"]');
    await removeBtn.trigger('click');

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([{ kind: 'literal', value: null }]);

    wrapper.unmount();
  });
});
