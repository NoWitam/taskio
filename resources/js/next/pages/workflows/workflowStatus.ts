// Shared status presentation for the Workflows module (next).
//
// Maps the backend `WorkflowStatus` enum (`active | inactive`) onto the StatusBadge
// descriptor shape so the status renders consistently — and NEVER color-only —
// across the card, the detail header, and the aside. Tones mirror the contract
// (success / neutral); each pairs an icon with a localized label. Mirrors
// `ui/data/botStatus.ts` (which lives in `ui/` because the design system's BotSelect
// renders bot statuses; nothing in `ui/` needs workflow statuses, so this one stays here).
import type { StatusMap } from '../../ui/data/StatusBadge.vue';
import type { WorkflowStatus } from './types';

type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** The two workflow statuses (active first). */
export const WORKFLOW_STATUSES: WorkflowStatus[] = ['active', 'inactive'];

/**
 * Build the StatusBadge map for the given translator. Called from a computed so
 * the labels stay reactive to the active locale.
 */
export function workflowStatusMap(t: Translate): StatusMap {
  return {
    active: { label: t('workflows.status.active'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
    inactive: { label: t('workflows.status.inactive'), variant: 'neutral', tone: 'subtle', icon: 'circle' },
  };
}
