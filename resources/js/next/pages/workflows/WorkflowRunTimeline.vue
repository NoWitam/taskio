<script setup lang="ts">
// WorkflowRunTimeline — the run-detail DRAWER body for one workflow run (next,
// §5.4). Opened by `?run_detail=<runId>` from a run row; fetches the run + its
// step audit via `workflowRuns.fetchRun` (the run route is nested under its
// workflow, so a foreign run 404s → the error state).
//
// Layout (the drawer chrome is off — this body owns its header + close + scroll):
//   • Header       — state badge (6-state, icon + label) + origin + trigger type +
//                    duration; a nested-run line when depth > 0 / origin_run_id set.
//   • Trigger      — `trigger_payload` flattened to labelled key→value rows (mono
//                    keys), NOT raw JSON.
//   • Step timeline — the shared Timeline pattern, one item per audit step ordered
//                    by `position`: type + key (mono), a status badge, the step
//                    `payload` as key→value, and an error Alert when present.
//
// Four states: loading (Timeline's own skeleton items), error (Alert + retry),
// empty (a run with zero recorded steps), success. All strings localized.
import { computed, onMounted, ref, watch } from 'vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Timeline from '../../ui/patterns/Timeline.vue';
import TimelineItem from '../../ui/patterns/TimelineItem.vue';
import {
  runStateIcon,
  originIcon,
  originLabel,
  triggerShort,
  stepIcon,
  stepLabel,
  toneToVariant,
} from './workflowMeta';
import { formatDuration, formatTimestamp } from './runFormat';
import { useWorkflowRunsStore } from '../../app/stores/workflowRuns';
import { useI18n } from '../../app/i18n';
import type { WorkflowRun, WorkflowRunStep, WorkflowTone } from './types';

const props = defineProps<{
  workflowId: string;
  runId: string;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const { t } = useI18n();
const store = useWorkflowRunsStore();

const run = ref<WorkflowRun | null>(null);
const loading = ref(false);
const loadError = ref(false);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = false;
  const result = await store.fetchRun(props.workflowId, props.runId);
  loading.value = false;
  if (result) run.value = result;
  else loadError.value = true;
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

function stringifyValue(value: unknown): string {
  if (value == null) return '—';
  if (Array.isArray(value)) return value.map((v) => stringifyValue(v)).join(', ');
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

const triggerRows = computed<KeyValue[]>(() => toRows(run.value?.trigger_payload));

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
          <Badge variant="neutral" tone="subtle" size="sm" :icon="originIcon(run.origin)">
            {{ originLabel(run.origin, t) }}
          </Badge>
          <Badge variant="info" tone="subtle" size="sm">
            {{ triggerShort(run.trigger_type, t) }}
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
      <Button
        variant="outline"
        size="icon-sm"
        class="-mr-next-1 -mt-next-1 shrink-0"
        :aria-label="t('drawer.close', 'Close panel')"
        @click="emit('close')"
      >
        <Icon name="x" class="text-next-xl" />
      </Button>
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
          <!-- Trigger payload: key→value rows (mono keys), not raw JSON. -->
          <section v-if="triggerRows.length" class="flex flex-col gap-next-2">
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

                <!-- Step payload (key→value) + error. -->
                <div class="flex flex-col gap-next-2">
                  <dl
                    v-if="stepRows(step).length"
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
  </div>
</template>
