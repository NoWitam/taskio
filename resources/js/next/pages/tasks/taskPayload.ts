// taskPayload — the pure builder for the Task create/update write payload.
//
// Extracted so both TaskFormModal (create/edit) and TaskDetailsDrawer (inline
// edits) build an IDENTICAL, contract-correct body, and so the form-link behavior
// is unit-testable without mounting a component.
//
// GOTCHA encoded here (verified against StoreTasksRequest + TaskDTO): the DTO
// coerces an ABSENT `form_id` to `null` on update, so omitting it would silently
// CLEAR the attached form. We therefore ALWAYS emit `form_id` as `string | null`.
// `approval_pipeline_id` has the SAME trap, so since Batch 2 added the pipeline
// picker it is ALSO an explicit `string | null` input — every caller must pass
// it. The MODAL passes its picker value; the detail DRAWER (no picker) ECHOES the
// task's own value. Either way the field is always present, so a cleared picker
// detaches and an inline metadata edit never silently wipes the link. Mirrors the
// description doc/markdown boundary via the caller-supplied, already-converted
// `description` value.
import type { TaskPriority, TaskWritePayload } from './types';

/** The fields a screen collects + the links it must preserve/echo. */
export interface BuildTaskPayloadInput {
  title: string;
  /** Already converted to the backend doc-string (or null) by the caller. */
  description?: string | null;
  priority: TaskPriority;
  deadline?: string | null;
  assigned_id: string | null;
  labels?: string[];
  /** Temp file ids of NEW uploads only (additive on the backend). */
  attachments?: string[];
  /** The form-picker value (or the task's echoed form_id when there is no picker). */
  form_id?: string | null;
  /**
   * The pipeline-picker value (modal) or the task's echoed approval_pipeline_id
   * (drawer). Explicit `string | null` — a cleared picker detaches; an absent link
   * sends null instead of being silently preserved by the DTO.
   */
  approval_pipeline_id?: string | null;
}

/**
 * Build the create/update payload. `form_id` AND `approval_pipeline_id` are ALWAYS
 * present (string|null) so a cleared picker / absent link reliably DETACHES rather
 * than being silently preserved by the DTO. Both come from explicit caller input:
 * the modal passes its picker values, the drawer echoes the loaded task's values.
 */
export function buildTaskPayload(input: BuildTaskPayloadInput): TaskWritePayload {
  return {
    title: input.title.trim(),
    description: input.description ?? null,
    priority: input.priority,
    deadline: input.deadline ?? null,
    assigned_id: input.assigned_id ?? '',
    labels: input.labels ?? [],
    attachments: input.attachments ?? [],
    form_id: input.form_id ?? null,
    approval_pipeline_id: input.approval_pipeline_id ?? null,
  };
}
