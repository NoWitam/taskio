// @vitest-environment happy-dom
// TemplateSlotsPanel.spec — the repeatable slots builder. Asserts adding a row emits the grown
// model, and that client validation surfaces the RESERVED + DUPLICATE name errors (mirroring the
// backend TemplateSlotValidator). i18n is real (EN); browser mocks isolate the DOM primitives.
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TemplateSlotsPanel from '../TemplateSlotsPanel.vue';
import type { SlotDraft } from '../templateSlots';

function slot(name: string, id = name, overrides: Partial<SlotDraft> = {}): SlotDraft {
  return {
    id,
    name,
    description: '',
    base: 'text',
    nullable: false,
    array: false,
    options: [{ id: 'o1', key: '', label: '' }],
    fields: [{ id: 'x1', key: '', label: '', base: 'text' }],
    ...overrides,
  };
}

describe('TemplateSlotsPanel', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('adds a slot row (emits the grown model)', async () => {
    const wrapper = mount(TemplateSlotsPanel, { props: { modelValue: [] }, attachTo: document.body });

    const addButton = wrapper.findAll('button').find((b) => b.text().includes('Add slot'));
    expect(addButton).toBeTruthy();
    await addButton!.trigger('click');

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted).toBeTruthy();
    expect((emitted![emitted!.length - 1][0] as SlotDraft[]).length).toBe(1);

    wrapper.unmount();
  });

  it('REJECTS a reserved slot name with a clear error', () => {
    const wrapper = mount(TemplateSlotsPanel, { props: { modelValue: [slot('globals')] }, attachTo: document.body });

    expect(wrapper.text()).toContain('This name is reserved.');

    wrapper.unmount();
  });

  it('flags DUPLICATE slot names', () => {
    const wrapper = mount(TemplateSlotsPanel, {
      props: { modelValue: [slot('topic', 'a'), slot('topic', 'b')] },
      attachTo: document.body,
    });

    expect(wrapper.text()).toContain('Slot names must be unique.');

    wrapper.unmount();
  });

  it('surfaces the nested FIELDS editor for an OBJECT slot (reusing the consts object builder)', () => {
    const wrapper = mount(TemplateSlotsPanel, {
      props: {
        modelValue: [slot('product', 'p', { base: 'object', fields: [{ id: 'f1', key: 'name', label: 'Name', base: 'text' }] })],
      },
      attachTo: document.body,
    });

    expect(wrapper.text()).toContain('Fields');
    const addField = wrapper.findAll('button').find((b) => b.text().includes('Add field'));
    expect(addField).toBeTruthy();

    wrapper.unmount();
  });

  it('flags DUPLICATE object field keys with a clear error', () => {
    const wrapper = mount(TemplateSlotsPanel, {
      props: {
        modelValue: [
          slot('product', 'p', {
            base: 'object',
            fields: [
              { id: 'f1', key: 'dup', label: '', base: 'text' },
              { id: 'f2', key: 'dup', label: '', base: 'number' },
            ],
          }),
        ],
      },
      attachTo: document.body,
    });

    expect(wrapper.text()).toContain('Field keys must be distinct.');

    wrapper.unmount();
  });

  it('shows the fixed-subfields note for a FILE slot (no field authoring)', () => {
    const wrapper = mount(TemplateSlotsPanel, {
      props: { modelValue: [slot('attachment', 'a', { base: 'file' })] },
      attachTo: document.body,
    });

    expect(wrapper.text()).toContain('A file exposes');
    // A file slot has no authorable fields editor.
    expect(wrapper.findAll('button').some((b) => b.text().includes('Add field'))).toBe(false);

    wrapper.unmount();
  });
});
