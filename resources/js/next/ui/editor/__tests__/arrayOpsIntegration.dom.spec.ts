// @vitest-environment happy-dom
// arrayOpsIntegration.dom.spec — the owner-reported array-ops integration fixes on the ONE shared
// pipeline editor:
//   • F3 — offer gating on arrays: on a MULTI running value with a choice `targetOptions`, the add menu
//     does NOT offer `match_to_choice` (the choice terminal) or `multi_to_text` (a list→text leak); once
//     the list is reduced to a scalar (array_count / array_at) the choice terminal IS offered again.
//   • F4 — array_at's REQUIRED typed default: the dedicated element-typed control emits `{type, value}`
//     LOCKED to the element base + marks itself required while the step is non-terminal; the shared
//     `pipelineSatisfies` / `elementPipelinesValid` gate blocks a non-terminal array_at that lacks a
//     valid default (and a MAP element pipeline treats its trailing array_at as non-terminal too).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import VariablePipelineEditor from '../extensions/VariablePipelineEditor.vue';
import TypedLiteralInput from '../../variables/TypedLiteralInput.vue';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import {
  arrayAtDefaultSatisfies,
  descriptorFromType,
  elementPipelinesValid,
  pipelineSatisfies,
} from '../extensions/operationHelpers';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type {
  OperationTypeDescriptor,
  VariableOperationDefinition,
  VariablePipelineStep,
} from '../extensions/types';

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

const CATALOG: VariableOperationDefinition[] = standardOperationsCatalog();
const SOURCE = [
  { label: 'Open', value: 'open' },
  { label: 'Done', value: 'done' },
];
const TARGET = [
  { label: 'Urgent', value: 'urgent' },
  { label: 'Low', value: 'low' },
];
// An `array<object>` (repeater) source descriptor — a STRUCTURAL element (no options on the element
// itself), so the option-membership multi ops are meaningless and must be dropped from the offer.
const OBJECT_ARRAY: OperationTypeDescriptor = {
  base: 'object',
  array: true,
  fields: [{ key: 'title', label: 'Title', descriptor: { base: 'text', array: false } }],
  elementDescriptor: {
    base: 'object',
    array: false,
    fields: [{ key: 'title', label: 'Title', descriptor: { base: 'text', array: false } }],
  },
};

async function openAddMenu(): Promise<void> {
  Array.from(document.body.querySelectorAll('button'))
    .find((b) => b.textContent?.trim() === 'Add operation')!
    .click();
  await flush();
  await nextTick();
}

describe('VariablePipelineEditor — F3 offer gating on arrays', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('a MULTI + targetOptions add menu drops match_to_choice AND multi_to_text (keeps the multi ops)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'multi', catalog: CATALOG, sourceOptions: SOURCE, targetOptions: TARGET, modelValue: [] },
      attachTo: document.body,
    });
    await openAddMenu();

    const menu = document.body.textContent ?? '';
    // The choice terminal is NOT reachable from a list, and the list→text leak is dropped.
    expect(menu).not.toContain('Match to a choice');
    expect(menu).not.toContain('To text (joined)');
    // …but the genuine array/multi ops stay offered.
    expect(menu).toContain('Includes');
    expect(menu).toContain('Get element'); // array_at

    wrapper.unmount();
  });

  it('after array_count reduces the list to a scalar, the choice terminal IS offered again', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: SOURCE,
        targetOptions: TARGET,
        modelValue: [{ stepId: 's1', operationId: 'array_count', args: {}, outputType: 'number' }],
      },
      attachTo: document.body,
    });
    await openAddMenu();

    // The running value is now a scalar number → the choice terminal (match_to_choice) is reachable.
    expect(document.body.textContent).toContain('Match to a choice');

    wrapper.unmount();
  });

  it('an array<object> add menu drops the option-membership multi ops (element has no options)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'multi', catalog: CATALOG, baseDescriptor: OBJECT_ARRAY, modelValue: [] },
      attachTo: document.body,
    });
    await openAddMenu();

    const menu = document.body.textContent ?? '';
    // Option-membership ops (includes / excludes / includes_any / includes_all) are meaningless on a
    // structural element with no options — an empty option Select builds an always-open gate — so they
    // are dropped from the offer. `multi_to_text` (the list→text leak) is dropped on any array.
    expect(menu).not.toContain('Includes'); // covers multi_includes / includes_any / includes_all
    expect(menu).not.toContain('Does not include'); // multi_excludes
    expect(menu).not.toContain('To text (joined)'); // multi_to_text
    // …but the option-free reducers stay, alongside the 6 array-transform ops.
    expect(menu).toContain('Is empty'); // multi_is_empty
    expect(menu).toContain('Count'); // multi_count / array_count
    expect(menu).toContain('Get element'); // array_at
    expect(menu).toContain('Map each item'); // array_map
    expect(menu).toContain('Keep items'); // array_filter
    expect(menu).toContain('Sort items'); // array_sort
    expect(menu).toContain('Combine into one'); // array_reduce

    wrapper.unmount();
  });

  it('a REAL enum MULTI (checklist) KEEPS the option-membership multi ops', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: { baseType: 'multi', catalog: CATALOG, sourceOptions: SOURCE, modelValue: [] },
      attachTo: document.body,
    });
    await openAddMenu();

    const menu = document.body.textContent ?? '';
    // The element IS an enum (it carries the source options), so every membership op stays offered.
    expect(menu).toContain('Includes'); // multi_includes (+ includes_any / includes_all)
    expect(menu).toContain('Includes any of'); // multi_includes_any
    expect(menu).toContain('Includes all of'); // multi_includes_all
    expect(menu).toContain('Does not include'); // multi_excludes

    wrapper.unmount();
  });
});

describe('VariablePipelineEditor — F4 array_at typed default control', () => {
  beforeEach(() => {
    setLocale('en');
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('the default control emits {type,value} typed to the element base + marks itself required', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: SOURCE,
        modelValue: [
          { stepId: 's1', operationId: 'array_at', args: { index: 1 }, outputType: 'text' },
          { stepId: 's2', operationId: 'enum_is', args: { value: 'open' }, outputType: 'boolean' },
        ],
      },
      attachTo: document.body,
    });

    // Open the array_at step (first chip) into edit mode.
    const chip = wrapper.findAll('button').find((b) => (b.attributes('aria-label') ?? '').startsWith('Edit step 1'));
    expect(chip, 'the array_at chip').toBeTruthy();
    await chip!.trigger('click');
    await flush();

    // array_at is NON-terminal (enum_is follows) and has no default → the required marker shows.
    expect(wrapper.find('[data-element-default-required]').exists()).toBe(true);

    // The default control is the element-typed literal — an enum Select over the element options.
    const defaultInput = wrapper.findAllComponents(TypedLiteralInput).find((c) => c.props('base') === 'enum');
    expect(defaultInput, 'the enum default control').toBeTruthy();

    // Picking an element option stores a `{type, value}` LOCKED to the element base.
    defaultInput!.vm.$emit('update:modelValue', 'open');
    await flush();
    const emitted = wrapper.emitted('update:modelValue')!;
    const pipeline = emitted[emitted.length - 1][0] as VariablePipelineStep[];
    expect(pipeline[0].args.default).toEqual({ type: 'enum', value: 'open' });

    // A valid default clears the required marker.
    expect(wrapper.find('[data-element-default-required]').exists()).toBe(false);

    wrapper.unmount();
  });
});

describe('F4 — array_at default Save gating (pure)', () => {
  const catalog = () => standardOperationsCatalog();
  const at = (over: Record<string, unknown> = {}): VariablePipelineStep => ({
    stepId: 'at', operationId: 'array_at', args: { index: 1, ...over } as VariablePipelineStep['args'], outputType: 'text',
  });
  const enumIs: VariablePipelineStep = { stepId: 'is', operationId: 'enum_is', args: { value: 'open' }, outputType: 'boolean' };

  it('a TERMINAL array_at (the last step) is legal WITHOUT a default', () => {
    expect(pipelineSatisfies(catalog(), 'multi', [at()], [])).toBe(true);
  });

  it('a NON-terminal array_at REQUIRES a valid typed default (blocks Save otherwise)', () => {
    expect(pipelineSatisfies(catalog(), 'multi', [at(), enumIs], ['boolean'])).toBe(false);
    expect(
      pipelineSatisfies(catalog(), 'multi', [at({ default: { type: 'enum', value: 'open' } }), enumIs], ['boolean']),
    ).toBe(true);
    // A WRONG-typed default (text where the element is enum) is rejected.
    expect(
      pipelineSatisfies(catalog(), 'multi', [at({ default: { type: 'text', value: 'x' } }), enumIs], ['boolean']),
    ).toBe(false);
  });

  it('a MAP element pipeline treats its LAST array_at as non-terminal (map forces a value)', () => {
    const root = descriptorFromType('multi');
    // lastStepIsTerminal=false (the map contract) → a trailing array_at still needs a default.
    expect(elementPipelinesValid(catalog(), root, [at()], false)).toBe(false);
    expect(elementPipelinesValid(catalog(), root, [at()], true)).toBe(true);
    expect(elementPipelinesValid(catalog(), root, [at({ default: { type: 'enum', value: 'open' } })], false)).toBe(true);
  });

  it("KEEPS the ''-is-unset rule: a {type:'text', value:''} default does NOT satisfy a non-terminal gate", () => {
    // An `array<text>` — its element default control is a text literal. An EMPTY string is treated as
    // UNSET (the KEPT rule the backend is aligning to), so a non-terminal array_at whose default is ''
    // stays UNsatisfied; a real value passes.
    const textArray: OperationTypeDescriptor = {
      base: 'text', array: true, elementDescriptor: { base: 'text', array: false },
    };
    const textEl: OperationTypeDescriptor = { base: 'text', array: false };
    expect(arrayAtDefaultSatisfies({ type: 'text', value: '' }, textEl)).toBe(false);
    expect(arrayAtDefaultSatisfies({ type: 'text', value: 'x' }, textEl)).toBe(true);
    // …and through the non-terminal element-pipeline gate:
    expect(elementPipelinesValid(catalog(), textArray, [at()], false)).toBe(false);
    expect(elementPipelinesValid(catalog(), textArray, [at({ default: { type: 'text', value: '' } })], false)).toBe(false);
    expect(elementPipelinesValid(catalog(), textArray, [at({ default: { type: 'text', value: 'x' } })], false)).toBe(true);
  });
});
