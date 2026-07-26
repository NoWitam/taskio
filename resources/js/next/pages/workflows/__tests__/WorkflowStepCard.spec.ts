// @vitest-environment happy-dom
// WorkflowStepCard.spec — one step card (§4.6). Behaviour-focused: the card's job is
// to render the right type-specific controls, FEED the variable catalog into the
// editors + add-ons, and mutate `step.config` with the EXACT wire shapes. The
// MarkdownEditor is STUBBED (its Tiptap internals are tested elsewhere) so we can
// assert the `variables` feed it receives without a heavy editor mount. The add-on
// pickers reuse the real ValueOrVariableField/DateOrVariableField; catalog variables
// are injected via the `catalog` prop. Mirrors the PipelineSelect.spec teleport/
// option conventions + the ValueOrVariableField.spec union assertions.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { h } from 'vue';
import WorkflowStepCard from '../WorkflowStepCard.vue';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import { emptyStepConfig, type StepDraft } from '../workflowEditorModel';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { WorkflowCatalog } from '../types';

// A catalog with an enum + a text + a date variable so the add-on filters have
// something to offer (priority ⇒ enum/text; deadline/windows ⇒ date), PLUS the SF1
// additions: the `operations` descriptors (fed to the editors' pipeline) and the
// label-less `ai_personas` catalog (localized to the ai-text persona Select).
const CATALOG: WorkflowCatalog = {
  variables: [
    { source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'] },
    { source: 'trigger', path: 'fields.title', name: 'Title', type: 'text' },
    { source: 'trigger', path: 'trigger.submitted_at', name: 'Submitted at', type: 'date' },
  ],
  fields: [],
  operations: [
    { id: 'text_uppercase', input: 'text', output: 'text', args: [] },
    { id: 'enum_to_text', input: 'enum', output: 'text', args: [] },
  ],
  ai_personas: [{ id: 'neutral' }, { id: 'friendly' }, { id: 'formal' }, { id: 'concise' }],
};

/**
 * A MarkdownEditor stub that records the `variables` feed (variable count + the
 * operations catalog size) plus the if-block / ai-text feature toggles, and echoes the
 * input via a plain textarea (so `update:modelValue` still flows through the card).
 */
const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['modelValue', 'variables', 'ifBlocks', 'aiText', 'hideToolbar', 'minHeight', 'maxHeight', 'placeholder', 'ariaLabel'],
  emits: ['update:modelValue'],
  setup(props: Record<string, unknown>, { emit }: { emit: (e: string, v: unknown) => void }) {
    return () => {
      const variables = props.variables as { variables?: unknown[]; operationsCatalog?: unknown[] } | undefined;
      const aiText = props.aiText as { personas?: Array<{ label: string }> } | undefined;
      return h('textarea', {
        class: 'md-stub',
        'data-var-count': String((variables?.variables ?? []).length),
        'data-op-count': String((variables?.operationsCatalog ?? []).length),
        'data-if-blocks': props.ifBlocks ? 'true' : 'false',
        'data-ai-text': props.aiText ? 'true' : 'false',
        'data-ai-personas': (aiText?.personas ?? []).map((p) => p.label).join(','),
        'aria-label': props.ariaLabel,
        value: props.modelValue,
        onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
      });
    };
  },
};

/** Build a `@[variable]("<escaped-json>")` directive the way the editor serializes it. */
function variableDirective(data: { id: string; name: string }): string {
  const payload = JSON.stringify({ v: 1, data: { locked: false, pipeline: [], resultType: 'text', type: 'text', ...data } });
  return `@[variable]("${payload.replace(/"/g, '\\"')}")`;
}

function mountCard(step: StepDraft, overrides: Record<string, unknown> = {}) {
  return mount(WorkflowStepCard, {
    attachTo: document.body,
    global: { stubs: { MarkdownEditor: MarkdownEditorStub } },
    props: {
      step,
      index: 0,
      total: 1,
      catalog: CATALOG,
      triggerType: null,
      steps: [step],
      position: 0,
      errors: {},
      duplicateKey: false,
      expanded: true,
      ...overrides,
    },
  });
}

function taskStep(config: Record<string, unknown> = {}): StepDraft {
  return reactive({ uid: 's1', type: 'create_task', key: 'task', config: { ...emptyStepConfig('create_task'), ...config } });
}
function reportStep(config: Record<string, unknown> = {}): StepDraft {
  return reactive({ uid: 's1', type: 'create_form_report', key: 'report', config: { ...emptyStepConfig('create_form_report'), ...config } });
}

describe('WorkflowStepCard — create_task', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('feeds the catalog variables + a NON-EMPTY operations catalog into the title editor', () => {
    const step = taskStep();
    const wrapper = mountCard(step);
    // The title MarkdownEditor stub received all 3 catalog variables + the ops catalog.
    const editors = wrapper.findAll('.md-stub');
    expect(editors.length).toBeGreaterThanOrEqual(1);
    expect(editors[0].attributes('data-var-count')).toBe('3');
    expect(Number(editors[0].attributes('data-op-count'))).toBeGreaterThan(0);
    // SF3.6: the TITLE now carries the FULL power too — if-blocks + ai-text on.
    expect(editors[0].attributes('data-if-blocks')).toBe('true');
    expect(editors[0].attributes('data-ai-text')).toBe('true');
    wrapper.unmount();
  });

  it('enables if-blocks + ai-text on EVERY text editor with localized personas', () => {
    const step = taskStep();
    const wrapper = mountCard(step);
    const editors = wrapper.findAll('.md-stub');
    // editors[0] = title, editors[1] = description — both carry the full power (SF3.6).
    expect(editors[0].attributes('data-if-blocks')).toBe('true');
    expect(editors[0].attributes('data-ai-text')).toBe('true');
    expect(editors[1].attributes('data-if-blocks')).toBe('true');
    expect(editors[1].attributes('data-ai-text')).toBe('true');
    // The label-less ai_personas ids are localized to their i18n labels.
    expect(editors[1].attributes('data-ai-personas')).toBe('Neutral,Friendly,Formal,Concise');
    wrapper.unmount();
  });

  it('priority add-on emits a variable union into config', async () => {
    const step = taskStep();
    const wrapper = mountCard(step);

    // Flip the priority field to variable mode (the compact Value|Variable toggle) + pick.
    const priority = wrapper.findAllComponents(ValueOrVariableField)[0];
    await priority.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();
    await priority.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    // The Variable picker is the shared inline TREE (B3); these are flat leaf treeitems.
    const options = document.body.querySelectorAll<HTMLElement>('[role="treeitem"]');
    // SHOW-ALL: the picker no longer pre-filters by type — every referenceable
    // variable is offered (Status, Title, Submitted at) and the user coerces it.
    expect(options.length).toBe(3);
    options[0].click();
    await nextTick();

    // The card wrote a {kind:'variable', ref} union with the TRUE enum type.
    expect(step.config.priority).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.status', type: 'enum' },
    });
    wrapper.unmount();
  });

  it('assignee writes BOTH keys when a target is picked and NEITHER when cleared', async () => {
    const step = taskStep();
    const wrapper = mountCard(step);

    // The default segment is `user` → the UserSelect is shown. Drive its model
    // directly (the picker's async list is not needed to assert the both-or-neither wire).
    const userSelect = wrapper.findComponent({ name: 'UserSelect' });
    expect(userSelect.exists()).toBe(true);

    userSelect.vm.$emit('update:modelValue', 'user-123');
    await nextTick();
    expect(step.config.assignee_type).toBe('user');
    expect(step.config.assignee_id).toBe('user-123');

    // Clearing the picker drops BOTH keys.
    userSelect.vm.$emit('update:modelValue', null);
    await nextTick();
    expect(step.config.assignee_type).toBeNull();
    expect(step.config.assignee_id).toBeNull();

    wrapper.unmount();
  });

  it('switching the assignee segment to bot clears a stale id (both-or-neither)', async () => {
    const step = taskStep({ assignee_type: 'user', assignee_id: 'user-1' });
    const wrapper = mountCard(step);

    // Segment control: click the "Bot" radio.
    const botRadio = wrapper.findAll('[role="radio"]').find((r) => r.text().includes('Bot'));
    expect(botRadio).toBeTruthy();
    await botRadio!.trigger('click');
    await nextTick();

    // The stale user id + type were cleared; the Bot picker is now shown.
    expect(step.config.assignee_type).toBeNull();
    expect(step.config.assignee_id).toBeNull();
    expect(wrapper.findComponent({ name: 'BotSelect' }).exists()).toBe(true);

    wrapper.unmount();
  });
});

describe('WorkflowStepCard — create_form_report', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders a required form picker + the name/guidelines editors with the full power', () => {
    const step = reportStep();
    const wrapper = mountCard(step);

    // The form field is required (its FormField label carries the asterisk marker).
    expect(wrapper.findComponent({ name: 'FormSelect' }).exists()).toBe(true);
    // editors[0] = name, editors[1] = guidelines — both carry the full power (SF3.6).
    const editors = wrapper.findAll('.md-stub');
    expect(editors[0].attributes('data-var-count')).toBe('3');
    expect(Number(editors[0].attributes('data-op-count'))).toBeGreaterThan(0);
    expect(editors[0].attributes('data-if-blocks')).toBe('true');
    expect(editors[0].attributes('data-ai-text')).toBe('true');
    expect(editors[1].attributes('data-if-blocks')).toBe('true');
    expect(editors[1].attributes('data-ai-text')).toBe('true');

    wrapper.unmount();
  });

  it('toggling the report source checkboxes writes the task/form subset (report vocabulary)', async () => {
    const step = reportStep();
    const wrapper = mountCard(step);

    const boxes = wrapper.findAll('input[type="checkbox"]');
    // Two report-source checkboxes: task, form.
    expect(boxes.length).toBe(2);

    await boxes[0].setValue(true); // task
    await nextTick();
    expect(step.config.sources).toEqual(['task']);

    await boxes[1].setValue(true); // form
    await nextTick();
    expect(step.config.sources).toEqual(['task', 'form']);

    // Unchecking both ⇒ null (the builder then omits `sources`).
    await boxes[0].setValue(false);
    await boxes[1].setValue(false);
    await nextTick();
    expect(step.config.sources).toBeNull();

    wrapper.unmount();
  });

  it('shows the server-default hints for the optional submission windows', () => {
    const step = reportStep();
    const wrapper = mountCard(step);
    expect(wrapper.text()).toContain('Defaults to the form’s enable date.');
    expect(wrapper.text()).toContain('Defaults to today.');
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — collapse + summary (SF2)', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('collapsed shows a one-line summary and hides the editor body', () => {
    const step = taskStep({ title: 'Ship the thing' });
    const wrapper = mountCard(step, { expanded: false });

    // The summary text rides the row; the toggle reports collapsed.
    expect(wrapper.text()).toContain('Ship the thing');
    expect(wrapper.get('button[aria-expanded]').attributes('aria-expanded')).toBe('false');
    // The heavy editor body (the MarkdownEditor stubs) is NOT rendered while collapsed.
    expect(wrapper.find('.md-stub').exists()).toBe(false);

    wrapper.unmount();
  });

  it('summary strips a variable directive to the catalog name (never raw bytes)', () => {
    const step = taskStep({ title: `For ${variableDirective({ id: 'fields.title', name: 'stale' })}` });
    const wrapper = mountCard(step, { expanded: false });
    // The catalog is authoritative → "Title", and never the raw @[variable] bytes.
    expect(wrapper.text()).toContain('For Title');
    expect(wrapper.text()).not.toContain('@[variable]');
    wrapper.unmount();
  });

  it('falls back to a type summary when the title is still blank', () => {
    const step = taskStep();
    const wrapper = mountCard(step, { expanded: false });
    expect(wrapper.text()).toContain('New task');
    wrapper.unmount();
  });

  it('the toggle emits `toggle` for the list editor to open/close the card', async () => {
    const step = taskStep();
    const wrapper = mountCard(step, { expanded: false });
    await wrapper.get('button[aria-expanded]').trigger('click');
    expect(wrapper.emitted('toggle')).toHaveLength(1);
    wrapper.unmount();
  });

  it('keeps reorder + remove controls on the COLLAPSED row', async () => {
    const step = taskStep();
    const wrapper = mountCard(step, { expanded: false, index: 1, total: 3 });

    // Remove is present (total > 1) and emits.
    const remove = wrapper.get('button[aria-label="Remove step"]');
    await remove.trigger('click');
    expect(wrapper.emitted('remove')).toHaveLength(1);

    // ▲ (up) emits move(-1) from a collapsed row.
    const up = wrapper.get('button[aria-label="Move step 2 up"]');
    await up.trigger('click');
    expect(wrapper.emitted('move')?.[0]).toEqual([-1]);

    wrapper.unmount();
  });

  it('marks the row with an error badge when the card carries an error', () => {
    const step = taskStep();
    const wrapper = mountCard(step, { expanded: false, errors: { 'config.title': 'Required' } });
    expect(wrapper.text()).toContain('Has errors');
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — step key normalization (SF2)', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('strips dots/spaces as the key is typed (path-safe charset only)', async () => {
    const step = taskStep();
    const wrapper = mountCard(step);

    const keyInput = wrapper.get('input[aria-label="Key"]');
    await keyInput.setValue('my step.key');
    // Only [A-Za-z0-9_] survives.
    expect(step.key).toBe('mystepkey');

    await keyInput.setValue('valid_key_2');
    expect(step.key).toBe('valid_key_2');

    wrapper.unmount();
  });
});
