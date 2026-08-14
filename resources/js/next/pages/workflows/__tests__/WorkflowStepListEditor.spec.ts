// @vitest-environment happy-dom
// WorkflowStepListEditor.spec — the ordered step editor (§4.6, the core). Asserts
// (1) the add-step SELECTION CARDS (SF2) offer exactly the TWO 5.1 types and add a
// correctly-shaped card, (2) the add cards DISABLE at the MAX_STEPS ceiling, (3) ▲▼
// reorder emits the reordered array, (4) min-1 is enforced (no remove on a single
// card), (5) per-index 422 errors route to the right card (`steps.<i>.*` → card i,
// stripped of the prefix), and (6) a duplicate key is flagged on BOTH offending cards.
// The child WorkflowStepCard is STUBBED to surface the props it receives (errors /
// duplicateKey / index / total / expanded) so routing is asserted without a heavy mount.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick } from 'vue';
import WorkflowStepListEditor from '../WorkflowStepListEditor.vue';
import { makeStepDraft, MAX_STEPS, type StepDraft } from '../workflowEditorModel';
import { STEP_TYPES } from '../workflowMeta';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// A lightweight card stub that echoes the props the list feeds it, so the routing +
// invariants are asserted at the boundary (not through the real card's internals).
const CardStub = {
  name: 'WorkflowStepCard',
  props: ['step', 'index', 'total', 'catalog', 'triggerType', 'steps', 'position', 'errors', 'duplicateKey', 'expanded'],
  emits: ['remove', 'move', 'toggle'],
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
          'data-expanded': String(props.expanded),
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
    props: { steps, catalog: null, triggerType: null, errors },
  });
}

describe('WorkflowStepListEditor', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('offers a SELECTION CARD per step type and adds a correctly-shaped card', async () => {
    const steps = twoSteps();
    const wrapper = mountEditor(steps);

    // One add card per step type (not a dropdown), in a stable order. R2 sub-stage 5
    // appended the third, `generate_content`; R3 B4 appended the fourth, `create_event`.
    const cards = wrapper.findAll('button[aria-label^="Add step:"]');
    expect(cards.map((c) => c.attributes('aria-label'))).toEqual([
      'Add step: Create task',
      'Add step: Create form report',
      'Add step: Generate content',
      'Add step: Add calendar event',
    ]);

    // Clicking "Create form report" appends a correctly-typed card with a unique key.
    await cards[1].trigger('click');

    const emitted = wrapper.emitted('update:steps');
    const next = emitted?.[emitted.length - 1]?.[0] as StepDraft[];
    expect(next).toHaveLength(3);
    expect(next[2].type).toBe('create_form_report');
    // report is taken by step 2 → the new one is report_2.
    expect(next[2].key).toBe('report_2');
    expect(Object.keys(next[2].config)).toContain('name');

    wrapper.unmount();
  });

  it('disables the add cards at the MAX_STEPS ceiling (client max:50)', () => {
    const keys: string[] = [];
    const many = Array.from({ length: MAX_STEPS }, () => {
      const draft = makeStepDraft('create_task', keys);
      keys.push(draft.key);
      return draft;
    });
    const wrapper = mountEditor(many);

    const cards = wrapper.findAll('button[aria-label^="Add step:"]');
    expect(cards).toHaveLength(STEP_TYPES.length);
    expect(cards.every((c) => c.attributes('disabled') !== undefined)).toBe(true);

    wrapper.unmount();
  });

  it('disables ONLY the generate_content card once its own per-type cap is reached', async () => {
    // Two generate_content steps is the backend budget guard (GENERATE_CONTENT_MAX); the
    // third would 422 on `steps.<i>.type`, so its add card goes disabled while the other
    // types stay available.
    const keys: string[] = [];
    const steps = ['generate_content', 'generate_content'].map((type) => {
      const draft = makeStepDraft(type as StepDraft['type'], keys);
      keys.push(draft.key);
      return draft;
    });
    const wrapper = mountEditor(steps);

    const cards = wrapper.findAll('button[aria-label^="Add step:"]');
    expect(cards).toHaveLength(STEP_TYPES.length);
    expect(cards[0].attributes('disabled')).toBeUndefined();
    expect(cards[1].attributes('disabled')).toBeUndefined();
    expect(cards[2].attributes('disabled')).toBeDefined();
    // The per-type cap is generate_content's ALONE — a fourth type must stay addable.
    expect(cards[3].attributes('disabled')).toBeUndefined();
    expect(cards[2].attributes('title')).toContain('2');
    // "Disabled actions must remain understandable": the reason is VISIBLE on the card, not
    // only in a `title` tooltip a keyboard/touch user can never reach.
    expect(cards[2].text()).toContain('A workflow can contain at most 2 content-generation steps.');
    expect(cards[0].text()).not.toContain('at most');

    // A click on the capped card is a no-op (no step is appended).
    await cards[2].trigger('click');
    expect(wrapper.emitted('update:steps')).toBeUndefined();

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

// --- Saved-model type-error gate + auto-expand (review findings 2+3) ----------
// These mount the REAL WorkflowStepCard (only MarkdownEditor stubbed) so the card's
// saved-model type-error computed + `type-error` emit path is actually covered — the
// stub above short-circuits it. A 2+-step workflow hydrates with EVERY card collapsed,
// yet the gate must still engage (the cards are always mounted) and the offending card
// must auto-expand so the user is shown where to fix it.
const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['modelValue'],
  emits: ['update:modelValue'],
  setup: () => () => h('textarea', { class: 'md-stub' }),
};

function mountRealEditor(steps: StepDraft[]) {
  return mount(WorkflowStepListEditor, {
    attachTo: document.body,
    global: { stubs: { MarkdownEditor: MarkdownEditorStub } },
    props: { steps, catalog: null, triggerType: null, errors: {} },
  });
}

/** A create_task draft with a chosen key + a priority value (literal or variable union). */
function taskWithPriority(key: string, priority: unknown): StepDraft {
  const draft = makeStepDraft('create_task', []);
  draft.key = key;
  draft.config = { ...draft.config, priority };
  return draft;
}

// An identity ENUM ref for the priority (choice) field: NO pipeline → it never targets
// the priority option set, so the backend rejects it and the FE must pre-block Save.
const IDENTITY_ENUM_PRIORITY = {
  kind: 'variable',
  ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
} as const;

describe('WorkflowStepListEditor — saved-model type gate + auto-expand (findings 2+3)', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('a COLLAPSED 2nd step with an identity-enum priority blocks Save and auto-expands', async () => {
    const good = taskWithPriority('a', null); // literal/unset → satisfied
    const bad = taskWithPriority('b', IDENTITY_ENUM_PRIORITY);
    const wrapper = mountRealEditor([good, bad]);
    await nextTick();
    await nextTick();

    // The gate engaged from the ALWAYS-mounted (initially collapsed) cards → the drawer
    // Save gate is blocked (type-errors true bubbled up WITHOUT expanding anything first).
    const te = wrapper.emitted('type-errors');
    expect(te).toBeTruthy();
    expect(te![te!.length - 1]).toEqual([true]);

    // The offending card auto-expanded (its body rendered); the good card stayed collapsed.
    expect(wrapper.find(`[id="wf-step-body-${bad.uid}"]`).exists()).toBe(true);
    expect(wrapper.find(`[id="wf-step-body-${good.uid}"]`).exists()).toBe(false);

    wrapper.unmount();
  });

  it('a literal/value-mode priority reports NO type error and no card auto-expands', async () => {
    const a = taskWithPriority('a', { kind: 'literal', value: 'high' });
    const b = taskWithPriority('b', { kind: 'literal', value: 'low' });
    const wrapper = mountRealEditor([a, b]);
    await nextTick();
    await nextTick();

    // A literal is always satisfied → every emit is false; the gate never blocks Save.
    const te = wrapper.emitted('type-errors');
    expect(te!.every((e) => e[0] === false)).toBe(true);
    // Both cards stayed collapsed (no error to surface).
    expect(wrapper.find(`[id="wf-step-body-${a.uid}"]`).exists()).toBe(false);
    expect(wrapper.find(`[id="wf-step-body-${b.uid}"]`).exists()).toBe(false);

    wrapper.unmount();
  });
});
