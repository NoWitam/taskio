// @vitest-environment happy-dom
// WorkflowScheduleExclusionsPanel.spec — the exceptions disclosure's "skip specific
// dates" flow (§4.5.7), exercised through the REAL DatePicker. The sibling
// WorkflowScheduleBuilder.spec stubs DatePicker to a bare <input>, so the actual
// typed-date parsing (dd.mm.yyyy → ISO yyyy-mm-dd) + the add/chip/remove round-trip
// were never covered end-to-end. Here the builder is mounted with the REAL DatePicker
// (only the AI modal + the strip's DateTimePicker are stubbed) so the exclusion-date
// path is proven against the shipped component. Store preview is mocked; timers are
// fake (the strip's debounced fetch never has to resolve for this flow).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import { emptyScheduleDraft, type ScheduleDraft } from '../workflowSchedule';

const schedulePreview = vi.fn();
vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({ schedulePreview, scheduleAssist: vi.fn() }),
  ScheduleAssistError: class ScheduleAssistError extends Error {},
}));

import WorkflowScheduleBuilder from '../WorkflowScheduleBuilder.vue';

const SCH = en.workflows.schedule;

// Stub ONLY the AI modal + the strip's DateTimePicker — the exclusions DatePicker is
// mounted for real (that is the whole point of this spec).
const AssistModalStub = {
  name: 'WorkflowScheduleAssistModal',
  props: ['open', 'tz'],
  emits: ['apply', 'update:open'],
  setup: () => () => h('div', { class: 'assist-modal-stub' }),
};
const DateTimePickerStub = {
  name: 'DateTimePicker',
  props: ['modelValue', 'ariaLabel'],
  setup: () => () => h('input', { class: 'dtp-stub' }),
};

function page(occurrences: string[], empty = false) {
  return { occurrences, count: occurrences.length, empty, approximate: false };
}

function mountBuilder(draft: ScheduleDraft) {
  const wrapper = mount(WorkflowScheduleBuilder, {
    props: {
      modelValue: draft,
      errors: {},
      'onUpdate:modelValue': (v: ScheduleDraft) => wrapper.setProps({ modelValue: v }),
    },
    global: {
      stubs: {
        WorkflowScheduleAssistModal: AssistModalStub,
        DateTimePicker: DateTimePickerStub,
        // DatePicker is intentionally NOT stubbed.
      },
    },
  });
  return wrapper;
}

type W = VueWrapper;
const draftOf = (w: W) => w.props('modelValue') as ScheduleDraft;

describe('WorkflowScheduleBuilder — exceptions panel (real DatePicker)', () => {
  beforeEach(() => {
    installBrowserMocks();
    vi.useFakeTimers();
    setLocale('en');
    schedulePreview.mockReset();
    schedulePreview.mockResolvedValue(page(['2026-07-13T09:00:00Z']));
  });
  afterEach(() => {
    vi.useRealTimers();
    restoreBrowserMocks();
  });

  it('adds an exclusion date through the real DatePicker, renders a chip, then removes it', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());

    // Open the collapsed "Exceptions" disclosure.
    const header = wrapper.findAll('button').find((b) => b.text().includes(SCH.exclusions.title))!;
    expect(header).toBeTruthy();
    await header.trigger('click');
    await nextTick();

    // Type a full date into the REAL DatePicker input. It live-parses dd.mm.yyyy and
    // publishes the ISO yyyy-mm-dd string to the builder's `newExclusionDate` v-model.
    const dateInput = wrapper
      .findAll('input')
      .find((i) => i.attributes('aria-label') === SCH.exclusions.datesLabel)!;
    expect(dateInput).toBeTruthy();
    await dateInput.setValue('24.12.2026');
    await nextTick();

    // "Add date" enables once a valid date is parsed; clicking commits it to the draft.
    const addBtn = () => wrapper.findAll('button').find((b) => b.text().includes(SCH.exclusions.addDate))!;
    expect(addBtn().attributes('disabled')).toBeUndefined();
    await addBtn().trigger('click');
    await nextTick();

    expect(draftOf(wrapper).exclusions.dates).toEqual(['2026-12-24']);

    // A chip renders for the ISO date with a "Remove date" affordance.
    const chip = wrapper.findAll('li').find((li) => li.text().includes('2026-12-24'))!;
    expect(chip).toBeTruthy();
    const removeBtn = chip.findAll('button').find((b) => b.attributes('aria-label') === SCH.exclusions.removeDate)!;
    expect(removeBtn).toBeTruthy();

    // Removing it empties the draft and drops the chip (the empty hint returns).
    await removeBtn.trigger('click');
    await nextTick();

    expect(draftOf(wrapper).exclusions.dates).toEqual([]);
    expect(wrapper.findAll('li').some((li) => li.text().includes('2026-12-24'))).toBe(false);
    expect(wrapper.text()).toContain(SCH.exclusions.datesEmpty);
    wrapper.unmount();
  });

  it('ignores a partial/unparseable date — nothing is added', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());

    const header = wrapper.findAll('button').find((b) => b.text().includes(SCH.exclusions.title))!;
    await header.trigger('click');
    await nextTick();

    // A partial date never resolves to an ISO value, so "Add date" stays disabled and
    // the draft is untouched.
    const dateInput = wrapper
      .findAll('input')
      .find((i) => i.attributes('aria-label') === SCH.exclusions.datesLabel)!;
    await dateInput.setValue('24.12');
    await nextTick();

    const addBtn = wrapper.findAll('button').find((b) => b.text().includes(SCH.exclusions.addDate))!;
    expect(addBtn.attributes('disabled')).toBeDefined();
    expect(draftOf(wrapper).exclusions.dates).toEqual([]);
    wrapper.unmount();
  });
});
