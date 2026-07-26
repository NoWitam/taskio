// @vitest-environment happy-dom
// ValueOrVariableField.refIdentity.spec — the CONTRACT pin for surface 1's move onto the
// shared VariableBrowser.
//
// The picker changed (a flat/indented tree → miller columns → back to ONE INLINE TREE over
// `buildVariableTree`, B3); the EMITTED REFERENCE must not have — not once. The assertions below
// are therefore frozen: only the ROW SELECTOR moved with the a11y model (B2 rows were `option`s
// inside column listboxes; B3 rows are `treeitem`s, and only search RESULTS stay `option`s).
// For every shape a workflow catalog can produce — a flat
// leaf, a step output, a globals literal, a file composite and its subfield, a composed
// object-global child, a repeater — picking the SAME variable must still emit exactly
// `{kind:'variable', ref:{source, path, type}}` with byte-identical values, because those
// bytes are what the backend stores and validates.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick } from 'vue';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogVariable } from '../types';

/** Every ref-shape a workflow catalog feeds this field, as `allValueVariables` emits them. */
const VARIABLES: CatalogVariable[] = [
  { source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'] },
  { source: 'steps', path: 'steps.make.title', name: 'make.title', type: 'text' },
  {
    source: 'globals',
    path: 'globals.brand',
    name: 'Globals › Brand',
    type: 'text',
    descriptor: { base: 'text', nullable: false, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.attachment',
    name: 'Attachment',
    type: 'file',
    descriptor: {
      base: 'file',
      nullable: false,
      array: false,
      fields: [{ key: 'size', label: 'size', descriptor: { base: 'number', nullable: false, array: false } }],
    },
  },
  // The flat subfield `expandVariables` already emits alongside the composite.
  {
    source: 'trigger',
    path: 'trigger.fields.attachment.size',
    name: 'Attachment › Size',
    type: 'number',
    descriptor: { base: 'number', nullable: false, array: false },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.items',
    name: 'Items (list)',
    type: 'text',
    descriptor: {
      base: 'object',
      nullable: false,
      array: true,
      fields: [{ key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } }],
    },
  },
  {
    source: 'globals',
    path: 'globals.company',
    name: 'Globals › Company',
    type: 'text',
    descriptor: {
      base: 'object',
      nullable: false,
      array: false,
      fields: [{ key: 'city', label: 'City', descriptor: { base: 'text', nullable: false, array: false } }],
    },
  },
];

function mountField(variables: CatalogVariable[] = VARIABLES) {
  return mount(ValueOrVariableField, {
    attachTo: document.body,
    props: { variables },
    slots: {
      default: (p: { value: unknown; setValue: (v: unknown) => void }) =>
        h('input', { class: 'literal-input', value: (p.value as string) ?? '' }),
    },
  });
}

type Wrapper = ReturnType<typeof mountField>;

function rows(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"], [role="option"]'));
}
function rowByText(text: string): HTMLElement | undefined {
  return rows().find((el) => el.querySelector('span.truncate')?.textContent?.trim() === text);
}
async function openPicker(wrapper: Wrapper): Promise<void> {
  await wrapper.get('button[aria-label="Variable"]').trigger('click');
  await nextTick();
  await wrapper.get('[role="combobox"]').trigger('click');
  await nextTick();
  await Promise.resolve();
  await nextTick();
}
function lastEmitted(wrapper: Wrapper): unknown {
  const emitted = wrapper.emitted('update:modelValue');
  return emitted?.[emitted.length - 1]?.[0];
}

describe('ValueOrVariableField — the emitted ref is unchanged by the browser rewire', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it.each([
    ['Status', { source: 'trigger', path: 'fields.status', type: 'enum' }],
    ['make.title', { source: 'steps', path: 'steps.make.title', type: 'text' }],
    ['Globals › Brand', { source: 'globals', path: 'globals.brand', type: 'text' }],
    ['Attachment', { source: 'trigger', path: 'trigger.fields.attachment', type: 'file' }],
    ['Items (list)', { source: 'trigger', path: 'trigger.fields.items', type: 'text' }],
  ])('picking %s emits the same ref as before', async (label, ref) => {
    const wrapper = mountField();
    await openPicker(wrapper);

    rowByText(label as string)!.click();
    await nextTick();

    expect(lastEmitted(wrapper)).toEqual({ kind: 'variable', ref });

    wrapper.unmount();
  });

  it('a file SUBFIELD keeps its composed path + scalar type (opened via the chevron, picked inline beneath it)', async () => {
    const wrapper = mountField();
    await openPicker(wrapper);

    rowByText('Attachment')!.querySelector('button')!.click();
    await nextTick();

    rowByText('Attachment › Size')!.click();
    await nextTick();

    expect(lastEmitted(wrapper)).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'trigger.fields.attachment.size', type: 'number' },
    });

    wrapper.unmount();
  });

  it('an object GLOBAL is expand-only; its composed child emits `globals.<key>.<field>` and INHERITS the globals source', async () => {
    const wrapper = mountField();
    await openPicker(wrapper);

    // INTENDED CHANGE (B2): the whole object global is no longer selectable — clicking it
    // opens its fields instead of emitting a whole-map ref.
    rowByText('Globals › Company')!.click();
    await nextTick();
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();

    rowByText('City')!.click();
    await nextTick();

    expect(lastEmitted(wrapper)).toEqual({
      kind: 'variable',
      ref: { source: 'globals', path: 'globals.company.city', type: 'text' },
    });

    wrapper.unmount();
  });

  it('re-picking after a chosen variable was removed emits the same ref again (no leaked state)', async () => {
    const wrapper = mountField();
    await openPicker(wrapper);
    rowByText('Status')!.click();
    await nextTick();
    const first = lastEmitted(wrapper);

    await wrapper.get('button[aria-label="Remove variable"]').trigger('click');
    await nextTick();
    await openPicker(wrapper);
    rowByText('Status')!.click();
    await nextTick();

    expect(lastEmitted(wrapper)).toEqual(first);

    wrapper.unmount();
  });
});
