# ADR-0049 — Authorship withdrawn: the module is read-only for humans

**Date:** 2026-08-06
**Status:** Accepted
**Module:** `App\Modules\Knowledge` (`routes/api.php`, `Policies\{KnowledgeEntry,KnowledgeRelation}Policy`,
`Http\Resources\{KnowledgeEntry,KnowledgeEntryList,KnowledgeRelation}Resource`,
`Services\{KnowledgeEntryService,KnowledgeRelationService,KnowledgeDraftService,KnowledgeGraphOpsApplier}`,
`Support\DraftRunNotes`), `tests/Feature/{KnowledgeEntryRulesTest,KnowledgeAuthoringWithdrawnTest}.php`
**Relates to:** ADR-0043 (the module design this batch withdraws half of — bases/entries were originally
fully human-editable), ADR-0046 (the AI composer, now the module's ONLY writer), ADR-0047 (typed
relations and the human relation panel this batch removes), ADR-0048 (the subject paradigm — the
composer content this decision makes the sole source of)

---

## Context

The product owner decided that a person may no longer author knowledge by hand. An entry or a relation
enters a base one way: the AI composer proposes it, and a person **approves, refuses, or asks again in
different words**. Nothing may be typed directly into a title or a body, no entry may be trashed or
restored one at a time, no relation may be drawn, edited, ended, retracted or deleted from a form, and no
past revision may be republished by a click. The composer (ADR-0046) already existed as *a* way to write
an entry; this batch makes it the *only* way, and reaches further than the composer's own surface — it
also closes the plain entry editor and the relation panel that ADR-0043/0047 built as ordinary,
independent, hand-operated screens.

This is a narrower rewrite than it sounds, in one respect: an earlier batch (documented as "B13" in
`docs/next/knowledge-uxui-spec.md` §25) had already retired *creating* a new entry by hand — the editor
kept only its edit mode, and `POST /entries` survived solely as the composer's own acceptance call. What
this batch removes is everything B13 left standing: **editing** an existing entry, ending its life
(trash/restore/purge), reordering a base, and every hand-operated relation verb. After this batch there is
no FormRequest, no controller action and no policy ability anywhere in the module that lets a person put
their own words into an entry's title or body, or assert, alter or retire a typed relation, by any route.

## Decisions

**D1 — every write-side entry/relation endpoint is removed from the route table, not merely refused.**
Gone from `routes/api.php`: entry `store`/`update`/`destroy`/`restore`/`forceDestroy`/`reorder`; relation
`store`/`update`/`end`/`destroy`; revision `restore`. Gone from `Http/Requests/`: the eight FormRequests
that used to guard them (`Store`/`UpdateKnowledgeEntryRequest`, `Store`/`Update`/`End`/
`DestroyKnowledgeRelationRequest`, `ReorderKnowledgeEntriesRequest`, `RestoreKnowledgeRevisionRequest`).
Gone from `KnowledgeEntryService`: the `reorder()` and `restoreRevision()` methods themselves — not just
their routes — so there is no code path left that can perform either operation, from any caller, ever.
The module's own route-file docblock states the intent directly: *"this file has no route that creates,
edits or deletes an entry or a relation, and it will not grow one back."*

**D2 — link-promotion died with the relation `store` route, and the code says so rather than leaving a
silent hole.** Promoting a machine-suggested edge (a `similarity`/`mention` `KnowledgeLink`) into a typed
relation was a person's action, reachable only through `POST /relations` with `promote_link_id`. That
route is gone, and `KnowledgeRelationService::create()` carries no promotion branch any more:

```php
// NO PROMOTION BRANCH. Promoting a machine suggestion into a hard relation was a person's
// action, through `POST /relations`, and that endpoint no longer exists — so every relation
// written here comes from an accepted proposal and is logged as an ordinary `create`.
```

**Erratum (debt sweep, ADR-0043–0050 follow-up).** This decision originally kept
`KnowledgeRelationEvent::OP_PROMOTE` "because historical rows carry it". They do not: the module has
never been committed to a shipped branch, and the only database that has ever run it holds `create`
events exclusively (`origin` likewise `composer` only, 26 rows). The constant was **deleted** — a named
constant nothing emits sends the next reader hunting for the writer. `op` is a plain string column, so a
row carrying `promote` would still read back if one ever appeared. `KnowledgeRelationOrigin::HUMAN` and
`::PROMOTED` are **kept** on the opposite reasoning, stated at the cases themselves: they are an
Eloquent CAST on a stored column and a published API value, so deleting a case makes the row that
carries it throw on read and narrows a documented response field — a product decision, not a tidy-up.

`KnowledgeRelationOrigin::PROMOTED` and `::HUMAN` are consequently **historical values only** from this
batch forward: `KnowledgeGraphOpsApplier`, the sole surviving caller of `create()`, always passes
`origin: KnowledgeRelationOrigin::COMPOSER`. A base that shows a `human`- or `promoted`-origin relation is
showing something asserted before this pivot, never something written today.

**D3 — two barriers, deliberately, and the second is not a formality.** The route is gone (structural —
a request 404s/405s at the router before any controller runs), **and** `KnowledgeEntryPolicy`/
`KnowledgeRelationPolicy` answer `false` in writing for every authoring ability. The policy methods are
kept rather than deleted, on purpose:

> a policy with no method for an ability is not a refusal — Laravel falls through to whatever the gate's
> default is. Answering `false` in writing is the barrier; deleting the method would be a hole shaped
> like a tidy-up.

`KnowledgeEntryPolicy::create/update/reorder/delete/restore/forceDelete` and
`KnowledgeRelationPolicy::create/update/end/delete` all return `false`, unconditionally, for every user —
so a future route re-added without reading this ADR is refused by the policy the moment it is wired up,
rather than silently working again. `KnowledgeAuthoringWithdrawnTest` pins both barriers independently:
`test_the_withdrawn_route_names_do_not_exist` (the router) and
`test_the_authoring_abilities_are_refused_for_everyone` (the policy), so restoring only one half still
fails the suite.

**D4 — "may direct the AI and approve its output" is a different ability from "may write by hand," and
conflating them is what nearly took the composer down with hand-authorship.** Before this batch, five
composition actions (start, refine, expand context, rebase, accept) were authorized through
`$user->can('create', KnowledgeEntry::class)`, and rejecting a draft through `$user->can('delete',
$draft)` — because, until now, both statements happened to be true of everybody at once. Denying `create`
and `delete` for the withdrawal would have silently denied the composer too. Three abilities were split
out under their own names instead of being folded into the now-denied ones:

| Ability | Rule | Replaces the borrowed use of |
|---|---|---|
| `compose` (`KnowledgeEntry`) | `$user !== null` | `create` — gated every draft-session endpoint (open, refine, expand-context, rebase, accept) |
| `retryIndex` (`KnowledgeEntry $entry`) | `$user !== null` | `update` — re-queuing a failed indexing run touches no text |
| `rejectDraft` (`KnowledgeEntry $entry`) | `$user !== null && $entry->isDraft()` | `delete` — refusing a *proposal* is not the same act as trashing a *published* entry, and the ability is refused outright for anything that is not a draft, so it can never become a back door to the first |

The module's own reasoning, quoted directly because it is the crux of the whole decision: *""May direct
the AI and approve its output" and "may write an entry by hand" were the same sentence only for as long
as both were true of everybody.""*

**D5 — what remains is a specific, enumerable list, not "whatever wasn't explicitly removed."** Every
read endpoint (list, show, revisions index, relations index, search, the graph); the entire composer
surface (`compose-availability`, open/refine/rebase/expand-context/accept/abandon a session, reject one
draft, the draft-diff and draft-relations previews); `POST entries/{entry}/retry-index` (re-queues
indexing, authors nothing); link `dismiss`/`undismiss` (rejecting a *machine* suggestion is refusal, not
authorship — the suggestion was never a person's own assertion to begin with); and the **whole base CRUD
surface, including `PATCH` on the charter**. The charter stays editable on purpose: a base is not an
entry, and `knowledge:purge-subject`'s report-only charter category (ADR-0045) exists specifically to send
an operator to edit it by hand — an erasure command that names a manual-edit remedy nobody could ever
carry out would be pointing at a door that does not open.

**D6 — capability flags that are now permanently `false` for everyone are removed from the wire, not sent
as dead weight.** `KnowledgeEntryResource`/`KnowledgeEntryListResource` drop `can_be_edited`,
`can_be_deleted`, `can_be_purged`; `KnowledgeRelationResource` drops `can_be_edited`, `can_be_ended`,
`can_be_deleted`. `is_owner` stays on both (provenance is still worth knowing even though it no longer
gates a write); `can_be_dismissed` stays on `KnowledgeLinkResource` (dismissing a suggestion is still a
real ability); `index.can_retry` stays; every `KnowledgeBaseResource` capability flag stays, because base
governance is untouched. The reasoning, from the resource itself: *"Sending three permanently-false
booleans on every edge of every graph would be a field the client must read to learn nothing."*

**D7 — `retract` and the hard `DELETE` on a relation are, as of this batch, reachable by nobody through
the product at all — a narrower claim than the module previously documented, and worth stating precisely.**
Before this pivot, `docs/backend/knowledge-api.md` described the machine contract as knowing only
`create`/`update`/`end`, with `retract` and `DELETE` "reachable ONLY from the relation panel's own human
endpoints." That panel is now gone. `KnowledgeGraphOpsApplier` — the sole caller of
`KnowledgeRelationService::create/update/end/supersede` — never calls `retract()` or `delete()`; nothing
else in the application calls either. `supersede()` and `end()` remain reachable, indirectly, through an
accepted `create` operation that carries a bound `replaces` (see `KnowledgeRelationService`'s own
docblock: *"`create`, `update`, `end` and `supersede` are called by `KnowledgeGraphOpsApplier` when a
human accepts what the composer proposed. There is no HTTP surface for any of them any more."*).
`delete()` remains reachable from exactly one place outside a test: the `knowledge:purge-subject`
erasure command (ADR-0045), which needs it for hard removal on a subject's behalf. `retract` — "this was
never true," a soft, KEPT-row lifecycle state — currently has no caller in the shipped product at all.

## Defects found and fixed along the way

**(j) `KnowledgeEntryService::update()`'s slug-rename branch had no uniqueness check of its own — a
guarantee made of good manners that this withdrawal broke.** `create()` de-collides through `mintSlug()`;
the column itself carries a plain index, not a unique one (the trash has to be able to hold a same-slug
row — see ADR-0043). The ONLY reason two live entries never landed on one slug through `update()` was
that, until this batch, its single caller happened to de-collide beforehand. Withdrawing hand-authorship
removed every *other* writer that might once have tripped over the gap first, and — because `update()` is
still the path `publishAmendment()` calls at accept time — turned a latent gap into a real one. Fixed by
checking `slugTaken()` before applying an explicit rename and throwing `KnowledgeSlugConflictException`
otherwise, mirroring the check `restore()` already performs:

```php
if ($dto->slug !== null && $dto->slug !== $previousSlug) {
    $base = $entry->base()->firstOrFail();

    if ($this->slugTaken($base, $dto->slug, $entry)) {
        throw new KnowledgeSlugConflictException($dto->slug);
    }

    $entry->slug = $dto->slug;
}
```

Pinned by `KnowledgeAuthoringWithdrawnTest::test_renaming_an_entry_onto_a_taken_slug_is_refused` and
`::test_saving_an_entry_under_its_own_slug_is_not_a_conflict` (the negative case — resaving under an
entry's own address must not read as a collision with itself). **This closes what an earlier verification
pass on this same batch had flagged as an open, unfixed gap** — it is not.

**(k) the chunk-cap dry run went with the entry FormRequest, silently, and an over-cap AI draft would
have been accepted and only failed indexing in the background — a page in the base no search would ever
return and no screen would ever explain.** The check used to answer a `422` to the person typing, inside
`StoreKnowledgeEntryRequest`/`UpdateKnowledgeEntryRequest`. Removing those requests removed the guard with
them, and nothing replaced it at first. Fixed: the composer's own laundering pass now asks the identical
question — via `KnowledgeDraftService::wouldBurstChunkCap()`, run against the FINAL published text (the
live target's body plus the addition, for a shadow amend, so an append that would only burst the cap once
folded into a long target is caught) — and drops the proposal, reporting
`DraftRunNotes::ENTRY_TOO_MANY_CHUNKS` (`entry_too_many_chunks`) to the reviewer rather than letting it
fail silently downstream. This is a fifth `notes[]` code, alongside `amend_append_only`, `amend_too_long`,
`wikilinks_lost` and `resolution_degraded` — `docs/backend/knowledge-api.md`'s "exactly 4 codes" is
corrected to 5 by this batch's documentation pass. Pinned by
`KnowledgeAuthoringWithdrawnTest::test_a_draft_that_could_never_be_indexed_is_dropped_and_reported` and
`::test_a_draft_within_the_chunk_cap_is_proposed_normally` (the negative case, so the guard is not simply
refusing everything).

## Rules that lost coverage

Withdrawing the entry FormRequests did not just remove an HTTP contract — it removed the only layer that
ever enforced several rules. Some were re-derived at the composer's own laundering layer (the slug,
oversize content, template-directive syntax, alias trimming/capping — see
`tests/Feature/KnowledgeEntryRulesTest.php`, which replaces the deleted `KnowledgeEntryCrudTest` and
follows each surviving rule to where it lives now). Two were fixed as defects above. The following did
**not** get a replacement, and are recorded here as accepted, currently-true gaps rather than left to be
discovered by surprise:

- **A required metadata field can now be absent.** `POST`/`PATCH /entries` used to 422 on a missing
  non-nullable field; `KnowledgeDraftService::metadata()` walks the base's descriptors and simply
  `continue`s past any key the model's reply did not mention — there is no notion of "missing" in the
  laundering pass at all. Pinned, deliberately, as a **characterized loss**:
  `KnowledgeEntryRulesTest::test_a_missing_non_nullable_metadata_field_is_no_longer_refused` — "so that
  restoring the requirement is a deliberate act with a red test in front of it rather than a surprise."
- **An invalid metadata field is silently dropped, never refused.** The verdict CHANGED with the
  endpoint: a bad-typed field, an undeclared key, or a field whose value smuggles template syntax used to
  refuse the whole request; the laundering now drops only that one key and keeps the rest of the entry
  (`KnowledgeEntryRulesTest::test_metadata_of_the_wrong_type_is_dropped_and_the_rest_of_the_entry_survives`,
  `::test_an_undeclared_metadata_field_is_dropped`, `::test_template_syntax_inside_metadata_costs_only_that_field`).
  A deliberate, tested trade-off — there is no writer standing at a form to be told which field was wrong
  — but it is a real behaviour change: bad data now enters a base quietly rather than bouncing at the
  door.
- ~~**An entry proposal with an empty or absent title is dropped with no note and no test pin.**~~
  **CLOSED by the debt sweep**, which took this ADR's own suggestion that it was "a good first candidate
  if this list is ever worked down". The drop now raises `DraftRunNotes::ENTRY_INCOMPLETE`
  (`{code, field, name}`) and is pinned four ways in `KnowledgeEntryRulesTest`. One detail found while
  fixing it, worth more than the fix: the note must NOT carry a `slug`, because the client files a
  slug-bearing note under that entry's CARD — and a proposal that was dropped has no card, so the
  "reported" note would have rendered nowhere and the drop would have stayed exactly as silent one layer
  further along. The handle travels as `name`. The original text of this item follows.
  `KnowledgeDraftService::launder()`: `if ($title === null || $content === null) { continue; } // an
  entry without a name or a body is not an entry`. Unlike every sibling drop this batch hardened (the
  chunk cap, `amend_too_long`, `wikilinks_lost`), this one raises no `DraftRunNotes` code — a reviewer is
  never told an entry was silently discarded for want of a title — and no test in the suite exercises the
  branch at all. Of the four items reviewed for this ADR, this is the one with the least coverage on both
  axes (behaviour is unreported, and the report is unpinned): a good first candidate if this list is ever
  worked down.
- **Oversize content is truncated, not refused — restated here because it is the same shape of change as
  the two metadata items above, not because it is uncovered.** `POST`/`PATCH /entries` used to 422 past
  `knowledge.entry_max_chars`; the laundering clips at the cap instead
  (`KnowledgeEntryRulesTest::test_content_over_the_cap_is_truncated_rather_than_refused`). Listed for
  completeness of the "verdict changed from refuse to silently-modify" pattern, not as an open gap — it is
  fully tested.

**The product consequence is worth stating in one sentence, because it is the sentence a reviewer will
actually live with: a typo in a generated entry can only be fixed by another AI call, never by
hand-editing.** There is no `PATCH /entries/{entry}` left to correct one character. The only paths back to
a clean entry are asking the composer to refine or re-amend it and accepting the new proposal, or —
for the entries above where the laundering silently dropped or clipped something — noticing the gap at
review time, since nothing downstream will surface it on its own.

## Known limitations

- ~~**The empty-title skip has no server-side signal at all**~~ — **CLOSED**, see "Rules that lost
  coverage" above. The note code and the tests landed in the ADR-0043–0050 debt sweep, as this item
  anticipated. **What remains open in the same family:** a draft dropped for TEMPLATE SYNTAX in its
  title or body (`$this->guard->isClean()`) is still discarded silently, as is one whose title slugifies
  to nothing and which offers no usable slug of its own. The first is deliberate in verdict (fail-closed
  is right) but not in silence; the second is close to unreachable. Neither was bundled into the sweep,
  for the reason this ADR gave the first time: they are laundering-visibility decisions and deserve
  their own pass.
- **The metadata items above are accepted trade-offs, not accidents, but nobody has re-examined whether
  the trade-off still holds now that the composer is the module's ONLY writer.** When a form and a model
  were two independent authors, absorbing a model's sloppiness while refusing a person's made sense
  asymmetrically (see the pre-existing "aliases" write contract, §"The `aliases` write contract" in
  `docs/backend/knowledge-api.md`, which reasoned about exactly this asymmetry before this batch). With
  hand-authorship gone, EVERY entry in a base now takes the lenient path — worth revisiting once real
  usage shows how often a required field or a bad-typed value actually goes missing in practice.
- **`retract` has no caller anywhere in the shipped product**, per D7. It is not dead code by accident —
  the type stays because a base's lifecycle model (`KnowledgeRelationState`) is meaningless without it,
  and because a future human-facing "this was never true" affordance is a plausible, scoped re-addition —
  but as shipped today it can never be reached, which is worth knowing before assuming the relation panel
  simply moved rather than disappeared.
- **This ADR does not re-examine `KnowledgeLink`'s `manual` source**, which predates this batch and is
  untouched by it. See `docs/next/knowledge-uxui-spec.md`'s errata for this batch for the one place that
  surface changed (the graph's layer filter drops it from the UI, independent of authorship).

## Alternatives considered

- **A narrow "fix a typo" ability, scoped to cosmetic edits only.** Rejected — there is no clean,
  enforceable line between "typo" and "content edit" (does correcting a wrong date count? A misattributed
  fact?), and any such ability reopens exactly the parallel-authorship surface the withdrawal exists to
  close, one exception at a time.
- **Deleting the now-`false` policy methods instead of keeping them.** Rejected, explicitly, per D3 — a
  missing ability is a gate fallthrough, not a refusal, and the second barrier only holds if it is
  written down.
- **Refusing the whole draft on any single bad metadata field or a missing required one, mirroring the
  old FormRequest's all-or-nothing verdict.** Considered and rejected for now — throwing away an entire
  AI-authored entry over one field is a worse trade than letting the field drop and catching it at the
  mandatory review step, given every entry now passes through a human reviewer regardless. Left as an
  open question in "Rules that lost coverage" rather than resolved either way, because usage data does not
  yet exist to judge it by.
