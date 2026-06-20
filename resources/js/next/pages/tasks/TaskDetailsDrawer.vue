<script setup lang="ts">
// TaskDetailsDrawer — the FUNCTIONAL task workspace for the next frontend.
//
// Opens from `?task=<id>` as a floating right-side Drawer. On open it fetches the
// FULL TaskResource (skeleton while loading, error + retry on failure), then lets
// the user WORK the task inline rather than just read it:
//
//   • Header: inline-editable title, a PROMINENT status switcher (a DropdownMenu
//     whose trigger shows the CURRENT status as a StatusBadge and whose options
//     are the allowed next statuses → `changeStatus`), the priority chip + an
//     approval indicator.
//   • Editable metadata: assignee (UserSelect, single), priority (SegmentedControl),
//     deadline (DatePicker), labels (LabelSelect) — each change persists via
//     `store.updateTask(id, FULL payload)` (optimistic UI + success/danger toast +
//     422 field-error surfacing).
//   • Description: a MarkdownViewer with an inline "Edit" affordance that swaps in
//     the MarkdownEditor; save → updateTask, cancel → revert.
//   • Attachments (read-only list).
//   • Tabs: Comments (works) + Activity (changelog Timeline).
//   • Footer lifecycle: delete → trash (confirm); when trashed: restore /
//     force-delete.
//
// The full write payload mirrors StoreTasksRequest exactly (title, description as a
// JSON-doc string, priority, deadline yyyy-mm-dd, assigned_id, labels[]) — built
// once in `buildPayload()` so every inline edit sends a complete, valid body and
// preserves form/pipeline/attachment links. The store reconciles `itemsByStatus`
// + `detail` on updateTask/changeStatus, so the board stays in sync without a
// refetch.
//
// All design-system components; no legacy imports; namespaced tokens; light+dark;
// i18n + a11y throughout (labelled controls, status announced live).
import { computed, reactive, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Tabs from '../../ui/navigation/Tabs.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import DatePicker from '../../ui/forms/DatePicker.vue';
import MarkdownViewer from '../../ui/editor/MarkdownViewer.vue';
import MarkdownEditor from '../../ui/editor/MarkdownEditor.vue';
import Alert from '../../ui/feedback/Alert.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Timeline, { type TimelineEntry } from '../../ui/patterns/Timeline.vue';
import TaskComments from './TaskComments.vue';
import TaskAttachmentsField from './TaskAttachmentsField.vue';
import { useTasksStore } from '../../app/stores/tasks';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useDebounce } from '../../app/composables/useDebounce';
import { useOutsideClick } from '../../app/composables/useOutsideClick';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useI18n } from '../../app/i18n';
import type { IconName } from '../../ui/primitives/icons';
import {
  ALL_PRIORITIES,
  priorityMeta,
  statusDescriptor,
  type TaskAttachment,
  type TaskDetail,
  type TaskPriority,
  type TaskStatus,
  type TaskWritePayload,
} from './types';
import {
  markdownToTaskDescriptionPayload,
  taskDescriptionToMarkdown,
} from './description';

const props = defineProps<{
  /** The task id to show (from `?task=<id>`); null when closed. */
  taskId: string | number | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
  /**
   * Request the create/edit Modal for the current task. KEPT for compatibility,
   * but the panel now edits inline; the Modal is reserved for CREATE.
   */
  (e: 'edit', id: string | number): void;
  /** The drawer was closed (clear the `?task` query). */
  (e: 'close'): void;
}>();

const { t, currentLocale } = useI18n();
const store = useTasksStore();
const toast = useToast();
const confirm = useConfirm();

const task = computed(() => store.detail);
const loading = computed(() => store.detailLoading);
const error = computed(() => store.detailError);

// In-flight flags for the lifecycle actions.
const changingStatus = ref(false);
const deleting = ref(false);
const restoring = ref(false);
const forceDeleting = ref(false);

// --- Responsive pane switching -------------------------------------------
// On wide screens the workspace shows three columns at once (properties /
// content / comments). Below `next-lg` they collapse into a single column whose
// visible region is driven by this SegmentedControl-backed toggle.
type DetailPane = 'content' | 'comments' | 'properties';
const mobilePane = ref<DetailPane>('content');
const paneOptions = computed<SegmentOption<DetailPane>[]>(() => [
  { value: 'content', label: t('tasks.detail.paneContent') },
  { value: 'comments', label: t('tasks.detail.paneComments') },
  { value: 'properties', label: t('tasks.detail.paneProperties') },
]);

// --- Load on open / id change --------------------------------------------
watch(
  () => [open.value, props.taskId] as const,
  ([isOpen, id]) => {
    if (isOpen && id != null) {
      void store.fetchTask(id);
      void store.fetchComments(id, { reset: true });
      void store.fetchChangelog(id);
    }
  },
  { immediate: true },
);

function retry(): void {
  if (props.taskId != null) void store.fetchTask(props.taskId);
}

function onClose(): void {
  store.clearDetail();
  emit('close');
}

// --- Status descriptors ---------------------------------------------------
const statusMap = computed(() => {
  if (!task.value) return {};
  return {
    [task.value.status]: statusDescriptor(
      task.value.status,
      t(`tasks.statuses.${task.value.status}`),
    ),
  };
});
const priority = computed(() => (task.value ? priorityMeta(task.value.priority) : null));
const priorityLabel = computed(() =>
  task.value ? t(priorityMeta(task.value.priority).i18nKey) : '',
);

const isTrashed = computed(() => task.value?.status === 'trash');
const isLocked = computed(() => !!task.value?.is_in_approval);

// Status transitions modelled as SEMANTIC actions (mirrors the legacy task
// dialog): the offered next steps depend on the current status, each with its
// own label/variant/icon. Shown in the footer alongside the lifecycle actions.
interface StatusAction {
  label: string;
  status: TaskStatus;
  variant: 'primary' | 'secondary';
  icon: IconName;
}
const statusActions = computed<StatusAction[]>(() => {
  const tk = task.value;
  if (!tk || isTrashed.value || isLocked.value) return [];
  const actions: StatusAction[] = [];
  switch (tk.status) {
    case 'to_do':
      actions.push({ label: 'startTask', status: 'in_progress', variant: 'primary', icon: 'arrow-right' });
      break;
    case 'in_progress':
      actions.push({ label: 'sendToTest', status: 'in_test', variant: 'secondary', icon: 'flag' });
      actions.push({ label: 'complete', status: 'done', variant: 'primary', icon: 'check' });
      break;
    case 'in_test':
      actions.push({ label: 'backToProgress', status: 'in_progress', variant: 'secondary', icon: 'undo' });
      actions.push({ label: 'complete', status: 'done', variant: 'primary', icon: 'check' });
      break;
    case 'done':
      actions.push({ label: 'backToProgress', status: 'in_progress', variant: 'secondary', icon: 'undo' });
      break;
    default:
      break;
  }
  return actions;
});

// --- Editable field models (mirror the FULL StoreTasksRequest payload) ----
// A local mirror so a control can show the new value optimistically; reverted
// from `task` on every (re)load, and re-synced after a save reconciles `detail`.
interface EditState {
  title: string;
  description: string;
  priority: TaskPriority;
  deadline: string | null;
  assigned_id: string | null;
  labels: string[];
  /** Temp file ids of NEW uploads (existing attachments live in attachmentSeed). */
  attachments: string[];
}
const editState = reactive<EditState>({
  title: '',
  description: '',
  priority: 'medium',
  deadline: null,
  assigned_id: null,
  labels: [],
  attachments: [],
});

// Seeds so already-selected assignee/labels render before async pages load.
const assigneeSeed = ref<
  Array<{ id: string; name: string; email?: string | null; avatar?: string | null }>
>([]);
const labelSeed = ref<
  Array<{ id: string; name: string; color?: string | null; icon?: string | null }>
>([]);
const attachmentSeed = ref<TaskAttachment[]>([]);

// Per-field saving + 422 error state, keyed by the backend field name.
const savingField = ref<keyof EditState | null>(null);
const fieldErrors = ref<Partial<Record<keyof EditState | 'form', string>>>({});

function syncEditFromTask(t0: TaskDetail | null): void {
  fieldErrors.value = {};
  if (!t0) return;
  editState.title = t0.title ?? '';
  editState.description = taskDescriptionToMarkdown(t0.description);
  editState.priority = t0.priority ?? 'medium';
  editState.deadline = t0.deadline ?? null;
  editState.assigned_id = t0.assigned ? String(t0.assigned.id) : null;
  editState.labels = (t0.labels ?? []).map((l) => String(l.id));
  assigneeSeed.value = t0.assigned
    ? [
        {
          id: String(t0.assigned.id),
          name: t0.assigned.name,
          email: t0.assigned.email ?? null,
          avatar: t0.assigned.avatar ?? null,
        },
      ]
    : [];
  labelSeed.value = (t0.labels ?? []).map((l) => ({
    id: String(l.id),
    name: l.name,
    color: l.color ?? null,
    icon: l.icon ?? null,
  }));
  editState.attachments = [];
  attachmentSeed.value = t0.attachments ?? [];
}

watch(task, (t0) => syncEditFromTask(t0), { immediate: true });

// --- Title inline edit ----------------------------------------------------
const editingTitle = ref(false);
function startTitleEdit(): void {
  if (isTrashed.value) return;
  editState.title = task.value?.title ?? '';
  editingTitle.value = true;
}
function cancelTitleEdit(): void {
  editingTitle.value = false;
  editState.title = task.value?.title ?? '';
}
async function commitTitle(): Promise<void> {
  if (!task.value) return;
  const next = editState.title.trim();
  if (!next || next === task.value.title) {
    cancelTitleEdit();
    return;
  }
  await persist('title');
  editingTitle.value = false;
}

// --- Description inline edit ----------------------------------------------
const editingDescription = ref(false);
const descriptionMarkdown = computed(() =>
  taskDescriptionToMarkdown(task.value?.description),
);
function startDescriptionEdit(): void {
  if (isTrashed.value) return;
  editState.description = descriptionMarkdown.value;
  editingDescription.value = true;
}
function cancelDescriptionEdit(): void {
  editingDescription.value = false;
  editState.description = descriptionMarkdown.value;
}
async function commitDescription(): Promise<void> {
  await persist('description');
  editingDescription.value = false;
}

// Clicking away from an open inline editor commits it (exits edit mode). The
// edit containers are excluded from the "outside" set, so Save/Cancel still work.
const titleEditRef = ref<HTMLElement | null>(null);
const descEditRef = ref<HTMLElement | null>(null);
useOutsideClick(
  titleEditRef,
  () => {
    if (editingTitle.value) void commitTitle();
  },
  editingTitle,
);
useOutsideClick(
  descEditRef,
  () => {
    if (editingDescription.value) void commitDescription();
  },
  editingDescription,
);

// --- Priority options -----------------------------------------------------
const priorityOptions = computed<SegmentOption<TaskPriority>[]>(() =>
  ALL_PRIORITIES.map((p) => ({
    value: p,
    label: t(`tasks.priorities.${p}`),
    icon: priorityMeta(p).icon,
  })),
);

// --- Persist a single field via the FULL update payload -------------------
function buildPayload(): TaskWritePayload {
  const payload: TaskWritePayload = {
    title: editState.title.trim(),
    description: markdownToTaskDescriptionPayload(editState.description),
    priority: editState.priority,
    deadline: editState.deadline ?? null,
    assigned_id: editState.assigned_id ?? '',
    labels: editState.labels,
    // Only NEW uploads are sent; the backend attaches them additively, so
    // existing attachments are preserved and removed only via the dedicated
    // delete endpoint.
    attachments: editState.attachments,
  };
  // Preserve form/pipeline links (no pickers here) so an inline edit never
  // wipes them.
  if (task.value) {
    if (task.value.form_id) payload.form_id = task.value.form_id;
    if (task.value.approval_pipeline_id)
      payload.approval_pipeline_id = task.value.approval_pipeline_id;
  }
  return payload;
}

async function persist(field: keyof EditState): Promise<void> {
  if (!task.value) return;
  savingField.value = field;
  fieldErrors.value = { ...fieldErrors.value, [field]: undefined, form: undefined };
  try {
    await store.updateTask(task.value.id, buildPayload());
    toast.success(t('tasks.toasts.updated'));
  } catch (err: unknown) {
    const res = (err as {
      response?: {
        status?: number;
        data?: { message?: string; errors?: Record<string, string[]> };
      };
    }).response;
    if (res?.status === 422 && res.data?.errors) {
      const mapped: Partial<Record<keyof EditState | 'form', string>> = {};
      Object.entries(res.data.errors).forEach(([key, messages]) => {
        mapped[key as keyof EditState] = messages?.[0] ?? '';
      });
      mapped.form = res.data.message ?? t('tasks.form.errorDescription');
      fieldErrors.value = mapped;
    } else {
      fieldErrors.value = { form: res?.data?.message ?? t('tasks.toasts.saveError') };
    }
    toast.danger(t('tasks.toasts.saveError'));
    // Revert the optimistic local value to the authoritative server value.
    syncEditFromTask(task.value);
  } finally {
    savingField.value = null;
  }
}

// Inline metadata edits (assignee / deadline / priority / labels) are debounced
// so rapid changes coalesce into a single request instead of firing on every
// interaction. Title/description still commit explicitly (Save / click-away).
const debouncedPersist = useDebounce((field: keyof EditState) => {
  void persist(field);
}, 500);

function selectPriority(value: TaskPriority): void {
  if (isTrashed.value) return;
  editState.priority = value;
  debouncedPersist('priority');
}

// New uploads land in editState.attachments as their temp ids resolve; persist
// (debounced) so several quick uploads coalesce into one additive update.
function onAttachmentsChanged(): void {
  if (isTrashed.value) return;
  debouncedPersist('attachments');
}

// Removing an EXISTING attachment hits the dedicated delete endpoint; the store
// refreshes the task, which re-seeds the field via the `task` watcher.
async function removeExistingAttachment(fileId: string): Promise<void> {
  if (!task.value) return;
  try {
    await store.removeAttachment(task.value.id, fileId);
    toast.success(t('tasks.toasts.updated'));
  } catch {
    toast.danger(t('attachments.removeError', 'Could not remove the attachment.'));
  }
}

// --- Status change --------------------------------------------------------
async function onChangeStatus(status: TaskStatus): Promise<void> {
  if (!task.value || status === task.value.status) return;
  changingStatus.value = true;
  try {
    await store.changeStatus(task.value.id, status);
    toast.success(t('tasks.toasts.statusChanged'));
  } catch (err: unknown) {
    toast.danger(extractMessage(err, t('tasks.toasts.statusError')));
  } finally {
    changingStatus.value = false;
  }
}

// --- Lifecycle ------------------------------------------------------------
async function onDelete(): Promise<void> {
  if (!task.value) return;
  const id = task.value.id;
  const ok = await confirm({
    title: t('tasks.detail.deleteTitle'),
    message: t('tasks.detail.deleteConfirm'),
    confirmLabel: t('tasks.detail.delete'),
    cancelLabel: t('tasks.form.cancel'),
    variant: 'danger',
    onConfirm: async () => {
      deleting.value = true;
      try {
        await store.deleteTask(id);
      } finally {
        deleting.value = false;
      }
    },
  });
  if (ok) {
    toast.success(t('tasks.toasts.deleted'));
    open.value = false;
  }
}

async function onForceDelete(): Promise<void> {
  if (!task.value) return;
  const id = task.value.id;
  const ok = await confirm({
    title: t('tasks.detail.forceDeleteTitle'),
    message: t('tasks.detail.forceDeleteConfirm'),
    confirmLabel: t('tasks.detail.forceDelete'),
    cancelLabel: t('tasks.form.cancel'),
    variant: 'danger',
    onConfirm: async () => {
      forceDeleting.value = true;
      try {
        await store.forceDeleteTask(id);
      } finally {
        forceDeleting.value = false;
      }
    },
  });
  if (ok) {
    toast.success(t('tasks.toasts.forceDeleted'));
    open.value = false;
  }
}

async function onRestore(): Promise<void> {
  if (!task.value) return;
  restoring.value = true;
  try {
    await store.restoreTask(task.value.id);
    toast.success(t('tasks.toasts.restored'));
  } catch (err: unknown) {
    toast.danger(extractMessage(err, t('tasks.toasts.error')));
  } finally {
    restoring.value = false;
  }
}

function extractMessage(err: unknown, fallback: string): string {
  const res = (err as {
    response?: { data?: { message?: string; errors?: Record<string, string[]> } };
  })?.response;
  const firstFieldError = res?.data?.errors
    ? Object.values(res.data.errors)[0]?.[0]
    : undefined;
  return firstFieldError ?? res?.data?.message ?? fallback;
}

// Deadline badge shown directly under the title (overdue / at-risk only — the
// most important state must be visible at a glance).
const deadlineBadge = computed<{ variant: 'danger' | 'warning'; icon: 'alert-triangle' | 'alert-circle'; label: string } | null>(() => {
  if (task.value?.is_overdue) {
    return { variant: 'danger', icon: 'alert-triangle', label: t('tasks.deadline.overdue') };
  }
  if (task.value?.is_at_risk) {
    return { variant: 'warning', icon: 'alert-circle', label: t('tasks.deadline.atRisk') };
  }
  return null;
});

// --- Changelog → Timeline -------------------------------------------------
function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(currentLocale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(d);
}

const changelogEntries = computed<TimelineEntry[]>(() =>
  store.changelog.map((entry) => ({
    id: entry.id,
    title: entry.event_description,
    description: entry.causer?.name ?? t('tasks.changelog.system'),
    time: formatDateTime(entry.created_at),
    datetime: entry.created_at,
    icon: 'clock',
    tone: 'neutral',
  })),
);

// Auto-load the next changelog page when the sentinel scrolls into view.
const activityScrollRef = ref<HTMLElement | null>(null);
const { sentinelRef: changelogSentinelRef } = useInfiniteScroll({
  root: activityScrollRef,
  onLoadMore: () => {
    if (props.taskId != null) void store.loadMoreChangelog(props.taskId);
  },
  canLoadMore: () =>
    store.changelogHasMore &&
    !store.changelogLoading &&
    !store.changelogLoadingMore &&
    !store.changelogError,
});
</script>

<template>
  <Drawer
    v-model:open="open"
    side="right"
    size="cover"
    floating
    :scroll-body="false"
    :aria-label="t('tasks.detail.title')"
    @close="onClose"
  >
    <template #title>
      <span v-if="loading" class="block">
        <Skeleton variant="text" width="60%" />
      </span>
      <span v-else-if="task" class="block">
        <!-- Inline-editable title -->
        <span v-if="editingTitle" ref="titleEditRef" class="flex items-center gap-next-2">
          <TextInput
            v-model="editState.title"
            class="flex-1"
            :aria-label="t('tasks.form.titleLabel')"
            @keydown.enter.prevent="commitTitle"
            @keydown.esc.prevent="cancelTitleEdit"
          />
          <Button
            size="sm"
            :loading="savingField === 'title'"
            :aria-label="t('tasks.form.save')"
            @click="commitTitle"
          >
            {{ t('tasks.form.save') }}
          </Button>
          <Button
            size="sm"
            variant="ghost"
            :aria-label="t('tasks.form.cancel')"
            @click="cancelTitleEdit"
          >
            {{ t('tasks.form.cancel') }}
          </Button>
        </span>
        <button
          v-else
          type="button"
          class="flex w-full items-start rounded-next-sm text-left text-next-2xl font-next-semibold text-next-fg transition-colors hover:text-next-primary disabled:cursor-default disabled:hover:text-next-fg"
          :class="isTrashed ? '' : 'cursor-text'"
          :disabled="isTrashed"
          :aria-label="t('tasks.detail.editTitle')"
          @click="startTitleEdit"
        >
          <span class="min-w-0 flex-1 break-words">{{ task.title }}</span>
        </button>

        <!-- Key state at a glance, in importance order: deadline alert →
             status → priority → in-approval lock → labels. -->
        <div class="mt-next-2 flex flex-wrap items-center gap-next-2">
          <Badge
            v-if="deadlineBadge"
            :variant="deadlineBadge.variant"
            tone="subtle"
            :icon="deadlineBadge.icon"
          >
            {{ deadlineBadge.label }}
          </Badge>
          <StatusBadge :status="task.status" :status-map="statusMap" size="sm" />
          <Badge
            v-if="priority"
            :variant="priority.tone"
            tone="subtle"
            :icon="priority.icon"
          >
            {{ priorityLabel }}
          </Badge>
          <Badge v-if="task.is_in_approval" variant="info" tone="subtle" icon="lock">
            {{ t('tasks.detail.inApproval') }}
          </Badge>
          <Badge
            v-for="label in task.labels ?? []"
            :key="label.id"
            variant="neutral"
            tone="subtle"
          >
            {{ label.name }}
          </Badge>
        </div>
      </span>
      <span v-else>{{ t('tasks.detail.title') }}</span>
    </template>

    <!-- LOADING: skeleton mirroring the real layout. -->
    <div v-if="loading" class="flex flex-col gap-next-5" aria-hidden="true">
      <div class="flex flex-wrap items-center gap-next-2">
        <Skeleton variant="rect" width="8rem" height="2rem" radius="full" />
        <Skeleton variant="rect" width="5rem" height="1.5rem" radius="full" />
      </div>
      <div class="flex flex-col gap-next-3">
        <Skeleton v-for="n in 4" :key="n" variant="text" :width="`${70 - n * 8}%`" />
      </div>
      <Skeleton variant="rect" width="100%" height="6rem" radius="md" />
    </div>

    <!-- ERROR -->
    <EmptyState
      v-else-if="error"
      variant="error"
      :title="t('tasks.detail.loadErrorTitle')"
      :description="t('tasks.detail.loadErrorDescription')"
    >
      <template #action>
        <Button variant="outline" leading-icon="redo" @click="retry">
          {{ t('tasks.detail.retry') }}
        </Button>
      </template>
    </EmptyState>

    <!-- SUCCESS -->
    <div v-else-if="task" class="flex h-full min-h-0 flex-col gap-next-4">
      <!-- A page-level save error (e.g. 422 message) surfaced once. -->
      <Alert v-if="fieldErrors.form" variant="danger" size="sm" class="shrink-0" :title="t('tasks.form.errorTitle')">
        {{ fieldErrors.form }}
      </Alert>

      <!-- Approval lock hint -->
      <Alert v-if="task.is_in_approval" variant="info" size="sm" class="shrink-0">
        {{ t('tasks.detail.inApprovalHint') }}
      </Alert>

      <!-- Narrow-screen pane switcher (hidden once the 3 panes fit side by side). -->
      <div class="shrink-0 next-lg:hidden">
        <SegmentedControl
          v-model="mobilePane"
          :options="paneOptions"
          equal-width
          :aria-label="t('tasks.detail.title')"
        />
      </div>

      <!-- Three-pane workspace: properties | content | comments. -->
      <div class="flex min-h-0 flex-1 flex-col gap-next-4 next-lg:flex-row next-lg:gap-next-6">
        <!-- LEFT RAIL: properties (assignee, priority, deadline, labels, creator, attachments). -->
        <aside
          class="min-h-0 overflow-y-auto next-lg:w-[24rem] next-lg:shrink-0 next-lg:border-r next-lg:border-next-border next-lg:pr-next-6"
          :class="mobilePane === 'properties' ? 'flex flex-1 flex-col' : 'hidden next-lg:flex next-lg:flex-col'"
          :aria-label="t('tasks.detail.paneProperties')"
        >
          <!-- Editable metadata: assignee / priority / deadline / labels. -->
          <section
            class="flex flex-col gap-next-4"
            :aria-label="t('tasks.detail.metadata')"
          >
        <FormField :label="t('tasks.detail.assignee')" :error="fieldErrors.assigned_id">
          <UserSelect
            v-model="editState.assigned_id"
            :seed="assigneeSeed"
            :readonly="isTrashed"
            :aria-invalid="!!fieldErrors.assigned_id"
            :placeholder="t('tasks.form.assigneePlaceholder')"
            :aria-label="t('tasks.detail.assignee')"
            @update:model-value="debouncedPersist('assigned_id')"
          />
        </FormField>

        <FormField :label="t('tasks.detail.deadline')" :error="fieldErrors.deadline">
          <DatePicker
            v-model="editState.deadline"
            :readonly="isTrashed"
            :aria-invalid="!!fieldErrors.deadline"
            :aria-label="t('tasks.detail.deadline')"
            @update:model-value="debouncedPersist('deadline')"
          />
        </FormField>

        <FormField
          :label="t('tasks.detail.priority')"
          :error="fieldErrors.priority"
        >
          <SegmentedControl
            v-model="editState.priority"
            :options="priorityOptions"
            size="sm"
            equal-width
            :disabled="isTrashed"
            :aria-label="t('tasks.detail.priority')"
            @update:model-value="selectPriority"
          />
        </FormField>

        <FormField
          :label="t('tasks.detail.labels')"
          :error="fieldErrors.labels"
        >
          <LabelSelect
            v-model="editState.labels"
            :seed="labelSeed"
            :readonly="isTrashed"
            :placeholder="t('tasks.form.labelsPlaceholder')"
            :aria-label="t('tasks.detail.labels')"
            @update:model-value="debouncedPersist('labels')"
          />
        </FormField>

        <!-- Creator stays read-only (not part of the write contract). -->
        <div class="flex flex-col gap-next-1_5">
          <span class="text-next-sm font-next-medium text-next-fg">
            {{ t('tasks.detail.creator') }}
          </span>
          <span class="flex items-center gap-next-2">
            <Avatar :name="task.creator?.name" size="sm" class="shrink-0" />
            <span class="min-w-0 truncate text-next-sm text-next-fg">{{ task.creator?.name }}</span>
          </span>
        </div>

            <!-- Attachments: existing (removable) + new uploads, or read-only when trashed. -->
            <section :aria-label="t('tasks.detail.attachments')">
              <h3 class="mb-next-2 text-next-sm font-next-semibold text-next-fg">
                {{ t('tasks.detail.attachments') }}
              </h3>
              <TaskAttachmentsField
                v-model="editState.attachments"
                :seed="attachmentSeed"
                :max="5"
                :readonly="isTrashed"
                @update:model-value="onAttachmentsChanged"
                @remove-existing="removeExistingAttachment"
              />
            </section>
          </section>
        </aside>

        <!-- CENTER: description + work tabs. The column is a flex column so the
             tabs fill the remaining height down to the bottom of the modal. -->
        <section
          class="min-w-0 min-h-0 next-lg:flex-1"
          :class="mobilePane === 'content' ? 'flex flex-1 flex-col gap-next-5' : 'hidden next-lg:flex next-lg:flex-1 next-lg:flex-col next-lg:gap-next-5'"
          :aria-label="t('tasks.detail.paneContent')"
        >

      <!-- Description: view ⇄ inline edit. Taller top part of the center column;
           only the description body scrolls (heading + edit button stay fixed). -->
      <section class="flex min-h-0 flex-2 flex-col" :aria-label="t('tasks.detail.description')">
        <div class="mb-next-2 flex shrink-0 items-center gap-next-2">
          <h3 class="text-next-sm font-next-semibold text-next-fg">
            {{ t('tasks.detail.description') }}
          </h3>
          <Button
            v-if="!editingDescription && !isTrashed"
            size="sm"
            variant="ghost"
            leading-icon="pencil"
            @click="startDescriptionEdit"
          >
            {{ t('tasks.detail.edit') }}
          </Button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
          <template v-if="editingDescription">
            <!-- Edit mode: the editor fills the area and scrolls INTERNALLY (its
                 toolbar stays put), while Save/Cancel stay pinned at the bottom. -->
            <div ref="descEditRef" class="flex h-full min-h-0 flex-col gap-next-2">
              <MarkdownEditor
                v-model="editState.description"
                class="next-desc-editor flex min-h-0 flex-1 flex-col"
                max-height="100%"
                :placeholder="t('tasks.form.descriptionPlaceholder')"
                :aria-label="t('tasks.detail.description')"
              />
              <div class="flex shrink-0 items-center gap-next-2">
                <Button
                  size="sm"
                  :loading="savingField === 'description'"
                  @click="commitDescription"
                >
                  {{ t('tasks.form.save') }}
                </Button>
                <Button size="sm" variant="ghost" @click="cancelDescriptionEdit">
                  {{ t('tasks.form.cancel') }}
                </Button>
              </div>
            </div>
          </template>
          <template v-else>
            <MarkdownViewer
              v-if="descriptionMarkdown"
              :source="descriptionMarkdown"
              :aria-label="t('tasks.detail.description')"
            />
            <p v-else class="text-next-sm italic text-next-muted-foreground">
              {{ t('tasks.detail.noDescription') }}
            </p>
          </template>
        </div>
      </section>

          <!-- Work tabs (shorter bottom part): Activity (live) + Form / Checklist / Approval. -->
          <div class="flex min-h-0 flex-3 flex-col">
          <Tabs
            variant="pills"
            fill
            :items="[
              { value: 'activity', label: t('tasks.detail.tabActivity'), icon: 'clock' },
              { value: 'form', label: t('tasks.detail.tabForm'), icon: 'file-text' },
              { value: 'checklist', label: t('tasks.detail.tabChecklist'), icon: 'list-checks' },
              { value: 'approval', label: t('tasks.detail.tabApproval'), icon: 'check-circle' },
            ]"
            :aria-label="t('tasks.detail.paneContent')"
          >
            <template #panel-activity>
              <div ref="activityScrollRef" class="min-h-0 flex-1 overflow-y-auto pt-next-3">
                <Alert v-if="store.changelogError" variant="danger" size="sm">
                  {{ t('tasks.changelog.loadError') }}
                </Alert>
                <template v-else>
                  <Timeline
                    :items="changelogEntries"
                    :loading="store.changelogLoading"
                    compact
                    :aria-label="t('tasks.changelog.title')"
                    :empty-title="t('tasks.changelog.empty')"
                    :empty-description="t('tasks.changelog.emptyDescription')"
                  />
                  <div
                    v-if="store.changelogHasMore && !store.changelogLoading"
                    ref="changelogSentinelRef"
                    class="h-px w-full"
                    aria-hidden="true"
                  />
                  <div
                    v-if="store.changelogLoadingMore"
                    class="flex justify-center py-next-2 text-next-muted-foreground"
                  >
                    <Icon name="loader" class="animate-spin" />
                  </div>
                </template>
              </div>
            </template>
            <template #panel-form>
              <EmptyState
                class="pt-next-3"
                icon="file-text"
                :title="t('tasks.detail.tabForm')"
                :description="t('tasks.detail.comingSoon')"
              />
            </template>
            <template #panel-checklist>
              <EmptyState
                class="pt-next-3"
                icon="list-checks"
                :title="t('tasks.detail.tabChecklist')"
                :description="t('tasks.detail.comingSoon')"
              />
            </template>
            <template #panel-approval>
              <EmptyState
                class="pt-next-3"
                icon="check-circle"
                :title="t('tasks.detail.tabApproval')"
                :description="t('tasks.detail.comingSoon')"
              />
            </template>
          </Tabs>
          </div>
        </section>

        <!-- RIGHT: comments are ALWAYS visible alongside the work content.
             This pane does NOT scroll itself — TaskComments owns its internal
             scroll region (fixed composer on top, scrollable list below). -->
        <aside
          class="min-h-0 next-lg:w-[24rem] next-lg:shrink-0 next-lg:border-l next-lg:border-next-border next-lg:pl-next-6"
          :class="mobilePane === 'comments' ? 'flex flex-1 flex-col' : 'hidden next-lg:flex next-lg:flex-col'"
          :aria-label="t('tasks.detail.tabComments')"
        >
          <h3 class="mb-next-3 shrink-0 text-next-sm font-next-semibold text-next-fg">
            {{ t('tasks.detail.tabComments') }}
          </h3>
          <TaskComments :task-id="task.id" class="min-h-0 flex-1" />
        </aside>
      </div>
    </div>

    <!-- Footer: status transitions (left) + lifecycle actions (right). -->
    <template v-if="task && !loading && !error" #footer>
      <div class="flex w-full items-center justify-between gap-next-2">
        <!-- Status transition actions (semantic, depend on current status). -->
        <div class="flex flex-wrap items-center gap-next-2">
          <Button
            v-for="action in statusActions"
            :key="action.status"
            :variant="action.variant"
            :leading-icon="action.icon"
            :loading="changingStatus"
            :disabled="changingStatus"
            @click="onChangeStatus(action.status)"
          >
            {{ t(`tasks.detail.${action.label}`) }}
          </Button>
        </div>
        <!-- Lifecycle actions. -->
        <div class="flex items-center gap-next-2">
          <template v-if="isTrashed">
            <Button variant="outline" leading-icon="undo" :loading="restoring" @click="onRestore">
              {{ t('tasks.detail.restore') }}
            </Button>
            <Button variant="danger" leading-icon="trash" :loading="forceDeleting" @click="onForceDelete">
              {{ t('tasks.detail.forceDelete') }}
            </Button>
          </template>
          <template v-else>
            <Button variant="danger" leading-icon="trash" :loading="deleting" @click="onDelete">
              {{ t('tasks.detail.delete') }}
            </Button>
          </template>
        </div>
      </div>
    </template>
  </Drawer>
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

/* Description editor in edit mode: the editor shell fills the available height
   and only its content area scrolls, so the toolbar + the Save/Cancel row below
   stay visible without scrolling the whole panel. */
.next-desc-editor :deep(.next-md-shell) {
  display: flex;
  min-height: 0;
  flex: 1 1 0%;
  flex-direction: column;
}
.next-desc-editor :deep(.next-md-content-wrap) {
  min-height: 0;
  max-height: none;
  flex: 1 1 0%;
}
</style>
