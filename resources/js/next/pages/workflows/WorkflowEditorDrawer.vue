<script setup lang="ts">
// WorkflowEditorDrawer — create / edit a workflow (§4, 5.1 rebuild → B7 wizard).
//
// Hosted inside WorkflowsModuleLayout's query-driven `size="cover"` Drawer
// (`?workflow=new` · `?workflow=<id>`). SLIM header (title only). B7 replaced the
// single-scroll body with a 3-STEP WIZARD driven by the shared `Stepper` primitive:
//   1. General — name, icon, description + the trigger-TYPE SegmentedControl.
//   2. Trigger — the per-type fields (WorkflowTriggerFields); for form_submitted ALSO
//      the conditions builder (conditions belong to the trigger).
//   3. Steps   — the step-list editor.
// Anuluj / Wstecz / Dalej / Zapisz przepływ live in the app-wide STICKY FOOTER (actions
// NEVER move into the header). Only the active step's panel renders; the schedule/steps
// builders re-seed from drawer-owned state on remount, so a remount is safe.
//
// The drawer OWNS the whole trigger/conditions/steps state, the wizard step + its
// per-step validation, and the payload assembly:
//   • trigger TYPE → SegmentedControl here (step 1); switching type resets the new
//     type's sub-state + clears conditions/catalog (side-effects live in the drawer).
//   • trigger fields → WorkflowTriggerFields (:type / v-model:formConfig / :scheduleDraft).
//   • CATALOG LIFECYCLE (§4.8 host duties): the editor ALWAYS fetches a catalog for the
//     current trigger via fetchWorkflowCatalog(triggerType, form_id?). A schedule/form-less
//     workflow gets a form-INDEPENDENT catalog (trigger-system + step-output variables); a
//     form_submitted workflow with a form selected ALSO layers that form's field vars. The
//     conditions loading/error/retry UI is gated on formSelected (conditions need the FORM
//     catalog's `fields`); the STEPS section surfaces catalog errors too (ungated) so a
//     form-less fetch failure is never a silent empty picker. When the form CHANGES →
//     refetch + CLEAR conditions with a one-time toast (workflows.condition.clearedOnFormChange).
//     When the trigger is schedule → the conditions section is HIDDEN entirely (the backend
//     rejects conditions on a schedule trigger).
//   • SAVE (step 3) → builds the EXACT WorkflowWritePayload: trigger_config per type
//     (form: {form_id, source?, anonymous?}, schedule: draftToConfig), typed conditions
//     (form trigger + a form selected only), steps via buildStepConfig. Client gates:
//     schedule builder isValid, required step fields non-blank, complete condition rows.
//   • PER-STEP GATE (Dalej) → validates only the CURRENT step and blocks with inline
//     errors; a click into an EARLIER step never validates (free back-nav; edit mode is
//     fully free-nav, create mode is `linear`).
//   • 422 MAP (§4.10): route name/description/icon, trigger_config.* (incl. schedule
//     params), conditions.N.*, steps.N.key / steps.N.config.*, steps top-level;
//     unmappable → danger toast. After a 422 the wizard JUMPS to the earliest step that
//     carries an error and marks the erroring steps `error` in the stepper.
import { computed, reactive, ref } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import IconInput from '../../ui/forms/IconInput.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import Button from '../../ui/primitives/Button.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Stepper, { type StepItem, type StepStatus } from '../../ui/navigation/Stepper.vue';
import WorkflowTriggerFields from './WorkflowTriggerFields.vue';
import WorkflowConditionsEditor from './WorkflowConditionsEditor.vue';
import WorkflowStepListEditor from './WorkflowStepListEditor.vue';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { TRIGGER_TYPES, triggerIcon, triggerLabel } from './workflowMeta';
import type { IconName } from '../../ui/primitives/icons';
import type {
  FormSubmittedTriggerConfig,
  ScheduleTriggerConfig,
  SubmissionSource,
  WorkflowCatalog,
  WorkflowDetail,
  WorkflowScheduleConfig,
  WorkflowStep,
  WorkflowTriggerType,
  WorkflowWritePayload,
} from './types';
import {
  buildStepConfig,
  duplicateKeyUids,
  emptyFormTriggerDraft,
  makeStepDraft,
  nextUid,
  MAX_STEPS,
  STEP_KEY_RE,
  type FormTriggerDraft,
  type StepDraft,
} from './workflowEditorModel';
import {
  draftToWire,
  emptyConditionTree,
  isTreeComplete,
  resolveOperationCatalog,
  wireToDraft,
  type DraftConditionGroup,
} from './workflowConditions';
import {
  configToDraft,
  draftToConfig,
  emptyScheduleDraft,
  type ScheduleDraft,
} from './workflowSchedule';

/** The three wizard steps, in order. */
type WizardStep = 'general' | 'trigger' | 'steps';
const WIZARD_ORDER: WizardStep[] = ['general', 'trigger', 'steps'];

const props = defineProps<{
  /** Workflow id to edit, or null to create a new one. */
  workflowId: string | null;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', workflow: WorkflowDetail): void;
}>();

const { t } = useI18n();
const store = useWorkflowsStore();
const toast = useToast();

function clonePlain<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

const isEdit = computed(() => props.workflowId !== null);

// --- Local editor draft ----------------------------------------------------
// Trigger is typed 5.1: a discriminating `triggerType` + the form draft + the
// schedule draft (only the selected type's slice is ever emitted). Conditions are
// the typed §4.8 array; steps are StepDrafts with stable local uids. No status.
const form = reactive<{
  name: string;
  description: string;
  icon: string | null;
  triggerType: WorkflowTriggerType;
  formTrigger: FormTriggerDraft;
  scheduleDraft: ScheduleDraft;
  conditionsTree: DraftConditionGroup;
  steps: StepDraft[];
}>({
  name: '',
  description: '',
  icon: null,
  triggerType: 'form_submitted',
  formTrigger: emptyFormTriggerDraft(),
  // The neutral v2 schedule draft (once daily at 09:00, §4.5.1).
  scheduleDraft: emptyScheduleDraft(),
  // The B3 condition TREE draft (empty AND group = "always runs").
  conditionsTree: emptyConditionTree(),
  steps: [makeStepDraft('create_task', [])],
});

/** The FormSelect seed (the currently-attached form's name) for the trigger panel. */
const formSeed = ref<Array<{ id: string; name: string; icon?: string | null }>>([]);

/** The user's active timezone, sent to the schedule assist (§4.5.4). */
const activeTz = ref<string | null>(null);
try {
  activeTz.value = Intl.DateTimeFormat().resolvedOptions().timeZone || null;
} catch {
  activeTz.value = null;
}

// REV5: the FRESH-schedule tz seed moved INTO `emptyScheduleDraft()` (the sole allowed
// touch of the draft model, §4.5.8) — it already seeds the resolved browser zone, so the
// wall-clock sentence + preview run in the viewer's zone. An EDITED schedule keeps its
// saved tz — seedFromDetail() overrides the draft below via configToDraft.

// Seed ONCE from the prefetched detail when editing (the layout keys this component
// by id, so it remounts + re-seeds per workflow → setup runs fresh).
const detailError = ref(false);
if (isEdit.value) {
  const detail = store.detail && store.detail.id === props.workflowId ? store.detail : null;
  if (detail) {
    seedFromDetail(clonePlain(detail));
  } else {
    detailError.value = true;
  }
}

function seedFromDetail(d: WorkflowDetail): void {
  form.name = d.name;
  form.description = d.description ?? '';
  form.icon = d.icon;
  form.triggerType = d.trigger_type;

  const cfg = d.trigger_config ?? {};

  if (d.trigger_type === 'form_submitted') {
    form.formTrigger = {
      form_id: cfg.form_id ?? null,
      source: Array.isArray(cfg.source?.in) ? [...(cfg.source!.in as SubmissionSource[])] : [],
      anonymous: cfg.anonymous ?? null,
    };
    // Seed the FormSelect label. The write body carries no form name, so seed the id
    // as a placeholder label; the FormSelect resolves the real name from its own page.
    if (form.formTrigger.form_id) {
      formSeed.value = [{ id: form.formTrigger.form_id, name: form.formTrigger.form_id }];
    }
  }

  if (d.trigger_type === 'schedule' && cfg.schedule) {
    form.scheduleDraft = configToDraft(cfg.schedule as WorkflowScheduleConfig);
  }

  // Conditions: the B3 TREE. wireToDraft hydrates BOTH the tree shape and a LEGACY
  // flat list (→ a single AND group; operator→op table). The conversion of a flat
  // "not_equals"/"is_not"/"excludes" changes the missing-field semantics (documented
  // in workflowConditions.ts) — a conscious difference when editing OLD workflows.
  form.conditionsTree = wireToDraft(d.conditions);

  // Steps: merge each saved config over the type's fully-shaped empty draft so every
  // control renders a defined value (B7c note #4).
  form.steps = (d.steps ?? []).map((s) => seedStep(s));
  if (form.steps.length === 0) form.steps = [makeStepDraft('create_task', [])];
}

function seedStep(s: WorkflowStep): StepDraft {
  const empty = makeStepDraft(s.type, []);
  return {
    uid: nextUid('step'),
    type: s.type,
    key: s.key,
    config: { ...empty.config, ...(s.config ?? {}) },
  };
}

// IconInput speaks IconName | null; store icon strings, bridge with a cast.
const iconModel = computed<IconName | null>({
  get: () => (form.icon ? (form.icon as IconName) : null),
  set: (value) => {
    form.icon = value ?? null;
  },
});

// --- Trigger-type SegmentedControl (step 1, moved here from the trigger panel) ---
// The type SELECTOR + all its side-effects live in the drawer now: switching type
// resets the new type's sub-state (the backend rejects cross-type keys), clears
// conditions/catalog, and warns when the old type carried settings.
const typeOptions = computed<SegmentOption<WorkflowTriggerType>[]>(() =>
  TRIGGER_TYPES.map((type) => ({
    value: type,
    label: triggerLabel(type, t),
    icon: triggerIcon(type),
    description: t(`workflows.trigger.${type}.description`),
  })),
);

/** Whether the CURRENT type holds meaningful sub-state (so a switch clears something). */
function hasTargeting(type: WorkflowTriggerType): boolean {
  if (type === 'form_submitted') {
    const f = form.formTrigger;
    return f.form_id !== null || f.source.length > 0 || f.anonymous !== null;
  }
  // A schedule always carries a family — switching away always clears it.
  return true;
}

const showTypeChangeWarning = ref(false);

const typeModel = computed<WorkflowTriggerType | null>({
  get: () => form.triggerType,
  set: (next) => onTypeChange(next),
});

function onTypeChange(next: WorkflowTriggerType | null): void {
  const value = next ?? 'form_submitted';
  if (value === form.triggerType) return;
  const cleared = hasTargeting(form.triggerType);
  form.triggerType = value;
  // Reset the NEW type's sub-state to its empty shape. The schedule draft self-seeds in
  // the builder on mount; the form draft is reset here. Either direction drops the form
  // → the drawer clears conditions + catalog via onFormChange(null).
  if (value === 'form_submitted') {
    form.formTrigger = emptyFormTriggerDraft();
  }
  onFormChange(null);
  showTypeChangeWarning.value = cleared;
}

// --- Catalog lifecycle (§4.8 host duties) ----------------------------------
// The catalog is now FORM-INDEPENDENT: EVERY trigger (schedule included) fetches a real
// catalog (trigger-system vars + `steps.<TYPE>.*` step outputs + operations + types) via
// the store's `GET /workflows/catalog?trigger_type=&form_id=`. A form_submitted workflow
// ALSO layers in the selected form's field vars (form_id). `catalogKey` tracks the
// (triggerType, formId) the current catalog belongs to so a form change knows to clear
// conditions. The conditions section's loading/error UI stays gated on `formSelected`
// (the FORM catalog); the steps section silently benefits from the form-independent one.
const catalog = ref<WorkflowCatalog | null>(null);
const catalogLoading = ref(false);
const catalogError = ref(false);
/** The (triggerType, formId) the current catalog belongs to (guards clear-on-form-change). */
let catalogKey: string | null = null;

/** Whether the conditions section is shown at all (hidden for schedule, §4.8). */
const showConditions = computed(() => form.triggerType === 'form_submitted');
/** Whether a form is selected (the conditions gate). */
const formSelected = computed(
  () => form.triggerType === 'form_submitted' && form.formTrigger.form_id !== null,
);
/** The merged operations catalog — the condition save gate (isTreeComplete) needs it. */
const operationsCatalog = computed(() => resolveOperationCatalog(catalog.value));

/** The form id that scopes the catalog (form_submitted only; schedule carries none). */
function catalogFormId(): string | null {
  return form.triggerType === 'form_submitted' ? form.formTrigger.form_id : null;
}
function keyOf(triggerType: WorkflowTriggerType, formId: string | null): string {
  return `${triggerType}:${formId ?? ''}`;
}

async function loadCatalog(triggerType: WorkflowTriggerType, formId: string | null): Promise<void> {
  catalogLoading.value = true;
  catalogError.value = false;
  try {
    catalog.value = await store.fetchWorkflowCatalog(triggerType, formId);
    catalogKey = keyOf(triggerType, formId);
  } catch {
    catalogError.value = true;
    catalog.value = null;
    catalogKey = null;
  } finally {
    catalogLoading.value = false;
  }
}

/** (Re)load the catalog for the CURRENT trigger type + form selection. */
function reloadCatalog(): void {
  void loadCatalog(form.triggerType, catalogFormId());
}

/**
 * React to a form_id change from the trigger panel (§4.4a / §4.8) — also the path a
 * trigger-TYPE change funnels through (onTypeChange → onFormChange(null)). A different
 * (triggerType, formId) invalidates the conditions (their field paths belong to the old
 * form's schema) → clear them with a one-time toast when clearing actually dropped rows,
 * then (re)fetch the FORM-INDEPENDENT catalog for the new target. Schedule / form-less now
 * fetches a REAL catalog (trigger vars + step outputs) instead of nulling it.
 */
function onFormChange(nextFormId: string | null): void {
  if (keyOf(form.triggerType, nextFormId) === catalogKey) return;

  const hadConditions = form.conditionsTree.children.length > 0;
  form.conditionsTree = emptyConditionTree();
  if (hadConditions) toast.info(t('workflows.condition.clearedOnFormChange'));

  clearScopedErrors();

  void loadCatalog(form.triggerType, nextFormId);
}

/** On mount / seed: fetch the form-independent catalog for the seeded trigger + form. */
reloadCatalog();

function onStepsUpdate(next: StepDraft[]): void {
  form.steps = next;
}

/**
 * Whether any step has a value-or-variable field whose SAVED pipeline does not satisfy
 * the field (a type/choice mismatch, bubbled from the step cards). Feeds the Save gate
 * so we block before the server 422s — the field + row badge already point at the fix.
 */
const stepsHaveTypeErrors = ref(false);
function onStepTypeErrors(hasAny: boolean): void {
  stepsHaveTypeErrors.value = hasAny;
}

// --- Validation (client-side, mirrors the FormRequest, §4.10) --------------
const errors = reactive<Record<string, string>>({});

function clearErrors(): void {
  Object.keys(errors).forEach((k) => delete errors[k]);
}

/** Drop the trigger/conditions errors that a form change invalidates. */
function clearScopedErrors(): void {
  Object.keys(errors).forEach((k) => {
    if (k.startsWith('trigger_config.') || k.startsWith('conditions.')) delete errors[k];
  });
}

const triggerRef = ref<InstanceType<typeof WorkflowTriggerFields> | null>(null);

/** Drop only the error keys a given wizard step owns (so a per-step re-validate is clean). */
function clearStepErrors(step: WizardStep): void {
  Object.keys(errors).forEach((k) => {
    if (stepForErrorKey(k) === step) delete errors[k];
  });
}

/** Step 1 — the name is the only client-side gate (icon/description are free-form). */
function validateGeneral(): boolean {
  clearStepErrors('general');
  if (!form.name.trim()) {
    errors['name'] = t('workflows.editor.validation.nameRequired');
    return false;
  }
  return true;
}

/**
 * Step 2 — the trigger + its conditions. Schedule: the builder's own live validation
 * (bounds + lt + a non-empty preview) must pass. form_submitted: we do NOT force a form
 * (the backend accepts "any form"), but the condition TREE must be complete — every
 * leaf resolves to a boolean and every group has ≥1 child (an empty tree is valid).
 */
function validateTrigger(): boolean {
  clearStepErrors('trigger');
  let ok = true;

  if (form.triggerType === 'schedule') {
    if (!triggerRef.value || !triggerRef.value.scheduleValid()) {
      errors['trigger_config.schedule'] = t('workflows.editor.validation.scheduleInvalid');
      ok = false;
    }
    return ok;
  }

  // form_submitted: the condition TREE must be complete (every leaf resolves to a
  // boolean; every group has ≥1 child). An EMPTY tree is valid ("always runs").
  if (formSelected.value && !isTreeComplete(form.conditionsTree, operationsCatalog.value)) {
    errors['conditions'] = t('workflows.condition.validation.treeIncomplete');
    ok = false;
  }
  return ok;
}

/** Step 3 — at least one step, unique non-blank keys, required config per type. */
function validateSteps(): boolean {
  clearStepErrors('steps');
  let ok = true;

  // At least one step (backend min:1 — the UI also prevents removing the last).
  if (form.steps.length < 1) {
    errors['steps'] = t('workflows.editor.validation.stepRequired');
    ok = false;
  }
  // At most MAX_STEPS (backend max:50 — the add cards also disable at the ceiling).
  if (form.steps.length > MAX_STEPS) {
    errors['steps'] = t('workflows.step.maxSteps');
    ok = false;
  }

  const dupes = duplicateKeyUids(form.steps);
  form.steps.forEach((s, i) => {
    const key = s.key.trim();
    if (!key) {
      errors[`steps.${i}.key`] = t('workflows.step.validation.keyRequired');
      ok = false;
    } else if (!STEP_KEY_RE.test(key)) {
      // A dot/space in the key would break `steps.<key>.<name>` references.
      errors[`steps.${i}.key`] = t('workflows.step.validation.keyInvalid');
      ok = false;
    }
    if (dupes.has(s.uid)) {
      errors[`steps.${i}.key`] = t('workflows.step.validation.keyDuplicate');
      ok = false;
    }
    const cfg = s.config ?? {};
    const blank = (k: string): boolean => {
      const v = cfg[k];
      return v == null || String(v).trim() === '';
    };
    if (s.type === 'create_task' && blank('title')) {
      errors[`steps.${i}.config.title`] = t('workflows.step.validation.configRequired');
      ok = false;
    }
    if (s.type === 'create_form_report') {
      if (blank('form_id')) errors[`steps.${i}.config.form_id`] = t('workflows.step.validation.configRequired');
      if (blank('name')) errors[`steps.${i}.config.name`] = t('workflows.step.validation.configRequired');
      if (blank('form_id') || blank('name')) ok = false;
    }
  });

  // A value-or-variable field type/choice mismatch (bubbled from the cards) blocks Save
  // — the offending field + its step row already carry the specific error surfacing.
  if (stepsHaveTypeErrors.value) {
    if (!errors['steps']) errors['steps'] = t('workflows.editor.validation.fieldTypeErrors');
    ok = false;
  }

  return ok;
}

/** Validate one wizard step (used by the Dalej gate). */
function validateStep(step: WizardStep): boolean {
  if (step === 'general') return validateGeneral();
  if (step === 'trigger') return validateTrigger();
  return validateSteps();
}

/** Validate every step (used before SAVE); returns whether ALL passed. */
function validate(): boolean {
  clearErrors();
  // Run each so its errors accumulate; `&&`-short-circuit would skip later steps.
  const g = validateGeneral();
  const tr = validateTrigger();
  const st = validateSteps();
  return g && tr && st;
}

// --- Payload builder (the EXACT wire, §4.4) --------------------------------

/** Build the form_submitted trigger_config: form_id + source (only when non-empty) + anonymous (only when not-any). */
function buildFormTriggerConfig(): FormSubmittedTriggerConfig {
  const cfg: FormSubmittedTriggerConfig = { form_id: form.formTrigger.form_id };
  if (form.formTrigger.source.length > 0) {
    cfg.source = { in: [...form.formTrigger.source] };
  }
  if (form.formTrigger.anonymous !== null) {
    cfg.anonymous = form.formTrigger.anonymous;
  }
  return cfg;
}

/** Build the schedule trigger_config from the v2 draft (§4.5.1). */
function buildScheduleTriggerConfig(): ScheduleTriggerConfig {
  // v2 has no families vocabulary; draftToConfig emits the flat wire (time × day × month).
  return { schedule: draftToConfig(form.scheduleDraft) };
}

function buildPayload(): WorkflowWritePayload {
  const payload: WorkflowWritePayload = {
    name: form.name.trim(),
    description: form.description.trim() || null,
    icon: form.icon || null,
    trigger_type: form.triggerType,
    steps: form.steps.map((s) => ({ type: s.type, key: s.key.trim(), config: buildStepConfig(s) })),
  };

  if (form.triggerType === 'form_submitted') {
    payload.trigger_config = buildFormTriggerConfig();
    // The condition TREE, only when a form is selected (§4.8). draftToWire OMITS an
    // empty tree (emit-or-omit) → the `conditions` key is dropped entirely.
    const wire = formSelected.value ? draftToWire(form.conditionsTree) : undefined;
    if (wire) payload.conditions = wire;
  } else {
    payload.trigger_config = buildScheduleTriggerConfig();
  }

  return payload;
}

// --- Submit ----------------------------------------------------------------
const saving = ref(false);

/**
 * Map a server 422 bag onto the flat error map (§4.10). Wire paths pass through
 * verbatim so each child section reads its own slice: name/description/icon,
 * trigger_config.* (incl. trigger_config.schedule.params.<name>), conditions.<i>.*,
 * steps.<i>.key / steps.<i>.config.* (incl. union sub-keys). Any un-mappable key
 * leaves the generic danger toast to speak.
 */
function applyServerErrors(err: unknown): boolean {
  const bag = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
  if (!bag) return false;
  let mapped = false;
  Object.entries(bag).forEach(([key, msgs]) => {
    if (!msgs?.length) return;
    errors[key] = msgs[0];
    mapped = true;
  });
  return mapped;
}

async function onSubmit(): Promise<void> {
  if (saving.value) return;
  if (!validate()) {
    jumpToFirstErrorStep();
    return;
  }
  saving.value = true;
  const payload = buildPayload();
  try {
    const result =
      isEdit.value && props.workflowId
        ? await store.updateWorkflow(props.workflowId, payload)
        : await store.createWorkflow(payload);
    toast.success(t(isEdit.value ? 'workflows.editor.toasts.updated' : 'workflows.editor.toasts.created'));
    emit('saved', result);
  } catch (err: unknown) {
    clearErrors();
    applyServerErrors(err);
    // Jump to the earliest step carrying a 422 error + mark it in the stepper.
    jumpToFirstErrorStep();
    toast.danger(t('workflows.editor.toasts.error'));
  } finally {
    saving.value = false;
  }
}

function onCancel(): void {
  emit('close');
}

// --- Wizard (3-step stepper, B7) -------------------------------------------
const activeStep = ref<WizardStep>('general');

/** Map a (client OR server) error key onto the wizard step that owns it (§4.10). */
function stepForErrorKey(key: string): WizardStep {
  if (key.startsWith('steps')) return 'steps';
  if (key.startsWith('trigger_config') || key.startsWith('conditions')) return 'trigger';
  // name / description / icon / trigger_type all live in step 1.
  return 'general';
}

/** Whether a wizard step currently carries ANY error (drives the stepper `error` status). */
function stepHasError(step: WizardStep): boolean {
  return Object.keys(errors).some((k) => stepForErrorKey(k) === step);
}

/** After a failed validate / a 422: jump to the EARLIEST step that carries an error. */
function jumpToFirstErrorStep(): void {
  const first = WIZARD_ORDER.find((s) => stepHasError(s));
  if (first) activeStep.value = first;
}

/** The stepper items — labels/descriptions i18n; status `error` when the step has errors. */
const stepItems = computed<StepItem<WizardStep>[]>(() => {
  const activeIdx = WIZARD_ORDER.indexOf(activeStep.value);
  return WIZARD_ORDER.map((value, index) => {
    let status: StepStatus;
    if (stepHasError(value)) status = 'error';
    else if (index < activeIdx) status = 'complete';
    else if (index === activeIdx) status = 'current';
    else status = 'upcoming';
    return {
      value,
      label: t(`workflows.editor.wizard.${value}`),
      description: t(`workflows.editor.wizard.${value}Description`),
      status,
    };
  });
});

const activeIndex = computed(() => WIZARD_ORDER.indexOf(activeStep.value));
const isFirstStep = computed(() => activeIndex.value === 0);
const isLastStep = computed(() => activeIndex.value === WIZARD_ORDER.length - 1);

/** Dalej — validate the current step and advance only when it passes. */
function goNext(): void {
  if (isLastStep.value) return;
  if (!validateStep(activeStep.value)) return;
  activeStep.value = WIZARD_ORDER[activeIndex.value + 1];
}

/** Wstecz — always free (never validates). */
function goBack(): void {
  if (isFirstStep.value) return;
  activeStep.value = WIZARD_ORDER[activeIndex.value - 1];
}

/**
 * A stepper click. In EDIT mode nav is free (the stepper isn't `linear`); in CREATE
 * mode `linear` already blocks jumping AHEAD, and a click into an EARLIER step must
 * NOT validate — so we just move. Forward progress in create mode goes through Dalej.
 */
function onStepClick(value: WizardStep): void {
  activeStep.value = value;
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- SLIM header: just the title (Anuluj/Zapisz live in the sticky footer). The host
         drawer is :padded="false", so this owns BOTH vertical gaps — kept symmetric. -->
    <header class="flex items-center gap-next-3 border-b border-next-border px-next-4 py-next-5">
      <h2 class="min-w-0 truncate text-next-lg font-next-semibold text-next-fg">
        {{ isEdit ? t('workflows.editor.editTitle') : t('workflows.editor.createTitle') }}
      </h2>
    </header>

    <!-- Deep-link without a prefetched detail → a clear error (no blank form). -->
    <EmptyState
      v-if="detailError"
      variant="error"
      class="m-next-4"
      :title="t('workflows.editor.editTitle')"
      :description="t('workflows.editor.detailError')"
    >
      <template #action>
        <Button variant="outline" size="sm" @click="onCancel">
          {{ t('workflows.editor.cancel') }}
        </Button>
      </template>
    </EmptyState>

    <!-- 3-STEP WIZARD: the stepper sits under the header; only the active step's panel
         renders. Panels use v-show (not v-if) so the trigger fields stay mounted — the
         drawer's SAVE validate() consults their scheduleValid() from any step. -->
    <template v-else>
      <!-- Stepper header. Clickable; free-nav on EDIT, `linear` (forward via Dalej) on CREATE. -->
      <div class="shrink-0 border-b border-next-border px-next-4 py-next-3">
        <Stepper
          v-model:active="activeStep"
          :steps="stepItems"
          orientation="horizontal"
          clickable
          :linear="!isEdit"
          :aria-label="t('workflows.editor.wizard.stepperLabel')"
          @step-click="onStepClick"
        />
      </div>

      <!-- Body: the active step's panel scrolls; siblings stay mounted but hidden. -->
      <div :data-active-step="activeStep" class="flex min-h-0 flex-1 flex-col overflow-y-auto p-next-4">
        <!-- STEP 1 — General: name, icon, description + the trigger TYPE. -->
        <section v-show="activeStep === 'general'" class="flex flex-col gap-next-6">
          <div class="flex flex-col gap-next-3">
            <div class="flex flex-col gap-next-3 next-sm:flex-row next-sm:items-start">
              <FormField
                :label="t('workflows.editor.nameLabel')"
                required
                :error="errors['name']"
                class="min-w-0 flex-1"
              >
                <TextInput v-model="form.name" :maxlength="255" :placeholder="t('workflows.editor.namePlaceholder')" />
              </FormField>
              <FormField :label="t('workflows.editor.iconLabel')" class="w-full shrink-0 next-sm:w-64">
                <IconInput v-model="iconModel" :placeholder="t('workflows.editor.iconPlaceholder')" />
              </FormField>
            </div>
            <FormField :label="t('workflows.editor.descriptionLabel')" :error="errors['description']">
              <Textarea
                v-model="form.description"
                :rows="2"
                :maxlength="2500"
                :placeholder="t('workflows.editor.descriptionPlaceholder')"
              />
            </FormField>
          </div>

          <!-- Trigger TYPE — a two-option SegmentedControl (§4.3). Owned by the drawer. -->
          <div class="flex flex-col gap-next-3">
            <div>
              <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.editor.sections.trigger') }}</h3>
              <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('workflows.editor.sections.triggerHint') }}</p>
            </div>
            <FormField :label="t('workflows.editor.trigger.typeLabel')" required :error="errors['trigger_type']">
              <SegmentedControl
                v-model="typeModel"
                :options="typeOptions"
                :columns="2"
                :aria-label="t('workflows.editor.trigger.typeLabel')"
              />
            </FormField>
            <Alert v-if="showTypeChangeWarning" variant="warning" size="sm">
              {{ t('workflows.editor.trigger.typeChangeWarning') }}
            </Alert>
          </div>
        </section>

        <!-- STEP 2 — Trigger: the per-type fields + (form_submitted) conditions. -->
        <section v-show="activeStep === 'trigger'" class="flex flex-col gap-next-6">
          <div>
            <h3 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.editor.sections.triggerConfig') }}</h3>
            <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">{{ t('workflows.editor.sections.triggerConfigHint') }}</p>
          </div>
          <WorkflowTriggerFields
            ref="triggerRef"
            :type="form.triggerType"
            v-model:form-config="form.formTrigger"
            v-model:schedule-draft="form.scheduleDraft"
            :errors="errors"
            :form-seed="formSeed"
            :schedule-tz="activeTz"
            @form-change="onFormChange"
          />

          <!-- CONDITIONS — form_submitted only (hidden for schedule, §4.8). The
               loading/error UI is gated on formSelected: the FORM catalog (its field
               vars) is what the conditions need, so a form-less form_submitted goes
               straight to the "pick a form" state while the form-independent catalog
               loads silently for the steps section. -->
          <template v-if="showConditions">
            <div v-if="catalogLoading && formSelected" class="flex flex-col gap-next-2" role="status" :aria-label="t('workflows.editor.catalogLoading')">
              <Skeleton variant="rect" height="2rem" width="16rem" />
              <Skeleton variant="rect" height="3rem" />
            </div>
            <Alert v-else-if="catalogError && formSelected" variant="danger" size="sm">
              {{ t('workflows.editor.catalogError') }}
              <template #actions>
                <Button
                  variant="outline"
                  size="sm"
                  leading-icon="rotate-ccw"
                  @click="reloadCatalog()"
                >
                  {{ t('workflows.editor.catalogRetry') }}
                </Button>
              </template>
            </Alert>
            <WorkflowConditionsEditor
              v-else
              v-model="form.conditionsTree"
              :catalog="catalog"
              :form-selected="formSelected"
              :errors="errors"
            />
          </template>
        </section>

        <!-- STEP 3 — Steps. The catalog feeds each card's variable pickers. A catalog fetch
             failure must NOT leave the pickers silently empty — surface it here too (ungated,
             unlike the conditions error which is formSelected-gated), so a schedule/form-less
             workflow gets an error + retry instead of zero offered variables. -->
        <section v-show="activeStep === 'steps'" class="flex flex-col gap-next-6">
          <Alert v-if="catalogError" variant="danger" size="sm">
            {{ t('workflows.editor.catalogError') }}
            <template #actions>
              <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="reloadCatalog()">
                {{ t('workflows.editor.catalogRetry') }}
              </Button>
            </template>
          </Alert>
          <WorkflowStepListEditor
            :steps="form.steps"
            :catalog="catalog"
            :trigger-type="form.triggerType"
            :errors="errors"
            @update:steps="onStepsUpdate"
            @type-errors="onStepTypeErrors"
          />
        </section>
      </div>

      <!-- STICKY FOOTER: Anuluj (left) · Wstecz / Dalej | Zapisz przepływ (right). -->
      <footer
        class="flex shrink-0 items-center justify-between gap-next-2 border-t border-next-border bg-next-card px-next-4 py-next-5"
      >
        <Button variant="ghost" :disabled="saving" @click="onCancel">
          {{ t('workflows.editor.cancel') }}
        </Button>
        <div class="flex items-center gap-next-2">
          <Button v-if="!isFirstStep" variant="outline" leading-icon="chevron-left" :disabled="saving" @click="goBack">
            {{ t('workflows.editor.back') }}
          </Button>
          <Button v-if="!isLastStep" trailing-icon="chevron-right" @click="goNext">
            {{ t('workflows.editor.next') }}
          </Button>
          <Button v-else leading-icon="check" :loading="saving" @click="onSubmit">
            {{ saving ? t('workflows.editor.saving') : t('workflows.editor.saveWorkflow') }}
          </Button>
        </div>
      </footer>
    </template>
  </div>
</template>
