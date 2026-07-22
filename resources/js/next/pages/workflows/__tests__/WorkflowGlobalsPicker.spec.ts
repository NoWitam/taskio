// @vitest-environment happy-dom
// WorkflowGlobalsPicker.spec — proves a workspace GLOBAL flows through the SAME catalog
// the editor already consumes (Phase 3): it is surfaced (and "Globals ›"-labelled) by the
// variable adapters, an OBJECT global is surfaced where a trigger section would be dropped,
// and picking one in ValueOrVariableField yields a `globals.<key>` ref carrying its type.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import { setLocale } from '../../../app/i18n';
import { allValueVariables, variablesOfType } from '../workflowVariables';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogVariable, WorkflowCatalog } from '../types';

beforeEach(() => setLocale('en'));

describe('globals in the catalog adapters', () => {
  it('surfaces a global as a pickable, "Globals ›"-labelled catalog variable of its type', () => {
    const catalog: WorkflowCatalog = {
      variables: [
        {
          source: 'globals',
          path: 'globals.brand',
          name: 'Brand',
          type: 'text',
          descriptor: { base: 'text', nullable: false, array: false },
        },
      ],
      fields: [],
    };

    const vars = variablesOfType(catalog, [], 0, ['text']);
    expect(vars).toHaveLength(1);
    expect(vars[0].source).toBe('globals');
    expect(vars[0].path).toBe('globals.brand');
    expect(vars[0].type).toBe('text');
    expect(vars[0].name).toBe('Globals › Brand');
  });

  it('surfaces an OBJECT global (a trigger section object would instead be dropped)', () => {
    const objectDescriptor = {
      base: 'object' as const,
      nullable: false,
      array: false,
      fields: [{ key: 'city', label: 'City', descriptor: { base: 'text' as const, nullable: false, array: false } }],
    };
    const catalog: WorkflowCatalog = {
      variables: [
        { source: 'globals', path: 'globals.address', name: 'Address', type: 'text', descriptor: objectDescriptor },
        { source: 'trigger', path: 'trigger.section', name: 'Section', type: 'text', descriptor: objectDescriptor },
      ],
      fields: [],
    };

    const paths = allValueVariables(catalog, [], 0).map((v) => v.path);
    expect(paths).toContain('globals.address'); // globals object → surfaced whole
    expect(paths).not.toContain('trigger.section'); // trigger section → dropped (leaves are flat)
  });
});

describe('picking a global in ValueOrVariableField', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('emits a variable union with a globals.<key> ref carrying the right type', async () => {
    const globalVar: CatalogVariable = {
      source: 'globals',
      path: 'globals.brand',
      name: 'Globals › Brand',
      type: 'text',
      descriptor: { base: 'text', nullable: false, array: false },
    };
    const wrapper = mount(ValueOrVariableField, {
      attachTo: document.body,
      props: { variables: [globalVar] },
      slots: {
        default: (slotProps: { value: unknown; setValue: (v: unknown) => void }) =>
          h('input', {
            class: 'literal-input',
            value: (slotProps.value as string) ?? '',
            onInput: (e: Event) => slotProps.setValue((e.target as HTMLInputElement).value),
          }),
      },
    });

    // Switch to variable mode, open the picker, choose the global.
    await wrapper.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();
    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    document.body.querySelectorAll<HTMLElement>('[role="option"]')[0].click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'globals', path: 'globals.brand', type: 'text' } },
    ]);

    wrapper.unmount();
  });
});
