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
import { useRouter } from 'vue-router';
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
import BotSelect from '../../ui/forms/BotSelect.vue';
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
import TaskBotActions from './TaskBotActions.vue';
import BotIdentity from '../bots/BotIdentity.vue';
import FormViewer from '../forms/FormViewer.vue';
import type { FormElement } from '../forms/types';
import { api } from '../../app/lib/api';
import { groupRunHistory } from '../../app/stores/approvalQueue';
import { approvalStatusMap } from '../approvals/approvalStatus';
import { resolveApprover, approverTypeIcon } from '../approvals/approver';
import { resolvePipelineIcon } from '../../ui/forms/pipelineIcon';
import { buildApprovalTabModel, type StageStep } from './approvalTabModel';
import type { ApprovalProcess, RunHistoryResponse } from '../approvals/queue-types';
import { useTasksStore } from '../../app/stores/tasks';
import { useBotActionsStore } from '../../app/stores/botActions';
import { useAuthStore } from '../../app/stores/auth';
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
import { buildTaskPayload } from './taskPayload';
import { resolveAssignee } from './assignee';

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
const router = useRouter();
const store = useTasksStore();
const botActionsStore = useBotActionsStore();
const auth = useAuthStore();
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
  botActionsStore.resetTaskActions();
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

// Form editability: the attached form may be FILLED only while the task is "in
// progress" AND the current user is the assignee. In every other case (any other
// status, a non-assignee, or during approval) the form is strictly read-only.
const currentUserId = computed(() =>
  auth.user?.id != null ? String(auth.user.id) : null,
);
const isAssignedToMe = computed(
  () =>
    !!task.value &&
    currentUserId.value != null &&
    String(task.value.assigned?.id) === currentUserId.value,
);
const canEditForm = computed(
  () => task.value?.status === 'in_progress' && isAssignedToMe.value,
);

// Server-authoritative capability flags (TaskPolicy). The UI only ever offers
// actions the user can actually perform — never a button that would 403.
const canUpdate = computed(() => !!task.value?.can_update);
const canDelete = computed(() => !!task.value?.can_delete);
const canRestore = computed(() => !!task.value?.can_restore);
const canForceDelete = computed(() => !!task.value?.can_force_delete);
// Inline editing is off when trashed OR when the user lacks update rights (e.g.
// not the creator/assignee, or the task is locked in an approval process).
const readonly = computed(() => isTrashed.value || !canUpdate.value);

// Status transitions are SERVER-AUTHORITATIVE: the resource lists exactly the
// statuses this user may set right now (`available_status_transitions`, computed
// via TaskStatus::canSetOn). We render one button per entry — so the assignee/
// creator gating, the "in_test + pipeline → no direct done" rule, and the
// in-approval lock are all honoured without duplicating that logic here.
interface StatusAction {
  label: string;
  status: TaskStatus;
  variant: 'primary' | 'secondary' | 'outline';
  icon: IconName;
}
const STATUS_RANK: Record<TaskStatus, number> = {
  to_do: 0, in_progress: 1, in_test: 2, done: 3, archive: 4, trash: 5,
};
function actionMeta(current: TaskStatus, target: TaskStatus): { label: string; icon: IconName } {
  switch (target) {
    case 'to_do':
      return { label: 'backToTodo', icon: 'undo' };
    case 'in_progress':
      return current === 'to_do'
        ? { label: 'startTask', icon: 'arrow-right' }
        : { label: 'backToProgress', icon: 'undo' };
    case 'in_test':
      return { label: 'sendToTest', icon: 'flag' };
    case 'done':
      return { label: 'complete', icon: 'check' };
    case 'archive':
      return { label: 'archive', icon: 'inbox' };
    default:
      return { label: target, icon: 'arrow-right' };
  }
}
const statusActions = computed<StatusAction[]>(() => {
  const tk = task.value;
  if (!tk) return [];
  const current = tk.status;
  const targets = tk.available_status_transitions ?? [];
  // The furthest-forward target is the primary action; backward moves stay subtle.
  const forwardMost = [...targets]
    .filter((s) => STATUS_RANK[s] > STATUS_RANK[current])
    .sort((a, b) => STATUS_RANK[b] - STATUS_RANK[a])[0];
  return targets.map((target) => {
    const meta = actionMeta(current, target);
    const backward = STATUS_RANK[target] < STATUS_RANK[current];
    return {
      status: target,
      label: meta.label,
      icon: meta.icon,
      variant: target === forwardMost ? 'primary' : backward ? 'outline' : 'secondary',
    } satisfies StatusAction;
  });
});

// --- Editable field models (mirror the FULL StoreTasksRequest payload) ----
// A local mirror so a control can show the new value optimistically; reverted
// from `task` on every (re)load, and re-synced after a save reconciles `detail`.
interface EditState {
  title: string;
  description: string;
  priority: TaskPriority;
  deadline: string | null;
  /** The assignee group toggle: a workspace member or a bot executor. */
  assignee_kind: 'user' | 'bot';
  /** The selected user OR bot id (null = unassigned). Drives assignee_type/id. */
  assignee_id: string | null;
  labels: string[];
  /** Temp file ids of NEW uploads (existing attachments live in attachmentSeed). */
  attachments: string[];
}
const editState = reactive<EditState>({
  title: '',
  description: '',
  priority: 'medium',
  deadline: null,
  assignee_kind: 'user',
  assignee_id: null,
  labels: [],
  attachments: [],
});

// Seeds so already-selected assignee/labels render before async pages load.
const assigneeSeed = ref<
  Array<{ id: string; name: string; email?: string | null; avatar?: string | null }>
>([]);
const botSeed = ref<Array<{ id: string; name: string }>>([]);
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
  // Polymorphic assignee: resolve user vs bot, set the toggle + id, seed the
  // matching select so the current assignee renders without an async load.
  const resolved = resolveAssignee(t0);
  editState.assignee_kind = resolved?.isBot ? 'bot' : 'user';
  editState.assignee_id = resolved ? resolved.id : null;
  assigneeSeed.value =
    resolved && !resolved.isBot
      ? [
          {
            id: resolved.id,
            name: resolved.name ?? '',
            email: t0.assigned?.email ?? null,
            avatar: resolved.avatar ?? null,
          },
        ]
      : [];
  botSeed.value =
    resolved && resolved.isBot ? [{ id: resolved.id, name: resolved.name ?? '' }] : [];
  editState.labels = (t0.labels ?? []).map((l) => String(l.id));
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
  if (readonly.value) return;
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
  if (readonly.value) return;
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
  // The shared builder ALWAYS emits form_id AND approval_pipeline_id (string|null).
  // The drawer has NO form/pipeline picker, so it ECHOES the task's current values
  // — the TaskDTO coerces an ABSENT id to null on update, so an inline metadata
  // edit would otherwise silently DETACH the attached form/pipeline. Only NEW
  // uploads are sent.
  return buildTaskPayload({
    title: editState.title,
    description: markdownToTaskDescriptionPayload(editState.description),
    priority: editState.priority,
    deadline: editState.deadline,
    // Drive the polymorphic assignee: assignee_type/assignee_id (both null clears).
    assignee_type: editState.assignee_id ? editState.assignee_kind : null,
    assignee_id: editState.assignee_id,
    labels: editState.labels,
    attachments: editState.attachments,
    form_id: task.value?.form_id ?? null,
    approval_pipeline_id: task.value?.approval_pipeline_id ?? null,
  });
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
  if (readonly.value) return;
  editState.priority = value;
  debouncedPersist('priority');
}

// Switching the assignee group clears the previously-picked id so a user id can
// never be sent as a bot (or vice versa). No persist yet — the user must then
// pick someone in the new group (or leave it cleared, which detaches).
function onAssigneeKindChange(): void {
  if (readonly.value) return;
  editState.assignee_id = null;
}

// The assignee group toggle options (Member | Bot) — backend validates the kind.
const assigneeKindOptions = computed<SegmentOption<'user' | 'bot'>[]>(() => [
  { value: 'user', label: t('tasks.form.assigneeMember'), icon: 'user' },
  { value: 'bot', label: t('tasks.form.assigneeBot'), icon: 'sparkles' },
]);

// When a bot that can execute tasks is assigned, surface the auto-run hint.
const showBotExecuteHint = computed(
  () => editState.assignee_kind === 'bot' && !!editState.assignee_id,
);

// The task's bot activity is shown only when it has/had a bot assignee OR the
// feed already has actions (a bot ran it earlier then got reassigned). The
// TaskBotActions component owns the actual fetch + its own four states.
const hasBotActivity = computed(() => {
  const resolved = resolveAssignee(task.value ?? {});
  return resolved?.isBot === true || botActionsStore.taskActions.length > 0;
});

// --- Interactive bot execution (Batch 4) ---------------------------------
// The bot asked a question and is WAITING for a human reply in the comments.
const botWaiting = computed(() => task.value?.bot_waiting === true);

// The run counter (used vs cap). Shown as muted meta near the bot assignee /
// activity; when the cap is reached the bot has HANDED the task to a human.
const botRunsUsed = computed(() => task.value?.bot_runs_used ?? 0);
const botRunsCap = computed(() => task.value?.bot_runs_cap ?? 0);
// Only render the counter when a cap is actually configured (>0), so tasks that
// never involved a bot don't show a "0/0" meta line.
const showBotRuns = computed(() => botRunsCap.value > 0);
const botHandedOver = computed(
  () => showBotRuns.value && botRunsUsed.value >= botRunsCap.value,
);

// New uploads land in editState.attachments as their temp ids resolve; persist
// (debounced) so several quick uploads coalesce into one additive update.
function onAttachmentsChanged(): void {
  if (readonly.value) return;
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

// --- Form tab: AUTO-SAVE (no submit button) -------------------------------
// The form renders the attached form's `content` via FormViewer. While editable
// (not in approval) it AUTO-SAVES with throttling as the user types — there is no
// submit button. During approval it is STRICTLY read-only (preview mode → disabled
// controls). Saving POSTs the answers (create-or-update) WITHOUT changing status.
type FormSaveState = 'idle' | 'saving' | 'saved' | 'error';
const formSaveState = ref<FormSaveState>('idle');

const formGate = computed(() => {
  const tk = task.value;
  if (!tk?.form) return 'none' as const;
  if (tk.form.can_be_filled === false) return 'not-fillable' as const;
  return 'fillable' as const;
});

// Snapshot the form's CONTENT + submission, refreshed only when the task or its
// attached form changes. An auto-save replaces `detail`, which would otherwise hand
// FormViewer new `content`/`initialData` references and trigger its re-hydration —
// wiping the user's in-flight input. Keying on [task id, form id] keeps both props
// STABLE across saves while still refreshing on a real task/form switch.
const formContent = ref<FormElement[] | null>(null);
const formInitialData = ref<Record<string, unknown> | null>(null);
watch(
  () => [task.value?.id, task.value?.form?.id] as const,
  () => {
    formContent.value = task.value?.form?.content ?? null;
    formInitialData.value = task.value?.form_submission?.data ?? null;
    formSaveState.value = 'idle';
  },
  { immediate: true },
);

async function saveForm(data: Record<string, unknown>): Promise<void> {
  if (!task.value || !canEditForm.value) return;
  formSaveState.value = 'saving';
  try {
    await store.submitTaskForm(task.value.id, data);
    formSaveState.value = 'saved';
  } catch (err: unknown) {
    formSaveState.value = 'error';
    toast.danger(extractMessage(err, t('tasks.detail.form.saveError')));
  }
}

// Throttle the auto-save so it fires once the user pauses, not on every keystroke.
const debouncedSaveForm = useDebounce((data: Record<string, unknown>) => {
  void saveForm(data);
}, 1000);

function onFormChange(data: Record<string, unknown>): void {
  if (!canEditForm.value) return;
  formSaveState.value = 'saving';
  debouncedSaveForm(data);
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

// --- Approval tab (READ-ONLY) ---------------------------------------------
// The Approval tab DISPLAYS the attached pipeline as a vertical stage stepper
// (current pending stage highlighted) + the run-history decisions per stage. It is
// read-only: approvals are decided in the Approvals queue/review, NOT here.
//
// Run-history fetch choice: a DIRECT `GET /approvals/runs/{runId}` via the api
// singleton (lazily, on tab activation), NOT the approval-queue store action. The
// store's `fetchRunHistory` writes into a cross-domain cache tied to the queue's
// list/decision lifecycle; a local ref keeps the task tab self-contained (its own
// loading/error/empty state) and avoids coupling the drawer to the queue store. We
// still REUSE the pure `groupRunHistory` helper + the `buildApprovalTabModel`
// adapter for the view-model.
const activeTab = ref<'activity' | 'form' | 'checklist' | 'approval'>('activity');

const runHistory = ref<ApprovalProcess[]>([]);
const runHistoryLoading = ref(false);
const runHistoryError = ref(false);
// The run id we have history for, so a tab re-activation / task change refetches.
const loadedRunId = ref<string | null>(null);

const pendingProcess = computed(() => task.value?.pending_approval_process ?? null);
const approvalPipeline = computed(() => task.value?.approval_pipeline ?? null);

// No pipeline attached at all (neither the eager-loaded resource nor the scalar id).
const hasPipeline = computed(
  () => !!approvalPipeline.value || !!task.value?.approval_pipeline_id,
);
// Pipeline attached but the task is NOT currently in an approval process.
const notInApproval = computed(() => hasPipeline.value && !pendingProcess.value);

// Prefer the pending run, but fall back to the latest (completed) run id so the
// Approval tab still shows decided stages + history after a run has finished.
const runId = computed(() => pendingProcess.value?.run_id ?? task.value?.approval_run_id ?? null);

async function loadRunHistory(): Promise<void> {
  const id = runId.value;
  if (!id) return;
  runHistoryLoading.value = true;
  runHistoryError.value = false;
  try {
    const res = await api.get<RunHistoryResponse>(`/approvals/runs/${id}`);
    runHistory.value = res.data ?? [];
    loadedRunId.value = id;
  } catch {
    runHistoryError.value = true;
  } finally {
    runHistoryLoading.value = false;
  }
}

// Lazily fetch the run history the FIRST time the Approval tab is opened for a run
// (and again if the run id changes). Only fetch when there IS a run id (a pipeline
// attached but not in approval has none → nothing to fetch).
watch(
  () => [activeTab.value, runId.value] as const,
  ([tab, id]) => {
    if (tab !== 'approval' || !id) return;
    if (id !== loadedRunId.value && !runHistoryLoading.value) void loadRunHistory();
  },
);

// Reset the cached history whenever the task changes so a stale run never leaks.
watch(
  () => task.value?.id,
  () => {
    runHistory.value = [];
    loadedRunId.value = null;
    runHistoryError.value = false;
  },
);

const approvalStatuses = computed(() => approvalStatusMap(t));

// The stepper view-model: pipeline stages + per-stage status (current pending /
// decided-from-history / upcoming), tolerant of stage=null history.
const approvalModel = computed(() =>
  buildApprovalTabModel(
    approvalPipeline.value?.stages ?? [],
    pendingProcess.value,
    groupRunHistory(runHistory.value),
  ),
);

/** Resolve a stage's backend icon enum to a renderable next IconName. */
function stageIcon(step: StageStep): IconName {
  return resolvePipelineIcon(step.icon);
}

// Resolve a stage's / decision's named approver (user|bot|null) — prefers the new
// `approver_identity`, falls back to the legacy `approver`, null → generic AI.
function stepApprover(step: StageStep) {
  return resolveApprover(step);
}
function entryApprover(entry: ApprovalProcess) {
  return resolveApprover(entry);
}

/** Read-only jump to the Approvals queue (decisions are made there, not here). */
function goToApprovals(): void {
  void router.push({ name: 'next.approvals.queue' });
}
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
          :class="readonly ? '' : 'cursor-text'"
          :disabled="readonly"
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
        <FormField
          :label="t('tasks.detail.assignee')"
          :error="fieldErrors.assignee_id || (fieldErrors as any).assignee_type"
        >
          <div class="flex flex-col gap-next-2">
            <SegmentedControl
              v-model="editState.assignee_kind"
              :options="assigneeKindOptions"
              size="sm"
              equal-width
              :disabled="readonly"
              :aria-label="t('tasks.form.assigneeKind')"
              @update:model-value="onAssigneeKindChange"
            />
            <UserSelect
              v-if="editState.assignee_kind === 'user'"
              v-model="editState.assignee_id"
              :seed="assigneeSeed"
              :readonly="readonly"
              :aria-invalid="!!fieldErrors.assignee_id"
              :placeholder="t('tasks.form.assigneePlaceholder')"
              :aria-label="t('tasks.detail.assignee')"
              @update:model-value="debouncedPersist('assignee_id')"
            />
            <BotSelect
              v-else
              v-model="editState.assignee_id"
              :seed="botSeed"
              :readonly="readonly"
              :aria-invalid="!!fieldErrors.assignee_id"
              :placeholder="t('tasks.form.botAssigneePlaceholder')"
              :aria-label="t('tasks.form.botAssignee')"
              @update:model-value="debouncedPersist('assignee_id')"
            />
            <p
              v-if="showBotExecuteHint"
              class="flex items-start gap-next-1_5 text-next-xs text-next-muted-foreground"
            >
              <Icon name="sparkles" class="mt-px shrink-0" aria-hidden="true" />
              <span>{{ t('tasks.form.botWillExecuteHint') }}</span>
            </p>
            <!-- Bot is waiting for a human reply (small hint next to the identity). -->
            <p
              v-if="botWaiting"
              class="flex items-start gap-next-1_5 text-next-xs font-next-medium text-next-warning"
            >
              <Icon name="help-circle" class="mt-px shrink-0" aria-hidden="true" />
              <span>{{ t('tasks.botWaiting.hint') }}</span>
            </p>
            <!-- Run counter (muted; warning + handed-over label when the cap is hit). -->
            <p
              v-if="showBotRuns"
              class="flex items-center gap-next-1_5 text-next-xs"
              :class="botHandedOver ? 'text-next-warning' : 'text-next-muted-foreground'"
            >
              <Icon
                :name="botHandedOver ? 'log-out' : 'redo'"
                class="shrink-0"
                aria-hidden="true"
              />
              <span>
                {{ t('tasks.botRuns.counter', '', { used: botRunsUsed, cap: botRunsCap }) }}
                <template v-if="botHandedOver"> · {{ t('tasks.botRuns.handedOver') }}</template>
              </span>
            </p>
          </div>
        </FormField>

        <FormField :label="t('tasks.detail.deadline')" :error="fieldErrors.deadline">
          <DatePicker
            v-model="editState.deadline"
            :readonly="readonly"
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
            :disabled="readonly"
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
            :readonly="readonly"
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
                :readonly="readonly"
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
            v-if="!editingDescription && !readonly"
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
            v-model="activeTab"
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

                <!-- Bot activity (only when a bot ran / is assigned). The shared
                     TaskBotActions component owns its own 4 states + load-more. -->
                <section
                  v-if="task && hasBotActivity"
                  class="mt-next-4 flex flex-col gap-next-2 border-t border-next-border pt-next-4"
                  :aria-label="t('tasks.detail.botActivity.title')"
                >
                  <h4 class="flex items-center gap-next-1_5 text-next-xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground">
                    <Icon name="sparkles" class="shrink-0" aria-hidden="true" />
                    {{ t('tasks.detail.botActivity.title') }}
                  </h4>
                  <TaskBotActions :task-id="task.id" />
                </section>
              </div>
            </template>
            <template #panel-form>
              <div class="min-h-0 flex-1 overflow-y-auto pt-next-3">
                <!-- No form attached. -->
                <EmptyState
                  v-if="formGate === 'none'"
                  icon="file-text"
                  :title="t('tasks.detail.form.noForm')"
                  :description="t('tasks.detail.form.noFormHint')"
                />
                <!-- Attached but not ready to fill (disabled / draft). -->
                <EmptyState
                  v-else-if="formGate === 'not-fillable'"
                  icon="file-text"
                  :title="t('tasks.detail.form.notFillable')"
                  :description="t('tasks.detail.form.notFillableHint')"
                />
                <!-- Fillable: auto-saving form (editable) or read-only preview
                     while the task is in approval. -->
                <div v-else class="flex flex-col gap-next-3">
                  <!-- Read-only: explain WHY (in approval vs not editable now). The
                       form is only fillable while In-progress AND you're the assignee. -->
                  <Alert
                    v-if="!canEditForm"
                    variant="info"
                    size="sm"
                    :icon="isLocked ? 'lock' : 'info'"
                  >
                    {{ isLocked ? t('tasks.detail.form.locked') : t('tasks.detail.form.readOnlyHint') }}
                  </Alert>
                  <!-- Editable → auto-save status (no submit button). -->
                  <div
                    v-else
                    class="flex items-center gap-next-1_5 text-next-xs text-next-muted-foreground"
                    aria-live="polite"
                  >
                    <template v-if="formSaveState === 'saving'">
                      <Icon name="loader" class="shrink-0 animate-spin" />
                      {{ t('tasks.detail.form.saving') }}
                    </template>
                    <template v-else-if="formSaveState === 'saved'">
                      <Icon name="check" class="shrink-0 text-next-success" />
                      {{ t('tasks.detail.form.saved') }}
                    </template>
                    <template v-else-if="formSaveState === 'error'">
                      <Icon name="alert-triangle" class="shrink-0 text-next-danger" />
                      {{ t('tasks.detail.form.saveError') }}
                    </template>
                    <template v-else>
                      <Icon name="info" class="shrink-0" />
                      {{ t('tasks.detail.form.autosaveHint') }}
                    </template>
                  </div>
                  <FormViewer
                    :content="formContent ?? []"
                    :mode="canEditForm ? 'fill' : 'preview'"
                    :initial-data="formInitialData"
                    hide-submit
                    :track-changes="canEditForm"
                    @change="onFormChange"
                  />
                </div>
              </div>
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
              <div class="min-h-0 flex-1 overflow-y-auto pt-next-3">
                <!-- No pipeline attached at all. -->
                <EmptyState
                  v-if="!hasPipeline"
                  icon="git-branch"
                  :title="t('tasks.detail.approval.noPipeline')"
                  :description="t('tasks.detail.approval.noPipelineHint')"
                />

                <!-- Pipeline attached → stage stepper (+ history when in approval). -->
                <div v-else class="flex flex-col gap-next-4">
                  <!-- Pipeline header. -->
                  <div class="flex items-center gap-next-2">
                    <Icon
                      :name="resolvePipelineIcon(approvalPipeline?.icon)"
                      class="shrink-0 text-next-muted-foreground"
                    />
                    <span class="min-w-0 truncate text-next-sm font-next-semibold text-next-fg">
                      {{ approvalPipeline?.name ?? t('tasks.detail.approval.pipeline') }}
                    </span>
                  </div>

                  <!-- Read-only "not currently in approval" hint. -->
                  <Alert v-if="notInApproval" variant="info" size="sm" icon="info">
                    {{ t('tasks.detail.approval.notInApproval') }}
                  </Alert>

                  <!-- Vertical stage stepper (ordered list for a11y). -->
                  <ol
                    v-if="approvalModel.steps.length"
                    class="flex flex-col"
                    :aria-label="t('tasks.detail.approval.stagesLabel')"
                  >
                    <li
                      v-for="(step, i) in approvalModel.steps"
                      :key="step.id"
                      class="relative flex gap-next-3 pb-next-4 last:pb-0"
                    >
                      <!-- Connector rail + node. -->
                      <div class="flex flex-col items-center">
                        <span
                          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-next-full border"
                          :class="step.isCurrent
                            ? 'border-next-primary bg-next-primary-subtle text-next-primary'
                            : step.status === 'upcoming'
                              ? 'border-next-border bg-next-muted text-next-muted-foreground'
                              : 'border-next-border bg-next-card text-next-fg'"
                          aria-hidden="true"
                        >
                          <Icon :name="stageIcon(step)" />
                        </span>
                        <span
                          v-if="i < approvalModel.steps.length - 1"
                          class="mt-next-1 w-px flex-1 bg-next-border"
                          aria-hidden="true"
                        />
                      </div>

                      <!-- Stage body. -->
                      <div
                        class="min-w-0 flex-1 rounded-next-md border p-next-3"
                        :class="step.isCurrent
                          ? 'border-next-primary/40 bg-next-primary-subtle/40'
                          : 'border-next-border'"
                      >
                        <div class="flex flex-wrap items-center justify-between gap-next-2">
                          <span class="min-w-0 truncate text-next-sm font-next-medium text-next-fg">
                            {{ step.name }}
                          </span>
                          <StatusBadge
                            v-if="step.status !== 'upcoming'"
                            :status="step.status"
                            :status-map="approvalStatuses"
                            size="sm"
                          />
                          <Badge v-else variant="neutral" tone="subtle" icon="clock" size="sm">
                            {{ t('tasks.detail.approval.stageUpcoming') }}
                          </Badge>
                        </div>

                        <!-- Approver: bot identity for a bot, avatar for a user,
                             generic AI glyph + label otherwise. -->
                        <div class="mt-next-2 flex items-center gap-next-2">
                          <template v-if="stepApprover(step)?.isBot">
                            <BotIdentity :name="stepApprover(step)?.name" size="xs" show-badge />
                          </template>
                          <template v-else-if="stepApprover(step)">
                            <Avatar
                              :name="stepApprover(step)?.name ?? undefined"
                              :src="stepApprover(step)?.avatar ?? undefined"
                              size="xs"
                              class="shrink-0"
                            />
                            <span class="min-w-0 truncate text-next-xs text-next-muted-foreground">
                              {{ stepApprover(step)?.name }}
                            </span>
                          </template>
                          <template v-else>
                            <Icon
                              :name="approverTypeIcon(step.approver_type)"
                              class="shrink-0 text-next-muted-foreground"
                            />
                            <span class="text-next-xs text-next-muted-foreground">
                              {{ t('tasks.detail.approval.aiApprover') }}
                            </span>
                          </template>
                        </div>

                        <!-- Per-stage decision history (approver / status / note / time). -->
                        <ul
                          v-if="step.history.length"
                          class="mt-next-3 flex flex-col gap-next-2 border-t border-next-border pt-next-2"
                        >
                          <li
                            v-for="entry in step.history"
                            :key="entry.id"
                            class="flex flex-col gap-next-1"
                          >
                            <div class="flex flex-wrap items-center gap-next-2">
                              <StatusBadge
                                :status="entry.status"
                                :status-map="approvalStatuses"
                                size="sm"
                              />
                              <BotIdentity
                                v-if="entryApprover(entry)?.isBot"
                                :name="entryApprover(entry)?.name"
                                size="xs"
                                show-badge
                              />
                              <span v-else class="min-w-0 truncate text-next-xs text-next-fg">
                                {{ entryApprover(entry)?.name ?? t('tasks.detail.approval.aiApprover') }}
                              </span>
                              <span
                                v-if="entry.decided_at"
                                class="text-next-2xs text-next-muted-foreground"
                              >
                                {{ formatDateTime(entry.decided_at) }}
                              </span>
                            </div>
                            <p
                              v-if="entry.note"
                              class="text-next-xs text-next-muted-foreground"
                            >
                              {{ entry.note }}
                            </p>
                          </li>
                        </ul>
                      </div>
                    </li>
                  </ol>

                  <!-- Decision history (pending OR a completed run). -->
                  <template v-if="runId">
                    <!-- Loading: skeleton rows mimicking the history entries. -->
                    <div
                      v-if="runHistoryLoading"
                      class="flex flex-col gap-next-2"
                      aria-hidden="true"
                    >
                      <Skeleton v-for="n in 2" :key="n" variant="rect" width="100%" height="2.5rem" radius="md" />
                    </div>
                    <!-- Error + retry. -->
                    <Alert
                      v-else-if="runHistoryError"
                      variant="danger"
                      size="sm"
                    >
                      <div class="flex items-center justify-between gap-next-2">
                        <span>{{ t('tasks.detail.approval.historyError') }}</span>
                        <Button size="sm" variant="outline" leading-icon="redo" @click="loadRunHistory">
                          {{ t('tasks.detail.retry') }}
                        </Button>
                      </div>
                    </Alert>
                    <!-- Empty (loaded, no decisions yet). -->
                    <p
                      v-else-if="!runHistory.length"
                      class="text-next-xs italic text-next-muted-foreground"
                    >
                      {{ t('tasks.detail.approval.historyEmpty') }}
                    </p>

                    <!-- Decisions orphaned by a pipeline re-save (stage FK nulled). -->
                    <div
                      v-if="approvalModel.orphanHistory.length"
                      class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3"
                    >
                      <span class="text-next-xs font-next-medium text-next-fg">
                        {{ t('tasks.detail.approval.historyOther') }}
                      </span>
                      <div
                        v-for="entry in approvalModel.orphanHistory"
                        :key="entry.id"
                        class="flex flex-wrap items-center gap-next-2"
                      >
                        <StatusBadge
                          :status="entry.status"
                          :status-map="approvalStatuses"
                          size="sm"
                        />
                        <BotIdentity
                          v-if="entryApprover(entry)?.isBot"
                          :name="entryApprover(entry)?.name"
                          size="xs"
                          show-badge
                        />
                        <span v-else class="min-w-0 truncate text-next-xs text-next-fg">
                          {{ entryApprover(entry)?.name ?? t('tasks.detail.approval.aiApprover') }}
                        </span>
                        <span
                          v-if="entry.decided_at"
                          class="text-next-2xs text-next-muted-foreground"
                        >
                          {{ formatDateTime(entry.decided_at) }}
                        </span>
                      </div>
                    </div>
                  </template>

                  <!-- Optional read-only link out to the Approvals queue. -->
                  <div>
                    <Button
                      variant="ghost"
                      size="sm"
                      leading-icon="external-link"
                      @click="goToApprovals"
                    >
                      {{ t('tasks.detail.approval.viewInApprovals') }}
                    </Button>
                  </div>
                </div>
              </div>
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
          <!-- Bot is waiting for a human reply → draw the eye to the composer:
               answering below resumes the bot (Batch 4). -->
          <Alert
            v-if="botWaiting"
            variant="info"
            size="sm"
            icon="help-circle"
            :title="t('tasks.botWaiting.calloutTitle')"
            class="mb-next-3 shrink-0"
          >
            {{ t('tasks.botWaiting.calloutBody') }}
          </Alert>
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
        <!-- Lifecycle actions (gated by the server capability flags). -->
        <div class="flex items-center gap-next-2">
          <template v-if="isTrashed">
            <Button v-if="canRestore" variant="outline" leading-icon="undo" :loading="restoring" @click="onRestore">
              {{ t('tasks.detail.restore') }}
            </Button>
            <Button v-if="canForceDelete" variant="danger" leading-icon="trash" :loading="forceDeleting" @click="onForceDelete">
              {{ t('tasks.detail.forceDelete') }}
            </Button>
          </template>
          <Button v-else-if="canDelete" variant="danger" leading-icon="trash" :loading="deleting" @click="onDelete">
            {{ t('tasks.detail.delete') }}
          </Button>
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
