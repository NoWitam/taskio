// @vitest-environment happy-dom
// WorkflowTriggerFields.spec — the PER-TYPE trigger fields (§4.3-4.5, 5.1 → B7 wizard).
//
// B7 SPLIT: the trigger-TYPE selector (+ the type-change reset/warning) moved UP to the
// drawer (wizard step 1) — those assertions now live in WorkflowEditorDrawer.spec. This
// spec covers what remains this component's job: the form_submitted panel's
// form/source/anonymous v-model emission (+ the `form-change` notify the drawer reacts
// to), and wiring the schedule assist's apply(draft) into the schedule v-model. `type`
// is a plain READ-ONLY prop now. The heavy schedule sub-components are STUBBED (their
// own specs cover them). FormSelect is stubbed to a native select for deterministic picks.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, h, reactive } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import WorkflowTriggerFields from '../WorkflowTriggerFields.vue';
import { emptyFormTriggerDraft, type FormTriggerDraft } from '../workflowEditorModel';
import { emptyScheduleDraft, type ScheduleDraft } from '../workflowSchedule';
import type { WorkflowTriggerType } from '../types';

// A FormSelect stub: a native <select> whose two options are known form ids so a pick
// is deterministic; emits update:modelValue like the real single-value picker.
const FormSelectStub = {
  name: 'FormSelect',
  props: ['modelValue', 'seed', 'placeholder', 'ariaLabel'],
  emits: ['update:modelValue'],
  setup(props: Record<string, unknown>, { emit }: { emit: (e: string, v: unknown) => void }) {
    return () =>
      h(
        'select',
        {
          class: 'form-select-stub',
          value: (props.modelValue as string) ?? '',
          onChange: (e: Event) => {
            const v = (e.target as HTMLSelectElement).value;
            emit('update:modelValue', v === '' ? null : v);
          },
        },
        [
          h('option', { value: '' }, '—'),
          h('option', { value: 'form-a' }, 'Form A'),
          h('option', { value: 'form-b' }, 'Form B'),
        ],
      );
  },
};

// A schedule builder stub: renders the current v2 time mode (the builder now hosts the
// AI assist internally, so TriggerFields no longer renders an assist sibling).
const ScheduleBuilderStub = {
  name: 'WorkflowScheduleBuilder',
  props: ['modelValue', 'errors', 'tz'],
  setup(props: Record<string, unknown>) {
    return () => h('div', { class: 'builder-stub' }, (props.modelValue as ScheduleDraft)?.time?.mode ?? '');
  },
};

function mountFields(initial: {
  type?: WorkflowTriggerType;
  formConfig?: FormTriggerDraft;
  scheduleDraft?: ScheduleDraft;
} = {}) {
  const state = reactive({
    type: initial.type ?? 'form_submitted',
    formConfig: initial.formConfig ?? emptyFormTriggerDraft(),
    scheduleDraft: initial.scheduleDraft ?? emptyScheduleDraft(),
  });
  const formChanges: Array<string | null> = [];
  const wrapper = mount(WorkflowTriggerFields, {
    attachTo: document.body,
    global: {
      stubs: {
        FormSelect: FormSelectStub,
        WorkflowScheduleBuilder: ScheduleBuilderStub,
      },
    },
    props: {
      // `type` is a plain read-only prop now — the drawer owns type switching.
      type: state.type,
      formConfig: state.formConfig,
      scheduleDraft: state.scheduleDraft,
      errors: {},
      'onUpdate:formConfig': (v: FormTriggerDraft) => {
        state.formConfig = v;
        wrapper.setProps({ formConfig: v });
      },
      'onUpdate:scheduleDraft': (v: ScheduleDraft) => {
        state.scheduleDraft = v;
        wrapper.setProps({ scheduleDraft: v });
      },
      onFormChange: (v: string | null) => formChanges.push(v),
    },
  });
  return { wrapper, state, formChanges };
}

describe('WorkflowTriggerFields', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders the schedule panel (not the form panel) when type is schedule', () => {
    const { wrapper } = mountFields({ type: 'schedule' });

    expect(wrapper.find('.builder-stub').exists()).toBe(true);
    expect(wrapper.find('.form-select-stub').exists()).toBe(false);

    wrapper.unmount();
  });

  it('picking a form emits form_id + a form-change notify; anonymous appears', async () => {
    const { wrapper, state, formChanges } = mountFields();

    // The anonymous tri-state is hidden until a form is selected.
    expect(wrapper.text()).not.toContain('Only anonymous');

    await wrapper.get('.form-select-stub').setValue('form-a');
    await nextTick();

    expect(state.formConfig.form_id).toBe('form-a');
    expect(formChanges).toEqual(['form-a']);
    // Anonymous tri-state is now shown.
    expect(wrapper.text()).toContain('Only anonymous');

    wrapper.unmount();
  });

  it('toggling the source CARDS writes the ordered source subset (multi selection cards)', async () => {
    const { wrapper, state } = mountFields({
      formConfig: { form_id: 'form-a', source: [], anonymous: null },
    });

    // The source group is the same selection-card control as Anonymity, in multi mode.
    const cards = wrapper.findAll('[role="checkbox"]');
    const cardBy = (text: string) => cards.find((c) => c.text().includes(text))!;
    // Toggle task first, then manual → the emitted subset stays in canonical order.
    await cardBy('during a task').trigger('click');
    await nextTick();
    await cardBy('manually').trigger('click');
    await nextTick();

    expect(state.formConfig.source).toEqual(['manual', 'task']);

    wrapper.unmount();
  });

  it('the source "Select all" card selects both sources at once', async () => {
    const { wrapper, state } = mountFields({
      formConfig: { form_id: 'form-a', source: [], anonymous: null },
    });

    const selectAll = wrapper.find('[data-seg-select-all]');
    expect(selectAll.exists()).toBe(true);
    expect(selectAll.attributes('aria-checked')).toBe('false');

    await selectAll.trigger('click');
    await nextTick();
    expect(state.formConfig.source).toEqual(['manual', 'task']);

    // …and toggles back to none (empty ⇒ any source).
    await wrapper.find('[data-seg-select-all]').trigger('click');
    await nextTick();
    expect(state.formConfig.source).toEqual([]);

    wrapper.unmount();
  });

  it('anonymous tri-state maps only_anonymous→true / only_non_anonymous→false / any→null', async () => {
    const { wrapper, state } = mountFields({
      formConfig: { form_id: 'form-a', source: [], anonymous: null },
    });

    const pick = async (label: string) => {
      const radio = wrapper
        .findAll('[role="radio"]')
        .find((r) => r.text().trim() === label);
      await radio!.trigger('click');
      await nextTick();
    };

    await pick('Only anonymous');
    expect(state.formConfig.anonymous).toBe(true);
    await pick('Only non-anonymous');
    expect(state.formConfig.anonymous).toBe(false);
    await pick('Any');
    expect(state.formConfig.anonymous).toBeNull();

    wrapper.unmount();
  });

  it('renders the v2 schedule builder for a schedule trigger', () => {
    const { wrapper } = mountFields({ type: 'schedule' });
    // The stub echoes the draft's v2 time mode (the neutral seed is `at`).
    expect(wrapper.find('.builder-stub').text()).toBe('at');
    wrapper.unmount();
  });
});
