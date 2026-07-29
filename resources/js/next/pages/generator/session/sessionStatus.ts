// Shared status presentation for generation SESSIONS (next, R2 sub-stage 2b).
//
// Maps the backend `GenerationSessionStatus` enum (`draft | generating | ready | failed`) onto the
// StatusBadge descriptor shape so a session's state renders consistently — and NEVER color-only —
// across the list row, the chat page header, and the module aside. Each pairs an icon with a localized
// label. Mirrors `pages/workflows/workflowStatus.ts`.
import type { StatusMap } from '../../../ui/data/StatusBadge.vue';
import type { SessionStatus } from '../sessionTypes';

type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** The four session statuses, in lifecycle order. */
export const SESSION_STATUSES: SessionStatus[] = ['draft', 'generating', 'ready', 'failed'];

/**
 * Build the StatusBadge map for the given translator. Called from a computed so the labels stay
 * reactive to the active locale. `generating` uses the neutral `loader` glyph (spun by the header's
 * own affordance, not here); `ready` is the success verdict; `failed` the danger verdict.
 */
export function sessionStatusMap(t: Translate): StatusMap {
  return {
    draft: { label: t('generator.sessions.status.draft'), variant: 'neutral', tone: 'subtle', icon: 'file-text' },
    generating: { label: t('generator.sessions.status.generating'), variant: 'info', tone: 'subtle', icon: 'loader' },
    ready: { label: t('generator.sessions.status.ready'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
    failed: { label: t('generator.sessions.status.failed'), variant: 'danger', tone: 'subtle', icon: 'x-circle' },
  };
}
