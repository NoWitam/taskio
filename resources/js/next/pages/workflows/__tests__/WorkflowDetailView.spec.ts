// @vitest-environment happy-dom
// WorkflowDetailView.spec — the read-only Overview panels (§3.2, REWRITTEN for 5.1).
//
// Behaviour-focused on the TYPED trigger/conditions/steps summaries the 5.1 re-scope
// introduced: the form_submitted trigger sentence ("specific form" vs "any form" —
// NEVER a raw id, §9-note-2) + the Source/Anonymous sub-lines; the schedule trigger
// sentence via `describeSchedule`; the Conditions panel HIDDEN for schedule and shown
// with typed rows for form_submitted; and the Steps panel echoing a step title with
// its variable directives stripped to the variable NAME (the MarkdownViewer renders
// base markdown only). The store + toast + vue-router are mocked; the REAL i18n +
// the REAL describeSchedule render the copy the test asserts on.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import WorkflowDetailView from '../WorkflowDetailView.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { WorkflowCatalog, WorkflowDetail } from '../types';

// --- Store + toast + router mocks -------------------------------------------
const detailRef = ref<WorkflowDetail | null>(null);
const fetchWorkflow = vi.fn();
const fetchWorkflowCatalog = vi.fn<(formId: string) => Promise<WorkflowCatalog>>();

vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    fetchWorkflow,
    fetchWorkflowCatalog,
    setStatus: vi.fn(),
  }),
}));

vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}));

const routeQuery = ref<Record<string, unknown>>({ section: 'overview' });
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'wf-1' }, get query() { return routeQuery.value; } }),
  useRouter: () => ({ push: vi.fn() }),
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

async function mountDetail(workflow: WorkflowDetail) {
  detailRef.value = workflow;
  const wrapper = mount(WorkflowDetailView, { attachTo: document.body });
  await flushPromises();
  return wrapper;
}

beforeEach(() => {
  installBrowserMocks();
  routeQuery.value = { section: 'overview' };
  detailRef.value = null;
  fetchWorkflow.mockReset();
  fetchWorkflowCatalog.mockReset();
  fetchWorkflowCatalog.mockResolvedValue(CATALOG);
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('WorkflowDetailView — form_submitted trigger sentence (§3.2)', () => {
  it('null form_id → "any form" sentence + "any source", never a raw id', async () => {
    const wrapper = await mountDetail(makeWorkflow({ trigger_config: { form_id: null } }));
    const text = wrapper.text();
    expect(text).toContain(en.workflows.detail.triggerFormAny);
    expect(text).not.toContain(en.workflows.detail.triggerFormSpecific);
    expect(text).toContain(en.workflows.detail.sourceAny);
    // No catalog fetch when there is no form.
    expect(fetchWorkflowCatalog).not.toHaveBeenCalled();
  });

  it('a specific form → "specific form" sentence (the id is NEVER surfaced)', async () => {
    const wrapper = await mountDetail(
      makeWorkflow({ trigger_config: { form_id: 'form-abc-uuid' } }),
    );
    const text = wrapper.text();
    expect(text).toContain(en.workflows.detail.triggerFormSpecific);
    expect(text).not.toContain('form-abc-uuid');
    expect(fetchWorkflowCatalog).toHaveBeenCalledWith('form-abc-uuid');
  });

  it('source subset + anonymous narrow → the matching sub-lines', async () => {
    const wrapper = await mountDetail(
      makeWorkflow({
        trigger_config: { form_id: 'f1', source: { in: ['manual'] }, anonymous: true },
      }),
    );
    const text = wrapper.text();
    expect(text).toContain(en.workflows.detail.sourceManualOnly);
    expect(text).toContain(en.workflows.trigger.anonymous.onlyAnonymous);
  });

  it('anonymous "any" (null) adds no anonymous line', async () => {
    const wrapper = await mountDetail(
      makeWorkflow({ trigger_config: { form_id: 'f1', anonymous: null } }),
    );
    expect(wrapper.text()).not.toContain(en.workflows.trigger.anonymous.onlyAnonymous);
    expect(wrapper.text()).not.toContain(en.workflows.trigger.anonymous.onlyNonAnonymous);
  });
});

describe('WorkflowDetailView — schedule trigger sentence (describeSchedule)', () => {
  it('renders the descriptor-driven cadence sentence + hides the Conditions panel', async () => {
    const wrapper = await mountDetail(
      makeWorkflow({
        trigger_type: 'schedule',
        trigger_config: { schedule: { family: 'daily', params: { time: '09:00' }, tz: null } },
        next_due_at: null,
      }),
    );
    const text = wrapper.text();
    // describeSchedule({family:'daily', params:{time:'09:00'}, tz:null}) → en describe.daily with UTC.
    expect(text).toContain('Daily at 09:00 (UTC)');
    // Conditions panel is form_submitted-only → hidden for schedule.
    expect(text).not.toContain(en.workflows.detail.conditionsTitle);
    expect(text).not.toContain(en.workflows.detail.conditionsAlways);
    // No catalog fetch for a schedule trigger.
    expect(fetchWorkflowCatalog).not.toHaveBeenCalled();
  });

  it('renders the multi-time + exclusions clauses in the sentence (B4)', async () => {
    const wrapper = await mountDetail(
      makeWorkflow({
        trigger_type: 'schedule',
        trigger_config: {
          schedule: {
            family: 'weekly',
            params: { weekdays: [1, 3] },
            times: ['08:00', '17:00'],
            tz: null,
            exclusions: { months: [8], weekdays: [0], dates: ['2026-12-24'] },
          },
        },
      }),
    );
    const text = wrapper.text();
    // Weekly on <days> at <times> (UTC) except: <weekdays> · <months> · <dates>.
    expect(text).toContain('Monday and Wednesday');
    expect(text).toContain('08:00 and 17:00');
    expect(text).toContain('except:');
    expect(text).toContain('Sunday');
    expect(text).toContain('August');
    expect(text).toContain('24.12.2026');
  });
});

describe('WorkflowDetailView — conditions panel (§3.2)', () => {
  it('empty conditions → the "always runs" copy', async () => {
    const wrapper = await mountDetail(makeWorkflow({ trigger_config: { form_id: 'f1' }, conditions: [] }));
    expect(wrapper.text()).toContain(en.workflows.detail.conditionsAlways);
  });

  it('a typed condition → field label (from catalog) + operator word + value chip', async () => {
    const wrapper = await mountDetail(
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
    const wrapper = await mountDetail(
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

describe('WorkflowDetailView — steps panel (§3.2)', () => {
  it('create_task → its title, with variable directives stripped to the variable name', async () => {
    const directive = `@[variable]("${JSON.stringify({ v: 1, data: { id: 'trigger.fields.status', name: 'Old', type: 'text' } }).replace(/"/g, '\\"')}")`;
    const wrapper = await mountDetail(
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
    const wrapper = await mountDetail(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [{ type: 'create_task', key: 'make', config: { title: '' } }],
      }),
    );
    expect(wrapper.text()).toContain(en.workflows.step.summary.createTaskFallback);
  });

  it('create_form_report → its name', async () => {
    const wrapper = await mountDetail(
      makeWorkflow({
        trigger_config: { form_id: 'f1' },
        steps: [{ type: 'create_form_report', key: 'rep', config: { form_id: 'f1', name: 'Weekly digest' } }],
      }),
    );
    expect(wrapper.text()).toContain('Weekly digest');
  });
});
