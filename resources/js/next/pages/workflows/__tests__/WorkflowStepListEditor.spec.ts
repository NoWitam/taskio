// @vitest-environment happy-dom
// WorkflowStepListEditor.spec — the ordered step editor (§4.6, the core). Asserts
// (1) the add-step DropdownMenu offers exactly the TWO 5.1 types and adds a
// correctly-shaped card, (2) ▲▼ reorder emits the reordered array, (3) min-1 is
// enforced (no remove on a single card), (4) per-index 422 errors route to the right
// card (`steps.<i>.*` → card i, stripped of the prefix), and (5) a duplicate key is
// flagged on BOTH offending cards. The child WorkflowStepCard is STUBBED to surface
// the props it receives (errors / duplicateKey / index / total) so routing is
// asserted without a heavy card mount. Mirrors the PipelineSelect.spec teleport
// conventions for the teleported DropdownMenu items.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import WorkflowStepListEditor from '../WorkflowStepListEditor.vue';
import { makeStepDraft, type StepDraft } from '../workflowEditorModel';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// A lightweight card stub that echoes the props the list feeds it, so the routing +
// invariants are asserted at the boundary (not through the real card's internals).
const CardStub = {
  name: 'WorkflowStepCard',
  props: ['step', 'index', 'total', 'catalog', 'steps', 'position', 'errors', 'duplicateKey'],
  emits: ['remove', 'move'],
  setup(props: Record<string, unknown>, { emit }: { emit: (e: string, ...a: unknown[]) => void }) {
    return () =>
      h(
        'li',
        {
          class: 'card-stub',
          'data-index': String(props.index),
          'data-total': String(props.total),
          'data-key': (props.step as StepDraft).key,
          'data-dup': String(props.duplicateKey),
          'data-errors': JSON.stringify(props.errors),
        },
        [
          h('button', { class: 'stub-remove', onClick: () => emit('remove') }, 'x'),
          h('button', { class: 'stub-up', onClick: () => emit('move', -1) }, 'up'),
          h('button', { class: 'stub-down', onClick: () => emit('move', 1) }, 'down'),
        ],
      );
  },
};

function twoSteps(): StepDraft[] {
  return [makeStepDraft('create_task', []), makeStepDraft('create_form_report', ['task'])];
}

function mountEditor(steps: StepDraft[], errors: Record<string, string> = {}) {
  return mount(WorkflowStepListEditor, {
    attachTo: document.body,
    global: { stubs: { WorkflowStepCard: CardStub } },
    props: { steps, catalog: null, errors },
  });
}

describe('WorkflowStepListEditor', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('the add-step menu offers exactly the two 5.1 step types and adds a shaped card', async () => {
    const steps = twoSteps();
    const wrapper = mountEditor(steps);

    // Open the add-step dropdown (the trigger button labelled "Add step").
    const trigger = wrapper.findAll('button').find((b) => b.text().includes('Add step'));
    await trigger!.trigger('click');
    await nextTick();

    const items = Array.from(document.body.querySelectorAll<HTMLElement>('[role="menuitem"]'));
    expect(items.map((i) => i.textContent?.trim())).toEqual(['Create task', 'Create form report']);

    // Choosing "Create form report" appends a correctly-typed card with a unique key.
    items[1].click();
    await nextTick();

    const emitted = wrapper.emitted('update:steps');
    const next = emitted?.[emitted.length - 1]?.[0] as StepDraft[];
    expect(next).toHaveLength(3);
    expect(next[2].type).toBe('create_form_report');
    // report is taken by step 2 → the new one is report_2.
    expect(next[2].key).toBe('report_2');
    expect(Object.keys(next[2].config)).toContain('name');

    wrapper.unmount();
  });

  it('▲▼ reorder emits the reordered array', async () => {
    const steps = twoSteps();
    const wrapper = mountEditor(steps);

    // Move card 0 DOWN.
    await wrapper.findAll('.stub-down')[0].trigger('click');
    let emitted = wrapper.emitted('update:steps');
    let next = emitted?.[emitted.length - 1]?.[0] as StepDraft[];
    expect(next.map((s) => s.key)).toEqual(['report', 'task']);

    // Move card 1 UP is a no-op-at-edge for the ORIGINAL list (parent owns state; the
    // component returns the same reference for an edge move).
    await wrapper.findAll('.stub-up')[0].trigger('click'); // card 0 up = edge no-op
    emitted = wrapper.emitted('update:steps');
    next = emitted?.[emitted.length - 1]?.[0] as StepDraft[];
    // Same order (edge no-op returns the same array).
    expect(next.map((s) => s.key)).toEqual(['task', 'report']);

    wrapper.unmount();
  });

  it('enforces min-1: a single card is passed total=1 (no remove affordance)', () => {
    const single = [makeStepDraft('create_task', [])];
    const wrapper = mountEditor(single);
    const card = wrapper.get('.card-stub');
    expect(card.attributes('data-total')).toBe('1');
    wrapper.unmount();
  });

  it('routes per-index 422 errors to the matching card (prefix stripped)', () => {
    const steps = twoSteps();
    const wrapper = mountEditor(steps, {
      'steps.0.key': 'Key required',
      'steps.1.config.form_id': 'Form is required',
      'steps.1.config.name': 'Name is required',
    });

    const cards = wrapper.findAll('.card-stub');
    const errors0 = JSON.parse(cards[0].attributes('data-errors') ?? '{}');
    const errors1 = JSON.parse(cards[1].attributes('data-errors') ?? '{}');
    expect(errors0).toEqual({ key: 'Key required' });
    expect(errors1).toEqual({ 'config.form_id': 'Form is required', 'config.name': 'Name is required' });

    wrapper.unmount();
  });

  it('flags a duplicate key on BOTH offending cards', () => {
    const steps = [makeStepDraft('create_task', []), makeStepDraft('create_task', [])];
    // Force a collision (both `task`).
    steps[1].key = 'task';
    const wrapper = mountEditor(steps);

    const cards = wrapper.findAll('.card-stub');
    expect(cards[0].attributes('data-dup')).toBe('true');
    expect(cards[1].attributes('data-dup')).toBe('true');

    wrapper.unmount();
  });

  it('surfaces a top-level steps error above the list', () => {
    const wrapper = mountEditor(twoSteps(), { steps: 'Add at least one step.' });
    expect(wrapper.get('[role="alert"]').text()).toBe('Add at least one step.');
    wrapper.unmount();
  });
});
