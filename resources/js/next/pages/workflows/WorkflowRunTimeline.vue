<script setup lang="ts">
// WorkflowRunTimeline — the run-detail DRAWER body for one workflow run (next,
// §5.4). Opened by `?run_detail=<runId>` from a run row; fetches the run + its
// step audit via `workflowRuns.fetchRun` (the run route is nested under its
// workflow, so a foreign run 404s → the error state).
//
// Layout (the drawer chrome is off — this body owns its header + close + scroll):
//   • Header       — state badge (6-state, icon + label) + a single SOURCE badge (the
//                    relabelled origin; B3 collapsed origin + trigger_type) + duration;
//                    a nested-run line when depth > 0 / origin_run_id set.
//   • Waiting      — (R2 sub-stage 5) a run parked on a content generation gets an
//                    expectation-setting panel: how long it usually takes, the elapsed
//                    time AS OF THE LAST READ, an explicit REFRESH (there is no live push
//                    here), and the honest "no cancel yet".
//   • Trigger      — `trigger_payload` flattened to labelled key→value rows (mono
//                    keys), NOT raw JSON.
//   • Step timeline — the shared Timeline pattern, one item per audit step ordered
//                    by `position`: type + key (mono), a status badge, the step
//                    `payload` as key→value, and an error Alert when present.
//
// Four states: loading (Timeline's own skeleton items), error (Alert + retry),
// empty (a run with zero recorded steps), success. All strings localized.
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Timeline from '../../ui/patterns/Timeline.vue';
import TimelineItem from '../../ui/patterns/TimelineItem.vue';
import EntityCard, { type EntityMetaItem } from '../../ui/patterns/EntityCard.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import SubmissionPreviewDrawer from '../forms/SubmissionPreviewDrawer.vue';
import { resolveFormIcon } from '../../ui/forms/formIcon';
import {
  runStateIcon,
  originIcon,
  originLabel,
  stepIcon,
  stepLabel,
  toneToVariant,
} from './workflowMeta';
import { describeOccurrence, formatScheduledAt } from './workflowSchedule';
import { formatDuration, formatTimestamp } from './runFormat';
import { useWorkflowRunsStore } from '../../app/stores/workflowRuns';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import type { WorkflowRun, WorkflowRunStep, WorkflowTone } from './types';

const props = defineProps<{
  workflowId: string;
  runId: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  /** A FAILED run was retried → the NEW run (state `pending`); the host opens/refreshes it. */
  (e: 'retried', run: WorkflowRun): void;
}>();

const { t } = useI18n();
const router = useRouter();
const store = useWorkflowRunsStore();
const toast = useToast();

const run = ref<WorkflowRun | null>(null);
const loading = ref(false);
const loadError = ref(false);

/**
 * When the currently-shown run was last READ. There is NO live push on the run detail, so
 * a `waiting` run's elapsed time is an honest snapshot taken at load — it advances only
 * when the user refreshes, and the copy says exactly that.
 */
const loadedAt = ref(Date.now());

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = false;
  const result = await store.fetchRun(props.workflowId, props.runId);
  loading.value = false;
  loadedAt.value = Date.now();
  if (result) {
    run.value = result;
  } else {
    loadError.value = true;
  }
}

onMounted(load);
watch(
  () => props.runId,
  () => {
    run.value = null;
    void load();
  },
);

// --- Header view-state -----------------------------------------------------
const stateVariant = computed(() => (run.value ? toneToVariant(run.value.state_tone) : 'neutral'));
const stateTone = computed<'solid' | 'subtle'>(() =>
  run.value?.state === 'failed' ? 'solid' : 'subtle',
);
const stateLabel = computed(() =>
  run.value ? t(`workflows.runs.state.${run.value.state}`, run.value.state_label) : '',
);
const durationText = computed(() => (run.value ? formatDuration(run.value.duration_seconds) : null));

// --- WAITING (R2 sub-stage 5 — the visible face of the async design) ---------
// A run that reaches a SUSPENDING step (`generate_content`) parks in `waiting` until the
// generation settles. The state badge already names it; this section sets EXPECTATIONS:
// how long it usually takes, that there is no live push (hence the explicit Refresh), and
// — honestly — that there is no cancel today.
const isWaiting = computed(() => run.value?.state === 'waiting');

/**
 * How long the RUN has been going, as of the last read ('—' handled by the template).
 *
 * This is deliberately measured from `started_at` (the whole run), NOT from the moment the
 * run parked: the run resource exposes no suspended-at / waiting-since instant, and a run
 * with a step BEFORE the generation makes the two diverge. The copy therefore says "running
 * for", not "waiting for" — the label has to name the interval it actually measures.
 */
const runElapsed = computed<string | null>(() => {
  if (!isWaiting.value) return null;
  const since = run.value?.started_at ?? run.value?.created_at;
  if (!since) return null;
  const ms = loadedAt.value - new Date(since).getTime();
  if (Number.isNaN(ms)) return null;
  return formatDuration(Math.max(0, Math.floor(ms / 1000)));
});
const startedText = computed(() => formatTimestamp(run.value?.started_at ?? run.value?.created_at));
const finishedText = computed(() => formatTimestamp(run.value?.finished_at));

// --- trigger_payload → key→value rows --------------------------------------
interface KeyValue {
  key: string;
  value: string;
}

/** Flatten a payload object to displayable rows; a nested object → its JSON. */
function toRows(payload: Record<string, unknown> | null | undefined): KeyValue[] {
  if (!payload || typeof payload !== 'object') return [];
  return Object.entries(payload).map(([key, value]) => ({
    key,
    value: stringifyValue(value),
  }));
}

// An ISO-8601 date-time (with a Z or ±hh:mm offset) — the shape a raw payload value
// would otherwise dump as an unreadable machine timestamp.
const ISO_DATETIME_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/;

function stringifyValue(value: unknown): string {
  if (value == null) return '—';
  if (Array.isArray(value)) return value.map((v) => stringifyValue(v)).join(', ');
  if (typeof value === 'object') return JSON.stringify(value);
  // Format a lone ISO timestamp (e.g. a step payload date) so the generic dump never
  // shows a raw machine string; non-ISO strings pass through untouched.
  if (typeof value === 'string' && ISO_DATETIME_RE.test(value)) return formatTimestamp(value);
  return String(value);
}

// The generic key→value dump for a payload that isn't a form/schedule trigger. `scheduled_at`
// is EXCLUDED — it is the schedule reason's domain (shown there for a real schedule run) and
// pure noise on a manual run, so it must never appear as a raw row.
const triggerRows = computed<KeyValue[]>(() =>
  toRows(run.value?.trigger_payload).filter((row) => row.key !== 'scheduled_at'),
);

// --- Schedule "reason" (B6): NAME the matched occurrence semantically ---------
// The "reason" answers WHY the SCHEDULE fired this run, so it is shown ONLY for a run
// the schedule ACTUALLY triggered (origin === 'schedule'). A MANUAL run — even of a
// schedule-type workflow — was started by a person clicking "Run now", NOT by a matched
// occurrence, so it carries no meaningful reason (its `scheduled_at` is just ~now) and
// must never show one. `scheduled_at` is also filtered out of the generic payload dump
// (below), so a manual run never leaks it as a raw ISO row either. The descriptor lets
// `describeOccurrence` name the slot in the schedule's own zone; it falls back to a
// Tier-A timestamp, never blank.
const scheduledAt = computed<string | null>(() => {
  const v = (run.value?.trigger_payload as Record<string, unknown> | null | undefined)?.scheduled_at;
  return typeof v === 'string' ? v : null;
});
const isScheduleRun = computed(
  () => run.value?.origin === 'schedule' && !!scheduledAt.value,
);
const scheduleReason = computed<string | null>(() => {
  if (!isScheduleRun.value || !scheduledAt.value) return null;
  return describeOccurrence(scheduledAt.value, run.value?.schedule_descriptor ?? null, t);
});
// The human-formatted fire instant (long weekday + date + HH:mm in the schedule's tz) —
// the companion to the semantic reason. When the reason ITSELF fell back to this same
// timestamp (a descriptor that can't be cleanly named), the two strings are equal and
// the template drops the duplicate row.
const scheduledAtText = computed<string | null>(() => {
  if (!scheduledAt.value) return null;
  return formatScheduledAt(scheduledAt.value, run.value?.schedule_descriptor ?? null, t);
});

// --- Form submission trigger (B7): a LEAN form item + submission card + diff drawer -
// The form item is composed ENTIRELY from the ONE run-show response — NO second API
// call. The `run.form` block (name + description + icon) is resolved LIVE server-side;
// when it is absent (the trigger form was deleted/foreign) we fall back to the
// name-only `trigger_payload.form` snapshot. Never fetch, never crash.
interface FormSubmittedPayload {
  form?: { id?: string | number; name?: string | null; is_anonymous?: boolean } | null;
  submission?: { id?: string | number } | null;
  source?: string | null;
  submitted_at?: string | null;
  fields?: Record<string, unknown> | null;
}
const formPayload = computed<FormSubmittedPayload | null>(() => {
  const p = run.value?.trigger_payload;
  if (!p || typeof p !== 'object') return null;
  return p as FormSubmittedPayload;
});
// The resolved form block carried in the run-show body (absent → fall back to payload).
const resolvedForm = computed(() => run.value?.form ?? null);
const isFormRun = computed(
  () => run.value?.trigger_type === 'form_submitted' && !!formPayload.value?.form,
);
const formId = computed<string | null>(() => {
  const id = resolvedForm.value?.id ?? formPayload.value?.form?.id;
  return id != null ? String(id) : null;
});
const formName = computed(
  () =>
    resolvedForm.value?.name ||
    formPayload.value?.form?.name ||
    t('workflows.runs.detail.formTrigger.untitledForm'),
);
// The form's OWN icon (resolved block only); a generic file glyph when missing/absent.
const formIcon = computed<IconName>(() => resolveFormIcon(resolvedForm.value?.icon));
// The live form description — rendered as the item subtitle ONLY when present.
const formDescription = computed<string | null>(() => {
  const d = resolvedForm.value?.description;
  return typeof d === 'string' && d.trim() !== '' ? d : null;
});
const formIsAnonymous = computed(() => !!formPayload.value?.form?.is_anonymous);
const submissionId = computed<string | null>(() => {
  const id = formPayload.value?.submission?.id;
  return id != null ? String(id) : null;
});
const submittedAt = computed<string | null>(() => formPayload.value?.submitted_at ?? null);
const submissionSource = computed<string | null>(() => formPayload.value?.source ?? null);
const submissionApproved = computed(() => !!submittedAt.value);
// Opens the form's detail route (→ submissions) in a NEW TAB (EntityCard adds rel).
const formHref = computed<string | null>(() =>
  formId.value ? router.resolve({ name: 'next.forms.detail', params: { id: formId.value } }).href : null,
);
const submissionSourceLabel = computed(() =>
  submissionSource.value === 'task'
    ? t('workflows.runs.detail.formTrigger.sourceTask')
    : t('workflows.runs.detail.formTrigger.sourceManual'),
);
const submissionMeta = computed<EntityMetaItem[]>(() => {
  const meta: EntityMetaItem[] = [];
  if (submittedAt.value) meta.push({ icon: 'calendar', label: formatTimestamp(submittedAt.value) });
  meta.push({ icon: 'inbox', label: submissionSourceLabel.value });
  return meta;
});
const submissionDrawerOpen = ref(false);

// --- Steps (ordered by position) -------------------------------------------
const steps = computed<WorkflowRunStep[]>(() =>
  [...(run.value?.steps ?? [])].sort((a, b) => a.position - b.position),
);
const hasSteps = computed(() => steps.value.length > 0);

function stepRows(step: WorkflowRunStep): KeyValue[] {
  return toRows(step.payload);
}
function stepTone(tone: WorkflowTone | string): 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  return toneToVariant(tone);
}
function stepStatusLabel(step: WorkflowRunStep): string {
  return t(`workflows.runs.stepStatus.${step.status}`, step.status_label);
}

// --- Step OUTPUT → resource item (task / report) ----------------------------
// A succeeded step publishes the resource it produced as its `payload` (the audit
// output, WorkflowRunStepResource): create_task → {task_id, title}; create_form_report
// → {report_id, report_name}; generate_content → {session_id, content, image_file_ids,
// status, has_failed_parts} (its card deep-links to the produced generation SESSION and
// deliberately replaces the raw dump — `content` can be a whole post).
// We render that as a thin EntityCard (mirroring the
// form/submission cards) instead of raw key→value rows. A step with no recognized
// output (a FAILED step's null payload, or an unexpected shape) falls back to the
// readable key→value summary + the error Alert — never a raw JSON dump.
interface StepResource {
  kind: 'task' | 'report' | 'session' | 'event';
  icon: IconName;
  title: string;
  /** Whole-card open target (a real deep-link) when one exists; absent → static card. */
  href?: string;
  actionLabel?: string;
}

function computeStepResource(step: WorkflowRunStep): StepResource | null {
  const p = step.payload;
  if (!p || typeof p !== 'object') return null;

  if (step.type === 'create_task' && p.task_id != null) {
    const title =
      typeof p.title === 'string' && p.title.trim() !== ''
        ? p.title
        : t('workflows.runs.detail.stepResult.untitledTask');
    // Tasks honor `?task=<id>` (TasksView opens its detail Drawer) — a real deep-link,
    // opened in a NEW TAB so the run drawer stays put (mirroring the form card).
    return {
      kind: 'task',
      icon: 'list-checks',
      title,
      href: router.resolve({ name: 'next.tasks', query: { task: String(p.task_id) } }).href,
      actionLabel: t('workflows.runs.detail.stepResult.openTask', '', { name: title }),
    };
  }

  if (step.type === 'create_form_report' && p.report_id != null) {
    const title =
      typeof p.report_name === 'string' && p.report_name.trim() !== ''
        ? p.report_name
        : t('workflows.runs.detail.stepResult.untitledReport');
    // The report step output now carries `form_id` (alongside report_id/report_name), so
    // the card deep-links to the form's reports view honoring `?report=<id>` — which
    // FormReportsView auto-opens, mirroring FormSubmissionsView's `?submission=`. Opened
    // in a NEW TAB like the task/form cards so the run drawer stays put. An OLDER run
    // whose output predates `form_id` degrades to a static card (no link, no crash).
    const reportFormId = p.form_id != null ? String(p.form_id) : null;
    const href = reportFormId
      ? router.resolve({
          name: 'next.forms.reports',
          params: { id: reportFormId },
          query: { report: String(p.report_id) },
        }).href
      : undefined;
    return {
      kind: 'report',
      icon: 'file-text',
      title,
      href,
      actionLabel: href
        ? t('workflows.runs.detail.stepResult.openReport', '', { name: title })
        : undefined,
    };
  }

  // generate_content publishes its outputs as the step payload; `session_id` is the
  // provenance handle, so the card deep-links straight to the generation SESSION that
  // produced the content (a real route — `next.generator.sessions.detail`), opened in a NEW
  // TAB like the task/report/form cards so the run drawer stays put. There is no title on
  // the wire (the outputs are session_id / content / image_file_ids / status /
  // has_failed_parts), so the card is labelled by the step, never by a raw uuid.
  if (step.type === 'generate_content' && p.session_id != null) {
    const title = t('workflows.runs.detail.stepResult.sessionTitle');
    return {
      kind: 'session',
      icon: 'sparkles',
      title,
      href: router.resolve({
        name: 'next.generator.sessions.detail',
        params: { id: String(p.session_id) },
      }).href,
      actionLabel: t('workflows.runs.detail.stepResult.openSession'),
    };
  }

  // create_event publishes `event_id` + `title`. The calendar screen opens ONE event from
  // `?event=<uuid>` on its own route, so the card deep-links there — the same shape the
  // task card uses. Unlike the session card this one HAS a title on the wire, so it shows
  // the event's real name and only falls back when the resolved title came through blank.
  if (step.type === 'create_event' && p.event_id != null) {
    const title =
      typeof p.title === 'string' && p.title.trim() !== ''
        ? p.title
        : t('workflows.runs.detail.stepResult.untitledEvent');
    return {
      kind: 'event',
      icon: 'calendar',
      title,
      href: router.resolve({ name: 'next.calendar', query: { event: String(p.event_id) } }).href,
      actionLabel: t('workflows.runs.detail.stepResult.openEvent', '', { name: title }),
    };
  }

  return null;
}

const stepResources = computed<Record<string, StepResource | null>>(() => {
  const map: Record<string, StepResource | null> = {};
  for (const step of steps.value) map[step.id] = computeStepResource(step);
  return map;
});

// --- Retry (E): a FAILED run offers "run again" → a NEW run -----------------
const retrying = ref(false);
const isFailed = computed(() => run.value?.state === 'failed');

/** Map the 202/422 outcome to our own localized copy (never echo server prose). */
function retryErrorMessage(err: unknown): string {
  const errors = (err as { response?: { data?: { errors?: Record<string, unknown> } } })?.response
    ?.data?.errors;
  if (errors && 'run' in errors) return t('workflows.runs.detail.retry.errorRun');
  if (errors && 'workflow' in errors) return t('workflows.runs.detail.retry.errorWorkflow');
  return t('workflows.runs.detail.retry.error');
}

async function onRetry(): Promise<void> {
  if (retrying.value || !isFailed.value) return;
  retrying.value = true;
  try {
    const newRun = await store.retryRun(props.workflowId, props.runId);
    toast.success(t('workflows.runs.detail.retry.success'));
    // The host opens/refreshes the new run (per-workflow → `?run_detail=`; global →
    // the local selectedRun ref).
    emit('retried', newRun);
  } catch (err: unknown) {
    toast.danger(retryErrorMessage(err));
  } finally {
    retrying.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Header: identity + close (the drawer chrome is off). -->
    <header class="flex items-start justify-between gap-next-3 border-b border-next-border p-next-4">
      <div class="min-w-0">
        <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('workflows.runs.detail.title') }}</h2>
        <div v-if="run" class="mt-next-2 flex flex-wrap items-center gap-next-2">
          <Badge
            :variant="stateVariant"
            :tone="stateTone"
            size="sm"
            :icon="runStateIcon(run.state)"
          >
            {{ stateLabel }}
          </Badge>
          <!-- Source (the relabelled origin — B3 collapsed origin + trigger_type into
               this single badge, mirroring the run row). -->
          <Badge variant="neutral" tone="subtle" size="sm" :icon="originIcon(run.origin)">
            {{ originLabel(run.origin, t) }}
          </Badge>
          <Badge v-if="run.depth > 0" variant="neutral" tone="subtle" size="sm" icon="git-branch">
            {{ t('workflows.runs.nestedBadge', '', { depth: run.depth }) }}
          </Badge>
        </div>
        <!-- Timing lines. -->
        <div v-if="run" class="mt-next-2 flex flex-wrap items-center gap-next-3 text-next-xs text-next-muted-foreground">
          <span v-if="startedText" class="inline-flex items-center gap-next-1">
            <Icon name="clock" class="shrink-0" aria-hidden="true" />
            {{ t('workflows.runs.detail.started', '', { when: startedText }) }}
          </span>
          <span v-if="finishedText">{{ t('workflows.runs.detail.finished', '', { when: finishedText }) }}</span>
          <span>{{ durationText ? t('workflows.runs.duration', '', { value: durationText }) : '—' }}</span>
        </div>
      </div>
      <div class="-mt-next-1 flex shrink-0 items-center gap-next-2">
        <!-- Retry (E): only a FAILED run can be re-run; starts a NEW run. -->
        <Button
          v-if="isFailed"
          variant="outline"
          size="sm"
          leading-icon="rotate-ccw"
          :loading="retrying"
          :disabled="retrying"
          @click="onRetry"
        >
          {{ t('workflows.runs.detail.retry.button') }}
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          class="-mr-next-1"
          :aria-label="t('drawer.close', 'Close panel')"
          @click="emit('close')"
        >
          <Icon name="x" class="text-next-xl" />
        </Button>
      </div>
    </header>

    <!-- Body (own scroll). -->
    <div class="min-h-0 flex-1 overflow-y-auto p-next-4">
      <!-- Loading: several skeleton timeline items. -->
      <Timeline
        v-if="loading && !run"
        loading
        :loading-count="4"
        :aria-label="t('workflows.runs.detail.steps')"
      />

      <!-- Error (foreign run 404 / fetch failed) + retry. -->
      <Alert v-else-if="loadError && !run" variant="danger" size="sm">
        <div class="flex items-center justify-between gap-next-2">
          <span>{{ t('workflows.runs.detail.loadError') }}</span>
          <Button size="sm" variant="outline" leading-icon="rotate-ccw" @click="load">
            {{ t('workflows.errors.retry') }}
          </Button>
        </div>
      </Alert>

      <template v-else-if="run">
        <div class="flex flex-col gap-next-6">
          <!-- WAITING: the run is parked on a content generation. Sets expectations
               honestly (a minute or two; no cancel yet) and offers the REFRESH affordance,
               because there is no live push on this screen. -->
          <Alert
            v-if="isWaiting"
            variant="info"
            size="sm"
            icon="clock"
            :title="t('workflows.runs.detail.waiting.title')"
          >
            <span class="block">{{ t('workflows.runs.detail.waiting.body') }}</span>
            <span v-if="runElapsed" class="mt-next-1 block">
              {{ t('workflows.runs.detail.waiting.elapsed', '', { value: runElapsed }) }}
            </span>
            <span class="mt-next-1 block">{{ t('workflows.runs.detail.waiting.noCancel') }}</span>
            <template #actions>
              <Button
                variant="outline"
                size="sm"
                leading-icon="rotate-ccw"
                :loading="loading"
                :disabled="loading"
                @click="load"
              >
                {{ t('workflows.runs.detail.waiting.refresh') }}
              </Button>
            </template>
          </Alert>

          <!-- Trigger context. Schedule runs → a semantic "reason"; form_submitted
               runs → form + submission cards; anything else → the raw payload rows. -->
          <section v-if="isScheduleRun && scheduleReason" class="flex flex-col gap-next-2">
            <h3 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.runs.detail.reason.heading') }}</h3>
            <dl class="flex flex-col divide-y divide-next-border rounded-next-md border border-next-border">
              <div class="grid grid-cols-[minmax(8rem,_1fr)_2fr] items-baseline gap-next-3 px-next-3 py-next-2">
                <dt class="min-w-0 truncate font-next-mono text-next-xs text-next-muted-foreground">{{ t('workflows.runs.detail.reason.label') }}</dt>
                <dd class="min-w-0 break-words text-next-sm text-next-fg">{{ scheduleReason }}</dd>
              </div>
              <!-- The formatted fire instant (in the schedule tz). Dropped when it equals
                   the reason (a descriptor whose reason itself fell back to this stamp). -->
              <div
                v-if="scheduledAtText && scheduledAtText !== scheduleReason"
                class="grid grid-cols-[minmax(8rem,_1fr)_2fr] items-baseline gap-next-3 px-next-3 py-next-2"
              >
                <dt class="min-w-0 truncate font-next-mono text-next-xs text-next-muted-foreground">{{ t('workflows.runs.detail.reason.scheduledLabel') }}</dt>
                <dd class="min-w-0 break-words text-next-sm text-next-fg">{{ scheduledAtText }}</dd>
              </div>
            </dl>
          </section>

          <section v-else-if="isFormRun" class="flex flex-col gap-next-3">
            <h3 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.runs.detail.formTrigger.heading') }}</h3>
            <!-- STACK the cards vertically (the drawer is narrow — side-by-side truncated
                 the form/submission names). Each card is full-width, one below the other. -->
            <div class="flex flex-col gap-next-3">
              <!-- Form: a LEAN item composed from the run-show response's resolved
                   `run.form` block (icon + name + description) — NO extra fetch. The
                   description shows only when present; the whole card opens the form in
                   a NEW TAB. Falls back to the name-only `trigger_payload.form` when the
                   resolved block is absent (deleted/foreign form). -->
              <EntityCard
                :title="formName"
                :subtitle="formDescription ?? undefined"
                :href="formHref ?? undefined"
                target="_blank"
                :action-label="t('workflows.runs.detail.formTrigger.openForm', '', { name: formName })"
              >
                <template #leading>
                  <span class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground" aria-hidden="true">
                    <Icon :name="formIcon" class="text-next-lg" />
                  </span>
                </template>
                <template #status>
                  <Icon name="external-link" class="text-next-muted-foreground" aria-hidden="true" />
                </template>
                <template v-if="formIsAnonymous" #meta>
                  <span class="inline-flex items-center gap-next-1">
                    <Icon name="eye-off" class="text-next-sm" />
                    {{ t('workflows.runs.detail.formTrigger.anonymousForm') }}
                  </span>
                </template>
              </EntityCard>

              <!-- Submission card: opens the diff preview drawer. -->
              <EntityCard
                :title="t('workflows.runs.detail.formTrigger.submissionTitle')"
                :meta="submissionMeta"
                @click="submissionDrawerOpen = true"
              >
                <template #leading>
                  <span class="flex h-10 w-10 items-center justify-center rounded-next-full bg-next-muted text-next-muted-foreground" aria-hidden="true">
                    <Icon :name="formIsAnonymous ? 'eye-off' : 'user'" class="text-next-lg" />
                  </span>
                </template>
                <template #status>
                  <StatusBadge
                    :status="submissionApproved ? 'approved' : 'pending'"
                    :label="submissionApproved ? t('forms.submissions.approved') : t('forms.submissions.pending')"
                    :status-map="{
                      approved: { label: t('forms.submissions.approved'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
                      pending: { label: t('forms.submissions.pending'), variant: 'warning', tone: 'subtle', icon: 'clock' },
                    }"
                    size="sm"
                  />
                </template>
              </EntityCard>
            </div>
          </section>

          <section v-else-if="triggerRows.length" class="flex flex-col gap-next-2">
            <h3 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.runs.detail.triggerPayload') }}</h3>
            <dl class="flex flex-col divide-y divide-next-border rounded-next-md border border-next-border">
              <div
                v-for="row in triggerRows"
                :key="row.key"
                class="grid grid-cols-[minmax(8rem,_1fr)_2fr] items-baseline gap-next-3 px-next-3 py-next-2"
              >
                <dt class="min-w-0 truncate font-next-mono text-next-xs text-next-muted-foreground">{{ row.key }}</dt>
                <dd class="min-w-0 break-words text-next-sm text-next-fg">{{ row.value }}</dd>
              </div>
            </dl>
          </section>

          <!-- Step timeline. -->
          <section class="flex flex-col gap-next-3">
            <h3 class="text-next-sm font-next-semibold text-next-fg">{{ t('workflows.runs.detail.steps') }}</h3>

            <!-- Empty: a run with zero recorded steps. -->
            <EmptyState
              v-if="!hasSteps"
              size="sm"
              icon="list-checks"
              :title="t('workflows.runs.detail.emptySteps')"
            />

            <Timeline v-else :aria-label="t('workflows.runs.detail.steps')">
              <TimelineItem
                v-for="(step, i) in steps"
                :key="step.id"
                :icon="stepIcon(step.type)"
                :tone="stepTone(step.status_tone)"
                :last="i === steps.length - 1"
              >
                <template #title>
                  <span class="inline-flex items-center gap-next-2">
                    <span>{{ stepLabel(step.type, t) }}</span>
                    <code class="rounded-next-sm bg-next-muted px-next-1_5 py-next-0_5 font-next-mono text-next-xs text-next-fg">{{ step.key }}</code>
                  </span>
                </template>
                <template #afterTitle>
                  <Badge :variant="stepTone(step.status_tone)" tone="subtle" size="sm">
                    {{ stepStatusLabel(step) }}
                  </Badge>
                </template>

                <!-- Step OUTPUT as a resource ITEM (task / report) — same card language
                     as the form/submission cards. An unrecognized output falls back to a
                     readable key→value summary; the error (if any) always shows. -->
                <div class="flex flex-col gap-next-2">
                  <EntityCard
                    v-if="stepResources[step.id]"
                    :title="stepResources[step.id]!.title"
                    :href="stepResources[step.id]!.href"
                    :target="stepResources[step.id]!.href ? '_blank' : undefined"
                    :action-label="stepResources[step.id]!.actionLabel"
                  >
                    <template #leading>
                      <span class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground" aria-hidden="true">
                        <Icon :name="stepResources[step.id]!.icon" class="text-next-lg" />
                      </span>
                    </template>
                    <template v-if="stepResources[step.id]!.href" #status>
                      <Icon name="external-link" class="text-next-muted-foreground" aria-hidden="true" />
                    </template>
                  </EntityCard>

                  <dl
                    v-else-if="stepRows(step).length"
                    class="flex flex-col divide-y divide-next-border rounded-next-md border border-next-border"
                  >
                    <div
                      v-for="row in stepRows(step)"
                      :key="row.key"
                      class="grid grid-cols-[minmax(7rem,_1fr)_2fr] items-baseline gap-next-3 px-next-3 py-next-1_5"
                    >
                      <dt class="min-w-0 truncate font-next-mono text-next-2xs text-next-muted-foreground">{{ row.key }}</dt>
                      <dd class="min-w-0 break-words text-next-xs text-next-fg">{{ row.value }}</dd>
                    </div>
                  </dl>

                  <Alert v-if="step.error" variant="danger" size="sm">
                    {{ step.error }}
                  </Alert>
                </div>
              </TimelineItem>
            </Timeline>
          </section>
        </div>
      </template>
    </div>

    <!-- Diff preview of the run's form submission (B7): snapshot vs current. -->
    <SubmissionPreviewDrawer
      v-model:open="submissionDrawerOpen"
      mode="diff"
      :snapshot-fields="formPayload?.fields ?? null"
      :submission-id="submissionId"
      :form-id="formId"
      :submitted-at="submittedAt"
      :source="submissionSource"
      :is-anonymous="formIsAnonymous"
    />
  </div>
</template>
