<script setup lang="ts">
// WorkflowDetailView — the read-only detail page for one workflow (§3). A sub-view
// of WorkflowsModuleLayout: the entity identity + section nav live in the module
// aside; the content area holds a slim right-aligned action bar + the active
// `?section=` (Overview | Runs, default overview).
//
// Reads the workflow from the store's detail cache when the user arrived via a card
// prefetch; on a DEEP LINK (no cache) it fetches by id and shows skeletons / an
// error state. The Overview renders structured, human-readable summaries (§3.2) for
// the TWO trigger types + TWO step types of the 5.1 re-scope:
//   • Status panel — StatusBadge + the inactive explanation.
//   • Trigger panel — form_submitted: "any form" vs "a specific form" (the read
//     resource carries only `form_id`, never a name, so we NEVER surface a raw id;
//     §3.2/§9-note-2), plus the Source subset + the Anonymous clause; schedule: the
//     descriptor-driven `describeSchedule` cadence sentence + next-due / last-run.
//   • Conditions panel — typed rows (field path's last segment + type icon +
//     operator word + a typed value chip) or the "always runs" empty state; HIDDEN
//     for schedule (conditions are form_submitted-only, backend never has them there).
//   • Steps panel — an ordered read-only list: position + type badge + the `key`
//     mono chip + a one-line summary (create_task → its title; create_form_report →
//     its name), with variable directives echoed as their variable name (the
//     MarkdownViewer renders base markdown only — it does NOT render `@[variable]`
//     directives as chips — so we strip each directive to its catalog/name label
//     per §3.2's fallback, never the raw `@[variable](…)` bytes).
//
// Actions (§3.1): back, Run now (when `can_run`, opens the `?run=<id>` run-now
// modal), Activate/Deactivate (when `can_change_status`), Edit (when
// `can_be_edited`, opens `?workflow=<id>`).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import WorkflowRunsView from './WorkflowRunsView.vue';
import { workflowStatusMap } from './workflowStatus';
import { triggerIcon, triggerLabel, stepIcon, stepLabel } from './workflowMeta';
import { describeSchedule } from './workflowSchedule';
import { stripVariableDirectives, variableIcon } from './workflowVariables';
import { useWorkflowsStore } from '../../app/stores/workflows';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import type {
  CreateFormReportStepConfig,
  CreateTaskStepConfig,
  FormSubmittedTriggerConfig,
  ScheduleTriggerConfig,
  SubmissionSource,
  WorkflowCatalog,
  WorkflowCondition,
  WorkflowStep,
  WorkflowVariableType,
} from './types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useWorkflowsStore();
const toast = useToast();

const workflowId = computed(() => String(route.params.id));
const statusMap = computed(() => workflowStatusMap(t));

// The active detail section, chosen from the module aside (`?section=`).
const section = computed(() => {
  const s = route.query.section;
  return (Array.isArray(s) ? s[0] : s) || 'overview';
});

// The cached detail (when it matches the route id), else null until fetched.
const workflow = computed(() =>
  store.detail && store.detail.id === workflowId.value ? store.detail : null,
);

const loading = ref(false);
const loadError = ref(false);

async function load(): Promise<void> {
  if (workflow.value) return; // prefetched by the list card → no fetch flash
  loading.value = true;
  loadError.value = false;
  const result = await store.fetchWorkflow(workflowId.value);
  loading.value = false;
  if (!result) loadError.value = true;
}

onMounted(load);
watch(workflowId, () => {
  loadError.value = false;
  void load();
});

// --- Capability-gated actions ---------------------------------------------
const canRun = computed(() => workflow.value?.can_run === true);
const canChangeStatus = computed(() => workflow.value?.can_change_status === true);
const canEdit = computed(() => workflow.value?.can_be_edited === true);
const isActive = computed(() => workflow.value?.status === 'active');

const togglingStatus = ref(false);
async function onToggleStatus(): Promise<void> {
  if (!workflow.value || togglingStatus.value) return;
  const next = isActive.value ? 'inactive' : 'active';
  togglingStatus.value = true;
  try {
    await store.setStatus(workflow.value.id, next);
    toast.success(
      next === 'active'
        ? t('workflows.statusAction.activated')
        : t('workflows.statusAction.deactivated'),
    );
  } catch {
    toast.danger(t('workflows.statusAction.error'));
  } finally {
    togglingStatus.value = false;
  }
}

function onRun(): void {
  if (workflow.value) void router.push({ query: { ...route.query, run: workflow.value.id } });
}
function onEdit(): void {
  if (workflow.value) void router.push({ query: { ...route.query, workflow: workflow.value.id } });
}
function onBack(): void {
  void router.push({ name: 'next.workflows' });
}

// --- Overview: typed view-state (the two 5.1 trigger types) -----------------
const triggerType = computed<'form_submitted' | 'schedule'>(
  () => workflow.value?.trigger_type ?? 'form_submitted',
);
const conditions = computed<WorkflowCondition[]>(() => workflow.value?.conditions ?? []);
const steps = computed<WorkflowStep[]>(() => workflow.value?.steps ?? []);

/**
 * The read-side variable catalog for this workflow's form (form_submitted only).
 * The detail resource does NOT embed the catalog, so we fetch it on demand purely
 * to give the Conditions field labels + the step-summary variable names their real
 * text. Everything degrades gracefully to a mono path / stripped name when absent
 * (§3.2: "never a blank").
 */
const catalog = ref<WorkflowCatalog | null>(null);
watch(
  workflow,
  async (wf) => {
    catalog.value = null;
    if (!wf || wf.trigger_type !== 'form_submitted') return;
    const formId = (wf.trigger_config as FormSubmittedTriggerConfig)?.form_id;
    if (!formId) return; // "any form" → no catalog
    try {
      // Best-effort: the panels degrade to path segments / stripped names when the
      // catalog can't be resolved (§3.2 "never a blank"), so a failure is non-fatal.
      catalog.value = await store.fetchWorkflowCatalog(formId);
    } catch {
      catalog.value = null;
    }
  },
  { immediate: true },
);

/** A localized timestamp, or the raw string if it can't be parsed; '' when absent. */
function formatWhen(raw: string | null | undefined): string {
  if (!raw) return '';
  const d = new Date(raw);
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleString();
}

// --- form_submitted trigger sentence ----------------------------------------
const formConfig = computed<FormSubmittedTriggerConfig>(
  () => (workflow.value?.trigger_config ?? { form_id: null }) as FormSubmittedTriggerConfig,
);

/** "…for a specific form" vs "…for any form" — never the raw id (§3.2/§9-note-2). */
const formTriggerSentence = computed(() =>
  formConfig.value.form_id
    ? t('workflows.detail.triggerFormSpecific')
    : t('workflows.detail.triggerFormAny'),
);

/** The Source sub-line: manual + in-task / manual only / in-task only / any source. */
const sourceSentence = computed(() => {
  const set = formConfig.value.source?.in as SubmissionSource[] | undefined;
  if (!set || set.length === 0) return t('workflows.detail.sourceAny');
  const hasManual = set.includes('manual');
  const hasTask = set.includes('task');
  if (hasManual && hasTask) return t('workflows.detail.sourceManualTask');
  if (hasManual) return t('workflows.detail.sourceManualOnly');
  if (hasTask) return t('workflows.detail.sourceTaskOnly');
  return t('workflows.detail.sourceAny');
});

/**
 * The Anonymous sub-line — only surfaced when the trigger actually narrows on it
 * (`anonymous` is a real boolean). "any" (null) is the default and adds no line.
 */
const anonymousSentence = computed<string | null>(() => {
  const value = formConfig.value.anonymous;
  if (value === true) return t('workflows.trigger.anonymous.onlyAnonymous');
  if (value === false) return t('workflows.trigger.anonymous.onlyNonAnonymous');
  return null;
});

// --- schedule trigger sentence (describeSchedule, §4.5.6) -------------------
const scheduleSentence = computed<string | null>(() => {
  if (triggerType.value !== 'schedule') return null;
  const schedule = (workflow.value?.trigger_config as ScheduleTriggerConfig | undefined)?.schedule;
  if (!schedule) return null;
  // B4: pass the full config so the sentence includes the multi-time + exclusions clauses.
  return describeSchedule(schedule, t);
});

const nextDueText = computed(() => formatWhen(workflow.value?.next_due_at));
const lastScheduledText = computed(() => formatWhen(workflow.value?.last_scheduled_run_at));

// --- Conditions panel (typed rows) ------------------------------------------
/** A condition's field LABEL from the catalog, else the path's last segment (§3.2). */
function conditionFieldLabel(cond: WorkflowCondition): string {
  const field = (catalog.value?.fields ?? []).find((f) => f.path === cond.field);
  if (field) return field.label;
  // Never a blank + never the raw full path — the last path segment reads best.
  const segments = cond.field.split('.');
  return segments[segments.length - 1] || cond.field;
}
function conditionFieldIcon(cond: WorkflowCondition): IconName {
  return variableIcon(cond.field_type as WorkflowVariableType);
}
function conditionOperatorLabel(op: WorkflowCondition['operator']): string {
  return t(`workflows.condition.operator.${op}`);
}
/** Value-less operators render no chip (§3.2). */
function isValueless(op: WorkflowCondition['operator']): boolean {
  return op === 'is_true' || op === 'is_false';
}
/** A `between` renders two chips (from → to); a set renders one chip per value. */
function conditionValueChips(cond: WorkflowCondition): string[] {
  if (isValueless(cond.operator)) return [];
  const value = cond.value;
  if (value == null) return [];
  if (Array.isArray(value)) return value.map((v) => String(v));
  return [String(value)];
}
/** Whether the two-value chips are a "from → to" range (a `between` operator). */
function isBetween(cond: WorkflowCondition): boolean {
  return cond.operator === 'between';
}

// --- Steps panel (ordered read-only list) -----------------------------------
/**
 * A one-line summary for a step row (§3.2 point 4): create_task → its `title`,
 * create_form_report → its `name`. Variable directives inside are stripped to their
 * variable name (catalog by path, else the directive's own name) — the MarkdownViewer
 * renders base markdown only and would otherwise print the raw `@[variable](…)` bytes.
 */
function stepSummary(step: WorkflowStep): string {
  const cfg = (step.config ?? {}) as Partial<CreateTaskStepConfig & CreateFormReportStepConfig>;
  const raw = step.type === 'create_task' ? cfg.title : cfg.name;
  const echoed = stripVariableDirectives(typeof raw === 'string' ? raw : '', catalog.value, steps.value);
  if (echoed.trim() !== '') return echoed;
  return step.type === 'create_task'
    ? t('workflows.step.summary.createTaskFallback')
    : t('workflows.step.summary.createFormReportFallback');
}

function goToRuns(): void {
  void router.push({
    name: 'next.workflows.detail',
    params: { id: workflowId.value },
    query: { ...route.query, section: 'runs' },
  });
}
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <!-- Deep-link / fetch error → a clear error state with retry + back. -->
    <EmptyState
      v-if="loadError && !workflow"
      variant="error"
      :title="t('workflows.detail.errorTitle')"
      :description="t('workflows.detail.errorDescription')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load">
          {{ t('workflows.errors.retry') }}
        </Button>
      </template>
      <template #secondary>
        <Button variant="ghost" size="sm" leading-icon="arrow-left" @click="onBack">
          {{ t('workflows.detail.back') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Loading (deep-link without a prefetch): geometry-mimicking skeletons. -->
    <div v-else-if="loading && !workflow" class="flex flex-col gap-next-6">
      <div class="flex items-center gap-next-3">
        <Skeleton variant="circle" diameter="2.75rem" />
        <div class="flex flex-1 flex-col gap-next-2">
          <Skeleton variant="text" width="30%" />
          <Skeleton variant="text" width="50%" />
        </div>
      </div>
      <Skeleton variant="rect" height="9rem" />
      <Skeleton variant="rect" height="6rem" />
    </div>

    <template v-else-if="workflow">
      <!-- Slim action bar (right-aligned); identity + section nav live in the aside. -->
      <div class="flex flex-wrap items-center justify-end gap-next-2">
        <Button variant="ghost" leading-icon="arrow-left" @click="onBack">
          {{ t('workflows.detail.back') }}
        </Button>
        <Button v-if="canRun" leading-icon="arrow-right" @click="onRun">
          {{ t('workflows.actions.run') }}
        </Button>
        <Button
          v-if="canChangeStatus"
          :variant="isActive ? 'outline' : 'primary'"
          :leading-icon="isActive ? 'circle' : 'check-circle'"
          :loading="togglingStatus"
          :disabled="togglingStatus"
          @click="onToggleStatus"
        >
          {{ isActive ? t('workflows.actions.deactivate') : t('workflows.actions.activate') }}
        </Button>
        <Button v-if="canEdit" leading-icon="pencil" @click="onEdit">
          {{ t('workflows.actions.edit') }}
        </Button>
      </div>

      <!-- RUNS section: the run monitoring list + run-detail drawer (§5). -->
      <template v-if="section === 'runs'">
        <WorkflowRunsView :workflow-id="workflowId" />
      </template>

      <!-- OVERVIEW section. -->
      <template v-else>
        <div class="flex flex-col gap-next-6">
          <!-- 1. Status panel. -->
          <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
            <header class="flex items-center justify-between gap-next-3">
              <div class="flex items-center gap-next-2">
                <span
                  class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-primary-subtle text-next-primary-subtle-foreground"
                  aria-hidden="true"
                >
                  <Icon name="layout-dashboard" />
                </span>
                <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.detail.tabOverview') }}</h2>
              </div>
              <StatusBadge :status="workflow.status" :status-map="statusMap" size="sm" />
            </header>
            <Alert v-if="!isActive" variant="warning" size="sm">
              {{ t('workflows.detail.inactiveExplanation') }}
            </Alert>
            <Button variant="ghost" size="sm" class="self-start" @click="goToRuns">
              {{ t('workflows.detail.viewRuns') }}
            </Button>
          </Surface>

          <!-- 2. Trigger panel — a human-readable sentence, not raw JSON. -->
          <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
            <header class="flex items-center gap-next-2">
              <span
                class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-info-subtle text-next-info-subtle-foreground"
                aria-hidden="true"
              >
                <Icon :name="triggerIcon(triggerType)" />
              </span>
              <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.detail.triggerTitle') }}</h2>
            </header>

            <div class="flex flex-wrap items-center gap-next-2">
              <Badge variant="info" tone="subtle" size="sm" :icon="triggerIcon(triggerType)">
                {{ triggerLabel(triggerType, t) }}
              </Badge>
            </div>

            <!-- form_submitted: the "any/specific form" sentence + quiet sub-lines. -->
            <template v-if="triggerType === 'form_submitted'">
              <p class="text-next-sm text-next-fg">{{ formTriggerSentence }}</p>
              <dl class="flex flex-col gap-next-1">
                <div class="flex flex-wrap items-baseline gap-next-2 text-next-sm">
                  <dt class="text-next-muted-foreground">{{ t('workflows.detail.sourceLabel') }}</dt>
                  <dd class="text-next-fg">{{ sourceSentence }}</dd>
                </div>
                <div v-if="anonymousSentence" class="flex flex-wrap items-baseline gap-next-2 text-next-sm">
                  <dt class="text-next-muted-foreground">{{ t('workflows.detail.anonymousLabel') }}</dt>
                  <dd class="text-next-fg">{{ anonymousSentence }}</dd>
                </div>
              </dl>
            </template>

            <!-- schedule: the descriptor-driven cadence sentence + timing lines. -->
            <template v-else-if="triggerType === 'schedule'">
              <p v-if="scheduleSentence" class="text-next-sm text-next-fg">{{ scheduleSentence }}</p>
              <div class="flex flex-col gap-next-1">
                <p v-if="nextDueText" class="text-next-sm text-next-muted-foreground">
                  <Icon name="calendar" class="mr-next-1 inline align-text-bottom" aria-hidden="true" />
                  {{ t('workflows.detail.nextRun', '', { when: nextDueText }) }}
                </p>
                <p v-if="lastScheduledText" class="text-next-sm text-next-muted-foreground">
                  <Icon name="clock" class="mr-next-1 inline align-text-bottom" aria-hidden="true" />
                  {{ t('workflows.detail.lastRun', '', { when: lastScheduledText }) }}
                </p>
              </div>
            </template>
          </Surface>

          <!-- 3. Conditions panel — form_submitted only (hidden for schedule). -->
          <Surface
            v-if="triggerType === 'form_submitted'"
            bg="card"
            border
            elevation="sm"
            radius="lg"
            class="flex flex-col gap-next-4 p-next-6"
          >
            <header class="flex items-center gap-next-2">
              <span
                class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-warning-subtle text-next-warning-subtle-foreground"
                aria-hidden="true"
              >
                <Icon name="git-branch" />
              </span>
              <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.detail.conditionsTitle') }}</h2>
            </header>
            <p v-if="conditions.length === 0" class="text-next-sm text-next-muted-foreground">
              {{ t('workflows.detail.conditionsAlways') }}
            </p>
            <ul v-else class="flex flex-col gap-next-2">
              <li
                v-for="(cond, i) in conditions"
                :key="i"
                class="flex flex-wrap items-center gap-next-2 text-next-sm"
              >
                <span class="inline-flex items-center gap-next-1 font-next-medium text-next-fg">
                  <Icon :name="conditionFieldIcon(cond)" class="text-next-muted-foreground" aria-hidden="true" />
                  {{ conditionFieldLabel(cond) }}
                </span>
                <span class="text-next-muted-foreground">{{ conditionOperatorLabel(cond.operator) }}</span>
                <!-- A between renders "from → to"; a set renders one chip per value. -->
                <template v-for="(chip, ci) in conditionValueChips(cond)" :key="ci">
                  <span
                    v-if="isBetween(cond) && ci === 1"
                    class="text-next-muted-foreground"
                    aria-hidden="true"
                  >→</span>
                  <code class="rounded-next-sm bg-next-muted px-next-1_5 py-next-0_5 font-next-mono text-next-xs text-next-fg">{{ chip }}</code>
                </template>
              </li>
            </ul>
          </Surface>

          <!-- 4. Steps panel — ordered read-only list. -->
          <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
            <header class="flex items-center gap-next-2">
              <span
                class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-success-subtle text-next-success-subtle-foreground"
                aria-hidden="true"
              >
                <Icon name="list-checks" />
              </span>
              <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.detail.stepsTitle') }}</h2>
            </header>
            <ol class="flex flex-col gap-next-3">
              <li
                v-for="(step, i) in steps"
                :key="step.key || i"
                class="flex items-start gap-next-3 rounded-next-md border border-next-border bg-next-muted/20 p-next-3"
              >
                <span
                  class="flex h-6 w-6 shrink-0 items-center justify-center rounded-next-full bg-next-primary-subtle text-next-xs font-next-semibold text-next-primary-subtle-foreground"
                  aria-hidden="true"
                >
                  {{ i + 1 }}
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-next-1">
                  <div class="flex flex-wrap items-center gap-next-2">
                    <Badge variant="neutral" tone="subtle" size="sm" :icon="stepIcon(step.type)">
                      {{ stepLabel(step.type, t) }}
                    </Badge>
                    <code class="rounded-next-sm bg-next-muted px-next-1_5 py-next-0_5 font-next-mono text-next-xs text-next-fg">{{ step.key }}</code>
                  </div>
                  <p class="min-w-0 truncate text-next-sm text-next-muted-foreground">{{ stepSummary(step) }}</p>
                </div>
              </li>
            </ol>
          </Surface>
        </div>
      </template>
    </template>
  </div>
</template>
