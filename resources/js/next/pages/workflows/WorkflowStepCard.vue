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
import { computed, ref } from 'vue';
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
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import ValueOrVariableField from './ValueOrVariableField.vue';
import DateOrVariableField from './DateOrVariableField.vue';
import { useI18n } from '../../app/i18n';
import { stepIcon, stepLabel } from './workflowMeta';
import { toEditorVariables, variablesOfType } from './workflowVariables';
import { type StepDraft } from './workflowEditorModel';
import type {
  CatalogVariable,
  FormReportSource,
  WorkflowCatalog,
  WorkflowFieldValue,
} from './types';
import type { VariableDefinition } from '../../ui/editor/extensions/types';

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
  /** Per-field errors for THIS card, keyed by the config field (or `key`). */
  errors: Record<string, string>;
  /** True when this card's `key` collides with another step's key. */
  duplicateKey: boolean;
}>();

const emit = defineEmits<{
  (e: 'remove'): void;
  (e: 'move', dir: -1 | 1): void;
}>();

const { t } = useI18n();

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
const editorVariables = computed<VariableDefinition[]>(() =>
  toEditorVariables(props.catalog, props.steps, props.position),
);
const editorFeature = computed(() => ({
  variables: editorVariables.value,
  operationsCatalog: [],
}));

// priority: enum + text variables; deadline / windows: date variables.
const enumTextVariables = computed<CatalogVariable[]>(() =>
  variablesOfType(props.catalog, props.steps, props.position, ['enum', 'text']),
);
const dateVariables = computed<CatalogVariable[]>(() =>
  variablesOfType(props.catalog, props.steps, props.position, 'date'),
);

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
  <li class="rounded-next-lg border border-next-border bg-next-card p-next-4">
    <!-- Header: position + type badge + trailing controls (X before ▲▼). -->
    <div class="mb-next-3 flex items-center justify-between gap-next-2">
      <span class="flex items-center gap-next-2 text-next-sm font-next-medium text-next-fg">
        <span
          class="flex h-6 w-6 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-2xs font-next-semibold text-next-primary-subtle-foreground"
          aria-hidden="true"
        >
          {{ index + 1 }}
        </span>
        <Badge variant="neutral" tone="subtle" size="sm" :icon="stepIcon(step.type)">
          {{ stepLabel(step.type, t) }}
        </Badge>
      </span>
      <div class="flex items-center gap-next-1">
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

    <div class="flex flex-col gap-next-4">
      <!-- Step key (reference source). -->
      <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-[12rem_1fr]">
        <FormField
          :label="t('workflows.step.keyLabel')"
          required
          :description="t('workflows.step.keyHint')"
          :error="keyError"
        >
          <TextInput
            v-model="step.key"
            class="font-next-mono"
            :placeholder="t('workflows.step.keyPlaceholder')"
            :aria-label="t('workflows.step.keyLabel')"
          />
        </FormField>
      </div>

      <!-- create_task -->
      <template v-if="step.type === 'create_task'">
        <!-- Title (required, one-line MarkdownEditor with variables). -->
        <FormField :label="t('workflows.step.config.title')" required :error="fieldError('title')">
          <MarkdownEditor
            :model-value="strValue('title')"
            hide-toolbar
            min-height="2.5rem"
            max-height="6rem"
            :variables="editorFeature"
            :placeholder="t('workflows.step.config.titlePlaceholder')"
            :aria-label="t('workflows.step.config.title')"
            @update:model-value="(v: string) => setCfg('title', v)"
          />
        </FormField>

        <!-- Description (full MarkdownEditor with variables). -->
        <FormField :label="t('workflows.step.config.description')" :error="fieldError('description')">
          <MarkdownEditor
            :model-value="strValue('description')"
            min-height="6rem"
            :variables="editorFeature"
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
              :variables="enumTextVariables"
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
              :variables="dateVariables"
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

        <!-- name (required, one-line MarkdownEditor with variables). -->
        <FormField :label="t('workflows.step.report.name')" required :error="fieldError('name')">
          <MarkdownEditor
            :model-value="strValue('name')"
            hide-toolbar
            min-height="2.5rem"
            max-height="6rem"
            :variables="editorFeature"
            :placeholder="t('workflows.step.report.namePlaceholder')"
            :aria-label="t('workflows.step.report.name')"
            @update:model-value="(v: string) => setCfg('name', v)"
          />
        </FormField>

        <!-- guidelines (optional, full MarkdownEditor). -->
        <FormField :label="t('workflows.step.report.guidelines')" :error="fieldError('guidelines')">
          <MarkdownEditor
            :model-value="strValue('guidelines')"
            min-height="6rem"
            :variables="editorFeature"
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
              :variables="dateVariables"
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
              :variables="dateVariables"
              :picker-label="t('workflows.step.report.submissionsTo')"
              :date-label="t('workflows.step.report.submissionsTo')"
            />
          </FormField>
        </div>
      </template>
    </div>
  </li>
</template>
