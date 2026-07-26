// @vitest-environment happy-dom
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import VariablePipelineEditor from '../extensions/VariablePipelineEditor.vue';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import type { VariableOperationDefinition, VariablePipelineStep } from '../extensions/types';

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
  // Boolean-terminal + plain text ops the rule LHS `when` sub-editor may offer/chain.
  {
    id: 'text_equals',
    label: 'Equals',
    inputTypes: ['text'],
    outputType: 'boolean',
    args: [{ id: 'value', label: 'Value', type: 'text' }],
  },
  { id: 'uppercase', label: 'Uppercase', inputTypes: ['text'], outputType: 'text' },
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

  it('a choiceRules rule LHS is a boolean-terminal `when` pipeline over TEXT (NOT free text); RHS `then` + fallback Selects unchanged', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        catalog: MATCH_CATALOG,
        targetOptions: TARGET_OPTIONS,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'match_to_choice',
            // `when` is now the WIRE `{op,args}[]` boolean pipeline, not a free-text string.
            args: { rules: [{ when: [{ op: 'text_equals', args: { value: 'BREAKING' } }], then: 'urgent' }], fallback: 'low' },
            outputType: 'enum' as const,
          },
        ],
      },
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    // The rule LHS is a condition-pipeline sub-editor (a role="group"), NOT a free-text input.
    expect(wrapper.findAll('input[aria-label^="Rule 1: when"]').length).toBe(0);
    const whenGroup = wrapper.find('[role="group"][aria-label^="Rule 1: when"]');
    expect(whenGroup.exists()).toBe(true);
    // The saved `when` pipeline renders its text_equals step as a chip (op label + value badge).
    expect(whenGroup.text()).toContain('Equals');
    expect(whenGroup.text()).toContain('BREAKING');

    // Comboboxes: 1 operation + 1 rule `then` + 1 fallback = 3 (both target-option fed). The `when`
    // step is a chip, so it adds none.
    const combos = wrapper.findAll('[role="combobox"]');
    expect(combos.length).toBe(3);
    // The fallback (last) Select offers the destination choices.
    await combos[combos.length - 1].trigger('click');
    await flush();
    expect(document.body.textContent).toContain('Pilne');
    // Close the fallback listbox before probing the `when` op Select.
    await combos[combos.length - 1].trigger('click');
    await flush();

    // The `when` sub-editor OFFERS all type-valid ops for TEXT (boolean-terminal + chainable) and NOT
    // choice-producing ops (no targetOptions in a condition context). Enter the `when` step's edit mode
    // and open its operation Select to read the offered ops.
    await whenGroup.get('ol button').trigger('click');
    await flush();
    const whenCombo = wrapper.find('[role="group"][aria-label^="Rule 1: when"]').find('[role="combobox"]');
    expect(whenCombo.exists()).toBe(true);
    await whenCombo.trigger('click');
    await flush();
    const optionLabels = Array.from(document.body.querySelectorAll('[role="option"]')).map(
      (o) => o.textContent ?? '',
    );
    expect(optionLabels.some((l) => l.includes('Equals'))).toBe(true);
    expect(optionLabels.some((l) => l.includes('Uppercase'))).toBe(true);
    expect(optionLabels.some((l) => l.includes('Match to a choice'))).toBe(false);

    // "Add rule" appends a rule SEEDED with a single `text_equals` `when` step (empty value) — the wire
    // `when` is a `{op,args}[]` pipeline, exactly the BE contract.
    const addBtn = wrapper.findAll('button').find((b) => b.text() === 'Add rule');
    expect(addBtn).toBeTruthy();
    await addBtn!.trigger('click');
    await flush();
    const emitted = wrapper.emitted('update:modelValue');
    const last = emitted![emitted!.length - 1][0] as Array<{ args: Record<string, unknown> }>;
    const rules = last[0].args.rules as Array<{ when: unknown; then: unknown }>;
    expect(rules.length).toBe(2);
    expect(rules[1].when).toEqual([{ op: 'text_equals', args: { value: '' } }]);

    wrapper.unmount();
  });

  it('Defect 4: surfaces the choice terminal + AUTO-BRIDGES a non-text value to a priority choice', async () => {
    // A NUMBER value: the choice terminal (match_to_choice, text input) is not directly offerable, yet
    // it must be reachable. It is surfaced in the add menu AND picking it auto-inserts num_to_text first.
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'number', catalog: standardOperationsCatalog(), targetOptions: TARGET_OPTIONS, modelValue: [] },
      attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flush();
    await flush();
    const menuBtn = Array.from(document.body.querySelectorAll('button')).find((b) =>
      b.textContent?.includes('Match to a choice'),
    );
    expect(menuBtn).toBeTruthy();
    menuBtn!.click();
    await flush();

    const emitted = wrapper.emitted('update:modelValue');
    const last = emitted![emitted!.length - 1][0] as VariablePipelineStep[];
    expect(last.map((s) => s.operationId)).toEqual(['num_to_text', 'match_to_choice']);
    wrapper.unmount();
  });

  it('Defect 4: a DATE value also reaches the choice terminal via its date→text bridge', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'date', catalog: standardOperationsCatalog(), targetOptions: TARGET_OPTIONS, modelValue: [] },
      attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flush();
    await flush();
    const menuBtn = Array.from(document.body.querySelectorAll('button')).find((b) =>
      b.textContent?.includes('Match to a choice'),
    );
    expect(menuBtn).toBeTruthy();
    menuBtn!.click();
    await flush();
    const emitted = wrapper.emitted('update:modelValue');
    const last = emitted![emitted!.length - 1][0] as VariablePipelineStep[];
    expect(last.map((s) => s.operationId)).toEqual(['date_to_text', 'match_to_choice']);
    wrapper.unmount();
  });

  it('Defect 4: an ENUM value reaches the choice terminal DIRECTLY (enum_to_choice, no bridge)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'enum', catalog: standardOperationsCatalog(), targetOptions: TARGET_OPTIONS, modelValue: [] },
      attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flush();
    await flush();
    const menuBtn = Array.from(document.body.querySelectorAll('button')).find((b) =>
      b.textContent?.trim().startsWith('To a choice'),
    );
    expect(menuBtn).toBeTruthy();
    menuBtn!.click();
    await flush();
    const emitted = wrapper.emitted('update:modelValue');
    const last = emitted![emitted!.length - 1][0] as VariablePipelineStep[];
    expect(last.map((s) => s.operationId)).toEqual(['enum_to_choice']);
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

// --- Markdown cleanup: the presence family is offered on condition surfaces, hidden on reference ones ---
describe('VariablePipelineEditor — presence-op offering (hidePresenceOps)', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('OFFERS the presence family by default (the direct-pipeline condition surfaces)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'text', catalog: standardOperationsCatalog(), modelValue: [] },
      attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flush();
    await flush();
    const text = document.body.textContent ?? '';
    expect(text).toContain('Has a value'); // is_present
    expect(text).toContain('Has no value'); // is_null
    expect(text).toContain('Fallback when empty'); // coalesce
    expect(text).toContain('Require a value'); // assert_present
    wrapper.unmount();
  });

  it('HIDES the presence family when hidePresenceOps is set (the reference surfaces), keeping other text ops', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'text', catalog: standardOperationsCatalog(), hidePresenceOps: true, modelValue: [] },
      attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flush();
    await flush();
    const text = document.body.textContent ?? '';
    expect(text).not.toContain('Has a value');
    expect(text).not.toContain('Has no value');
    expect(text).not.toContain('Fallback when empty');
    expect(text).not.toContain('Require a value');
    // A non-presence text op is still offered (only the presence family is dropped).
    expect(text).toContain('Uppercase');
    wrapper.unmount();
  });
});
