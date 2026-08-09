# ADR-0047 — Knowledge typed relations (Wiki-Graf)

**Date:** 2026-08-05
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`Models\KnowledgeRelation`, `Models\KnowledgeRelationEvent`,
`Enums\KnowledgeRelationType`, `Enums\KnowledgeRelationState`, `Enums\KnowledgeRelationOrigin`,
`Enums\KnowledgeEntryType`, `Support\RelationVocabulary`, `Support\RelationVerdict`,
`Support\KnowledgeGraphOps`, `Services\KnowledgeRelationService`, `Services\KnowledgeGraphService`,
`Services\KnowledgeGraphOpsApplier`, `Services\KnowledgeDraftRelationService`,
`Http\Controllers\KnowledgeRelationController`, `Http\Controllers\KnowledgeGraphController`,
`Http\Resources\KnowledgeRelationResource`, `Http\Resources\KnowledgeGraphResource`,
`Http\Requests\{Store,Update,End,Destroy,Index}KnowledgeRelationRequest`,
`Http\Requests\AcceptKnowledgeDraftsRequest`, `Services\KnowledgeSubjectPurgeService` (extended)),
migrations `2026_08_07_000013_create_knowledge_relations_table.php`,
`2026_08_07_000014_create_knowledge_relation_events_table.php` (+ tenant mirrors
`0001_01_01_000070`/`0001_01_01_000071`), plus the app-wide `App\Http\Middleware\SetUserLocale.php`
and `database/migrations/2026_08_08_000000_make_users_locale_nullable.php` (found and fixed during
this batch, not scoped to relations alone — see "Defects found and fixed along the way")
**Relates to:** ADR-0043 (the module and the entry this batch adds relations to), ADR-0044 (the link
graph a relation is deliberately NOT a row of), ADR-0045 (the data-erasure command this batch extends
with a new match category), ADR-0046 (the AI composer whose `graph_ops` this batch's write path
applies), `docs/ai/reference-links.md` §7 (the external research this batch implements, with one named
departure)

---

## Context

The base's graph already drew two kinds of line before this batch: `KnowledgeLink` rows the module
DERIVES from prose (a `[[wikilink]]`, a title mention, a cosine-similarity match) and re-derives on
every save or re-index. Nothing in the graph asserted a STATEMENT — "Anna WORKS ON the refund
project", "the outage OCCURRED DURING the migration" — that a person stood behind and that a sweep
could not quietly rewrite out from under them. The AI composer (ADR-0046) already proposed such
statements as part of `graph_ops`, but had nowhere durable to put them once accepted; this batch is
where they land.

The product-vision backlog (`docs/product/plan-dzialania.md` → "Moduł Wiedzy") named this "LLM-owa
ekstrakcja relacji" as a deliberately deferred Stage 2 item. The owner's decision this round was to
build the STORAGE, WRITE PATH and REVIEW surface for typed relations now — sourced both from the
composer's own proposals and from a human drawing an edge by hand — while leaving the deeper Stage 2
concerns (a dedicated LLM extraction pass with its own cost line, entity-duplicate merging,
graph-wide inference, community detection) explicitly out of scope. See "Known limitations" below and
the updated `docs/product/plan-dzialania.md` entry.

## Decisions

**D1 — a relation is a separate table, `knowledge_relations`, never a column or a `source` value on
`knowledge_links`.** `knowledge_links` is a CACHE: every row is deleted and rebuilt on save, on
re-index, and on a `links.version` bump — that is what makes the derived graph cheap and self-healing.
A relation is the opposite kind of thing: it was proposed by a paid AI call or drawn by a person, then
approved. A sweep that quietly erased those with no error and no log line would destroy curated work
with nobody noticing until they went looking for a fact that used to be there. A separate table does
not merely make that unlikely, it makes it structurally impossible — the link sweep's `DELETE` cannot
name a table it never queries. There is deliberately **no unique constraint** on
`(from_entry_id, to_entry_id, relation_type)`: two relations of the same type between the same pair are
legitimate ("met on 2026-08-15" and "met on 2026-09-12" are two facts, not a duplicate), so duplicate
detection is a service-level check over the ACTIVE subset (same pair, same verb, same `valid_from` —
including a shared `null`), not a database constraint that cannot express "active only."

**D2 — a relation's audit trail is its own append-only table, `knowledge_relation_events`, never the
shared `changelogs` module.** Four independent reasons, the first decisive on its own:

1. `changelogs` exists **only in the central schema** — there is no tenant mirror, so a relation
   belonging to an own-database workspace would resolve to a connection where the table is simply not
   there, and half the product would fail to log at all, silently, discovered only at runtime.
2. `changelogs.causer_id` is a **NOT NULL foreign key to `users`**. A relation the AI composer asserted
   has no user, and the entire point of `origin` (D-below) is being able to ask later "what does this
   base believe only because a model said so" — attributing it to whoever clicked accept destroys that
   distinction.
3. The `HasChangelog` trait is driven by **model events** (`created`/`updated`/`deleted`); the verbs
   this module needs are domain ones — `end`, `supersede`, `retract`, `promote` — which all look
   identical to an `updated` hook, exactly the distinction an audit trail exists to preserve.
4. `changelogs` carries `updated_at`; this module's audit posture (matching
   `knowledge_entry_revisions`) is append-only WITHOUT one — a record that can be modified is not
   evidence.

`knowledge_relation_events` therefore has no foreign key on `relation_id` at all, on purpose: a cascade
would delete a relation's history along with the relation, erasing precisely the `delete` event that
explains where it went. The log outlives its subject.

**D3 — a closed vocabulary of 15 relation types in code, with a per-base allow-list as a subset; 8
entity types derived BACKWARD from the verbs, not from a general ontology.**
`KnowledgeRelationType` (`member_of`, `works_on`, `knows`, `created`, `owns`, `located_in`,
`participated_in`, `occurred_during`, `part_of`, `is_a`, `uses`, `depends_on`, `precedes`, `caused`,
`opposes`) is fixed in code, not a database row — free-text predicates ("works on", "working on",
"pracuje nad", "assigned to") would read naturally and be useless, four unrelated edges that can never
be queried together. A base may narrow this to a subset (`RelationVocabulary::for()`); `null` (never
configured) means the whole vocabulary and `[]` means none, deliberately different so a base that never
thought about relation types is not silently stricter than one that chose to allow nothing.
`KnowledgeEntryType` (`person`, `organization`, `event`, `place`, `product`, `work`, `concept`, `other`)
exists for exactly one job — letting the type matrix say "a person WORKS ON a project" is a sentence and
"a city WORKS ON a person" is not — and each class earns its place only because some relation needs to
tell it apart from another: `work` is kept apart from `product` because a person `created` a work while
a system `uses`/`depends_on` a product, and collapsing them would license "the team created the product
line" as the same claim as "Anna wrote the spec." `other` is a deliberate answer ("typed, but none of
these") and participates in nothing the matrix constrains; `null` (the default for every entry written
before this column existed) is a different, far more common statement — nobody has said — and the
matrix treats it as unknown rather than as a type in its own right.

**D4 — the pair matrix is ADVISORY when either end's type is unknown; this is a named, deliberate
departure from the module's own strict-mode posture.** `RelationVocabulary::check()` returns one of
three verdicts (`RelationVerdict`), not a boolean: `ALLOWED` (both ends typed, pair in the matrix),
`REFUSED` (both ends typed, pair NOT in the matrix — the only hard refusal), `UNKNOWN_TYPES` (at least
one end untyped, or typed `other`). Only `REFUSED` stops a write; `UNKNOWN_TYPES` is accepted by the
HTTP write path and carried to a composer review as a warning (`WARN_UNTYPED_PAIR`,
`pair_unchecked`). Elsewhere in this module the posture is fail-closed (the template directive guard,
the amendment allow-list, the phrase matcher in the erasure command) — this is the one place it is not,
and it is worth naming as a departure from `docs/ai/reference-links.md` §7's own accepted principle
("the enforcement of what is even a legal relation belongs entirely in code against a closed
vocabulary"). The reason is what a false refusal costs here versus there: everywhere else a refusal
costs one retry; here, EVERY entry written before `entry_type` existed carries `null`, so a matrix that
refused on a guess would reject correct relations across a whole base's existing history with total
confidence, and the "safe" direction (refuse) is the destructive one. `other` is treated as unknown for
the mirror reason — it is the writer's own admission that no class applies, so constraining by it would
invent a rule out of that admission.

**D5 — the machine-facing contract knows only `create`/`update`/`end`; `retract` (soft, "this was never
true") and the hard `DELETE` exist ONLY as a person's own affordance.** `KnowledgeGraphOps` (the
composer's laundering layer) drops `delete`/`retract`/`remove`/`destroy` from a model's proposed
`graph_updates` unconditionally, however well-formed — `REJECT_FORBIDDEN_OP` — because an AI able to
retract statements is an AI able to quietly empty a base, and no amount of review catches an ABSENCE (a
reviewer only sees the operations that are there). `end` (with a date) is the expressive equivalent for
every legitimate case: a fact that stopped being true is still a fact about the past. `retract` — "this
was NEVER true" — and the hard `DELETE /relations/{relation}` — irreversible removal of the row — are
both reachable only from the relation panel's own human-only endpoints
(`KnowledgeRelationService::retract()`/`delete()`), gated by `KnowledgeRelationPolicy::delete()` to the
relation's creator or the workspace owner. `retract` KEEPS the row (`state: retracted`) precisely so the
base remembers a wrong claim was made and rejected, which is what stops the same wrong relation being
proposed and re-accepted next month; the hard `DELETE` is the only irreversible action in the whole
relation surface.

**D6 — `superseded_by_id` lives on the OLD (ended) relation, pointing forward at its replacement.** A
reader following a fact that has stopped being true arrives at the one that replaced it, rather than at
a dead end. `KnowledgeRelationService::supersede()` ends the old relation AND stamps this pointer in one
transaction, logged as a single `supersede` event carrying both ids — never two independent `end` +
`create` writes that could disagree about which is which.

**D7 — a model addresses entities and relations by opaque HANDLES (`E<n>`/`R<n>`/`N<n>`), never by
database id.** `KnowledgeGraphOps` accepts only handles matching `^[ENR]\d{1,3}$`; a database uuid is
never given to a model and never accepted back from one. A handle that does not resolve simply fails to
resolve — it does not silently name the wrong row, because there is no row it could accidentally match.
This closes an entire class of identity hallucination more cheaply than validating a returned id would:
a made-up handle is caught by a lookup miss, a made-up but syntactically-valid-looking id would have had
to be checked against every id in the base to catch the same mistake.

**D8 — the server owns the ontology; the client owns only phrasing.** `relation_vocabulary`
(`KnowledgeRelationType::catalog()`) is sent with every `KnowledgeBaseResource` — id, both labels
(forward/inverse), `symmetric`, `property_keys`, `from_types`/`to_types` — so a relation editor needs no
second request and, critically, a client cannot disagree with the server about what a verb accepts. This
was written into the frontend spec as an open contract question (§27.12 GK8: "duplicating the ontology
in `relationLabels.ts` guarantees drift") and resolved by shipping the catalog rather than documenting a
convention to keep two copies in step. Three enforcement points on the frontend read this catalog rather
than hardcoding the 15 verbs: the type picker (grouped, from `catalog()`), the pair-type advisory badge
(from `from_types`/`to_types`), and the properties repeater (from `property_keys`) — see
`resources/js/next/docs/pages/KnowledgePage.vue`.

**D9 — relation operation keys are `graph:<ordinal>` positions into the frozen `graph_ops` column, not
a content hash; selection is three-valued.** `graph_ops` is rewritten WHOLESALE on every composer run
(never patched in place), so an ordinal position is stable for exactly as long as it needs to be — while
a reviewer is reading a preview, the run cannot renumber under them, because rewriting only happens on
the next refine or generation. A refinement that DOES renumber invalidates any preview a client is
still holding, and `POST …/accept` catches that with `422 unknown_op_key` rather than quietly applying
the wrong operation under an old key. `AcceptKnowledgeDraftsRequest.graph_op_keys` is deliberately
three-valued: the key **absent** means "apply every proposed operation" (the compatible default, what a
client that has not learned about selection keeps getting); `[]` means "apply none of them, while still
accepting the selected drafts"; a non-empty list means exactly those, and everything outside it is
reported in `skipped[]` as `not_selected` — never silently dropped, because a refusal that leaves no
trace reads exactly like a bug that ate the operation.

**D10 — `replaces`/`pair_with` are INSEPARABLE by refusal, bound independently of payload order, and a
dangling `replaces` after its partner is rejected degrades to an unbound field, never to a dropped
relation.** A `create` naming `replaces: R7` and the `end` on `R7` are two halves of one fact ("Anna now
works on Orion, replacing Genesis"); selecting only one half would either assert she works in two
places at once (the `create` without its `end`) or that she works nowhere (the `end` without its
replacement). `AcceptKnowledgeDraftsRequest::withValidator()` refuses a selection that splits a bound
pair — `422 inseparable_ops` — rather than auto-completing it: auto-including the missing half would
write something the reviewer never ticked, the same class of defect as a checkbox that silently does
more than it says, worse here because the thing added is a fact about a person. The binding itself is
resolved by a PRE-PASS over the whole batch before anything is judged (`KnowledgeGraphOps::graphUpdates()`
collects every surviving `end` first), because a model may write a `create`'s `replaces` before the
`end` it refers to appears in its own reply, and resolving the binding in payload order would accept or
drop the SAME pair depending on how the model happened to sequence its answer. When the `end` half is
later dropped by laundering (the type matrix, a cap, a duplicate, an unknown handle), the surviving
`create`'s `replaces` field is UNBOUND rather than the `create` itself being refused
(`unbindDeadReplacements()`, `WARN_REPLACES_UNBOUND`) — the new relation is a complete, true statement
on its own, and discarding it because its footnote lost its referent would throw away a fact to punish
an annotation.

**D11 — the review gate applies to everything; there is no auto-accept mode.** An explicit owner
decision, not a technical default: every relation a composer proposes — however confidently, however
unambiguous — sits in `graph_ops` until a human selects it in `POST …/accept`. The write path
(`KnowledgeGraphOpsApplier`) is the SAME code whether the caller ticked one box or all of them; there is
no second, faster path for "just apply what the model said." This is the same posture ADR-0046 already
established for entries (nothing publishes without an explicit accept) extended without exception to
the graph half of a proposal.

## Defects found and fixed along the way

**(a) The composer asked a model to return "the complete new content" of an amendment candidate while
the context it was actually shown was a 1500-character excerpt of that same entry — a silent,
undetectable loss of content on a long entry.** The two numbers lived in one config knob
(`retrieval_excerpt_chars`, default 1500) applied uniformly to every retrieved entry, whether it was
context ("the base already covers this") or an amendment candidate the model was being asked to rewrite
whole. This was invisible on short entries and on every entry ranked below the amendment cap (where the
excerpt is legitimately all that is shown), which is why it shipped unnoticed — it only bit a genuinely
long entry landing in one of the top `max_shadow_per_session` slots. Fixed by splitting the cap in two:
`amend_full_chars` (12000, the WHOLE text, reserved for the ranked candidates the composer may actually
propose to amend) versus `retrieval_excerpt_chars` (1500, context-only, never a rewrite target), plus the
`truncated` flag (`KnowledgeDraftRetrievalService::freeze()`) that is threaded through to force any
candidate the composer did NOT see in full into APPEND-only mode — a rewrite it cannot prove it read
whole is refused outright (see (b) below and D5 of ADR-0046).

**(b) The optimistic lock did not trip on a composer-authored rewrite, because the lock token was being
read from the request payload — which the composer's `accept` endpoint never emits at all.** A manual
edit sends `expected_revision_id` in its own PATCH body; `AcceptKnowledgeDraftsRequest` carries only
`entry_ids`/`graph_op_keys`/`status`, so a code path that read the token from the request always saw
`null`, and `assertNotStale()` returns early on a null token — meaning a concurrent human edit to the
target could be silently overwritten by a rewrite the composer generated against a version that no
longer existed. This was invisible in the existing test suite because the regression test itself set the
stamp by hand on the fixture request payload — asserting a property of the TEST'S OWN fixture, not of
the code under test, and staying green while the production path never exercised the field it was
supposed to prove. Fixed by reading `$shadow->target_revision_id` — the column frozen server-side at the
moment the composer actually read the entry — as the lock token in `publishAmendment()`, never a
client-supplied value.

**(c) The `wiki_updates` channel wrote directly to EXISTING entries, resolved by slug, firing whenever
ANY draft in the session was accepted — bypassing the card, the diff and the optimistic lock entirely —
and `applyGraphOps()` ran without filtering by which entries the reviewer had actually selected.**
Accepting one, unrelated draft in a session could silently rewrite N existing entries nobody had looked
at, with no card shown for any of them and no way to have refused just one. This is the single most
serious defect this batch caught, because it defeated the review gate (D11) by construction rather than
by a missed check. Fixed by turning every content operation aimed at an EXISTING entity into a SHADOW
DRAFT during laundering (`KnowledgeDraftService::absorbWikiUpdates()`), reported with
`WARN_MOVED_TO_REVIEW` so the ops report says the proposal moved rather than silently vanished; what
survives in `wiki_updates` is now only a NEW entity's own initial body, written together with the entity
by `KnowledgeGraphOpsApplier::createDeclaredEntities()` — a write nobody could accept without also
accepting the entity it belongs to.

**(d) Fixing (c) left the READ half wrong: the draft card and the diff panel rendered an append shadow's
raw `content` column — the addition ALONE — as though it were the entry's entire new text, so a diff
that claimed to show "what accepting this does" actively misrepresented it.** An append shadow's
`content` deliberately holds only the addition (that is what keeps it commutative with a concurrent
human edit — see D-above and ADR-0046 D4), but nothing downstream of that storage decision had been
taught the difference before this batch, so a reviewer looking at the diff saw one new sentence where
the true result was the whole document plus one sentence. Fixed by `KnowledgeEntry::amendedBody()`,
which composes the target's LIVE text with the shadow's addition through `SectionAppender` at READ time
— `KnowledgeEntryResource.amended_body` and `GET …/draft-diff`'s `to.content` both render this composed
result now, with `content`/the raw column reserved for what is actually stored, never shown as the
projected outcome. Documented explicitly in `docs/backend/knowledge-api.md` with a warning to render
`amended_body`, never `content`, for exactly this reason.

**(e) The deferred half of a staged accept was unreachable: `createDeclaredEntities()` used to SKIP an
entity a previous accept had already created and return NOTHING for its handle, so a relation depending
on it in a later accept reported `dependency_not_accepted` — a code that reads exactly like the
reviewer's own refusal, for a dependency that had in fact already been satisfied.** A reviewer publishing
the prose half of a proposal now and returning for the graph half later would find the entity's own
handle simply missing from the live handle map on the second pass, with no way to complete the deferred
relations at all — the code read as a decision the reviewer never made. Fixed by having the ledger
(`applied_ops`) record WHICH ENTRY each `entity:<n>` handle became (`entity:0=<uuid>`, not merely that
the operation ran), so `createDeclaredEntities()` can hand the already-created entry back on a later
pass instead of silently omitting it. Re-deriving the entry from its title was rejected as an alternative
fix — `mintSlug()` de-collides and titles repeat, so a title-based guess could resolve to the WRONG
entry and write a false relation that reads exactly like a true one.

**(f) `App::setLocale()` was never called anywhere in the request lifecycle, so every server-composed
string — validation messages, domain refusals, the relation vocabulary's own labels — rendered in
`APP_LOCALE` regardless of the authenticated user's chosen locale, even though `users.locale` has been
persisted (and offered in the UI switcher) since that feature shipped.** Invisible on a single-locale
installation; on a bilingual one, switching the interface to English and then hitting a relation refusal
or reading a relation type's label from the API still produced Polish prose. This is not scoped to
relations alone, but this batch is what surfaced it — the relation vocabulary's labels are the first
sizeable body of server-composed, user-facing prose this module ships. Fixed app-wide, not locally: a new
`App\Http\Middleware\SetUserLocale` (appended to the `api` middleware group, after `Authenticate` so it
can read the resolved user) sets the locale from `$request->user()?->locale` when it is a supported one;
`users.locale` was made nullable (`2026_08_08_000000_make_users_locale_nullable.php`) so "chose English"
is a distinguishable fact from "never asked" — the column previously defaulted to `'en'`, which the fix
would otherwise have turned into an invented preference for every user who had never touched the
switcher.

**(g) A variable holding the parsed `graph_op_keys` was declared inside a `try` block and referenced
from the `catch` block below it, leaving both the `unknown_op_key` and `inseparable_ops` 422 branches
dead code that could never execute.** Caught during this batch's own review pass, before it reached a
released state; fixed by restructuring `AcceptKnowledgeDraftsRequest::withValidator()` into a single
flow where the parsed key list is computed once, outside any exception boundary, before either
downstream check runs — both codes are exercised by `KnowledgeGraphApplyTest`.

**(A1) A relation handle (`R<n>`) resolved to a row by TYPE and LOWEST ID across the WHOLE BASE, not to
the specific row the handle was minted for — so an approved `end` or `update` could land on a stranger's
relation.** The frozen resolution set gave the composer `R7` as the address of one particular relation,
but `KnowledgeGraphOpsApplier::resolveRelation()` re-derived the row at apply time by looking up "the
current relation of this handle's TYPE, ordered by id, take the first" — a guess that happens to be
correct exactly when a base has at most one active relation of that type touching the resolved entity,
and silently wrong the moment it has two. A reviewer approving "end Anna's membership at Acme" could
therefore end a DIFFERENT membership relation that merely shared a type and sorted first, and — because
`supersede()` reads the same resolution — a `replaces` binding could stitch the wrong relation's history
to the new one. **613 green tests never caught it because every fixture that exercised the path built
exactly one relation of the type under test** — the bug needs a SECOND relation of the same type on the
same entity to become observable at all, which nothing in the existing suite constructed. Fixed by
carrying the relation's own database id inside the frozen handle itself: `ResolvedRelation` (the DTO the
resolution set serializes) grew an `id` field alongside `handle`, and `resolveRelation()` now looks the
row up `WHERE id = <that id> AND knowledge_base_id = <this base>` — re-checked as current and same-base
at apply time, never trusted blindly, so a stale or cross-base id resolves to nothing rather than to a
write. The obvious objection — a set frozen for a prompt is not supposed to carry a database id where a
model can see it — is answered by WHERE the set is read: `KnowledgeDraftService::knownEntities()`
renders the frozen state field by explicit field (handle, label, dates, properties, description) into
the prompt, and nothing serializes the array wholesale, so the id never reaches the model's context even
though it rides along in the same jsonb column. A second, smaller defect travelled with the same fix:
when a relation's BOTH ends were in one resolution pass, it used to be described twice — once from each
end — and MINTED A SEPARATE HANDLE EACH TIME, so "Anna knows Bob" could arrive as both `R3` (from Anna's
side) and `R9` (from Bob's), two addresses for one row with no way to tell they were the same edge.
`describeRelations()` now mints a handle ONCE per relation id and reuses it across both listings (a
`$minted` map threaded by reference through the whole resolution pass) — the direction and label still
differ per side, because that is the entire point of reading a relation from each end, but the handle
identifying WHICH row no longer does.

## Known limitations

- **`relations_detected_at` does not exist as a session field, and there is no separate "detect
  relations" action.** Relation extraction happens INSIDE ordinary composition/refinement — the same
  call that proposes entries and content also proposes `graph_ops` — so the frontend spec's §27.5
  ("a second, metered pass, mirroring `expand-context`") and its blocking question GK1 are answered
  "no": there is no separate button, no separate spend line, and nothing analogous to
  `context_expanded_at` for relations. A dedicated, independently-triggerable relation-detection pass is
  a real Stage 2 candidate, not something this batch approximates.
- **A session whose resolution set was frozen BEFORE (A1)'s fix has no `id` on its relation handles, and
  every `end`/`update` operation naming one of those handles now reports `relation_gone` rather than
  applying — by design, not as an accepted regression.** `resolveRelation()` treats a handle with no `id`
  field as unresolvable and returns `null` (`SKIP_RELATION_GONE`) instead of falling back to the
  by-type/lowest-id guess the fix exists to be rid of — the guess is precisely the behaviour A1 removed,
  and reintroducing it as a fallback for old sessions would silently bring the defect back for exactly
  the sessions old enough to still be open. The alternative — inferring which relation an old, id-less
  handle "must have meant" from context — was rejected for the same reason re-deriving a
  `createDeclaredEntities()` handle from a title was rejected elsewhere in this document: a guess that is
  sometimes right produces a false fact that reads exactly like a true one. A session old enough to be
  affected is also old enough to be within `knowledge:reap-draft-sessions`'s ordinary 14-day window, so
  this self-heals the same way the `applied_ops` mixed-format limitation above does, with no migration.
- **`applied_ops` is a mixed format across sessions created before and after (e) above was fixed.** A
  session generated before this fix recorded a bare `entity:<n>` with no `=<uuid>` suffix; on such a
  session the deferred half of a staged accept is still unreachable exactly as (e) describes. This is
  self-healing rather than migrated: `knowledge:reap-draft-sessions` purges any session untouched for 14
  days (`knowledge.drafting.abandon_after_days`), and no backfill migration was written, because turning
  a bare `entity:0` into `entity:0=<uuid>` after the fact would require GUESSING which entry the handle
  became — by title, which collides and de-collides exactly the way (e)'s own fix rejected as an
  approach.
- **A `replaces` degradation (`WARN_REPLACES_UNBOUND`) is silent for a reviewer who does not read the
  warnings section.** The relation itself is still written and still true; only the "this replaces that
  other one" annotation is lost, along with the `superseded_by_id` link a reader would otherwise have
  followed. There is no separate, louder surface for this today beyond the ops report's warnings list.
- **The subject-erasure command's per-session scan cost grew by a fifth jsonb surface.** Matching a
  phrase against a drafting session now scans `prompt_history`, `retrieval_set`, `resolution_set`,
  `graph_ops` AND `notes` (previously four surfaces, before the composer's own run-notes column existed)
  — see the "Data erasure" update in `docs/backend/knowledge-api.md`. This batch additionally added a
  SIXTH, session-independent surface: `KnowledgeRelation.description`/`.properties`, scanned as its own
  match category (`matchedRelations()`) because a relation's free-text description can name a subject
  that neither entry it connects ever mentions, and the entry cascade alone cannot reach that text.
  Neither surface is expected to matter for `knowledge.purge.max_entries` breadth in practice (a session
  or a relation is a small document relative to an entry), but it is a real, measured cost increase on
  every `knowledge:purge-subject` run, not merely a documentation update.
- **`POST …/accept`'s `updated[]` field, once found to be permanently empty (see the documentation
  verification note below), has since been REMOVED from the response — `accepted`/`relations`/
  `conflicts`/`skipped` are the whole shape now.** It existed to report "entries the graph half
  changed," but nothing in `KnowledgeGraphOpsApplier` ever wrote to it, and the one case it was meant
  for — an amendment's target — was already fully covered: `publishAmendment()` returns the LIVE target
  entry, which `accepted[]` already carries for that draft id. Keeping an always-empty field with a
  plausible-sounding name was judged worse than removing it. `entity_gone`/`unknown_handle` on
  `KnowledgeGraphOpsApplier` were the same shape of gap (constants declared for a case — a target
  vanishing mid-review — that surfaces through the shadow-draft `conflicts[]` path instead) and were
  removed alongside it, with a comment left in the source stating plainly that neither skip exists.

## Alternatives considered

- **Extending `knowledge_links` with a `source: 'relation'` row instead of a new table.** Rejected — see
  D1. The link table's unique key (`from_entry_id`, `target_slug`, `source`) cannot express two different
  relation types between the same pair, it has no temporal columns (`valid_from`/`valid_to`), and its
  only "a human said no" mechanism (`dismissed_at`) is a reversible soft flag, not the append-only,
  human-restricted `retract`/`delete` split this feature needs. More decisively: the link sweep's
  wholesale `DELETE`-and-rebuild is exactly the operation an approved relation must survive.
- **Reusing the `changelogs` module for the audit trail.** Rejected — see D2's four independent reasons,
  the tenant-mirror gap being decisive on its own.
- **A hard (strict-mode) type-matrix refusal, matching the module's posture everywhere else.** Rejected
  for the pair matrix specifically — see D4. Every entry predating `entry_type` carries `null`, and a
  hard refusal on a guess would reject correct relations across a whole base's existing history.
- **Auto-including the paired half of a `replaces`/`end` selection rather than refusing a split.**
  Rejected — see D10. Writing something the reviewer never selected is the same class of defect as a
  checkbox that silently does more than it says.
- **An auto-accept mode for high-confidence proposals.** Rejected outright by the owner (D11) — the
  review gate is binding for everything in this module, with no confidence threshold that bypasses it.
- **Re-deriving a `createDeclaredEntities()` handle from the entity's title on a later accept, instead of
  recording the entry id in the ledger.** Rejected — see (e). `mintSlug()` de-collides titles, so a
  title-based guess could resolve to the wrong entry, writing a false relation.

## Consequences

- **A relation carries no score, is never filtered by `min_score`, and outranks a similarity edge for
  the graph's node cap.** `KnowledgeGraphService` synthesizes a maximum ranking weight for a relation
  (`RELATION_SCORE = 1.0`) purely for ordering when the node cap forces a choice — a statement a person
  approved outranks a vector's 0.9 guess — but a relation is never dropped by tightening the similarity
  threshold, because the notion does not apply to an assertion the way it applies to a measurement.
- **The graph endpoint gained two new query parameters** (`relations`, default on; `include_historical`,
  default off) and `edges[]` gained a first-class `kind` discriminator (`link` | `relation`) with every
  field of the OTHER kind present-and-null rather than absent — a client never tests for key existence
  before reading a value. See the updated `docs/backend/knowledge-api.md` → "Graph."
- **Two new per-base/per-session caps exist in config**: `knowledge.relations.max_relations_per_entry`
  (40, active relations only — history does not compete for the budget) and
  `knowledge.relations.max_ops_per_session` (20, the composer's own per-run ceiling on proposed graph
  operations).
- **The frontend spec's blocking contract questions (§27.12, GK1–GK10) are now all answered by shipped
  code**, several of them differently from the spec's own assumption — see the new errata section
  (`docs/next/knowledge-uxui-spec.md`) for the full, numbered accounting, including the field-name
  corrigenda (`predicate`→`relation_type`, `valid_until`→`valid_to`, `entity_type`→`entry_type`) and the
  `retract`≠hard-delete distinction the spec's own confirmation dialog copy had backwards.
- **`docs/ai/reference-links.md` §7 ("LLM relation extraction") moves from "accepted direction for a
  not-yet-built Stage 2" to "implemented, with one named departure."** The departure (D4, the advisory
  pair matrix) is recorded there as an explicit, reasoned exception to the source research's own
  principle, not a silent drift from it.
