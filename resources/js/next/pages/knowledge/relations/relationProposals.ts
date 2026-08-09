// relationProposals — how a run's proposed relations are ORDERED and GROUPED for review.
//
// ------------------------------------------------------------------------------------------------
// GROUPING IS PER ENTITY, NOT PER OPERATION.
//
// Reviewing relations is CHECKING FACTS ABOUT SOMEBODY. "What will be true about Anna Kowalska" is
// one glance; "every addition in this session" makes the reviewer jump between subjects on every
// row and hold six half-checked people in their head. Grouping by operation is convenient for an
// audit log and hostile to the job actually being done here.
//
// Within a group the operations run `create` → `update` → `end`: what arrives, then what changes,
// then what goes away.
//
// ------------------------------------------------------------------------------------------------
// WHY THIS IS NOT A CARD IN THE DRAFT BOARD.
//
// A relation frequently joins TWO ENTRIES THAT ALREADY EXIST and belongs to no draft at all.
// Filing some relations under draft cards and leaving the rest homeless would split one review into
// two shapes and break bulk acceptance across them.
//
// Pure and Vue-free: the grouping, the ordering and the dependency rule are the parts worth pinning
// with tests, and none of them needs a DOM.
import type { KnowledgeProposedEntity, KnowledgeProposedRelation } from '../types';

/** A stable handle for one proposed operation — it has no id, so identity is composed. */
export function proposalKey(proposal: KnowledgeProposedRelation, index: number): string {
  return [
    proposal.op ?? 'create',
    proposal.relation ?? '',
    proposal.from ?? '',
    proposal.to ?? '',
    proposal.relation_type ?? '',
    // The index is the LAST resort, and it is needed: two `end` ops on the same relation handle
    // would otherwise collide, and a duplicate key silently drops a row from the review.
    index,
  ].join('|');
}

/**
 * The same identity, safe to put in an `id` attribute.
 *
 * `proposalKey` joins its parts with `|`, which is legal in an HTML id and NOT legal in a CSS
 * selector — so `aria-describedby` would point at an element nothing could ever look up, and the
 * reason text would be unreachable to exactly the assistive technology it exists for. Reduced to
 * `[A-Za-z0-9_-]` rather than escaped, because the id only has to be unique and referenceable.
 */
export function proposalDomId(proposal: KnowledgeProposedRelation, index: number): string {
  return `kg-op-${proposalKey(proposal, index).replace(/[^A-Za-z0-9_-]+/g, '-')}`;
}

/**
 * ONE THING A REVIEWER DECIDES ABOUT — which is not always one operation.
 *
 * "Anna moved from Acme to Nowa Firma" reaches the client as TWO operations: an `end` on the old
 * relation and a `create` for the new one. They are one change, and the server refuses to apply
 * half of it — an `end` alone says Anna works nowhere, a `create` alone says she works in two
 * places at once. Both are confident falsehoods about the world.
 *
 * So the pair is merged into a single unit here, and the panel renders one control for it. Two
 * checkboxes where one of the four combinations always 422s would be a control that does not do
 * what it appears to: the reviewer would tick a box, press accept, and be told they were not
 * allowed to have done the thing the interface offered.
 *
 * `keys` is what the unit commits — one key for an ordinary operation, both for a pair.
 */
export interface ProposalUnit {
  /** The unit's identity: the primary operation's key. */
  key: string;
  /** EVERY key this unit commits. `graph_op_keys` is built from these. */
  keys: string[];
  /** The operation the sentence is written about — the `create` half of a pair. */
  primary: KnowledgeProposedRelation;
  /** The `end` half, when this unit is a replacement. Null for a plain operation. */
  ended: KnowledgeProposedRelation | null;
}

/**
 * Merge paired operations into units, leaving everything else alone.
 *
 * The `create` becomes the primary because it is the statement that will be true afterwards, and a
 * reviewer reads a replacement forwards ("she now works at Nowa Firma") rather than backwards. The
 * `end` half is carried alongside so the sentence can name what is being closed.
 *
 * `pair_with` points BOTH ways, so a partner that is missing from the list (filtered, capped, or
 * simply absent) degrades to two independent units rather than to a unit with a dangling half —
 * a half-rendered pair would be a control committing a key the reviewer cannot see.
 */
export function mergePairs(proposals: KnowledgeProposedRelation[]): ProposalUnit[] {
  const byKey = new Map(proposals.map((proposal) => [proposal.key, proposal]));
  const consumed = new Set<string>();
  const units: ProposalUnit[] = [];

  for (const proposal of proposals) {
    if (consumed.has(proposal.key)) continue;

    const partner = proposal.pair_with ? byKey.get(proposal.pair_with) : undefined;

    if (!partner) {
      units.push({ key: proposal.key, keys: [proposal.key], primary: proposal, ended: null });

      continue;
    }

    // Whichever half is the `end` is the one being closed; the other is what will be true.
    const [primary, ended] =
      proposal.op === 'end' ? [partner, proposal] : [proposal, partner];

    consumed.add(proposal.key);
    consumed.add(partner.key);
    units.push({
      key: primary.key,
      // Sorted so the request body is stable regardless of the order the two arrived in.
      keys: [primary.key, ended.key].sort(),
      primary,
      ended,
    });
  }

  return units;
}

export interface ProposalGroup {
  /** The entity handle this group is about, or `''` when the subject end is unnamed. */
  handle: string;
  title: string;
  isDraft: boolean;
  entryType: string | null;
  proposals: KnowledgeProposedRelation[];
}

const OP_RANK: Record<string, number> = { create: 0, update: 1, end: 2 };

/**
 * Group the proposals by their SUBJECT entity.
 *
 * The subject is the `from` end — the thing the statement is about. An `update`/`end` op names an
 * existing relation rather than a pair, and the server still fills `from`/`to` for it, so those
 * land in the same group as the `create` they modify. Where it does not, the op goes to a group
 * keyed on the empty handle rather than being dropped: an operation the reviewer cannot see is one
 * they cannot approve or reject, and it would still be counted in the total.
 */
export function groupProposals(
  proposals: KnowledgeProposedRelation[],
  entities: KnowledgeProposedEntity[],
): ProposalGroup[] {
  const drafts = new Map(entities.map((entity) => [entity.handle, entity]));
  const groups = new Map<string, ProposalGroup>();

  for (const proposal of proposals) {
    const handle = proposal.from ?? '';
    const existing = groups.get(handle);

    if (existing) {
      existing.proposals.push(proposal);

      continue;
    }

    const draft = drafts.get(handle);
    groups.set(handle, {
      handle,
      // The title the server resolved, then the draft's own, then nothing readable at all — in
      // which case the group is still rendered, under a neutral name.
      title: proposal.from_title ?? draft?.title ?? '',
      isDraft: draft !== undefined,
      entryType: draft?.entry_type ?? null,
      proposals: [proposal],
    });
  }

  for (const group of groups.values()) {
    group.proposals.sort(
      (a, b) =>
        (OP_RANK[a.op ?? 'create'] ?? 0) - (OP_RANK[b.op ?? 'create'] ?? 0) ||
        (a.relation_type ?? '').localeCompare(b.relation_type ?? '') ||
        (a.to_title ?? '').localeCompare(b.to_title ?? ''),
    );
  }

  // Groups themselves read alphabetically. Not by count and not by draft-ness: a reviewer looking
  // for one person should find them in a predictable place, and "most relations first" moves the
  // whole list every time a refinement changes one number.
  return [...groups.values()].sort((a, b) => a.title.localeCompare(b.title));
}

/**
 * The drafts a proposal is WAITING ON, minus the ones already accepted.
 *
 * `depends_on_draft` is frozen at generation time, so it still names entities that have since been
 * published. Subtracting the accepted set here is what makes the block release LIVE — the reviewer
 * accepts an entry and the relation row unlocks under their hand, instead of staying disabled with
 * a reason that is no longer true.
 */
export function blockingDrafts(
  proposal: KnowledgeProposedRelation,
  acceptedHandles: ReadonlySet<string>,
): string[] {
  return proposal.depends_on_draft.filter((handle) => !acceptedHandles.has(handle));
}

/** Whether a proposal can be accepted right now. */
export function isBlocked(
  proposal: KnowledgeProposedRelation,
  acceptedHandles: ReadonlySet<string>,
): boolean {
  return blockingDrafts(proposal, acceptedHandles).length > 0;
}

/**
 * The readable title of a blocking draft — what the reason line names.
 *
 * A handle (`E3`) is meaningless to a reviewer. If the title cannot be found the handle is shown
 * rather than a blank, because "Waiting for the entry «»" is worse than an opaque code: the first
 * looks broken, the second at least says something is missing.
 */
export function draftTitle(handle: string, entities: KnowledgeProposedEntity[]): string {
  return entities.find((entity) => entity.handle === handle)?.title || handle;
}
