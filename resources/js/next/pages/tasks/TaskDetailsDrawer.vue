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
import Skeleton from '../../ui/data/Skeleton.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import DropdownMenuLabel from '../../ui/overlay/DropdownMenuLabel.vue';
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
import { useTasksStore } from '../../app/stores/tasks';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import {
  ALL_PRIORITIES,
  offerableStatuses,
  priorityMeta,
  statusDescriptor,
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

// Allowed next statuses to offer in the switcher (advisory; server is final).
const offerable = computed<TaskStatus[]>(() =>
  task.value ? offerableStatuses(task.value) : [],
);
function statusOptionMap(status: TaskStatus) {
  return { [status]: statusDescriptor(status, t(`tasks.statuses.${status}`)) };
}

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
}
const editState = reactive<EditState>({
  title: '',
  description: '',
  priority: 'medium',
  deadline: null,
  assigned_id: null,
  labels: [],
});

// Seeds so already-selected assignee/labels render before async pages load.
const assigneeSeed = ref<
  Array<{ id: string; name: string; email?: string | null; avatar?: string | null }>
>([]);
const labelSeed = ref<
  Array<{ id: string; name: string; color?: string | null; icon?: string | null }>
>([]);

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

// --- Priority options -----------------------------------------------------
const priorityOptions = computed<SegmentOption<TaskPriority>[]>(() =>
  ALL_PRIORITIES.map((p) => ({ value: p, label: t(`tasks.priorities.${p}`) })),
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
  };
  // Preserve form/pipeline links + current attachments (no pickers here) so an
  // inline edit never wipes them.
  if (task.value) {
    if (task.value.form_id) payload.form_id = task.value.form_id;
    if (task.value.approval_pipeline_id)
      payload.approval_pipeline_id = task.value.approval_pipeline_id;
    payload.attachments = (task.value.attachments ?? []).map((a) => String(a.id));
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

// --- Deadline tone --------------------------------------------------------
const deadlineStatusLabel = computed(() => {
  if (task.value?.is_overdue) return t('tasks.deadline.overdue');
  if (task.value?.is_at_risk) return t('tasks.deadline.atRisk');
  return '';
});
const deadlineToneClass = computed(() => {
  if (!task.value?.deadline) return 'text-next-muted-foreground';
  if (task.value.is_overdue) return 'text-next-danger';
  if (task.value.is_at_risk) return 'text-next-warning';
  return 'text-next-fg';
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
</script>

<template>
  <Drawer
    v-model:open="open"
    side="right"
    size="lg"
    floating
    :aria-label="t('tasks.detail.title')"
    @close="onClose"
  >
    <template #title>
      <span v-if="loading" class="block">
        <Skeleton variant="text" width="60%" />
      </span>
      <span v-else-if="task" class="block">
        <!-- Inline-editable title -->
        <span v-if="editingTitle" class="flex items-center gap-next-2">
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
          class="group flex w-full items-start gap-next-2 rounded-next-sm text-left text-next-lg font-next-semibold text-next-fg hover:text-next-primary disabled:cursor-default disabled:hover:text-next-fg"
          :disabled="isTrashed"
          :aria-label="t('tasks.detail.editTitle')"
          @click="startTitleEdit"
        >
          <span class="min-w-0 flex-1 break-words">{{ task.title }}</span>
          <Icon
            v-if="!isTrashed"
            name="pencil"
            class="mt-1 shrink-0 text-next-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
            aria-hidden="true"
          />
        </button>
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
    <div v-else-if="task" class="flex flex-col gap-next-5">
      <!-- A page-level save error (e.g. 422 message) surfaced once. -->
      <Alert v-if="fieldErrors.form" variant="danger" size="sm" :title="t('tasks.form.errorTitle')">
        {{ fieldErrors.form }}
      </Alert>

      <!-- Header controls: PROMINENT status switcher + priority + approval. -->
      <section class="flex flex-col gap-next-2" :aria-label="t('tasks.detail.status')">
        <span class="text-next-xs font-next-medium text-next-muted-foreground">
          {{ t('tasks.detail.status') }}
        </span>
        <div class="flex flex-wrap items-center gap-next-2">
          <!-- Trashed tasks can't transition (restore/force-delete only). -->
          <StatusBadge
            v-if="isTrashed"
            :status="task.status"
            :status-map="statusMap"
          />
          <!-- Locked (in approval) → show the status but no switcher. -->
          <StatusBadge
            v-else-if="isLocked"
            :status="task.status"
            :status-map="statusMap"
          />
          <DropdownMenu
            v-else
            :aria-label="t('tasks.detail.changeStatusLabel')"
          >
            <template #trigger="{ props: triggerProps }">
              <button
                type="button"
                class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-card px-next-2 py-next-1 text-next-sm transition-colors duration-[var(--duration-next-fast)] hover:bg-next-accent hover:text-next-accent-foreground disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="changingStatus || offerable.length === 0"
                :aria-label="t('tasks.detail.changeStatusLabel')"
                :aria-haspopup="triggerProps['aria-haspopup']"
                :aria-expanded="triggerProps['aria-expanded'] === 'true'"
                :aria-controls="triggerProps['aria-controls']"
              >
                <StatusBadge :status="task.status" :status-map="statusMap" size="sm" />
                <Icon
                  :name="changingStatus ? 'loader' : 'chevron-down'"
                  :class="changingStatus ? 'animate-spin' : ''"
                  class="shrink-0 text-next-muted-foreground"
                  aria-hidden="true"
                />
              </button>
            </template>

            <DropdownMenuLabel>{{ t('tasks.detail.changeStatusLabel') }}</DropdownMenuLabel>
            <DropdownMenuItem
              v-for="status in offerable"
              :key="status"
              :label="t(`tasks.statuses.${status}`)"
              @select="onChangeStatus(status)"
            >
              <StatusBadge :status="status" :status-map="statusOptionMap(status)" size="sm" />
            </DropdownMenuItem>
          </DropdownMenu>

          <Badge
            v-if="priority"
            :variant="priority.tone"
            tone="subtle"
            :icon="priority.icon"
            :title="t('tasks.priorityLabel', '', { label: priorityLabel })"
          >
            {{ priorityLabel }}
          </Badge>
          <Badge v-if="task.is_in_approval" variant="info" tone="subtle" icon="lock">
            {{ t('tasks.detail.inApproval') }}
          </Badge>
        </div>
        <!-- Live region so a status change is announced to AT. -->
        <span class="sr-only" role="status" aria-live="polite">
          {{ t('tasks.statuses.' + task.status) }}
        </span>
      </section>

      <!-- Approval lock hint -->
      <Alert v-if="task.is_in_approval" variant="info" size="sm">
        {{ t('tasks.detail.inApprovalHint') }}
      </Alert>

      <!-- Editable metadata: assignee / priority / deadline / labels. -->
      <section
        class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2"
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
            @update:model-value="persist('assigned_id')"
          />
        </FormField>

        <FormField :label="t('tasks.detail.deadline')" :error="fieldErrors.deadline">
          <DatePicker
            v-model="editState.deadline"
            :readonly="isTrashed"
            :aria-invalid="!!fieldErrors.deadline"
            :aria-label="t('tasks.detail.deadline')"
            @update:model-value="persist('deadline')"
          />
          <template v-if="deadlineStatusLabel">
            <span class="mt-next-1 flex items-center gap-next-1 text-next-xs" :class="deadlineToneClass">
              <Icon name="alert-triangle" aria-hidden="true" />
              {{ deadlineStatusLabel }}
            </span>
          </template>
        </FormField>

        <FormField
          class="next-sm:col-span-2"
          :label="t('tasks.detail.priority')"
          :error="fieldErrors.priority"
        >
          <SegmentedControl
            v-model="editState.priority"
            :options="priorityOptions"
            equal-width
            :disabled="isTrashed"
            :aria-label="t('tasks.detail.priority')"
            @update:model-value="persist('priority')"
          />
        </FormField>

        <FormField
          class="next-sm:col-span-2"
          :label="t('tasks.detail.labels')"
          :error="fieldErrors.labels"
        >
          <LabelSelect
            v-model="editState.labels"
            :seed="labelSeed"
            :readonly="isTrashed"
            :placeholder="t('tasks.form.labelsPlaceholder')"
            :aria-label="t('tasks.detail.labels')"
            @update:model-value="persist('labels')"
          />
        </FormField>

        <!-- Creator stays read-only (not part of the write contract). -->
        <div class="flex flex-col gap-next-1 next-sm:col-span-2">
          <span class="text-next-xs font-next-medium text-next-muted-foreground">
            {{ t('tasks.detail.creator') }}
          </span>
          <span class="text-next-sm text-next-fg">{{ task.creator?.name }}</span>
        </div>
      </section>

      <!-- Description: view ⇄ inline edit. -->
      <section :aria-label="t('tasks.detail.description')">
        <div class="mb-next-2 flex items-center justify-between gap-next-2">
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

        <template v-if="editingDescription">
          <MarkdownEditor
            v-model="editState.description"
            :placeholder="t('tasks.form.descriptionPlaceholder')"
            :aria-label="t('tasks.detail.description')"
          />
          <div class="mt-next-2 flex items-center gap-next-2">
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
      </section>

      <!-- Attachments (read-only) -->
      <section v-if="task.attachments?.length" :aria-label="t('tasks.detail.attachments')">
        <h3 class="mb-next-2 text-next-sm font-next-semibold text-next-fg">
          {{ t('tasks.detail.attachments') }}
        </h3>
        <ul class="flex flex-col gap-next-2">
          <li
            v-for="file in task.attachments"
            :key="file.id"
            class="flex items-center gap-next-3 rounded-next-md border border-next-border bg-next-card p-next-2"
          >
            <Icon name="file-text" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate text-next-sm text-next-fg">{{ file.name }}</span>
            <span v-if="file.size_human" class="shrink-0 text-next-xs text-next-muted-foreground">
              {{ file.size_human }}
            </span>
            <a
              :href="file.path"
              target="_blank"
              rel="noopener noreferrer"
              class="shrink-0 rounded-next-sm p-next-1 text-next-muted-foreground hover:text-next-fg"
              :aria-label="t('tasks.detail.download', '', { name: file.name })"
            >
              <Icon name="download" aria-hidden="true" />
            </a>
          </li>
        </ul>
      </section>

      <!-- Tabs: Comments + Activity -->
      <Tabs
        :items="[
          { value: 'comments', label: t('tasks.detail.tabComments'), icon: 'mail' },
          { value: 'activity', label: t('tasks.detail.tabActivity'), icon: 'clock' },
        ]"
        :aria-label="t('tasks.detail.title')"
      >
        <template #panel-comments>
          <TaskComments :task-id="task.id" class="pt-next-3" />
        </template>
        <template #panel-activity>
          <div class="pt-next-3">
            <Alert v-if="store.changelogError" variant="danger" size="sm">
              {{ t('tasks.changelog.loadError') }}
            </Alert>
            <Timeline
              v-else
              :items="changelogEntries"
              :loading="store.changelogLoading"
              compact
              :aria-label="t('tasks.changelog.title')"
              :empty-title="t('tasks.changelog.empty')"
              :empty-description="t('tasks.changelog.emptyDescription')"
            />
          </div>
        </template>
      </Tabs>
    </div>

    <!-- Footer: lifecycle actions only (status + fields are inline above). -->
    <template v-if="task && !loading && !error" #footer>
      <div class="flex w-full items-center justify-end gap-next-2">
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
</style>
