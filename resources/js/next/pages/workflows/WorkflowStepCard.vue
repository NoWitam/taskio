<script setup lang="ts">
// WorkflowStepCard — one step card in the ordered step editor (§4.6).
//
// Header: a position badge + the type badge + trailing controls in the RULE order —
// a conditional remove ✕ (only when > 1 step) BEFORE the permanent ▲▼ reorder
// Buttons (disabled at the ends, keyboard-reachable, aria-labelled). Body: the
// required `key` field (mono, client uniqueness) + the type-specific config fields.
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
import Checkbox from '../../ui/forms/Checkbox.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import ValueOrVariableField from './ValueOrVariableField.vue';
import DateOrVariableField from './DateOrVariableField.vue';
import WorkflowArgVariableField from './WorkflowArgVariableField.vue';
import FormFileInput from '../forms/FormFileInput.vue';
import { useI18n } from '../../app/i18n';
import { stepIcon, stepLabel } from './workflowMeta';
import { allValueVariables, stripVariableDirectives, toEditorVariablesTyped, variablesOfType } from './workflowVariables';
import { resolveOperationCatalog } from './workflowConditions';
import { pipelineSatisfies } from '../../ui/editor/extensions/operationHelpers';
import { sanitizeStepKey, type StepDraft } from './workflowEditorModel';
import type {
  CatalogVariable,
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

/** Whether this card carries ANY error (config 422, duplicate key, or a field type mismatch). */
const hasError = computed(
  () => Object.keys(props.errors).length > 0 || props.duplicateKey || hasTypeError.value,
);

/**
 * The one-line collapsed summary: the step's title / report name with its variable
 * directives stripped to their names (§3.2), or a type fallback when still blank.
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
  for (const [field, spec] of Object.entries(vovFieldSpecs.value)) {
    const value = cfg<WorkflowFieldValue | null>(field) ?? null;
    if (!value || value.kind !== 'variable') continue;
    const pipeline = (value.pipeline ?? []).map(toEditorStep);
    const satisfied = pipelineSatisfies(
      catalog,
      value.ref.type as VariablePrimitive,
      pipeline,
      spec.resultTypes as VariablePrimitive[],
      spec.targetOptions,
    );
    if (!satisfied) fields.push(field);
  }
  return fields;
});
const hasTypeError = computed(() => typeErrorFields.value.length > 0);

// Bubble the gate to the list editor → drawer (same `type-error` boolean contract as
// before). Immediate so an ALWAYS-mounted collapsed card reports its state on hydration;
// `flush: 'post'` defers the FIRST emit until AFTER this card has mounted, so the parent's
// reaction (auto-expand mutating expandedUids, which feeds back into this card's own
// render) never re-enters an instance that is still initializing (emitsOptions/flags null).
watch(hasTypeError, (value) => emit('type-error', value), { immediate: true, flush: 'post' });

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
    </div>
  </li>
</template>
