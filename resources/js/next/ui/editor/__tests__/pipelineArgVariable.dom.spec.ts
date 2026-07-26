// @vitest-environment happy-dom
// pipelineArgVariable.dom.spec — phase-4b: ANY operation argument — value, option OR
// structural — may be a VARIABLE. VariablePipelineEditor decides WHETHER to offer it (the
// host provides the `argVariable` slot + within the depth cap) and hands the slot the arg
// + its running source/target options + a `setValue`; the value-or-variable UI itself is
// the host's (a stub here). These tests pin the GATING (every control, which depths) + the
// raw-arg serialization (literal stays byte-identical, a variable rides through as
// `{kind:'variable', …}`).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import VariablePipelineEditor from '../extensions/VariablePipelineEditor.vue';
import { MAX_ARG_VARIABLE_DEPTH } from '../extensions/operationHelpers';
import type { VariableArgValue, VariableOperationDefinition, VariablePipelineStep } from '../extensions/types';

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

// An op with ONE value-typed (text) arg + one enum→text op with a sourceMap arg + a
// text→choice op with choiceRules/choiceFallback args — the three "shapes" the gate cares about.
const CATALOG: VariableOperationDefinition[] = [
  {
    id: 'text_append',
    label: 'Append',
    inputTypes: ['text'],
    outputType: 'text',
    args: [{ id: 'value', label: 'Value', type: 'text' }],
  },
  {
    id: 'enum_to_text',
    label: 'To text',
    inputTypes: ['enum'],
    outputType: 'text',
    args: [{ id: 'mapping', label: 'Value per option', type: 'sourceMap', mapType: 'text' }],
  },
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
  // Boolean-terminal text op the rule LHS `when` pipeline is built from.
  {
    id: 'text_equals',
    label: 'Equals',
    inputTypes: ['text'],
    outputType: 'boolean',
    args: [{ id: 'value', label: 'Value', type: 'text' }],
  },
];

const SOURCE_OPTIONS = [
  { label: 'Open', value: 'open' },
  { label: 'Done', value: 'done' },
];
const TARGET_OPTIONS = [
  { label: 'Urgent', value: 'urgent' },
  { label: 'Low', value: 'low' },
];

/** A stub `argVariable` slot: a marker div (echoing the depth) + a button that picks a variable. */
function argSlot() {
  return {
    argVariable: (p: {
      arg: { type: string };
      value: unknown;
      depth: number;
      setValue: (v: VariableArgValue) => void;
    }) =>
      h('div', { class: 'arg-var-slot', 'data-depth': String(p.depth) }, [
        h(
          'button',
          {
            class: 'do-pick',
            onClick: () =>
              p.setValue({ kind: 'variable', ref: { source: 'trigger', path: 'trigger.title', type: 'text' } }),
          },
          'pick',
        ),
        h('span', { class: 'arg-val' }, JSON.stringify(p.value ?? null)),
      ]),
  };
}

function mountEditor(
  pipeline: VariablePipelineStep[],
  props: Record<string, unknown> = {},
  withSlot = true,
) {
  return mount(VariablePipelineEditor, {
    props: { baseType: 'text', catalog: CATALOG, modelValue: pipeline, ...props },
    slots: withSlot ? argSlot() : {},
    attachTo: document.body,
  });
}

/** Enter a step's edit mode (a mounted step renders as a chip first). */
async function edit(wrapper: ReturnType<typeof mountEditor>) {
  await wrapper.get('ol button').trigger('click');
  await flush();
}

function lastPipeline(wrapper: ReturnType<typeof mountEditor>): VariablePipelineStep[] {
  const emitted = wrapper.emitted('update:modelValue');
  return emitted![emitted!.length - 1][0] as VariablePipelineStep[];
}

describe('VariablePipelineEditor — arg variables (phase-4b, every control)', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('offers the argVariable slot for a VALUE-TYPED (text) arg, one depth deeper', async () => {
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' }],
      { depth: 0 },
    );
    await edit(wrapper);

    const slot = wrapper.find('.arg-var-slot');
    expect(slot.exists()).toBe(true);
    // The arg sits one level below its pipeline (depth 0 → 1).
    expect(slot.attributes('data-depth')).toBe('1');

    wrapper.unmount();
  });

  it('picking a variable in the slot serializes {kind:variable, ref} as the RAW arg value', async () => {
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' }],
      { depth: 0 },
    );
    await edit(wrapper);
    await wrapper.get('.do-pick').trigger('click');
    await flush();

    expect(lastPipeline(wrapper)[0].args.value).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'trigger.title', type: 'text' },
    });

    wrapper.unmount();
  });

  it('a variable arg echoes the referenced path tail in the collapsed chip (not "—")', async () => {
    const wrapper = mountEditor([
      {
        stepId: 's1',
        operationId: 'text_append',
        args: { value: { kind: 'variable', ref: { source: 'trigger', path: 'trigger.title', type: 'text' } } },
        outputType: 'text',
      },
    ]);
    // Collapsed chip (no edit) shows the arg badge "Value: title".
    const chip = wrapper.get('ol').text();
    expect(chip).toContain('title');

    wrapper.unmount();
  });

  it('a LITERAL text arg (no variable) serializes byte-identically — a raw string, no wrapper', async () => {
    // No slot provided → the literal control renders (feature off, as in conditions/markdown).
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' }],
      {},
      false,
    );
    await edit(wrapper);

    // The only text input in edit mode is the arg's literal control.
    await wrapper.get('input').setValue('hello');
    await flush();

    expect(lastPipeline(wrapper)[0].args.value).toBe('hello');
    expect(wrapper.find('.arg-var-slot').exists()).toBe(false);

    wrapper.unmount();
  });

  it('Defect-3: a STRUCTURAL sourceMap offers a PER-ENTRY slot (one per source option), NOT one whole-arg slot', async () => {
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'enum_to_text', args: { mapping: {} }, outputType: 'text' }],
      { baseType: 'enum', sourceOptions: SOURCE_OPTIONS },
    );
    await edit(wrapper);

    // Each source option's target is its own value-or-variable (the stub slot replaces each leaf) —
    // two options ⇒ two entry slots — and no inline map inputs render.
    expect(wrapper.findAll('.arg-var-slot').length).toBe(2);
    expect(wrapper.findAll('input[aria-label^="Value per option:"]').length).toBe(0);
    // Every entry sits one level below the pipeline (depth 0 → 1).
    expect(wrapper.findAll('.arg-var-slot').every((s) => s.attributes('data-depth') === '1')).toBe(true);

    wrapper.unmount();
  });

  it('Defect-3: choiceRules offers a PER-RULE `then` entry slot; choiceFallback stays a whole-arg slot', async () => {
    const wrapper = mountEditor(
      [
        {
          stepId: 's1',
          operationId: 'match_to_choice',
          // `when` is now the WIRE boolean pipeline; its text_equals step is a collapsed chip here.
          args: { rules: [{ when: [{ op: 'text_equals', args: { value: 'x' } }], then: 'urgent' }], fallback: 'low' },
          outputType: 'enum',
        },
      ],
      { baseType: 'text', targetOptions: TARGET_OPTIONS },
    );
    await edit(wrapper);

    // Two slots: the choiceFallback WHOLE arg + the single rule's `then` ENTRY. The rule LHS `when`
    // pipeline renders its step as a chip (not in edit mode), so it contributes no arg slot. Adding a
    // rule adds a slot.
    expect(wrapper.findAll('.arg-var-slot').length).toBe(2);

    wrapper.unmount();
  });

  it('Defect-3: a map entry slot gets the running SOURCE options + the entry target type; a TEXT entry threads NO target options', async () => {
    const captured: Array<{ resultTypes: unknown; sourceOptions: unknown; targetOptions: unknown }> = [];
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'enum',
        catalog: CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        targetOptions: TARGET_OPTIONS,
        // enum_to_text → mapType text: each entry is a TEXT value (no destination choice).
        modelValue: [{ stepId: 's1', operationId: 'enum_to_text', args: { mapping: {} }, outputType: 'text' }],
      },
      slots: {
        argVariable: (p: { resultTypes: unknown; sourceOptions: unknown; targetOptions: unknown }) => {
          captured.push({ resultTypes: p.resultTypes, sourceOptions: p.sourceOptions, targetOptions: p.targetOptions });
          return h('div', { class: 'arg-var-slot' });
        },
      },
      attachTo: document.body,
    });
    await edit(wrapper);

    const last = captured[captured.length - 1];
    expect(last.resultTypes).toEqual(['text']); // typed to the map target
    expect(last.sourceOptions).toEqual(SOURCE_OPTIONS); // running source options for the leaf
    expect(last.targetOptions).toEqual([]); // a text entry maps to no destination choice

    wrapper.unmount();
  });

  it('Defect-3: a CHOICE structural entry (rule `then`) threads the target options + an enum terminal', async () => {
    const captured: Array<{ resultTypes: unknown; targetOptions: unknown }> = [];
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        catalog: CATALOG,
        targetOptions: TARGET_OPTIONS,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'match_to_choice',
            args: { rules: [{ when: [{ op: 'text_equals', args: { value: 'x' } }], then: 'urgent' }], fallback: 'low' },
            outputType: 'enum',
          },
        ],
      },
      slots: {
        argVariable: (p: { resultTypes: unknown; targetOptions: unknown }) => {
          captured.push({ resultTypes: p.resultTypes, targetOptions: p.targetOptions });
          return h('div', { class: 'arg-var-slot' });
        },
      },
      attachTo: document.body,
    });
    await edit(wrapper);

    // The rule `then` ENTRY is a choice target: enum terminal + the destination options threaded. (The
    // choiceFallback WHOLE arg has no `resultTypes` — the reference editor derives it — so it is excluded.)
    const thenEntry = captured.find(
      (c) => Array.isArray(c.resultTypes) && c.resultTypes.length === 1 && c.resultTypes[0] === 'enum',
    );
    expect(thenEntry).toBeTruthy();
    expect(thenEntry!.targetOptions).toEqual(TARGET_OPTIONS);

    wrapper.unmount();
  });

  it(`renders LITERAL-ONLY at the depth cap (depth = ${MAX_ARG_VARIABLE_DEPTH}), even with the slot`, async () => {
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' }],
      { depth: MAX_ARG_VARIABLE_DEPTH },
    );
    await edit(wrapper);

    // At/over the cap the arg is a plain literal — no toggle slot.
    expect(wrapper.find('.arg-var-slot').exists()).toBe(false);
    expect(wrapper.find('input').exists()).toBe(true);

    wrapper.unmount();
  });

  it(`renders a STRUCTURAL arg LITERAL-ONLY at the depth cap (its bespoke editor, no slot)`, async () => {
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'enum_to_text', args: { mapping: {} }, outputType: 'text' }],
      { baseType: 'enum', sourceOptions: SOURCE_OPTIONS, depth: MAX_ARG_VARIABLE_DEPTH },
    );
    await edit(wrapper);

    // The depth gate is arg-type-agnostic: a structural arg at the cap is its literal map editor.
    expect(wrapper.find('.arg-var-slot').exists()).toBe(false);
    expect(wrapper.findAll('input[aria-label^="Value per option:"]').length).toBe(2);

    wrapper.unmount();
  });

  it(`still offers the slot at depth ${MAX_ARG_VARIABLE_DEPTH - 1} (just under the cap)`, async () => {
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' }],
      { depth: MAX_ARG_VARIABLE_DEPTH - 1 },
    );
    await edit(wrapper);

    const slot = wrapper.find('.arg-var-slot');
    expect(slot.exists()).toBe(true);
    expect(slot.attributes('data-depth')).toBe(String(MAX_ARG_VARIABLE_DEPTH));

    wrapper.unmount();
  });
});
