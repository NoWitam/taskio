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

// --- Extended arg kinds: sourceOption / sourceOptions over the SOURCE options --
const ENUM_CATALOG: VariableOperationDefinition[] = [
  {
    id: 'enum_is',
    label: 'Is',
    inputTypes: ['enum'],
    outputType: 'boolean',
    args: [{ id: 'value', label: 'Value', type: 'sourceOption' }],
  },
  {
    id: 'multi_includes',
    label: 'Includes any of',
    inputTypes: ['multi'],
    outputType: 'boolean',
    args: [{ id: 'values', label: 'Values', type: 'sourceOptions' }],
  },
  {
    id: 'enum_to_text',
    label: 'To text',
    inputTypes: ['enum'],
    outputType: 'text',
    args: [{ id: 'mapping', label: 'Value per option', type: 'sourceMap', mapType: 'text' }],
  },
];

const SOURCE_OPTIONS = [
  { label: 'Niski', value: 'low' },
  { label: 'Wysoki', value: 'high' },
];

describe('VariablePipelineEditor — source-driven args (enum/multi)', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('a sourceOption arg renders a Select fed by the SOURCE variable options', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'enum',
        catalog: ENUM_CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        modelValue: [
          { stepId: 's1', operationId: 'enum_is', args: { value: '' }, outputType: 'boolean' as const },
        ],
      },
      attachTo: document.body,
    });
    // The freshly mounted step renders as a chip; open its edit mode.
    await wrapper.get('ol button').trigger('click');
    await flush();

    // A combobox exists and its (teleported) options carry the SOURCE labels.
    const combo = wrapper.findAll('[role="combobox"]');
    expect(combo.length).toBeGreaterThan(0);
    await combo[combo.length - 1].trigger('click');
    await flush();
    const bodyText = document.body.textContent ?? '';
    expect(bodyText).toContain('Niski');
    expect(bodyText).toContain('Wysoki');
    wrapper.unmount();
  });

  it('a sourceOptions arg renders multi selection cards and emits a string[] value', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: ENUM_CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        modelValue: [
          { stepId: 's1', operationId: 'multi_includes', args: { values: [] }, outputType: 'boolean' as const },
        ],
      },
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    // The multi picker is a checkbox-card group over the source options (the
    // leading "select all" card is excluded — a valueless attr reads as '').
    const cards = wrapper
      .findAll('[role="checkbox"]')
      .filter((c) => c.attributes('data-seg-select-all') === undefined);
    expect(cards.map((c) => c.text())).toEqual(['Niski', 'Wysoki']);

    await cards[1].trigger('click');
    await flush();
    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted).toBeTruthy();
    const last = emitted![emitted!.length - 1][0] as Array<{ args: Record<string, unknown> }>;
    expect(last[0].args.values).toEqual(['high']);
    wrapper.unmount();
  });

  it('a sourceMap arg renders one typed input PER source option and emits the mapping record', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'enum',
        catalog: ENUM_CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        modelValue: [
          { stepId: 's1', operationId: 'enum_to_text', args: { mapping: {} }, outputType: 'text' as const },
        ],
      },
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    // One text input per option, labelled "<arg>: <option>".
    const inputs = wrapper.findAll('input[aria-label^="Value per option:"]');
    expect(inputs.length).toBe(2);
    expect(inputs.map((i) => i.attributes('aria-label'))).toEqual([
      'Value per option: Niski',
      'Value per option: Wysoki',
    ]);

    await inputs[0].setValue('mało');
    await flush();
    const emitted = wrapper.emitted('update:modelValue');
    const last = emitted![emitted!.length - 1][0] as Array<{ args: Record<string, unknown> }>;
    expect(last[0].args.mapping).toEqual({ low: 'mało' });
    wrapper.unmount();
  });
});

// --- Choice-producing (target-driven) args — a "choice"/enum destination field ---
const TARGET_OPTIONS = [
  { label: 'Pilne', value: 'urgent' },
  { label: 'Niski', value: 'low' },
];

const CHOICE_ENUM_CATALOG: VariableOperationDefinition[] = [
  {
    id: 'enum_to_choice',
    label: 'To a choice',
    inputTypes: ['enum'],
    outputType: 'enum',
    args: [{ id: 'mapping', label: 'Choice per option', type: 'sourceMap', mapType: 'enum' }],
  },
  { id: 'enum_to_text', label: 'To text', inputTypes: ['enum'], outputType: 'text' },
];

const MATCH_CATALOG: VariableOperationDefinition[] = [
  {
    id: 'match_to_choice',
    label: 'Match to a choice',
    inputTypes: ['text'],
    outputType: 'enum',
    args: [
      { id: 'rules', label: 'Rules', type: 'choiceRules' },
      { id: 'fallback', label: 'Fallback', type: 'choiceFallback' },
    ],
  },
];

describe('VariablePipelineEditor — choice-producing args (targetOptions)', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('a sourceMap arg with mapType enum renders a TARGET-option Select per source option', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'enum',
        catalog: CHOICE_ENUM_CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        targetOptions: TARGET_OPTIONS,
        modelValue: [
          { stepId: 's1', operationId: 'enum_to_choice', args: { mapping: {} }, outputType: 'enum' as const },
        ],
      },
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    // NO free-text mapping inputs — each option maps to a destination CHOICE Select.
    expect(wrapper.findAll('input[aria-label^="Choice per option:"]').length).toBe(0);
    // 1 operation Select + 2 per-option target Selects.
    const combos = wrapper.findAll('[role="combobox"]');
    expect(combos.length).toBe(3);
    // The last (a per-option map Select) is fed the DESTINATION options.
    await combos[combos.length - 1].trigger('click');
    await flush();
    const bodyText = document.body.textContent ?? '';
    expect(bodyText).toContain('Pilne');
    expect(bodyText).toContain('Niski');
    wrapper.unmount();
  });

  it('a choiceRules arg renders repeatable when→then rows (then = target Select) + a choiceFallback Select', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        catalog: MATCH_CATALOG,
        targetOptions: TARGET_OPTIONS,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'match_to_choice',
            args: { rules: [{ when: 'BREAKING', then: 'urgent' }], fallback: 'low' },
            outputType: 'enum' as const,
          },
        ],
      },
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    // The single rule row shows its `when` text input carrying the saved value.
    const whenInputs = wrapper.findAll('input[aria-label^="Rule 1: when"]');
    expect(whenInputs.length).toBe(1);
    expect((whenInputs[0].element as HTMLInputElement).value).toBe('BREAKING');
    // Comboboxes: 1 operation + 1 rule `then` + 1 fallback = 3 (both target-option fed).
    const combos = wrapper.findAll('[role="combobox"]');
    expect(combos.length).toBe(3);
    // The fallback (last) Select offers the destination choices.
    await combos[combos.length - 1].trigger('click');
    await flush();
    expect(document.body.textContent).toContain('Pilne');

    // "Add rule" appends an empty rule row to the choiceRules value.
    const addBtn = wrapper.findAll('button').find((b) => b.text() === 'Add rule');
    expect(addBtn).toBeTruthy();
    await addBtn!.trigger('click');
    await flush();
    const emitted = wrapper.emitted('update:modelValue');
    const last = emitted![emitted!.length - 1][0] as Array<{ args: Record<string, unknown> }>;
    expect((last[0].args.rules as unknown[]).length).toBe(2);

    wrapper.unmount();
  });

  it('HIDES choice-producing ops in the add menu without targetOptions, and SHOWS them with', async () => {
    const without = mount(VariablePipelineEditor, {
      props: { baseType: 'enum', catalog: CHOICE_ENUM_CATALOG, modelValue: [] },
      attachTo: document.body,
    });
    await without.get('button').trigger('click');
    await flush();
    await flush();
    let bodyText = document.body.textContent ?? '';
    expect(bodyText).toContain('To text');
    expect(bodyText).not.toContain('To a choice');
    without.unmount();

    document.body.innerHTML = '';
    const withTargets = mount(VariablePipelineEditor, {
      props: { baseType: 'enum', catalog: CHOICE_ENUM_CATALOG, targetOptions: TARGET_OPTIONS, modelValue: [] },
      attachTo: document.body,
    });
    await withTargets.get('button').trigger('click');
    await flush();
    await flush();
    bodyText = document.body.textContent ?? '';
    expect(bodyText).toContain('To a choice');
    withTargets.unmount();
  });
});
