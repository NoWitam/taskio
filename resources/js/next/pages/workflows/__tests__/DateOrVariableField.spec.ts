// @vitest-environment happy-dom
// DateOrVariableField.spec — the date value-or-variable add-on (§4.9.2). It composes
// ValueOrVariableField with a DatePicker literal + a `date`-filtered variable picker,
// and — SF1 — FORWARDS the operations catalog while forcing the pipeline's required
// terminal to `date`. Behaviour-focused: the COMPACT Value | Variable toggle (SF3.3)
// is present, a picked date variable emits the `{kind:'variable', ref}` union with the
// date type, the inner field receives `result-types: ['date']` + the catalog, and — via
// the operations MODAL (SF3.5) — a pipeline that returns a non-date type is MARKED.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import DateOrVariableField from '../DateOrVariableField.vue';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogVariable } from '../types';

const DATE_VARIABLES: CatalogVariable[] = [
  { source: 'trigger', path: 'trigger.submitted_at', name: 'Submitted at', type: 'date' },
];

function mountField(props: Record<string, unknown> = {}) {
  return mount(DateOrVariableField, {
    attachTo: document.body,
    props: { variables: DATE_VARIABLES, ...props },
  });
}

describe('DateOrVariableField', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('shows the COMPACT Value | Variable toggle and a date literal in value mode', () => {
    const wrapper = mountField();
    // The toggle is two aria-pressed icon buttons — NOT full SegmentedControl cards.
    expect(wrapper.findAll('[role="radio"]').length).toBe(0);
    expect(wrapper.get('button[aria-label="Value"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.find('button[aria-label="Variable"]').exists()).toBe(true);
    // The literal control is a DatePicker (its trigger carries the date aria-label).
    expect(wrapper.find('[aria-label="Pick a date"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('forwards the operations catalog and forces the pipeline to return a date', () => {
    const catalog = standardOperationsCatalog();
    const wrapper = mountField({ operationsCatalog: catalog });

    const inner = wrapper.findComponent(ValueOrVariableField);
    expect(inner.exists()).toBe(true);
    expect(inner.props('resultTypes')).toEqual(['date']);
    expect(inner.props('operationsCatalog')).toEqual(catalog);

    wrapper.unmount();
  });

  it('picking a date variable emits the variable union with the date type', async () => {
    const wrapper = mountField();

    await wrapper.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();

    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    // The Variable picker is now an expandable TREE (§refinement 5); a flat date var is a leaf row.
    document.body.querySelectorAll<HTMLElement>('[role="treeitem"]')[0].click();
    await nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted?.[emitted.length - 1]).toEqual([
      { kind: 'variable', ref: { source: 'trigger', path: 'trigger.submitted_at', type: 'date' } },
    ]);

    wrapper.unmount();
  });

  it('MARKS a pipeline whose result type is not a date (in the operations modal)', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'trigger.submitted_at', type: 'date' },
        pipeline: [{ op: 'date_weekday', args: {} }], // date → number
      },
    });
    await nextTick();

    // Open the operations modal from the chip.
    await wrapper.get('button[aria-label="Edit operations: Submitted at"]').trigger('click');
    await nextTick();

    expect(document.body.querySelector('[data-type-satisfied]')?.getAttribute('data-type-satisfied')).toBe('false');

    wrapper.unmount();
  });

  it('RE-EMITS update:typeError (non-null) for a non-date terminal, and null when it satisfies', async () => {
    const wrapper = mountField({
      operationsCatalog: standardOperationsCatalog(),
      modelValue: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'trigger.submitted_at', type: 'date' },
        pipeline: [{ op: 'date_weekday', args: {} }], // date → number (not a date)
      },
    });
    await nextTick();
    // The field also surfaces its own error skin outside the modal.
    expect(wrapper.find('.next-vov.is-error').exists()).toBe(true);
    let te = wrapper.emitted('update:typeError');
    expect(te).toBeTruthy();
    expect(te![te!.length - 1][0]).not.toBeNull();

    // An identity date ref satisfies `date` → no error, emits null.
    await wrapper.setProps({
      modelValue: { kind: 'variable', ref: { source: 'trigger', path: 'trigger.submitted_at', type: 'date' } },
    });
    await nextTick();
    expect(wrapper.find('.next-vov.is-error').exists()).toBe(false);
    te = wrapper.emitted('update:typeError');
    expect(te![te!.length - 1][0]).toBeNull();

    wrapper.unmount();
  });
});
