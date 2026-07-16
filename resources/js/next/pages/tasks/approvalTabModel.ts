// approvalTabModel — the PURE adapter behind the task detail's Approval tab.
//
// Extracted from TaskDetailsDrawer so the per-stage status logic is unit-testable
// WITHOUT mounting the Drawer. Given the attached pipeline's stages, the task's
// current pending process (or null), and the grouped run history (from the shared
// `groupRunHistory` helper), it produces an ordered stepper view-model: one row
// per pipeline stage, each with a resolved status + the decision entries that
// landed on it.
//
// Reuses the verified Approvals domain types (do NOT redefine): ApprovalStage from
// pages/approvals/types, ApprovalProcess + ApprovalProcessStatus from
// pages/approvals/queue-types, and RunHistoryGroup from the queue store's pure
// grouping helper. Cross-page import (tasks → approvals) is acceptable here.
import type { ApprovalStage } from '../approvals/types';
import type {
  ApprovalProcess,
  ApprovalProcessStatus,
} from '../approvals/queue-types';
import type { RunHistoryGroup } from '../../app/stores/approvalQueue';

/**
 * The resolved status of a single stage row in the stepper:
 *   • `pending`  — the CURRENT stage awaiting a decision (highlighted).
 *   • `approved` / `rejected` — a stage already DECIDED (status from its latest
 *     history entry).
 *   • `upcoming` — a future stage not yet reached (no decision, not current).
 */
export type StageStatus = ApprovalProcessStatus | 'upcoming';

/** One row in the stepper view-model — a pipeline stage + its resolved state. */
export interface StageStep {
  /** Stage id (stable key). */
  id: string;
  name: string;
  /** Backend IconEnum value (NOT a next icon name) or null — resolved at render. */
  icon: string | null;
  description: string | null;
  approver_type: ApprovalStage['approver_type'];
  /** The stage's human approver (when `approver_type === 'user'`), else null. Back-compat. */
  approver: ApprovalStage['approver'];
  /**
   * NEW (Batch 3) polymorphic approver identity — User | Bot | null. PREFERRED for
   * rendering (resolve via `resolveApprover`, which falls back to `approver`).
   */
  approver_identity: ApprovalStage['approver_identity'];
  order: number;
  /** Resolved per-stage status: pending / approved / rejected / upcoming. */
  status: StageStatus;
  /** True for the CURRENT pending stage (drives the highlight). */
  isCurrent: boolean;
  /**
   * The decision entries (ApprovalProcess[]) that targeted this stage, newest
   * first — surfaced under the row as the per-stage history (approver/note/time).
   * Empty for upcoming/pending stages with no recorded decisions.
   */
  history: ApprovalProcess[];
}

/** The full stepper view-model the Approval tab renders. */
export interface ApprovalTabModel {
  /** One row per pipeline stage, in `order`. */
  steps: StageStep[];
  /** The id of the current pending stage (highlight target), or null. */
  currentStageId: string | null;
  /**
   * Run-history decision entries whose stage FK was nulled (the pipeline was
   * re-saved) — they cannot be attached to a current stage, so they are surfaced
   * separately so nothing is silently dropped. Newest first.
   */
  orphanHistory: ApprovalProcess[];
}

/** Newest-first sort by `decided_at` then `created_at` (best-effort, stable). */
function byRecencyDesc(a: ApprovalProcess, b: ApprovalProcess): number {
  const at = a.decided_at ?? a.created_at ?? '';
  const bt = b.decided_at ?? b.created_at ?? '';
  if (at === bt) return 0;
  return at < bt ? 1 : -1;
}

/**
 * Build the stepper view-model.
 *
 * @param stages   The attached pipeline's stages (any order — sorted here by `order`).
 * @param pending  The task's CURRENT pending process, or null when not in approval.
 * @param groups   The grouped run history (from `groupRunHistory`), or [] when none.
 */
export function buildApprovalTabModel(
  stages: ApprovalStage[],
  pending: ApprovalProcess | null | undefined,
  groups: RunHistoryGroup[] = [],
): ApprovalTabModel {
  const currentStageId = pending?.stage?.id ?? null;

  // Index history by the stage it targeted so each step can pick up its decisions.
  const historyByStage = new Map<string, ApprovalProcess[]>();
  let orphanHistory: ApprovalProcess[] = [];
  for (const group of groups) {
    if (group.stageId == null) {
      orphanHistory = [...orphanHistory, ...group.processes];
    } else {
      historyByStage.set(group.stageId, [...group.processes].sort(byRecencyDesc));
    }
  }
  orphanHistory = orphanHistory.sort(byRecencyDesc);

  // Among the pipeline stages, find the position of the current pending stage so
  // stages BEFORE it (with no decision) still read as past, not upcoming — but the
  // primary signal is always the decision history + the pending pointer.
  const ordered = [...stages].sort((a, b) => a.order - b.order);

  const steps: StageStep[] = ordered.map((stage) => {
    const history = historyByStage.get(stage.id) ?? [];
    const isCurrent = currentStageId != null && stage.id === currentStageId;

    let status: StageStatus;
    if (isCurrent) {
      // The stage awaiting a decision right now.
      status = 'pending';
    } else if (history.length > 0) {
      // Decided: take the latest recorded decision's status. A non-terminal
      // (still pending) latest entry is surfaced as pending too.
      status = history[0]!.status;
    } else {
      // No decision recorded and not the current stage → not yet reached.
      status = 'upcoming';
    }

    return {
      id: stage.id,
      name: stage.name,
      icon: stage.icon,
      description: stage.description,
      approver_type: stage.approver_type,
      approver: stage.approver ?? null,
      approver_identity: stage.approver_identity ?? null,
      order: stage.order,
      status,
      isCurrent,
      history,
    };
  });

  return { steps, currentStageId, orphanHistory };
}
