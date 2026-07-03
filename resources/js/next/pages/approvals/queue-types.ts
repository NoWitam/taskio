// Approvals QUEUE + decisions domain types for the isolated "next" frontend
// (Batch 2: Queue + decisions). A SIBLING of `./types.ts` (Batch 1: Pipelines) —
// kept separate so the pipeline types stay focused.
//
// These MIRROR the VERIFIED backend contract 1:1 — no invented fields:
//   • ApprovalQueueItemResource (GET /approvals/queue — cursorPaginate(8))
//   • ApprovalProcessResource   (GET /approvals/processes/{process},
//                                GET /approvals/runs/{runId} as a collection)
//   • GET /approvals/queue/count → { count }
//   • POST /approvals/processes/{process}/decide → ApprovalProcessResource
//
// Response wrapping: NO Approvals resource declares a `data` key, so Laravel's
// default wrapper kicks in — EVERY body is `{ data: ... }`. The store reads a
// single resource as `res.data` and a collection as `res.data` (array) +
// `res.meta`. The queue's `meta.total` is present ONLY on the FIRST page (no
// `cursor` in the request).
//
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/approvals.ts` + `types/approvals.ts` are reference only).
import type { ApproverType } from './types';
import type { ApprovalPipeline } from './types';
import type { ApprovalUser } from './types';
import type { ApproverIdentity } from './types';
import type { FormElement } from '../forms/types';
import type { EntityDescription } from './entityDescription';

export type { ApproverType } from './types';

/** The approval process status enum (`ApprovalProcessStatus`). */
export type ApprovalProcessStatus = 'pending' | 'approved' | 'rejected';

// --- Queue item sub-shapes (ApprovalQueueItemResource) ---------------------

/** The process facet embedded in a queue item (compact — not the full resource). */
export interface QueueItemProcess {
  id: string;
  run_id: string;
  status: ApprovalProcessStatus;
  approver_type: ApproverType;
  /** NEW (Batch 3) polymorphic approver identity — User | Bot | null (ai/unresolved). */
  approver_identity?: ApproverIdentity | null;
  created_at: string | null;
}

/** The pipeline facet embedded in a queue item. */
export interface QueueItemPipeline {
  id: string | null;
  name: string | null;
  /** Legacy IconEnum value (NOT a `next` icon name) or null. */
  icon: string | null;
}

/** The current-stage facet embedded in a queue item. */
export interface QueueItemStage {
  id: string | null;
  name: string | null;
  icon: string | null;
  description: string | null;
  order: number | null;
}

/** One labelled extra field rendered as a chip / list row on the entity. */
export interface EntityExtraField {
  label: string;
  value: string;
  /** Legacy IconEnum value or null. */
  icon: string | null;
}

/**
 * The entity's form preview payload: the element tree (`content`) plus the
 * existing nested `submission` to hydrate the read-only FormViewer.
 */
export interface EntityForm {
  id: string;
  name: string;
  /** FormViewer `content` — the element tree. */
  content: FormElement[];
  /** FormViewer `initialData` — the nested submission (may be null). */
  submission: Record<string, unknown> | null;
}

/**
 * The approvable entity facet (null when the model does not implement
 * Approvable). Mirrors `Approvable::toApprovalQueueItem()`.
 */
export interface QueueItemEntity {
  id: string | number;
  type: string;
  type_label: string;
  type_icon: string | null;
  name: string;
  /**
   * The entity's description — a ProseMirror doc OBJECT for Tasks, a plain string
   * for others, or null. Render via `entityDescriptionToText` (never raw).
   */
  description: EntityDescription;
  extra_fields: EntityExtraField[];
  form: EntityForm | null;
  comments_url: string | null;
}

/** A single queue row (ApprovalQueueItemResource). */
export interface ApprovalQueueItem {
  process: QueueItemProcess;
  pipeline: QueueItemPipeline;
  stage: QueueItemStage;
  /** null when the entity does not implement Approvable. */
  entity: QueueItemEntity | null;
}

// --- Process detail (ApprovalProcessResource) ------------------------------

/**
 * A full approval process (ApprovalProcessResource) — the decision detail. The
 * pipeline/stage/approver are conditionally loaded (`whenLoaded`) so they may be
 * absent depending on the endpoint.
 */
export interface ApprovalProcess {
  id: string;
  run_id: string;
  status: ApprovalProcessStatus;
  note: string | null;
  approver_type: ApproverType;
  /** `whenLoaded('approver')` — present when approver_type === 'user'. Back-compat. */
  approver?: ApprovalUser | null;
  /**
   * NEW (Batch 3) polymorphic approver identity — User | Bot | null. PREFERRED for
   * rendering (use `resolveApprover`, falling back to the legacy `approver`).
   */
  approver_identity?: ApproverIdentity | null;
  /** `whenLoaded('pipeline')` — the full pipeline incl. stages. */
  pipeline?: ApprovalPipeline | null;
  /** `whenLoaded('stage')` — the stage this process targets (may be null FK). */
  stage?: ApprovalProcessStage | null;
  decided_at: string | null;
  created_at: string | null;
}

/**
 * The stage embedded in an ApprovalProcessResource (ApprovalStageResource). The
 * FK can be null on HISTORICAL processes (re-saving a pipeline rewrites stages
 * wholesale and nulls old stage FKs) — callers MUST tolerate `stage === null`.
 */
export interface ApprovalProcessStage {
  id: string;
  name: string;
  icon: string | null;
  description: string | null;
  approver_type: ApproverType;
  /** NEW (Batch 3) polymorphic approver identity — User | Bot | null. */
  approver_identity?: ApproverIdentity | null;
  order: number;
}

// --- Envelopes -------------------------------------------------------------

/**
 * Cursor-paginated list envelope from `/approvals/queue`. Meta carries the cursor
 * fields PLUS `total` — but `total` is present ONLY on the first page (no
 * `cursor`); the store captures it on the initial fetch and never overwrites it.
 */
export interface QueueListMeta {
  next_cursor: string | null;
  /** Count of MY pending items — first page only. */
  total?: number;
}
export interface QueueListResponse {
  data: ApprovalQueueItem[];
  meta: QueueListMeta;
}

/** `{ count }` envelope from `GET /approvals/queue/count`. */
export interface QueueCountResponse {
  count: number;
}

/** Single-resource envelope from `GET /approvals/processes/{process}` + decide. */
export interface ApprovalProcessResponse {
  data: ApprovalProcess;
}

/** Collection envelope from `GET /approvals/runs/{runId}` (full run history). */
export interface RunHistoryResponse {
  data: ApprovalProcess[];
}

// --- Decision payload (DecideRequest) --------------------------------------

/**
 * The decide body. Mirrors `DecideRequest` 1:1:
 *   decision (req `approved|rejected`), note (nullable, required_if
 *   decision=rejected, ≤2500).
 */
export interface DecisionPayload {
  decision: 'approved' | 'rejected';
  note?: string | null;
}

/**
 * The structured error the store surfaces when a decision is no longer possible
 * (422 — the process was already decided / is no longer pending) or when the
 * client-side `required_if` note guard trips, so the UI can translate it via its
 * own i18n catalog instead of relying on the server string.
 */
export interface DecisionError {
  /** Discriminant the UI checks before translating. */
  kind: 'already_decided' | 'note_required';
  /** The FRONTEND i18n key the UI passes to `t()` to render the message. */
  messageKey: string;
}
