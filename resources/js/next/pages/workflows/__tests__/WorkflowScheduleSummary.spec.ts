// @vitest-environment happy-dom
// WorkflowScheduleSummary.spec — the cadence summary band (§4.5.3). Asserts the live
// describeSchedule sentence renders (real i18n) and the "Plan with AI" button emits
// `assist` (the summary owns NO AI logic — the host opens the modal).
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import WorkflowScheduleSummary from '../WorkflowScheduleSummary.vue';
import { emptyScheduleDraft } from '../workflowSchedule';

describe('WorkflowScheduleSummary', () => {
  beforeEach(() => setLocale('en'));

  it('renders the live sentence and emits assist on the AI button', async () => {
    const wrapper = mount(WorkflowScheduleSummary, { props: { draft: emptyScheduleDraft() } });

    expect(wrapper.text()).toContain('Daily at 09:00');

    const assist = wrapper.findAll('button').find((b) => b.text().includes(en.workflows.schedule.summary.assist));
    expect(assist).toBeTruthy();
    await assist!.trigger('click');
    expect(wrapper.emitted('assist')).toBeTruthy();

    wrapper.unmount();
  });

  it('reflects a multi-time draft', () => {
    const draft = emptyScheduleDraft();
    draft.time = { mode: 'at', at: ['08:00', '17:00'] };
    const wrapper = mount(WorkflowScheduleSummary, { props: { draft } });
    expect(wrapper.text()).toContain('At 08:00 and 17:00');
    wrapper.unmount();
  });

  it('shows the "({tz})" clause only for a FOREIGN activeTz (REV5, §4.5.10)', () => {
    const draft = emptyScheduleDraft();
    draft.tz = 'Europe/Warsaw';

    const foreign = mount(WorkflowScheduleSummary, { props: { draft, activeTz: 'UTC' } });
    expect(foreign.text()).toContain('(Europe/Warsaw)');
    foreign.unmount();

    const same = mount(WorkflowScheduleSummary, { props: { draft, activeTz: 'Europe/Warsaw' } });
    expect(same.text()).not.toContain('(Europe/Warsaw)');
    same.unmount();
  });
});
