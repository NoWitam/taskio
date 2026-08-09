// relationLabels — how a typed relation is WORDED, and nothing else.
//
// ------------------------------------------------------------------------------------------------
// THE SPLIT: the server owns the ontology, the client owns the prose.
//
// The server sends the whole vocabulary on the base (`relation_vocabulary`): which verbs exist,
// which are symmetric, which property keys each accepts, and which entry types may sit at either
// end. NONE of that is duplicated here — copying a type matrix into the frontend guarantees the two
// disagree the first time the ontology moves, and the client would be confidently wrong.
//
// What IS here is the wording, and that is not an oversight either. `KnowledgeRelationResource`
// carries `label` / `inverse_label`, but those are rendered with the SERVER's locale — Laravel's
// `__()` against `APP_LOCALE`, a per-deployment env value. The next frontend switches language in
// the browser and sends no locale to the API, so on a Polish UI those strings would arrive in
// English. A user would read "Anna member of Acme" in an otherwise Polish sentence.
//
// So: verbs are translated CLIENT-side, keyed by the id, and the server's own wording is the
// fallback for a verb this catalog has not learned yet. A sixteenth verb shipped by the backend
// then renders in the server's language instead of as a raw `member_of`, which is the right
// failure — degraded, not broken.
//
// ------------------------------------------------------------------------------------------------
// DIRECTION IS DATA, NOT STRING COMPARISON.
//
// A relation panel is read from ONE entry's point of view, so the same row is worded two ways:
// "Anna is a member of Acme" on Anna's page, "Acme has member Anna" on Acme's. Symmetric verbs
// (`knows`, `opposes`) word IDENTICALLY in both directions — that is intentional, and it is read
// off `symmetric` in the vocabulary, never inferred by comparing the two label strings. Two verbs
// could coincide in one language and diverge in another, and a UI that guessed would draw an
// arrowhead on a claim that has no direction.
//
// Pure and Vue-free so every rule below is pinned by unit tests rather than by rendered DOM.
import type {
  KnowledgeRelation,
  KnowledgeRelationTypeId,
  KnowledgeRelationVocabularyItem,
} from '../types';
import { translate } from '../../../app/i18n';

/** Which way a statement is being read. */
export type RelationDirection = 'forward' | 'inverse';

/**
 * The editorial grouping the type picker uses. Fifteen flat options is a list people stop reading;
 * five named clusters of two to four is a list they scan.
 *
 * This is PRESENTATION, not ontology — the server has no opinion on it, and a verb missing from
 * every group still renders (see `groupedVocabulary`), it just lands in the trailing bucket rather
 * than vanishing from the picker.
 */
export const RELATION_GROUPS: Array<{ id: string; types: KnowledgeRelationTypeId[] }> = [
  { id: 'affiliation', types: ['member_of', 'owns', 'part_of', 'is_a'] },
  { id: 'action', types: ['works_on', 'created', 'uses', 'participated_in'] },
  { id: 'spacetime', types: ['located_in', 'occurred_during', 'precedes'] },
  { id: 'social', types: ['knows', 'opposes'] },
  { id: 'dependency', types: ['depends_on', 'caused'] },
];

/**
 * The verb, worded for a direction.
 *
 * `fallback` is the server's own label for that direction — passed in by the caller because only
 * the caller knows whether it is holding a relation, a graph edge or a vocabulary row.
 */
export function predicateLabel(
  type: KnowledgeRelationTypeId | string | null,
  direction: RelationDirection,
  fallback?: string | null,
): string {
  if (!type) return fallback ?? '';

  const key = `knowledge.relations.predicate.${type}.${direction}`;
  const translated = translate(key);

  // `translate` echoes the key back when it is missing, which is the signal that this build does
  // not know the verb. Prefer the server's wording over showing a dotted path to a user.
  if (translated !== key) return translated;

  return fallback ?? type;
}

/** The verb of a RELATION, worded for the end it is being read from. */
export function relationPredicate(
  relation: Pick<KnowledgeRelation, 'relation_type' | 'label' | 'inverse_label' | 'symmetric'>,
  direction: RelationDirection,
): string {
  // A symmetric claim reads the same both ways; asking for the inverse of one is not an error, it
  // just does not change anything. Resolving that HERE means no caller has to remember it.
  const effective = relation.symmetric ? 'forward' : direction;

  return predicateLabel(
    relation.relation_type,
    effective,
    effective === 'forward' ? relation.label : relation.inverse_label,
  );
}

/**
 * Which way to read a relation when looking at `entryId`.
 *
 * Looking at the SOURCE, the statement reads forward; looking at the target, it reads backwards.
 * An entry that is neither end reads forward, because that is the relation's own direction and
 * inventing an inverse for a bystander would be worse than plain.
 */
export function directionFrom(
  relation: Pick<KnowledgeRelation, 'from_entry_id' | 'to_entry_id'>,
  entryId: string | null,
): RelationDirection {
  return entryId !== null && relation.to_entry_id === entryId && relation.from_entry_id !== entryId
    ? 'inverse'
    : 'forward';
}

/**
 * The two ends of a relation, ordered for the direction it is being read in.
 *
 * Returned as `{ subject, object }` rather than `{ from, to }` on purpose: past this point the
 * caller is building a SENTENCE, and a sentence has a subject, not a source.
 */
export function relationEnds(
  relation: Pick<KnowledgeRelation, 'from_entry' | 'to_entry'>,
  direction: RelationDirection,
) {
  return direction === 'inverse'
    ? { subject: relation.to_entry ?? null, object: relation.from_entry ?? null }
    : { subject: relation.from_entry ?? null, object: relation.to_entry ?? null };
}

/**
 * The label of one PROPERTY key (`role`, `share`, `how`, …).
 *
 * The set is closed and small — each verb declares zero or one — so these earn real names rather
 * than the raw identifier the server happens to store them under. Falls back to the key itself for
 * anything the backend adds before this catalog learns it: an English-looking word beats a blank
 * label, and it is still the thing the API will echo back.
 */
export function propertyLabel(key: string): string {
  const translated = translate(`knowledge.relations.property.${key}`);

  return translated === `knowledge.relations.property.${key}` ? key : translated;
}

/** The label of one entry type. `null` becomes "Unspecified" — a name, never a warning. */
export function entryTypeLabel(type: string | null): string {
  return translate(`knowledge.entityType.${type ?? 'unspecified'}`);
}

/** The icon for an entry type, for the text badge. Never used on a graph node — see G9. */
export function entryTypeIcon(type: string | null): string {
  const icons: Record<string, string> = {
    person: 'user',
    organization: 'users',
    event: 'calendar',
    place: 'map-pin',
    product: 'package',
    work: 'bookmark',
    concept: 'braces',
    other: 'circle',
  };

  return icons[type ?? ''] ?? 'circle';
}

/**
 * The vocabulary split into the picker's groups, in `RELATION_GROUPS` order.
 *
 * Only verbs the BASE allows appear — `vocabulary` is the whole catalog and `allowed` is this
 * base's subset, and a picker offering fifteen verbs where three apply is a picker people stop
 * reading. Anything allowed but ungrouped lands in a trailing `other` group rather than being
 * dropped: a verb the backend adds must stay reachable before this file learns where to file it.
 */
export function groupedVocabulary(
  vocabulary: KnowledgeRelationVocabularyItem[],
  allowed: string[] | null,
): Array<{ id: string; items: KnowledgeRelationVocabularyItem[] }> {
  const permitted = allowed === null ? null : new Set(allowed);
  const usable = vocabulary.filter((item) => permitted === null || permitted.has(item.id));
  const filed = new Set<string>();

  const groups = RELATION_GROUPS.map((group) => {
    const items = group.types
      .map((id) => usable.find((item) => item.id === id))
      .filter((item): item is KnowledgeRelationVocabularyItem => item !== undefined);

    for (const item of items) filed.add(item.id);

    return { id: group.id, items };
  }).filter((group) => group.items.length > 0);

  const ungrouped = usable.filter((item) => !filed.has(item.id));

  return ungrouped.length > 0 ? [...groups, { id: 'other', items: ungrouped }] : groups;
}

/** One vocabulary row by id, or null. The client's only lookup into the server's ontology. */
export function vocabularyItem(
  vocabulary: KnowledgeRelationVocabularyItem[],
  type: string | null,
): KnowledgeRelationVocabularyItem | null {
  return vocabulary.find((item) => item.id === type) ?? null;
}

/**
 * Whether this verb ORDINARILY joins these two entry types — the advisory check, mirrored from
 * `RelationVocabulary::checkTypes()`, including its deliberate soft edge.
 *
 * Returns `null` — meaning "no opinion" — when either end is untyped or is `other`. That is not
 * laziness: every entry written before `entry_type` existed is untyped, and a check that refused on
 * unknown types would light up a warning on every correct relation in an old base. `other` is the
 * writer saying no class applies, so constraining by it would be inventing a rule out of an
 * admission that none exists.
 *
 * The answer is ADVISORY in every case. The server accepts an unusual pairing and so does the UI;
 * the copy says "unusual", never "wrong", because the matrix is an editorial heuristic and not an
 * ontology with guarantees.
 */
export function pairingVerdict(
  item: KnowledgeRelationVocabularyItem | null,
  fromType: string | null,
  toType: string | null,
): 'ok' | 'unusual' | null {
  if (!item) return null;
  if (!fromType || !toType || fromType === 'other' || toType === 'other') return null;

  return item.from_types.includes(fromType as never) && item.to_types.includes(toType as never)
    ? 'ok'
    : 'unusual';
}
