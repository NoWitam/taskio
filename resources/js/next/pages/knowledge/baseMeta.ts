// baseMeta — the pure presentation helpers for a KNOWLEDGE BASE row/card. No Vue, no HTTP.
//
// Mirrors `pages/generator/templateMeta.ts`: the small derivations a card needs live outside the
// component so they can be unit-tested and shared with the base settings surfaces (B5).
import type { KnowledgeBase, KnowledgeIndexStatus } from './types';

type Translate = (
  key: string,
  defaultValue?: string,
  params?: Record<string, string | number>,
) => string;

/**
 * The FIRST SENTENCE of a base's charter — the card subtitle.
 *
 * The charter is a multi-paragraph brief written for AI consumers; a card can only carry its
 * opening claim, so the text is cut at the first sentence terminator (or the first line break,
 * whichever comes first — a charter written as the five prompted questions has no full stop on
 * line one). An over-long single sentence is trimmed with an ellipsis rather than wrapped, since
 * the card clamps to two lines anyway.
 */
export function charterSummary(charter: string | null | undefined, maxChars = 160): string | null {
  const text = (charter ?? '').trim();
  if (text === '') return null;

  const firstLine = text.split(/\r?\n/, 1)[0].trim();
  const match = firstLine.match(/^[\s\S]*?[.!?](?=\s|$)/);
  const sentence = (match ? match[0] : firstLine).trim();

  return sentence.length > maxChars ? `${sentence.slice(0, maxChars).trimEnd()}…` : sentence;
}

/**
 * A base's subtitle: its charter's opening sentence, else its description, else the honest
 * "no charter yet" note. The description is the fallback rather than being ignored — it is a
 * real, editable field, and a base with only a description would otherwise read as empty.
 */
export function baseSubtitle(base: KnowledgeBase, t: Translate): string {
  return charterSummary(base.charter) ?? base.description?.trim() ?? t('knowledge.bases.noCharter');
}

/**
 * The human name of a language tag. Known tags are translated; anything else (a base created
 * through the API with `pt-BR`) falls back to the tag itself, upper-cased — never to a guess.
 */
export function languageLabel(tag: string | null | undefined, t: Translate): string {
  const value = (tag ?? '').trim();
  if (value === '') return '—';
  return t(`knowledge.language.${value}`, value.toUpperCase());
}

/** ISO timestamp → `dd.mm.yyyy` (locale-agnostic; the surrounding labels are i18n). */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

/**
 * Collapse a base's `index_summary` census into ONE badge state + label.
 *
 * The unit here is ENTRIES (the backend is explicit: `index_summary.total === entries_count`, while
 * an ENTRY's own `index.chunks_count` counts chunks). Mixing the two would print a fraction whose
 * halves came from different denominators, so this function only ever divides entries by entries.
 *
 * Priority is "what does the user need to know first", not arithmetic:
 *   failed → something is broken and needs a human;
 *   pending_budget → nothing is broken, but no retry helps (raising the AI cap does);
 *   fully indexed → the quiet, good case;
 *   indexing → work is in flight;
 *   partial → some done, some queued — the only state that shows numbers;
 *   pending → nothing started yet.
 *
 * Returns null for an EMPTY base: "0/0 indexed" is a claim about nothing.
 */
export function baseIndexState(
  base: Pick<KnowledgeBase, 'index_summary' | 'entries_count'>,
  t: Translate,
): { status: KnowledgeIndexStatus; label: string } | null {
  const summary = base.index_summary ?? {};
  const total = summary.total ?? base.entries_count ?? 0;
  if (total <= 0) return null;

  const indexed = summary.indexed ?? 0;
  const pick = (status: KnowledgeIndexStatus, label: string) => ({ status, label });

  if ((summary.failed ?? 0) > 0) return pick('failed', t('knowledge.index.failed'));
  if ((summary.pending_budget ?? 0) > 0) return pick('pending_budget', t('knowledge.index.pendingBudget'));
  if (indexed >= total) return pick('indexed', t('knowledge.index.indexed'));
  if ((summary.indexing ?? 0) > 0) return pick('indexing', t('knowledge.index.indexing'));
  if (indexed > 0 || (summary.partial ?? 0) > 0) {
    return pick('partial', t('knowledge.index.partial', '', { done: indexed, total }));
  }
  return pick('pending', t('knowledge.index.pending'));
}
