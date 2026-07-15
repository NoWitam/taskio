// Forms domain types for the isolated "next" frontend.
//
// These MIRROR the verified backend contract (FormListResource / FormResource,
// FormSubmissionResource / FormReportResource, the FormElementType enum + the
// element `content` schema validated by ValidFormContent, and the `/api/forms`
// list query params) — no invented fields. Self-contained: NO import from the
// legacy `resources/js/` (the legacy `store/forms.ts` is reference only).
//
// NOTE: a Form `description` is a PLAIN string column (max 1000), and `content`
// is a plain array (the element tree). Unlike Tasks there is NO ProseMirror /
// MarkdownTreeCast boundary here — no doc↔markdown conversion is needed.

// The polymorphic `creator` union (user | workflow_run | bot) is shared across
// every resource that emits it — imported, never redefined.
import type { Creator } from '../../ui/patterns/creator';

/**
 * A user as returned by UserResource. Retained for reference; the `creator`
 * relation is now the polymorphic `Creator` union (Phase 3), not a bare user.
 */
export interface FormUser {
  id: string | number;
  name: string;
  email?: string | null;
  avatar?: string | null;
}

/**
 * The shared fields present on BOTH FormListResource and FormResource. The list
 * resource returns exactly these; the detail resource adds `content`.
 */
export interface FormBase {
  id: string;
  name: string;
  /** Legacy IconEnum value (NOT a `next` icon name) or null. */
  icon: string | null;
  description: string | null;
  is_anonymous: boolean;

  // Activation status.
  enabled_at: string | null;
  is_enabled: boolean;

  // Index status.
  indexed_at: string | null;
  is_indexed: boolean;
  is_indexing: boolean;

  // Versioning.
  content_version: number;
  content_updated_at: string | null;

  // Centralized capability flags (server-authoritative; drive the UI gating).
  can_be_edited: boolean;
  can_be_filled: boolean;
  can_be_enabled: boolean;
  can_be_disabled: boolean;
  can_be_indexed: boolean;
  can_be_unindexed: boolean;
  can_restore_index: boolean;
  has_index_backup: boolean;
  is_draft: boolean;

  // Filter & reporting capabilities (opaque here; used in later batches).
  available_filters?: unknown;
  reporting_mode?: string | null;

  /**
   * `whenLoaded('creator')` → present on the list query (with('creator')).
   * Polymorphic (Phase 3): a User, a workflow_run (automation), or a Bot — or
   * null. Render via `CreatorBadge` / the `creator` helpers.
   */
  creator?: Creator | null;
  /** `whenCounted('submissions')` → may be absent; treat as 0. */
  submissions_count?: number;
  created_at: string | null;
  updated_at: string | null;
}

/** A form list row (FormListResource). */
export type FormSummary = FormBase;

/** The FULL form (FormResource) — adds the element tree `content`. */
export interface FormDetail extends FormBase {
  content: FormElement[];
}

// --- Element schema (the form `content`) ----------------------------------
// Verified against `FormElementType` + `ValidFormContent` + the legacy builder
// and FormViewer. Each element is `{ id, type, config }`; `config` shape varies
// by type. The full per-type config is exercised by the Batch-2 builder; this
// batch only reads/serializes `content` opaquely, so configs are loosely typed.

export type FormElementType =
  // Layout.
  | 'section'
  | 'grid'
  | 'repeater'
  // Content.
  | 'heading'
  | 'text_block'
  | 'divider'
  // Input fields.
  | 'short_text'
  | 'long_text'
  | 'select'
  | 'image'
  | 'checkbox'
  | 'number'
  | 'date'
  | 'time'
  | 'url'
  | 'checklist';

/** An option for `select` / `checklist` inputs. */
export interface FormElementOption {
  value: string;
  label: string;
  icon?: string;
  hint?: string;
}

/**
 * Loosely-typed element config (the Batch-2 builder narrows this per type). The
 * known keys mirror `ValidFormContent` + the legacy ElementEditor 1:1.
 */
export interface FormElementConfig {
  // Layout (section / repeater).
  name?: string;
  icon?: string;
  description?: string;
  children?: FormElement[];
  // Repeater bounds.
  min?: number;
  max?: number;
  // Grid.
  columns?: Array<{ width: 25 | 50 | 75 | 100; element: FormElement | null }>;
  // Content.
  level?: 1 | 2 | 3;
  text?: string;
  content?: string;
  // Input common.
  label?: string;
  placeholder?: string;
  hint?: string;
  required?: boolean;
  // Text inputs.
  minLength?: number;
  maxLength?: number;
  rows?: number;
  // Number.
  step?: number;
  // Select / checklist.
  options?: FormElementOption[];
  multiple?: boolean;
  // Image.
  maxSize?: number;
  acceptedTypes?: string[];
  [key: string]: unknown;
}

export interface FormElement {
  id: string;
  type: FormElementType;
  config: FormElementConfig;
}

// --- List query / envelopes ------------------------------------------------

/**
 * The list-screen filter state. Mirrors the `/api/forms` query params 1:1 (the
 * page owns this; the store serializes it). Booleans are sent as `1`/`0` so the
 * backend `filled()` + `boolean()` checks distinguish "true", "false", and
 * "absent".
 */
export interface FormFilters {
  search?: string;
  /** `onlyTrashed` when true. */
  trashed?: boolean;
  /** Enabled (true) vs draft (false); omit for "all". */
  enabled?: boolean;
  /** Indexed (true) vs not indexed (false); omit for "all". */
  indexed?: boolean;
}

/** Cursor-paginated list envelope from `/api/forms`. */
export interface FormListMeta {
  next_cursor: string | null;
  /** Only present on the first page (no cursor). */
  total?: number | null;
}
export interface FormListResponse {
  data: FormSummary[];
  meta: FormListMeta;
}

/** Detail envelope from `GET /api/forms/{id}` and the lifecycle endpoints. */
export interface FormDetailResponse {
  data: FormDetail;
}

// --- Submissions (FormSubmissionResource) -----------------------------------

/**
 * The submissions-list filter state. Mirrors the `/forms/{id}/submissions` query
 * params 1:1 (verified against `FormSubmissionService::indexByForm`): `search`
 * (over `data`), `sources[]` (morph aliases `task` / `form`), `indexed`
 * (true/false), `sort` (newest/oldest), `trashed` (the bucket tab), and the date
 * filter (`date_preset` / `date_from` / `date_to` over `approved_at`).
 */
export interface SubmissionFilters {
  search?: string;
  sources?: string[];
  indexed?: boolean;
  sort?: 'newest' | 'oldest';
  trashed?: boolean;
  date_preset?: '' | 'today' | 'this_week' | 'last_week' | 'this_month';
  date_from?: string | null;
  date_to?: string | null;
}

export interface FormSubmission {
  id: string;
  form_id: string;
  form?: FormSummary;
  data: Record<string, unknown>;
  /** `submittable_type` (e.g. the Form morph alias or `task`). */
  source: string | null;
  form_content_version_id: string | null;
  indexed_at: string | null;
  approved_at: string | null;
  is_approved: boolean;
  can_be_edited: boolean;
  /** Polymorphic creator (Phase 3): user | workflow_run | bot | null. */
  creator?: Creator | null;
  created_at: string | null;
  updated_at: string | null;
}

// --- Reports (FormReportResource) -------------------------------------------

/** A generated report file (FileResource); `path` is the download URL. */
export interface FormReportFile {
  id: string;
  name: string;
  path: string;
  type?: string | null;
  size?: number | null;
  size_human?: string | null;
  created_at?: string | null;
}

/**
 * The reports-list filter state. Mirrors `FormReportService::indexByForm` 1:1:
 * `search` (name/guidelines), `sort` (newest/oldest), `trashed` (bucket tab),
 * `only_completed` / `only_pending` (status), and the date filter over
 * `created_at` (`date_preset` / `date_from` / `date_to`).
 */
export interface ReportFilters {
  search?: string;
  sort?: 'newest' | 'oldest';
  trashed?: boolean;
  only_completed?: boolean;
  only_pending?: boolean;
  date_preset?: '' | 'today' | 'this_week' | 'last_week' | 'this_month';
  date_from?: string | null;
  date_to?: string | null;
}

export interface FormReport {
  id: string;
  form_id: string;
  form?: FormSummary;
  name: string;
  guidelines: string | null;
  sources: string[] | null;
  sources_formatted: string[];
  /** `Y-m-d`. */
  submissions_from: string;
  /** `Y-m-d`. */
  submissions_to: string;
  /** `dd.mm.yyyy - dd.mm.yyyy`. */
  date_range: string;
  is_completed: boolean;
  completed_at: string | null;
  /** The generated file (present once completed). */
  file?: FormReportFile | null;
  /** Polymorphic creator (Phase 3): user | workflow_run | bot | null. */
  creator?: Creator | null;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/** Index compatibility info (`GET /api/forms/{id}/compatibility`). */
export interface CompatibilityInfo {
  total_submissions: number;
  compatible_count: number;
  incompatible_count: number;
  incompatible_periods: Array<{
    version: number;
    submissions_count: number;
    period_from: string;
    period_to: string;
  }>;
}
