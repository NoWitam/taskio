// Shared status presentation for bots (AI Characters) — a `ui/` module, not a page module.
//
// WHY IT LIVES HERE: `ui/forms/BotSelect.vue` is a GLOBAL picker in the design system and it must
// render a bot's status exactly like the Bots card/detail do. `ui/**` may never import from
// `pages/**` (the design system is the lower layer — see `__tests__/uiLayerImportBoundary.spec.ts`),
// so the map — and the `BotStatus` enum it keys on — moved DOWN here and `pages/bots/*` imports it.
// The alternative, a second local copy inside `ui/`, is exactly the drift this file prevents.
//
// It maps the backend `BotStatus` enum (`active | inactive`) onto the StatusBadge descriptor shape,
// so the status renders consistently — and NEVER color-only — across the card, the detail header,
// the list and every picker. Tones mirror the contract (success / neutral) and each pairs an icon
// with a localized label.
import type { StatusMap } from './StatusBadge.vue';

/**
 * The bot status enum — collapsed to a two-state toggle. A bot is either live (`active`, tone
 * success) or off (`inactive`, tone neutral). Status is NEVER sent on create/update; it is toggled
 * through `PATCH /bots/{id}/status`.
 */
export type BotStatus = 'active' | 'inactive';

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
