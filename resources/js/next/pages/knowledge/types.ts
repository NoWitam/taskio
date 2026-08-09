// Knowledge module wire types (next frontend).
//
// Every shape here is VERIFIED against `app/modules/Knowledge` — bases, entries, revisions, links,
// search hits, the graph, and the AI composer. Nothing is written from a guess, and each section
// names the resource/service it mirrors so the next reader can re-check it in one hop.
//
// The rule the whole file exists to enforce: DO NOT INVENT FIELDS. A shape that describes what the
// UI wishes the server sent is how a screen ends up rendering `undefined` in production, and the
// type system will not catch it — the response is `any` at the seam.
//
//   routes  app/modules/Knowledge/routes/api.php
import type { Creator } from '../../ui/patterns/creator';
import type { VariableDescriptorField } from '../../ui/variables/types';

/**
 * A base's METADATA SCHEMA field, exactly as stored: `{key, label, descriptor}` where
 * `descriptor` is a shared Variables descriptor (`{base, nullable, array, options?, fields?}`).
 *
 * It is a straight alias of the design-system `VariableDescriptorField` — the backend
 * validates this list through the SAME `ConstantTypeValidator` a constant's type goes
 * through (see KnowledgeMetadataValidator), so a second, module-local shape would be a
 * copy of a contract that is already shared.
 */
export type KnowledgeSchemaField = VariableDescriptorField;

/**
 * A KNOWLEDGE BASE (KnowledgeBaseResource, 1:1).
 *
 * Two capability flags, and they are NOT interchangeable:
 *   • `can_be_edited`  — `update`: name / description / language. Any workspace member.
 *   • `can_be_managed` — `manage`: the CHARTER and the METADATA SCHEMA (governance; the base's
 *     creator or the workspace owner). Sending a governed field a member may not change is a
 *     403 for the WHOLE request, so the UI must gate the two independently.
 *
 * `entries_count` is `whenCounted('entries')`: present on the list (`withCount`) and on `show`,
 * ABSENT on a create/update response — hence optional.
 *
 * `ghost_links_count` + `index_summary` are the B2b CARD CONTRACT: added after B4 wrote this file,
 * and ALWAYS present (KnowledgeBaseService::attachAggregates runs on every controller path,
 * including store/update). They are what lets a base card render from the LIST response alone.
 * NOTE the UNIT difference the backend states explicitly: at BASE level `index_summary` counts
 * ENTRIES (`total === entries_count`), while an ENTRY's own `index.chunks_count` counts CHUNKS.
 * Mixing the two would render "3/50" from two different denominators.
 *
 * Still NOT in this contract (and therefore not rendered anywhere): `schema_version` and
 * `incomplete_entries_count`. The spec's §7.4 "entries with missing metadata" banner has no
 * server-side counter, so it is not built.
 */
export interface KnowledgeBase {
  id: string;
  name: string;
  description: string | null;
  charter: string | null;
  /** A short language tag: `pl`, `en`, `pt-BR` (backend regex `^[a-z]{2}(-[A-Za-z]{2})?$`). */
  language: string;
  metadata_schema: KnowledgeSchemaField[];
  /**
   * The verbs THIS base allows, already RESOLVED: a base that never narrowed the vocabulary is
   * answered with the full list rather than with null, so a picker can render this directly and
   * never has to know that "unconfigured" and "allows everything" are the same thing here.
   */
  relation_types: KnowledgeRelationTypeId[];
  /** The catalog those ids index into — labels, symmetry, property keys, the type matrix. */
  relation_vocabulary: KnowledgeRelationVocabularyItem[];
  entries_count?: number;
  /** Unresolved `[[wikilinks]]` in this base — the red-link chip. Render ONLY when > 0. */
  ghost_links_count: number;
  /** Per-ENTRY index census. `{}` is possible on an empty base; every key is optional. */
  index_summary: KnowledgeIndexSummary;
  creator?: Creator | null;
  is_owner: boolean;
  can_be_edited: boolean;
  can_be_managed: boolean;
  can_be_deleted: boolean;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/**
 * The base-level index census (`KnowledgeBaseResource.index_summary`). Counted in ENTRIES.
 * `total` equals `entries_count`, so a badge can be a fraction without a second lookup.
 */
export interface KnowledgeIndexSummary {
  total?: number;
  indexed?: number;
  pending?: number;
  indexing?: number;
  partial?: number;
  pending_budget?: number;
  failed?: number;
}

/** The page-owned list filters, mirroring the ONLY two server query params. */
export interface KnowledgeBaseFilters {
  /** `?search=` — matched against name + description (KnowledgeBaseService::index). */
  search?: string;
  /** `?trashed=1` — list the workspace's trashed bases instead of its live ones. */
  trashed?: boolean;
}

/**
 * The create/update body.
 *
 * Every key is OPTIONAL on purpose: on PATCH an ABSENT key means UNCHANGED (see
 * UpdateKnowledgeBaseRequest::resolved*), so a plain rename must NOT carry `charter` /
 * `metadata_schema` — echoing them back is both a wipe hazard if they drift and the
 * difference between needing `update` and needing `manage`.
 *
 * `name` is required by the backend on CREATE; the form guarantees it there.
 */
export interface KnowledgeBaseWritePayload {
  name?: string;
  description?: string | null;
  charter?: string | null;
  language?: string;
  metadata_schema?: KnowledgeSchemaField[];
}

/** `{ data: KnowledgeBase[], meta: { next_cursor } }` — a cursor page (25/page, name-ordered). */
export interface KnowledgeBaseListResponse {
  data: KnowledgeBase[];
  meta?: { next_cursor?: string | null };
}

/** `{ data: KnowledgeBase }` — every single-base read/write. */
export interface KnowledgeBaseResponse {
  data: KnowledgeBase;
}

/**
 * The EDITORIAL status of an entry (KnowledgeEntryStatus). Declared here — rather than with the
 * entry shape in B5 — because `statusMaps.ts` renders it and the enum is already closed.
 */
export type KnowledgeEntryStatus = 'draft' | 'proposed' | 'approved' | 'archived';

/**
 * The INDEXING state of an entry (KnowledgeIndexStatus). Orthogonal to the editorial status.
 * B1 only ever writes `pending`; the remaining states are produced by the indexing worker (B2a).
 */
export type KnowledgeIndexStatus =
  | 'pending'
  | 'indexing'
  | 'indexed'
  | 'partial'
  | 'pending_budget'
  | 'failed';

// ---------------------------------------------------------------------------
// ENTRIES (B5)
//
// Backend contract (VERIFIED against app/modules/Knowledge — do NOT invent fields):
//   resources  Http/Resources/KnowledgeEntry{,List}Resource.php, KnowledgeLinkResource.php,
//              KnowledgeEntryRevisionResource.php, KnowledgeSearchResultResource.php
//   requests   Store/UpdateKnowledgeEntryRequest.php, KnowledgeSearchRequest.php,
//              ReorderKnowledgeEntriesRequest.php
//   service    KnowledgeEntryService::index()  (cursorPaginate(25), orderBy position,id)
//   errors     Exceptions/{StaleKnowledgeWrite,KnowledgeSlugConflict}Exception.php
// ---------------------------------------------------------------------------

/** The `index` object both entry resources carry. Counted in CHUNKS (contrast KnowledgeIndexSummary). */
export interface KnowledgeEntryIndex {
  status: KnowledgeIndexStatus | null;
  /** Chunks currently stored for this entry. Null until the entry has been chunked at all. */
  chunks_count: number | null;
  /**
   * B6c — how many of those chunks carry a CURRENT vector, i.e. the NUMERATOR that makes
   * `chunks_count` mean something ("7 of 8 passages are searchable", not just "partly indexed").
   *
   * `null` means "this connection CANNOT COUNT" (no vector support), which is emphatically NOT
   * zero: rendering 0/8 there would read as total data loss. A null falls back to showing the bare
   * `chunks_count` — see `entryIndexLabel`.
   */
  indexed_chunks_count: number | null;
  /** Derived from the digest PAIR, not from `status` — true when the content moved on. */
  needs_indexing: boolean;
  /**
   * B6c — whether `POST /knowledge/entries/{id}/retry-index` would be accepted. True for exactly
   * `failed`, `pending_budget` and `partial`; `indexed` has nothing to do, `indexing` is claimed by
   * a live worker, and `pending` is already queued. The UI shows the button ONLY on this flag
   * rather than re-deriving the rule, which is how the two would drift.
   */
  can_retry: boolean;
}

/**
 * The fields BOTH entry resources share. The list resource swaps `content` for `excerpt`; nothing
 * else differs, which is why the search-result resource can delegate to the list one wholesale.
 */
interface KnowledgeEntryCommon {
  id: string;
  knowledge_base_id: string;
  title: string;
  /** Stable handle minted from the FIRST title. Wikilinks address entries by this, never by id. */
  slug: string;
  /**
   * The other names this entry answers to. These feed the MENTION layer only — a `[[wikilink]]`
   * still resolves by slug and nothing else, so an alias can never move an authored edge.
   */
  aliases: string[];
  /**
   * What kind of thing this entry is. `null` on EVERY entry written before typed relations, and
   * that is a normal state, not a gap: the reader renders nothing for it, the graph draws the node
   * exactly as before, and only the settings panel names it — neutrally, as "Unspecified".
   */
  entry_type: KnowledgeEntryType | null;
  metadata: Record<string, unknown>;
  status: KnowledgeEntryStatus | null;
  stale_at: string | null;
  is_stale: boolean;
  position: number;
  /** The OPTIMISTIC-LOCK token — echo it back as `expected_revision_id` on every write. */
  current_revision_id: string | null;
  index: KnowledgeEntryIndex;
  creator?: Creator | null;
  is_owner: boolean;
  can_be_edited: boolean;
  can_be_deleted: boolean;
  can_be_purged: boolean;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/**
 * An entry as a LIST row: no `content`, a 200-char whitespace-collapsed `excerpt` instead.
 *
 * That excerpt is also the ONLY cheap description of an entry the API offers — there is no
 * `…/preview` endpoint (spec §19 q6 was answered "no"), so the reader's hover popover is fed from
 * the list rows it already loaded rather than from a per-hover request.
 */
export interface KnowledgeEntryListItem extends KnowledgeEntryCommon {
  excerpt: string;
}

/** An entry in FULL — the reader's and the editor's payload. Carries links only on `show`. */
export interface KnowledgeEntry extends KnowledgeEntryCommon {
  content: string;
  /** Edges drawn BY this entry. Present only on the single-entry routes (whenLoaded). */
  links?: KnowledgeLink[];
  /** Edges drawn AT this entry. Present only on the single-entry routes (whenLoaded). */
  backlinks?: KnowledgeLink[];
}

/**
 * How an edge came to exist (KnowledgeLinkSource, 1:1).
 *
 *   wikilink    the source entry's text says `[[target]]`. The content is the authority.
 *   similarity  the vector layer proposed it, with a `score` and `evidence`.
 *   mention     the target's NAME appears in the source's text (B10). Textual, not statistical:
 *               no score, and its `evidence` is where the name was found. DIRECTIONAL — A can
 *               mention B without B mentioning A, and both directions can exist as two edges.
 *   manual      a human drew it. Nothing automated may remove it.
 *
 * `wikilink` and `mention` are MUTUALLY EXCLUSIVE per target and the wikilink wins, so a pair
 * never carries both.
 */
export type KnowledgeLinkSource = 'wikilink' | 'similarity' | 'mention' | 'manual';

/**
 * One edge of the knowledge graph, in either direction (KnowledgeLinkResource).
 *
 * `is_ghost` is stated by the server, never inferred from a null `to_entry_id`: a ghost is the
 * "red link" feature — an invitation to write the entry — and re-deriving it per consumer is how it
 * ends up rendered as an error in one place and a broken link in another.
 *
 * `score` / `evidence` / `dismissed_at` are filled for MACHINE-DERIVED edges only. What `evidence`
 * MEANS depends on the kind, and the two are not interchangeable:
 *   • `similarity` — the pair of chunk ORDINALS behind the proposal (a citation address).
 *   • `mention`    — `{char_start, char_length}` into the SOURCE entry's own content, so
 *                    `mb_substr(source.content, char_start, char_length)` is the mentioning text.
 *                    See `mentionText()` in `entryMeta` — the offsets count CODE POINTS.
 *
 * `can_be_dismissed` is the server's own verdict (`KnowledgeLinkSource::isDismissable()`): true for
 * the two DERIVED kinds, false for what a human wrote or drew. Read the FLAG — never re-derive it
 * from `source`, or a fifth kind means editing every consumer again.
 */
export interface KnowledgeLink {
  id: string;
  knowledge_base_id: string;
  from_entry_id: string;
  to_entry_id: string | null;
  /** The slug the edge points at. For a ghost this is the only handle that exists. */
  target_slug: string | null;
  source: KnowledgeLinkSource | null;
  is_ghost: boolean;
  /** Cosine similarity in [0,1] for a `similarity` edge; null otherwise. */
  score: number | null;
  evidence: KnowledgeLinkEvidence | null;
  /** Whether a human may dismiss this edge. Server-stated; do not infer it from `source`. */
  can_be_dismissed: boolean;
  /** Set while the user has dismissed this proposal. The row STAYS visible, with an undo. */
  dismissed_at: string | null;
  /** The far end, loaded on the OUTGOING list. Null when the edge is a ghost. */
  target?: KnowledgeLinkEnd | null;
  /** The near end, loaded on the BACKLINK list. */
  source_entry?: KnowledgeLinkEnd | null;
  created_at: string | null;
}

/** The minimal {id,title,slug} either end of an edge is loaded as — never the whole entry. */
export interface KnowledgeLinkEnd {
  id: string;
  title: string;
  slug: string;
}

// ---------------------------------------------------------------------------
// TYPED RELATIONS — the fifth graph layer, and the only one a HUMAN authored the
// meaning of.
//
//   model     Models/KnowledgeRelation.php
//   resource  Http/Resources/KnowledgeRelationResource.php
//   enums     Enums/KnowledgeRelationType.php · KnowledgeRelationState.php ·
//             KnowledgeRelationOrigin.php · KnowledgeEntryType.php
//
// The four older layers are DERIVED — the machine's reading of text, rebuilt on every save. A
// relation is ASSERTED: somebody said it is so, with a verb from a closed vocabulary, an optional
// span of validity, and a description for whoever reads it next. That is why it cannot be
// `dismissed` (a guess can be refused; a statement is ENDED when it stops being true, or RETRACTED
// when it never was) and why it is the only layer whose edge carries a written label.
// ---------------------------------------------------------------------------

/**
 * The 15 verbs (KnowledgeRelationType). A closed vocabulary on purpose: an open one becomes a
 * synonym swamp within a month, and nothing downstream can reason about it.
 *
 * This union exists for autocomplete and exhaustiveness, NOT as the source of truth. What a given
 * base allows, what each verb means, which property keys it takes and which entry types it may
 * join all arrive on the base as `relation_vocabulary` — the client never re-implements ontology.
 */
export type KnowledgeRelationTypeId =
  | 'member_of'
  | 'works_on'
  | 'knows'
  | 'created'
  | 'owns'
  | 'located_in'
  | 'participated_in'
  | 'occurred_during'
  | 'part_of'
  | 'is_a'
  | 'uses'
  | 'depends_on'
  | 'precedes'
  | 'caused'
  | 'opposes';

/**
 * The lifecycle, and all three are DIFFERENT SENTENCES:
 *
 *   active     a claim about now.
 *   ended      a claim about the past. `valid_to` says when it stopped being true.
 *   retracted  "this was never true" — the record survives, marked as withdrawn.
 *
 * Note what `retracted` is NOT: it is not deletion. `POST …/end {retract:true}` keeps the row.
 * Destroying a relation outright is `DELETE`, a separate and more restricted verb.
 */
export type KnowledgeRelationState = 'active' | 'ended' | 'retracted';

/**
 * Where the statement came from, which is the question a reviewer actually asks.
 *
 *   human     a person wrote it.
 *   composer  a model proposed it and a person clicked accept.
 *   promoted  a person turned a machine SUGGESTION (similarity/mention) into an assertion.
 *
 * ALL THREE STAY, INCLUDING THE TWO NOTHING WRITES ANY MORE. Hand-authorship and link-promotion
 * were withdrawn (ADR-0049 D2), so only `composer` is produced from here on — but `origin` is a
 * CAST ON A STORED COLUMN and a published API value (`origin` on the relation and graph resources),
 * and the backend deliberately kept both cases for exactly that reason. A row asserted before the
 * pivot still carries them, so a client type that omitted them would be lying about a value it will
 * receive. This is a narrowing of a documented response field, not a tidy-up.
 */
export type KnowledgeRelationOrigin = 'human' | 'composer' | 'promoted';

/**
 * What kind of thing an entry is (KnowledgeEntryType). Seven real categories plus `other`, which
 * is the writer saying no class applies — treated as UNKNOWN by the type matrix rather than as a
 * constraint, because inventing a rule out of an admission that none exists is worse than none.
 *
 * `null` is the NORMAL state: every entry written before this existed carries it, and the UI must
 * render nothing at all for it. A warning badge would turn a whole base into a list of defects.
 */
export type KnowledgeEntryType =
  | 'person'
  | 'organization'
  | 'event'
  | 'place'
  | 'product'
  | 'work'
  | 'concept'
  | 'other';

/** Either end of a relation: the minimum needed to render a sentence and route to the entry. */
export interface KnowledgeRelationEnd {
  id: string;
  title: string;
  slug: string;
  entry_type: KnowledgeEntryType | null;
}

/** One typed relation (KnowledgeRelationResource). */
export interface KnowledgeRelation {
  id: string;
  knowledge_base_id: string;
  from_entry_id: string;
  to_entry_id: string;
  relation_type: KnowledgeRelationTypeId | null;
  /**
   * The server's wording, both readings. Rendered in the SERVER's locale, which the next
   * frontend's language switch does not reach — so treat these as a FALLBACK behind the client's
   * own catalog. See `relations/relationLabels.ts`.
   */
  label: string | null;
  inverse_label: string | null;
  /** The claim reads the same both ways — draw it undirected, word it the same from either end. */
  symmetric: boolean;
  description: string | null;
  properties: Record<string, string | number | boolean | null>;
  /** Dates, never timestamps — these facts are known to the day at best. ISO `yyyy-mm-dd`. */
  valid_from: string | null;
  valid_to: string | null;
  state: KnowledgeRelationState | null;
  is_active: boolean;
  /** The relation that replaced this one, when it was superseded rather than merely ended. */
  superseded_by_id: string | null;
  origin: KnowledgeRelationOrigin | null;
  from_entry?: KnowledgeRelationEnd | null;
  to_entry?: KnowledgeRelationEnd | null;
  creator?: Creator | null;
  can_be_edited: boolean;
  /** Ending is ordinary member work and only offered while the relation is still active. */
  can_be_ended: boolean;
  /** Destroying the statement is NOT ordinary member work — a narrower flag on purpose. */
  can_be_deleted: boolean;
  created_at: string | null;
  updated_at: string | null;
}

/**
 * One verb as the SERVER describes it (`KnowledgeRelationType::catalog()`), delivered on the base
 * so a relation editor needs no second request.
 *
 * Everything structural lives here and nowhere else in the client: which property keys the verb
 * accepts, and which entry types may sit at either end. Copying that into the frontend would
 * guarantee drift the first time the ontology moves. What the client DOES own is the wording.
 */
export interface KnowledgeRelationVocabularyItem {
  id: KnowledgeRelationTypeId;
  label: string;
  inverse_label: string;
  symmetric: boolean;
  property_keys: string[];
  from_types: KnowledgeEntryType[];
  to_types: KnowledgeEntryType[];
}

// THERE ARE NO RELATION WRITE PAYLOADS, and that is the product decision rather than an omission.
// A person can no longer add, edit, end or delete a relation (or an entry) by hand — only the AI
// composer proposes and a person approves or refuses, through a drafting session. The routes,
// their FormRequests and the editor component are gone and the policy denies all four abilities
// (ADR-0049 D2), so `KnowledgeRelationCreatePayload` / `…UpdatePayload` / `…EndPayload` described
// requests that would now be refused. Deleted with the `editor` copy block that dressed them.
//
// The SERVICE still performs every one of those operations — the composer and the applier call it
// the moment a human accepts a proposal. What is gone is the surface that let a person call it.

/** CONTRACT MIRROR of the `max:` on `description` the composer's own relation writes accept. */
export const KNOWLEDGE_RELATION_DESCRIPTION_MAX = 300;

/** The 422 code a second identical relation comes back as; `context.existing_relation_id` says which. */
export const KNOWLEDGE_RELATION_DUPLICATE = 'knowledge_relation_duplicate';

/**
 * Whatever KnowledgeSimilarityLinker stored as the proposal's justification. Deliberately open:
 * the UI renders the pairs it finds as `label: value` and invents nothing.
 */
export type KnowledgeLinkEvidence = Record<string, unknown>;

/** One append-only snapshot of an entry (KnowledgeEntryRevisionResource). No `updated_at` — by design. */
export interface KnowledgeEntryRevision {
  id: string;
  knowledge_entry_id: string;
  title: string;
  content: string;
  metadata: Record<string, unknown>;
  change_note: string | null;
  /** The author of THAT version — named `author`, not `creator`, on purpose. */
  author?: Creator | null;
  created_at: string | null;
}

/**
 * The entry list's server filters — and ONLY these three exist
 * (KnowledgeEntryService::filtered). There is no sort param: the order is always
 * `position, id`, which is what makes the reader's contents panel and the table agree.
 */
export interface KnowledgeEntryFilters {
  /** `?status[]=` — repeated; anything outside the enum is dropped server-side. */
  status?: KnowledgeEntryStatus[];
  /** `?stale=1` — only entries flagged as needing a refresh. */
  stale?: boolean;
  /** `?search=` — matched against title + content (the shared Searchable scope). */
  search?: string;
  /**
   * B6c — `?trashed=1` swaps the page to the base's DELETED entries (`onlyTrashed`). Same
   * pagination, same resource, and it composes with the three filters above. An entry-level trash
   * the base-level one cannot express: a base whose entries were deleted one at a time is not
   * itself deleted.
   */
  trashed?: boolean;
}

/**
 * The entry create/update body.
 *
 * PATCH SEMANTICS, and they are the whole reason this type is all-optional: an ABSENT key means
 * UNCHANGED (UpdateKnowledgeEntryRequest::resolved*), so a caller sends a DIFF. Two consequences
 * worth stating because getting either wrong loses data:
 *   • Never echo `content` back on a metadata-only save — it is a 40 000-character round trip that
 *     also re-chunks and re-indexes the entry for nothing.
 *   • `stale_at: null` PRESENT clears the flag; `stale_at` absent leaves it alone. Those are
 *     different requests, so the flag's control must send the key explicitly.
 *
 * `expected_revision_id` is the optimistic lock: pass the `current_revision_id` you read, and a
 * concurrent save comes back as a 409 you can act on instead of a silent overwrite.
 */
export interface KnowledgeEntryWritePayload {
  title?: string;
  content?: string | null;
  metadata?: Record<string, unknown>;
  status?: KnowledgeEntryStatus;
  stale_at?: string | null;
  change_note?: string;
  expected_revision_id?: string | null;
  /** UPDATE only. Normalized server-side; a mismatch is a 422, not a silent rewrite. */
  slug?: string;
  /**
   * Absent means unchanged; present-but-EMPTY clears the list. Deduped case-insensitively
   * server-side, and anything past {@link KNOWLEDGE_ALIAS_MAX} is dropped rather than refused.
   */
  aliases?: string[];
  /** Absent means unchanged; explicit `null` clears it back to unspecified. */
  entry_type?: KnowledgeEntryType | null;
}

/** `{ data: KnowledgeEntryListItem[], meta: { next_cursor } }` — a cursor page (25/page). */
export interface KnowledgeEntryListResponse {
  data: KnowledgeEntryListItem[];
  meta?: { next_cursor?: string | null };
}

/** `{ data: KnowledgeEntry }` — every single-entry read/write. */
export interface KnowledgeEntryResponse {
  data: KnowledgeEntry;
}

/** `{ data: KnowledgeEntryRevision[] }` — the whole history, unpaginated. */
export interface KnowledgeRevisionListResponse {
  data: KnowledgeEntryRevision[];
}

/** `{ data: KnowledgeLink }` — a dismiss / undismiss result. */
export interface KnowledgeLinkResponse {
  data: KnowledgeLink;
}

// --- Search ----------------------------------------------------------------

/**
 * The CITATION on a search hit, and the only place highlighting is described.
 *
 * Every position is a CHARACTER OFFSET into the ENTRY'S OWN `content`, never into markup:
 *
 *     mb_substr(entry.content, char_start, char_length) === snippet
 *
 * and each `highlights` pair is `[start, length]` RELATIVE TO THE SNIPPET. The server deliberately
 * returns no HTML, so the client renders segments from the offsets — see `search/highlightSegments`.
 *
 * `ordinal` / `heading_path` / `score` are null when the entry matched on the KEYWORD leg alone
 * (not yet indexed, or words match while meaning does not). That is a normal result, not a
 * degraded one — the row renders the same, just without a percentage.
 *
 * `truncated_before` / `truncated_after` say whether text exists on either side, so an ellipsis is
 * drawn where there really is more. They exist BECAUSE the snippet is a verbatim slice: putting the
 * "…" into the string would break the offset invariant above.
 */
export interface KnowledgeMatchedChunk {
  ordinal: number | null;
  /**
   * The passage's heading trail as ONE ALREADY-JOINED STRING — e.g. `"Cennik > Zwroty"` — NOT an
   * array. The server flattens it before it is ever stored (`KnowledgeChunker::formatPath()` joins
   * with `KnowledgeChunker::PATH_SEPARATOR`, `' > '`, and the column holds the joined text), so
   * this is a string on the wire and `ChunkMatch::$headingPath` is `?string`.
   *
   * Typing it as `string[]` is how a renderer ends up iterating it CHARACTER BY CHARACTER (Vue's
   * `v-for` happily walks a string) and printing `C › e › n › n › i › k`, with a `.length` check
   * that passes because a string has one. Split it with `headingPathParts()` in
   * `search/highlightSegments` — never index or iterate it directly.
   */
  heading_path: string | null;
  /** A real cosine similarity in [0,1] — this, never `rrf_score`, is the "match: 87%" number. */
  score: number | null;
  snippet: string;
  char_start: number;
  char_length: number;
  highlights: Array<[number, number]>;
  truncated_before: boolean;
  truncated_after: boolean;
}

/** One search result: a list-shaped entry + why it is here. */
export interface KnowledgeSearchResult extends KnowledgeEntryListItem {
  /** Always present on BOTH endpoints, so a global row never has to ask where it belongs. */
  base: { id: string; name: string } | null;
  matched_chunk: KnowledgeMatchedChunk | null;
  /** How many of this entry's passages made the vector leg's top-K. Zero for a keyword-only hit. */
  matched_chunks_count: number;
  /** A fused RANK score, comparable only within one response. NOT a percentage — never rendered. */
  rrf_score: number;
}

/** Why the semantic leg did not run (`meta.vector_search_reason`). */
export type KnowledgeVectorSkipReason = 'budget' | 'disabled' | 'unsupported' | 'error' | null;

/**
 * The search response envelope. NOT paginated, by design: a relevance ranking has no stable cursor
 * (the ordering is recomputed from a fresh query embedding each time), so `has_more` means
 * "the list was CUT at `limit`", not "fetch page 2".
 */
export interface KnowledgeSearchResponse {
  data: KnowledgeSearchResult[];
  meta: {
    query: string;
    count: number;
    limit: number;
    has_more: boolean;
    vector_search_skipped: boolean;
    vector_search_reason: KnowledgeVectorSkipReason;
  };
}

// --- Graph (B5b) -----------------------------------------------------------
//
// Backend contract (VERIFIED against app/modules/Knowledge — do NOT invent fields):
//   route     GET /knowledge/bases/{base}/graph      routes/api.php
//   request   Http/Requests/KnowledgeGraphRequest.php + DTOs/KnowledgeGraphQuery.php
//   resource  Http/Resources/KnowledgeGraphResource.php
//   service   Services/KnowledgeGraphService.php
//
// Two modes, ONE shape: without `?entry=` the response is the base OVERVIEW (its most connected
// entries, topped up with recently updated isolated ones so a young base does not render an empty
// canvas); with it, the EGO neighbourhood of that entry to `?depth=` hops.
// ---------------------------------------------------------------------------

/**
 * One node. DELIBERATELY minimal — no content, no excerpt, no metadata: what a node carries is
 * what changes how it is DRAWN.
 *
 * `degree` counts the edges present in THIS response (not in the base), and `distance` is hops
 * from the centre — 0 for the centre itself, `null` in the overview, which has no centre.
 */
export interface KnowledgeGraphNode {
  id: string;
  slug: string;
  title: string;
  /**
   * What KIND of thing this node is, or null where nobody has said — which is most nodes, and is
   * a normal state rather than a gap. The graph deliberately does NOT encode it visually: seven
   * categories cannot be told apart in a 12px glyph, and shape is already spoken for by
   * ghost/draft/amended. It lives as a text badge in the lists instead.
   */
  entry_type: KnowledgeEntryType | null;
  status: KnowledgeEntryStatus | null;
  is_stale: boolean;
  degree: number;
  distance: number | null;
}

/**
 * One edge. Both endpoints are GUARANTEED to be ids present in `nodes[]` (the service assembles
 * the response so this holds), and `to` is never null — a link with no target is a GHOST and
 * arrives in `ghosts[]` instead.
 *
 * `source` is typed nullable only because the resource writes `$edge->source?->value`; the column
 * is not nullable, so a null is drawn as the neutral `manual` line rather than dropped.
 *
 * `kind` is the DISCRIMINATOR between the two things an edge can be, and the server sends every
 * field of the other kind PRESENT AND NULL rather than absent. The shape of a row therefore does
 * not change with its kind — only its contents do — so no consumer has to test for key existence.
 *
 *   link      DERIVED. The machine's reading of the text, rebuilt on every save, dismissible
 *             because it is a guess.
 *   relation  ASSERTED. A typed statement a person approved, which no sweep may remove and
 *             which `dismiss` is simply not the available answer for (ending or retracting are).
 *
 * It is OPTIONAL here only because the composer's preview endpoint predates it on some payloads;
 * `edgeKind()` in the layout treats a missing `kind` as `link`, which is what it was before.
 */
export interface KnowledgeGraphEdge {
  /**
   * The link row's id — `null` on a PREVIEW edge from the composer's relations endpoint, which
   * describes a relation that does not exist yet and therefore has no row. Key on
   * `(from, to, source)` rather than on this.
   */
  id: string | null;
  /** `link` (derived) or `relation` (asserted). Absent on older preview payloads = `link`. */
  kind?: 'link' | 'relation';
  from: string;
  to: string;
  source: KnowledgeLinkSource | null;
  /** Cosine similarity for a `similarity` edge; null for wikilinks and manual edges. */
  score: number | null;
  evidence: KnowledgeLinkEvidence | null;
  /** Always present; false unless `include_dismissed=1` was asked for. */
  dismissed: boolean;
  /**
   * Whether a human may dismiss this edge — the SERVER's verdict, on both graph endpoints since
   * B12. Read the flag; never re-derive it from `source`. A preview edge is always `false`:
   * nothing is materialised, so nothing can be refused. ALWAYS false on a relation.
   */
  can_be_dismissed: boolean;

  // --- kind=relation; present-and-null on a link ------------------------------------------
  /** The verb. Null on a link. */
  relation_type?: KnowledgeRelationTypeId | null;
  /**
   * The server's own wording of the verb, both readings. Present, but the UI prefers its OWN
   * i18n: these strings are rendered with the SERVER's locale (`APP_LOCALE`), which does not
   * follow the next frontend's language switch. They are the fallback for a verb the client's
   * catalog has not learned yet — see `relations/relationLabels.ts`.
   */
  label?: string | null;
  inverse_label?: string | null;
  /** The verb reads the same both ways, so the edge is drawn WITHOUT an arrowhead. */
  symmetric?: boolean;
  description?: string | null;
  properties?: Record<string, string | number | boolean | null> | null;
  valid_from?: string | null;
  valid_to?: string | null;
  state?: KnowledgeRelationState | null;
  origin?: KnowledgeRelationOrigin | null;
}

/**
 * An unresolved `[[wikilink]]` drawn BY the nodes on screen, aggregated by the slug that was
 * meant. NOT an edge: there is no target to draw to — which is exactly the "red link" invitation.
 */
export interface KnowledgeGraphGhost {
  target_slug: string;
  from_ids: string[];
  count: number;
}

/** The node-cap receipt. `hidden_nodes` is what an uncapped answer would have added. */
export interface KnowledgeGraphTruncated {
  hidden_nodes: number;
  hidden_edges: number;
}

/** The graph payload. `center` is a BARE uuid string (or null in the overview), not an object. */
export interface KnowledgeGraphData {
  center: string | null;
  nodes: KnowledgeGraphNode[];
  edges: KnowledgeGraphEdge[];
  ghosts: KnowledgeGraphGhost[];
  truncated: KnowledgeGraphTruncated;
}

/** `{ data: KnowledgeGraphData }` — the graph read. */
export interface KnowledgeGraphResponse {
  data: KnowledgeGraphData;
}

/** The graph query, mirroring KnowledgeGraphQuery 1:1. Every key is optional. */
export interface KnowledgeGraphFilters {
  /** `?entry=<uuid>` — the ego centre. Omitted / null selects the overview. */
  entry?: string | null;
  /** `?depth=` — 1 or 2 hops. Clamped server-side to KnowledgeGraphQuery::MAX_DEPTH. */
  depth?: KnowledgeGraphDepth;
  /** `?sources=a,b` — the edge kinds to walk. Empty is NOT sent (the server would default). */
  sources?: KnowledgeLinkSource[];
  /**
   * `?min_score=` — a cosine floor applied to SCORED edges only (a wikilink, a mention and a
   * manual edge have no score and are never filtered by it).
   *
   * Omitted by this client, deliberately. The server's default is the LOWEST bar that can produce
   * an edge — `min(similarity.threshold, similarity.threshold_short)`, i.e. 0.60 since the linker
   * became length-aware (B10.1) — so leaving it out shows every edge that exists. Sending the
   * standard threshold instead would silently hide the short-text pairs stored under the lower one.
   */
  minScore?: number;
  /** `?include_dismissed=1` — dismissed edges arrive carrying `dismissed: true`. */
  includeDismissed?: boolean;
}

/** The only two depths the server accepts (KnowledgeGraphQuery::MAX_DEPTH = 2). */
export type KnowledgeGraphDepth = 1 | 2;

/** CONTRACT MIRROR of `KnowledgeGraphQuery::MAX_DEPTH`. */
export const KNOWLEDGE_GRAPH_MAX_DEPTH = 2;

/**
 * CONTRACT MIRROR of `config('knowledge.graph.max_nodes')` (= 60) — the server's own node cap.
 * The layout carries the same number so a client-side cap can never be looser than the server's.
 */
export const KNOWLEDGE_GRAPH_MAX_NODES = 60;

/** Every edge kind the server can label an edge with (KnowledgeLinkSource::ids()). */
export const KNOWLEDGE_LINK_SOURCES: KnowledgeLinkSource[] = [
  'wikilink',
  'similarity',
  'mention',
  'manual',
];

// --- The AI COMPOSER --------------------------------------------------------
//
// Backend contract (VERIFIED against app/modules/Knowledge — do NOT invent fields):
//   controller  Http/Controllers/KnowledgeDraftSessionController.php
//   resource    Http/Resources/KnowledgeDraftSessionResource.php
//   service     Services/KnowledgeDraftSessionService.php (availability) + KnowledgeDraftService
//   relations   Services/KnowledgeDraftRelationService.php
//   diff        Http/Controllers/KnowledgeEntryRevisionController.php::draftDiff
//   event       Events/KnowledgeDraftSessionUpdated.php + routes/channels.php
// ---------------------------------------------------------------------------

/** The composer's state machine. `idle` is a session that has not been queued yet. */
export type KnowledgeDraftSessionStatus = 'idle' | 'generating' | 'ready' | 'failed';

/**
 * WHY a session failed — a stable CODE, never prose. The client owns the wording (it has the room
 * and the locale); a server-composed message would be untranslatable copy baked into an API.
 */
export type KnowledgeDraftFailureReason =
  | 'unparseable'
  | 'empty'
  | 'seed_missed'
  | 'provider'
  | 'disabled'
  | null;

/**
 * Why the composer cannot run. `disabled` is the kill switch; the budget code is the shared
 * over-cap refusal. `null` when it can.
 */
export type KnowledgeComposeBlockReason = 'disabled' | 'ai_budget_exceeded' | null;

/**
 * The answer to "can the composer run at all", asked BEFORE the form renders (DC9) so an over-cap
 * workspace sees an explanation instead of a button that 429s. The gate decides — this endpoint
 * exists precisely so its answer and a POST's answer cannot drift.
 */
export interface KnowledgeComposeAvailability {
  can_compose: boolean;
  reason: KnowledgeComposeBlockReason;
  budget: {
    cost_used: number | null;
    cost_cap: number | null;
    cost_remaining: number | null;
    warn_reached: boolean;
    blocked: boolean;
    /** The usage window; `resets_at` is what the "renews on …" line renders. */
    period: { starts_at?: string | null; ends_at?: string | null; resets_at?: string | null } | null;
  };
  /** The server's own input bounds — the counter and the start gate read THESE, not a constant. */
  limits: {
    source_max_chars: number;
    prompt_max_chars: number;
    max_entries_per_session: number;
  };
}

/**
 * A draft's amendment target — present ONLY on a SHADOW draft (`targets_entry` is `whenLoaded`-style
 * conditional on `isShadow()`). Its presence is what makes a draft an amendment rather than a new
 * entry.
 */
export interface KnowledgeDraftTarget {
  id: string;
  title: string;
  slug: string;
  current_revision_id: string | null;
}

/**
 * A DRAFT is an ordinary entry resource — that is the whole design. It carries `content`,
 * `metadata`, and the `current_revision_id` the diff view needs, with no second shape to keep in
 * step. The draft-only fields below are null/absent on every published entry.
 */
export interface KnowledgeDraftEntry extends KnowledgeEntry {
  draft_session_id: string | null;
  /** Present only on a shadow draft. */
  targets_entry?: KnowledgeDraftTarget | null;
  target_revision_id?: string | null;
  /** The target moved since the composer read it — surfaced BEFORE acceptance, not after. */
  target_revision_stale?: boolean;
  /**
   * HOW this amendment changes its target. Shadow drafts only.
   *
   *   rewrite  `content` replaces the whole document.
   *   append   `content` is the ADDITION ALONE — not the new body.
   */
  amend_mode?: 'rewrite' | 'append' | null;
  /** `append` only: the section the addition joins. `null` means the end of the document. */
  amend_section?: string | null;
  /**
   * WHAT THE ENTRY WOULD SAY once accepted — RENDER THIS, never `content`.
   *
   * On an append, `content` holds only the addition, because storing it that way is what keeps the
   * operation commutative with somebody else's concurrent edit. Rendering that raw column shows a
   * diff claiming the whole document is replaced by one sentence, which is worse than showing no
   * diff at all: the reviewer is not left uninformed, they are actively misled about what they are
   * approving. For a rewrite and for an ordinary new draft this is `content` unchanged.
   *
   * Composed server-side from the target's LIVE text at read time, matching what publication does,
   * so the card cannot go stale against a parallel edit either.
   */
  amended_body?: string;
}

/** A duplicate warning, keyed by draft id on the session. `score` is a cosine similarity in [0,1]. */
export interface KnowledgeDraftDuplicate {
  slug: string;
  title: string;
  score: number;
}

/** One drafting session — the payload every composer response returns. */
// ---------------------------------------------------------------------------
// GRAPH OPS — what one composer run wants to do to the graph, and what the server
// already refused.
//
//   support  Support/KnowledgeGraphOps.php   (the laundering + every code below)
//   applier  Services/KnowledgeGraphOpsApplier.php  (the accept-time skip codes)
//
// `rejected[]` is NOT an error log, it is a SECTION OF THE REVIEW. Each item names an operation
// the server refused and why, so a gap in the vocabulary or a run of invented handles is visible
// evidence instead of a silent shortfall nobody can account for. Without it, a reviewer cannot
// tell "the agent found nothing" from "the agent found things and they were thrown away" — and
// that is the difference between trusting this surface and not.
// ---------------------------------------------------------------------------

/**
 * Why the server refused one proposed operation.
 *
 *   unknown_handle        it named an entity/relation that is not in this run.
 *   unknown_relation_type the verb is not in the vocabulary at all.
 *   type_not_allowed      the verb exists but THIS BASE does not allow it.
 *   self_loop             both ends were the same thing.
 *   forbidden_op          an operation the composer is never permitted (it may never DELETE).
 *   unknown_op            an operation name nothing recognises.
 *   properties_refused    a property key the verb does not declare. Refused, never dropped: a
 *                         fact that disappears on save is worse than one that was rejected.
 *   duplicate_relation    it already exists — `existing_relation_id` says which.
 *   pair_refused          the type matrix says this verb does not join these two kinds. Only
 *                         ever raised when BOTH ends are typed (see `pair_unchecked`).
 *   op_cap_reached        the run proposed more than the per-run cap; `max` says the cap.
 *   template_directive    the text tried to smuggle a template directive through.
 *   malformed             it did not parse.
 */
export type KnowledgeGraphOpRejectCode =
  | 'unknown_handle'
  | 'unknown_relation_type'
  | 'type_not_allowed'
  | 'self_loop'
  | 'forbidden_op'
  | 'unknown_op'
  | 'properties_refused'
  | 'duplicate_relation'
  | 'pair_refused'
  | 'op_cap_reached'
  | 'template_directive'
  | 'malformed';

/**
 * The ADVISORY half — things that happened, with a caveat.
 *
 *   pair_unchecked             one or both ends are untyped, so the matrix had no opinion. This
 *                              is the normal state of every entry written before entry types.
 *   rewrite_degraded_to_append the rewrite became an append. `reason` is `truncated` (the target
 *                              was too long to show the composer in full) or `no_revision`.
 *   wikilinks_lost             a rewrite drops `[[links]]` the current text carries; `links` is
 *                              the list. Allowed through WITH a warning rather than refused.
 *   ambiguity_unresolved       a name matched several entries and nothing settled it;
 *                              `candidates` is who it might be.
 *   moved_to_review            a content change aimed at an EXISTING entry became a shadow draft,
 *                              so a human sees it before it lands. Carries `entity` + `title`.
 *
 * That last one is a receipt for a hole that used to exist: `wiki_updates` against an existing
 * entry once wrote straight through on accept, with no card and no diff. "Change an existing
 * entry's text" now has ONE mechanism, the reviewed one — and the operation is REPORTED as moved
 * rather than dropped in silence, because a reviewer who saw it proposed last round would
 * otherwise be left wondering where it went.
 */
export type KnowledgeGraphOpWarnCode =
  | 'pair_unchecked'
  | 'rewrite_degraded_to_append'
  | 'wikilinks_lost'
  | 'ambiguity_unresolved'
  | 'moved_to_review'
  | 'replaces_unbound';

/** One refusal or caveat: a code plus whatever context that code carries. */
export interface KnowledgeGraphOpReport {
  code: KnowledgeGraphOpRejectCode | KnowledgeGraphOpWarnCode;
  /** Which half of the run it came from. */
  scope?: 'entities' | 'wiki_updates' | 'graph_updates';
  /** HANDLES (`E1`, `R2`), not ids — at rejection time nothing has been created. */
  entity?: string | null;
  relation?: string | null;
  from?: string | null;
  to?: string | null;
  title?: string | null;
  type?: string | null;
  op?: string | null;
  property?: string | null;
  /** `duplicate_relation` only — the relation that already says this. */
  existing_relation_id?: string | null;
  /** `op_cap_reached` only. */
  max?: number;
  /** `rewrite_degraded_to_append`: `truncated` | `no_revision`. */
  reason?: string | null;
  /** `wikilinks_lost` only — the slugs the rewrite would drop. */
  links?: string[];
  /** `ambiguity_unresolved` only. */
  mention?: string | null;
  candidates?: KnowledgeEntityCandidate[];
  [key: string]: unknown;
}

/**
 * One proposed relation operation.
 *
 * `from` / `to` are HANDLES (`E1`), not entry ids — the composer names things before they exist,
 * and half of them may be drafts nobody has accepted. `relation` is the handle of an EXISTING
 * relation and is what `update` / `end` name instead of a pair.
 *
 * The composer may never DELETE. It proposes `create`, `update` and `end`; retracting a statement
 * as never-having-been-true is a judgement only a person makes, and it is not in this contract at
 * all rather than merely being filtered out of it.
 */
export interface KnowledgeGraphRelationOp {
  op: 'create' | 'update' | 'end';
  from?: string | null;
  to?: string | null;
  relation?: string | null;
  type?: KnowledgeRelationTypeId | null;
  description?: string | null;
  properties?: Record<string, string | number | boolean | null>;
  valid_from?: string | null;
  valid_to?: string | null;
}

/** One entity the run wants to CREATE, named by handle. */
export interface KnowledgeGraphEntityOp {
  ref?: string | null;
  title?: string | null;
  slug?: string | null;
  entry_type?: KnowledgeEntryType | null;
  [key: string]: unknown;
}

/**
 * A NAME THE RUN COULD NOT PLACE — reported so that silence never has to be interpreted.
 *
 * The model gets first refusal on every ambiguity because it has the sentence; whatever it
 * declines lands here. Nothing was proposed for this name: no entry, no relation. A reviewer who
 * is not told that reads the absence as a judgement ("the model decided Kasia was not important"),
 * when in fact the model said the opposite — that it could not tell who she was.
 */
export interface KnowledgeUnresolvedMention {
  mention: string;
  /** Why it could not be placed, in the model's own words. Null when it gave none. */
  note: string | null;
}

export interface KnowledgeGraphOps {
  entities: KnowledgeGraphEntityOp[];
  wiki_updates: Array<Record<string, unknown>>;
  graph_updates: KnowledgeGraphRelationOp[];
  unresolved: KnowledgeUnresolvedMention[];
  rejected: KnowledgeGraphOpReport[];
  warnings: KnowledgeGraphOpReport[];
}

/** One entry a name might refer to. */
export interface KnowledgeEntityCandidate {
  handle?: string;
  slug: string;
  title: string;
  entry_type: KnowledgeEntryType | null;
  score: number | null;
}

/**
 * A name that matched SEVERAL entries — a QUESTION, not a result.
 *
 * `context` is the surrounding text, and it is what makes the choice possible: two entries called
 * "Anna" are indistinguishable by title alone, and a picker offering two identical labels asks the
 * reviewer to guess. The composer may answer this from context; whatever it does not answer is a
 * one-click decision here.
 */
export interface KnowledgeAmbiguousMention {
  text: string;
  kind: KnowledgeEntryType | null;
  context: string | null;
  candidates: KnowledgeEntityCandidate[];
}

export interface KnowledgeResolutionSet {
  entities: Array<Record<string, unknown>>;
  ambiguous: KnowledgeAmbiguousMention[];
  unresolved: Array<Record<string, unknown>>;
  /**
   * TITLES of entries that matched but did not fit the context ceiling.
   *
   * The composer never saw them. That makes the absence of any proposal about them a fact about
   * the BUDGET rather than an opinion about the entries — and a reviewer who does not know the
   * difference will read "the model had nothing to say about our refund policy" when the truth is
   * "the model was never shown it".
   */
  omitted: string[];
  /**
   * Non-empty means the resolution pass ran with less than its full apparatus (budget, no vector
   * support, a base past the scan limit). It changes what `unresolved` MEANS — "I did not look
   * properly" rather than "it is not in this base" — which the reviewer has to be able to see.
   */
  degraded: string[];
}

/**
 * The note codes this build words, and what each one carries besides its code.
 *
 *   amend_append_only        the rewrite became an append (the target was too long to show the
 *                            composer in full). Carries `slug`.
 *   amend_too_long           the amended entry would pass the length limit. Carries `slug`.
 *   wikilinks_lost           the rewrite drops `[[links]]`; `links` is the list. Carries `slug`.
 *   resolution_degraded      name-matching ran with less than its full apparatus; `reason` says
 *                            which shortfall, and that is the whole value of the note.
 *   entry_incomplete         a PROPOSAL WAS DROPPED because it arrived without a `field`
 *                            (`title` / `content`); `name` is the model's slug for it, or the one
 *                            half that survived. See the `slug` note below — this one carries none
 *                            ON PURPOSE.
 *   facts_truncated
 *   facts_unavailable        rendered by the fact checklist, in context, not in the notes list.
 *   protagonist_without_entry  rendered by its own banner, for the same reason.
 *
 * OPEN on purpose (`string & {}`): a backend that adds a code must still render — `noteLine` words
 * an unknown one rather than emitting a blank row, because the count above the list has already
 * promised it.
 */
export type KnowledgeRunNoteCode =
  | 'amend_append_only'
  | 'amend_too_long'
  | 'wikilinks_lost'
  | 'resolution_degraded'
  | 'entry_incomplete'
  | 'facts_truncated'
  | 'facts_unavailable'
  | 'protagonist_without_entry'
  | (string & {});

/**
 * `{code, ...context}` — what the server did to the model's answer. Codes, never prose.
 *
 * WHERE A NOTE RENDERS IS DECIDED BY ITS `slug`, AND ONLY BY THAT. A note naming a slug is filed
 * under that entry's card (`notesBySlug`); everything else goes to the run list (`runNotes`). So a
 * note about something that has NO card — a proposal the server dropped — must not carry a slug, or
 * it renders nowhere at all. `entry_incomplete` is exactly that case.
 */
export interface KnowledgeRunNote {
  code: KnowledgeRunNoteCode;
  [key: string]: unknown;
}

/**
 * ONE THING THE MATERIAL SAYS HAPPENED — the reviewer's checklist, not a verdict.
 *
 * This list exists because of a specific failure: dramatic facts were vanishing out of the source
 * text (a drinking incident, a wave of abuse) and nothing said so. Automatic checking cannot catch
 * it — a model that writes up the departure date without the incident reports its coverage
 * perfectly honestly — so the PERSON is the check, and a person needs something to read.
 */
export interface KnowledgeFact {
  /** `F1`, `F2` — how the run notes name a fact they could not find in any draft. */
  id: string;
  text: string;
  /**
   * THE SOURCE'S OWN WORDING — "15 sierpnia", never `2026-08-15`.
   *
   * Deliberately not normalised, and it must not be normalised here either: the reviewer compares
   * this against their own text, and an already-interpreted date is one more thing to trust rather
   * than the thing they wrote.
   */
  date: string | null;
  subjects: string[];
  /**
   * A draft CLAIMED this fact. Note what that is and is not: the claim is the run's own report, so
   * `true` means "somebody said they wrote it up", never "it was written up well". An entry that
   * records the date and omits what happened claims coverage perfectly honestly — which is why the
   * checklist stays a reading aid and the person stays the check.
   */
  covered?: boolean;
  /**
   * WHICH drafts claimed it — a LIST, and that is the substance rather than a detail.
   *
   * An episode between two people belongs in BOTH their chronicles: "Łukasz przeprosił
   * influencerkę" is one fact that two entries rightly cover. Showing only the first would make a
   * correct answer look partial and send a reviewer hunting for a gap that is not there.
   *
   * Absent on sessions generated before drafts recorded their claims. That is not a shortfall —
   * nobody asked those runs — so it renders as nothing rather than as a missing answer.
   */
  covered_by?: KnowledgeFactClaim[];
}

/** One draft that claims a fact. `slug` is the DRAFT's address, which may be a reserved shadow. */
export interface KnowledgeFactClaim {
  id: string;
  slug: string;
  title: string;
}

export interface KnowledgeDraftSession {
  id: string;
  knowledge_base_id: string;
  status: KnowledgeDraftSessionStatus;
  failure_reason: KnowledgeDraftFailureReason;
  source_text: string;
  /** Every instruction asked so far, oldest first — the refine bar's history. */
  prompt_history: string[];
  seed_slug: string | null;
  seed_title: string | null;
  /**
   * When the retrieval context was last WIDENED, or null.
   *
   * Non-null means `expand-context` will refuse (422 `knowledge_context_already_expanded`) until a
   * refinement consumes it — so this is the source of truth for disabling the paid action, for
   * EVERYONE looking at the session rather than only the tab that pressed it. A refinement clears
   * it and returns the session already cleared, so re-enabling costs no extra fetch.
   */
  context_expanded_at: string | null;
  /**
   * WHAT THE RUN PROPOSED TO DO TO THE GRAPH, already laundered by the server.
   *
   * Nothing here has been applied — a human accepts it, and the write path re-checks every rule
   * from scratch because the base can move while the proposal sits on screen.
   */
  graph_ops: KnowledgeGraphOps;
  /**
   * WHO AND WHAT the source material is about, matched against entries that already exist. Frozen
   * at session start and stable for the session's whole life.
   */
  resolution: KnowledgeResolutionSet;
  /** What the SERVER did to the model's answer — `{code, ...context}`, codes and not prose. */
  notes: KnowledgeRunNote[];
  /**
   * What the material says happened, beside what was proposed. Empty when the reading found no
   * facts, and also when it could not run — `facts_unavailable` in the notes tells those apart.
   */
  facts: KnowledgeFact[];
  drafts?: KnowledgeDraftEntry[];
  drafts_count?: number;
  /** Duplicate warnings by DRAFT ID. Empty until the relations endpoint has run once. */
  duplicates: Record<string, KnowledgeDraftDuplicate>;
  creator?: Creator | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface KnowledgeDraftSessionResponse {
  data: KnowledgeDraftSession;
}

/** The create body. `seed_*` carry a red link's slug into the session. */
export interface KnowledgeDraftSessionPayload {
  source_text: string;
  seed_slug?: string;
  seed_title?: string;
}

/**
 * One draft that could NOT be published: its target moved, so its optimistic-lock token is stale.
 * Reported ALONGSIDE what did go through — a reviewer who accepted five proposals is not told none
 * of them happened because one target moved.
 */
export interface KnowledgeDraftConflict {
  entry_id: string;
  targets_entry_id: string | null;
  current_revision_id: string | null;
}

/**
 * ONE OPERATION THAT DID NOT HAPPEN when the reviewer pressed accept.
 *
 * `skipped` is NOT an error list and must never be rendered as one. A 200 carrying four accepted
 * entries and two skips is the ordinary case — the reviewer approved a subset, and two operations
 * depended on the rest. What it exists for is that a person who approved six things and got four
 * has to be told WHICH two and WHY, which is news about the world rather than a failure.
 *
 *   already_applied          somebody (or an earlier press) already did it.
 *   relation_gone            the relation it would have ended is no longer there.
 *   dependency_not_accepted  an end of it is a draft that was NOT in this batch. The commonest
 *                            one, and the one the review panel warns about BEFORE the press.
 *   refused                  a rule refused it on the write path, with `reason` saying which.
 *   not_selected             the reviewer UNTICKED it. Reported rather than omitted, and that is
 *                            the whole point: a refusal that leaves no trace is indistinguishable
 *                            from a refusal that did not take effect, so the reviewer would have
 *                            no way to confirm their own decision landed.
 */
export type KnowledgeDraftSkipCode =
  | 'already_applied'
  | 'relation_gone'
  | 'dependency_not_accepted'
  | 'refused'
  | 'not_selected';

export interface KnowledgeDraftSkipped {
  code: KnowledgeDraftSkipCode;
  /** Handles, not ids: at skip time the entity may never have been created. */
  entity?: string | null;
  relation?: string | null;
  from?: string | null;
  to?: string | null;
  op?: string | null;
  reason?: string | null;
  [key: string]: unknown;
}

/** `POST …/accept` → 200 either way; partial success is the normal case. */
export interface KnowledgeDraftAcceptResult {
  /**
   * The entries that now exist as asked — INCLUDING the targets of accepted amendments.
   *
   * There is no separate `updated` list, and its absence is the contract rather than a gap.
   * Publishing an amendment returns the LIVE TARGET and destroys the shadow, so the row that
   * changed is already here for every amendment; a second list would have been a duplicate of
   * this one. (It was also always empty — the applier set a key no branch ever wrote.)
   *
   * A caller that needs "created" versus "changed" tells them apart from the DRAFT it sent, not
   * from this array: a shadow draft carries `targets_entry`, and an amendment's accepted row is
   * the target's id rather than the draft's, so matching back is impossible from here.
   */
  accepted: KnowledgeEntry[];
  /** The typed relations that were actually asserted. */
  relations: KnowledgeRelation[];
  conflicts: KnowledgeDraftConflict[];
  skipped: KnowledgeDraftSkipped[];
}

/** Which baseline a draft diff compares against. `target` is the amended entry (shadow only). */
export type KnowledgeDraftDiffBaseline = 'original' | 'previous' | 'target';

/** One side of a draft diff. */
export interface KnowledgeDraftDiffSide {
  revision_id: string | null;
  title: string;
  content: string;
  created_at: string | null;
}

/**
 * The two TEXTS a draft diff compares — the CLIENT renders the diff (`ui/data/TextDiffView`).
 * `has_baseline` is false when there is nothing before this version to compare with.
 */
export interface KnowledgeDraftDiff {
  baseline: KnowledgeDraftDiffBaseline;
  /** Shadow only: the target has moved on, so accepting would hit the optimistic lock. */
  target_revision_stale: boolean;
  has_baseline: boolean;
  from: KnowledgeDraftDiffSide | null;
  to: KnowledgeDraftDiffSide;
}

/** Why the semantic legs did not run for a relations preview; null when they did. */
export type KnowledgeDraftVectorSkip = 'budget' | 'disabled' | 'unsupported' | 'error' | null;

/**
 * The composer's relations preview — DELIBERATELY the base graph's shape plus two node fields, so
 * the canvas, the layout and the neighbour list are reused rather than forked (DC5).
 *
 * `center` is always null (a proposal is an overview, not a walk from one entry) and `ghosts` is
 * always empty (an unresolved link in a draft is not a red link in the base until it is accepted).
 */
export interface KnowledgeDraftRelations extends KnowledgeGraphData {
  nodes: KnowledgeDraftRelationNode[];
  duplicates: Record<string, KnowledgeDraftDuplicate>;
  vector_skipped: KnowledgeDraftVectorSkip;
  /**
   * The relation operations this run proposes, in a shape built FOR THE REVIEW.
   *
   * Preferred over `session.graph_ops.graph_updates` for rendering, and the difference is the only
   * reason this list exists: `graph_ops` names both ends by HANDLE (`E1`), which a reviewer cannot
   * read, while these carry the titles resolved and the draft dependency spelled out. The two
   * describe the same operations; one is the machine's record and one is the human's.
   */
  proposed_relations: KnowledgeProposedRelation[];
  /** The entities this run wants to CREATE — handle, title, kind. */
  proposed_entities: KnowledgeProposedEntity[];
}

/** One entity the run proposes to create. `id` is always null: nothing is materialised. */
export interface KnowledgeProposedEntity {
  id: null;
  /** The composer's own handle (`E1`) — how relations name it before it exists. */
  handle: string;
  title: string;
  slug: string | null;
  entry_type: KnowledgeEntryType | null;
  is_draft: true;
}

/** One proposed relation operation, with both ends already named. */
export interface KnowledgeProposedRelation {
  kind: 'relation';
  /**
   * The SERVER's stable identity for this operation (`graph:<ordinal>`), and what `accept` takes
   * back as `graph_op_keys[]`.
   *
   * Server-minted rather than client-computed on purpose: a refinement RENUMBERS the operations,
   * so a preview held on screen silently stops meaning what it did. A key the client invented
   * would still look valid and would apply the wrong operation; this one stops matching, and
   * `accept` answers 422 instead.
   */
  key: string;
  /**
   * The OTHER HALF of a "end the old one, assert the new one" change, or null.
   *
   * Present on BOTH halves and pointing at each other, which is what makes grouping a single pass
   * over one field rather than a search for the `end` that each `replaces` refers to.
   *
   * These two operations are ONE CHANGE and the server refuses to apply half of it: an `end`
   * without its replacement says Anna works nowhere, and a replacement without the ending says she
   * works in two places at once. Both are confidently false statements about the world, which is
   * why they cannot be ticked apart. Refusing the pair outright is always allowed.
   */
  pair_with: string | null;
  /** `create` only — the handle (`R7`) of the relation this one supersedes. */
  replaces: string | null;
  /** Always null — there is no row yet. Key on `key`. */
  id: null;
  op: 'create' | 'update' | 'end' | null;
  /** HANDLES, not entry ids. */
  from: string | null;
  to: string | null;
  /** The handle of an EXISTING relation, for `update` / `end`. */
  relation: string | null;
  from_title: string | null;
  to_title: string | null;
  relation_type: KnowledgeRelationTypeId | null;
  description: string | null;
  properties: Record<string, string | number | boolean | null>;
  valid_from: string | null;
  valid_to: string | null;
  /**
   * Which ends are DRAFTS that have to be accepted first — a LIST, because both ends can be new
   * and a reviewer needs to know which drafts, not merely that there are some.
   *
   * This is what makes the dependency visible BEFORE the accept press rather than discovered as a
   * `dependency_not_accepted` skip afterwards. Same fact, two very different experiences.
   */
  depends_on_draft: string[];
}

export interface KnowledgeDraftRelationNode extends KnowledgeGraphNode {
  /** This circle is a PROPOSAL, not something that exists yet. */
  is_draft: boolean;
  /**
   * This existing entry has pending shadow drafts against it. Always present (empty when none).
   *
   * A LIST, because a shape that could not express two proposals against one entry would have to
   * change the day one appears. It is how an amendment reaches the picture at all: a shadow draft
   * is never a node of its own, so there is nothing to draw an edge to.
   */
  amended_by: Array<{ draft_id: string }>;
}

export interface KnowledgeDraftRelationsResponse {
  data: KnowledgeDraftRelations;
}

// --- Contract constants ----------------------------------------------------

/**
 * The per-entry content cap, in characters.
 *
 * CONTRACT MIRROR of `config('knowledge.entry_max_chars')` (= 40000), enforced by
 * StoreKnowledgeEntryRequest as `max:` on `content`. The frontend has no access to backend config,
 * so this is a hand-copied constant: if the config changes, change it here too. It is a SOFT gate
 * in the UI (counter + doctrinal warning); the server is the authority and answers 422.
 */
export const KNOWLEDGE_ENTRY_MAX_CHARS = 40000;

/**
 * CONTRACT MIRROR of `config('knowledge.search.max_results')` (= 25) — also the server's `max:` on
 * `?limit`. Sending more is a 422, so the UI never offers it.
 */
export const KNOWLEDGE_SEARCH_MAX_RESULTS = 25;

/**
 * How many alternative names one entry may carry.
 *
 * CONTRACT MIRROR of `EntryAliases::MAX` (= 10), which is also the `max:` on the `aliases` array
 * in the write requests. Worth knowing about the server's behaviour past the cap: normalization
 * DROPS the extras silently rather than answering 422, so a UI that let the user type an
 * eleventh alias would show it saved and then lose it. The input stops at ten instead.
 */
export const KNOWLEDGE_ALIAS_MAX = 10;

/** CONTRACT MIRROR of `EntryAliases::MAX_CHARS` (= 120) — the `max:` on each `aliases.*` string. */
export const KNOWLEDGE_ALIAS_MAX_CHARS = 120;

/** The 409 code an optimistic-lock failure carries (StaleKnowledgeWriteException::CODE). */
export const KNOWLEDGE_STALE_WRITE = 'knowledge_stale_write';

/** The 409 code a restore into a taken slug carries (KnowledgeSlugConflictException::CODE). */
export const KNOWLEDGE_SLUG_CONFLICT = 'knowledge_slug_conflict';

/**
 * The 422 code a SECOND context expansion carries (KnowledgeContextAlreadyExpanded::CODE).
 *
 * It is a STATE conflict about this session, not an authorization or budget problem — which is why
 * it has its own code and why the client can afford to treat it as "already done" rather than as a
 * failure. Nothing was spent, and the state the caller wanted is the state that exists.
 */
export const KNOWLEDGE_CONTEXT_ALREADY_EXPANDED = 'knowledge_context_already_expanded';

/** The parsed body of a 409 `knowledge_stale_write`. */
export interface KnowledgeStaleWriteError {
  code: typeof KNOWLEDGE_STALE_WRITE;
  message: string;
  /** The revision the server actually holds — re-arm the editor's lock with this on overwrite. */
  current_revision_id: string | null;
}

/** The parsed body of a 409 `knowledge_slug_conflict`. */
export interface KnowledgeSlugConflictError {
  code: typeof KNOWLEDGE_SLUG_CONFLICT;
  message: string;
  slug: string;
}
