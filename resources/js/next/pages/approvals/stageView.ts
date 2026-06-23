// Stage view-model for the Approvals → review drawer (next).
//
// Folds a pipeline's stages together with the run-history groups into a single
// ordered list the header stepper AND the right-pane timeline both render, so the
// "where am I in the pipeline" logic lives in ONE place (and is unit-testable):
//
//   • state    — `done` (a prior stage, already decided), `current` (the stage the
//                process under review sits on), or `upcoming` (a later, locked
//                stage). Derived from the current process's stage `order`.
//   • outcome  — the stage's terminal decision when decided: a rejection ends the
//                run, otherwise an approval, otherwise null (no decision yet).
//   • processes — that stage's decisions pulled from the grouped run history.
//
// Tolerates a null `currentOrder` (deep-link / not-yet-loaded): stages with a
// decision read as `done`, the rest as `upcoming`.
import type { ApprovalStage } from './types';
import type { ApprovalProcess, ApprovalProcessStatus } from './queue-types';
import type { RunHistoryGroup } from '../../app/stores/approvalQueue';

export type StageState = 'done' | 'current' | 'upcoming';

export interface StageView {
  stage: ApprovalStage;
  state: StageState;
  /** Terminal decision for this stage, or null when not yet decided. */
  outcome: ApprovalProcessStatus | null;
  /** Decisions recorded against this stage (from the grouped run history). */
  processes: ApprovalProcess[];
}

/** A rejection ends a run, so it wins; otherwise an approval; otherwise undecided. */
function terminalOutcome(processes: ApprovalProcess[]): ApprovalProcessStatus | null {
  if (processes.some((p) => p.status === 'rejected')) return 'rejected';
  if (processes.some((p) => p.status === 'approved')) return 'approved';
  return null;
}

export function buildStageViews(
  stages: ApprovalStage[],
  groups: RunHistoryGroup[],
  currentOrder: number | null,
): StageView[] {
  const byStageId = new Map(
    groups.filter((g) => g.stageId != null).map((g) => [g.stageId as string, g]),
  );

  return [...stages]
    .sort((a, b) => a.order - b.order)
    .map((stage) => {
      const processes = byStageId.get(stage.id)?.processes ?? [];
      const outcome = terminalOutcome(processes);
      const state: StageState =
        currentOrder == null
          ? outcome
            ? 'done'
            : 'upcoming'
          : stage.order < currentOrder
            ? 'done'
            : stage.order === currentOrder
              ? 'current'
              : 'upcoming';
      return { stage, state, outcome, processes };
    });
}
