// @vitest-environment happy-dom
// WorkflowConditionsEditor.spec — the typed, schema-driven conditions builder
// (§4.8). Asserts (1) the FORM-GATED disabled state (no form ⇒ the needsForm Alert +
// a disabled Add button, no rows), (2) the operator Select is scoped to the selected
// field's type allow-list, and (3) a boolean-typed condition renders NO value input.
// Fields come from the catalog prop; the emitted model is the typed
// WorkflowCondition[]. Mirrors the PipelineSelect.spec teleport/option conventions.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import WorkflowConditionsEditor from '../WorkflowConditionsEditor.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogField, WorkflowCondition } from '../types';

const FIELDS: CatalogField[] = [
  { path: 'fields.status', field_id: 'status', label: 'Status', type: 'enum', enumOptions: ['open', 'done'], operators: ['is', 'is_not', 'in'] },
  { path: 'fields.count', field_id: 'count', label: 'Count', type: 'number', operators: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'] },
  { path: 'fields.agreed', field_id: 'agreed', label: 'Agreed', type: 'boolean', operators: ['is_true', 'is_false'] },
];

describe('WorkflowConditionsEditor', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders the form-gated disabled state when no form is selected', () => {
    const wrapper = mount(WorkflowConditionsEditor, {
      props: { formSelected: false, fields: null, modelValue: [] },
    });

    // The needsForm Alert is shown and the Add button is disabled; no rows.
    expect(wrapper.text()).toContain('Pick a form to add conditions on its fields.');
    const addBtn = wrapper.get('button');
    expect(addBtn.attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('li').length).toBe(0);

    wrapper.unmount();
  });

  it('adds a well-typed row seeded from the first field on Add', async () => {
    const wrapper = mount(WorkflowConditionsEditor, {
      props: { formSelected: true, fields: FIELDS, modelValue: [] },
    });

    await wrapper.get('button').trigger('click'); // Add condition
    const emitted = wrapper.emitted('update:modelValue');
    const rows = emitted?.[emitted.length - 1]?.[0] as WorkflowCondition[];
    expect(rows).toHaveLength(1);
    expect(rows[0]).toEqual({ field: 'fields.status', field_type: 'enum', operator: 'is', value: undefined });

    wrapper.unmount();
  });

  it('scopes the operator Select to the selected field type allow-list', async () => {
    // A number-typed condition should offer exactly the number operators.
    const model: WorkflowCondition[] = [{ field: 'fields.count', field_type: 'number', operator: 'gt', value: null }];
    const wrapper = mount(WorkflowConditionsEditor, {
      attachTo: document.body,
      props: { formSelected: true, fields: FIELDS, modelValue: model },
    });
    await nextTick();

    // Open the operator Select (the 2nd combobox in the row: field, operator, value).
    const comboboxes = wrapper.findAll('[role="combobox"]');
    await comboboxes[1].trigger('click');
    await nextTick();

    const options = Array.from(document.body.querySelectorAll('[role="option"]')).map((o) => o.textContent?.trim());
    expect(options).toEqual(['equals', 'is not', 'greater than', 'at least', 'less than', 'at most']);

    wrapper.unmount();
  });

  it('renders NO value input for a boolean condition', () => {
    const model: WorkflowCondition[] = [{ field: 'fields.agreed', field_type: 'boolean', operator: 'is_true' }];
    const wrapper = mount(WorkflowConditionsEditor, {
      props: { formSelected: true, fields: FIELDS, modelValue: model },
    });

    // The value FormField shows the boolean note, not an input/combobox.
    expect(wrapper.text()).toContain('No value needed for this operator.');
    // Only field + operator comboboxes exist (no value control).
    expect(wrapper.findAll('[role="combobox"]').length).toBe(2);

    wrapper.unmount();
  });
});
