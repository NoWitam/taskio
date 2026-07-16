// @vitest-environment happy-dom
// WorkflowRunTimeline.spec — the run-detail trigger context + step outputs + retry.
//   • B6/B7 form_submitted branch: the trigger becomes a LEAN Form item (composed from
//     the run-show `form` block — no extra fetch; whole-card link, opens in a NEW TAB)
//     + a Submission card (opens the diff drawer). The two cards STACK vertically (A)
//     in the narrow drawer.
//   • B: a step's OUTPUT renders as a resource ITEM (a task/report EntityCard), not raw
//     key→value rows — a create_task step → a task item that opens the task in a new tab.
//   • E: a FAILED run shows a "Run again" button that calls the store retry action,
//     handles the 202 (emits `retried` with the new run), and surfaces the 422 messages.
// The runs store + vue-router + useToast are mocked; the diff drawer is stubbed.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import WorkflowRunTimeline from '../WorkflowRunTimeline.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// --- Runs store mock --------------------------------------------------------
const fetchRun = vi.fn();
const retryRun = vi.fn();
vi.mock('../../../app/stores/workflowRuns', () => ({
  useWorkflowRunsStore: () => ({ fetchRun, retryRun }),
}));

// --- Toast mock -------------------------------------------------------------
const toastSuccess = vi.fn();
const toastDanger = vi.fn();
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({
    success: toastSuccess,
    danger: toastDanger,
    info: vi.fn(),
    warning: vi.fn(),
    show: vi.fn(),
    dismiss: vi.fn(),
    clear: vi.fn(),
  }),
}));

// --- Router mock (href resolution for form + task cards) --------------------
vi.mock('vue-router', () => ({
  useRouter: () => ({
    resolve: (loc: { name?: string; params?: { id?: string }; query?: Record<string, string> }) => {
      if (loc.name === 'next.tasks') return { href: `/next/tasks?task=${loc.query?.task ?? ''}` };
      if (loc.name === 'next.forms.reports')
        return { href: `/next/forms/${loc.params?.id ?? ''}/reports?report=${loc.query?.report ?? ''}` };
      return { href: `/next/forms/${loc.params?.id ?? ''}` };
    },
  }),
}));

const formRun = {
  id: 'run-1',
  state: 'completed',
  state_label: 'Completed',
  state_tone: 'success',
  origin: 'event',
  trigger_type: 'form_submitted',
  depth: 0,
  origin_run_id: null,
  error: null,
  started_at: '2026-07-02T14:00:00Z',
  finished_at: '2026-07-02T14:00:05Z',
  created_at: '2026-07-02T14:00:00Z',
  duration_seconds: 5,
  // Resolved live server-side in the run-show body (no extra fetch).
  form: { id: 'form-1', name: 'Contact form', description: 'Reach the team', icon: null },
  trigger_payload: {
    form: { id: 'form-1', name: 'Contact form', is_anonymous: false },
    submission: { id: 'sub-1' },
    source: 'manual',
    submitted_at: '2026-07-02T14:00:00Z',
    fields: { name: 'Ada' },
  },
  steps: [],
};

// A run whose steps produced a task + a report (manual/schedule so the trigger context
// is NOT a form — the only EntityCards on screen are the two step resource items).
const stepRun = {
  id: 'run-3',
  state: 'completed',
  state_label: 'Completed',
  state_tone: 'success',
  origin: 'manual',
  trigger_type: 'schedule',
  depth: 0,
  origin_run_id: null,
  error: null,
  started_at: '2026-07-02T14:00:00Z',
  finished_at: '2026-07-02T14:00:05Z',
  created_at: '2026-07-02T14:00:00Z',
  duration_seconds: 5,
  trigger_payload: { scheduled_at: '2026-07-02T14:00:00Z' },
  steps: [
    {
      id: 'step-1',
      position: 0,
      type: 'create_task',
      key: 'make_task',
      status: 'succeeded',
      status_label: 'Succeeded',
      status_tone: 'success',
      payload: { task_id: '019task', title: 'Review the draft' },
      error: null,
      created_at: '2026-07-02T14:00:01Z',
    },
    {
      id: 'step-2',
      position: 1,
      type: 'create_form_report',
      key: 'make_report',
      status: 'succeeded',
      status_label: 'Succeeded',
      status_tone: 'success',
      payload: { report_id: '019rep', report_name: 'Weekly summary' },
      error: null,
      created_at: '2026-07-02T14:00:02Z',
    },
  ],
};

// A run whose create_form_report step output carries `form_id` — the card deep-links to
// the form's reports view honoring `?report=<id>`, opened in a new tab.
const reportRun = {
  ...stepRun,
  id: 'run-4',
  steps: [
    {
      id: 'step-r',
      position: 0,
      type: 'create_form_report',
      key: 'make_report',
      status: 'succeeded',
      status_label: 'Succeeded',
      status_tone: 'success',
      payload: { report_id: '019rep', report_name: 'Weekly summary', form_id: 'form-9' },
      error: null,
      created_at: '2026-07-02T14:00:02Z',
    },
  ],
};

// A SCHEDULE run (origin schedule) carrying its matched fire instant + descriptor — the
// trigger section names the occurrence semantically + shows a formatted date (NOT raw ISO).
const scheduleRun = {
  ...stepRun,
  id: 'run-5',
  origin: 'schedule',
  trigger_type: 'schedule',
  trigger_payload: { scheduled_at: '2026-07-02T14:00:00Z' },
  schedule_descriptor: {
    time: { mode: 'at', at: ['14:00'] },
    day: { mode: 'weekdays', weekdays: [4] },
    tz: 'UTC',
  },
  steps: [],
};

// A FAILED run (manual, retry-eligible) with one failed step.
const failedRun = {
  ...stepRun,
  id: 'run-2',
  state: 'failed',
  state_label: 'Failed',
  state_tone: 'danger',
  error: 'boom',
  steps: [
    {
      id: 'step-x',
      position: 0,
      type: 'create_task',
      key: 'make_task',
      status: 'failed',
      status_label: 'Failed',
      status_tone: 'danger',
      payload: null,
      error: 'create_task step requires a non-empty `title`.',
      created_at: '2026-07-02T14:00:01Z',
    },
  ],
};

function mountTimeline(runId = 'run-1') {
  return mount(WorkflowRunTimeline, {
    attachTo: document.body,
    props: { workflowId: 'wf-1', runId },
    global: { stubs: { SubmissionPreviewDrawer: true } },
  });
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
  fetchRun.mockReset();
  retryRun.mockReset();
  toastSuccess.mockReset();
  toastDanger.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowRunTimeline — form_submitted trigger cards (B7)', () => {
  it('renders the Form card as a whole-card link that opens the form in a new tab', async () => {
    fetchRun.mockResolvedValue(formRun);
    const wrapper = mountTimeline();
    await flushPromises();

    expect(wrapper.text()).toContain('Form submission');
    expect(wrapper.text()).toContain('Contact form');

    const link = wrapper.findAll('a').find((a) => a.attributes('href') === '/next/forms/form-1');
    expect(link).toBeTruthy();
    expect(link?.attributes('target')).toBe('_blank');
    expect(link?.attributes('rel')).toContain('noopener');
  });

  it('opens the submission diff drawer when the Submission card is clicked', async () => {
    fetchRun.mockResolvedValue(formRun);
    const wrapper = mountTimeline();
    await flushPromises();

    const drawer = wrapper.findComponent({ name: 'SubmissionPreviewDrawer' });
    expect(drawer.props('open')).toBe(false);

    // The submission card is the interactive (non-link) EntityCard.
    const cards = wrapper.findAllComponents({ name: 'EntityCard' });
    const submissionCard = cards.find((c) => c.props('href') === undefined);
    expect(submissionCard).toBeTruthy();
    submissionCard!.vm.$emit('click');
    await nextTick();

    expect(drawer.props('open')).toBe(true);
    // The snapshot fields the run saw are handed to the drawer.
    expect(drawer.props('snapshotFields')).toEqual({ name: 'Ada' });
    expect(drawer.props('submissionId')).toBe('sub-1');
  });

  it('does not render the raw trigger payload rows for a form run', async () => {
    fetchRun.mockResolvedValue(formRun);
    const wrapper = mountTimeline();
    await flushPromises();

    expect(wrapper.text()).not.toContain('Trigger payload');
  });

  it('stacks the form + submission cards vertically (A)', async () => {
    fetchRun.mockResolvedValue(formRun);
    const wrapper = mountTimeline();
    await flushPromises();

    const cards = wrapper.findAllComponents({ name: 'EntityCard' });
    expect(cards).toHaveLength(2);
    // The two cards share a vertical-stack wrapper (not a two-column grid).
    const wrapEl = cards[0].element.parentElement as HTMLElement;
    expect(wrapEl.className).toContain('flex-col');
    expect(wrapEl.className).not.toContain('grid-cols-2');
  });
});

describe('WorkflowRunTimeline — step outputs as resource items (B)', () => {
  it('renders a create_task step as a task item that opens the task in a new tab (not raw task_id)', async () => {
    fetchRun.mockResolvedValue(stepRun);
    const wrapper = mountTimeline('run-3');
    await flushPromises();

    // The task title shows; the raw output key does NOT.
    expect(wrapper.text()).toContain('Review the draft');
    expect(wrapper.text()).not.toContain('task_id');

    // A whole-card link to the tasks module honoring `?task=<id>`, opened in a new tab.
    const taskLink = wrapper
      .findAll('a')
      .find((a) => a.attributes('href') === '/next/tasks?task=019task');
    expect(taskLink).toBeTruthy();
    expect(taskLink?.attributes('target')).toBe('_blank');
  });

  it('renders a create_form_report step as a report item (name, not raw report_id)', async () => {
    fetchRun.mockResolvedValue(stepRun);
    const wrapper = mountTimeline('run-3');
    await flushPromises();

    expect(wrapper.text()).toContain('Weekly summary');
    expect(wrapper.text()).not.toContain('report_id');
  });

  it('links the report card to the reports view (?report=) in a new tab when form_id is present', async () => {
    fetchRun.mockResolvedValue(reportRun);
    const wrapper = mountTimeline('run-4');
    await flushPromises();

    const link = wrapper
      .findAll('a')
      .find((a) => a.attributes('href') === '/next/forms/form-9/reports?report=019rep');
    expect(link).toBeTruthy();
    expect(link?.attributes('target')).toBe('_blank');
    expect(link?.attributes('rel')).toContain('noopener');
    expect(wrapper.text()).toContain('Weekly summary');
  });

  it('renders the report card as a static card (no link) when form_id is absent on an older run', async () => {
    fetchRun.mockResolvedValue(stepRun);
    const wrapper = mountTimeline('run-3');
    await flushPromises();

    // The report step in stepRun has no form_id → the card is static (no anchor, no crash).
    const reportCard = wrapper
      .findAllComponents({ name: 'EntityCard' })
      .find((c) => c.text().includes('Weekly summary'));
    expect(reportCard).toBeTruthy();
    expect(reportCard!.props('href')).toBeUndefined();
    expect(reportCard!.find('a').exists()).toBe(false);
  });
});

describe('WorkflowRunTimeline — schedule trigger context (Fix 3)', () => {
  it('shows the semantic reason + a formatted scheduled date, not the raw scheduled_at ISO', async () => {
    fetchRun.mockResolvedValue(scheduleRun);
    const wrapper = mountTimeline('run-5');
    await flushPromises();

    // The schedule reason section (heading + labelled reason) replaces the raw dump.
    expect(wrapper.text()).toContain('Schedule');
    expect(wrapper.text()).toContain('Reason');
    // 2026-07-02 is a Thursday → the descriptor names it "Thursday at 14:00".
    expect(wrapper.text()).toContain('Thursday');
    expect(wrapper.text()).toContain('14:00');
    // A human-formatted scheduled date row is present (labelled), and the RAW ISO is gone.
    expect(wrapper.text()).toContain('Scheduled for');
    expect(wrapper.text()).not.toContain('2026-07-02T14:00:00Z');
    // The generic key→value dump ("scheduled_at" mono key) is NOT rendered for a schedule run.
    expect(wrapper.text()).not.toContain('scheduled_at');
    expect(wrapper.text()).not.toContain('Trigger payload');
  });
});

describe('WorkflowRunTimeline — retry a failed run (E)', () => {
  it('shows the "Run again" button only for a failed run', async () => {
    fetchRun.mockResolvedValue(formRun); // completed
    const completed = mountTimeline('run-1');
    await flushPromises();
    expect(completed.text()).not.toContain('Run again');

    fetchRun.mockResolvedValue(failedRun);
    const failed = mountTimeline('run-2');
    await flushPromises();
    expect(failed.text()).toContain('Run again');
  });

  it('calls retryRun and emits `retried` with the new run on 202', async () => {
    const newRun = { ...failedRun, id: 'run-99', state: 'pending', steps: [] };
    fetchRun.mockResolvedValue(failedRun);
    retryRun.mockResolvedValue(newRun);
    const wrapper = mountTimeline('run-2');
    await flushPromises();

    const btn = wrapper.findAll('button').find((b) => b.text().includes('Run again'));
    await btn!.trigger('click');
    await flushPromises();

    expect(retryRun).toHaveBeenCalledWith('wf-1', 'run-2');
    expect(toastSuccess).toHaveBeenCalled();
    const emitted = wrapper.emitted('retried');
    expect(emitted).toBeTruthy();
    expect(emitted![0][0]).toMatchObject({ id: 'run-99', state: 'pending' });
  });

  it('surfaces the 422 `run` message (run not failed) via a danger toast', async () => {
    fetchRun.mockResolvedValue(failedRun);
    retryRun.mockRejectedValue({ response: { data: { errors: { run: ['x'] } } } });
    const wrapper = mountTimeline('run-2');
    await flushPromises();

    const btn = wrapper.findAll('button').find((b) => b.text().includes('Run again'));
    await btn!.trigger('click');
    await flushPromises();

    expect(toastDanger).toHaveBeenCalledWith('Only a failed run can be retried.');
    expect(wrapper.emitted('retried')).toBeFalsy();
  });

  it('surfaces the 422 `workflow` message (run-budget cap) via a danger toast', async () => {
    fetchRun.mockResolvedValue(failedRun);
    retryRun.mockRejectedValue({ response: { data: { errors: { workflow: ['x'] } } } });
    const wrapper = mountTimeline('run-2');
    await flushPromises();

    const btn = wrapper.findAll('button').find((b) => b.text().includes('Run again'));
    await btn!.trigger('click');
    await flushPromises();

    expect(toastDanger).toHaveBeenCalledWith('This workflow hit its run limit — the retry was blocked.');
  });
});
