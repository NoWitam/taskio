// @vitest-environment happy-dom
// elementObjectPipeline.dom.spec — array-transform WAVE 3, REWORKED for the F5 INLINE builder. An
// object/file (repeater / file array) map/filter/sort element pipeline is a scope-rooted value-or-
// variable UNION rooted at `element.<field>`. It USED to render through a host-injected
// `#elementScopeUnion` slot (a variable-only picker); it is now a SELF-CONTAINED inline control inside
// VariablePipelineEditor — a labelled subfield Select + the SAME nested pipeline editor scalar elements
// use — so it works on EVERY surface with no injection point. These tests pin:
//   • the subfield Select renders with LABELLED options (the field labels, values `element.<key>`);
//   • picking a subfield emits the EXACT scope-union wire (`{kind:'variable', ref:{source:'scope',
//     path, type}}`), empty pipeline OMITTED — byte-identical to before;
//   • after a pick the nested pipeline editor renders, and building it round-trips the union wire
//     byte-for-byte (`…, pipeline:[{op,args}]`);
//   • a SCALAR/enum array stays a BARE pipeline (no subfield Select);
//   • the terminal-gating strip still marks a wrong/identity terminal (blocks the host Save);
//   • `Element` still EXPANDS into subfields for a repeater reducer (bare-reducer path unchanged).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import { setLocale } from '../../../app/i18n';
import VariablePipelineEditor from '../extensions/VariablePipelineEditor.vue';
import Select from '../../forms/Select.vue';
import { descriptorToOperation } from '../extensions/operationHelpers';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import type { OperationTypeDescriptor, VariableOperationDefinition, VariablePipelineStep } from '../extensions/types';
import type { VariableDescriptor, VariableSourceVar } from '../../variables/types';

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

const CATALOG: VariableOperationDefinition[] = standardOperationsCatalog();

const REPEATER: VariableDescriptor = {
  base: 'object',
  nullable: false,
  array: true,
  fields: [
    { key: 'price', label: 'Price', descriptor: { base: 'number', nullable: false, array: false } },
    { key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } },
  ],
};
const REPEATER_DESC: OperationTypeDescriptor = descriptorToOperation(REPEATER);

type ArgSlot = { scopeVariables?: VariableSourceVar[] };
type Wrapper = ReturnType<typeof mount>;

function lastPipeline(wrapper: Wrapper): VariablePipelineStep[] {
  const emitted = wrapper.emitted('update:modelValue');
  return emitted![emitted!.length - 1][0] as VariablePipelineStep[];
}

/** The inline subfield Select (aria-labelled "Item field") — the F5 replacement for the picker slot. */
function subfieldSelect(wrapper: Wrapper) {
  return wrapper.findAllComponents(Select).find((component) => component.props('ariaLabel') === 'Item field');
}

/** The nested element pipeline editor rooted at the picked subfield (base-type number for `element.price`). */
function nestedEditor(wrapper: Wrapper) {
  return wrapper.findAllComponents(VariablePipelineEditor).find((component) => component.props('baseType') === 'number');
}

async function openFilterStep(wrapper: Wrapper): Promise<void> {
  await wrapper.get('ol button').trigger('click');
  await flush();
}

describe('VariablePipelineEditor — object/file element pipeline INLINE builder (wave 3, F5)', () => {
  beforeEach(() => {
    setLocale('en');
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('a filter over a REPEATER renders a labelled subfield SELECT (not a variable picker)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        baseDescriptor: REPEATER_DESC,
        catalog: CATALOG,
        modelValue: [{ stepId: 's1', operationId: 'array_filter', args: { pipeline: [] }, outputType: 'multi' }],
      },
      attachTo: document.body,
    });
    await openFilterStep(wrapper);

    const select = subfieldSelect(wrapper);
    expect(select, 'the inline subfield Select').toBeTruthy();
    // Its options are the element's subfields: the field LABEL, value `element.<key>`.
    expect(select!.props('options')).toEqual([
      { value: 'element.price', label: 'Price' },
      { value: 'element.name', label: 'Name' },
    ]);
    // No nested pipeline editor renders until a field is picked.
    expect(nestedEditor(wrapper)).toBeUndefined();

    wrapper.unmount();
  });

  it('picking `element.<field>` emits the EXACT scope-union wire (empty pipeline OMITTED)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        baseDescriptor: REPEATER_DESC,
        catalog: CATALOG,
        modelValue: [{ stepId: 's1', operationId: 'array_filter', args: { pipeline: [] }, outputType: 'multi' }],
      },
      attachTo: document.body,
    });
    await openFilterStep(wrapper);

    // Pick the numeric `price` subfield through the inline Select.
    subfieldSelect(wrapper)!.vm.$emit('update:modelValue', 'element.price');
    await flush();

    // The arg value is the scope-rooted UNION — byte-identical to the BE shape, with an EMPTY pipeline
    // omitted (exactly what the old host-slot field emitted for a fresh pick).
    expect(lastPipeline(wrapper)[0].args.pipeline).toEqual({
      kind: 'variable',
      ref: { source: 'scope', path: 'element.price', type: 'number' },
    });
    // …and the nested pipeline editor now renders, rooted at the number subfield.
    expect(nestedEditor(wrapper), 'the nested pipeline editor after a pick').toBeTruthy();

    wrapper.unmount();
  });

  it('building the picked subfield pipeline round-trips the union wire byte-for-byte', async () => {
    // Hydrate with the union already rooted at `element.price` (empty inner pipeline).
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        baseDescriptor: REPEATER_DESC,
        catalog: CATALOG,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_filter',
            args: { pipeline: { kind: 'variable', ref: { source: 'scope', path: 'element.price', type: 'number' } } },
            outputType: 'multi',
          },
        ],
      },
      attachTo: document.body,
    });
    await openFilterStep(wrapper);

    // The subfield Select shows the picked field, and the nested editor is live.
    expect(subfieldSelect(wrapper)!.props('modelValue')).toBe('element.price');
    const nested = nestedEditor(wrapper);
    expect(nested, 'the nested editor for the picked subfield').toBeTruthy();

    // Building the nested pipeline (num_gt over the element) writes the union back with WIRE steps —
    // the exact BE shape, no editor keys (stepId/outputType) leaking into `pipeline`.
    nested!.vm.$emit('update:modelValue', [
      { stepId: 'n1', operationId: 'num_gt', args: { value: 18 }, outputType: 'boolean' },
    ]);
    await flush();

    expect(lastPipeline(wrapper)[0].args.pipeline).toEqual({
      kind: 'variable',
      ref: { source: 'scope', path: 'element.price', type: 'number' },
      pipeline: [{ op: 'num_gt', args: { value: 18 } }],
    });

    wrapper.unmount();
  });

  it('the terminal-gating strip marks an IDENTITY (non-boolean) filter pick as unsatisfied', async () => {
    // element.price with NO ops returns a number, not the boolean a filter requires → not satisfied.
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        baseDescriptor: REPEATER_DESC,
        catalog: CATALOG,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_filter',
            args: { pipeline: { kind: 'variable', ref: { source: 'scope', path: 'element.price', type: 'number' } } },
            outputType: 'multi',
          },
        ],
      },
      attachTo: document.body,
    });
    await openFilterStep(wrapper);

    const strip = wrapper.find('[data-element-terminal-satisfied]');
    expect(strip.exists()).toBe(true);
    expect(strip.attributes('data-element-terminal-satisfied')).toBe('false');

    wrapper.unmount();
  });

  it('a filter over a SCALAR/enum array stays a BARE pipeline (no subfield Select)', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: [
          { label: 'Open', value: 'open' },
          { label: 'Done', value: 'done' },
        ],
        modelValue: [
          { stepId: 's1', operationId: 'array_filter', args: { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] }, outputType: 'multi' },
        ],
      },
      attachTo: document.body,
    });
    await openFilterStep(wrapper);

    // The inline subfield Select is NEVER rendered for a scalar/enum element — the bare pipeline renders.
    expect(subfieldSelect(wrapper)).toBeUndefined();
    expect(wrapper.text()).toContain('Is'); // the bare enum_is chip is live

    wrapper.unmount();
  });

  it('`Element` EXPANDS into subfields for a repeater reducer, and stays a leaf for a scalar element', async () => {
    // A reduce over a repeater keeps a BARE reducer; its ops reference `element.<field>` as scope
    // arg-variables, so the reducer's arg-variable browser gets `Element` carrying the element FIELDS.
    const objectScope: ArgSlot[] = [];
    const objectWrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        baseDescriptor: REPEATER_DESC,
        catalog: CATALOG,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_reduce',
            args: { seed: { type: 'number', value: 0 }, reducer: [{ op: 'num_add', args: { value: '' } }] },
            outputType: 'multi',
          },
        ],
      },
      slots: {
        argVariable: (p: ArgSlot) => {
          objectScope.push(p);
          return h('div', { class: 'arg-var-slot' });
        },
      },
      attachTo: document.body,
    });
    await objectWrapper.get('ol button').trigger('click');
    await flush();
    // Open the reducer's num_add step so its `value` arg offers the arg-variable slot (with scope vars).
    const numAddChip = objectWrapper
      .findAll('button')
      .find((b) => (b.attributes('aria-label') ?? '').startsWith('Edit step') && b.text().includes('Add'));
    expect(numAddChip, 'num_add reducer chip').toBeTruthy();
    await numAddChip!.trigger('click');
    await flush();

    const withScope = objectScope.find((p) => Array.isArray(p.scopeVariables) && p.scopeVariables.length >= 2);
    expect(withScope, 'scope vars threaded into the reducer').toBeTruthy();
    const element = withScope!.scopeVariables!.find((v) => v.path === 'element');
    expect(element, 'Element scope var').toBeTruthy();
    // Element carries the repeater element's FIELDS → the browser expands it into element.<field>.
    expect(element!.descriptor?.fields?.map((f) => f.key)).toEqual(['price', 'name']);
    objectWrapper.unmount();

    // A SCALAR/enum element: `Element` carries NO fields (a single leaf, wave-2 behaviour).
    const scalarScope: ArgSlot[] = [];
    const scalarWrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: [{ label: 'Open', value: 'open' }],
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_reduce',
            args: { seed: { type: 'number', value: 0 }, reducer: [{ op: 'num_add', args: { value: '' } }] },
            outputType: 'multi',
          },
        ],
      },
      slots: {
        argVariable: (p: ArgSlot) => {
          scalarScope.push(p);
          return h('div', { class: 'arg-var-slot' });
        },
      },
      attachTo: document.body,
    });
    await scalarWrapper.get('ol button').trigger('click');
    await flush();
    const scalarAdd = scalarWrapper
      .findAll('button')
      .find((b) => (b.attributes('aria-label') ?? '').startsWith('Edit step') && b.text().includes('Add'));
    await scalarAdd!.trigger('click');
    await flush();
    const scalarWithScope = scalarScope.find((p) => Array.isArray(p.scopeVariables) && p.scopeVariables.length >= 2);
    const scalarElement = scalarWithScope!.scopeVariables!.find((v) => v.path === 'element');
    expect(scalarElement!.descriptor?.fields ?? []).toEqual([]);
    scalarWrapper.unmount();
  });
});
