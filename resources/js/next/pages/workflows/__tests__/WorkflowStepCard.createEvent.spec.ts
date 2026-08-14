// @vitest-environment happy-dom
// WorkflowStepCard.createEvent.spec — the `create_event` step editor (R3 B4).
//
// `buildStepConfig`'s payload shaping is already pinned in `workflowEditorModel.spec.ts`. What
// is NOT covered anywhere, and is what this file is for, is the CARD:
//
//   THE SWITCH MOVES THE REQUIRED FIELD. `all_day` decides which date field the server demands
//   and which it FORBIDS. On screen that means one branch replaces the other — not "both, one
//   greyed out". A card that showed the wrong branch would let an author fill a field that is
//   dropped before it reaches the wire, and the run would then fail on a field they did fill.
//
//   FLIPPING IT MUST NOT DESTROY THE DRAFT. Both groups stay in the config; only the chosen one
//   is emitted. This is the same rule the calendar drawer follows, and it is asserted on the
//   CONFIG rather than on the DOM, because a value that survives only on screen is not a value.
//
//   ERRORS LAND ON THE FIELD THAT CAUSED THEM. The server answers `config.start_date` /
//   `config.starts_at`; the card has to route each to its own control, including the flag the
//   value-or-variable child needs to show its own invalid state.
//
//   `all_day` IS A LITERAL, NOT A VARIABLE. It is the discriminator; a run-time value would make
//   the definition unvalidatable at write time. It gets a Switch and must never grow a picker.
//
//   THE SWITCH ALSO MOVES THE GRANULARITY. The timed rows name a MOMENT and their literal control
//   must be able to say an hour; the all-day row names a DAY and must not pretend to. A day-only
//   control under "Starts" cannot express the field it asks for, and the value it emits is read as
//   midnight — the PREVIOUS square for every workspace west of Greenwich. Pinned on the prop the
//   card passes down, because both halves of the mistake (missing on the timed rows, present on
//   the all-day one) render perfectly well.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { h, nextTick, reactive } from 'vue';
import WorkflowStepCard from '../WorkflowStepCard.vue';
import { buildStepConfig, emptyStepConfig, type StepDraft } from '../workflowEditorModel';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { WorkflowCatalog } from '../types';

const CATALOG: WorkflowCatalog = {
  variables: [{ source: 'trigger', path: 'fields.when', name: 'When', type: 'date' }],
  fields: [],
  operations: [],
};

/**
 * The date value-or-variable control, stubbed to a marker that reports which field it is and
 * whether the card told it an external error is present. Its own behaviour has a spec of its
 * own; here it would only be a way for this file to fail for unrelated reasons.
 */
const DateFieldStub = {
  name: 'DateOrVariableField',
  // Declared as an OBJECT, not the usual array, for one reason: `withTime` must be typed
  // `Boolean` so the stub casts a bare `with-time` attribute exactly as the real component's
  // `withDefaults(…, { withTime: false })` does. An array declaration would hand the stub the
  // empty string, and the card would look as though it had passed nothing.
  props: {
    modelValue: { type: null, default: null },
    variables: { type: Array, default: () => [] },
    operationsCatalog: { type: Array, default: () => [] },
    argVariables: { type: Array, default: () => [] },
    withTime: { type: Boolean, default: false },
    externalErrorPresent: { type: Boolean, default: false },
    pickerLabel: { type: String, default: '' },
    dateLabel: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  setup: (props: Record<string, unknown>) => () =>
    h('div', {
      class: 'date-stub',
      'data-label': String(props.pickerLabel ?? ''),
      'data-with-time': props.withTime ? 'true' : 'false',
      'data-external-error': props.externalErrorPresent ? 'true' : 'false',
    }),
};

function eventStep(config: Record<string, unknown> = {}): StepDraft {
  return reactive({
    uid: 'e1',
    type: 'create_event',
    key: 'event',
    config: { ...emptyStepConfig('create_event'), ...config },
  }) as StepDraft;
}

function mountCard(step: StepDraft, overrides: Record<string, unknown> = {}) {
  return mount(WorkflowStepCard, {
    attachTo: document.body,
    global: { stubs: { DateOrVariableField: DateFieldStub } },
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

/** The stubbed date controls currently on screen, by their label. */
function dateFields(wrapper: ReturnType<typeof mountCard>): string[] {
  return wrapper.findAll('.date-stub').map((el) => el.attributes('data-label') ?? '');
}

beforeEach(() => {
  setActivePinia(createPinia());
  installBrowserMocks();
});

afterEach(() => {
  restoreBrowserMocks();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('WorkflowStepCard — create_event: the discriminator moves the required field', () => {
  it('shows the TIMED pair by default — a start and an optional end, no day field', () => {
    const step = eventStep();
    const wrapper = mountCard(step);

    expect(step.config.all_day).toBe(false);
    expect(dateFields(wrapper)).toEqual(['Starts', 'Ends']);
    expect(dateFields(wrapper)).not.toContain('Day');
    wrapper.unmount();
  });

  it('replaces the pair with the single DAY field when the switch goes on', async () => {
    const step = eventStep();
    const wrapper = mountCard(step);

    const toggle = wrapper.find('[role="switch"]');
    expect(toggle.exists()).toBe(true);

    await toggle.trigger('click');
    await nextTick();

    // Replaced, not merely hidden: the timed fields are FORBIDDEN on the wire, so leaving them
    // on screen would invite the author to fill something that gets dropped.
    expect(dateFields(wrapper)).toEqual(['Day']);
    expect(step.config.all_day).toBe(true);
    wrapper.unmount();
  });

  it('marks exactly the required field of the ACTIVE branch, in both branches', async () => {
    const step = eventStep();
    const wrapper = mountCard(step);

    // Timed: the start is required, the end is not.
    const timedRequired = wrapper
      .findAll('label')
      .filter((l) => l.text().includes('*'))
      .map((l) => l.text());
    expect(timedRequired.some((l) => l.includes('Starts'))).toBe(true);
    expect(timedRequired.some((l) => l.includes('Ends'))).toBe(false);

    await wrapper.find('[role="switch"]').trigger('click');
    await nextTick();

    const dayRequired = wrapper
      .findAll('label')
      .filter((l) => l.text().includes('*'))
      .map((l) => l.text());
    expect(dayRequired.some((l) => l.includes('Day'))).toBe(true);
    wrapper.unmount();
  });

  /**
   * Both groups stay in the DRAFT while only one reaches the wire. Asserted on the config and
   * then through `buildStepConfig`, because that is where the two facts have to agree: nothing
   * is lost, and nothing forbidden is sent.
   */
  it('keeps BOTH time groups in the draft across a flip, and still emits only one', async () => {
    const step = eventStep({
      starts_at: { kind: 'literal', value: '2026-08-10 09:00' },
      ends_at: { kind: 'literal', value: '2026-08-10 10:00' },
      start_date: { kind: 'literal', value: '2026-08-12' },
      title: 'Sprint review',
    });
    const wrapper = mountCard(step);

    await wrapper.find('[role="switch"]').trigger('click');
    await nextTick();

    // Nothing was destroyed by the flip…
    expect(step.config.starts_at).toEqual({ kind: 'literal', value: '2026-08-10 09:00' });
    expect(step.config.ends_at).toEqual({ kind: 'literal', value: '2026-08-10 10:00' });

    // …and the wire carries the ALL-DAY group alone.
    const allDayWire = buildStepConfig(step);
    expect(allDayWire).toHaveProperty('start_date');
    expect(allDayWire).not.toHaveProperty('starts_at');
    expect(allDayWire).not.toHaveProperty('ends_at');

    await wrapper.find('[role="switch"]').trigger('click');
    await nextTick();

    expect(dateFields(wrapper)).toEqual(['Starts', 'Ends']);
    const timedWire = buildStepConfig(step);
    expect(timedWire).toHaveProperty('starts_at');
    expect(timedWire).not.toHaveProperty('start_date');
    wrapper.unmount();
  });

  /**
   * The granularity follows the same switch as the required field, and for the same reason: the
   * step is either creating a DAY-shaped event or a MOMENT-shaped one, and each control has to
   * be able to say exactly what its label promises — no more, no less.
   */
  it('gives the timed rows a control that can state an HOUR — both of them', () => {
    const wrapper = mountCard(eventStep());

    const withTime = (label: string) =>
      wrapper.find(`.date-stub[data-label="${label}"]`).attributes('data-with-time');

    expect(withTime('Starts')).toBe('true');
    expect(withTime('Ends')).toBe('true');
    wrapper.unmount();
  });

  it('leaves the all-day row a DAY control — an all-day event has no hour and no zone', async () => {
    const step = eventStep();
    const wrapper = mountCard(step);

    await wrapper.find('[role="switch"]').trigger('click');
    await nextTick();

    expect(dateFields(wrapper)).toEqual(['Day']);
    expect(wrapper.find('.date-stub[data-label="Day"]').attributes('data-with-time')).toBe('false');
    wrapper.unmount();
  });

  it('never offers a variable for the discriminator — it is a literal Switch', async () => {
    const step = eventStep();
    const wrapper = mountCard(step);

    // The three date rows are value-or-variable; `all_day` is not one of them.
    expect(dateFields(wrapper)).not.toContain('All day');
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(1);
    wrapper.unmount();
  });

  /**
   * NO COLOUR CONTROL — the step used to offer the calendar's six values, and the pick meant
   * nothing.
   *
   * A calendar colour states a MEANING: a task deadline is coloured by its priority, a
   * workflow run by its result, a schedule by the one colour that says "this is a projection,
   * not a fact". An event states none, so the grid gives every event the same server-assigned
   * constant. `allowedStepKeys` now REFUSES a `color` key outright, which is why this is
   * asserted rather than left to the eye: a picker put back here would not be a cosmetic
   * regression, it would 422 every save of a workflow that has this step in it.
   */
  it('offers NO colour control — an event has no meaning to colour by', () => {
    const step = eventStep();
    const wrapper = mountCard(step);

    const colour = wrapper
      .findAllComponents({ name: 'Select' })
      .find((s) => s.props('ariaLabel') === 'Colour');
    expect(colour).toBeUndefined();
    expect(wrapper.text()).not.toContain('Colour');
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — create_event: server errors land on their own field', () => {
  it('routes `config.start_date` to the day control in the all-day branch', () => {
    const step = eventStep({ all_day: true });
    const wrapper = mountCard(step, { errors: { 'config.start_date': 'Pick the day this event falls on.' } });

    expect(wrapper.text()).toContain('Pick the day this event falls on.');

    const day = wrapper.find('.date-stub[data-label="Day"]');
    expect(day.attributes('data-external-error')).toBe('true');
    wrapper.unmount();
  });

  it('routes `config.starts_at` to the start control, and leaves the end alone', () => {
    const step = eventStep();
    const wrapper = mountCard(step, { errors: { 'config.starts_at': 'This step needs a start.' } });

    expect(wrapper.text()).toContain('This step needs a start.');
    expect(wrapper.find('.date-stub[data-label="Starts"]').attributes('data-external-error')).toBe('true');
    expect(wrapper.find('.date-stub[data-label="Ends"]').attributes('data-external-error')).toBe('false');
    wrapper.unmount();
  });

  it('routes `config.title` to the title field rather than to a date one', () => {
    const step = eventStep();
    const wrapper = mountCard(step, { errors: { 'config.title': 'Give the event a title.' } });

    expect(wrapper.text()).toContain('Give the event a title.');
    expect(wrapper.find('.date-stub[data-label="Starts"]').attributes('data-external-error')).toBe('false');
    wrapper.unmount();
  });

  it('names the two outputs a later step can reference', () => {
    const step = eventStep();
    const wrapper = mountCard(step);

    // The step publishes `event_id` + `title` under its own key; an author who cannot see the
    // handles cannot chain anything onto the event they just created.
    expect(wrapper.text()).toContain('event_id');
    expect(wrapper.text()).toContain('event');
    wrapper.unmount();
  });
});
