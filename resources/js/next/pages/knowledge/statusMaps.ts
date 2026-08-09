// Shared status presentation for the KNOWLEDGE module (next) — spec §12.
//
// Two INDEPENDENT vocabularies, deliberately kept in one file so they cannot drift apart in
// tone/icon language while staying separate maps (an `approved` entry can be `pending`, and a
// `draft` can be fully `indexed` — they are never merged into one badge):
//
//   • entryStatusMap  — the EDITORIAL state (KnowledgeEntryStatus: draft|proposed|approved|archived)
//   • indexStatusMap  — the INDEXING state  (KnowledgeIndexStatus: pending|indexing|indexed|
//                       partial|pending_budget|failed)
//
// Every descriptor pairs an icon with a localized label, so no state is ever carried by color
// alone. Labels are resolved through the caller's `t` (called from a computed) so they follow a
// runtime locale switch.
//
// `partial` is the one state whose label needs numbers (`Partial: {done}/{total}`); the map holds
// the bare label and `indexStatusLabel()` builds the counted variant, because a StatusMap entry
// cannot carry per-instance data.
//
// SCOPE NOTE (verified backend contract): `index.status` lives on an ENTRY
// (KnowledgeEntryListResource), never on a base — KnowledgeBaseResource carries NO index
// aggregate. So the base list renders no index badge; these maps are consumed by the entry
// surfaces (reader / table, B5).
import type { StatusMap } from '../../ui/data/StatusBadge.vue';
import type { KnowledgeEntryStatus, KnowledgeIndexStatus } from './types';

type Translate = (
  key: string,
  defaultValue?: string,
  params?: Record<string, string | number>,
) => string;

/** The four editorial statuses, in lifecycle order (write → blessing → retirement). */
export const ENTRY_STATUSES: KnowledgeEntryStatus[] = ['draft', 'proposed', 'approved', 'archived'];

/** The six index states, in the order they read as a progression / an exception. */
export const INDEX_STATUSES: KnowledgeIndexStatus[] = [
  'pending',
  'indexing',
  'indexed',
  'partial',
  'pending_budget',
  'failed',
];

/** StatusBadge descriptors for an entry's EDITORIAL status (spec §12.1). */
export function entryStatusMap(t: Translate): StatusMap {
  return {
    draft: { label: t('knowledge.status.draft'), variant: 'neutral', tone: 'subtle', icon: 'pencil' },
    proposed: { label: t('knowledge.status.proposed'), variant: 'info', tone: 'subtle', icon: 'inbox' },
    approved: { label: t('knowledge.status.approved'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
    archived: { label: t('knowledge.status.archived'), variant: 'neutral', tone: 'subtle', icon: 'archive' },
  };
}

/**
 * StatusBadge descriptors for an entry's INDEX state (spec §12.2).
 *
 * `failed` is the only SOLID tone in the module: it is the one state that means something is
 * broken and needs a human, so it is allowed to shout while the rest stay subtle.
 */
export function indexStatusMap(t: Translate): StatusMap {
  return {
    pending: { label: t('knowledge.index.pending'), variant: 'neutral', tone: 'subtle', icon: 'clock' },
    indexing: { label: t('knowledge.index.indexing'), variant: 'info', tone: 'subtle', icon: 'loader' },
    indexed: { label: t('knowledge.index.indexed'), variant: 'success', tone: 'subtle', icon: 'check-circle' },
    partial: { label: t('knowledge.index.partialShort'), variant: 'warning', tone: 'subtle', icon: 'alert-triangle' },
    pending_budget: { label: t('knowledge.index.pendingBudget'), variant: 'warning', tone: 'subtle', icon: 'wallet' },
    failed: { label: t('knowledge.index.failed'), variant: 'danger', tone: 'solid', icon: 'x-circle' },
  };
}

/**
 * The badge LABEL for an index state, counted where that is the honest reading: `partial` becomes
 * `Partial: {done}/{total}` as soon as both numbers are known, and stays the bare word otherwise
 * (a "Partial: 0/0" would claim knowledge the caller does not have).
 */
export function indexStatusLabel(
  status: KnowledgeIndexStatus,
  t: Translate,
  counts?: { done?: number | null; total?: number | null },
): string {
  if (status === 'partial' && counts?.done != null && counts?.total != null) {
    return t('knowledge.index.partial', '', { done: counts.done, total: counts.total });
  }
  return t(`knowledge.index.${status === 'pending_budget' ? 'pendingBudget' : status}`);
}
