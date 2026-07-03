// Unit tests for the pure polymorphic-assignee resolver + payload builder.
// Guards the Batch 2 rule: PREFER the new `assignee` field (User|Bot|null),
// fall back to the legacy `assigned` UserResource only when `assignee` is absent;
// backfill a user avatar from `assigned`; and build the assignee_type/assignee_id
// write pair (incl. clear → both null).
import { describe, expect, it } from 'vitest';
import { resolveAssignee, buildAssigneePayload } from '../assignee';

describe('resolveAssignee', () => {
  it('resolves a USER from the new assignee field', () => {
    const r = resolveAssignee({
      assignee: { type: 'user', id: 'u1', name: 'Ada', email: 'a@x.io', avatar: null, is_bot: false },
    });
    expect(r).toEqual({ type: 'user', id: 'u1', name: 'Ada', avatar: null, isBot: false });
  });

  it('resolves a BOT from the new assignee field (never an avatar)', () => {
    const r = resolveAssignee({
      assignee: { type: 'bot', id: 'b1', name: 'Maven', email: null, avatar: null, is_bot: true },
    });
    expect(r).toEqual({ type: 'bot', id: 'b1', name: 'Maven', avatar: null, isBot: true });
  });

  it('returns null when assignee is explicitly null (unassigned)', () => {
    expect(resolveAssignee({ assignee: null })).toBeNull();
  });

  it('backfills the user avatar from the legacy assigned relation (same id)', () => {
    const r = resolveAssignee({
      assignee: { type: 'user', id: 'u1', name: 'Ada', email: null, avatar: null, is_bot: false },
      assigned: { id: 'u1', name: 'Ada', avatar: 'https://img/ada.png' },
    });
    expect(r?.avatar).toBe('https://img/ada.png');
  });

  it('does NOT backfill an avatar onto a bot', () => {
    const r = resolveAssignee({
      assignee: { type: 'bot', id: 'b1', name: 'Maven', email: null, avatar: null, is_bot: true },
      assigned: { id: 'b1', name: 'x', avatar: 'https://img/x.png' },
    });
    expect(r?.avatar).toBeNull();
  });

  it('falls back to the legacy assigned user when assignee is ABSENT', () => {
    const r = resolveAssignee({
      assigned: { id: 'u2', name: 'Grace', avatar: 'https://img/g.png' },
    });
    expect(r).toEqual({ type: 'user', id: 'u2', name: 'Grace', avatar: 'https://img/g.png', isBot: false });
  });

  it('returns null when neither field carries an assignee', () => {
    expect(resolveAssignee({})).toBeNull();
    expect(resolveAssignee({ assigned: null })).toBeNull();
  });

  it('tolerates a former member with a null name', () => {
    const r = resolveAssignee({
      assignee: { type: 'user', id: 'u3', name: null, email: null, avatar: null, is_bot: false },
    });
    expect(r?.name).toBeNull();
    expect(r?.type).toBe('user');
  });
});

describe('buildAssigneePayload', () => {
  it('builds a user pair', () => {
    expect(buildAssigneePayload('user', 'u1')).toEqual({
      assignee_type: 'user',
      assignee_id: 'u1',
    });
  });

  it('builds a bot pair', () => {
    expect(buildAssigneePayload('bot', 'b1')).toEqual({
      assignee_type: 'bot',
      assignee_id: 'b1',
    });
  });

  it('clears with BOTH null when no id is selected', () => {
    expect(buildAssigneePayload('user', null)).toEqual({
      assignee_type: null,
      assignee_id: null,
    });
    expect(buildAssigneePayload('bot', null)).toEqual({
      assignee_type: null,
      assignee_id: null,
    });
  });
});
