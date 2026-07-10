// @vitest-environment happy-dom
// TargetPickerModal.spec — the run-now modal (§6, REWRITTEN for 5.1).
//
// Behaviour-focused: the modal's job is (a) pick the right target control for the
// TWO surviving trigger types (form_submitted → a submission-id TextInput; schedule
// → confirm-only, no field), (b) block an empty required target CLIENT-side (never
// submit), (c) reframe as a "test run" when the workflow is inactive (§6.2), and
// (d) map the run 422 by bag KEY through `mapRunNowError` — the bag now has EXACTLY
// two keys (`target_id`, `workflow`); the deleted `approval_process` arm must NOT
// resurface. The workflows/runs stores + toast + vue-router are mocked so no HTTP /
// navigation side effects; the REAL i18n renders the copy the test asserts on.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import TargetPickerModal from '../TargetPickerModal.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import type { WorkflowDetail } from '../types';

// --- Store + toast + router mocks -------------------------------------------
const detailRef = ref<WorkflowDetail | null>(null);
const run = vi.fn();
const fetchWorkflow = vi.fn();

vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    run,
    fetchWorkflow,
  }),
}));

const fetchRuns = vi.fn();
vi.mock('../../../app/stores/workflowRuns', () => ({
  useWorkflowRunsStore: () => ({ workflowId: null, fetchRuns }),
}));

const toastSuccess = vi.fn();
const toastDanger = vi.fn();
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: toastSuccess, danger: toastDanger, info: vi.fn(), warning: vi.fn() }),
}));

// The modal reads `route.query` only for the runs-refetch shortcut; a static empty
// query is enough for every case here.
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
}));

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

function mountModal(workflow: WorkflowDetail) {
  detailRef.value = workflow;
  return mount(TargetPickerModal, {
    attachTo: document.body,
    props: { workflowId: workflow.id },
  });
}

/** Query the teleported modal panel in the document body. */
function panel(): HTMLElement {
  const el = document.body.querySelector('[role="dialog"]');
  if (!el) throw new Error('modal panel not found');
  return el as HTMLElement;
}

/** The primary confirm button is the last footer button. */
function confirmButton(): HTMLButtonElement {
  const buttons = panel().querySelectorAll('footer button');
  return buttons[buttons.length - 1] as HTMLButtonElement;
}

beforeEach(() => {
  installBrowserMocks();
  detailRef.value = null;
  run.mockReset();
  fetchWorkflow.mockReset();
  fetchRuns.mockReset();
  toastSuccess.mockReset();
  toastDanger.mockReset();
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('TargetPickerModal — target control per trigger type (§6.1)', () => {
  it('form_submitted → renders a submission-id field (+ its hint), no schedule copy', async () => {
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    const text = panel().textContent ?? '';
    expect(text).toContain(en.workflows.run.submissionIdLabel);
    expect(text).toContain(en.workflows.run.submissionIdHint);
    expect(text).not.toContain(en.workflows.run.scheduleConfirm);
    expect(panel().querySelector('input')).not.toBeNull();
  });

  it('schedule → confirm-only (no input field, shows the schedule confirm copy)', async () => {
    mountModal(makeWorkflow({ trigger_type: 'schedule' }));
    await flushPromises();

    const text = panel().textContent ?? '';
    expect(text).toContain(en.workflows.run.scheduleConfirm);
    expect(text).not.toContain(en.workflows.run.submissionIdLabel);
    expect(panel().querySelector('input')).toBeNull();
  });
});

describe('TargetPickerModal — client-side required guard (§6.3)', () => {
  it('form_submitted with an empty id never submits and shows the required copy', async () => {
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(run).not.toHaveBeenCalled();
    expect(panel().textContent).toContain(en.workflows.run.errors.targetRequired);
  });

  it('schedule requires no target — submit proceeds with no target_id', async () => {
    run.mockResolvedValue({ id: 'run-1' });
    mountModal(makeWorkflow({ trigger_type: 'schedule' }));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(run).toHaveBeenCalledWith('wf-1', undefined);
    expect(toastSuccess).toHaveBeenCalledWith(en.workflows.run.toasts.started);
  });

  it('form_submitted with a value submits the trimmed submission id', async () => {
    run.mockResolvedValue({ id: 'run-1' });
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    const input = panel().querySelector('input') as HTMLInputElement;
    input.value = '  sub-123  ';
    input.dispatchEvent(new Event('input'));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(run).toHaveBeenCalledWith('wf-1', 'sub-123');
  });
});

describe('TargetPickerModal — test-run framing when inactive (§6.2)', () => {
  it('inactive → shows the test-run note and the confirm label becomes "Test run"', async () => {
    mountModal(makeWorkflow({ status: 'inactive' }));
    await flushPromises();

    expect(panel().textContent).toContain(en.workflows.run.testRunNote);
    expect(confirmButton().textContent).toContain(en.workflows.run.testRunConfirm);
  });

  it('active → no test-run note and the confirm label is "Run now"', async () => {
    mountModal(makeWorkflow({ status: 'active' }));
    await flushPromises();

    expect(panel().textContent).not.toContain(en.workflows.run.testRunNote);
    expect(confirmButton().textContent).toContain(en.workflows.run.confirm);
  });
});

describe('TargetPickerModal — 422 mapping, both bag keys (§6.3)', () => {
  it('target_id 422 → flags the id field with the not-found copy', async () => {
    run.mockRejectedValue({ response: { status: 422, data: { errors: { target_id: ['nope'] } } } });
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    const input = panel().querySelector('input') as HTMLInputElement;
    input.value = 'sub-x';
    input.dispatchEvent(new Event('input'));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(panel().textContent).toContain(en.workflows.run.errors.targetNotFound);
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(toastDanger).toHaveBeenCalledWith(en.workflows.run.errors.targetNotFound);
  });

  it('workflow 422 → surfaces the cap-reached copy (not tied to the id field)', async () => {
    run.mockRejectedValue({ response: { status: 422, data: { errors: { workflow: ['cap'] } } } });
    mountModal(makeWorkflow({ trigger_type: 'schedule' }));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(panel().textContent).toContain(en.workflows.run.errors.capReached);
    expect(toastDanger).toHaveBeenCalledWith(en.workflows.run.errors.capReached);
  });
});
