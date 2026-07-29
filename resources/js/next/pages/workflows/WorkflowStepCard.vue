<script setup lang="ts">
// WorkflowStepCard — one step card in the ordered step editor (§4.6).
//
// Header: a position badge + the type badge + trailing controls in the RULE order —
// a conditional remove ✕ (only when > 1 step) BEFORE the permanent ▲▼ reorder
// Buttons (disabled at the ends, keyboard-reachable, aria-labelled). Body: the
// required `key` field (mono, client uniqueness) + the type-specific config fields.
//
// R2 sub-stage 5 adds the THIRD type, `generate_content`: a TemplateSelect + one typed row
// per declared template slot (fetched from the chosen template — no new endpoint), the
// composite-slot refusal, the template-DRIFT warning, an optional Disk destination + session
// name, and the recipe's honest scale/cost note. See the block in the script for the two
// invariants it protects.
//
// 5.1 (B7c): TWO step types. create_task carries a MarkdownEditor title/description
// (variable chips), a value-or-variable priority + deadline, LabelSelect, an
// assignee SegmentedControl (user/bot) + UserSelect/BotSelect, and single FormSelect
// / PipelineSelect attaches. create_form_report carries a required FormSelect, a
// MarkdownEditor name/guidelines, a two-checkbox report-source group, and two
// optional DateOrVariableField submission windows (server-default hints).
//
// VARIABLES: the card receives the fetched `catalog` + the full `steps` list + this
// step's `position`. It feeds `toEditorVariables(catalog, steps, position)` into the
// editors (identity-only directives; system + position-scoped, KEY-substituted step
// outputs) and `variablesOfType(...)` into the add-on pickers. A null catalog
// (schedule trigger / no form) degrades to step outputs only — the editors still work.
//
// The parent owns the StepDraft; this card mutates `step.key` / `step.config` in
// place and emits remove/move.
import { computed, markRaw, ref, watch } from 'vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import BotSelect from '../../ui/forms/BotSelect.vue';
import FormSelect from '../../ui/forms/FormSelect.vue';
import PipelineSelect from '../../ui/forms/PipelineSelect.vue';
import TemplateSelect from '../../ui/forms/TemplateSelect.vue';
import Checkbox from '../../ui/forms/Checkbox.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import TypedLiteralInput from '../../ui/variables/TypedLiteralInput.vue';
import ValueOrVariableField from './ValueOrVariableField.vue';
import DateOrVariableField from './DateOrVariableField.vue';
import WorkflowArgVariableField from './WorkflowArgVariableField.vue';
import FormFileInput from '../forms/FormFileInput.vue';
import FolderPickerPanel from '../disk/FolderPickerPanel.vue';
import { useI18n } from '../../app/i18n';
import { useTemplatesStore } from '../../app/stores/templates';
import { stepIcon, stepLabel } from './workflowMeta';
import { allValueVariables, stripVariableDirectives, toEditorVariablesTyped, variablesOfType } from './workflowVariables';
import { resolveOperationCatalog } from './workflowConditions';
import { getVariableIconLabel, pipelineSatisfies } from '../../ui/editor/extensions/operationHelpers';
import { sanitizeStepKey, type StepDraft } from './workflowEditorModel';
import { defaultSingleValue } from '../variables/consts';
import { contentTypeLabel } from '../generator/templateMeta';
import { STORYBOARD_MAX_SHOTS, type Template, type TemplateSlot } from '../generator/types';
import type { VariableDescriptorOption, VariableLiteralBase } from '../../ui/variables/types';
import type {
  CatalogVariable,
  CatalogVariableDescriptor,
  ConstantBase,
  FormReportSource,
  WorkflowCatalog,
  WorkflowFieldPipelineStep,
  WorkflowFieldValue,
  WorkflowTriggerType,
  WorkflowVariableType,
} from './types';
import type {
  AiPersona,
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableDefinition,
  VariableFeatureConfig,
  VariableOperationDefinition,
  VariableOption,
  VariablePipelineStep,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';

const props = defineProps<{
  step: StepDraft;
  index: number;
  total: number;
  /**
   * The fetched variable catalog (variables + condition fields), or null when there
   * is no form (schedule trigger). Passed DOWN by the drawer (B7d).
   */
  catalog: WorkflowCatalog | null;
  /** All step drafts (for position-scoped, KEY-substituted step outputs). */
  steps: StepDraft[];
  /** This step's index (earlier-only variable scoping). */
  position: number;
  /**
   * The workflow's trigger type — supplements a null catalog (schedule / any form)
   * with the trigger's SYSTEM variables (e.g. `trigger.scheduled_at` for a schedule).
   */
  triggerType: WorkflowTriggerType | null;
  /** Per-field errors for THIS card, keyed by the config field (or `key`). */
  errors: Record<string, string>;
  /** True when this card's `key` collides with another step's key. */
  duplicateKey: boolean;
  /** Whether the card body (the full editor) is expanded; collapsed shows a summary row. */
  expanded: boolean;
}>();

const emit = defineEmits<{
  (e: 'remove'): void;
  (e: 'move', dir: -1 | 1): void;
  /** Toggle the collapsed/expanded body (the list editor owns which cards are open). */
  (e: 'toggle'): void;
  /**
   * Whether this card has ANY value-or-variable field whose SAVED pipeline does not
   * satisfy the field (a type/choice mismatch). The list editor bubbles this to the
   * drawer so the existing "steps have errors → block Save" gate engages.
   */
  (e: 'type-error', value: boolean): void;
}>();

const { t } = useI18n();

/** A stable id so the collapse toggle's aria-controls points at this card's body. */
const bodyId = computed(() => `wf-step-body-${props.step.uid}`);

// --- Value-or-variable FIELD type errors -------------------------------------
// SF (review finding 2+3): the type-satisfaction gate must NOT depend on the card
// body being mounted. The card is ALWAYS mounted (only its BODY sits behind
// `v-if=expanded`), so we compute the gate HERE from the SAVED `config` model rather
// than from the child fields' mount-time `update:typeError` emits — a card edited with
// 2+ steps hydrates COLLAPSED, so those child emits never fire and the drawer's Save
// gate stayed open until the server 422. `hasTypeError` (declared below, near the
// value-or-variable field specs) is the single source of truth bubbled to the drawer.

/**
 * Whether this card carries ANY error worth painting the row RED: a server/client 422,
 * a duplicate key, a value-or-variable type mismatch, or a HARD generate_content problem
 * (a composite slot the workflow can never supply, or template drift). A merely
 * not-yet-filled required slot is deliberately NOT here — it blocks Save (see
 * `blocksSave`) and is surfaced by its own warning, but a freshly-added card must not
 * greet the author in red before they have done anything.
 */
const hasError = computed(
  () =>
    Object.keys(props.errors).length > 0 ||
    props.duplicateKey ||
    hasTypeError.value ||
    generateContentHardError.value,
);

/**
 * The one-line collapsed summary: the step's title / report name with its variable
 * directives stripped to their names (§3.2), or a type fallback when still blank.
 * A generate_content step summarises as "<template name> · N inputs" (the recipe IS the
 * step's identity); before the template resolves it falls back to the session name, then
 * to the type fallback — never a blank row, never a raw uuid.
 */
const summary = computed<string>(() => {
  if (props.step.type === 'create_task') {
    const title = stripVariableDirectives(strValue('title'), props.catalog, props.steps, props.triggerType).trim();
    return title || t('workflows.step.summary.createTaskFallback');
  }
  if (props.step.type === 'create_form_report') {
    const name = stripVariableDirectives(strValue('name'), props.catalog, props.steps, props.triggerType).trim();
    return name || t('workflows.step.summary.createFormReportFallback');
  }
  if (props.step.type === 'generate_content') {
    if (template.value) {
      return t('workflows.step.generate_content.summary', '', {
        name: template.value.name,
        count: declaredSlots.value.length,
      });
    }
    return strValue('name').trim() || t('workflows.step.summary.generateContentFallback');
  }
  return '';
});

/** Normalize the key as it is typed — only `[A-Za-z0-9_]` may reach the model (§4.6). */
function onKeyInput(value: string): void {
  props.step.key = sanitizeStepKey(String(value ?? ''));
}

// --- Config getters / setters ----------------------------------------------
function cfg<T = unknown>(field: string): T {
  return props.step.config?.[field] as T;
}
function setCfg(field: string, value: unknown): void {
  props.step.config = { ...(props.step.config ?? {}), [field]: value };
}
/** A string config field (title/description/name/guidelines), coerced. */
function strValue(field: string): string {
  return String(props.step.config?.[field] ?? '');
}
/** A nullable id/select field's current value. */
function idValue(field: string): string | null {
  return (props.step.config?.[field] as string | null) ?? null;
}

// The key error prefers the server/client key message, then the duplicate flag.
const keyError = computed<string | undefined>(() => {
  if (props.errors.key) return props.errors.key;
  if (props.duplicateKey) return t('workflows.step.validation.keyDuplicate');
  return undefined;
});

/**
 * The error for a config `field`, preferring the exact `config.<field>` key but
 * falling back to any nested sub-key (`config.<field>.ref.path` / `.value` /
 * `.labels.0`) so a union/array-shape 422 still surfaces on the field.
 */
function fieldError(field: string): string | undefined {
  const exact = props.errors[`config.${field}`];
  if (exact) return exact;
  const nestedPrefix = `config.${field}.`;
  for (const [key, msg] of Object.entries(props.errors)) {
    if (key.startsWith(nestedPrefix)) return msg;
  }
  return undefined;
}

// --- Variable feeds (editors + add-ons) ------------------------------------
// SF1: the editors carry the FULL runtime power the engine already executes — the
// variable feed uses the TRUE type + enum options (so a chip's pipeline offers the
// right enum/date/multi operations), and the operations catalog + if-block / ai-text
// features are wired below.
const editorVariables = computed<VariableDefinition[]>(() =>
  toEditorVariablesTyped(props.catalog, props.steps, props.position, props.triggerType),
);
/** The merged 79-op catalog (backend descriptors × FE labels; full standard set as fallback). */
const operationsCatalog = computed<VariableOperationDefinition[]>(() =>
  resolveOperationCatalog(props.catalog),
);
/**
 * The editor's variable feature. The arrays are the seed; the two GETTERS are what keeps
 * the feed LIVE — this card's catalog is FETCHED (async) and its variable set changes
 * whenever a step key is renamed, an earlier step is inserted or the trigger form is
 * switched. The editor builds its extensions once at setup, so without these an
 * already-open editor would keep showing the feed it happened to see at mount.
 */
const editorFeature = computed<VariableFeatureConfig>(() => ({
  variables: editorVariables.value,
  operationsCatalog: operationsCatalog.value,
  source: () => allVariables.value,
  catalog: () => operationsCatalog.value,
  // B4 — a chip's pipeline ARGUMENT may itself be a variable, exactly like a step field's. The
  // control is this page's value-or-variable field, INJECTED (the editor lives in `ui/**` and must
  // not import a page); `markRaw` keeps the component definition out of Vue's reactivity.
  argVariableField: markRaw(WorkflowArgVariableField),
}));

// AI-text personas: the catalog's label-less ids (fallback to the closed set), each
// localized to {id,label} via `workflows.aiPersona.<id>`. Knowledge labels are OFF —
// a workflow field has no label catalog to attach.
const AI_PERSONA_IDS = ['neutral', 'friendly', 'formal', 'concise'];
const aiPersonas = computed<AiPersona[]>(() => {
  const ids = (props.catalog?.ai_personas ?? []).map((p) => p.id);
  const list = ids.length ? ids : AI_PERSONA_IDS;
  return list.map((id) => ({ id, label: t(`workflows.aiPersona.${id}`) }));
});
const aiTextConfig = computed<AiTextFeatureConfig>(() => ({
  personas: aiPersonas.value,
  labelsEnabled: false,
}));

// DECISION (SF3.6) — if-block / ai-text are now enabled on ALL text fields:
//   • DESCRIPTION / GUIDELINES (multi-line) and TITLE / NAME alike carry the FULL
//     power: if-blocks (depth 3) + ai-text + typed variables + the operations catalog.
//   • TITLE / NAME previously ran as compact `hide-toolbar` one-liners (variables
//     only); the user wants a conditional branch / AI-written value there too, so they
//     become ordinary multi-line editors WITH the toolbar (the only affordance for
//     inserting an if-block / ai-text). The engine already trims the resolved title to
//     255 chars at run time, so a longer composed value never breaks a run.
const IF_BLOCK_CONFIG: IfBlockFeatureConfig = { maxDepth: 3 };

// SF (show-all): value-or-variable pickers no longer PRE-FILTER by the field's type —
// EVERY referenceable variable is offered and the user coerces it with operations
// (priority terminates on a choice via enum_to_choice/match_to_choice; the date fields
// terminate on `date`). ONE feed drives every add-on field.
const allVariables = computed<CatalogVariable[]>(() =>
  allValueVariables(props.catalog, props.steps, props.position, props.triggerType),
);
// priority MUST terminate on a CHOICE targeting the priority option set (backend now
// rejects an identity ref / a plain text|enum terminal).
const PRIORITY_RESULT_TYPES: WorkflowVariableType[] = ['enum'];

// --- create_task: priority literal Select ----------------------------------
const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
const priorityOptions = computed<SelectOption[]>(() =>
  PRIORITIES.map((p) => ({ value: p, label: t(`workflows.step.priority.${p}`) })),
);

// value-or-variable v-model bridges (the config stores the WorkflowFieldValue union).
const priorityModel = computed<WorkflowFieldValue | null>({
  get: () => cfg<WorkflowFieldValue | null>('priority') ?? null,
  set: (v) => setCfg('priority', v),
});
const deadlineModel = computed<WorkflowFieldValue<string> | null>({
  get: () => cfg<WorkflowFieldValue<string> | null>('deadline') ?? null,
  set: (v) => setCfg('deadline', v),
});
const fromModel = computed<WorkflowFieldValue<string> | null>({
  get: () => cfg<WorkflowFieldValue<string> | null>('submissions_from') ?? null,
  set: (v) => setCfg('submissions_from', v),
});
const toModel = computed<WorkflowFieldValue<string> | null>({
  get: () => cfg<WorkflowFieldValue<string> | null>('submissions_to') ?? null,
  set: (v) => setCfg('submissions_to', v),
});

// --- create_task: attachments (value-or-variable, FILE) ---------------------
// A FILE terminal. Unlike priority/deadline, the picker is genuinely TYPE-FILTERED to
// file variables: NO operation coerces another type INTO a file, so a non-file pick
// could never satisfy the field — offering it (show-all) would only invite a dead end.
const FILE_RESULT_TYPES: WorkflowVariableType[] = ['file'];
const attachmentsModel = computed<WorkflowFieldValue | null>({
  get: () => cfg<WorkflowFieldValue | null>('attachments') ?? null,
  set: (v) => setCfg('attachments', v),
});
const fileVariables = computed<CatalogVariable[]>(() =>
  variablesOfType(props.catalog, props.steps, props.position, 'file', props.triggerType),
);

// --- generate_content (R2 sub-stage 5) ---------------------------------------
// The step runs a Generator TEMPLATE, and everything the editor needs beyond `template_id`
// lives ON that template (its DECLARED slots + its content_type). So the card FETCHES it
// (`GET /generator/templates/{id}` — the existing read, no new endpoint) and renders one
// typed row per declared slot.
//
// TWO invariants this block exists to protect:
//   • COMPOSITE slots. A descriptor with base `object`, or `array:true` with base `file`,
//     can NEVER be supplied by a workflow: the shared resolver has no object coercion and
//     the automation fill scope refuses the deferred composites, so the mapped value would
//     be silently discarded — which is why the backend 422s it. We therefore mark it
//     unsupported BEFORE save: the input is DISABLED with an explanation, and a REQUIRED
//     composite condemns the WHOLE template (an inline warning says so) rather than letting
//     the author save a step that would hard-fail every single run.
//   • DRIFT. A saved mapping is NEVER silently reset when the template changed underneath
//     it. The differences are NAMED inline while they are still fixable (the run hard-fails
//     on drift), and dropping a stale mapping is an explicit, author-driven action.
const template = ref<Template | null>(null);
const templateLoading = ref(false);
const templateError = ref(false);

/** The chosen template id (null until one is picked). */
const templateId = computed<string | null>(() => idValue('template_id'));

/** The TemplateSelect seed, so the chosen template's NAME renders before its page loads. */
const templateSeed = computed(() =>
  template.value
    ? [{ id: template.value.id, name: template.value.name, content_type: template.value.content_type }]
    : [],
);

// A request token so a fast re-pick can never let a stale response win.
let templateToken = 0;

async function loadTemplate(id: string | null): Promise<void> {
  const myToken = (templateToken += 1);
  if (!id) {
    template.value = null;
    templateLoading.value = false;
    templateError.value = false;
    return;
  }
  templateLoading.value = true;
  templateError.value = false;
  try {
    // Resolved LAZILY (not at setup) so a create_task / create_form_report card — which
    // never needs it — carries no Pinia dependency at all.
    const result = await useTemplatesStore().fetchTemplate(id);
    if (myToken !== templateToken) return;
    template.value = result;
  } catch {
    if (myToken !== templateToken) return;
    template.value = null;
    templateError.value = true;
  } finally {
    if (myToken === templateToken) templateLoading.value = false;
  }
}

// Load on hydration AND whenever the id changes (a re-pick, or an id restored from a saved
// step). This watcher only READS — it never touches the author's slot mapping.
watch(
  templateId,
  (id) => {
    if (props.step.type !== 'generate_content') return;
    void loadTemplate(id);
  },
  { immediate: true },
);

function retryTemplate(): void {
  void loadTemplate(templateId.value);
}

/**
 * Pick a template from the Select. Choosing a DIFFERENT recipe makes the previous slot
 * mapping meaningless (the names/types belong to another template), so it is cleared HERE —
 * on an explicit, author-initiated change only. Hydration goes through the watcher above
 * and never clears anything.
 */
function onTemplatePick(next: string | null): void {
  if (next === templateId.value) return;
  setCfg('template_id', next);
  setCfg('slots', {});
  // A freshly-picked recipe has no authoring history, so nothing about it can have DRIFTED
  // (see `authoredSlotNames`): its required inputs are merely unfilled.
  authoredSlotNames.value = null;
}

/** The chosen template's DECLARED slots ([] until it resolves). */
const declaredSlots = computed<TemplateSlot[]>(() => template.value?.slots ?? []);

/** The saved `<slot name> => value-or-variable` map (never mutated in place). */
const slotMap = computed<Record<string, unknown>>(() => {
  const raw = cfg<Record<string, unknown> | null>('slots');
  return raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
});

function slotValue(name: string): unknown {
  return slotMap.value[name] ?? null;
}
function setSlot(name: string, value: unknown): void {
  setCfg('slots', { ...slotMap.value, [name]: value });
}
/** Drop a slot from the map entirely — "unmapped", not "mapped to empty". */
function unsetSlot(name: string): void {
  const next = { ...slotMap.value };
  delete next[name];
  setCfg('slots', next);
}

/**
 * A slot is REQUIRED unless its descriptor says `nullable: true`. There is NO `required`
 * key — everything is required BY DEFAULT — which is exactly why every row carries an
 * explicit marker instead of only flagging the exceptions.
 */
function slotRequired(descriptor: CatalogVariableDescriptor): boolean {
  return descriptor.nullable !== true;
}

/**
 * Whether a declared slot is a COMPOSITE a workflow cannot supply — the FE mirror of
 * `StoreWorkflowRequest::isUnsuppliableSlot`: base `object` (any shape), or `array:true`
 * with base `file`. A plain SCALAR `file` slot is deliberately NOT one: it resolves, and it
 * is the owner-approved automation path.
 */
function slotUnsupported(descriptor: CatalogVariableDescriptor): boolean {
  if (descriptor.base === 'object') return true;
  return descriptor.base === 'file' && descriptor.array === true;
}

/**
 * The slot's own workflow TYPE, recovered from its descriptor exactly as the backend's
 * `VariableType::fromDescriptor` does: an `enum` base is enum / multi by `array`, every
 * other base is itself (or multi when arrayed). The DESCRIPTOR-only bases (`object`,
 * `time`) degrade to text — neither is reachable here (`object` is refused as a composite
 * and `time` is not an authorable slot base).
 */
function slotResultType(descriptor: CatalogVariableDescriptor): WorkflowVariableType {
  if (descriptor.base === 'object') return 'text';
  if (descriptor.base === 'enum') return descriptor.array ? 'multi' : 'enum';
  const scalar: WorkflowVariableType =
    descriptor.base === 'number' || descriptor.base === 'boolean' || descriptor.base === 'date' || descriptor.base === 'file'
      ? descriptor.base
      : 'text';
  return descriptor.array ? 'multi' : scalar;
}

/**
 * The slot's ELEMENT type — its own base, ignoring `array`. This is both the label vocabulary
 * for an arrayed row (a list of TEXT is "Text (list)", not "Multi-choice (list)") and the
 * scalar terminal an arrayed slot additionally accepts (see `slotResultTypes`).
 */
function slotElementType(descriptor: CatalogVariableDescriptor): WorkflowVariableType {
  if (descriptor.base === 'object') return 'text';
  if (descriptor.base === 'enum') return 'enum';
  return descriptor.base === 'number' || descriptor.base === 'boolean' || descriptor.base === 'date' || descriptor.base === 'file'
    ? descriptor.base
    : 'text';
}

/**
 * The terminal types a slot's variable pipeline may END on.
 *
 * A SCALAR slot accepts exactly its own type — the FE tightening is safe there because a cast
 * operation always exists to reach it (same precedent as `create_task.deadline`).
 *
 * An ARRAYED slot accepts its `multi` type OR its plain ELEMENT type, because:
 *   • NO operation produces `multi` from a scalar (only array_map/filter/sort do, and those
 *     need an array INPUT), so demanding `multi` made every scalar variable an unreachable
 *     dead end — the picker offered it and the gate then refused the save with no way out;
 *   • the SERVER admits the SAME pair — `StoreWorkflowRequest::slotPipelineTerminals` passes
 *     both the slot's `multi` and its element type to the pipeline validator (an empty/identity
 *     pipeline was always accepted; the element terminal was the missing half) — and the runtime
 *     WRAPS it: `VariableResolver::coerce` does
 *     `MULTI => is_array($value) ? array_values($value) : [$value]`.
 * Mirroring the documented runtime wrap is what keeps the picker and the validator agreeing;
 * both sides are pinned by tests, so neither can tighten alone.
 */
function slotResultTypes(descriptor: CatalogVariableDescriptor): WorkflowVariableType[] {
  const terminal = slotResultType(descriptor);
  if (terminal !== 'multi') return [terminal];
  return ['multi', slotElementType(descriptor)];
}

/** The declared slots a workflow CAN supply (the ones that get a live input). */
const supportedSlots = computed<TemplateSlot[]>(() =>
  declaredSlots.value.filter((slot) => !slotUnsupported(slot.descriptor)),
);

/** REQUIRED composites — these condemn the whole template for workflow use. */
const requiredCompositeSlots = computed<string[]>(() =>
  declaredSlots.value
    .filter((slot) => slotUnsupported(slot.descriptor) && slotRequired(slot.descriptor))
    .map((slot) => slot.name),
);

/** NULLABLE composites that were nonetheless MAPPED (the backend rejects only these). */
const mappedCompositeSlots = computed<string[]>(() =>
  declaredSlots.value
    .filter(
      (slot) =>
        slotUnsupported(slot.descriptor) &&
        !slotRequired(slot.descriptor) &&
        Object.prototype.hasOwnProperty.call(slotMap.value, slot.name),
    )
    .map((slot) => slot.name),
);

/** Required, supportable slots the author has not mapped yet. */
const missingRequiredSlots = computed<string[]>(() =>
  supportedSlots.value
    .filter((slot) => slotRequired(slot.descriptor) && !hasUsableSlotValue(slot.name))
    .map((slot) => slot.name),
);

/**
 * Whether a mapped value would actually SURVIVE `buildStepConfig` (which drops null / ''),
 * so "mapped" here means the same thing it will mean on the wire — otherwise a cleared
 * field would read as satisfied here and 422 on save.
 */
function hasUsableSlotValue(name: string): boolean {
  const value = slotMap.value[name];
  if (value == null || value === '') return false;
  if (typeof value === 'object' && (value as WorkflowFieldValue).kind === 'literal') {
    const literal = (value as Extract<WorkflowFieldValue, { kind: 'literal' }>).value;
    return literal != null && literal !== '';
  }
  return true;
}

// --- Template DRIFT ---------------------------------------------------------
/** Mapped names the template no longer declares (a slot removed or renamed after authoring). */
const driftRemoved = computed<string[]>(() => {
  if (!template.value) return [];
  const declared = new Set(declaredSlots.value.map((slot) => slot.name));
  return Object.keys(slotMap.value).filter((name) => !declared.has(name));
});

/**
 * The slot names this step was AUTHORED against — snapshotted ONCE, from the mapping the
 * card hydrated with, and never updated afterwards. Null when the step carries no saved
 * mapping (a brand-new step, or one whose recipe was just re-picked): there is no authoring
 * history to compare against, so nothing can have drifted.
 *
 * This is the whole point of the snapshot: drift means "unknown AT AUTHORING TIME", NOT
 * "absent from the live draft". Deriving it from the live draft made ordinary authoring —
 * pick a recipe with two required inputs, fill the first — announce that "this template
 * changed after the step was saved", which is simply false, and suppressed the accurate
 * "these inputs still need a value" message in the process.
 */
const authoredSlotNames = ref<string[] | null>(
  props.step.type === 'generate_content' &&
  idValue('template_id') &&
  Object.keys(slotMap.value).length > 0
    ? Object.keys(slotMap.value)
    : null,
);

/**
 * Required slots the template gained AFTER this step was authored: required, still unmapped,
 * and absent from the authoring-time snapshot. A step with no snapshot reports none.
 */
const driftAdded = computed<string[]>(() => {
  const authored = authoredSlotNames.value;
  if (!template.value || authored === null) return [];
  return missingRequiredSlots.value.filter((name) => !authored.includes(name));
});

/**
 * Required slots that are simply NOT FILLED IN YET (the unfinished case), minus the ones the
 * drift alert already names — so the author is never told the same thing twice, and never
 * told "the template drifted" when the truth is "you haven't filled this in".
 */
const unfilledRequiredSlots = computed<string[]>(() =>
  missingRequiredSlots.value.filter((name) => !driftAdded.value.includes(name)),
);

const hasDrift = computed(() => driftRemoved.value.length > 0 || driftAdded.value.length > 0);

/** Explicitly drop the stale mappings the template no longer declares (never automatic). */
function dropUnknownSlots(): void {
  const declared = new Set(declaredSlots.value.map((slot) => slot.name));
  const next: Record<string, unknown> = {};
  for (const [name, value] of Object.entries(slotMap.value)) {
    if (declared.has(name)) next[name] = value;
  }
  setCfg('slots', next);
}

/**
 * The HARD problems: a composite the workflow can never supply, or drift the server will
 * reject. These paint the card red immediately — they are broken now, not merely unfinished.
 */
const generateContentHardError = computed(
  () =>
    props.step.type === 'generate_content' &&
    (requiredCompositeSlots.value.length > 0 ||
      mappedCompositeSlots.value.length > 0 ||
      driftRemoved.value.length > 0),
);

/**
 * Everything that must block SAVE — the hard problems plus the unfinished ones.
 *
 * While the template is still LOADING, Save is blocked so it WAITS rather than round-trips:
 * the declarations are simply not known yet, and letting a fast Save through skipped every
 * per-slot check and came back as a 422. A FAILED fetch is deliberately non-blocking — we
 * cannot check anything and the server stays authoritative — as is a step with no template
 * picked (its own `template_id` required error covers that).
 */
const generateContentBlocked = computed(() => {
  if (props.step.type !== 'generate_content') return false;
  if (templateLoading.value) return true;
  if (!template.value) return false;
  return generateContentHardError.value || missingRequiredSlots.value.length > 0;
});

// --- Slot ROW rendering helpers ---------------------------------------------
/**
 * The human TYPE marker for a slot row (reuses the shared type vocabulary). Labelled from the
 * descriptor's OWN base + its array flag, NOT from the engine type: an `array:true, base:text`
 * slot resolves to the engine's `multi`, so labelling from that read "Multi-choice (list)" over
 * a repeater of free-text inputs. An arrayed ENUM keeps the genuine "Multi-choice" word.
 */
function slotTypeLabel(descriptor: CatalogVariableDescriptor): string {
  if (descriptor.base === 'enum') {
    return getVariableIconLabel(descriptor.array ? 'multi' : 'enum');
  }
  const base = getVariableIconLabel(slotElementType(descriptor) as VariablePrimitive);
  return descriptor.array ? t('workflows.variable.collection', '', { name: base }) : base;
}

/** The literal-control base for a slot (the composites never reach a control). */
function slotLiteralBase(descriptor: CatalogVariableDescriptor): VariableLiteralBase {
  return descriptor.base === 'enum' ? 'enum' : ((descriptor.base === 'number' || descriptor.base === 'boolean' || descriptor.base === 'date' ? descriptor.base : 'text') as VariableLiteralBase);
}
function slotOptions(descriptor: CatalogVariableDescriptor): VariableDescriptorOption[] {
  return (descriptor.options ?? []) as VariableDescriptorOption[];
}
/** Whether the row's literal side is a repeatable list (an arrayed scalar/enum slot). */
function slotIsList(descriptor: CatalogVariableDescriptor): boolean {
  return descriptor.array === true && descriptor.base !== 'file';
}
/** Whether the row's literal side is a FILE pick (a scalar file slot — mirrors `attachments`). */
function slotIsFile(descriptor: CatalogVariableDescriptor): boolean {
  return descriptor.base === 'file' && descriptor.array !== true;
}

/** The literal LIST behind an arrayed slot's value (always an array for the repeater). */
function slotList(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}
function setSlotListItem(list: unknown[], index: number, item: unknown, setValue: (v: unknown) => void): void {
  const next = [...list];
  next[index] = item;
  setValue(next);
}
function addSlotListItem(descriptor: CatalogVariableDescriptor, list: unknown[], setValue: (v: unknown) => void): void {
  setValue([...list, defaultSingleValue(slotLiteralBase(descriptor) as ConstantBase, descriptor.options ?? [])]);
}
function removeSlotListItem(list: unknown[], index: number, setValue: (v: unknown) => void): void {
  setValue(list.filter((_, i) => i !== index));
}

/** The value-or-variable union currently held by a slot row (null ⇒ unmapped). */
function slotUnion(name: string): WorkflowFieldValue | null {
  return (slotValue(name) as WorkflowFieldValue | null) ?? null;
}

/**
 * Write one slot row. A `null` (the field's fully-cleared state) UNSETS the key rather
 * than storing an empty value, so "unmapped" stays unmapped — the exact distinction the
 * backend's per-slot rules read (`array_key_exists`).
 */
function onSlotInput(name: string, value: WorkflowFieldValue | null): void {
  if (value == null) unsetSlot(name);
  else setSlot(name, value);
}

// --- The optional Disk folder + session name --------------------------------
/** Where produced images are exported; null = the Disk ROOT (the picker's current level). */
const folderModel = computed<string | null>({
  get: () => idValue('folder_id'),
  set: (value) => setCfg('folder_id', value),
});

/**
 * The SCALE / COST note for the chosen recipe, keyed off its `content_type`: a
 * `video_script` fans out to one image per shot (up to the platform ceiling) in a single
 * run, while `post` / `post_with_image` are one piece. Honest, not alarmist — and it falls
 * back to a generic note so a new content type still says something true.
 */
const scaleHint = computed<string>(() => {
  const type = template.value?.content_type;
  if (!type) return '';
  const key = `workflows.step.generate_content.scale.${type}`;
  const fallback = t('workflows.step.generate_content.scale.other');
  return t(key, fallback, { max: STORYBOARD_MAX_SHOTS });
});

/** The chosen recipe's localized content-type name (for the scale note's heading). */
const contentTypeName = computed<string>(() =>
  template.value ? contentTypeLabel(template.value.content_type, t) : '',
);

// --- Value-or-variable field SPECS + the saved-model type-error gate ----------
// ONE spec map: each value-or-variable config field the card owns → its terminal
// contract ({resultTypes, targetOptions}). The template binds :result-types /
// :target-options FROM this map AND the gate below reads the SAME map, so the render
// bindings and the gate can NEVER drift. Date fields terminate on `date` with no
// target; priority terminates on a `enum` CHOICE targeting the priority option set.
interface VovFieldSpec {
  resultTypes: WorkflowVariableType[];
  targetOptions?: VariableOption[];
}
const DATE_RESULT_TYPES: WorkflowVariableType[] = ['date'];
const vovFieldSpecs = computed(() => ({
  priority: { resultTypes: PRIORITY_RESULT_TYPES, targetOptions: priorityOptions.value } as VovFieldSpec,
  deadline: { resultTypes: DATE_RESULT_TYPES } as VovFieldSpec,
  submissions_from: { resultTypes: DATE_RESULT_TYPES } as VovFieldSpec,
  submissions_to: { resultTypes: DATE_RESULT_TYPES } as VovFieldSpec,
  attachments: { resultTypes: FILE_RESULT_TYPES } as VovFieldSpec,
}));

/** Project a SAVED `{op,args}` wire step onto the editor step shape `pipelineSatisfies` reads. */
function toEditorStep(wire: WorkflowFieldPipelineStep): VariablePipelineStep {
  return {
    stepId: '',
    operationId: wire.op,
    args: (wire.args ?? {}) as VariablePipelineStep['args'],
    outputType: operationsCatalog.value.find((op) => op.id === wire.op)?.outputType ?? 'text',
  };
}

/**
 * The value-or-variable config fields whose SAVED value does not satisfy the field's
 * terminal/choice contract. Derived from the SAVED model (NOT child emits) so the gate
 * works while the card is COLLAPSED. A literal (value-mode) or unset field is never an
 * error; only a VARIABLE-mode value with an unsatisfying pipeline is.
 */
const typeErrorFields = computed<string[]>(() => {
  const catalog = operationsCatalog.value;
  // Catalog not yet available → not evaluable; don't emit a spurious error (re-runs
  // reactively once resolveOperationCatalog yields a non-empty set).
  if (catalog.length === 0) return [];
  const fields: string[] = [];
  // The fixed per-type specs (create_task / create_form_report). A generate_content step
  // owns none of these keys, so the loop is a no-op there.
  for (const [field, spec] of Object.entries(vovFieldSpecs.value)) {
    const value = cfg<WorkflowFieldValue | null>(field) ?? null;
    if (!value || value.kind !== 'variable') continue;
    const pipeline = (value.pipeline ?? []).map(toEditorStep);
    const satisfied = pipelineSatisfies(
      catalog,
      value.ref.type as WorkflowVariableType as VariablePrimitive,
      pipeline,
      spec.resultTypes as VariablePrimitive[],
      spec.targetOptions,
    );
    if (!satisfied) fields.push(field);
  }
  // generate_content: one DYNAMIC spec per declared slot (its terminal is the slot's own
  // type, recovered from its descriptor exactly as the backend does). Reported under the
  // `slots.<name>` key so the row can highlight itself.
  for (const slot of supportedSlots.value) {
    const value = slotValue(slot.name);
    if (!value || typeof value !== 'object' || (value as WorkflowFieldValue).kind !== 'variable') continue;
    const union = value as Extract<WorkflowFieldValue, { kind: 'variable' }>;
    const pipeline = (union.pipeline ?? []).map(toEditorStep);
    const satisfied = pipelineSatisfies(
      catalog,
      union.ref.type as WorkflowVariableType as VariablePrimitive,
      pipeline,
      slotResultTypes(slot.descriptor) as VariablePrimitive[],
    );
    if (!satisfied) fields.push(`slots.${slot.name}`);
  }
  return fields;
});
const hasTypeError = computed(() => typeErrorFields.value.length > 0);

/**
 * Whether this card BLOCKS Save. A superset of `hasError`: it additionally covers the
 * generate_content "you have not filled the required inputs yet" case, which must stop a
 * doomed round-trip without painting a brand-new card red.
 */
const blocksSave = computed(() => hasTypeError.value || generateContentBlocked.value);

// Bubble the gate to the list editor → drawer (same `type-error` boolean contract as
// before — it means "this card is not saveable yet"). Immediate so an ALWAYS-mounted
// collapsed card reports its state on hydration; `flush: 'post'` defers the FIRST emit
// until AFTER this card has mounted, so the parent's reaction (auto-expand mutating
// expandedUids, which feeds back into this card's own render) never re-enters an instance
// that is still initializing (emitsOptions/flags null).
watch(blocksSave, (value) => emit('type-error', value), { immediate: true, flush: 'post' });

// --- create_task: assignee (SegmentedControl user/bot + picker) ------------
const assigneeSegments = computed<SegmentOption<'user' | 'bot'>[]>(() => [
  { value: 'user', label: t('workflows.step.config.assigneeUser'), icon: 'user' },
  { value: 'bot', label: t('workflows.step.config.assigneeBot'), icon: 'sparkles' },
]);
// The segment the user last chose (so the correct picker shows even with no id yet).
const pendingAssigneeType = ref<'user' | 'bot'>(idValue('assignee_type') === 'bot' ? 'bot' : 'user');
// The shown segment: the saved assignee_type when set, else the pending choice.
const shownAssigneeType = computed<'user' | 'bot'>(() =>
  idValue('assignee_type') ? (idValue('assignee_type') as 'user' | 'bot') : pendingAssigneeType.value,
);

/** Pick an assignee id — sets BOTH keys (both-or-neither). */
function setAssignee(id: string | null): void {
  if (id) {
    setCfg('assignee_type', shownAssigneeType.value);
    setCfg('assignee_id', id);
  } else {
    // Clearing the picker drops both keys.
    setCfg('assignee_type', null);
    setCfg('assignee_id', null);
  }
}
function onAssigneeSegment(v: 'user' | 'bot'): void {
  pendingAssigneeType.value = v;
  // Changing the target type clears a stale id (both-or-neither integrity).
  setCfg('assignee_type', null);
  setCfg('assignee_id', null);
}

// --- create_form_report: sources (two-checkbox report vocabulary) ----------
const REPORT_SOURCES: FormReportSource[] = ['task', 'form'];
function sourceChecked(source: FormReportSource): boolean {
  const list = (cfg<FormReportSource[] | null>('sources') ?? []) as FormReportSource[];
  return list.includes(source);
}
function toggleSource(source: FormReportSource, checked: boolean): void {
  const list = new Set((cfg<FormReportSource[] | null>('sources') ?? []) as FormReportSource[]);
  if (checked) list.add(source);
  else list.delete(source);
  const next = REPORT_SOURCES.filter((s) => list.has(s));
  // Empty ⇒ null so the builder omits `sources`.
  setCfg('sources', next.length ? next : null);
}

// labels multi (literal ids).
const labelsModel = computed<string[]>({
  get: () => (cfg<string[] | null>('labels') ?? []) as string[],
  set: (v) => setCfg('labels', v),
});
</script>

<template>
  <li
    class="overflow-hidden rounded-next-lg border bg-next-card"
    :class="hasError ? 'border-next-danger' : 'border-next-border'"
  >
    <!-- Collapsed ROW header: a toggle button (chevron + position + type + summary +
         error badge) spanning the row, then the trailing controls (X before ▲▼). The
         reorder / remove affordances stay visible whether the card is open or closed. -->
    <div class="flex items-center gap-next-2 px-next-3 py-next-2_5">
      <button
        type="button"
        class="flex min-w-0 flex-1 items-center gap-next-2 rounded-next-md text-left outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
        :aria-expanded="expanded"
        :aria-controls="bodyId"
        @click="emit('toggle')"
      >
        <Icon
          name="chevron-down"
          class="shrink-0 text-next-muted-foreground transition-transform duration-[var(--duration-next-fast)]"
          :class="expanded ? 'rotate-180' : ''"
        />
        <span
          class="flex h-6 w-6 shrink-0 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-2xs font-next-semibold text-next-primary-subtle-foreground"
          aria-hidden="true"
        >
          {{ index + 1 }}
        </span>
        <Badge variant="neutral" tone="subtle" size="sm" :icon="stepIcon(step.type)" class="shrink-0">
          {{ stepLabel(step.type, t) }}
        </Badge>
        <span v-if="!expanded" class="min-w-0 flex-1 truncate text-next-sm text-next-muted-foreground">
          {{ summary }}
        </span>
        <Badge
          v-if="hasError"
          variant="danger"
          tone="subtle"
          size="sm"
          icon="alert-circle"
          class="shrink-0"
        >
          {{ t('workflows.step.rowError') }}
        </Badge>
      </button>
      <div class="flex shrink-0 items-center gap-next-1">
        <Button
          v-if="total > 1"
          variant="ghost"
          size="icon-xs"
          leading-icon="x"
          :aria-label="t('workflows.step.removeStep')"
          @click="emit('remove')"
        />
        <Button
          variant="ghost"
          size="icon-xs"
          leading-icon="chevron-up"
          :disabled="index === 0"
          :aria-label="t('workflows.step.moveUp', '', { position: index + 1 })"
          @click="emit('move', -1)"
        />
        <Button
          variant="ghost"
          size="icon-xs"
          leading-icon="chevron-down"
          :disabled="index === total - 1"
          :aria-label="t('workflows.step.moveDown', '', { position: index + 1 })"
          @click="emit('move', 1)"
        />
      </div>
    </div>

    <div
      v-if="expanded"
      :id="bodyId"
      class="flex flex-col gap-next-4 border-t border-next-border px-next-4 pb-next-4 pt-next-4"
    >
      <!-- Step key (reference source). -->
      <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
        <FormField
          :label="t('workflows.step.keyLabel')"
          required
          :description="t('workflows.step.keyHint')"
          :error="keyError"
        >
          <TextInput
            :model-value="step.key"
            class="font-next-mono"
            :placeholder="t('workflows.step.keyPlaceholder')"
            :aria-label="t('workflows.step.keyLabel')"
            @update:model-value="onKeyInput"
          />
        </FormField>
      </div>

      <!-- create_task -->
      <template v-if="step.type === 'create_task'">
        <!-- Title (required, full MarkdownEditor: variables + operations + if-blocks + ai-text). -->
        <FormField :label="t('workflows.step.config.title')" required :error="fieldError('title')">
          <MarkdownEditor
            :model-value="strValue('title')"
            min-height="4rem"
            :variables="editorFeature"
            :if-blocks="IF_BLOCK_CONFIG"
            :ai-text="aiTextConfig"
            :placeholder="t('workflows.step.config.titlePlaceholder')"
            :aria-label="t('workflows.step.config.title')"
            @update:model-value="(v: string) => setCfg('title', v)"
          />
        </FormField>

        <!-- Description (full MarkdownEditor: variables + operations + if-blocks + ai-text). -->
        <FormField :label="t('workflows.step.config.description')" :error="fieldError('description')">
          <MarkdownEditor
            :model-value="strValue('description')"
            min-height="6rem"
            :variables="editorFeature"
            :if-blocks="IF_BLOCK_CONFIG"
            :ai-text="aiTextConfig"
            :placeholder="t('workflows.step.config.descriptionPlaceholder')"
            :aria-label="t('workflows.step.config.description')"
            @update:model-value="(v: string) => setCfg('description', v)"
          />
        </FormField>

        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2">
          <!-- Priority: value-or-variable (Select literal | enum/text variable). -->
          <FormField :label="t('workflows.step.config.priority')" :error="fieldError('priority')">
            <ValueOrVariableField
              v-model="priorityModel"
              :variables="allVariables"
              :operations-catalog="operationsCatalog"
              :arg-variables="allVariables"
              :result-types="vovFieldSpecs.priority.resultTypes"
              :target-options="vovFieldSpecs.priority.targetOptions"
              :external-error-present="!!fieldError('priority')"
              :picker-label="t('workflows.step.config.priority')"
            >
              <template #default="{ value, setValue }">
                <Select
                  :model-value="(value as string | null) ?? null"
                  :options="priorityOptions"
                  :placeholder="t('workflows.step.config.priorityPlaceholder')"
                  :aria-label="t('workflows.step.config.priority')"
                  @update:model-value="(v) => setValue(v)"
                />
              </template>
            </ValueOrVariableField>
          </FormField>

          <!-- Deadline: date-or-variable. -->
          <FormField :label="t('workflows.step.config.deadline')" :error="fieldError('deadline')">
            <DateOrVariableField
              v-model="deadlineModel"
              :variables="allVariables"
              :operations-catalog="operationsCatalog"
              :arg-variables="allVariables"
              :external-error-present="!!fieldError('deadline')"
              :picker-label="t('workflows.step.config.deadline')"
              :date-label="t('workflows.step.config.deadline')"
            />
          </FormField>
        </div>

        <!-- Labels (literal ids, multi). -->
        <FormField :label="t('workflows.step.config.labels')" :description="t('workflows.step.config.labelsHint')" :error="fieldError('labels')">
          <LabelSelect
            v-model="labelsModel"
            :addable="false"
            :aria-label="t('workflows.step.config.labels')"
            :placeholder="t('workflows.step.config.labelsPlaceholder')"
          />
        </FormField>

        <!-- Attachment: value-or-variable (upload a file | a FILE variable, e.g. a
             submission's file). No operations modal — nothing coerces INTO a file, so
             the variable picker is type-filtered to file variables. -->
        <FormField
          :label="t('workflows.step.config.attachments')"
          :description="t('workflows.step.config.attachmentsHint')"
          :error="fieldError('attachments')"
        >
          <ValueOrVariableField
            v-model="attachmentsModel"
            :variables="fileVariables"
            :result-types="vovFieldSpecs.attachments.resultTypes"
            :external-error-present="!!fieldError('attachments')"
            :picker-label="t('workflows.step.config.attachments')"
          >
            <template #default="{ value, setValue, disabled }">
              <FormFileInput
                :model-value="(value as string | null) ?? null"
                :disabled="disabled"
                @update:model-value="(v) => setValue(v)"
              />
            </template>
          </ValueOrVariableField>
        </FormField>

        <!-- Assignee: SegmentedControl user/bot + the matching picker. -->
        <FormField
          :label="t('workflows.step.config.assignee')"
          :description="t('workflows.step.config.assigneeHint')"
          :error="fieldError('assignee_id') || fieldError('assignee_type')"
        >
          <div class="flex flex-col gap-next-2 next-sm:flex-row next-sm:items-center">
            <SegmentedControl
              size="sm"
              :model-value="shownAssigneeType"
              :options="assigneeSegments"
              :aria-label="t('workflows.step.config.assignee')"
              class="shrink-0"
              @update:model-value="(v) => onAssigneeSegment(v as 'user' | 'bot')"
            />
            <UserSelect
              v-if="shownAssigneeType === 'user'"
              :model-value="idValue('assignee_id')"
              class="min-w-0 flex-1"
              :aria-label="t('workflows.step.config.assigneeUser')"
              :placeholder="t('workflows.step.config.assigneePlaceholder')"
              @update:model-value="(v) => setAssignee(v)"
            />
            <BotSelect
              v-else
              :model-value="idValue('assignee_id')"
              class="min-w-0 flex-1"
              :aria-label="t('workflows.step.config.assigneeBot')"
              :placeholder="t('workflows.step.config.assigneePlaceholder')"
              @update:model-value="(v) => setAssignee(v)"
            />
          </div>
        </FormField>

        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2">
          <!-- form_id (single, optional). -->
          <FormField :label="t('workflows.step.config.formId')" :error="fieldError('form_id')">
            <FormSelect
              :model-value="idValue('form_id')"
              :aria-label="t('workflows.step.config.formId')"
              :placeholder="t('workflows.step.config.formIdPlaceholder')"
              @update:model-value="(v) => setCfg('form_id', v)"
            />
          </FormField>
          <!-- approval_pipeline_id (single, optional). -->
          <FormField
            :label="t('workflows.step.config.approvalPipelineId')"
            :description="t('workflows.step.config.approvalPipelineIdHint')"
            :error="fieldError('approval_pipeline_id')"
          >
            <PipelineSelect
              :model-value="idValue('approval_pipeline_id')"
              :aria-label="t('workflows.step.config.approvalPipelineId')"
              :placeholder="t('workflows.step.config.approvalPipelineIdPlaceholder')"
              @update:model-value="(v) => setCfg('approval_pipeline_id', v)"
            />
          </FormField>
        </div>
      </template>

      <!-- create_form_report -->
      <template v-else-if="step.type === 'create_form_report'">
        <!-- form_id (required). -->
        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
          <FormField :label="t('workflows.step.report.formId')" required :error="fieldError('form_id')">
            <FormSelect
              :model-value="idValue('form_id')"
              :aria-label="t('workflows.step.report.formId')"
              :placeholder="t('workflows.step.config.formIdPlaceholder')"
              @update:model-value="(v) => setCfg('form_id', v)"
            />
          </FormField>
        </div>

        <!-- name (required, full MarkdownEditor: variables + operations + if-blocks + ai-text). -->
        <FormField :label="t('workflows.step.report.name')" required :error="fieldError('name')">
          <MarkdownEditor
            :model-value="strValue('name')"
            min-height="4rem"
            :variables="editorFeature"
            :if-blocks="IF_BLOCK_CONFIG"
            :ai-text="aiTextConfig"
            :placeholder="t('workflows.step.report.namePlaceholder')"
            :aria-label="t('workflows.step.report.name')"
            @update:model-value="(v: string) => setCfg('name', v)"
          />
        </FormField>

        <!-- guidelines (optional, full MarkdownEditor: variables + operations + if-blocks + ai-text). -->
        <FormField :label="t('workflows.step.report.guidelines')" :error="fieldError('guidelines')">
          <MarkdownEditor
            :model-value="strValue('guidelines')"
            min-height="6rem"
            :variables="editorFeature"
            :if-blocks="IF_BLOCK_CONFIG"
            :ai-text="aiTextConfig"
            :placeholder="t('workflows.step.report.guidelinesPlaceholder')"
            :aria-label="t('workflows.step.report.guidelines')"
            @update:model-value="(v: string) => setCfg('guidelines', v)"
          />
        </FormField>

        <!-- sources (task / form report vocabulary). -->
        <FormField
          :label="t('workflows.step.report.sources')"
          :description="t('workflows.step.report.sourcesHint')"
          :error="fieldError('sources')"
        >
          <div class="flex flex-col gap-next-2 next-sm:flex-row next-sm:gap-next-6">
            <Checkbox
              :model-value="sourceChecked('task')"
              :label="t('workflows.step.report.source.task')"
              @update:model-value="(v) => toggleSource('task', v)"
            />
            <Checkbox
              :model-value="sourceChecked('form')"
              :label="t('workflows.step.report.source.form')"
              @update:model-value="(v) => toggleSource('form', v)"
            />
          </div>
        </FormField>

        <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2">
          <!-- submissions_from (optional, date-or-variable, server-default hint). -->
          <FormField
            :label="t('workflows.step.report.submissionsFrom')"
            :description="t('workflows.step.report.fromHint')"
            :error="fieldError('submissions_from')"
          >
            <DateOrVariableField
              v-model="fromModel"
              :variables="allVariables"
              :operations-catalog="operationsCatalog"
              :arg-variables="allVariables"
              :external-error-present="!!fieldError('submissions_from')"
              :picker-label="t('workflows.step.report.submissionsFrom')"
              :date-label="t('workflows.step.report.submissionsFrom')"
            />
          </FormField>
          <!-- submissions_to (optional, date-or-variable, server-default hint). -->
          <FormField
            :label="t('workflows.step.report.submissionsTo')"
            :description="t('workflows.step.report.toHint')"
            :error="fieldError('submissions_to')"
          >
            <DateOrVariableField
              v-model="toModel"
              :variables="allVariables"
              :operations-catalog="operationsCatalog"
              :arg-variables="allVariables"
              :external-error-present="!!fieldError('submissions_to')"
              :picker-label="t('workflows.step.report.submissionsTo')"
              :date-label="t('workflows.step.report.submissionsTo')"
            />
          </FormField>
        </div>
      </template>

      <!-- generate_content (R2 sub-stage 5) -->
      <template v-else-if="step.type === 'generate_content'">
        <!-- The RECIPE (required). Changing it clears the slot mapping (it belonged to the
             old recipe); hydration never does. -->
        <FormField
          :label="t('workflows.step.generate_content.templateLabel')"
          required
          :description="t('workflows.step.generate_content.templateHint')"
          :error="fieldError('template_id')"
        >
          <TemplateSelect
            :model-value="templateId"
            :seed="templateSeed"
            :aria-label="t('workflows.step.generate_content.templateLabel')"
            :placeholder="t('workflows.step.generate_content.templatePlaceholder')"
            @update:model-value="onTemplatePick"
          />
        </FormField>

        <!-- Loading the chosen recipe's declarations. -->
        <div
          v-if="templateLoading"
          class="flex flex-col gap-next-2"
          role="status"
          :aria-label="t('workflows.step.generate_content.templateLoading')"
        >
          <Skeleton variant="rect" height="1.25rem" width="12rem" />
          <Skeleton variant="rect" height="2.5rem" />
          <Skeleton variant="rect" height="2.5rem" />
        </div>

        <!-- The recipe could not be read — the slot rows are unknown, so say so instead of
             rendering an empty (and misleading) "no inputs" state. -->
        <Alert v-else-if="templateError" variant="danger" size="sm">
          {{ t('workflows.step.generate_content.templateLoadError') }}
          <template #actions>
            <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="retryTemplate">
              {{ t('workflows.step.generate_content.templateRetry') }}
            </Button>
          </template>
        </Alert>

        <template v-else-if="template">
          <!-- SCALE / COST: how much one run of THIS recipe actually produces. -->
          <Alert v-if="scaleHint" variant="info" size="sm" :title="contentTypeName">
            {{ scaleHint }}
          </Alert>

          <!-- A REQUIRED composite condemns the whole recipe for workflow use: it can never
               be supplied, so every run would hard-fail. Say it here, not via a 422. -->
          <Alert
            v-if="requiredCompositeSlots.length"
            variant="danger"
            size="sm"
            :title="t('workflows.step.generate_content.composite.requiredWarningTitle')"
          >
            {{
              t('workflows.step.generate_content.composite.requiredWarning', '', {
                slots: requiredCompositeSlots.join(', '),
              })
            }}
          </Alert>

          <!-- DRIFT: the recipe changed after this step was authored. NAME the differences;
               never silently reset the author's mapping. -->
          <Alert
            v-if="hasDrift"
            variant="warning"
            size="sm"
            :title="t('workflows.step.generate_content.drift.title')"
          >
            <span v-if="driftRemoved.length" class="block">
              {{ t('workflows.step.generate_content.drift.removed', '', { slots: driftRemoved.join(', ') }) }}
            </span>
            <span v-if="driftAdded.length" class="block">
              {{ t('workflows.step.generate_content.drift.added', '', { slots: driftAdded.join(', ') }) }}
            </span>
            <template v-if="driftRemoved.length" #actions>
              <Button variant="outline" size="sm" leading-icon="x" @click="dropUnknownSlots">
                {{ t('workflows.step.generate_content.drift.removeUnknown') }}
              </Button>
            </template>
          </Alert>

          <!-- Still-unfilled required inputs: blocks Save, but a WARNING (not an error) —
               a freshly-picked recipe is unfinished, not broken. -->
          <Alert v-if="unfilledRequiredSlots.length" variant="warning" size="sm">
            {{
              t('workflows.step.generate_content.missingRequired', '', {
                slots: unfilledRequiredSlots.join(', '),
              })
            }}
          </Alert>

          <!-- The recipe's DECLARED inputs, one typed row each. -->
          <div class="flex flex-col gap-next-3">
            <div>
              <h4 class="text-next-sm font-next-semibold text-next-fg">
                {{ t('workflows.step.generate_content.slotsTitle') }}
              </h4>
              <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">
                {{ t('workflows.step.generate_content.slotsHint') }}
              </p>
            </div>

            <p v-if="declaredSlots.length === 0" class="text-next-xs text-next-muted-foreground">
              {{ t('workflows.step.generate_content.noSlots') }}
            </p>

            <FormField
              v-for="slot in declaredSlots"
              :key="slot.name"
              :label="slot.name"
              :required="slotRequired(slot.descriptor)"
              :description="slot.description ?? undefined"
              :error="fieldError(`slots.${slot.name}`)"
            >
              <div class="flex flex-col gap-next-1_5">
                <!-- TYPE + REQUIRED markers. Required is the DEFAULT (a descriptor has no
                     `required` key — it is `nullable !== true`), so BOTH states are marked
                     explicitly rather than only flagging the exception. -->
                <div class="flex flex-wrap items-center gap-next-1_5">
                  <Badge variant="neutral" tone="subtle" size="sm">{{ slotTypeLabel(slot.descriptor) }}</Badge>
                  <Badge
                    :variant="slotRequired(slot.descriptor) ? 'warning' : 'neutral'"
                    tone="subtle"
                    size="sm"
                    :icon="slotRequired(slot.descriptor) ? 'alert-circle' : 'circle'"
                  >
                    {{
                      slotRequired(slot.descriptor)
                        ? t('workflows.step.generate_content.requiredMarker')
                        : t('workflows.step.generate_content.optionalMarker')
                    }}
                  </Badge>
                </div>

                <!-- COMPOSITE: a workflow can never supply it. Disabled + explained, never
                     hidden — the author must be able to see why the recipe is limited. -->
                <template v-if="slotUnsupported(slot.descriptor)">
                  <TextInput
                    model-value=""
                    disabled
                    :aria-label="slot.name"
                    :placeholder="t('workflows.step.generate_content.composite.placeholder')"
                  />
                  <p class="flex items-start gap-next-1 text-next-xs text-next-muted-foreground">
                    <Icon name="info" class="mt-px shrink-0" aria-hidden="true" />
                    <span>
                      {{
                        slotRequired(slot.descriptor)
                          ? t('workflows.step.generate_content.composite.requiredNote')
                          : t('workflows.step.generate_content.composite.optionalNote')
                      }}
                    </span>
                  </p>
                  <!-- Already mapped (an older step, or the recipe was retyped): the server
                       rejects it, so offer the one-click fix rather than a silent drop. -->
                  <p
                    v-if="mappedCompositeSlots.includes(slot.name)"
                    class="flex flex-wrap items-center gap-next-2 text-next-xs text-next-danger"
                    role="alert"
                  >
                    <span>{{ t('workflows.step.generate_content.composite.mappedNote') }}</span>
                    <Button variant="outline" size="xs" leading-icon="x" @click="unsetSlot(slot.name)">
                      {{ t('workflows.step.generate_content.composite.unmap') }}
                    </Button>
                  </p>
                </template>

                <!-- FILE (scalar): mirrors create_task's attachment — upload a file OR
                     reference a FILE variable. No operations: nothing coerces INTO a file,
                     so the picker is type-filtered instead of show-all. -->
                <ValueOrVariableField
                  v-else-if="slotIsFile(slot.descriptor)"
                  :model-value="slotUnion(slot.name)"
                  :variables="fileVariables"
                  :result-types="slotResultTypes(slot.descriptor)"
                  :external-error-present="!!fieldError(`slots.${slot.name}`)"
                  :picker-label="slot.name"
                  @update:model-value="(v) => onSlotInput(slot.name, v)"
                >
                  <template #default="{ value, setValue, disabled }">
                    <FormFileInput
                      :model-value="(value as string | null) ?? null"
                      :disabled="disabled"
                      @update:model-value="(v) => setValue(v)"
                    />
                  </template>
                </ValueOrVariableField>

                <!-- LIST (an arrayed scalar / choice): a repeatable literal, or one
                     multi-valued variable coerced by operations. -->
                <ValueOrVariableField
                  v-else-if="slotIsList(slot.descriptor)"
                  :model-value="slotUnion(slot.name)"
                  :variables="allVariables"
                  :operations-catalog="operationsCatalog"
                  :arg-variables="allVariables"
                  :result-types="slotResultTypes(slot.descriptor)"
                  :external-error-present="!!fieldError(`slots.${slot.name}`)"
                  :picker-label="slot.name"
                  @update:model-value="(v) => onSlotInput(slot.name, v)"
                >
                  <template #default="{ value, setValue, disabled }">
                    <div class="flex w-full flex-col gap-next-2 py-next-1_5">
                      <div
                        v-for="(item, i) in slotList(value)"
                        :key="i"
                        class="flex items-start gap-next-2"
                      >
                        <div class="min-w-0 flex-1">
                          <TypedLiteralInput
                            :model-value="item"
                            :base="slotLiteralBase(slot.descriptor)"
                            :options="slotOptions(slot.descriptor)"
                            :disabled="disabled"
                            :aria-label="slot.name"
                            @update:model-value="(v) => setSlotListItem(slotList(value), i, v, setValue)"
                          />
                        </div>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          type="button"
                          leading-icon="trash"
                          :disabled="disabled"
                          :aria-label="t('workflows.step.generate_content.removeItem')"
                          @click="removeSlotListItem(slotList(value), i, setValue)"
                        />
                      </div>
                      <div>
                        <Button
                          variant="outline"
                          size="xs"
                          type="button"
                          leading-icon="plus"
                          :disabled="disabled"
                          @click="addSlotListItem(slot.descriptor, slotList(value), setValue)"
                        >
                          {{ t('workflows.step.generate_content.addItem') }}
                        </Button>
                      </div>
                    </div>
                  </template>
                </ValueOrVariableField>

                <!-- SCALAR / CHOICE: the shared typed literal, or any variable coerced by
                     operations to the slot's own type. -->
                <ValueOrVariableField
                  v-else
                  :model-value="slotUnion(slot.name)"
                  :variables="allVariables"
                  :operations-catalog="operationsCatalog"
                  :arg-variables="allVariables"
                  :result-types="slotResultTypes(slot.descriptor)"
                  :external-error-present="!!fieldError(`slots.${slot.name}`)"
                  :picker-label="slot.name"
                  @update:model-value="(v) => onSlotInput(slot.name, v)"
                >
                  <template #default="{ value, setValue, disabled }">
                    <TypedLiteralInput
                      :model-value="value"
                      :base="slotLiteralBase(slot.descriptor)"
                      :options="slotOptions(slot.descriptor)"
                      :disabled="disabled"
                      :aria-label="slot.name"
                      @update:model-value="(v) => setValue(v)"
                    />
                  </template>
                </ValueOrVariableField>
              </div>
            </FormField>
          </div>
        </template>

        <!-- Optional session NAME + the Disk destination for the produced images. -->
        <FormField
          :label="t('workflows.step.generate_content.nameLabel')"
          :description="t('workflows.step.generate_content.nameHint')"
          :error="fieldError('name')"
        >
          <TextInput
            :model-value="strValue('name')"
            :maxlength="255"
            :aria-label="t('workflows.step.generate_content.nameLabel')"
            :placeholder="t('workflows.step.generate_content.namePlaceholder')"
            @update:model-value="(v: string) => setCfg('name', v)"
          />
        </FormField>

        <FormField
          :label="t('workflows.step.generate_content.folderLabel')"
          :description="t('workflows.step.generate_content.folderHint')"
          :error="fieldError('folder_id')"
        >
          <div class="flex flex-col gap-next-2">
            <!-- The picker's CURRENT level IS the destination (the same panel the Disk
                 copy/move dialogs use); staying at the root means the Disk root. -->
            <FolderPickerPanel v-model="folderModel" />
            <p class="text-next-xs text-next-muted-foreground">
              {{ folderModel ? t('workflows.step.generate_content.folderChosen') : t('workflows.step.generate_content.folderRoot') }}
            </p>
          </div>
        </FormField>

        <!-- What this step publishes for LATER steps. `status` is listed because the
             backend publishes it, and labelled honestly: it is always `ready` today. -->
        <div class="flex flex-col gap-next-1 rounded-next-md border border-next-border bg-next-muted/20 p-next-3">
          <p class="text-next-xs font-next-medium text-next-fg">
            {{ t('workflows.step.generate_content.outputs.title') }}
          </p>
          <p class="text-next-xs text-next-muted-foreground">
            {{ t('workflows.step.generate_content.outputs.hint', '', { key: step.key || 'content' }) }}
          </p>
          <p class="text-next-xs text-next-muted-foreground">
            {{ t('workflows.step.generate_content.outputs.statusNote') }}
          </p>
        </div>
      </template>
    </div>
  </li>
</template>
