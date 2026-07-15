<script setup lang="ts">
// WorkflowRunRow — one run in the Runs list (next, §5.3). A Surface card shaped
// like the Bot-inbox row: a state badge (the exhaustive 6-state map — icon +
// label, NEVER color-only), a single SOURCE badge (the relabelled origin — icon +
// label; B3 collapsed the redundant origin + trigger_type pair into one), a
// steps-count chip, the started time + formatted duration ("—" while unfinished),
// a nested-run badge when depth > 0, and — for a failed run — a truncated one-line
// error preview in danger. The whole row is a button that opens the run detail
// drawer.
//
// `showWorkflow` (the global cross-workflow feed) renders the run's parent workflow
// {icon, name} as a leading identity line; it degrades gracefully when the row
// carries no `workflow` (the per-workflow feed omits it).
//
// The Badge VARIANT is derived from the resource's `state_tone` (toneToVariant);
// the icon comes from the fixed state map (runStateIcon). The label prefers the
// FE i18n key (workflows.runs.state.<state>) for language-switch correctness,
// falling back to the server `state_label` (§5.3 / §5.5). Source label is FE i18n.
import { computed } from 'vue';
import Surface from '../../ui/layout/Surface.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import {
  runStateIcon,
  originIcon,
  originLabel,
  toneToVariant,
} from './workflowMeta';
import { formatDuration, formatTimestamp, truncateError } from './runFormat';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import type { WorkflowRun } from './types';

const props = withDefaults(
  defineProps<{
    run: WorkflowRun;
    /** Global feed: render the parent workflow's {icon, name} as a leading line. */
    showWorkflow?: boolean;
  }>(),
  { showWorkflow: false },
);

const emit = defineEmits<{ (e: 'open', run: WorkflowRun): void }>();

const { t } = useI18n();

// Parent-workflow identity (global feed only). Absent on the per-workflow feed.
const workflow = computed(() => props.run.workflow ?? null);
const workflowIcon = computed<IconName>(() => (workflow.value?.icon as IconName) || 'workflow');

// State badge: variant from state_tone, icon from the fixed map, label from FE
// i18n (fallback to the server label). `failed` renders solid for emphasis.
const stateVariant = computed(() => toneToVariant(props.run.state_tone));
const stateTone = computed<'solid' | 'subtle'>(() =>
  props.run.state === 'failed' ? 'solid' : 'subtle',
);
const stateLabel = computed(() =>
  t(`workflows.runs.state.${props.run.state}`, props.run.state_label),
);

const startedText = computed(() =>
  formatTimestamp(props.run.started_at ?? props.run.created_at),
);
const durationText = computed(() => formatDuration(props.run.duration_seconds));
const errorPreview = computed(() => truncateError(props.run.error));
const stepsCount = computed(() => props.run.steps_count ?? 0);

function onOpen(): void {
  emit('open', props.run);
}
</script>

<template>
  <Surface bg="card" border radius="md" class="w-full">
    <button
      type="button"
      class="flex w-full flex-col gap-next-2 p-next-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
      :aria-label="t('workflows.runs.openRun', '', { state: stateLabel })"
      @click="onOpen"
    >
      <!-- Global feed: parent-workflow identity line (icon + name). Degrades to
           nothing on the per-workflow feed (no `workflow` on the row). -->
      <div
        v-if="showWorkflow && workflow"
        class="flex min-w-0 items-center gap-next-1_5 text-next-sm font-next-medium text-next-fg"
      >
        <Icon :name="workflowIcon" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        <span class="min-w-0 truncate">{{ workflow.name }}</span>
      </div>

      <div class="flex flex-wrap items-center gap-next-2">
        <!-- State (icon + label, never color-only). -->
        <Badge
          :variant="stateVariant"
          :tone="stateTone"
          size="sm"
          :icon="runStateIcon(run.state)"
          class="shrink-0"
        >
          {{ stateLabel }}
        </Badge>

        <!-- Source (the relabelled origin — B3 collapsed origin + trigger_type into
             this single badge, since they were redundant for this module). -->
        <Badge
          variant="neutral"
          tone="subtle"
          size="sm"
          :icon="originIcon(run.origin)"
          class="shrink-0"
        >
          {{ originLabel(run.origin, t) }}
        </Badge>

        <!-- Nested-run badge (a run spawned by another run). -->
        <Badge
          v-if="run.depth > 0"
          variant="neutral"
          tone="subtle"
          size="sm"
          icon="git-branch"
          class="shrink-0"
        >
          {{ t('workflows.runs.nestedBadge', '', { depth: run.depth }) }}
        </Badge>

        <span class="flex-1" />

        <Icon
          name="chevron-right"
          class="shrink-0 text-next-muted-foreground"
          aria-hidden="true"
        />
      </div>

      <!-- Meta line: steps · started · duration. -->
      <div class="flex flex-wrap items-center gap-next-3 text-next-xs text-next-muted-foreground">
        <span class="inline-flex items-center gap-next-1">
          <Icon name="list-checks" class="shrink-0" aria-hidden="true" />
          {{ t('workflows.runs.stepsCount', '', { count: stepsCount }) }}
        </span>
        <span v-if="startedText" class="inline-flex items-center gap-next-1">
          <Icon name="clock" class="shrink-0" aria-hidden="true" />
          {{ startedText }}
        </span>
        <span class="inline-flex items-center gap-next-1">
          {{ durationText ? t('workflows.runs.duration', '', { value: durationText }) : '—' }}
        </span>
      </div>

      <!-- Error preview (failed runs) — one line, danger. -->
      <p
        v-if="errorPreview"
        class="min-w-0 truncate text-next-xs text-next-danger"
        :title="run.error ?? undefined"
      >
        {{ errorPreview }}
      </p>
    </button>
  </Surface>
</template>
