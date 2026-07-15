// @vitest-environment happy-dom
// TargetPickerModal.spec — the run-now modal (§6, REWRITTEN for B8).
//
// Behaviour-focused: the modal's job is (a) pick the right target control for the
// TWO surviving trigger types — form_submitted now offers PICK / CREATE actions that
// resolve to ONE submission uuid (`target_id`), schedule stays confirm-only; (b)
// keep Run DISABLED until a submission is selected/created (the `targetRequired`
// client guard, no empty submit); (c) reframe as a "test run" when inactive (§6.2);
// (d) map the run 422 by bag KEY through `mapRunNowError` (`target_id`, `workflow`);
// and (e) scope Pick/Create by the trigger's `trigger_config.form_id` — null shows a
// FormSelect step first. The stores + toast + vue-router are mocked; the child
// drawers (SubmissionPickerDrawer / FormFillView) + FormSelect are stubbed so the
// modal's wiring is asserted without HTTP; the REAL i18n renders the asserted copy.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import TargetPickerModal from '../TargetPickerModal.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { en } from '../../../app/i18n/en';
import { setLocale } from '../../../app/i18n';
import type { WorkflowDetail } from '../types';
import type { FormSubmission } from '../../forms/types';

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
const runsStoreMock = { workflowId: null as string | null, fetchRuns };
vi.mock('../../../app/stores/workflowRuns', () => ({
  useWorkflowRunsStore: () => runsStoreMock,
}));

const toastSuccess = vi.fn();
const toastDanger = vi.fn();
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: toastSuccess, danger: toastDanger, info: vi.fn(), warning: vi.fn() }),
}));

const routeName = ref<string | undefined>(undefined);
const routeQuery = ref<Record<string, unknown>>({});
vi.mock('vue-router', () => ({
  useRoute: () => ({
    get name() {
      return routeName.value;
    },
    get query() {
      return routeQuery.value;
    },
  }),
}));

// --- Child stubs: emit the events the modal wires up, without HTTP ----------
function makeSubmission(id: string): FormSubmission {
  return {
    id,
    form_id: 'form-1',
    data: {},
    source: 'form',
    form_content_version_id: null,
    indexed_at: null,
    approved_at: '2026-07-10T09:00:00Z',
    is_approved: true,
    can_be_edited: false,
    creator: null,
    created_at: '2026-07-10T09:00:00Z',
    updated_at: null,
  };
}

const PickerStub = defineComponent({
  name: 'SubmissionPickerDrawer',
  props: { open: { type: Boolean, default: false }, formId: { default: null } },
  emits: ['update:open', 'select'],
  setup(_, { emit }) {
    return () =>
      h('button', {
        'data-test': 'picker-select',
        onClick: () => emit('select', 'sub-picked', makeSubmission('sub-picked')),
      });
  },
});

const FillStub = defineComponent({
  name: 'FormFillView',
  props: { formId: { type: String, required: true } },
  emits: ['submitted', 'close'],
  setup(_, { emit }) {
    return () =>
      h('button', {
        'data-test': 'fill-submit',
        onClick: () => emit('submitted', makeSubmission('sub-created')),
      });
  },
});

const FormSelectStub = defineComponent({
  name: 'FormSelect',
  props: { modelValue: { default: null } },
  emits: ['update:modelValue'],
  setup(_, { emit }) {
    return () =>
      h('button', {
        'data-test': 'form-select',
        onClick: () => emit('update:modelValue', 'form-chosen'),
      });
  },
});

function makeWorkflow(overrides: Partial<WorkflowDetail> = {}): WorkflowDetail {
  return {
    id: 'wf-1',
    name: 'WF',
    status: 'active',
    description: null,
    icon: null,
    trigger_type: 'form_submitted',
    trigger_config: { form_id: 'form-1' },
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
    global: {
      stubs: {
        SubmissionPickerDrawer: PickerStub,
        FormFillView: FillStub,
        FormSelect: FormSelectStub,
      },
    },
  });
}

/** The teleported MODAL panel (the first dialog; drawers teleport after it). */
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

/** Find a body button whose text contains `label`. */
function bodyButton(label: string): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find((b) =>
    (b.textContent ?? '').includes(label),
  ) as HTMLButtonElement | undefined;
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
  detailRef.value = null;
  routeName.value = undefined;
  routeQuery.value = {};
  runsStoreMock.workflowId = null;
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
  it('form_submitted → Pick + Create actions, empty summary, no raw id input', async () => {
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    const text = panel().textContent ?? '';
    expect(text).toContain(en.workflows.run.pick);
    expect(text).toContain(en.workflows.run.create);
    expect(text).toContain(en.workflows.run.noSubmission);
    expect(text).not.toContain(en.workflows.run.scheduleConfirm);
    // The raw submission-id TextInput is gone.
    expect(panel().querySelector('input')).toBeNull();
  });

  it('schedule → confirm-only (no Pick/Create, shows the schedule confirm copy)', async () => {
    mountModal(makeWorkflow({ trigger_type: 'schedule', trigger_config: {} }));
    await flushPromises();

    const text = panel().textContent ?? '';
    expect(text).toContain(en.workflows.run.scheduleConfirm);
    expect(text).not.toContain(en.workflows.run.pick);
    expect(text).not.toContain(en.workflows.run.create);
  });
});

describe('TargetPickerModal — Pick + Create buttons are a side-by-side equal-width row', () => {
  it('lays Pick and Create in one flex row, each flex-1', async () => {
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    const pick = bodyButton(en.workflows.run.pick)!;
    const create = bodyButton(en.workflows.run.create)!;

    // Both equal-width (flex-1) and in the SAME flex row (no wrapping/stacking).
    expect(pick.classList.contains('flex-1')).toBe(true);
    expect(create.classList.contains('flex-1')).toBe(true);
    expect(pick.parentElement).toBe(create.parentElement);
    expect(pick.parentElement?.classList.contains('flex')).toBe(true);
    expect(pick.parentElement?.classList.contains('flex-wrap')).toBe(false);
  });
});

describe('TargetPickerModal — Run stays disabled until a target is resolved', () => {
  it('form_submitted with nothing selected → Run is disabled and never submits', async () => {
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    expect(confirmButton().disabled).toBe(true);
    confirmButton().click();
    await flushPromises();
    expect(run).not.toHaveBeenCalled();
  });

  it('schedule requires no target — Run is enabled and submits with no target_id', async () => {
    run.mockResolvedValue({ id: 'run-1' });
    mountModal(makeWorkflow({ trigger_type: 'schedule', trigger_config: {} }));
    await flushPromises();

    expect(confirmButton().disabled).toBe(false);
    confirmButton().click();
    await flushPromises();

    expect(run).toHaveBeenCalledWith('wf-1', undefined);
    expect(toastSuccess).toHaveBeenCalledWith(en.workflows.run.toasts.started);
  });
});

describe('TargetPickerModal — Pick flow sets target_id', () => {
  it('picking a submission enables Run and submits its id as target_id', async () => {
    run.mockResolvedValue({ id: 'run-1' });
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    // Open the picker, then let the (stubbed) drawer emit a selection.
    bodyButton(en.workflows.run.pick)!.click();
    await flushPromises();
    (document.body.querySelector('[data-test="picker-select"]') as HTMLButtonElement).click();
    await flushPromises();

    // The summary now shows the picked submission (anonymous creator + Manual source).
    expect(panel().textContent).toContain(en.workflows.run.pick); // still available to re-pick
    expect(confirmButton().disabled).toBe(false);

    confirmButton().click();
    await flushPromises();
    expect(run).toHaveBeenCalledWith('wf-1', 'sub-picked');
  });
});

describe('TargetPickerModal — Create flow yields a real submission target', () => {
  it('bound form → FormFillView creates a submission and its id becomes target_id', async () => {
    run.mockResolvedValue({ id: 'run-1' });
    const wrapper = mountModal(makeWorkflow({ trigger_type: 'form_submitted', trigger_config: { form_id: 'form-1' } }));
    await flushPromises();

    bodyButton(en.workflows.run.create)!.click();
    await flushPromises();

    // A bound form skips the FormSelect step → FormFillView mounts immediately.
    const fill = wrapper.findComponent(FillStub);
    expect(fill.exists()).toBe(true);
    expect(fill.props('formId')).toBe('form-1');

    (document.body.querySelector('[data-test="fill-submit"]') as HTMLButtonElement).click();
    await flushPromises();

    expect(confirmButton().disabled).toBe(false);
    confirmButton().click();
    await flushPromises();
    expect(run).toHaveBeenCalledWith('wf-1', 'sub-created');
  });

  it('form_id null → Create shows a FormSelect step first, then FormFillView', async () => {
    const wrapper = mountModal(makeWorkflow({ trigger_type: 'form_submitted', trigger_config: { form_id: null } }));
    await flushPromises();

    // The Pick drawer is scoped with a null form (its own FormSelect step handles it).
    expect(wrapper.findComponent(PickerStub).props('formId')).toBeNull();

    bodyButton(en.workflows.run.create)!.click();
    await flushPromises();

    // Step 1: no form chosen yet → the FormSelect stub is shown, FormFillView is not.
    expect(document.body.querySelector('[data-test="form-select"]')).not.toBeNull();
    expect(wrapper.findComponent(FillStub).exists()).toBe(false);

    // Choosing a form advances to FormFillView.
    (document.body.querySelector('[data-test="form-select"]') as HTMLButtonElement).click();
    await flushPromises();
    expect(wrapper.findComponent(FillStub).exists()).toBe(true);
    expect(wrapper.findComponent(FillStub).props('formId')).toBe('form-chosen');
  });
});

describe('TargetPickerModal — test-run framing when inactive (§6.2)', () => {
  it('inactive → shows the test-run note and the confirm label becomes "Test run"', async () => {
    mountModal(makeWorkflow({ status: 'inactive', trigger_type: 'schedule', trigger_config: {} }));
    await flushPromises();

    expect(panel().textContent).toContain(en.workflows.run.testRunNote);
    expect(confirmButton().textContent).toContain(en.workflows.run.testRunConfirm);
  });

  it('active → no test-run note and the confirm label is "Run now"', async () => {
    mountModal(makeWorkflow({ status: 'active', trigger_type: 'schedule', trigger_config: {} }));
    await flushPromises();

    expect(panel().textContent).not.toContain(en.workflows.run.testRunNote);
    expect(confirmButton().textContent).toContain(en.workflows.run.confirm);
  });
});

describe('TargetPickerModal — 422 mapping, both bag keys (§6.3)', () => {
  it('target_id 422 → surfaces the not-found copy (inline + toast)', async () => {
    run.mockRejectedValue({ response: { status: 422, data: { errors: { target_id: ['nope'] } } } });
    mountModal(makeWorkflow({ trigger_type: 'form_submitted' }));
    await flushPromises();

    // Pick a submission so Run is enabled, then submit → the server rejects it.
    bodyButton(en.workflows.run.pick)!.click();
    await flushPromises();
    (document.body.querySelector('[data-test="picker-select"]') as HTMLButtonElement).click();
    await flushPromises();
    confirmButton().click();
    await flushPromises();

    expect(panel().textContent).toContain(en.workflows.run.errors.targetNotFound);
    expect(toastDanger).toHaveBeenCalledWith(en.workflows.run.errors.targetNotFound);
  });

  it('workflow 422 → surfaces the cap-reached copy', async () => {
    run.mockRejectedValue({ response: { status: 422, data: { errors: { workflow: ['cap'] } } } });
    mountModal(makeWorkflow({ trigger_type: 'schedule', trigger_config: {} }));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(panel().textContent).toContain(en.workflows.run.errors.capReached);
    expect(toastDanger).toHaveBeenCalledWith(en.workflows.run.errors.capReached);
  });
});

describe('TargetPickerModal — runs refetch on the Runs child route (§6.3 / Batch 3)', () => {
  it('refetches the runs list (honoring the URL filters) when the Runs route is active', async () => {
    routeName.value = 'next.workflows.detail.runs';
    routeQuery.value = { state: 'failed', origin: 'manual' };
    runsStoreMock.workflowId = 'wf-1';
    run.mockResolvedValue({ id: 'run-1' });
    mountModal(makeWorkflow({ trigger_type: 'schedule', trigger_config: {} }));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(fetchRuns).toHaveBeenCalledWith(
      'wf-1',
      { state: ['failed'], origin: ['manual'] },
      { reset: true },
    );
  });

  it('does NOT refetch when another detail section is active', async () => {
    routeName.value = 'next.workflows.detail.overview';
    runsStoreMock.workflowId = 'wf-1';
    run.mockResolvedValue({ id: 'run-1' });
    mountModal(makeWorkflow({ trigger_type: 'schedule', trigger_config: {} }));
    await flushPromises();

    confirmButton().click();
    await flushPromises();

    expect(run).toHaveBeenCalled();
    expect(fetchRuns).not.toHaveBeenCalled();
  });
});
