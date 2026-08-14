// @vitest-environment happy-dom
// WorkflowOverviewView.spec — the read-only Overview panels (§3.2; the assertions
// moved verbatim from WorkflowDetailView.spec when Batch 3 split the detail into a
// SHELL + section child routes — the Overview is its own routed view now).
//
// Behaviour-focused on the TYPED trigger/conditions/steps summaries the 5.1 re-scope
// introduced: the form_submitted trigger sentence ("specific form" vs "any form" —
// NEVER a raw id, §9-note-2) + the Source/Anonymous sub-lines; the schedule trigger
// sentence via `describeSchedule`; the Conditions panel HIDDEN for schedule and shown
// with typed rows for form_submitted; the Steps panel echoing a step title with
// its variable directives stripped to the variable NAME (the MarkdownViewer renders
// base markdown only); and the "View runs" push targeting the runs CHILD ROUTE with
// the query preserved. The store + vue-router are mocked; the REAL i18n + the REAL
// describeSchedule render the copy the test asserts on.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import WorkflowOverviewView from '../WorkflowOverviewView.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { WorkflowCatalog, WorkflowDetail, WorkflowTriggerType } from '../types';

// --- Store + router mocks ----------------------------------------------------
const detailRef = ref<WorkflowDetail | null>(null);
const fetchWorkflowCatalog =
  vi.fn<(triggerType: WorkflowTriggerType, formId?: string | null) => Promise<WorkflowCatalog>>();

vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    fetchWorkflowCatalog,
  }),
}));

const routeQuery = ref<Record<string, unknown>>({});
const routerPush = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => ({
    name: 'next.workflows.detail.overview',
    params: { id: 'wf-1' },
    get query() {
      return routeQuery.value;
    },
  }),
  useRouter: () => ({ push: routerPush }),
}));

const CATALOG: WorkflowCatalog = {
  variables: [{ source: 'trigger', path: 'trigger.fields.status', name: 'Status', type: 'enum', enumOptions: ['open', 'done'] }],
  fields: [{ path: 'fields.status', field_id: 'status', label: 'Status', type: 'enum', enumOptions: ['open', 'done'], operators: ['is', 'in'] }],
};

function makeWorkflow(overrides: Partial<WorkflowDetail> = {}): WorkflowDetail {
  return {
    id: 'wf-1',
    name: 'WF',
    status: 'active',
    description: null,
    icon: null,
    trigger_type: 'form_submitted',
    trigger_config: { form_id: null },
    conditions: [],
    steps: [],
    last_scheduled_run_at: null,
    next_due_at: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_change_status: true,
    can_run: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

async function mountOverview(workflow: WorkflowDetail) {
  detailRef.value = workflow;
  const wrapper = mount(WorkflowOverviewView, { attachTo: document.body });
  await flushPromises();
  return wrapper;
}

beforeEach(() => {
  installBrowserMocks();
  routeQuery.value = {};
  detailRef.value = null;
  routerPush.mockReset();
  fetchWorkflowCatalog.mockReset();
  fetchWorkflowCatalog.mockResolvedValue(CATALOG);
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowOverviewView — form_submitted trigger sentence (§3.2)', () => {
  it('null form_id → "any form" sentence + "any source", never a raw id', async () => {
    const wrapper = await mountOverview(makeWorkflow({ trigger_config: { form_id: null } }));
    const text = wrapper.text();
    expect(text).toContain(en.workflows.detail.triggerFormAny);
    expect(text).not.toContain(en.workflows.detail.triggerFormSpecific);
    expect(text).toContain(en.workflows.detail.sourceAny);
    // No catalog fetch when there is no form.
    expect(fetchWorkflowCatalog).not.toHaveBeenCalled();
  });

  it('a specific form → "specific form" sentence (the id is NEVER surfaced)', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({ trigger_config: { form_id: 'form-abc-uuid' } }),
    );
    const text = wrapper.text();
    expect(text).toContain(en.workflows.detail.triggerFormSpecific);
    expect(text).not.toContain('form-abc-uuid');
    expect(fetchWorkflowCatalog).toHaveBeenCalledWith('form_submitted', 'form-abc-uuid');
  });

  it('source subset + anonymous narrow → the matching sub-lines', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1', source: { in: ['manual'] }, anonymous: true },
      }),
    );
    const text = wrapper.text();
    expect(text).toContain(en.workflows.detail.sourceManualOnly);
    expect(text).toContain(en.workflows.trigger.anonymous.onlyAnonymous);
  });

  it('anonymous "any" (null) adds no anonymous line', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({ trigger_config: { form_id: 'f1', anonymous: null } }),
    );
    expect(wrapper.text()).not.toContain(en.workflows.trigger.anonymous.onlyAnonymous);
    expect(wrapper.text()).not.toContain(en.workflows.trigger.anonymous.onlyNonAnonymous);
  });
});

describe('WorkflowOverviewView — schedule trigger sentence (describeSchedule)', () => {
  it('renders the v2 cadence sentence (via configToDraft) + hides the Conditions panel', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_type: 'schedule',
        trigger_config: { schedule: { time: { mode: 'at', at: ['09:00'] } } },
        next_due_at: null,
      }),
    );
    const text = wrapper.text();
    // describeSchedule(configToDraft({time:{mode:'at',at:['09:00']}})) → the neutral head
    // (no tz clause when tz is blank, §4.5.10).
    expect(text).toContain('Daily at 09:00');
    // Conditions panel is form_submitted-only → hidden for schedule.
    expect(text).not.toContain(en.workflows.detail.conditionsTitle);
    expect(text).not.toContain(en.workflows.detail.conditionsAlways);
    // No catalog fetch for a schedule trigger.
    expect(fetchWorkflowCatalog).not.toHaveBeenCalled();
  });

  it('renders the multi-axis + exclusions clauses in the sentence (§4.5.10)', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_type: 'schedule',
        trigger_config: {
          schedule: {
            time: { mode: 'at', at: ['08:00', '17:00'] },
            day: { mode: 'weekdays', weekdays: [1, 3] },
            exclusions: { months: [8], weekdays: [0], dates: ['2026-12-24'] },
          },
        },
      }),
    );
    const text = wrapper.text();
    // At <times>, on <days> — except: <weekdays>; <months>; <date>.
    expect(text).toContain('At 08:00 and 17:00');
    expect(text).toContain('on Mondays and Wednesdays');
    expect(text).toContain('except:');
    expect(text).toContain('Sundays');
    expect(text).toContain('August');
    expect(text).toContain('24.12.2026');
  });
});

describe('WorkflowOverviewView — conditions panel (§3.2)', () => {
  it('empty conditions → the "always runs" copy', async () => {
    const wrapper = await mountOverview(makeWorkflow({ trigger_config: { form_id: 'f1' }, conditions: [] }));
    expect(wrapper.text()).toContain(en.workflows.detail.conditionsAlways);
  });

  it('a typed condition → field label (from catalog) + operator word + value chip', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        conditions: [{ field: 'fields.status', field_type: 'enum', operator: 'is', value: 'open' }],
      }),
    );
    const text = wrapper.text();
    expect(text).toContain('Status'); // catalog label, not the raw path
    expect(text).toContain(en.workflows.condition.operator.is);
    expect(text).toContain('open');
  });

  it('a value-less operator (is_true) renders no value chip', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        conditions: [{ field: 'fields.flag', field_type: 'boolean', operator: 'is_true' }],
      }),
    );
    const text = wrapper.text();
    // The last path segment is the label fallback (catalog has no `fields.flag`).
    expect(text).toContain('flag');
    expect(text).toContain(en.workflows.condition.operator.is_true);
  });
});

describe('WorkflowOverviewView — steps panel (§3.2)', () => {
  it('create_task → its title, with variable directives stripped to the variable name', async () => {
    const directive = `@[variable]("${JSON.stringify({ v: 1, data: { id: 'trigger.fields.status', name: 'Old', type: 'text' } }).replace(/"/g, '\\"')}")`;
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [{ type: 'create_task', key: 'make', config: { title: `Ticket for ${directive}` } }],
      }),
    );
    const text = wrapper.text();
    expect(text).toContain('Ticket for Status'); // catalog name by path
    expect(text).not.toContain('@[variable]'); // never the raw directive bytes
    expect(text).toContain('make'); // the key mono chip
  });

  it('an empty create_task title → the fallback summary', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [{ type: 'create_task', key: 'make', config: { title: '' } }],
      }),
    );
    expect(wrapper.text()).toContain(en.workflows.step.summary.createTaskFallback);
  });

  it('create_form_report → its name', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [{ type: 'create_form_report', key: 'rep', config: { form_id: 'f1', name: 'Weekly digest' } }],
      }),
    );
    expect(wrapper.text()).toContain('Weekly digest');
  });

  /**
   * R3 B7 — `create_event`, the FOURTH step type. It is titled by `title` (like `create_task`)
   * and NOT by `name` (like `create_form_report`), and it has a fallback of its own.
   *
   * This pair exists because the summary used to be a binary ternary over two type names: a
   * fourth type read as a form report, took the wrong key, found nothing, and fell back to
   * "New task" — wrong word, wrong field, no error. The map that replaced it is only correct as
   * long as every type in it is exercised.
   */
  it('create_event → its title, read from `title` and not from `name`', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [
          {
            type: 'create_event',
            key: 'event',
            // `name` is deliberately present and WRONG: reading the create_form_report key
            // would surface it, which is exactly the regression this pins.
            config: { title: 'Recording session', name: 'not the event title', all_day: true },
          },
        ],
      }),
    );

    const text = wrapper.text();
    expect(text).toContain('Recording session');
    expect(text).not.toContain('not the event title');
    expect(text).toContain('event'); // the key mono chip
  });

  it('an empty create_event title → the EVENT fallback, never the task one', async () => {
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [{ type: 'create_event', key: 'event', config: { title: '', all_day: false } }],
      }),
    );

    expect(wrapper.text()).toContain(en.workflows.step.summary.createEventFallback);
    expect(wrapper.text()).not.toContain(en.workflows.step.summary.createTaskFallback);
  });

  it('strips variable directives out of a create_event title too', async () => {
    const directive = `@[variable]("${JSON.stringify({ v: 1, data: { id: 'trigger.fields.status', name: 'Old', type: 'text' } }).replace(/"/g, '\\"')}")`;
    const wrapper = await mountOverview(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [{ type: 'create_event', key: 'event', config: { title: `Shoot for ${directive}`, all_day: true } }],
      }),
    );

    expect(wrapper.text()).toContain('Shoot for Status');
    expect(wrapper.text()).not.toContain('@[variable]');
  });
});

describe('WorkflowOverviewView — "View runs" navigation (Batch 3)', () => {
  it('pushes the runs CHILD ROUTE with the current query preserved', async () => {
    routeQuery.value = { workflow: 'wf-2' };
    const wrapper = await mountOverview(makeWorkflow());

    const viewRuns = wrapper
      .findAll('button')
      .find((b) => b.text().includes(en.workflows.detail.viewRuns));
    expect(viewRuns).toBeTruthy();
    await viewRuns!.trigger('click');

    expect(routerPush).toHaveBeenCalledWith({
      name: 'next.workflows.detail.runs',
      params: { id: 'wf-1' },
      query: { workflow: 'wf-2' },
    });
  });
});
