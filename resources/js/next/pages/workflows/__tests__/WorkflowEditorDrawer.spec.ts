// @vitest-environment happy-dom
// WorkflowEditorDrawer.spec — the 5.1 orchestrator + B7 3-step WIZARD (§4, §4.8, §4.10).
//
// The drift-critical test: it asserts the EXACT trigger_config/conditions/steps wire
// the drawer POSTs for BOTH trigger types, plus the catalog lifecycle (fetch on form
// select, refetch + CLEAR-conditions toast on form CHANGE, disabled/cleared on form
// clear), conditions HIDDEN for schedule, and the §4.10 422 routing onto per-field
// errors. The child sections are STUBBED to exposed v-models so the test drives the
// drawer's state directly; the store + toast are mocked so no HTTP/UI side effects.
//
// B7: the editor is now a 3-step wizard (General → Trigger → Steps). The trigger-TYPE
// selector lives in the drawer (step 1 SegmentedControl, NOT stubbed); the SAVE button
// only renders on the last step, so save flows navigate to step 3 first. Panels use
// v-show so every stub input stays mounted (the name in step 1, the step-fill in step 3
// are reachable from any step). Helpers `gotoStep(3)` / `save` drive the wizard.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, h, ref } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { WorkflowCatalog, WorkflowDetail } from '../types';
import type { FormTriggerDraft, StepDraft } from '../workflowEditorModel';
import { makeStepDraft } from '../workflowEditorModel';
import type { ScheduleDraft } from '../workflowSchedule';

// --- Store + toast mocks -----------------------------------------------------
const createWorkflow = vi.fn();
const updateWorkflow = vi.fn();
const fetchWorkflowCatalog = vi.fn();
const detailRef = ref<WorkflowDetail | null>(null);

const CATALOG: WorkflowCatalog = {
  variables: [{ source: 'trigger', path: 'fields.status', name: 'Status', type: 'enum', enumOptions: ['a'] }],
  fields: [{ path: 'fields.status', field_id: 'status', label: 'Status', type: 'enum', enumOptions: ['a'], operators: ['is'] }],
};

vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({
    get detail() {
      return detailRef.value;
    },
    createWorkflow,
    updateWorkflow,
    fetchWorkflowCatalog,
    schedulePreview: vi.fn().mockResolvedValue({ occurrences: [], count: 6, empty: false, approximate: false }),
  }),
  ScheduleAssistError: class extends Error {},
}));

const toastSuccess = vi.fn();
const toastDanger = vi.fn();
const toastInfo = vi.fn();
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: toastSuccess, danger: toastDanger, info: toastInfo, warning: vi.fn() }),
}));

// --- Child section stubs (v-model driven) ------------------------------------
// The trigger fields stub exposes buttons to select a form / clear it / set
// source+anonymous, and exposes `scheduleValid()` so the drawer's save gate works.
// `type` is a plain READ-ONLY prop now (the drawer owns type switching via its own
// SegmentedControl in step 1); the stub echoes it into `.trigger-type` for assertions.
// A `seed-schedule` button lets a schedule-mode test seed a real schedule draft (the
// stubbed builder doesn't self-seed).
const TriggerFieldsStub = {
  name: 'WorkflowTriggerFields',
  props: ['type', 'formConfig', 'scheduleDraft', 'errors', 'formSeed', 'scheduleTz'],
  emits: ['update:formConfig', 'update:scheduleDraft', 'form-change'],
  setup(props: Record<string, unknown>, { emit, expose }: { emit: (e: string, ...a: unknown[]) => void; expose: (o: unknown) => void }) {
    expose({ scheduleValid: () => true });
    const setForm = (id: string | null) => {
      emit('update:formConfig', { ...(props.formConfig as FormTriggerDraft), form_id: id });
      emit('form-change', id);
    };
    const setSource = (src: Array<'manual' | 'task'>) => emit('update:formConfig', { ...(props.formConfig as FormTriggerDraft), source: src });
    const setAnon = (a: boolean | null) => emit('update:formConfig', { ...(props.formConfig as FormTriggerDraft), anonymous: a });
    const seedSchedule = () => {
      // v2 neutral draft: once daily at 09:00 (§4.5.1). draftToConfig emits the flat wire.
      emit('update:scheduleDraft', {
        time: { mode: 'at', at: ['09:00'] },
        day: { mode: 'every_day' },
        month: { mode: 'every_month' },
        exclusions: { months: [], weekdays: [], dates: [] },
        tz: '',
      });
    };
    return () =>
      h('div', { class: 'trigger-stub' }, [
        h('span', { class: 'trigger-type' }, props.type as string),
        h('button', { class: 'pick-a', onClick: () => setForm('form-a') }, 'pickA'),
        h('button', { class: 'pick-b', onClick: () => setForm('form-b') }, 'pickB'),
        h('button', { class: 'clear-form', onClick: () => setForm(null) }, 'clear'),
        h('button', { class: 'set-source', onClick: () => setSource(['manual']) }, 'src'),
        h('button', { class: 'set-anon', onClick: () => setAnon(true) }, 'anon'),
        h('button', { class: 'seed-schedule', onClick: seedSchedule }, 'seed'),
      ]);
  },
};

// The conditions stub (B3 TREE): `modelValue` is a DraftConditionGroup; "add" emits a
// one-condition AND tree. `data-count` reads the root's child count.
const TREE_ONE = {
  uid: 'g-root',
  kind: 'group',
  logic: 'and',
  children: [
    {
      uid: 'c1',
      kind: 'condition',
      source: 'fields.status',
      sourceType: 'enum',
      pipeline: [{ stepId: 's', operationId: 'enum_is', args: { value: 'a' }, outputType: 'boolean' }],
    },
  ],
};
const ConditionsStub = {
  name: 'WorkflowConditionsEditor',
  props: ['modelValue', 'catalog', 'formSelected', 'errors'],
  emits: ['update:modelValue'],
  setup(props: Record<string, unknown>, { emit }: { emit: (e: string, v: unknown) => void }) {
    return () =>
      h(
        'div',
        {
          class: 'conditions-stub',
          'data-count': String(((props.modelValue as { children?: unknown[] })?.children ?? []).length),
          'data-gated': String(!(props.formSelected as boolean)),
        },
        [h('button', { class: 'add-condition', onClick: () => emit('update:modelValue', TREE_ONE) }, 'add')],
      );
  },
};

// The steps stub exposes a button to seed a valid create_task step.
const StepsStub = {
  name: 'WorkflowStepListEditor',
  props: ['steps', 'catalog', 'errors'],
  emits: ['update:steps'],
  setup(props: Record<string, unknown>, { emit }: { emit: (e: string, v: unknown) => void }) {
    return () =>
      h('div', { class: 'steps-stub', 'data-has-catalog': String(!!props.catalog) }, [
        h(
          'button',
          {
            class: 'fill-step',
            onClick: () => {
              // The drawer now starts with NO step (the author adds the first one). Seed a real
              // create_task draft when the list is empty, then set its title — mirrors a user
              // adding + filling the first step; the payload shape matches the old default seed.
              const existing = props.steps as StepDraft[];
              const base = existing.length > 0 ? existing : [makeStepDraft('create_task', [])];
              const next = base.map((s) => ({ ...s, config: { ...s.config, title: 'Do it' } }));
              emit('update:steps', next);
            },
          },
          'fill',
        ),
        // R2 sub-stage 5: append a `generate_content` step (unconfigured), and a second
        // button that gives every such step a chosen template — so a test can drive the
        // drawer's client-side gate from either side.
        h(
          'button',
          {
            class: 'add-gc',
            onClick: () => {
              const existing = props.steps as StepDraft[];
              emit('update:steps', [
                ...existing,
                makeStepDraft('generate_content', existing.map((s) => s.key)),
              ]);
            },
          },
          'addGc',
        ),
        h(
          'button',
          {
            class: 'fill-gc',
            onClick: () => {
              const next = (props.steps as StepDraft[]).map((s) =>
                s.type === 'generate_content' ? { ...s, config: { ...s.config, template_id: 'tpl-1' } } : s,
              );
              emit('update:steps', next);
            },
          },
          'fillGc',
        ),
      ]);
  },
};

import WorkflowEditorDrawer from '../WorkflowEditorDrawer.vue';

function mountDrawer(workflowId: string | null = null) {
  const saved: WorkflowDetail[] = [];
  const wrapper = mount(WorkflowEditorDrawer, {
    attachTo: document.body,
    global: {
      stubs: {
        WorkflowTriggerFields: TriggerFieldsStub,
        WorkflowConditionsEditor: ConditionsStub,
        WorkflowStepListEditor: StepsStub,
      },
    },
    props: { workflowId, onSaved: (w: WorkflowDetail) => saved.push(w) },
  });
  return { wrapper, saved };
}

/**
 * Fill the name + the single step so the client-side validate() passes. Panels use
 * v-show, so the step-1 name input and the step-3 fill button are always mounted.
 */
async function makeValid(wrapper: ReturnType<typeof mount>): Promise<void> {
  await wrapper.get('input').setValue('My workflow'); // the name TextInput (first input)
  await wrapper.get('.fill-step').trigger('click');
  await nextTick();
}

/** Find a footer button by its (partial) text. */
function footerButton(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('button').find((b) => b.text().includes(text));
}

/** Click the wizard "Next" button (advances one step when the current step validates). */
async function clickNext(wrapper: ReturnType<typeof mount>): Promise<void> {
  await footerButton(wrapper, 'Next')!.trigger('click');
  await nextTick();
}

/** Click the wizard "Back" button. */
async function clickBack(wrapper: ReturnType<typeof mount>): Promise<void> {
  await footerButton(wrapper, 'Back')!.trigger('click');
  await nextTick();
}

/** The active wizard step value ('general' | 'trigger' | 'steps') from the body marker. */
function activeStep(wrapper: ReturnType<typeof mount>): string {
  return wrapper.find('[data-active-step]').attributes('data-active-step') ?? '';
}

/** Whether a wizard step (by its label) is marked with the stepper `error` status. */
function stepHasError(wrapper: ReturnType<typeof mount>, label: string): boolean {
  const li = wrapper.findAll('.next-stepper__item').find((n) => n.text().includes(label));
  // The error node renders an x icon; the error label tone uses text-next-danger.
  return !!li && li.find('.text-next-danger').exists();
}

/** Click a stepper step by label (edit mode = free nav; create mode = linear). */
async function clickStep(wrapper: ReturnType<typeof mount>, label: string): Promise<void> {
  const btn = wrapper.findAll('.next-stepper__control').find((n) => n.text().includes(label));
  await btn!.trigger('click');
  await nextTick();
}

/** Advance from step 1 (General) to step 3 (Steps) via Next×2 (create mode, linear). */
async function gotoSteps(wrapper: ReturnType<typeof mount>): Promise<void> {
  await clickNext(wrapper); // General → Trigger
  await clickNext(wrapper); // Trigger → Steps
}

/**
 * Switch the trigger TYPE to schedule via the drawer's own SegmentedControl (step 1),
 * then seed a real schedule draft through the stub (the stubbed builder doesn't seed).
 */
async function switchToSchedule(wrapper: ReturnType<typeof mount>): Promise<void> {
  const scheduleRadio = wrapper.findAll('[role="radio"]').find((r) => r.text().includes('Schedule'));
  await scheduleRadio!.trigger('click');
  await nextTick();
  await wrapper.get('.seed-schedule').trigger('click');
  await nextTick();
}

/** Navigate to step 3 (if not already there) and click Save workflow, flushing submit. */
async function save(wrapper: ReturnType<typeof mount>): Promise<void> {
  // The "Save workflow" button only renders on the last step; navigate there first.
  if (!footerButton(wrapper, 'Save workflow')) {
    await gotoSteps(wrapper);
  }
  const saveBtn = footerButton(wrapper, 'Save workflow');
  await saveBtn!.trigger('click');
  await nextTick();
  await Promise.resolve();
  await nextTick();
}

describe('WorkflowEditorDrawer', () => {
  beforeEach(() => {
    installBrowserMocks();
    createWorkflow.mockReset();
    updateWorkflow.mockReset();
    fetchWorkflowCatalog.mockReset();
    toastSuccess.mockReset();
    toastDanger.mockReset();
    toastInfo.mockReset();
    detailRef.value = null;
    createWorkflow.mockImplementation(async (payload) => ({ id: 'new', ...payload }));
    updateWorkflow.mockImplementation(async (_id, payload) => ({ id: _id, ...payload }));
    fetchWorkflowCatalog.mockResolvedValue(CATALOG);
  });
  afterEach(() => restoreBrowserMocks());

  it('a NEW workflow starts with NO step — the author adds the first one (no default seed)', () => {
    const { wrapper } = mountDrawer();
    // The steps list is seeded empty; the >=1-step Save gate (backend min:1) still requires
    // the author to add at least one before the workflow can be created.
    expect((wrapper.findComponent(StepsStub).props('steps') as StepDraft[]).length).toBe(0);
  });

  it('form select → fetches the catalog, conditions become enabled, steps get the catalog', async () => {
    const { wrapper } = mountDrawer();

    // Before a form: conditions gated (formSelected=false), steps have no catalog.
    expect(wrapper.get('.conditions-stub').attributes('data-gated')).toBe('true');
    expect(wrapper.get('.steps-stub').attributes('data-has-catalog')).toBe('false');

    await wrapper.get('.pick-a').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    expect(fetchWorkflowCatalog).toHaveBeenCalledWith('form_submitted', 'form-a');
    expect(wrapper.get('.conditions-stub').attributes('data-gated')).toBe('false');
    expect(wrapper.get('.steps-stub').attributes('data-has-catalog')).toBe('true');
  });

  it('form CHANGE clears conditions with a one-time toast', async () => {
    const { wrapper } = mountDrawer();

    await wrapper.get('.pick-a').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    // Add a condition, then change the form → conditions cleared + a toast.
    await wrapper.get('.add-condition').trigger('click');
    await nextTick();
    expect(wrapper.get('.conditions-stub').attributes('data-count')).toBe('1');

    await wrapper.get('.pick-b').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    expect(fetchWorkflowCatalog).toHaveBeenLastCalledWith('form_submitted', 'form-b');
    expect(wrapper.get('.conditions-stub').attributes('data-count')).toBe('0');
    expect(toastInfo).toHaveBeenCalledTimes(1);
  });

  it('clearing the form disables + clears conditions (no toast when there were none)', async () => {
    const { wrapper } = mountDrawer();
    await wrapper.get('.pick-a').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();
    toastInfo.mockReset();

    await wrapper.get('.clear-form').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    // Conditions are gated (no form). The steps section KEEPS a catalog — clearing the form
    // now refetches the FORM-INDEPENDENT catalog (trigger vars + step outputs) instead of
    // nulling it, so a form-less form_submitted still offers step/trigger variables.
    expect(wrapper.get('.conditions-stub').attributes('data-gated')).toBe('true');
    expect(wrapper.get('.steps-stub').attributes('data-has-catalog')).toBe('true');
    expect(toastInfo).not.toHaveBeenCalled();
  });

  it('schedule trigger HIDES the conditions section entirely', async () => {
    const { wrapper } = mountDrawer();
    expect(wrapper.find('.conditions-stub').exists()).toBe(true);

    await switchToSchedule(wrapper);

    expect(wrapper.find('.conditions-stub').exists()).toBe(false);
    expect(wrapper.get('.trigger-type').text()).toBe('schedule');
  });

  it('SAVE payload — form_submitted wire (form_id + source + anonymous + typed conditions)', async () => {
    const { wrapper } = mountDrawer();
    await makeValid(wrapper);

    await wrapper.get('.pick-a').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();
    await wrapper.get('.set-source').trigger('click');
    await wrapper.get('.set-anon').trigger('click');
    await wrapper.get('.add-condition').trigger('click');
    await nextTick();

    await save(wrapper);

    expect(createWorkflow).toHaveBeenCalledTimes(1);
    const payload = createWorkflow.mock.calls[0][0];
    expect(payload).toEqual({
      name: 'My workflow',
      description: null,
      icon: null,
      trigger_type: 'form_submitted',
      steps: [{ type: 'create_task', key: 'task', config: { title: 'Do it' } }],
      trigger_config: { form_id: 'form-a', source: { in: ['manual'] }, anonymous: true },
      // The condition TREE wire (root without `kind`; pipeline steps as {op, args}).
      conditions: {
        logic: 'and',
        children: [{ kind: 'condition', source: 'fields.status', source_type: 'enum', pipeline: [{ op: 'enum_is', args: { value: 'a' } }] }],
      },
    });
    expect(toastSuccess).toHaveBeenCalled();
  });

  it('SAVE payload — schedule wire (schedule config, no conditions key)', async () => {
    const { wrapper } = mountDrawer();
    await makeValid(wrapper);

    await switchToSchedule(wrapper);

    await save(wrapper);

    expect(createWorkflow).toHaveBeenCalledTimes(1);
    const payload = createWorkflow.mock.calls[0][0];
    expect(payload.trigger_type).toBe('schedule');
    expect(payload.trigger_config).toEqual({ schedule: { time: { mode: 'at', at: ['09:00'] } } });
    expect('conditions' in payload).toBe(false);
  });

  it('SAVE payload — schedule v2 wire (multi-time × weekdays + exclusions + tz)', async () => {
    const { wrapper } = mountDrawer();
    await makeValid(wrapper);

    // Switch type via the drawer's SegmentedControl, then drive a richer v2 draft directly.
    const scheduleRadio = wrapper.findAll('[role="radio"]').find((r) => r.text().includes('Schedule'));
    await scheduleRadio!.trigger('click');
    await nextTick();
    const trigger = wrapper.findComponent(TriggerFieldsStub);
    trigger.vm.$emit('update:scheduleDraft', {
      time: { mode: 'at', at: ['08:00', '17:00'] },
      day: { mode: 'weekdays', weekdays: [1, 3] },
      month: { mode: 'every_month' },
      tz: 'Europe/Warsaw',
      exclusions: { months: [8], weekdays: [0, 6], dates: ['2026-12-24'] },
    });
    await nextTick();

    await save(wrapper);

    const payload = createWorkflow.mock.calls[0][0];
    // draftToConfig emits the FLAT wire: time.at + day.weekdays, omits every_month, and
    // emits exclusions with only the present keys + the set tz.
    expect(payload.trigger_config.schedule).toEqual({
      time: { mode: 'at', at: ['08:00', '17:00'] },
      day: { mode: 'weekdays', weekdays: [1, 3] },
      tz: 'Europe/Warsaw',
      exclusions: { months: [8], weekdays: [0, 6], dates: ['2026-12-24'] },
    });
  });

  it('422 routing — trigger_config.form_id / schedule param / conditions.N / steps.N.config', async () => {
    const { wrapper } = mountDrawer();
    await makeValid(wrapper);
    await wrapper.get('.pick-a').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    createWorkflow.mockRejectedValueOnce({
      response: {
        data: {
          errors: {
            'trigger_config.form_id': ['Form required.'],
            'conditions.0.operator': ['Bad operator.'],
            'steps.0.config.title': ['Title required.'],
          },
        },
      },
    });

    await save(wrapper);

    // The errors object is passed to every child stub via props; assert the drawer
    // routed each wire key verbatim by reading the stub's received `errors` prop.
    const trigger = wrapper.findComponent(TriggerFieldsStub);
    const conditions = wrapper.findComponent(ConditionsStub);
    const steps = wrapper.findComponent(StepsStub);
    expect((trigger.props('errors') as Record<string, string>)['trigger_config.form_id']).toBe('Form required.');
    expect((conditions.props('errors') as Record<string, string>)['conditions.0.operator']).toBe('Bad operator.');
    expect((steps.props('errors') as Record<string, string>)['steps.0.config.title']).toBe('Title required.');
    expect(toastDanger).toHaveBeenCalled();
  });

  it('edit seed — hydrates a LEGACY flat condition list into an editable TREE + saves the tree', async () => {
    detailRef.value = {
      id: 'wf1',
      name: 'Seeded',
      status: 'inactive',
      description: 'desc',
      icon: null,
      trigger_type: 'form_submitted',
      trigger_config: { form_id: 'form-a', source: { in: ['task'] }, anonymous: false },
      // A legacy FLAT list — B3 converts it to a single AND group (operator → op table).
      conditions: [{ field: 'fields.status', field_type: 'enum', operator: 'is', value: 'a' }],
      steps: [{ type: 'create_task', key: 'task', config: { title: 'Existing' } }],
      last_scheduled_run_at: null,
      next_due_at: null,
      is_owner: true,
      can_be_edited: true,
      can_be_deleted: true,
      can_change_status: true,
      can_run: true,
      created_at: null,
      updated_at: null,
    };

    const { wrapper } = mountDrawer('wf1');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    // The seeded form triggers a catalog fetch on mount; the flat list became a 1-child tree.
    expect(fetchWorkflowCatalog).toHaveBeenCalledWith('form_submitted', 'form-a');
    expect(wrapper.get('.conditions-stub').attributes('data-count')).toBe('1');

    await save(wrapper);
    const payload = updateWorkflow.mock.calls[0][1];
    expect(payload.trigger_config).toEqual({ form_id: 'form-a', source: { in: ['task'] }, anonymous: false });
    // Saved as the TREE wire (the legacy `is` operator → enum_is).
    expect(payload.conditions).toEqual({
      logic: 'and',
      children: [{ kind: 'condition', source: 'fields.status', source_type: 'enum', pipeline: [{ op: 'enum_is', args: { value: 'a' } }] }],
    });
  });

  // --- B7 wizard behaviour ---------------------------------------------------

  it('Next with an empty name blocks the step change and shows the name error', async () => {
    const { wrapper } = mountDrawer();
    // Step 1 is active; leave the name blank.
    expect(activeStep(wrapper)).toBe('general');

    await clickNext(wrapper);

    // Still on step 1; the name FormField shows the required error; the step is flagged.
    expect(activeStep(wrapper)).toBe('general');
    expect(wrapper.text()).toContain('A name is required.');
    expect(stepHasError(wrapper, 'General')).toBe(true);

    // Filling the name and retrying advances (and clears the flag).
    await wrapper.get('input').setValue('Named');
    await clickNext(wrapper);
    expect(activeStep(wrapper)).toBe('trigger');
    expect(stepHasError(wrapper, 'General')).toBe(false);
  });

  it('changing the trigger type on step 1 changes step 2 content (conditions gone for schedule)', async () => {
    const { wrapper } = mountDrawer();

    // Default form_submitted → step 2 shows the conditions section.
    await wrapper.get('input').setValue('Named');
    await clickNext(wrapper);
    expect(activeStep(wrapper)).toBe('trigger');
    expect(wrapper.find('.conditions-stub').exists()).toBe(true);
    expect(wrapper.get('.trigger-type').text()).toBe('form_submitted');

    // Back to step 1, switch to schedule → step 2 drops the conditions section.
    await clickBack(wrapper);
    const scheduleRadio = wrapper.findAll('[role="radio"]').find((r) => r.text().includes('Schedule'));
    await scheduleRadio!.trigger('click');
    await wrapper.get('.seed-schedule').trigger('click');
    await nextTick();
    await clickNext(wrapper);
    expect(activeStep(wrapper)).toBe('trigger');
    expect(wrapper.find('.conditions-stub').exists()).toBe(false);
    expect(wrapper.get('.trigger-type').text()).toBe('schedule');
  });

  it('422 on name (from step 3) jumps back to step 1 and marks it with the error status', async () => {
    const { wrapper } = mountDrawer();
    await makeValid(wrapper);
    // Navigate all the way to step 3 and submit; the server rejects the name.
    await gotoSteps(wrapper);
    expect(activeStep(wrapper)).toBe('steps');

    createWorkflow.mockRejectedValueOnce({
      response: { data: { errors: { name: ['Name is taken.'] } } },
    });

    await save(wrapper);

    // The wizard jumps to the earliest erroring step (General) + flags it.
    expect(activeStep(wrapper)).toBe('general');
    expect(stepHasError(wrapper, 'General')).toBe(true);
    expect(wrapper.text()).toContain('Name is taken.');
    expect(toastDanger).toHaveBeenCalled();
  });

  it('edit mode allows clicking straight to step 3 (free navigation)', async () => {
    detailRef.value = {
      id: 'wf2',
      name: 'Editable',
      status: 'inactive',
      description: '',
      icon: null,
      trigger_type: 'schedule',
      trigger_config: { schedule: { time: { mode: 'at', at: ['09:00'] } } },
      conditions: [],
      steps: [{ type: 'create_task', key: 'task', config: { title: 'Existing' } }],
      last_scheduled_run_at: null,
      next_due_at: null,
      is_owner: true,
      can_be_edited: true,
      can_be_deleted: true,
      can_change_status: true,
      can_run: true,
      created_at: null,
      updated_at: null,
    };

    const { wrapper } = mountDrawer('wf2');
    await nextTick();

    // Start on step 1; click the Steps step directly (edit mode is NOT linear).
    expect(activeStep(wrapper)).toBe('general');
    await clickStep(wrapper, 'Steps');
    expect(activeStep(wrapper)).toBe('steps');
    // The Save workflow button is available on the last step.
    expect(footerButton(wrapper, 'Save workflow')).toBeTruthy();
  });

  // --- generate_content client gates (R2 sub-stage 5) ------------------------
  // The drawer owns the part it can decide from the DRAFT alone: a recipe must be chosen,
  // and at most TWO generate_content steps per workflow (the backend budget guard). The
  // per-SLOT rules need the chosen template's declarations and are evaluated by the step
  // card, which bubbles its verdict through the same `type-error` gate.

  it('blocks Save when a generate_content step has no template chosen', async () => {
    const { wrapper } = mountDrawer();
    await wrapper.get('input').setValue('My workflow');
    await gotoSteps(wrapper);
    await wrapper.get('.add-gc').trigger('click');
    await nextTick();

    await save(wrapper);

    // No round-trip, and the error lands on the exact field the server would name.
    expect(createWorkflow).not.toHaveBeenCalled();
    expect(activeStep(wrapper)).toBe('steps');
    expect(wrapper.findComponent(StepsStub).props('errors')).toHaveProperty(
      'steps.0.config.template_id',
    );
  });

  it('SAVE payload — generate_content emits ONLY template_id when nothing else is set', async () => {
    const { wrapper } = mountDrawer();
    await wrapper.get('input').setValue('My workflow');
    await gotoSteps(wrapper);
    await wrapper.get('.add-gc').trigger('click');
    await nextTick();
    await wrapper.get('.fill-gc').trigger('click');
    await nextTick();

    await save(wrapper);

    expect(createWorkflow).toHaveBeenCalledTimes(1);
    const payload = createWorkflow.mock.calls[0][0];
    expect(payload.steps).toEqual([
      { type: 'generate_content', key: 'content', config: { template_id: 'tpl-1' } },
    ]);
  });

  it('rejects the THIRD generate_content step on its own row (max 2 per workflow)', async () => {
    const { wrapper } = mountDrawer();
    await wrapper.get('input').setValue('My workflow');
    await gotoSteps(wrapper);
    for (let i = 0; i < 3; i += 1) {
      await wrapper.get('.add-gc').trigger('click');
      await nextTick();
    }
    await wrapper.get('.fill-gc').trigger('click');
    await nextTick();

    await save(wrapper);

    expect(createWorkflow).not.toHaveBeenCalled();
    const errors = wrapper.findComponent(StepsStub).props('errors') as Record<string, string>;
    // Only the OFFENDING (third) step is flagged — the first two are fine.
    expect(errors['steps.2.type']).toBeTruthy();
    expect(errors['steps.0.type']).toBeUndefined();
    expect(errors['steps.1.type']).toBeUndefined();
  });
});
