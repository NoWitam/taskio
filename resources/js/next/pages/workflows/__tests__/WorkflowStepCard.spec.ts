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
import { emptyStepConfig, type StepDraft } from '../workflowEditorModel';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { WorkflowCatalog } from '../types';

// A catalog with an enum + a text + a date variable so the add-on filters have
// something to offer (priority ⇒ enum/text; deadline/windows ⇒ date).
const CATALOG: WorkflowCatalog = {
  variables: [
    { source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'] },
    { source: 'trigger', path: 'fields.title', name: 'Title', type: 'text' },
    { source: 'trigger', path: 'trigger.submitted_at', name: 'Submitted at', type: 'date' },
  ],
  fields: [],
};

/**
 * A MarkdownEditor stub that records the `variables` feed and echoes the input via a
 * plain textarea (so `update:modelValue` still flows through the card).
 */
const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['modelValue', 'variables', 'hideToolbar', 'minHeight', 'maxHeight', 'placeholder', 'ariaLabel'],
  emits: ['update:modelValue'],
  setup(props: Record<string, unknown>, { emit }: { emit: (e: string, v: unknown) => void }) {
    return () =>
      h('textarea', {
        class: 'md-stub',
        'data-var-count': String(((props.variables as { variables?: unknown[] })?.variables ?? []).length),
        'aria-label': props.ariaLabel,
        value: props.modelValue,
        onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
      });
  },
};

function mountCard(step: StepDraft, overrides: Record<string, unknown> = {}) {
  return mount(WorkflowStepCard, {
    attachTo: document.body,
    global: { stubs: { MarkdownEditor: MarkdownEditorStub } },
    props: {
      step,
      index: 0,
      total: 1,
      catalog: CATALOG,
      steps: [step],
      position: 0,
      errors: {},
      duplicateKey: false,
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

  it('feeds the catalog variables into the title editor', () => {
    const step = taskStep();
    const wrapper = mountCard(step);
    // The title MarkdownEditor stub received all 3 catalog variables.
    const editors = wrapper.findAll('.md-stub');
    expect(editors.length).toBeGreaterThanOrEqual(1);
    expect(editors[0].attributes('data-var-count')).toBe('3');
    wrapper.unmount();
  });

  it('priority add-on emits a variable union into config', async () => {
    const step = taskStep();
    const wrapper = mountCard(step);

    // Flip the priority field to variable mode (the braces toggle) + pick a variable.
    await wrapper.get('button[aria-pressed]').trigger('click');
    await nextTick();
    await wrapper.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    const options = document.body.querySelectorAll<HTMLElement>('[role="option"]');
    // enum + text are the compatible types → 2 options (Status, Title).
    expect(options.length).toBe(2);
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

  it('renders a required form picker + the name editor with variables', () => {
    const step = reportStep();
    const wrapper = mountCard(step);

    // The form field is required (its FormField label carries the asterisk marker).
    expect(wrapper.findComponent({ name: 'FormSelect' }).exists()).toBe(true);
    // The name editor (a stub) received the catalog variables.
    const editors = wrapper.findAll('.md-stub');
    expect(editors[0].attributes('data-var-count')).toBe('3');

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
