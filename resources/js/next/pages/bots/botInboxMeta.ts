// Shared presentation for the Bot Inbox execution buckets (next, Batch 7).
//
// Maps a `BotInboxState` onto a bucket descriptor: an i18n label KEY (resolved via
// t() at the call site), a `next` IconName, and a Badge/StatusBadge variant tone.
// Kept as a pure module so the bucket bar + the per-row state badge render buckets
// IDENTICALLY, and the mapping is unit-testable.
import type { IconName } from '../../ui/primitives/icons';
import type { BotInboxState } from './types';

type BucketTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

interface BucketMeta {
  /** i18n key for the label (resolve via t()). */
  i18nKey: string;
  icon: IconName;
  tone: BucketTone;
}

// Tones per the contract: queued=neutral, running=info/primary, waiting=warning,
// in_approval=info, revision=warning, failed=danger, done=success. Icons are the
// closest available `next` registry glyphs (no play/hourglass): running→loader,
// waiting→help-circle, in_approval→lock, revision→rotate-ccw, failed→alert-triangle.
const BUCKET_META: Record<BotInboxState, BucketMeta> = {
  queued: { i18nKey: 'bots.inbox.states.queued', icon: 'clock', tone: 'neutral' },
  running: { i18nKey: 'bots.inbox.states.running', icon: 'loader', tone: 'info' },
  waiting: { i18nKey: 'bots.inbox.states.waiting', icon: 'help-circle', tone: 'warning' },
  in_approval: { i18nKey: 'bots.inbox.states.in_approval', icon: 'lock', tone: 'info' },
  revision: { i18nKey: 'bots.inbox.states.revision', icon: 'rotate-ccw', tone: 'warning' },
  failed: { i18nKey: 'bots.inbox.states.failed', icon: 'alert-triangle', tone: 'danger' },
  done: { i18nKey: 'bots.inbox.states.done', icon: 'check-circle', tone: 'success' },
};

const FALLBACK: BucketMeta = {
  i18nKey: 'bots.inbox.states.unknown',
  icon: 'circle',
  tone: 'neutral',
};

/** The bucket descriptor for an inbox state (falls back gracefully for an unknown). */
export function botInboxMeta(state: BotInboxState | string): BucketMeta {
  return BUCKET_META[state as BotInboxState] ?? FALLBACK;
}

/** The "All" pseudo-bucket descriptor (no `state` filter) for the bucket bar. */
export const ALL_BUCKET_META: BucketMeta = {
  i18nKey: 'bots.inbox.states.all',
  icon: 'inbox',
  tone: 'neutral',
};
