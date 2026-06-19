// @vitest-environment happy-dom
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import VariablePipelineEditor from '../extensions/VariablePipelineEditor.vue';
import type { VariableOperationDefinition } from '../extensions/types';

const CATALOG: VariableOperationDefinition[] = [
  { id: 'uppercase', label: 'Uppercase', inputTypes: ['text'], outputType: 'text' },
  { id: 'length', label: 'Length', inputTypes: ['text'], outputType: 'number' },
];

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

describe('VariablePipelineEditor "+ Dodaj operację"', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('opens the operations menu on a single click (no double-toggle)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'text', catalog: CATALOG, modelValue: [] },
      attachTo: document.body,
    });

    const trigger = wrapper.get('button');
    expect((trigger.element as HTMLButtonElement).disabled).toBe(false);

    await trigger.trigger('click');
    await flush();
    await flush();

    // The menu is teleported to <body>; its operation rows should now be present.
    const bodyText = document.body.textContent ?? '';
    expect(bodyText).toContain('Uppercase');
    expect(bodyText).toContain('Length');

    wrapper.unmount();
  });
});
