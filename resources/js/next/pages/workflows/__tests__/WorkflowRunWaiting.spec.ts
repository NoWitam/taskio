// @vitest-environment happy-dom
// WorkflowRunWaiting.spec — the `waiting` run state + the generate_content step result
// (R2 sub-stage 5).
//
// `waiting` used to be a RESERVED state the engine never produced. A workflow that reaches
// a SUSPENDING step (`generate_content`) now genuinely parks there, so this spec pins the
// three surfaces the author actually meets:
//   • the runs LIST — `waiting` is a rendered badge AND a selectable filter option,
//   • the run DETAIL — an expectation-setting panel (how long, elapsed as of the last read,
//     an explicit REFRESH because there is no live push, and the honest "no cancel yet"),
//   • the step RESULT — a finished generate_content step deep-links to the produced
//     generation session.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import WorkflowRunRow from '../WorkflowRunRow.vue';
import WorkflowRunTimeline from '../WorkflowRunTimeline.vue';
import Badge from '../../../ui/primitives/Badge.vue';
import EntityCard from '../../../ui/patterns/EntityCard.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { RUN_STATES, type WorkflowRun } from '../types';
import { runStateIcon } from '../workflowMeta';

// --- Runs store mock --------------------------------------------------------
const fetchRun = vi.fn();
const retryRun = vi.fn();
vi.mock('../../../app/stores/workflowRuns', () => ({
  useWorkflowRunsStore: () => ({ fetchRun, retryRun }),
}));

vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({
    success: vi.fn(),
    danger: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    show: vi.fn(),
    dismiss: vi.fn(),
    clear: vi.fn(),
  }),
}));

// --- Router mock (href resolution for the session deep link) ----------------
vi.mock('vue-router', () => ({
  useRouter: () => ({
    resolve: (loc: { name?: string; params?: { id?: string }; query?: Record<string, string> }) => {
      if (loc.name === 'next.generator.sessions.detail') {
        return { href: `/next/generator/sessions/${loc.params?.id ?? ''}` };
      }
      return { href: `/next/${loc.name ?? ''}` };
    },
  }),
}));

function run(overrides: Partial<WorkflowRun> = {}): WorkflowRun {
  return {
    id: 'r1',
    state: 'completed',
    state_label: 'Completed',
    state_tone: 'success',
    origin: 'manual',
    trigger_type: 'schedule',
    depth: 0,
    origin_run_id: null,
    error: null,
    started_at: '2026-01-01T00:00:00Z',
    finished_at: null,
    created_at: '2026-01-01T00:00:00Z',
    duration_seconds: null,
    steps_count: 1,
    ...overrides,
  } as WorkflowRun;
}

/** A run parked on a generation — the shape the backend produces for a suspended step. */
function waitingRun(overrides: Partial<WorkflowRun> = {}): WorkflowRun {
  return run({
    id: 'run-waiting',
    state: 'waiting',
    state_label: 'Waiting',
    state_tone: 'info',
    finished_at: null,
    duration_seconds: null,
    trigger_payload: {},
    steps: [],
    ...overrides,
  });
}

beforeEach(() => {
  setLocale('en');
  installBrowserMocks();
  fetchRun.mockReset();
});
afterEach(() => restoreBrowserMocks());

describe('the `waiting` run state — list surfaces', () => {
  it('is a REAL, selectable filter option (the runs list derives its options from RUN_STATES)', () => {
    expect([...RUN_STATES]).toContain('waiting');
    // The filter option needs both halves of the icon+text signal.
    expect(runStateIcon('waiting')).toBeTruthy();
  });

  it('renders a `waiting` run row with an icon + a localized label (never colour alone)', () => {
    const wrapper = mount(WorkflowRunRow, { props: { run: waitingRun() } });
    const badges = wrapper.findAllComponents(Badge);
    expect(badges[0].props('icon')).toBe(runStateIcon('waiting'));
    expect(wrapper.text()).toContain('Waiting');
    wrapper.unmount();
  });
});

describe('the `waiting` run state — run detail', () => {
  function mountTimeline(runId = 'run-waiting') {
    return mount(WorkflowRunTimeline, {
      attachTo: document.body,
      props: { workflowId: 'wf-1', runId },
      global: { stubs: { SubmissionPreviewDrawer: true } },
    });
  }

  it('explains the wait, the timing expectation and that there is no cancel', async () => {
    fetchRun.mockResolvedValue(waitingRun());
    const wrapper = mountTimeline();
    await flushPromises();

    expect(wrapper.text()).toContain('Waiting for content generation');
    expect(wrapper.text()).toContain('usually takes a minute or two');
    expect(wrapper.text()).toContain('can’t be cancelled yet');
    wrapper.unmount();
  });

  it('shows the ELAPSED time as of the last read, and REFRESH re-reads the run', async () => {
    // The run STARTED 5 minutes ago (relative to a frozen clock) so the elapsed string is
    // stable. The interval measured is the whole RUN — the run resource carries no
    // suspended-at/waiting-since instant — so the copy must say "running for", never
    // "waiting for" (a step before the generation makes the two diverge).
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:05:00Z'));
    fetchRun.mockResolvedValue(waitingRun({ started_at: '2026-01-01T00:00:00Z' }));

    const wrapper = mountTimeline();
    await flushPromises();
    expect(wrapper.text()).toContain('Running for 5m 0s');
    expect(wrapper.text()).not.toContain('Waiting for 5m 0s');

    // There is NO live push on this screen, so the refresh affordance is the mechanism.
    expect(fetchRun).toHaveBeenCalledTimes(1);
    const refresh = wrapper.findAll('button').find((b) => b.text().includes('Refresh'));
    expect(refresh).toBeTruthy();
    await refresh!.trigger('click');
    await flushPromises();
    expect(fetchRun).toHaveBeenCalledTimes(2);

    vi.useRealTimers();
    wrapper.unmount();
  });

  it('does NOT show the waiting panel for a completed run', async () => {
    fetchRun.mockResolvedValue(run({ id: 'run-done', steps: [], trigger_payload: {} }));
    const wrapper = mountTimeline('run-done');
    await flushPromises();

    expect(wrapper.text()).not.toContain('Waiting for content generation');
    wrapper.unmount();
  });

  it('a finished generate_content step deep-links to the produced generation session', async () => {
    fetchRun.mockResolvedValue(
      run({
        id: 'run-gen',
        trigger_payload: {},
        steps: [
          {
            id: 'step-g',
            position: 0,
            type: 'generate_content',
            key: 'content',
            status: 'succeeded',
            status_label: 'Succeeded',
            status_tone: 'success',
            payload: {
              session_id: 'sess-77',
              content: 'The generated post body',
              image_file_ids: ['f1', 'f2'],
              status: 'ready',
              has_failed_parts: false,
            },
            error: null,
            created_at: '2026-01-01T00:00:01Z',
          },
        ],
      }),
    );
    const wrapper = mountTimeline('run-gen');
    await flushPromises();

    const card = wrapper.findComponent(EntityCard);
    expect(card.exists()).toBe(true);
    expect(card.props('href')).toBe('/next/generator/sessions/sess-77');
    expect(card.props('title')).toBe('Generated content');
    // The resource card REPLACES the raw key→value dump, so a whole post body never
    // lands in the timeline as a payload row.
    expect(wrapper.text()).not.toContain('The generated post body');
    wrapper.unmount();
  });
});
