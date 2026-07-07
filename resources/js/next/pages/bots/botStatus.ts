// Shared status presentation for the Bots module (next).
//
// Maps the backend `BotStatus` enum (`active | inactive`) onto the StatusBadge
// descriptor shape so the status renders consistently — and NEVER color-only —
// across the card, the detail header, and the list. Tones mirror the contract
// (success / neutral) and each pairs an icon with a localized label.
import type { StatusMap } from '../../ui/data/StatusBadge.vue';
import type { BotStatus } from './types';

type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** The two bot statuses (active first). */
export const BOT_STATUSES: BotStatus[] = ['active', 'inactive'];

/**
 * Build the StatusBadge map for the given translator. Called from a computed so
 * the labels stay reactive to the active locale.
 */
export function botStatusMap(t: Translate): StatusMap {
  return {
    active: { label: t('bots.statuses.active'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
    inactive: { label: t('bots.statuses.inactive'), variant: 'neutral', tone: 'subtle', icon: 'circle' },
  };
}
