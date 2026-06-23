// Tasks domain types + enum helpers for the isolated "next" frontend.
//
// These MIRROR the verified backend contract (TaskListResource, TaskStatus /
// TaskPriority enums, the /api/tasks list query params) — no invented fields.
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/tasks.ts` is reference only).
//
// IMPORTANT: status / priority LABELS are i18n KEYS resolved through `t()` at the
// call site — never the backend Polish strings. The helpers below expose the i18n
// key, the StatusBadge descriptor (variant/tone), and a `next`-registry icon.
import type { IconName } from '../../ui/primitives/icons';
import type { StatusDescriptor } from '../../ui/data/StatusBadge.vue';
import type { JSONNode } from '../../ui/editor/markdown';
import type { FormElement } from '../forms/types';
// Reuse the verified Approvals domain types (Batch 1 Pipelines + Batch 2 Queue) —
// the task detail now eager-loads the SAME ApprovalPipelineResource /
// ApprovalProcessResource shapes, so we import rather than redefine them.
import type { ApprovalPipeline } from '../approvals/types';
import type { ApprovalProcess } from '../approvals/queue-types';

/**
 * A task `description` as the backend returns it: a ProseMirror/Tiptap doc OBJECT
 * (`MarkdownTreeCast` → `MarkdownTree`), NOT a markdown string. Older/legacy data
 * may also surface as a JSON string or null. Convert at the boundary with the
 * helpers in `./description` (never hand the raw value to MarkdownViewer).
 */
export type TaskDescription = JSONNode | string | null;

/** The six task statuses (backend enum `TaskStatus`). */
export type TaskStatus =
  | 'to_do'
  | 'in_progress'
  | 'in_test'
  | 'done'
  | 'archive'
  | 'trash';

/** The four priorities (backend enum `TaskPriority`). */
export type TaskPriority = 'urgent' | 'high' | 'medium' | 'low';

/** A user as returned by UserResource (the `assigned` relation). */
export interface TaskUser {
  id: string | number;
  name: string;
  email?: string | null;
  avatar?: string | null;
}

/** A label as returned by LabelResource. */
export interface TaskLabel {
  id: string | number;
  name: string;
  color?: string | null;
  description?: string | null;
  icon?: string | null;
}

/**
 * A task list row (verified `TaskListResource` shape). `comments` is
 * `whenCounted` on the backend, so it may be absent → treat as 0.
 */
export interface TaskListItem {
  id: string | number;
  title: string;
  /** ProseMirror doc object (see `TaskDescription`), not a markdown string. */
  description?: TaskDescription;
  priority: TaskPriority;
  /** Formatted `dd.mm.yyyy` or null. */
  deadline: string | null;
  is_overdue: boolean;
  is_at_risk: boolean;
  comments?: number;
  assigned: TaskUser;
  labels: TaskLabel[];
  is_in_approval: boolean;
}

/**
 * The list-screen filter state. Mirrors the `/api/tasks` query params 1:1 (the
 * page owns this; the store serializes it). `status` is passed separately to the
 * store (one request per status), so it is NOT part of the shared filter object.
 */
export interface TaskFilters {
  search?: string;
  priority?: TaskPriority | '';
  /** Assignee user ids → serialized as `user_id[]`. */
  user_id?: Array<string | number>;
  /** Label ids → serialized as `labels[]`. */
  labels?: Array<string | number>;
  labelOperator?: 'AND' | 'OR';
  date_from?: string | null;
  date_to?: string | null;
  date_preset?: '' | 'today' | 'this_week' | 'last_week' | 'this_month';
  /** Serialized as `hide_without_deadline=1` when on. */
  hide_without_deadline?: boolean;
}

/** Cursor-paginated list envelope from `/api/tasks`. */
export interface TaskListMeta {
  next_cursor: string | null;
  /** Only present on the first page (no cursor). */
  total?: number | null;
}
export interface TaskListResponse {
  data: TaskListItem[];
  meta: TaskListMeta;
}

// --- Detail (full TaskResource) ------------------------------------------

/**
 * The subset of a Form (FormResource) the Task detail needs when a form is
 * attached: it eager-loads `form` so `form.content` (the element tree) is present
 * and we can render it through FormViewer. `can_be_filled` is the server-
 * authoritative capability flag the Form tab gates on. Mirrors the relevant
 * FormResource fields 1:1 — no invented fields.
 */
export interface TaskForm {
  id: string | number;
  name: string;
  /** Legacy IconEnum value (NOT a `next` icon name) or null. */
  icon?: string | null;
  /** The element tree to render (FormViewer `content`). */
  content: FormElement[];
  /** Server-authoritative: false → the form isn't ready to fill. */
  can_be_filled?: boolean;
}

/**
 * The task's FormSubmission as eager-loaded into TaskResource (`form_submission`).
 * It arrives NESTED inside the TaskResource — so even though a SINGLE
 * FormSubmissionResource is unwrapped on its own endpoint, here it is read as
 * `task.form_submission`. `data` is the NESTED answers map FormViewer hydrates from.
 */
export interface TaskFormSubmission {
  id: string | number;
  data: Record<string, unknown> | null;
}

/** An attachment as returned by FileResource (the `attachments` relation). */
export interface TaskAttachment {
  id: string | number;
  name: string;
  /** Download URL (route('disk.show')). */
  path: string;
  type?: string | null;
  size?: number | null;
  size_human?: string | null;
  created_at?: string | null;
}

/**
 * The FULL task as returned by `GET /api/tasks/{id}` → `{ data: TaskResource }`.
 * Mirrors `TaskResource::toArray` exactly (no invented fields). `description` is
 * a markdown string (nullable). Relations beyond the always-present ones
 * (`form`, `approval_pipeline`, …) are `whenLoaded` on the backend and may be
 * absent — typed optional and treated as deferred in the UI.
 */
export interface TaskDetail {
  id: string | number;
  title: string;
  /** ProseMirror doc object (see `TaskDescription`), not a markdown string. */
  description: TaskDescription;
  status: TaskStatus;
  priority: TaskPriority;
  /** ISO `yyyy-mm-dd` or null. */
  deadline: string | null;
  /** Signed day delta to now (negative = future). */
  deadline_overdue: number | null;
  is_overdue: boolean;
  is_at_risk: boolean;
  attachments: TaskAttachment[];
  creator: TaskUser;
  assigned: TaskUser;
  labels: TaskLabel[];
  form_id: string | null;
  approval_pipeline_id: string | null;
  is_in_approval: boolean;
  /**
   * The attached form (eager-loaded by TaskResource → present whenever `form_id`
   * is set). Carries `content` (the element tree) + `can_be_filled` so the Form
   * tab can render + gate it.
   */
  form?: TaskForm | null;
  /** The task's current form answers (eager-loaded `form_submission`), or null. */
  form_submission?: TaskFormSubmission | null;
  /**
   * The attached approval pipeline (ApprovalPipelineResource) — full stages +
   * ownership/capability flags. Present whenever a pipeline is attached; null
   * otherwise. REUSES the Approvals module type (do not redefine).
   */
  approval_pipeline?: ApprovalPipeline | null;
  /**
   * The task's CURRENT pending approval process (ApprovalProcessResource), or null
   * when the task is not in approval. Carries `run_id` (for the run history),
   * `stage` (the current pending stage), `approver`, `status`, `note`. REUSES the
   * Approvals Queue type (do not redefine).
   */
  pending_approval_process?: ApprovalProcess | null;
  /**
   * Server-authoritative: the statuses the CURRENT user may transition to RIGHT
   * NOW (subset of the enum, never `trash`; empty while in approval or when the
   * user isn't permitted). Computed via `TaskStatus::canSetOn` — the UI renders a
   * button per entry and nothing else.
   */
  available_status_transitions: TaskStatus[];
  /** Server-authoritative capability flags (TaskPolicy). The UI hides/disables to match. */
  can_update: boolean;
  can_delete: boolean;
  can_restore: boolean;
  can_force_delete: boolean;
  /**
   * The latest approval run id (pending OR completed), or null when no pipeline was
   * ever attached. Lets the Approval tab fetch the run history even after a run has
   * finished (so decided stages don't all read as "upcoming").
   */
  approval_run_id: string | null;
}

/** Detail envelope from `GET /api/tasks/{id}`. */
export interface TaskDetailResponse {
  data: TaskDetail;
}

// --- Create / update payload (StoreTasksRequest + TaskDTO) ----------------

/**
 * The create/update payload — matches `StoreTasksRequest::rules()` +
 * `TaskDTO::fromRequest()` 1:1 (no invented fields):
 *   title               required, string, max 255
 *   description         nullable, string, max 2500 (markdown string)
 *   priority            required, TaskPriority enum value
 *   deadline            nullable, date — sent ISO `yyyy-mm-dd`
 *   assigned_id         required, uuid (exists:users)
 *   labels[]            array (0..5) of uuid label ids
 *   attachments[]       array (0..5) of uuid file ids (uploads are deferred)
 *   form_id             nullable, uuid
 *   approval_pipeline_id nullable, uuid
 * NOTE: status is NOT part of the create/update payload — new tasks default to
 * `to_do` server-side; status changes go through the dedicated endpoint.
 */
export interface TaskWritePayload {
  title: string;
  description?: string | null;
  priority: TaskPriority;
  deadline?: string | null;
  assigned_id: string;
  labels?: string[];
  attachments?: string[];
  form_id?: string | null;
  approval_pipeline_id?: string | null;
}

// --- Comments (CommentResource, cursor-paginated) -------------------------

export interface TaskCommentAuthor {
  id: string | number;
  name: string;
  email?: string | null;
}

/** A comment as returned by CommentResource. */
export interface TaskComment {
  id: string | number;
  content: string;
  author: TaskCommentAuthor;
  created_at: string;
  updated_at: string;
  is_edited: boolean;
}

export interface TaskCommentsResponse {
  data: TaskComment[];
  meta?: { next_cursor: string | null };
}

// --- Changelog (ChangelogResource, cursor-paginated) ----------------------

export interface ChangelogCauser {
  id: string | number;
  name: string;
  email?: string | null;
}

/** A changelog entry as returned by ChangelogResource. */
export interface ChangelogEntry {
  id: string | number;
  event: string;
  event_description: string;
  details: Record<string, unknown>;
  causer: ChangelogCauser | null;
  created_at: string;
}

export interface ChangelogResponse {
  data: ChangelogEntry[];
  meta?: { next_cursor: string | null };
}

// --- Enum metadata --------------------------------------------------------

/** Display order shown on the board (archive/trash reached via the filter). */
export const BOARD_STATUSES: TaskStatus[] = [
  'to_do',
  'in_progress',
  'in_test',
  'done',
];

/** All statuses, including the secondary archive/trash buckets. */
export const ALL_STATUSES: TaskStatus[] = [
  ...BOARD_STATUSES,
  'archive',
  'trash',
];

export const ALL_PRIORITIES: TaskPriority[] = [
  'urgent',
  'high',
  'medium',
  'low',
];

interface StatusMeta {
  /** i18n key for the label (resolve via `t()`). */
  i18nKey: string;
  /** Badge variant (maps the backend tone → our Badge family). */
  variant: StatusDescriptor['variant'];
  /** `next`-registry icon. */
  icon: IconName;
}

interface PriorityMeta {
  i18nKey: string;
  /** Badge variant family for the priority chip. */
  tone: StatusDescriptor['variant'];
  icon: IconName;
}

// Backend tone → our Badge variant: to_do=neutral, in_progress=primary,
// in_test=warning, done=success, archive=neutral, trash=neutral. Icons are mapped
// to the closest available `next` icon (the backend's own icon names are not in
// the next registry).
const STATUS_META: Record<TaskStatus, StatusMeta> = {
  to_do: { i18nKey: 'tasks.statuses.to_do', variant: 'neutral', icon: 'help-circle' },
  in_progress: { i18nKey: 'tasks.statuses.in_progress', variant: 'primary', icon: 'loader' },
  in_test: { i18nKey: 'tasks.statuses.in_test', variant: 'warning', icon: 'info' },
  done: { i18nKey: 'tasks.statuses.done', variant: 'success', icon: 'check-circle' },
  archive: { i18nKey: 'tasks.statuses.archive', variant: 'neutral', icon: 'inbox' },
  trash: { i18nKey: 'tasks.statuses.trash', variant: 'neutral', icon: 'trash' },
};

const PRIORITY_META: Record<TaskPriority, PriorityMeta> = {
  urgent: { i18nKey: 'tasks.priorities.urgent', tone: 'danger', icon: 'alert-triangle' },
  high: { i18nKey: 'tasks.priorities.high', tone: 'warning', icon: 'chevron-up' },
  medium: { i18nKey: 'tasks.priorities.medium', tone: 'primary', icon: 'minus' },
  low: { i18nKey: 'tasks.priorities.low', tone: 'neutral', icon: 'chevron-down' },
};

export function statusMeta(status: TaskStatus): StatusMeta {
  return STATUS_META[status];
}

// NOTE: the set of statuses to offer is NOT computed client-side — the server
// returns the authoritative `available_status_transitions` on TaskResource
// (computed via `TaskStatus::canSetOn`), and the detail drawer renders exactly
// those. Don't reintroduce a client-side mirror of the transition rules here.

export function priorityMeta(priority: TaskPriority): PriorityMeta {
  return PRIORITY_META[priority];
}

/**
 * Build a StatusBadge `statusMap` entry for a task status, using the localized
 * label resolved by the caller (so language switches re-render it).
 */
export function statusDescriptor(status: TaskStatus, label: string): StatusDescriptor {
  const meta = STATUS_META[status];
  return {
    label,
    variant: meta.variant,
    tone: 'subtle',
    icon: meta.icon,
  };
}
