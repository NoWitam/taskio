// @vitest-environment happy-dom
// elementPipeline.dom.spec — array-transform WAVE 2, the element-pipeline arg control. The
// map/filter/sort/reduce ops host a per-element pipeline rooted at the array's ELEMENT type,
// terminal-gated per host op, and feed two SYNTHETIC scope variables (Element/Indeks) into the
// nested arg-variable browser (contextual — never in an ordinary pipeline). These tests pin:
//   • the wire round-trip: an element pipeline serializes as `Array<{op,args}>` (no editor keys);
//   • Element/Indeks reach the element editor's arg-variable slot AND not an ordinary pipeline;
//   • terminal gating: a filter pipeline not ending boolean reads "not satisfied" (blocks Save).
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import { setLocale } from '../../../app/i18n';
import VariablePipelineEditor from '../extensions/VariablePipelineEditor.vue';
import ChoiceRuleWhenField from '../extensions/ChoiceRuleWhenField.vue';
import { standardOperationsCatalog } from '../extensions/standardOperations';
import type { VariableOperationDefinition, VariablePipelineStep } from '../extensions/types';

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

const CATALOG: VariableOperationDefinition[] = standardOperationsCatalog();
const SOURCE_OPTIONS = [
  { label: 'Open', value: 'open' },
  { label: 'Done', value: 'done' },
];

/** Capture every `argVariable` slot invocation's props (the scope-vars carrier lives here). */
type SlotProps = { depth: number; scopeVariables?: Array<{ path: string; name: string }> };
function captureSlot(bucket: SlotProps[]) {
  return {
    argVariable: (p: SlotProps) => {
      bucket.push(p);
      return h('div', { class: 'arg-var-slot' });
    },
  };
}

describe('VariablePipelineEditor — element pipeline (array-transform wave 2)', () => {
  beforeEach(() => {
    setLocale('en');
    document.body.innerHTML = '';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('renders the element pipeline over an ARRAY and stores it as WIRE {op,args}[]', async () => {
    const captured: SlotProps[] = [];
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_filter',
            args: { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] },
            outputType: 'multi',
          },
        ],
      },
      slots: captureSlot(captured),
      attachTo: document.body,
    });

    // Open the filter step → the element pipeline editor renders with the enum_is step as a chip.
    await wrapper.get('ol button').trigger('click');
    await flush();

    // The nested enum_is chip echoes its op label — the element editor is live.
    expect(wrapper.text()).toContain('Is');

    wrapper.unmount();
  });

  it('feeds the SYNTHETIC Element/Indeks scope variables into the element editor arg-variable slot', async () => {
    const captured: SlotProps[] = [];
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        depth: 0,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_filter',
            args: { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] },
            outputType: 'multi',
          },
        ],
      },
      slots: captureSlot(captured),
      attachTo: document.body,
    });

    // Open the filter step, then open the nested enum_is step so its sourceOption arg offers the slot.
    await wrapper.get('ol button').trigger('click');
    await flush();
    const nestedChip = wrapper.findAll('ol button').find((b) => b.text().includes('Is'));
    expect(nestedChip, 'nested enum_is chip').toBeTruthy();
    await nestedChip!.trigger('click');
    await flush();

    // The slot fired WITH the two scope variables (Element + Indeks), typed from the element.
    const withScope = captured.find((p) => Array.isArray(p.scopeVariables) && p.scopeVariables.length >= 2);
    expect(withScope, 'an argVariable invocation carrying scope vars').toBeTruthy();
    const paths = withScope!.scopeVariables!.map((v) => v.path);
    expect(paths).toContain('element');
    expect(paths).toContain('index');

    wrapper.unmount();
  });

  it('does NOT inject scope variables into an ORDINARY (non-element) pipeline', async () => {
    const captured: SlotProps[] = [];
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'text',
        catalog: CATALOG,
        depth: 0,
        modelValue: [{ stepId: 's1', operationId: 'text_append', args: { value: '' }, outputType: 'text' }],
      },
      slots: captureSlot(captured),
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    expect(captured.length).toBeGreaterThan(0);
    // No ordinary-pipeline arg carries scope variables.
    expect(captured.every((p) => p.scopeVariables === undefined || p.scopeVariables.length === 0)).toBe(true);

    wrapper.unmount();
  });

  it('terminal-gating: a filter pipeline NOT ending boolean reads "not satisfied"', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        // enum_to_number ends NUMBER — the wrong terminal for filter (needs boolean).
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_filter',
            args: { pipeline: [{ op: 'enum_to_number', args: { mapping: {} } }] },
            outputType: 'multi',
          },
        ],
      },
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    const strip = wrapper.find('[data-element-terminal-satisfied]');
    expect(strip.exists()).toBe(true);
    expect(strip.attributes('data-element-terminal-satisfied')).toBe('false');

    wrapper.unmount();
  });

  it('terminal-gating: a filter pipeline ending boolean reads "satisfied"', async () => {
    const wrapper = mount(VariablePipelineEditor, {
      props: {
        baseType: 'multi',
        catalog: CATALOG,
        sourceOptions: SOURCE_OPTIONS,
        modelValue: [
          {
            stepId: 's1',
            operationId: 'array_filter',
            args: { pipeline: [{ op: 'enum_is', args: { value: 'open' } }] },
            outputType: 'multi',
          },
        ],
      },
      attachTo: document.body,
    });
    await wrapper.get('ol button').trigger('click');
    await flush();

    expect(
      wrapper.find('[data-element-terminal-satisfied]').attributes('data-element-terminal-satisfied'),
    ).toBe('true');

    wrapper.unmount();
  });

  it('the element pipeline projects wire↔editor and round-trips as Array<{op,args}> (no editor keys)', () => {
    // The element pipeline reuses ChoiceRuleWhenField as its wire↔editor projector. Projecting IN mints
    // stable editor ids + resolves the op output type; projecting back OUT strips to the WIRE shape.
    let captured: { steps: VariablePipelineStep[]; onSteps: (s: VariablePipelineStep[]) => void } | null = null;
    const wrapper = mount(ChoiceRuleWhenField, {
      props: { modelValue: [{ op: 'enum_is', args: { value: 'open' } }], catalog: CATALOG },
      slots: {
        default: (p: { steps: VariablePipelineStep[]; onSteps: (s: VariablePipelineStep[]) => void }) => {
          captured = p;
          return h('div');
        },
      },
    });

    expect(captured!.steps).toHaveLength(1);
    expect(captured!.steps[0].operationId).toBe('enum_is');
    expect(captured!.steps[0].outputType).toBe('boolean');
    expect(captured!.steps[0]).toHaveProperty('stepId');

    // Editing the projected steps emits the WIRE shape back — {op,args} only.
    captured!.onSteps([{ stepId: 'x', operationId: 'enum_is', args: { value: 'done' }, outputType: 'boolean' }]);
    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual([{ op: 'enum_is', args: { value: 'done' } }]);

    wrapper.unmount();
  });
});
