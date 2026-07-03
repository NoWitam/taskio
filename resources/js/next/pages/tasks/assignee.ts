// assignee — pure helpers for the polymorphic task assignee (Batch 2).
//
// A task's assignee is now either a User or a Bot (or null/unassigned). The
// backend emits BOTH the NEW `assignee` field (TaskAssignee, the source of truth)
// AND the legacy `assigned` UserResource (null when a bot is assigned, kept for
// back-compat). `next` PREFERS `assignee`, falling back to `assigned` only when
// `assignee` is absent (older payloads) — these helpers centralize that rule so
// every render site (card, board, detail header) and the write-payload builder
// agree, and so the logic is unit-testable without mounting a component.
import type { TaskAssignee, TaskUser } from './types';

/** The normalized identity a render site needs, regardless of source field. */
export interface ResolvedAssignee {
  type: 'user' | 'bot';
  id: string;
  /** Display name; null when a former member's name is missing (tolerated). */
  name: string | null;
  /** Avatar src for a USER (null for a bot, which renders the sparkles glyph). */
  avatar: string | null;
  isBot: boolean;
}

/**
 * Resolve the identity to render from a task, preferring the NEW polymorphic
 * `assignee` and falling back to the legacy `assigned` UserResource only when
 * `assignee` is absent. Returns null when the task is unassigned. A null `name`
 * (former member with no name) is tolerated — the caller decides the placeholder.
 */
export function resolveAssignee(task: {
  assignee?: TaskAssignee | null;
  assigned?: TaskUser | null;
}): ResolvedAssignee | null {
  // Prefer the new field as the source of truth.
  if (task.assignee !== undefined) {
    const a = task.assignee;
    if (!a) return null; // explicit null → unassigned
    const isBot = a.is_bot || a.type === 'bot';
    // The polymorphic `assignee` always sends a null avatar; for a USER, backfill
    // the real avatar from the legacy `assigned` relation when it's the same id so
    // the image isn't lost (the legacy relation is null for a bot).
    const userAvatar =
      !isBot && task.assigned && String(task.assigned.id) === String(a.id)
        ? task.assigned.avatar ?? null
        : a.avatar ?? null;
    return {
      type: isBot ? 'bot' : 'user',
      id: String(a.id),
      name: a.name ?? null,
      avatar: isBot ? null : userAvatar,
      isBot,
    };
  }
  // Legacy fallback: only a user could be assigned (a bot would null `assigned`).
  const u = task.assigned;
  if (!u || u.id == null) return null;
  return {
    type: 'user',
    id: String(u.id),
    name: u.name ?? null,
    avatar: u.avatar ?? null,
    isBot: false,
  };
}

/**
 * Build the `assignee_type` / `assignee_id` pair for a write payload from a
 * picker selection. `kind` is the chosen group (member vs bot); `id` is null
 * when nothing is selected. Returns BOTH null to CLEAR the assignee. `next`
 * always sends these (they WIN over the legacy `assigned_id`).
 */
export function buildAssigneePayload(
  kind: 'user' | 'bot',
  id: string | null,
): { assignee_type: 'user' | 'bot' | null; assignee_id: string | null } {
  if (!id) return { assignee_type: null, assignee_id: null };
  return { assignee_type: kind, assignee_id: id };
}
