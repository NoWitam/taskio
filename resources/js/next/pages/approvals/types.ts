// Approvals domain types for the isolated "next" frontend (Batch 1: Pipelines).
//
// These MIRROR the VERIFIED backend contract 1:1 — no invented fields:
//   • ApprovalPipelineListResource (GET /approval-pipelines — cursorPaginate(8))
//   • ApprovalPipelineResource     (GET /approval-pipelines/{id})
//   • StageResource                (nested in the detail resource)
//   • StoreApprovalPipelineRequest / UpdateApprovalPipelineRequest (the write body)
//
// IMPORTANT response wrapping: NO Approvals resource declares a `data` key, so
// Laravel's default wrapper kicks in — EVERY response body is `{ data: ... }`.
// The store reads a single resource as `res.data` and a collection as
// `res.data` (array) + `res.meta` (cursor fields ONLY — there is NO `total`).
//
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/approvals.ts` + `types/approvals.ts` are reference only).

/** A user as returned by UserResource (the stage `approver` + pipeline `creator`). */
export interface ApprovalUser {
  id: string | number;
  name: string;
  email?: string | null;
  avatar?: string | null;
}

/** The approver type enum (`ApproverType`). Icons: user / sparkles / sparkles. */
export type ApproverType = 'user' | 'ai' | 'bot';

/**
 * The NEW polymorphic approver identity (Batch 3), emitted ADDITIVELY as
 * `approver_identity` on stages, processes, and the queue process block. Resolves
 * the approver to a tagged User OR Bot, or null for a generic `ai` stage / an
 * unresolved relation (a former member). PREFERRED over the legacy `approver`
 * UserResource for rendering (see `resolveApprover`). Mirrors ApproverResource 1:1:
 *   user → { type:'user', id, name, email:string|null, avatar:null, is_bot:false }
 *   bot  → { type:'bot',  id, name, email:null,        avatar:null, is_bot:true  }
 *   ai / unresolved → null
 */
export interface ApproverIdentity {
  type: 'user' | 'bot';
  id: string | number;
  name: string;
  email: string | null;
  avatar: string | null;
  is_bot: boolean;
}

/** A pipeline stage as returned by StageResource (the DETAIL resource). */
export interface ApprovalStage {
  id: string;
  name: string;
  /** Legacy IconEnum value (NOT a `next` icon name) or null. */
  icon: string | null;
  description: string | null;
  approver_type: ApproverType;
  /** `whenLoaded('approver')` — present when approver_type === 'user'. Back-compat. */
  approver?: ApprovalUser | null;
  /**
   * NEW (Batch 3) polymorphic approver identity — User | Bot | null. PREFERRED for
   * rendering (use `resolveApprover`, which falls back to the legacy `approver`).
   */
  approver_identity?: ApproverIdentity | null;
  /** The stage's position; the server derives it from the array index. */
  order: number;
}

/**
 * A lightweight stage summary as embedded in the LIST resource
 * (ApprovalPipelineListResource exposes `stages: [{ name, icon, order }]`).
 */
export interface ApprovalStageSummary {
  name: string;
  icon: string | null;
  order: number;
}

/** Capability + ownership flags shared by both pipeline resources. */
interface PipelineFlags {
  /** The current user created this pipeline. */
  is_owner: boolean;
  /** No active (pending) processes → editing is allowed. */
  can_be_edited: boolean;
  /** No active (pending) processes → deletion is allowed. */
  can_be_deleted: boolean;
}

/** A pipeline LIST row (ApprovalPipelineListResource). */
export interface ApprovalPipelineListItem extends PipelineFlags {
  id: string;
  name: string;
  /** Legacy IconEnum value or null. */
  icon: string | null;
  description: string | null;
  /** `whenCounted('stages')` — may be absent; fall back to `stages.length`. */
  stages_count?: number;
  /** A compact stage summary for the card (name/icon/order only). */
  stages: ApprovalStageSummary[];
  created_at: string | null;
}

/** The FULL pipeline (ApprovalPipelineResource) — adds full stages + creator. */
export interface ApprovalPipeline extends PipelineFlags {
  id: string;
  name: string;
  icon: string | null;
  description: string | null;
  stages: ApprovalStage[];
  /** `whenLoaded('creator')`. */
  creator?: ApprovalUser | null;
  created_at: string | null;
}

// --- List query / envelopes ------------------------------------------------

/**
 * The list-screen filter state. Mirrors the `/approval-pipelines` query params
 * 1:1 — the ONLY server filter is `search` (matches name). The page owns this;
 * the store serializes it.
 */
export interface PipelineFilters {
  search?: string;
}

/**
 * Cursor-paginated list envelope from `/approval-pipelines`. Meta carries cursor
 * fields ONLY — there is NO `total` (cursorPaginate(8)).
 */
export interface PipelineListMeta {
  next_cursor: string | null;
}
export interface PipelineListResponse {
  data: ApprovalPipelineListItem[];
  meta: PipelineListMeta;
}

/** Detail envelope from `GET /approval-pipelines/{id}` + the create/update calls. */
export interface PipelineDetailResponse {
  data: ApprovalPipeline;
}

// --- Write payload (StoreApprovalPipelineRequest / UpdateApprovalPipelineRequest)

/**
 * A single stage in the write payload. Mirrors `stages.*` validation 1:1:
 *   name (req ≤255), icon (nullable ≤50), description (nullable ≤2500),
 *   approver_type (req `user|ai|bot`), approver_id (nullable, REQUIRED when
 *   approver_type=user (a users uuid) OR =bot (a bots uuid); `ScopedExists`
 *   validates it server-side). For `ai`, send `approver_id: null`.
 * `order` is the array index — the server derives it (do NOT send it).
 */
export interface PipelineStagePayload {
  name: string;
  icon?: string | null;
  description?: string | null;
  approver_type: ApproverType;
  approver_id?: string | null;
}

/**
 * The pipeline write body. Mirrors the FormRequest 1:1:
 *   name (req ≤255), icon (nullable ≤50), description (nullable ≤2500),
 *   stages (req array, min 1). The server rewrites stages wholesale on update.
 */
export interface PipelineWritePayload {
  name: string;
  icon?: string | null;
  description?: string | null;
  stages: PipelineStagePayload[];
}

/**
 * The structured error the store surfaces for the 422 "pipeline has active
 * processes" response (detected by the `pipeline` validation field) so the UI can
 * translate it via its own i18n catalog instead of relying on the server string.
 */
export interface PipelineActiveProcessesError {
  /** Discriminant the UI checks before translating. */
  kind: 'active_processes';
  /** The FRONTEND i18n key the UI passes to `t()` to render the localized message. */
  messageKey: string;
}
