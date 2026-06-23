// Unit tests for the PURE approval-tab adapter. Verifies the per-stage status
// resolution that drives the task detail's Approval stepper WITHOUT mounting the
// Drawer: no-pipeline, the pending-stage highlight, decided stages from the run
// history, upcoming (not-yet-reached) stages, and tolerance of `stage = null`
// history (orphaned when a pipeline is re-saved).
import { describe, expect, it } from 'vitest';
import { buildApprovalTabModel } from '../approvalTabModel';
import { groupRunHistory } from '../../../app/stores/approvalQueue';
import type { ApprovalStage } from '../../approvals/types';
import type { ApprovalProcess } from '../../approvals/queue-types';

// --- Fixtures --------------------------------------------------------------
function stage(id: string, order: number, over: Partial<ApprovalStage> = {}): ApprovalStage {
  return {
    id,
    name: `Stage ${id}`,
    icon: null,
    description: null,
    approver_type: 'user',
    approver: { id: `u-${id}`, name: `Approver ${id}` },
    order,
    ...over,
  };
}

function proc(
  id: string,
  stageId: string | null,
  status: ApprovalProcess['status'],
  over: Partial<ApprovalProcess> = {},
): ApprovalProcess {
  return {
    id,
    run_id: 'run-1',
    status,
    note: null,
    approver_type: 'user',
    approver: { id: `u-${id}`, name: `Decider ${id}` },
    stage: stageId
      ? { id: stageId, name: `Stage ${stageId}`, icon: null, description: null, approver_type: 'user', order: 0 }
      : null,
    decided_at: status === 'pending' ? null : '2026-06-01T10:00:00Z',
    created_at: '2026-06-01T09:00:00Z',
    ...over,
  };
}

describe('buildApprovalTabModel', () => {
  it('returns no steps when the pipeline has no stages', () => {
    const model = buildApprovalTabModel([], null, []);
    expect(model.steps).toEqual([]);
    expect(model.currentStageId).toBeNull();
    expect(model.orphanHistory).toEqual([]);
  });

  it('orders stages by `order` regardless of input order', () => {
    const model = buildApprovalTabModel([stage('b', 2), stage('a', 1)], null, []);
    expect(model.steps.map((s) => s.id)).toEqual(['a', 'b']);
  });

  it('highlights the current pending stage and marks it pending', () => {
    const pending = proc('p1', 's2', 'pending');
    const model = buildApprovalTabModel([stage('s1', 1), stage('s2', 2), stage('s3', 3)], pending, []);

    const [s1, s2, s3] = model.steps;
    expect(model.currentStageId).toBe('s2');
    expect(s2.isCurrent).toBe(true);
    expect(s2.status).toBe('pending');
    // No decisions recorded, not current → s1 + s3 are upcoming.
    expect(s1.status).toBe('upcoming');
    expect(s1.isCurrent).toBe(false);
    expect(s3.status).toBe('upcoming');
  });

  it('marks decided stages from the run history (approved/rejected)', () => {
    const history = [proc('d1', 's1', 'approved'), proc('d2', 's2', 'rejected')];
    const groups = groupRunHistory(history);
    const pending = proc('p3', 's3', 'pending');
    const model = buildApprovalTabModel(
      [stage('s1', 1), stage('s2', 2), stage('s3', 3)],
      pending,
      groups,
    );

    const [s1, s2, s3] = model.steps;
    expect(s1.status).toBe('approved');
    expect(s1.history.map((h) => h.id)).toContain('d1');
    expect(s2.status).toBe('rejected');
    expect(s3.status).toBe('pending');
    expect(s3.isCurrent).toBe(true);
  });

  it('leaves not-yet-reached stages upcoming when there is no history or pending', () => {
    const model = buildApprovalTabModel([stage('s1', 1), stage('s2', 2)], null, []);
    expect(model.steps.every((s) => s.status === 'upcoming')).toBe(true);
    expect(model.steps.every((s) => !s.isCurrent)).toBe(true);
  });

  it('tolerates `stage = null` history (orphaned by a pipeline re-save)', () => {
    const history = [proc('d1', 's1', 'approved'), proc('orphan', null, 'rejected')];
    const groups = groupRunHistory(history);
    const model = buildApprovalTabModel([stage('s1', 1)], null, groups);

    // The known-stage decision still lands on its step…
    expect(model.steps[0].status).toBe('approved');
    // …and the orphaned (stage=null) decision is surfaced separately, not dropped.
    expect(model.orphanHistory.map((h) => h.id)).toEqual(['orphan']);
  });

  it('picks the latest decision when a stage has several history entries', () => {
    const history = [
      proc('old', 's1', 'rejected', { decided_at: '2026-06-01T08:00:00Z' }),
      proc('new', 's1', 'approved', { decided_at: '2026-06-02T08:00:00Z' }),
    ];
    const groups = groupRunHistory(history);
    const model = buildApprovalTabModel([stage('s1', 1)], null, groups);

    // Newest-first → the step shows the most recent decision's status.
    expect(model.steps[0].status).toBe('approved');
    expect(model.steps[0].history[0].id).toBe('new');
  });
});
