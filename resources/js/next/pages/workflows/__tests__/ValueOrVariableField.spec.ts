// @vitest-environment happy-dom
// ValueOrVariableField.spec — the value-or-variable add-on (§4.9.1), REDESIGNED
// (SF3.3-5). Asserts the COMPACT mode toggle (two `aria-pressed` icon buttons, NOT
// full SegmentedControl cards) flips between literal and variable modes, that the
// literal slot control emits the `{kind:'literal'}` union, that picking a catalog
// variable emits `{kind:'variable', ref}` with the TRUE workflow type and renders it
// as a CHIP inside the field, that clicking the chip opens the OPERATIONS MODAL
// (VariablePipelineEditor + a live type status + a Save gated on the terminal type),
// and that saving emits `{kind:'variable', ref, pipeline:[{op,args}]}`.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { h } from 'vue';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import VariablePipelineEditor from '../../../ui/editor/extensions/VariablePipelineEditor.vue';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
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

/** Click the compact mode toggle button (Value | Variable) by its aria-label. */
async function setMode(wrapper: ReturnType<typeof mountField>, label: 'Value' | 'Variable') {
  await wrapper.get(`button[aria-label="${label}"]`).trigger('click');
  await nextTick();
}

/** Open the operations modal for the picked variable (clicks the chip token). */
async function openOpsModal(wrapper: ReturnType<typeof mountField>, name = 'Status') {
  await wrapper.get(`button[aria-label="Edit operations: ${name}"]`).trigger('click');
  await nextTick();
}

/** Find a footer/action button in the teleported modal DOM by its trimmed text. */
function modalButton(text: string): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find(
    (b) => b.textContent?.trim() === text,
  ) as HTMLButtonElement | undefined;
}

describe('ValueOrVariableField', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('shows a COMPACT (not full-card) Value | Variable toggle and starts in value mode', () => {
    const wrapper = mountField();

    // The toggle is two aria-pressed icon buttons — NOT a SegmentedControl (no radios).
    expect(wrapper.findAll('[role="radio"]').length).toBe(0);
    const valueBtn = wrapper.get('button[aria-label="Value"]');
    const variableBtn = wrapper.get('button[aria-label="Variable"]');
    expect(valueBtn.attributes('aria-pressed')).toBe('true');
    expect(variableBtn.attributes('aria-pressed')).toBe('false');
    // The literal slot control is shown and emits the literal union.
    expect(wrapper.find('.literal-input').exists()).toBe(true);

    wrapper.get('.literal-input').setValue('hello');
    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([{ kind: 'literal', value: 'hello' }]);

    wrapper.unmount();
  });

  it('the toggle flips to variable mode (aria-pressed) and shows the picker', async () => {
    const wrapper = mountField();

    await setMode(wrapper, 'Variable');

    expect(wrapper.get('button[aria-label="Variable"]').attributes('aria-pressed')).toBe('true');
    // The literal slot is replaced by the variable picker (a combobox).
    expect(wrapper.find('.literal-input').exists()).toBe(false);
    expect(wrapper.find('[role="combobox"]').exists()).toBe(true);

    wrapper.unmount();
  });

  it('picking a variable emits a variable union carrying the TRUE workflow type', async () => {
    const wrapper = mountField();

    await setMode(wrapper, 'Variable');

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

  it('renders a chip INSIDE the field for a pre-selected variable and removes it back to value mode', async () => {
    const wrapper = mountField({
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.name', type: 'text' } },
    });
    await nextTick();

    // The chip token shows the catalog name and there is an ✕ remove button.
    expect(wrapper.find('.next-vov__token').exists()).toBe(true);
    expect(wrapper.text()).toContain('Name');
    const removeBtn = wrapper.get('button[aria-label="Remove variable"]');
    await removeBtn.trigger('click');

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([{ kind: 'literal', value: null }]);

    wrapper.unmount();
  });

  // --- SF3.5: operations MODAL + type gate ----------------------------------

  it('clicking the chip opens the operations modal with a VariablePipelineEditor + enum sourceOptions', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum', 'text'],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    });
    await nextTick();

    // No inline pipeline before opening the modal.
    expect(wrapper.findComponent(VariablePipelineEditor).exists()).toBe(false);

    await openOpsModal(wrapper);

    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    expect(pipeline.exists()).toBe(true);
    // Base type = the enum var's TRUE type; sourceOptions = its options (label=value).
    expect(pipeline.props('baseType')).toBe('enum');
    expect(pipeline.props('sourceOptions')).toEqual([
      { label: 'open', value: 'open' },
      { label: 'done', value: 'done' },
    ]);

    wrapper.unmount();
  });

  it('saving the modal emits {kind:variable, ref, pipeline:[{op,args}]}', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum', 'text'],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    });
    await nextTick();
    await openOpsModal(wrapper);

    // Drive the shared pipeline editor's model update (its full add-op UI is tested
    // elsewhere) — the field projects the editor step onto the {op,args} wire on Save.
    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    pipeline.vm.$emit('update:modelValue', [
      { stepId: 's1', operationId: 'enum_to_text', args: {}, outputType: 'text' },
    ]);
    await nextTick();

    modalButton('Save')!.click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'enum_to_text', args: {} }],
      },
    ]);

    wrapper.unmount();
  });

  it('MARKS a pipeline whose result type does not satisfy the terminals + BLOCKS Save', async () => {
    // A date-required field fed an enum var + a length op (→ number) must flag the mismatch.
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['date'],
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'text_length', args: {} }],
      },
    });
    await nextTick();
    await openOpsModal(wrapper);

    const status = document.body.querySelector('[data-type-satisfied]');
    expect(status?.getAttribute('data-type-satisfied')).toBe('false');
    expect(status?.textContent).toContain('Returns:');
    // Save is blocked while the terminal type is wrong.
    expect(modalButton('Save')?.disabled).toBe(true);

    wrapper.unmount();
  });

  it('accepts a pipeline whose result type DOES satisfy the terminals (Save enabled)', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum', 'text'],
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'enum_to_text', args: {} }],
      },
    });
    await nextTick();
    await openOpsModal(wrapper);

    expect(document.body.querySelector('[data-type-satisfied]')?.getAttribute('data-type-satisfied')).toBe('true');
    expect(modalButton('Save')?.disabled).toBe(false);

    wrapper.unmount();
  });

  it('the chip shows an operations marker when the pipeline is non-empty', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum', 'text'],
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'enum_to_text', args: {} }],
      },
    });
    await nextTick();

    expect(wrapper.find('.next-vov__token-ops').exists()).toBe(true);
    expect(wrapper.find('.next-vov__token-ops').text()).toContain('1');

    wrapper.unmount();
  });

  // --- SF: show-all picker + field-level type error + target-specific choice ----

  const PRIORITY_TARGETS = [
    { value: 'urgent', label: 'Urgent' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
  ];

  it('offers EVERY provided variable in the picker (no type pre-filter, point 1)', async () => {
    const mixed: CatalogVariable[] = [
      { source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'] },
      { source: 'trigger', path: 'fields.name', name: 'Name', type: 'text' },
      { source: 'trigger', path: 'trigger.submitted_at', name: 'When', type: 'date' },
      { source: 'trigger', path: 'fields.count', name: 'Count', type: 'number' },
    ];
    const wrapper = mountField({ variables: mixed });

    await setMode(wrapper, 'Variable');
    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    // The field renders whatever it is given — every type, not just the field's own.
    expect(document.body.querySelectorAll('[role="option"]').length).toBe(4);

    wrapper.unmount();
  });

  it('flags an identity enum ref for a CHOICE field + emits a type error, then clears once a choice op is added', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum'],
      targetOptions: PRIORITY_TARGETS,
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    });
    await nextTick();

    // The FIELD itself surfaces the error: danger box skin + a danger "action required"
    // pill (not the neutral ops marker) + aria-invalid on the chip + an inline helper.
    expect(wrapper.find('.next-vov.is-error').exists()).toBe(true);
    expect(wrapper.find('.next-vov__token-error').exists()).toBe(true);
    expect(wrapper.find('.next-vov__token-ops').exists()).toBe(false);
    expect(wrapper.get('.next-vov__token-main').attributes('aria-invalid')).toBe('true');
    expect(wrapper.find('.next-vov__error').exists()).toBe(true);
    // It emitted a NON-null type error (the host lights its per-field channel).
    let te = wrapper.emitted('update:typeError');
    expect(te).toBeTruthy();
    expect(te![te!.length - 1][0]).not.toBeNull();

    // Coercing to the destination choice set (enum_to_choice + a full mapping) clears it.
    await wrapper.setProps({
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'enum_to_choice', args: { mapping: { open: 'urgent', done: 'low' } } }],
      },
    });
    await nextTick();

    expect(wrapper.find('.next-vov.is-error').exists()).toBe(false);
    expect(wrapper.find('.next-vov__token-error').exists()).toBe(false);
    te = wrapper.emitted('update:typeError');
    expect(te![te!.length - 1][0]).toBeNull();

    wrapper.unmount();
  });

  it('flags a TEXT terminal for a CHOICE field (point 3 — must end on a choice op)', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum'],
      targetOptions: PRIORITY_TARGETS,
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'enum_to_text', args: {} }], // enum → text: never targets the choice set
      },
    });
    await nextTick();

    expect(wrapper.find('.next-vov.is-error').exists()).toBe(true);
    const te = wrapper.emitted('update:typeError');
    expect(te![te!.length - 1][0]).not.toBeNull();

    wrapper.unmount();
  });

  it('suppresses its inline type-error message when the host shows an external error (finding 4)', async () => {
    // Same unsatisfying identity enum ref for a CHOICE field, but the HOST already shows
    // a server error → the field must NOT stack a second, differently-worded message.
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum'],
      targetOptions: PRIORITY_TARGETS,
      externalErrorPresent: true,
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    });
    await nextTick();

    // The field's OWN inline type-error <p> is gone (only the host message renders).
    expect(wrapper.find('.next-vov__error').exists()).toBe(false);
    // …but the error SKIN + "action required" pill + aria-invalid still convey the state
    // (they carry no duplicate text).
    expect(wrapper.find('.next-vov.is-error').exists()).toBe(true);
    expect(wrapper.find('.next-vov__token-error').exists()).toBe(true);
    expect(wrapper.get('.next-vov__token-main').attributes('aria-invalid')).toBe('true');

    wrapper.unmount();
  });
});
