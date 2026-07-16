// approver — pure helpers for the polymorphic stage/process approver (Batch 3).
//
// A pipeline stage's (and an approval process's) approver is now a User, a named
// Bot, or a generic AI (no named identity). The backend emits the NEW additive
// `approver_identity` field (ApproverResource: User|Bot|null) alongside the legacy
// `approver` UserResource (kept for back-compat). `next` PREFERS `approver_identity`,
// falling back to `approver` only when the new field is absent (older payloads) —
// these helpers centralize that rule so every render site (the pipeline editor's
// preview, the review-drawer decision history, the task approval stepper) agrees,
// and so the logic is unit-testable without mounting a component.
import type { ApprovalUser, ApproverIdentity, ApproverType } from './types';
import type { IconName } from '../../ui/primitives/icons';

/** The normalized approver a render site needs, regardless of source field. */
export interface ResolvedApprover {
  type: 'user' | 'bot';
  id: string;
  /** Display name; null when a former member's name is missing (tolerated). */
  name: string | null;
  /** Avatar src for a USER (null for a bot, which renders the sparkles glyph). */
  avatar: string | null;
  isBot: boolean;
}

/**
 * Resolve the named approver to render from a stage/process, preferring the NEW
 * `approver_identity` and falling back to the legacy `approver` UserResource only
 * when `approver_identity` is absent. Returns null for a GENERIC AI stage (no
 * named approver) or an unresolved relation — the caller renders the generic
 * "AI reviewer" fallback in that case. A null `name` (former member) is tolerated.
 */
export function resolveApprover(source: {
  approver_type?: ApproverType;
  approver_identity?: ApproverIdentity | null;
  approver?: ApprovalUser | null;
}): ResolvedApprover | null {
  // Prefer the new field as the source of truth.
  if (source.approver_identity !== undefined) {
    const a = source.approver_identity;
    if (!a) return null; // generic ai / unresolved → no named approver
    const isBot = a.is_bot || a.type === 'bot';
    // The polymorphic identity always sends a null avatar; for a USER, backfill
    // the real avatar from the legacy `approver` relation when it's the same id.
    const userAvatar =
      !isBot && source.approver && String(source.approver.id) === String(a.id)
        ? source.approver.avatar ?? null
        : a.avatar ?? null;
    return {
      type: isBot ? 'bot' : 'user',
      id: String(a.id),
      name: a.name ?? null,
      avatar: isBot ? null : userAvatar,
      isBot,
    };
  }
  // Legacy fallback: only a user could be the named approver (a bot is new).
  const u = source.approver;
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
 * The icon for an approver TYPE. `user` → user; `ai` AND `bot` → `sparkles` (the
 * local icon registry has no dedicated `bot` glyph — the bot case is distinguished
 * by rendering `BotIdentity`, not by this fallback icon). Note: the backend
 * `ApproverType::icon()` returns the string `'bot'`, which is NOT a valid next
 * IconName, so the FE maps it to `sparkles` here.
 */
export function approverTypeIcon(type: ApproverType): IconName {
  return type === 'user' ? 'user' : 'sparkles';
}
