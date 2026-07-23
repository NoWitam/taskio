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

  it('OFFERS the slot for a STRUCTURAL sourceMap (map) arg — phase-4b widened it past value args', async () => {
    const wrapper = mountEditor(
      [{ stepId: 's1', operationId: 'enum_to_text', args: { mapping: {} }, outputType: 'text' }],
      { baseType: 'enum', sourceOptions: SOURCE_OPTIONS },
    );
    await edit(wrapper);

    // The structural arg now offers the value/variable toggle (the stub slot replaces the map editor).
    expect(wrapper.find('.arg-var-slot').exists()).toBe(true);
    expect(wrapper.findAll('input[aria-label^="Value per option:"]').length).toBe(0);

    wrapper.unmount();
  });

  it('OFFERS the slot for choiceRules AND choiceFallback args (one per arg)', async () => {
    const wrapper = mountEditor(
      [
        {
          stepId: 's1',
          operationId: 'match_to_choice',
          args: { rules: [{ when: 'x', then: 'urgent' }], fallback: 'low' },
          outputType: 'enum',
        },
      ],
      { baseType: 'text', targetOptions: TARGET_OPTIONS },
    );
    await edit(wrapper);

    // BOTH the choiceRules and the choiceFallback arg offer the toggle slot.
    expect(wrapper.findAll('.arg-var-slot').length).toBe(2);

    wrapper.unmount();
  });

  it('the slot receives the running source + target options (for the recursive literal control)', async () => {
    // A stub cannot echo scoped props, so we assert the passthrough via a dedicated capturing slot.
    const captured: Array<{ sourceOptions: unknown; targetOptions: unknown }> = [];
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'enum',
        catalog: CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        targetOptions: TARGET_OPTIONS,
        modelValue: [{ stepId: 's1', operationId: 'enum_to_text', args: { mapping: {} }, outputType: 'text' }],
      },
      slots: {
        argVariable: (p: { sourceOptions: unknown; targetOptions: unknown }) => {
          captured.push({ sourceOptions: p.sourceOptions, targetOptions: p.targetOptions });
          return h('div', { class: 'arg-var-slot' });
        },
      },
      attachTo: document.body,
    });
    await edit(wrapper);

    expect(captured[captured.length - 1].sourceOptions).toEqual(SOURCE_OPTIONS);
    expect(captured[captured.length - 1].targetOptions).toEqual(TARGET_OPTIONS);

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
