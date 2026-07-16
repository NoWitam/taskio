// pipelinePayload — the pure builder for an approval-pipeline stage's write
// payload (Batch 3). Extracted from PipelineBuilderDrawer so the approver-branch
// logic (user | ai | bot) is unit-testable without mounting the drawer.
//
// Contract (mirrors `stages.*` validation): `approver_id` is sent for a `user`
// stage (a user uuid) OR a `bot` stage (a bot uuid), and is null for an `ai`
// stage. `order` is the array index — the server derives it, so it is NOT sent.
import type { ApproverType, PipelineStagePayload } from './types';

/** The local stage-draft fields the builder reads (a subset of StageDraft). */
export interface StagePayloadInput {
  name: string;
  icon?: string | null;
  description?: string | null;
  approver_type: ApproverType;
  approver_id?: string | null;
}

/**
 * Build ONE stage's contract payload. The approver branch:
 *   • `ai`         → approver_id: null (no named approver).
 *   • `user`/`bot` → approver_id: the selected uuid (or null when not yet picked,
 *     which the server then rejects via required_if / ScopedExists).
 * Trims name/description; an empty description becomes null.
 */
export function buildStagePayload(stage: StagePayloadInput): PipelineStagePayload {
  return {
    name: stage.name.trim(),
    icon: stage.icon || null,
    description: stage.description?.trim() || null,
    approver_type: stage.approver_type,
    approver_id: stage.approver_type === 'ai' ? null : stage.approver_id ?? null,
  };
}
