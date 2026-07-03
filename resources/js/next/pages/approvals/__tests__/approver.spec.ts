// Unit tests for the pure polymorphic-approver resolver (Batch 3). Guards the
// rule: PREFER the new `approver_identity` (User|Bot|null), fall back to the legacy
// `approver` UserResource only when the new field is absent; backfill a user
// avatar from `approver`; null (generic AI / unresolved) → caller renders the
// generic "AI reviewer". Also covers the type-icon map (bot → sparkles).
import { describe, expect, it } from 'vitest';
import { resolveApprover, approverTypeIcon } from '../approver';

describe('resolveApprover', () => {
  it('resolves a USER from approver_identity', () => {
    const r = resolveApprover({
      approver_type: 'user',
      approver_identity: { type: 'user', id: 'u1', name: 'Ada', email: 'a@x.io', avatar: null, is_bot: false },
    });
    expect(r).toEqual({ type: 'user', id: 'u1', name: 'Ada', avatar: null, isBot: false });
  });

  it('resolves a BOT from approver_identity (never an avatar)', () => {
    const r = resolveApprover({
      approver_type: 'bot',
      approver_identity: { type: 'bot', id: 'b1', name: 'Maven', email: null, avatar: null, is_bot: true },
    });
    expect(r).toEqual({ type: 'bot', id: 'b1', name: 'Maven', avatar: null, isBot: true });
  });

  it('returns null for a generic AI stage (approver_identity null)', () => {
    expect(resolveApprover({ approver_type: 'ai', approver_identity: null })).toBeNull();
  });

  it('backfills the user avatar from the legacy approver relation (same id)', () => {
    const r = resolveApprover({
      approver_type: 'user',
      approver_identity: { type: 'user', id: 'u1', name: 'Ada', email: null, avatar: null, is_bot: false },
      approver: { id: 'u1', name: 'Ada', avatar: 'https://img/ada.png' },
    });
    expect(r?.avatar).toBe('https://img/ada.png');
  });

  it('does NOT backfill an avatar onto a bot', () => {
    const r = resolveApprover({
      approver_type: 'bot',
      approver_identity: { type: 'bot', id: 'b1', name: 'Maven', email: null, avatar: null, is_bot: true },
      approver: { id: 'b1', name: 'x', avatar: 'https://img/x.png' },
    });
    expect(r?.avatar).toBeNull();
  });

  it('falls back to the legacy approver user when approver_identity is ABSENT', () => {
    const r = resolveApprover({
      approver_type: 'user',
      approver: { id: 'u2', name: 'Grace', avatar: 'https://img/g.png' },
    });
    expect(r).toEqual({ type: 'user', id: 'u2', name: 'Grace', avatar: 'https://img/g.png', isBot: false });
  });

  it('returns null when neither field carries a named approver (legacy ai stage)', () => {
    expect(resolveApprover({ approver_type: 'ai' })).toBeNull();
    expect(resolveApprover({ approver_type: 'ai', approver: null })).toBeNull();
  });

  it('tolerates a former member with a null name', () => {
    const r = resolveApprover({
      approver_type: 'user',
      approver_identity: { type: 'user', id: 'u3', name: null as unknown as string, email: null, avatar: null, is_bot: false },
    });
    expect(r?.name).toBeNull();
    expect(r?.type).toBe('user');
  });
});

describe('approverTypeIcon', () => {
  it('maps user → user, ai/bot → sparkles (no bot glyph in the registry)', () => {
    expect(approverTypeIcon('user')).toBe('user');
    expect(approverTypeIcon('ai')).toBe('sparkles');
    expect(approverTypeIcon('bot')).toBe('sparkles');
  });
});
