<script setup lang="ts">
// TaskCard — a single task row/card for the "next" Tasks BROWSE experience.
//
// Built on the design-system Card (interactive whole-card action via the
// stretched-link pattern → emits `select`). Shows: title (clamped), StatusBadge
// (status + i18n label), priority badge (tone + icon + i18n), assignee Avatar
// (name in aria), labels (Badge list collapsing to "+N" via useChipOverflow),
// deadline with overdue (danger) / at-risk (warning) styling + icon, comments
// count, and an `is_in_approval` indicator.
//
// A `loading` skeleton variant mirrors the card geometry (header line + meta row)
// per the project skeleton rule — used to fill columns/lists while a page loads.
//
// All strings go through `t()`; no hardcoded display text. Status/priority labels
// are i18n keys, never the backend Polish strings.
import { computed, ref, watch } from 'vue';
import Card from '../../ui/layout/Card.vue';
import Badge from '../../ui/primitives/Badge.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { useChipOverflow } from '../../app/composables/useChipOverflow';
import { useI18n } from '../../app/i18n';
import {
  priorityMeta,
  statusDescriptor,
  type TaskListItem,
  type TaskStatus,
} from './types';

const props = withDefaults(
  defineProps<{
    /** The task to render. Omit when `loading`. */
    task?: TaskListItem;
    /** The status bucket this card belongs to (drives the StatusBadge). */
    status?: TaskStatus;
    /** Render the geometry-matching skeleton instead of real content. */
    loading?: boolean;
  }>(),
  { loading: false },
);

const emit = defineEmits<{ (e: 'select', task: TaskListItem): void }>();

const { t } = useI18n();

// --- Priority + status descriptors ---------------------------------------
const priority = computed(() =>
  props.task ? priorityMeta(props.task.priority) : null,
);
const priorityLabel = computed(() =>
  props.task ? t(priorityMeta(props.task.priority).i18nKey) : '',
);

// Build a one-entry StatusBadge map for this card's status, localized.
const statusMap = computed(() => {
  if (!props.status) return {};
  return {
    [props.status]: statusDescriptor(props.status, t(`tasks.statuses.${props.status}`)),
  };
});

// --- Deadline styling -----------------------------------------------------
const deadlineTone = computed(() => {
  if (!props.task?.deadline) return 'muted';
  if (props.task.is_overdue) return 'danger';
  if (props.task.is_at_risk) return 'warning';
  return 'muted';
});
const deadlineClass = computed(() => {
  switch (deadlineTone.value) {
    case 'danger':
      return 'text-next-danger';
    case 'warning':
      return 'text-next-warning';
    default:
      return 'text-next-muted-foreground';
  }
});
const deadlineStatusLabel = computed(() => {
  if (props.task?.is_overdue) return t('tasks.deadline.overdue');
  if (props.task?.is_at_risk) return t('tasks.deadline.atRisk');
  return '';
});

const commentsCount = computed(() => props.task?.comments ?? 0);

// --- Labels overflow ("+N") ----------------------------------------------
const labels = computed(() => props.task?.labels ?? []);
const labelTrackRef = ref<HTMLElement | null>(null);
const labelMeasureRef = ref<HTMLElement | null>(null);

const { visibleCount, hiddenCount, recompute } = useChipOverflow({
  trackRef: labelTrackRef,
  measureRef: labelMeasureRef,
  total: () => labels.value.length,
});

watch(labels, () => recompute(), { deep: true });

const visibleLabels = computed(() => labels.value.slice(0, visibleCount.value));

function labelColorStyle(color?: string | null): Record<string, string> | undefined {
  if (!color) return undefined;
  // Custom label colors are author-defined; tint the chip with a subtle wash so
  // it still reads in light + dark (the token surfaces can't express arbitrary
  // colors). A border carries the hue so it's never color-only-on-fill.
  return {
    backgroundColor: `color-mix(in srgb, ${color} 18%, transparent)`,
    color: 'var(--color-next-fg)',
    boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${color} 45%, transparent)`,
  };
}

function onSelect(): void {
  if (props.task) emit('select', props.task);
}
</script>

<template>
  <!-- Loading skeleton: mirrors the real card's geometry (badges row, title,
       meta footer) — several of these fill a column/list while a page loads. -->
  <div
    v-if="loading || !task"
    class="next-card flex flex-col gap-next-3 rounded-next-lg border border-next-border bg-next-card p-next-4 shadow-next-xs"
    role="status"
    :aria-label="t('common.loading', 'Loading…')"
  >
    <div class="flex items-center gap-next-2" aria-hidden="true">
      <Skeleton variant="rect" width="5rem" height="1.25rem" radius="full" />
      <Skeleton variant="rect" width="4rem" height="1.25rem" radius="full" />
    </div>
    <Skeleton variant="text" width="80%" />
    <Skeleton variant="text" width="55%" />
    <div class="flex items-center justify-between" aria-hidden="true">
      <Skeleton variant="circle" diameter="1.75rem" />
      <Skeleton variant="text" width="3rem" />
    </div>
  </div>

  <Card
    v-else
    as="button"
    variant="interactive"
    :action-label="t('tasks.open', 'Open task: {title}', { title: task.title })"
    @activate="onSelect"
  >
    <template #header>
      <div class="flex flex-col gap-next-2">
        <!-- Badges row: status + priority + approval indicator. -->
        <div class="flex flex-wrap items-center gap-next-2">
          <StatusBadge
            v-if="status"
            :status="status"
            :status-map="statusMap"
            size="sm"
          />
          <Badge
            v-if="priority"
            :variant="priority.tone"
            tone="subtle"
            size="sm"
            :icon="priority.icon"
            :title="t('tasks.priorityLabel', 'Priority: {label}', { label: priorityLabel })"
          >
            {{ priorityLabel }}
          </Badge>
          <Badge
            v-if="task.is_in_approval"
            variant="info"
            tone="subtle"
            size="sm"
            icon="lock"
          >
            {{ t('tasks.inApproval', 'In approval') }}
          </Badge>
        </div>

        <!-- Title (clamped to two lines). -->
        <h3 class="line-clamp-2 text-next-sm font-next-semibold text-next-fg">
          {{ task.title }}
        </h3>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center gap-next-3">
        <!-- Assignee avatar (name available to AT via aria-label). -->
        <Avatar
          :name="task.assigned?.name"
          :src="task.assigned?.avatar ?? undefined"
          :alt="t('tasks.assignedTo', 'Assigned to {name}', { name: task.assigned?.name ?? '' })"
          size="xs"
        />

        <!-- Labels: collapse overflow into a shared +N pill. The hidden
             measuring row holds every chip at natural width for the fit math. -->
        <div
          v-if="labels.length"
          ref="labelTrackRef"
          class="relative flex min-w-0 flex-1 items-center gap-next-1 overflow-hidden"
        >
          <Badge
            v-for="label in visibleLabels"
            :key="label.id"
            variant="neutral"
            tone="subtle"
            size="sm"
            truncate
            :style="labelColorStyle(label.color)"
            class="shrink-0"
          >
            {{ label.name }}
          </Badge>
          <Badge
            v-if="hiddenCount > 0"
            variant="neutral"
            tone="subtle"
            size="sm"
            class="shrink-0"
            :title="t('tasks.moreLabels', '{count} more labels', { count: hiddenCount })"
          >
            +{{ hiddenCount }}
          </Badge>

          <!-- Hidden measuring row. -->
          <div
            ref="labelMeasureRef"
            aria-hidden="true"
            class="pointer-events-none invisible absolute left-0 top-0 flex items-center gap-next-1"
          >
            <Badge
              v-for="label in labels"
              :key="label.id"
              data-measure-chip
              variant="neutral"
              tone="subtle"
              size="sm"
              truncate
              class="shrink-0"
            >
              {{ label.name }}
            </Badge>
          </div>
        </div>
        <div v-else class="min-w-0 flex-1" />

        <!-- Right cluster: comments + deadline. -->
        <div class="flex shrink-0 items-center gap-next-3 text-next-xs">
          <span
            v-if="commentsCount > 0"
            class="flex items-center gap-next-1 text-next-muted-foreground"
            :title="t('tasks.comments', '{count} comments', { count: commentsCount })"
          >
            <Icon name="mail" aria-hidden="true" />
            <span>{{ commentsCount }}</span>
          </span>

          <span
            v-if="task.deadline"
            class="flex items-center gap-next-1 font-next-medium"
            :class="deadlineClass"
          >
            <Icon
              :name="task.is_overdue ? 'alert-triangle' : 'calendar'"
              aria-hidden="true"
            />
            <span>{{ task.deadline }}</span>
            <span v-if="deadlineStatusLabel" class="sr-only">
              {{ deadlineStatusLabel }}
            </span>
          </span>
        </div>
      </div>
    </template>
  </Card>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
