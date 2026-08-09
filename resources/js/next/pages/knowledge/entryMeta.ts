// entryMeta — pure presentation helpers for a KNOWLEDGE ENTRY. No Vue, no HTTP.
//
// Sibling of `baseMeta.ts`: the derivations the reader, the table and the rail all need, kept
// outside the components so they are unit-testable and cannot drift between the three surfaces
// that show the same entry.
import type {
  KnowledgeEntry,
  KnowledgeEntryListItem,
  KnowledgeIndexStatus,
  KnowledgeLink,
  KnowledgeLinkEnd,
} from './types';

type Translate = (
  key: string,
  defaultValue?: string,
  params?: Record<string, string | number>,
) => string;

/**
 * The index badge label for ONE entry.
 *
 * `partial` becomes the COUNTED "Partial: 7/8" as soon as the numbers are honest — which B6c made
 * possible by adding `index.indexed_chunks_count`, the numerator that was missing when this
 * function first shipped (it deliberately refused to invent a denominator then, and still does).
 *
 * THE NULL IS NOT A ZERO. `indexed_chunks_count === null` means "this connection cannot count
 * them" (no vector support), not "none are indexed": rendering `0/8` there would tell the user
 * their index was wiped. A null therefore falls back to the bare word — the same answer this
 * function gave before the numerator existed.
 */
export function entryIndexLabel(
  status: KnowledgeIndexStatus | null | undefined,
  t: Translate,
  chunks?: { indexed?: number | null; total?: number | null },
): string {
  if (!status) return t('knowledge.index.pending');
  if (status === 'partial') {
    const done = chunks?.indexed;
    const total = chunks?.total;
    return done != null && total != null && total > 0
      ? t('knowledge.index.partial', '', { done, total })
      : t('knowledge.index.partialShort');
  }
  return t(`knowledge.index.${status === 'pending_budget' ? 'pendingBudget' : status}`);
}

/** The contextual copy for an index state — mandatory by spec: never make the user guess. */
export function entryIndexHint(
  status: KnowledgeIndexStatus | null | undefined,
  t: Translate,
): string | null {
  switch (status) {
    case 'pending':
      return t('knowledge.index.pendingHint');
    case 'indexing':
      return t('knowledge.index.indexingHint');
    case 'partial':
      return t('knowledge.index.partialHintShort');
    case 'pending_budget':
      return t('knowledge.index.pendingBudgetHint');
    case 'failed':
      return t('knowledge.index.failedHint');
    default:
      // `indexed` is the quiet good case — a banner saying "this worked" is noise.
      return null;
  }
}

/** The Alert variant that carries an index hint, matching the badge's severity. */
export function entryIndexHintVariant(
  status: KnowledgeIndexStatus | null | undefined,
): 'info' | 'warning' | 'danger' {
  if (status === 'failed') return 'danger';
  if (status === 'partial' || status === 'pending_budget') return 'warning';
  return 'info';
}

/** One row of the "Similar" panel — a similarity edge flattened to what the UI renders. */
export interface SimilarNeighbour {
  /** The EDGE id — what dismiss/undo acts on. */
  linkId: string;
  /** The entry at the other end. Never null: a similarity edge always has two real ends. */
  target: KnowledgeLinkEnd;
  score: number | null;
  evidence: Record<string, unknown> | null;
  dismissed: boolean;
  /** The SERVER's verdict on whether this row may be dismissed (`can_be_dismissed`). */
  canDismiss: boolean;
}

/**
 * One row of the "Mentions" panel (B10) — the target's NAME appears in this entry's text, or this
 * entry's name appears in theirs.
 *
 * DIRECTION IS KEPT, unlike the similarity panel which unions both ways. A mention is directional
 * by nature — A can name B without B naming A, and the two directions are two separate edges with
 * their own evidence and their own dismissal — so folding them together would claim a mutual
 * relationship that may not exist and would leave one dismissal attached to nothing.
 */
export interface MentionNeighbour {
  linkId: string;
  /** The entry at the other end of THIS direction. */
  target: KnowledgeLinkEnd;
  /** `outgoing` = this entry mentions them; `incoming` = they mention this entry. */
  direction: 'outgoing' | 'incoming';
  /** The mentioning words, when they can be resolved from content in hand. */
  text: string | null;
  dismissed: boolean;
  canDismiss: boolean;
}

/**
 * The mentioning words, sliced out of the content the mention was found in.
 *
 * The offsets are PHP `mb_substr` arithmetic — CODE POINTS, not UTF-16 units — so the slice runs
 * over `Array.from(content)`. Indexing the string directly drifts by one for every astral
 * character (one emoji in an entry) and quotes the wrong words. Same rule as `highlightSegments`.
 *
 * Returns null rather than a guess when the evidence is not the expected shape or the content is
 * not in hand.
 */
export function mentionText(
  content: string | null | undefined,
  evidence: Record<string, unknown> | null | undefined,
): string | null {
  if (typeof content !== 'string' || content === '' || !evidence) return null;

  const start = evidence.char_start;
  const length = evidence.char_length;
  if (typeof start !== 'number' || typeof length !== 'number') return null;
  if (!Number.isFinite(start) || !Number.isFinite(length) || start < 0 || length <= 0) return null;

  const chars = Array.from(content);
  if (start >= chars.length) return null;

  const text = chars.slice(start, start + length).join('').trim();
  return text === '' ? null : text;
}

/**
 * This entry's mentions, both directions.
 *
 * Only the OUTGOING rows can quote the text: the offsets index the SOURCE entry's content, and for
 * an incoming mention that is the other entry's body, which this client does not hold. Such a row
 * renders without a quote rather than with a wrong one.
 */
export function mentionNeighbours(entry: KnowledgeEntry | null | undefined): MentionNeighbour[] {
  if (!entry) return [];

  const rows: MentionNeighbour[] = [];

  for (const link of entry.links ?? []) {
    if (link.source !== 'mention' || !link.target) continue;
    rows.push({
      linkId: link.id,
      target: link.target,
      direction: 'outgoing',
      text: mentionText(entry.content, link.evidence),
      dismissed: link.dismissed_at != null,
      canDismiss: link.can_be_dismissed,
    });
  }

  for (const link of entry.backlinks ?? []) {
    if (link.source !== 'mention' || !link.source_entry) continue;
    rows.push({
      linkId: link.id,
      target: link.source_entry,
      direction: 'incoming',
      text: null,
      dismissed: link.dismissed_at != null,
      canDismiss: link.can_be_dismissed,
    });
  }

  // Outgoing first (what THIS entry says), then by title — a total order, so the panel cannot
  // reshuffle between renders.
  return rows.sort(
    (a, b) =>
      (a.direction === b.direction ? 0 : a.direction === 'outgoing' ? -1 : 1) ||
      a.target.title.localeCompare(b.target.title) ||
      a.linkId.localeCompare(b.linkId),
  );
}

/**
 * The similarity neighbours of an entry — the UNION of both directions.
 *
 * Similarity is symmetric in meaning but stored as a directed row, so an entry can be the `from` of
 * one edge and the `to` of another with the same neighbour. Showing only outgoing edges would hide
 * half of a genuinely mutual relationship, and showing both without de-duplicating would list the
 * same neighbour twice. De-duplication keeps the FIRST occurrence, preferring the higher score.
 */
export function similarNeighbours(entry: KnowledgeEntry | null | undefined): SimilarNeighbour[] {
  if (!entry) return [];

  const rows: SimilarNeighbour[] = [];
  const collect = (links: KnowledgeLink[] | undefined, end: 'target' | 'source_entry'): void => {
    for (const link of links ?? []) {
      if (link.source !== 'similarity') continue;
      const other = end === 'target' ? link.target : link.source_entry;
      if (!other) continue;
      rows.push({
        linkId: link.id,
        target: other,
        score: link.score,
        evidence: link.evidence,
        dismissed: link.dismissed_at != null,
        canDismiss: link.can_be_dismissed,
      });
    }
  };
  collect(entry.links, 'target');
  collect(entry.backlinks, 'source_entry');

  const byEntry = new Map<string, SimilarNeighbour>();
  for (const row of rows) {
    const seen = byEntry.get(row.target.id);
    if (!seen || (row.score ?? 0) > (seen.score ?? 0)) byEntry.set(row.target.id, row);
  }

  return [...byEntry.values()].sort((a, b) => (b.score ?? 0) - (a.score ?? 0));
}

/** Resolved outgoing wikilinks — "links to". Ghosts are excluded; they have their own panel. */
export function outgoingWikilinks(entry: KnowledgeEntry | null | undefined): KnowledgeLinkEnd[] {
  return (entry?.links ?? [])
    .filter((link) => link.source === 'wikilink' && !link.is_ghost && link.target)
    .map((link) => link.target as KnowledgeLinkEnd);
}

/** Incoming wikilinks — "linked from". */
export function backlinkSources(entry: KnowledgeEntry | null | undefined): KnowledgeLinkEnd[] {
  return (entry?.backlinks ?? [])
    .filter((link) => link.source === 'wikilink' && link.source_entry)
    .map((link) => link.source_entry as KnowledgeLinkEnd);
}

/**
 * The entry's RED LINKS: wikilinks it draws to slugs that do not exist.
 *
 * An invitation, not an error — which is why they are returned as slugs the UI turns into "create
 * this entry" buttons rather than as broken-link warnings.
 */
export function ghostSlugs(entry: KnowledgeEntry | null | undefined): string[] {
  const slugs = (entry?.links ?? [])
    .filter((link) => link.is_ghost && link.target_slug)
    .map((link) => link.target_slug as string);
  return [...new Set(slugs)];
}

/** The first paragraph of an entry's body — the hover preview's text. */
export function firstParagraph(content: string | null | undefined, maxChars = 220): string {
  const text = (content ?? '').trim();
  if (text === '') return '';

  // Skip leading headings / blockquote markers so the preview opens on prose, not on "# Title".
  const body = text
    .split(/\r?\n\s*\r?\n/)
    .map((block) => block.trim())
    .find((block) => block !== '' && !/^#{1,6}\s/.test(block));

  const flat = (body ?? text).replace(/\s+/g, ' ').trim();
  return flat.length > maxChars ? `${flat.slice(0, maxChars).trimEnd()}…` : flat;
}

/**
 * Order entries the way the SERVER does (`position`, then `id`).
 *
 * Applied client-side after a local reorder so the contents panel, the prev/next arrows and the
 * table cannot momentarily disagree about the order while the write is in flight.
 */
export function byPosition<T extends { position: number; id: string }>(entries: T[]): T[] {
  return [...entries].sort((a, b) => a.position - b.position || a.id.localeCompare(b.id));
}

/**
 * The values of a metadata field that are NOT described by the base's schema.
 *
 * A schema change never rewrites entries (the backend keeps the values), so an entry can carry a
 * key the schema dropped. Those are shown, flagged "off-schema", rather than silently hidden —
 * hiding them is how a user concludes their data was deleted.
 */
export function offSchemaKeys(
  metadata: Record<string, unknown> | null | undefined,
  schemaKeys: readonly string[],
): string[] {
  const known = new Set(schemaKeys);
  return Object.keys(metadata ?? {}).filter((key) => !known.has(key));
}

/** A short, human excerpt for any entry shape (list row or full). */
export function entryExcerpt(entry: KnowledgeEntryListItem | KnowledgeEntry): string {
  const asList = entry as Partial<KnowledgeEntryListItem>;
  if (typeof asList.excerpt === 'string' && asList.excerpt !== '') return asList.excerpt;
  return firstParagraph((entry as Partial<KnowledgeEntry>).content);
}
