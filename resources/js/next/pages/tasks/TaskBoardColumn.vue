<script setup lang="ts">
// TaskBoardColumn — one status column in the Tasks Board view (next frontend).
//
// A column header (localized status label + a total-count badge) over a
// scrollable body that hosts a TaskList for this status. The body is the
// IntersectionObserver root, so each column infinitely scrolls independently.
import { computed, ref } from 'vue';
import TaskList from './TaskList.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Badge from '../../ui/primitives/Badge.vue';
import { useTasksStore } from '../../app/stores/tasks';
import { useI18n } from '../../app/i18n';
import {
  statusDescriptor,
  type TaskFilters,
  type TaskListItem,
  type TaskStatus,
} from './types';

const props = defineProps<{
  status: TaskStatus;
  filters: TaskFilters;
  hasActiveFilters?: boolean;
}>();

const emit = defineEmits<{ (e: 'select', task: TaskListItem): void }>();

const { t } = useI18n();
const store = useTasksStore();

const bodyRef = ref<HTMLElement | null>(null);
const scrollRoot = computed(() => bodyRef.value);

const total = computed(() => store.totalFor(props.status));

const statusMap = computed(() => ({
  [props.status]: statusDescriptor(props.status, t(`tasks.statuses.${props.status}`)),
}));
</script>

<template>
  <section
    class="flex min-h-0 w-full flex-col rounded-next-lg border border-next-border bg-next-muted/40"
    :aria-label="t(`tasks.statuses.${status}`)"
  >
    <!-- Column header: status label + total count badge. A solid surface + a
         stronger bottom border make the header stand out from the tinted body. -->
    <header
      class="flex items-center justify-between gap-next-2 rounded-t-next-lg border-b-2 border-next-border bg-next-card px-next-3 py-next-3"
    >
      <StatusBadge :status="status" :status-map="statusMap" size="md" />
      <Badge v-if="total !== null" variant="neutral" tone="subtle" size="sm">
        {{ total }}
      </Badge>
    </header>

    <!-- Scrollable body: this element is the infinite-scroll root. -->
    <div ref="bodyRef" class="min-h-0 flex-1 overflow-y-auto p-next-3">
      <TaskList
        :status="status"
        :filters="filters"
        :has-active-filters="hasActiveFilters"
        :scroll-root="scrollRoot"
        :skeleton-count="3"
        compact-empty
        @select="emit('select', $event)"
      />
    </div>
  </section>
</template>
