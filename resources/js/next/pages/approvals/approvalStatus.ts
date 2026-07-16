// Approval status → StatusBadge descriptor mapping for the "next" frontend.
//
// VERIFIED enum mapping (do NOT invent):
//   ApprovalProcessStatus = pending | approved | rejected
//     → tone warning / success / danger, icons clock / check-circle / x-circle.
//   ApproverType = user | ai → icons user / sparkles.
//
// Status is NEVER color-only — each descriptor pairs an icon + a translated label
// so meaning survives for color-blind users. The labels are resolved through the
// caller's `t()` so they follow the UI locale.
import type { StatusMap } from '../../ui/data/StatusBadge.vue';
import type { IconName } from '../../ui/primitives/icons';
import type { ApproverType } from './queue-types';

type Translate = (key: string, fallback?: string, params?: Record<string, string | number>) => string;

/**
 * Build the StatusMap for the three approval process statuses, with localized
 * labels. Pass the component's `t` so the chips follow the active locale.
 */
export function approvalStatusMap(t: Translate): StatusMap {
  return {
    pending: { label: t('approvals.status.pending'), variant: 'warning', tone: 'subtle', icon: 'clock' },
    approved: { label: t('approvals.status.approved'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
    rejected: { label: t('approvals.status.rejected'), variant: 'danger', tone: 'subtle', icon: 'x-circle' },
  };
}

/**
 * The icon for an approver type (`user` → user; `ai` AND `bot` → sparkles). The
 * local icon registry has no dedicated `bot` glyph — a named bot is distinguished
 * by rendering `BotIdentity`, not by this fallback icon. (Re-exported from
 * `./approver` for new call sites; kept here for existing imports.)
 */
export function approverTypeIcon(type: ApproverType): IconName {
  return type === 'user' ? 'user' : 'sparkles';
}
