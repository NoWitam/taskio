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
import PipelineArgLiteralInput from '../../../ui/editor/extensions/PipelineArgLiteralInput.vue';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogVariable, WorkflowFieldValue } from '../types';

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

/** Open the variable TREE picker (variable mode must already be active). */
async function openTreePicker(wrapper: ReturnType<typeof mountField>) {
  await wrapper.get('[role="combobox"]').trigger('click');
  await nextTick();
  await Promise.resolve();
  await nextTick();
}

/** The visible tree rows in the teleported picker panel. */
function treeItems(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"]'));
}

/** A visible tree row whose label text matches (for picking a specific variable). */
function treeItemByText(text: string): HTMLElement | undefined {
  return treeItems().find((el) => el.textContent?.includes(text));
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

    // Open the tree picker + choose the first variable (enum-typed).
    await openTreePicker(wrapper);
    treeItems()[0].click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    ]);

    wrapper.unmount();
  });

  it('picking a FILE SUBFIELD emits a ref carrying the composed <file>.<key> path + scalar type', async () => {
    // The host feeds the expanded file subfields (phase-2c). Picking one must build a plain
    // identity ref at the composed path with the subfield's SCALAR type — the existing ref
    // machinery, no special-casing.
    const fileSubfields: CatalogVariable[] = [
      { source: 'trigger', path: 'trigger.fields.attachment.name', name: 'Attachment › Name', type: 'text' },
      { source: 'trigger', path: 'trigger.fields.attachment.size', name: 'Attachment › Size', type: 'number' },
    ];
    const wrapper = mountField({ variables: fileSubfields });

    await setMode(wrapper, 'Variable');
    await openTreePicker(wrapper);

    // The first row is the text `.name` subfield → a text ref at the composed path.
    treeItems()[0].click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'trigger', path: 'trigger.fields.attachment.name', type: 'text' } },
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
    await openTreePicker(wrapper);

    // The field renders whatever it is given — every type, not just the field's own.
    expect(treeItems().length).toBe(4);

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

  // --- phase-1a: descriptor enum labels + phase-1b: per-reference default -------

  it('the ops modal shows HUMAN enum labels from the descriptor (label ≠ value)', async () => {
    const withDescriptor: CatalogVariable[] = [
      {
        source: 'trigger',
        path: 'fields.status',
        name: 'Status',
        type: 'enum',
        enumOptions: ['open', 'done'],
        descriptor: {
          base: 'enum',
          nullable: false,
          array: false,
          options: [
            { key: 'open', label: 'Open ticket' },
            { key: 'done', label: 'Resolved' },
          ],
        },
      },
    ];
    const wrapper = mountField({
      variables: withDescriptor,
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum', 'text'],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    });
    await nextTick();
    await openOpsModal(wrapper);

    // sourceOptions now carry the human labels; the stored value stays the wire key.
    expect(wrapper.findComponent(VariablePipelineEditor).props('sourceOptions')).toEqual([
      { label: 'Open ticket', value: 'open' },
      { label: 'Resolved', value: 'done' },
    ]);

    wrapper.unmount();
  });

  // --- §refinement 1: default is pipeline-only, nullable-gated, TYPED ----------
  const NULLABLE_TEXT: CatalogVariable = {
    source: 'trigger', path: 'fields.nickname', name: 'Nickname', type: 'text',
    descriptor: { base: 'text', nullable: true, array: false },
  };
  const NON_NULLABLE_TEXT: CatalogVariable = {
    source: 'trigger', path: 'fields.name', name: 'Name', type: 'text',
    descriptor: { base: 'text', nullable: false, array: false },
  };
  const NULLABLE_NUMBER: CatalogVariable = {
    source: 'trigger', path: 'fields.count', name: 'Count', type: 'number',
    descriptor: { base: 'number', nullable: true, array: false },
  };
  const NULLABLE_ENUM: CatalogVariable = {
    source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'],
    descriptor: { base: 'enum', nullable: true, array: false, options: [{ key: 'open', label: 'Open' }, { key: 'done', label: 'Done' }] },
  };
  const NULLABLE_BOOLEAN: CatalogVariable = {
    source: 'trigger', path: 'fields.flag', name: 'Flag', type: 'boolean',
    descriptor: { base: 'boolean', nullable: true, array: false },
  };

  it('default: NOT rendered on the field surface (moved into the ops modal)', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['text'],
      variables: [NULLABLE_TEXT],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.nickname', type: 'text' } },
    });
    await nextTick();
    // No default control anywhere on the field surface (before the modal is opened).
    expect(wrapper.find('[data-vov-default]').exists()).toBe(false);
    expect(wrapper.find('.next-vov__default').exists()).toBe(false);
    wrapper.unmount();
  });

  it('default: shown in the modal ONLY for a NULLABLE variable', async () => {
    const nullableW = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['text'],
      variables: [NULLABLE_TEXT],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.nickname', type: 'text' } },
    });
    await nextTick();
    await openOpsModal(nullableW, 'Nickname');
    expect(document.body.querySelector('[data-vov-default]')).not.toBeNull();
    nullableW.unmount();
    await nextTick();

    const nonNullW = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['text'],
      variables: [NON_NULLABLE_TEXT],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.name', type: 'text' } },
    });
    await nextTick();
    await openOpsModal(nonNullW, 'Name');
    // A non-nullable variable never offers a default (a default-when-empty is meaningless).
    expect(document.body.querySelector('[data-vov-default]')).toBeNull();
    nonNullW.unmount();
  });

  it('default: the control is TYPED to the variable base (number → numeric, enum → Select)', async () => {
    const numberW = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['number'],
      variables: [NULLABLE_NUMBER],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.count', type: 'number' } },
    });
    await nextTick();
    await openOpsModal(numberW, 'Count');
    const numberRegion = document.body.querySelector('[data-vov-default]')!;
    expect(numberRegion.querySelector('input[type="number"]')).not.toBeNull();
    numberW.unmount();
    await nextTick();

    const enumW = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum'],
      variables: [NULLABLE_ENUM],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
    });
    await nextTick();
    await openOpsModal(enumW, 'Status');
    const enumRegion = document.body.querySelector('[data-vov-default]')!;
    // An enum default is a Select of the descriptor options — NOT a free-text box.
    expect(enumRegion.querySelector('[role="combobox"]')).not.toBeNull();
    expect(enumRegion.querySelector('input[type="number"]')).toBeNull();
    enumW.unmount();
  });

  it('default: round-trips a NUMERIC default through the modal (typed, not a string)', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['number'],
      variables: [NULLABLE_NUMBER],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.count', type: 'number' } },
    });
    await nextTick();
    await openOpsModal(wrapper, 'Count');

    const input = document.body.querySelector('[data-vov-default] input[type="number"]') as HTMLInputElement;
    input.value = '7';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
    modalButton('Save')!.click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.count', type: 'number' },
      default: 7,
    });
    wrapper.unmount();
  });

  it('default: hydrates an existing default into the modal control + clearing OMITS the key', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['text'],
      variables: [NULLABLE_TEXT],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.nickname', type: 'text' }, default: 'Anonymous' },
    });
    await nextTick();
    await openOpsModal(wrapper, 'Nickname');

    const input = document.body.querySelector('[data-vov-default] input') as HTMLInputElement;
    expect(input.value).toBe('Anonymous'); // hydrated
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
    modalButton('Save')!.click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue')!;
    // Empty ⇒ the key is gone (byte-identical to a ref without a default).
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.nickname', type: 'text' },
    });
    wrapper.unmount();
  });

  it('default: a BOOLEAN (Warunek) variable is a tri-state — "no default" never serializes false', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['boolean'],
      variables: [NULLABLE_BOOLEAN],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.flag', type: 'boolean' } },
    });
    await nextTick();
    await openOpsModal(wrapper, 'Flag');
    // The default control is a tri-state Select (not a bare on/off switch that would force false).
    expect(document.body.querySelector('[data-vov-default] [role="combobox"]')).not.toBeNull();

    // Saving with "no default" selected must NOT add `default: false`.
    modalButton('Save')!.click();
    await nextTick();
    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.flag', type: 'boolean' },
    });
    wrapper.unmount();
  });

  it('per-reference default: SURVIVES a modal pipeline save', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      resultTypes: ['enum', 'text'],
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        default: 'open',
      },
    });
    await nextTick();
    await openOpsModal(wrapper);

    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    pipeline.vm.$emit('update:modelValue', [
      { stepId: 's1', operationId: 'enum_to_text', args: {}, outputType: 'text' },
    ]);
    await nextTick();
    modalButton('Save')!.click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
      pipeline: [{ op: 'enum_to_text', args: {} }],
      default: 'open',
    });

    wrapper.unmount();
  });

  // --- phase-4b: value-typed op ARGUMENTS may be variables (RECURSIVE) -----------

  const ARG_POOL: CatalogVariable[] = [
    { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
  ];

  it('threads depth 0 + the argVariable slot into the modal pipeline editor', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      argVariables: ARG_POOL,
      resultTypes: ['text'],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.name', type: 'text' } },
    });
    await nextTick();
    await openOpsModal(wrapper, 'Name');

    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    // A top-level field hosts a depth-0 pipeline, and it FILLS the argVariable slot (so a value-
    // typed op arg can become a variable). The conditions/markdown builders pass neither.
    expect(pipeline.props('depth')).toBe(0);
    expect(typeof pipeline.vm.$slots.argVariable).toBe('function');

    wrapper.unmount();
  });

  it('renders a RECURSIVE value-or-variable field for a value-typed (text) op arg, then picks + serializes it', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      argVariables: ARG_POOL,
      resultTypes: ['text'],
      // A text base var with a text_append op whose `value` arg starts as a LITERAL.
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.name', type: 'text' },
        pipeline: [{ op: 'text_append', args: { value: '' } }],
      },
    });
    await nextTick();
    await openOpsModal(wrapper, 'Name');

    // Enter the step's edit mode → the `value` arg renders a NESTED value-or-variable field.
    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    await pipeline.get('ol button').trigger('click');
    await nextTick();

    const nestedFields = wrapper.findAllComponents(ValueOrVariableField);
    const argField = nestedFields[nestedFields.length - 1];
    expect(nestedFields.length).toBeGreaterThanOrEqual(1);

    // Toggle the nested arg field to Variable + pick the pooled 'Title' variable.
    await argField.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();
    await argField.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();
    treeItems()[0].click();
    await nextTick();

    // Saving the field emits the pipeline with the arg carried as a nested variable union.
    modalButton('Save')!.click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.name', type: 'text' },
      pipeline: [
        {
          op: 'text_append',
          args: { value: { kind: 'variable', ref: { source: 'trigger', path: 'trigger.title', type: 'text' } } },
        },
      ],
    });

    wrapper.unmount();
  });

  it('round-trips a saved arg-variable: hydrates the nested chip + re-saves byte-identically', async () => {
    const saved: WorkflowFieldValue = {
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.name', type: 'text' },
      pipeline: [
        {
          op: 'text_append',
          args: { value: { kind: 'variable', ref: { source: 'trigger', path: 'trigger.title', type: 'text' } } },
        },
      ],
    };
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      argVariables: ARG_POOL,
      resultTypes: ['text'],
      modelValue: saved,
    });
    await nextTick();
    await openOpsModal(wrapper, 'Name');

    // Enter edit mode → the nested arg field hydrates the saved arg-variable as a CHIP ('Title').
    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    await pipeline.get('ol button').trigger('click');
    await nextTick();
    // Two chip tokens in the document now: the OUTER field's (Name) + the NESTED arg field's
    // (Title, in the teleported modal). Query the document — teleported nodes are outside `wrapper`.
    expect(document.body.querySelectorAll('.next-vov__token').length).toBeGreaterThanOrEqual(2);
    expect(document.body.textContent).toContain('Title');

    // Saving re-emits the SAME structure (the arg-variable union passes through the wire).
    modalButton('Save')!.click();
    await nextTick();
    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual(saved);

    wrapper.unmount();
  });

  // --- phase-4b: OPTION & STRUCTURAL op-argument variables (widened past value args) ---
  const MIXED_ARG_POOL: CatalogVariable[] = [
    { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
    { source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'] },
    { source: 'trigger', path: 'fields.tags', name: 'Tags', type: 'multi', enumOptions: ['a', 'b'] },
    { source: 'trigger', path: 'fields.count', name: 'Count', type: 'number' },
  ];

  /** Open the ops modal for `name` + enter the (single) pipeline step's edit mode → the arg fields mount. */
  async function openModalStep(wrapper: ReturnType<typeof mountField>, name: string) {
    await openOpsModal(wrapper, name);
    const pipeline = wrapper.findComponent(VariablePipelineEditor);
    await pipeline.get('ol button').trigger('click');
    await nextTick();
  }

  /** The nested arg field whose result-types EXACTLY equal `types` (the outer field is excluded). */
  function argFieldByResultTypes(wrapper: ReturnType<typeof mountField>, types: string[]) {
    return wrapper
      .findAllComponents(ValueOrVariableField)
      .find((f) => JSON.stringify(f.props('resultTypes')) === JSON.stringify(types));
  }

  it('phase-4b: a choiceFallback (single-choice) arg offers the full pool (show-all, like the field) + serializes a picked variable', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      argVariables: MIXED_ARG_POOL,
      resultTypes: ['enum'],
      targetOptions: PRIORITY_TARGETS,
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.name', type: 'text' },
        pipeline: [{ op: 'match_to_choice', args: { rules: [], fallback: '' } }],
      },
    });
    await nextTick();
    await openModalStep(wrapper, 'Name');

    // The choiceFallback arg's recursive field offers the toggle with the FULL pool (show-all, exactly
    // like the field-level picker) — type-appropriateness is enforced by the terminal gate (resultTypes
    // enum|text), not by hiding variables, so the author can pick any variable and coerce it via the pipeline.
    const fallbackField = argFieldByResultTypes(wrapper, ['enum', 'text']);
    expect(fallbackField).toBeTruthy();
    expect(fallbackField!.props('variables')).toEqual(MIXED_ARG_POOL);
    // Its VALUE mode renders the shared literal control (the choiceFallback Select).
    expect(fallbackField!.findComponent(PipelineArgLiteralInput).exists()).toBe(true);

    // Picking a variable serializes the arg as {kind:'variable', ref}; the rest of the pipeline is untouched.
    fallbackField!.vm.$emit('update:modelValue', {
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
    });
    await nextTick();
    modalButton('Save')!.click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.name', type: 'text' },
      pipeline: [
        {
          op: 'match_to_choice',
          args: {
            rules: [],
            fallback: { kind: 'variable', ref: { source: 'trigger', path: 'fields.status', type: 'enum' } },
          },
        },
      ],
    });
    wrapper.unmount();
  });

  it('phase-4b: a sourceOptions (multi-choice) arg offers the full pool (show-all)', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      argVariables: MIXED_ARG_POOL,
      resultTypes: ['boolean'],
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'enum_in', args: { values: [] } }],
      },
    });
    await nextTick();
    await openModalStep(wrapper, 'Status');

    // The FULL pool is offered (show-all) — the terminal gate (resultTypes ['multi']) enforces the type,
    // not the picker; the author picks any variable and coerces via the pipeline.
    const valuesField = argFieldByResultTypes(wrapper, ['multi']);
    expect(valuesField).toBeTruthy();
    expect(valuesField!.props('variables')).toEqual(MIXED_ARG_POOL);
    wrapper.unmount();
  });

  it('phase-4b: a STRUCTURAL sourceMap arg offers an UNFILTERED picker (no catalog/gate) + a clarifying toggle label, literal byte-identical', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      argVariables: MIXED_ARG_POOL,
      resultTypes: ['text'],
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
        pipeline: [{ op: 'enum_to_text', args: { mapping: { open: 'O', done: 'D' } } }],
      },
    });
    await nextTick();
    await openModalStep(wrapper, 'Status');

    // The structural mapping arg: EVERY variable offered (unfiltered), NO ops catalog, NO type gate.
    const mappingField = argFieldByResultTypes(wrapper, []);
    expect(mappingField).toBeTruthy();
    expect(mappingField!.props('variables')).toEqual(MIXED_ARG_POOL);
    expect(mappingField!.props('operationsCatalog')).toEqual([]);
    expect(mappingField!.props('variableModeLabel')).toBe('Use a variable for the whole mapping');
    // Its VALUE mode renders the bespoke map editor (the byte-identical literal control).
    expect(mappingField!.findComponent(PipelineArgLiteralInput).exists()).toBe(true);

    // Saving WITHOUT choosing a variable keeps the literal mapping byte-identical (no {kind} wrapper).
    modalButton('Save')!.click();
    await nextTick();
    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
      pipeline: [{ op: 'enum_to_text', args: { mapping: { open: 'O', done: 'D' } } }],
    });
    wrapper.unmount();
  });

  // --- §refinement 5: the picker is an EXPANDABLE TREE --------------------------
  const FILE_COMPOSITE: CatalogVariable[] = [
    {
      source: 'trigger', path: 'trigger.fields.attachment', name: 'Attachment', type: 'file',
      descriptor: {
        base: 'file', nullable: false, array: false,
        fields: [
          { key: 'name', label: 'name', descriptor: { base: 'text', nullable: false, array: false } },
          { key: 'size', label: 'size', descriptor: { base: 'number', nullable: false, array: false } },
        ],
      },
    },
    // Its flat subfields (as `expandVariables` emits them) — the tree nests them under the file.
    { source: 'trigger', path: 'trigger.fields.attachment.name', name: 'Attachment › Name', type: 'text' },
    { source: 'trigger', path: 'trigger.fields.attachment.size', name: 'Attachment › Size', type: 'number' },
  ];

  it('picker tree: an object/file variable is an EXPANDABLE node; expanding reveals children; a child emits <parent>.<key>', async () => {
    const wrapper = mountField({ variables: FILE_COMPOSITE });
    await setMode(wrapper, 'Variable');
    await openTreePicker(wrapper);

    // Only the container row shows; it is expandable + collapsed (its children are not in the DOM).
    let rows = treeItems();
    expect(rows.length).toBe(1);
    expect(rows[0].getAttribute('aria-expanded')).toBe('false');

    // Expand it (the chevron) → the child subfields appear.
    rows[0].querySelector('button')!.click();
    await nextTick();
    rows = treeItems();
    expect(rows.length).toBe(3);
    expect(rows[0].getAttribute('aria-expanded')).toBe('true');

    // Pick the text `.name` child → a ref at the composed path with the child's scalar type.
    treeItemByText('› Name')!.click();
    await nextTick();
    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'trigger.fields.attachment.name', type: 'text' },
    });

    wrapper.unmount();
  });

  it('picker tree: a REPEATER stays a single, non-expandable LIST entry', async () => {
    const repeater: CatalogVariable[] = [
      {
        source: 'trigger', path: 'trigger.fields.items', name: 'Items (list)', type: 'text',
        descriptor: {
          base: 'object', nullable: false, array: true,
          fields: [{ key: 'item_name', label: 'Item name', descriptor: { base: 'text', nullable: false, array: false } }],
        },
      },
    ];
    const wrapper = mountField({ variables: repeater });
    await setMode(wrapper, 'Variable');
    await openTreePicker(wrapper);

    const rows = treeItems();
    expect(rows.length).toBe(1);
    // Not expandable (no aria-expanded, no chevron button).
    expect(rows[0].getAttribute('aria-expanded')).toBeNull();
    expect(rows[0].querySelector('button')).toBeNull();
    // …but marked as a list (§refinement 3 array marker).
    expect(rows[0].querySelector('[data-marker="list"]')).not.toBeNull();

    wrapper.unmount();
  });

  it('picker tree: shows the empty state when there are no variables', async () => {
    const wrapper = mountField({ variables: [] });
    await setMode(wrapper, 'Variable');
    await openTreePicker(wrapper);
    expect(treeItems().length).toBe(0);
    expect(document.body.textContent).toContain('No variables available');
    wrapper.unmount();
  });

  it('picker tree: keyboard — ArrowDown moves the active row and Enter picks it', async () => {
    // VARIABLES = [Status(enum), Name(text)] — two flat leaves.
    const wrapper = mountField();
    await setMode(wrapper, 'Variable');
    await openTreePicker(wrapper);

    const tree = document.body.querySelector('[role="tree"]') as HTMLElement;
    tree.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    await nextTick();
    tree.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue')!;
    expect(emitted[emitted.length - 1][0]).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.name', type: 'text' },
    });
    wrapper.unmount();
  });

  // --- §refinement 3: nullable / array type-icon markers -----------------------
  it('markers: a NULLABLE variable shows an "optional" marker, an ARRAY variable a "list" marker (announced)', async () => {
    const marked: CatalogVariable[] = [
      { source: 'trigger', path: 'fields.nickname', name: 'Nickname', type: 'text', descriptor: { base: 'text', nullable: true, array: false } },
      { source: 'trigger', path: 'fields.tags', name: 'Tags', type: 'multi', enumOptions: ['a', 'b'], descriptor: { base: 'enum', nullable: false, array: true } },
    ];
    const wrapper = mountField({ variables: marked });
    await setMode(wrapper, 'Variable');
    await openTreePicker(wrapper);

    const nick = treeItemByText('Nickname')!;
    const tags = treeItemByText('Tags')!;
    const optional = nick.querySelector('[data-marker="optional"]');
    const list = tags.querySelector('[data-marker="list"]');
    expect(optional).not.toBeNull();
    expect(list).not.toBeNull();
    // The markers are announced (title + sr-only).
    expect(optional!.getAttribute('title')).toBeTruthy();
    expect(list!.getAttribute('title')).toBeTruthy();

    wrapper.unmount();
  });

  it('markers: the picked-variable chip carries the type-icon marker', async () => {
    const wrapper = mountField({
      variables: [{ source: 'trigger', path: 'fields.nickname', name: 'Nickname', type: 'text', descriptor: { base: 'text', nullable: true, array: false } }],
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'fields.nickname', type: 'text' } },
    });
    await nextTick();
    expect(wrapper.find('.next-vov__token [data-marker="optional"]').exists()).toBe(true);
    wrapper.unmount();
  });
});
