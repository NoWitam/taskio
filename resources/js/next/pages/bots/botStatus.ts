// Shared status presentation for the Bots module (next, Batch 1).
//
// Maps the backend `BotStatus` enum (`draft | active | disabled`) onto the
// StatusBadge descriptor shape so the status renders consistently — and NEVER
// color-only — across the card, the detail header, and the editor. Tones mirror
// the contract (neutral / success / neutral) and each pairs an icon with a
// localized label.
import type { StatusMap } from '../../ui/data/StatusBadge.vue';
import type { BotStatus } from './types';

type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** The ordered status list for the editor's status select. */
export const BOT_STATUSES: BotStatus[] = ['draft', 'active', 'disabled'];

/**
 * Build the StatusBadge map for the given translator. Called from a computed so
 * the labels stay reactive to the active locale.
 */
export function botStatusMap(t: Translate): StatusMap {
  return {
    draft: { label: t('bots.statuses.draft'), variant: 'neutral', tone: 'subtle', icon: 'file-text' },
    active: { label: t('bots.statuses.active'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
    disabled: { label: t('bots.statuses.disabled'), variant: 'neutral', tone: 'subtle', icon: 'lock' },
  };
}
