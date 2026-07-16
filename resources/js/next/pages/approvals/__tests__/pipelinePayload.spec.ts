// Unit tests for the pure pipeline-stage payload builder (Batch 3). Guards the
// approver branch: a `user` stage sends the user id, a `bot` stage sends the bot
// id, an `ai` stage sends approver_id: null. The drawer clears approver_id on a
// type switch, so the builder also tolerates a null id on a user/bot stage.
import { describe, expect, it } from 'vitest';
import { buildStagePayload } from '../pipelinePayload';

describe('buildStagePayload', () => {
  it('sends the user id for a USER stage', () => {
    const p = buildStagePayload({ name: 'Review', approver_type: 'user', approver_id: 'u1' });
    expect(p.approver_type).toBe('user');
    expect(p.approver_id).toBe('u1');
  });

  it('sends the bot id for a BOT stage', () => {
    const p = buildStagePayload({ name: 'AI gate', approver_type: 'bot', approver_id: 'b1' });
    expect(p.approver_type).toBe('bot');
    expect(p.approver_id).toBe('b1');
  });

  it('sends approver_id: null for an AI stage (even if a stale id lingers)', () => {
    const p = buildStagePayload({ name: 'Auto', approver_type: 'ai', approver_id: 'stale' });
    expect(p.approver_type).toBe('ai');
    expect(p.approver_id).toBeNull();
  });

  it('keeps approver_id null on a user/bot stage when nothing is picked yet', () => {
    expect(buildStagePayload({ name: 'x', approver_type: 'user', approver_id: null }).approver_id).toBeNull();
    expect(buildStagePayload({ name: 'x', approver_type: 'bot' }).approver_id).toBeNull();
  });

  it('trims the name + description and nulls an empty description', () => {
    const p = buildStagePayload({
      name: '  Review  ',
      description: '   ',
      approver_type: 'ai',
    });
    expect(p.name).toBe('Review');
    expect(p.description).toBeNull();
  });

  it('passes through the icon (or null)', () => {
    expect(buildStagePayload({ name: 'x', icon: 'flag', approver_type: 'ai' }).icon).toBe('flag');
    expect(buildStagePayload({ name: 'x', icon: '', approver_type: 'ai' }).icon).toBeNull();
  });
});
