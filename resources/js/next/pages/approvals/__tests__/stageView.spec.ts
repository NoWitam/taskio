import { describe, it, expect } from 'vitest';
import { buildStageViews } from '../stageView';
import type { ApprovalStage } from '../types';
import type { ApprovalProcess, ApprovalProcessStatus } from '../queue-types';
import type { RunHistoryGroup } from '../../../app/stores/approvalQueue';

function stage(id: string, order: number): ApprovalStage {
  return { id, name: `Stage ${order}`, icon: null, description: null, approver_type: 'user', order };
}

function proc(id: string, status: ApprovalProcessStatus, s: ApprovalStage): ApprovalProcess {
  return {
    id,
    run_id: 'run',
    status,
    note: null,
    approver_type: 'user',
    decided_at: null,
    created_at: null,
    stage: { ...s },
  };
}

function group(s: ApprovalStage, processes: ApprovalProcess[]): RunHistoryGroup {
  return { stageId: s.id, stageName: s.name, order: s.order, processes };
}

describe('buildStageViews', () => {
  const s1 = stage('s1', 1);
  const s2 = stage('s2', 2);
  const s3 = stage('s3', 3);

  it('classifies stages as done / current / upcoming around the current order', () => {
    const groups = [group(s1, [proc('p1', 'approved', s1)])];
    const views = buildStageViews([s1, s2, s3], groups, 2);

    expect(views.map((v) => v.state)).toEqual(['done', 'current', 'upcoming']);
    expect(views[0].outcome).toBe('approved');
    expect(views[1].outcome).toBeNull();
  });

  it('sorts by stage order regardless of input order', () => {
    const views = buildStageViews([s3, s1, s2], [], 1);
    expect(views.map((v) => v.stage.id)).toEqual(['s1', 's2', 's3']);
  });

  it('treats a rejection as the terminal outcome over an approval in the same stage', () => {
    const groups = [group(s1, [proc('a', 'approved', s1), proc('b', 'rejected', s1)])];
    const views = buildStageViews([s1], groups, 2);
    expect(views[0].outcome).toBe('rejected');
  });

  it('with a null current order, decided stages read as done and the rest as upcoming', () => {
    const groups = [group(s1, [proc('p1', 'approved', s1)])];
    const views = buildStageViews([s1, s2], groups, null);
    expect(views.map((v) => v.state)).toEqual(['done', 'upcoming']);
  });
});
