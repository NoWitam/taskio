// @vitest-environment happy-dom
// WorkflowConditionsEditor.spec — the B3 condition TREE builder (rebuilt).
//
// Asserts the FORM GATE (no form ⇒ needsForm Alert, no tree), the recursive render
// (an AND group with two chips + a nested OR group), the logic toggle emit, "Add
// condition" opening the Modal (over a catalog that carries `operations`), removing a
// chip, and the depth-limit disabling the deepest "Add group".
import { describe, it, expect, beforeEach, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import WorkflowConditionsEditor from '../WorkflowConditionsEditor.vue';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogField, WorkflowCatalog } from '../types';
import type { DraftCondition, DraftConditionGroup } from '../workflowConditions';
import type { VariablePipelineStep } from '../../../ui/editor/extensions/types';

const FIELDS: CatalogField[] = [
  { path: 'fields.status', field_id: 'status', label: 'Status', type: 'enum', enumOptions: ['open', 'done'], operators: ['is', 'is_not', 'in'] },
  { path: 'fields.count', field_id: 'count', label: 'Count', type: 'number', operators: ['eq', 'gt'] },
];

// A catalog that carries the operation DESCRIPTORS (mapped from the FE standard
// catalog) — proves the editor runs on catalog.operations, not a hardcoded list.
const OPERATIONS = standardOperationsCatalog().map((op) => ({
  id: op.id,
  input: op.inputTypes[0],
  output: op.outputType,
  args: (op.args ?? []).map((a) => ({ id: a.id, type: a.type, mapType: a.mapType })),
}));
const CATALOG: WorkflowCatalog = { variables: [], fields: FIELDS, operations: OPERATIONS };

/**
 * The same catalog WITH the field variables that carry the structured descriptors — the source of a
 * chip's nullable (`?`) / array (`[]`) markers (`fields.x` ↔ `trigger.fields.x`).
 */
const DESCRIBED_CATALOG: WorkflowCatalog = {
  ...CATALOG,
  variables: [
    {
      source: 'trigger', path: 'trigger.fields.status', name: 'Status', type: 'enum',
      descriptor: { base: 'enum', nullable: true, array: false },
      nullable: true,
    },
    {
      source: 'trigger', path: 'trigger.fields.count', name: 'Count', type: 'number',
      descriptor: { base: 'number', nullable: false, array: false },
    },
  ],
};

function condition(uid: string, source: string, sourceType: CatalogField['type'], op: string, args: Record<string, unknown>): DraftCondition {
  const step: VariablePipelineStep = { stepId: `s-${uid}`, operationId: op, args: args as VariablePipelineStep['args'], outputType: 'boolean' };
  return { uid, kind: 'condition', source, sourceType, pipeline: [step] };
}

/** An AND root with two conditions + a nested OR group holding one condition. */
function sampleTree(): DraftConditionGroup {
  return {
    uid: 'g-root',
    kind: 'group',
    logic: 'and',
    children: [
      condition('c1', 'fields.status', 'enum', 'enum_is', { value: 'open' }),
      condition('c2', 'fields.count', 'number', 'num_gt', { value: 3 }),
      {
        uid: 'g-sub',
        kind: 'group',
        logic: 'or',
        children: [condition('c3', 'fields.status', 'enum', 'enum_is', { value: 'done' })],
      },
    ],
  };
}

function mountEditor(modelValue: DraftConditionGroup, formSelected = true, catalog: WorkflowCatalog = CATALOG) {
  return mount(WorkflowConditionsEditor, {
    attachTo: document.body,
    props: { modelValue, catalog, formSelected },
  });
}

describe('WorkflowConditionsEditor (tree)', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders the form-gated state when no form is selected (Alert, no tree)', () => {
    const wrapper = mountEditor({ uid: 'g', kind: 'group', logic: 'and', children: [] }, false);
    expect(wrapper.text()).toContain('Pick a form to add conditions on its fields.');
    expect(wrapper.find('[role="group"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('renders the tree: an AND group with two chips + a nested OR group', () => {
    const wrapper = mountEditor(sampleTree());
    // Two group cards (root + nested), the two field labels, and the nested OR.
    expect(wrapper.findAll('[role="group"]').length).toBe(2);
    expect(wrapper.text()).toContain('Status');
    expect(wrapper.text()).toContain('Count');
    // Three condition chips → three "Edit condition" buttons.
    const chips = wrapper.findAll('button').filter((b) => (b.attributes('aria-label') ?? '').startsWith('Edit condition:'));
    expect(chips.length).toBe(3);
    wrapper.unmount();
  });

  it('a chip marks a NULLABLE source with the optional "?" marker (a required one has none)', () => {
    const wrapper = mountEditor(sampleTree(), true, DESCRIBED_CATALOG);
    const chips = wrapper.findAll('button').filter((b) => (b.attributes('aria-label') ?? '').startsWith('Edit condition:'));

    // c1 / c3 are on the NULLABLE `fields.status`; c2 is on the required `fields.count`.
    expect(chips[0].find('[data-marker="optional"]').exists()).toBe(true);
    expect(chips[1].find('[data-marker="optional"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('a chip without catalog descriptors still renders its plain type glyph', () => {
    const wrapper = mountEditor(sampleTree());
    const chips = wrapper.findAll('button').filter((b) => (b.attributes('aria-label') ?? '').startsWith('Edit condition:'));
    expect(chips[0].find('[data-marker="optional"]').exists()).toBe(false);
    expect(chips[0].find('svg').exists()).toBe(true);
    wrapper.unmount();
  });

  it('toggling the ROOT group logic emits the updated tree', async () => {
    const wrapper = mountEditor(sampleTree());
    const radiogroups = wrapper.findAll('[role="radiogroup"]');
    const rootAny = radiogroups[0].findAll('[role="radio"]').find((r) => r.text().includes('Any'));
    await rootAny!.trigger('click');
    const emitted = wrapper.emitted('update:modelValue') ?? [];
    const last = emitted[emitted.length - 1]?.[0] as DraftConditionGroup;
    expect(last.logic).toBe('or');
    wrapper.unmount();
  });

  it('"Add condition" opens the condition Modal', async () => {
    const wrapper = mountEditor(sampleTree());
    const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Add condition'));
    await addBtn!.trigger('click');
    await nextTick();
    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).toBeTruthy();
    expect(dialog?.textContent).toContain('Add condition');
    wrapper.unmount();
  });

  it('removing a chip emits the tree without that condition', async () => {
    const wrapper = mountEditor(sampleTree());
    const removeBtn = wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Remove condition');
    await removeBtn!.trigger('click');
    const emitted = wrapper.emitted('update:modelValue') ?? [];
    const last = emitted[emitted.length - 1]?.[0] as DraftConditionGroup;
    // c1 removed → the root keeps c2 + the nested group.
    expect(last.children).toHaveLength(2);
    expect(last.children.some((c) => c.uid === 'c1')).toBe(false);
    wrapper.unmount();
  });

  it('the depth limit disables the deepest "Add group"', () => {
    // A chain nested to depth 5 (root = 1).
    const g5: DraftConditionGroup = { uid: 'g5', kind: 'group', logic: 'and', children: [] };
    const g4: DraftConditionGroup = { uid: 'g4', kind: 'group', logic: 'and', children: [g5] };
    const g3: DraftConditionGroup = { uid: 'g3', kind: 'group', logic: 'and', children: [g4] };
    const g2: DraftConditionGroup = { uid: 'g2', kind: 'group', logic: 'and', children: [g3] };
    const g1: DraftConditionGroup = { uid: 'g1', kind: 'group', logic: 'and', children: [g2] };

    const wrapper = mountEditor(g1);
    const addGroupButtons = wrapper.findAll('button').filter((b) => b.text().includes('Add group'));
    expect(addGroupButtons.length).toBe(5);
    const disabled = addGroupButtons.filter((b) => b.attributes('disabled') !== undefined);
    // Only the depth-5 group can no longer nest.
    expect(disabled.length).toBe(1);
    wrapper.unmount();
  });
});
