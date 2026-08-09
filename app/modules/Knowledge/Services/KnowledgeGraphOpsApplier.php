<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\KnowledgeEntryDTO;
use App\Modules\Knowledge\DTOs\KnowledgeRelationDTO;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationOrigin;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Exceptions\KnowledgeRelationRefused;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Support\EntryAliases;
use Illuminate\Support\Carbon;

/**
 * APPLYING a reviewed graph proposal — the last step of the composer, and the only one that writes.
 *
 * ------------------------------------------------------------------------------------------------
 * EVERY RULE IS CHECKED AGAIN, FROM SCRATCH
 *
 * The laundering in {@see \App\Modules\Knowledge\Support\KnowledgeGraphOps} judged this proposal when
 * the run finished. It has since sat on a reviewer's screen — for a minute or an hour — and the base
 * can move underneath it: the target can be trashed, the relation can be ended by somebody else, an
 * identical statement can be asserted by hand. So nothing here trusts the earlier verdict; every write
 * goes through {@see KnowledgeRelationService}, which re-runs the vocabulary, the matrix, the duplicate
 * guard and the caps.
 *
 * WHAT THAT REFUSES IS REPORTED, NEVER SWALLOWED. A relation the base declines at this point is not an
 * error — it is a fact about the world having changed — and the reviewer who clicked accept has to be
 * told which of the things they approved did not happen and why. Hence `skipped[]`.
 *
 * ------------------------------------------------------------------------------------------------
 * IDEMPOTENT BY LEDGER
 *
 * `applied_ops` records the index of every operation that has been applied. A double click, a
 * re-delivered job, a browser that retried a timed-out POST: all three replay the same accept, and the
 * ledger is what makes the second one a no-op instead of a second relation. The lesson is one this
 * codebase has paid for before — a token per DELIVERY, not per intent.
 *
 * ------------------------------------------------------------------------------------------------
 * IT DOES NOT CHANGE ANY EXISTING ENTRY'S TEXT
 *
 * It used to, and that was a hole: `wiki_updates` wrote an existing entry from here, resolved by slug,
 * firing whenever ANY draft in the session was accepted — no review card, no diff, no chance to refuse.
 * The same conceptual operation arriving as `entries[].action=update` went through the whole shadow
 * machinery instead, so one path walked around the other's safeguards.
 *
 * Content changes to existing entries are SHADOW DRAFTS now, applied one accepted id at a time by
 * {@see KnowledgeDraftSessionService::publishAmendment()} — which is also where an append gets composed
 * late against the live text and a rewrite gets checked against its frozen revision. This class writes
 * relations, and the initial body of an entity the same proposal creates.
 *
 * ------------------------------------------------------------------------------------------------
 * INDEXING IS DISPATCHED AFTER THE COMMIT, NEVER INSIDE IT
 *
 * The write path never waits on an AI provider. Re-indexing an amended entry is queued by the entry
 * observer and runs after the transaction commits, so no row lock is held across an HTTP call and a
 * provider outage can never roll back text a human already approved. The requirement that accepted
 * knowledge is re-embedded is met by the queue, which is the only place it can be met without making
 * the database's consistency depend on somebody else's uptime.
 */
class KnowledgeGraphOpsApplier
{
    public const SKIP_ALREADY_APPLIED = 'already_applied';

    // THERE IS NO `unknown_handle` OR `entity_gone` SKIP, deliberately.
    //
    // Both were declared here and neither was ever emitted, because each case is caught earlier by
    // something that knows more. An unknown handle is refused during LAUNDERING (KnowledgeGraphOps
    // REJECT_UNKNOWN_HANDLE) and never reaches this class; an entity that vanished between composition
    // and acceptance comes out of the SHADOW path as a conflict on the card the reviewer clicked. What
    // is left for this class — a handle resolving to nothing at apply time — is reported as
    // SKIP_DEPENDENCY_NOT_ACCEPTED, which is the honest description of it.
    //
    // Removed rather than kept "reserved": a dead constant with a good name reads like a live code path
    // to the next person, and a client switching on skip codes would carry two branches that can never
    // run.

    public const SKIP_RELATION_GONE = 'relation_gone';

    public const SKIP_DEPENDENCY_NOT_ACCEPTED = 'dependency_not_accepted';

    public const SKIP_REFUSED = 'refused';

    /**
     * The reviewer did not choose this operation.
     *
     * Reported rather than left out, because a refusal that leaves no trace reads exactly like a bug
     * that dropped the operation — and the reviewer needs to see that their "no" took effect.
     */
    public const SKIP_NOT_SELECTED = 'not_selected';

    public function __construct(
        private KnowledgeRelationService $relations,
        private KnowledgeEntryService $entries,
    ) {}

    /**
     * Apply everything in this session's proposal that has not been applied yet.
     *
     * @param  array<string, KnowledgeEntry>  $acceptedNewEntities  N-handle => the entry it became
     * @param  array<int, string>|null  $selected  operation keys the reviewer chose; null = all of them
     * @return array{applied: array<int, KnowledgeRelation>, conflicts: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    public function apply(
        KnowledgeDraftSession $session,
        KnowledgeBase $base,
        KnowledgeEntryStatus $status,
        array $acceptedNewEntities = [],
        ?array $selected = null,
    ): array {
        $ops = $session->graphOps();
        $applied = $this->appliedIndexes($session);

        $result = ['applied' => [], 'conflicts' => [], 'skipped' => []];

        // The handle map is rebuilt from the LIVE base, not from the frozen set: an entity that was
        // renamed or trashed since the composition must stop resolving, which turns its operations into
        // reported skips rather than writes against the wrong row.
        $handles = $this->liveHandles($session, $base) + $acceptedNewEntities;

        // `wiki_updates` DELIBERATELY HAS NO BRANCH HERE ANY MORE.
        //
        // It used to write an existing entry's text from this loop — resolving the entity by slug and
        // firing whenever ANY draft in the session was accepted, with no review card, no diff and no
        // chance to refuse. That is now impossible by construction: a content change aimed at an
        // existing entry is turned into a SHADOW DRAFT while the answer is laundered, and shadows are
        // applied by the draft path, one accepted id at a time. What survives in `wiki_updates` is the
        // initial content of a NEW entity, written with the entity itself by createDeclaredEntities().
        // ENDINGS FIRST, then everything else.
        //
        // A `create` may declare `replaces` for an `end` the model happened to write after it, and the
        // binding is a property of the SET rather than of the position. Applying in payload order would
        // make a replacement link up or not depending on how the model sequenced its answer — a rule
        // nobody could reason about. Reordering is safe because every key is index-based, so neither the
        // ledger nor the reviewer's selection moves, and the whole batch is one transaction regardless.
        $ordered = $ops['graph_updates'];
        uasort($ordered, static fn (array $a, array $b): int => (int) (($b['op'] ?? '') === 'end') <=> (int) (($a['op'] ?? '') === 'end'));

        // Handle => the relation the `end` in this batch resolved to, so a `replaces` can point at a row
        // rather than at a handle nothing has looked up yet.
        $endedRelations = [];

        foreach ($ordered as $index => $update) {
            $key = 'graph:' . $index;

            // THE REVIEWER'S SELECTION. Null means they did not select and everything applies; a list
            // means they did, and an operation outside it is REPORTED rather than silently absent — a
            // refusal that leaves no trace is indistinguishable from a bug that dropped the operation.
            if ($selected !== null && !in_array($key, $selected, true)) {
                $result['skipped'][] = ['op' => $key, 'code' => self::SKIP_NOT_SELECTED];

                continue;
            }

            if (in_array($key, $applied, true)) {
                $result['skipped'][] = ['op' => $key, 'code' => self::SKIP_ALREADY_APPLIED];

                // AN ENDING APPLIED EARLIER STILL BINDS ITS REPLACEMENT.
                //
                // This used to `continue` straight past, leaving `$endedRelations` empty for a `create`
                // that names it — so a supersede split across two passes lost its link silently: the
                // old relation ended, the new one existed, and nothing joined them. A reader following
                // the retired fact reached a dead end instead of the fact that replaced it.
                //
                // Looked up WITHOUT the active filter, because by now it is precisely not active.
                if (($update['op'] ?? null) === 'end') {
                    $ended = $this->resolveRelation($update, $base, $session, activeOnly: false);

                    if ($ended !== null) {
                        $endedRelations[(string) ($update['relation'] ?? '')] = $ended;
                    }
                }

                continue;
            }

            $outcome = $this->applyGraphUpdate($update, $base, $handles, $session, $endedRelations);

            if (($update['op'] ?? null) === 'end' && isset($outcome['relation'])) {
                $endedRelations[(string) ($update['relation'] ?? '')] = $outcome['relation'];
            }

            $this->collect($result, $outcome, $key, $applied, $session);
        }

        return $result;
    }

    /**
     * Fold one operation's outcome into the result, and record it as applied when it wrote something.
     *
     * The ledger is written INSIDE the surrounding transaction, so a rollback un-applies the record of
     * the application too — the two can never disagree about what happened.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $outcome
     * @param  array<int, string>  $applied
     */
    private function collect(array &$result, array $outcome, string $key, array &$applied, KnowledgeDraftSession $session): void
    {
        if (isset($outcome['skipped'])) {
            $result['skipped'][] = ['op' => $key] + $outcome['skipped'];

            return;
        }

        if (isset($outcome['conflict'])) {
            $result['conflicts'][] = ['op' => $key] + $outcome['conflict'];

            return;
        }

        // NO ENTRY BRANCH. The graph half does not touch the text of an existing entry — that channel
        // was closed when `wiki_updates` was routed through shadow drafts (see the loop above), and the
        // one entry it does write, a declared entity's initial body, is written with the entity itself
        // in createDeclaredEntities(). Anything folded in here would be an entry nothing produces.
        if (isset($outcome['relation'])) {
            $result['applied'][] = $outcome['relation'];
        }

        $applied[] = $key;
        $session->forceFill(['applied_ops' => array_values(array_unique($applied))])->save();
    }

    // ---- relations ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $update
     * @param  array<string, KnowledgeEntry>  $handles
     * @return array<string, mixed>
     */
    private function applyGraphUpdate(array $update, KnowledgeBase $base, array $handles, KnowledgeDraftSession $session, array $endedRelations = []): array
    {
        $op = (string) ($update['op'] ?? '');

        return match ($op) {
            'create' => $this->createRelation($update, $base, $handles, $session, $endedRelations),
            'update', 'end' => $this->changeRelation($op, $update, $base, $session),
            // Unreachable: the laundering already dropped everything else. Answered rather than
            // assumed, because "cannot happen" is a property of today's callers.
            default => ['skipped' => ['code' => self::SKIP_REFUSED, 'reason' => $op]],
        };
    }

    /**
     * @param  array<string, mixed>  $update
     * @param  array<string, KnowledgeEntry>  $handles
     * @return array<string, mixed>
     */
    private function createRelation(array $update, KnowledgeBase $base, array $handles, KnowledgeDraftSession $session, array $endedRelations = []): array
    {
        $from = $handles[(string) ($update['from'] ?? '')] ?? null;
        $to = $handles[(string) ($update['to'] ?? '')] ?? null;

        if ($from === null || $to === null) {
            // The commonest cause is a relation naming an entity whose own draft was NOT accepted in
            // this batch. That is not an error and must not read like one — the reviewer accepted a
            // subset, and the preview told them this relation depended on the rest.
            //
            // THE MISSING SUBJECT IS NAMED, not merely handled. `N1` means nothing to the person who
            // just unticked a card; "Influencerka" means everything. This matters more than it looks:
            // in a subject-shaped graph one card is an end of nearly every edge, so refusing a single
            // draft can silently take the entire graph with it — which is exactly what happened on the
            // first real run, and the response said only `dependency_not_accepted` eight times.
            $missing = [];

            foreach ([$update['from'] ?? null, $update['to'] ?? null] as $handle) {
                if (is_string($handle) && ($handles[$handle] ?? null) === null) {
                    $missing[$handle] = $this->declaredTitle($session, $handle);
                }
            }

            return ['skipped' => [
                'code' => self::SKIP_DEPENDENCY_NOT_ACCEPTED,
                'from' => $update['from'] ?? null,
                'to' => $update['to'] ?? null,
                // handle => the title of the draft that was not accepted, or null if the handle names
                // nothing this session proposed.
                'missing' => $missing,
            ]];
        }

        $type = KnowledgeRelationType::tryFrom((string) ($update['type'] ?? ''));

        if ($type === null) {
            return ['skipped' => ['code' => self::SKIP_REFUSED, 'reason' => 'unknown_type']];
        }

        try {
            $relation = $this->relations->create($base, new KnowledgeRelationDTO(
                fromEntryId: (string) $from->getKey(),
                toEntryId: (string) $to->getKey(),
                type: $type,
                description: $update['description'] ?? null,
                properties: is_array($update['properties'] ?? null) ? $update['properties'] : [],
                validFrom: $this->date($update['valid_from'] ?? null),
                validTo: $this->date($update['valid_to'] ?? null),
                // COMPOSER, not human: a model proposed this and a person approved it, and the base
                // must be able to tell those apart later. See KnowledgeRelationOrigin.
                origin: KnowledgeRelationOrigin::COMPOSER,
                draftSessionId: (string) $session->getKey(),
            ));
        } catch (KnowledgeRelationRefused $refused) {
            // The base declined it NOW — a duplicate somebody asserted by hand, a cap reached, a type
            // the owner has since removed from the vocabulary. Reported with the reason, because it is
            // news about the world rather than a bug.
            return ['skipped' => ['code' => self::SKIP_REFUSED, 'reason' => $refused->reason] + $refused->context];
        }

        // THE BINDING, made real: the relation this one replaces now POINTS AT IT.
        //
        // Direction is old → new (`superseded_by_id` on the ended row), which is what the column was
        // built for: a reader following a fact that has stopped being true arrives at the one that
        // replaced it, rather than at a dead end. The reverse would make the current fact carry a
        // pointer to history, which is the wrong way for a reader to travel.
        //
        // Routed through the relation service's own `supersede()` so the audit trail gets a `supersede`
        // event carrying BOTH ids — the link is in the log, not only in a column.
        $replaced = $endedRelations[(string) ($update['replaces'] ?? '')] ?? null;

        if ($replaced !== null) {
            $this->relations->supersede($replaced, $relation);
        }

        return ['relation' => $relation];
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    private function changeRelation(string $op, array $update, KnowledgeBase $base, KnowledgeDraftSession $session): array
    {
        $relation = $this->resolveRelation($update, $base, $session);

        if ($relation === null) {
            // Ended, retracted or deleted between the composition and the click. Not an error: somebody
            // got there first, and the outcome the reviewer wanted is already true.
            return ['skipped' => ['code' => self::SKIP_RELATION_GONE, 'relation' => $update['relation'] ?? null]];
        }

        if ($op === 'end') {
            return ['relation' => $this->relations->end($relation, $this->date($update['valid_to'] ?? null))];
        }

        try {
            return ['relation' => $this->relations->update(
                $relation,
                $update['description'] ?? $relation->description,
                is_array($update['properties'] ?? null) && $update['properties'] !== [] ? $update['properties'] : null,
                $relation->valid_from,
                $relation->valid_to,
            )];
        } catch (KnowledgeRelationRefused $refused) {
            return ['skipped' => ['code' => self::SKIP_REFUSED, 'reason' => $refused->reason] + $refused->context];
        }
    }

    /**
     * The live relation an `R<n>` handle names, or null.
     *
     * READ FROM THE ID THE FREEZE RECORDED, then re-checked. It is not re-derived: this used to look
     * the handle up by TYPE and take the lowest id in the base, so `end R1` landed on whichever active
     * relation of that type happened to sort first — a different edge from the one the reviewer read,
     * with `superseded_by_id` then binding the wrong history to the new fact. Every fixture had one
     * relation per type, which is why six hundred green tests never saw it.
     *
     * The RE-CHECK is what that lookup was reaching for, and it stays: `current()`, so an edge somebody
     * ended in the meantime stops resolving instead of being ended twice; and the base's own key, so an
     * id belonging to another base resolves to nothing rather than to a write.
     *
     * `$activeOnly` is dropped in exactly one case — re-finding an ending THIS SESSION already applied,
     * so a replacement accepted in a later pass can still be bound to it. The base guard is never
     * dropped.
     *
     * @param  array<string, mixed>  $update
     */
    private function resolveRelation(array $update, KnowledgeBase $base, KnowledgeDraftSession $session, bool $activeOnly = true): ?KnowledgeRelation
    {
        $handle = is_string($update['relation'] ?? null) ? $update['relation'] : null;

        if ($handle === null) {
            return null;
        }

        foreach ($session->resolutionSet()['entities'] as $entity) {
            foreach (is_array($entity['relations'] ?? null) ? $entity['relations'] : [] as $frozen) {
                if (($frozen['handle'] ?? null) !== $handle) {
                    continue;
                }

                $id = is_string($frozen['id'] ?? null) ? $frozen['id'] : '';

                if ($id === '') {
                    // A set frozen before the id was carried. The handle stops resolving rather than
                    // falling back to the guess this method exists to be rid of.
                    return null;
                }

                return KnowledgeRelation::query()
                    ->where('knowledge_base_id', $base->getKey())
                    ->whereKey($id)
                    ->when($activeOnly, fn ($query) => $query->current())
                    ->first();
            }
        }

        return null;
    }

    // ---- new entities -----------------------------------------------------------------

    /**
     * Create the entities a proposal declared (`N<n>`), inside the caller's transaction.
     *
     * They are ordinary entries created through the ordinary service, so each gets a revision, a minted
     * slug and an indexing queue entry exactly like one a person typed. Returned keyed by handle so the
     * relation operations in the same batch can name them — which is what lets "Anna met Bob" create
     * Bob and the relation as one indivisible act.
     *
     * ------------------------------------------------------------------------------------------------
     * AN ENTITY CREATED BY AN EARLIER ACCEPT IS STILL RETURNED
     *
     * This used to skip an already-applied entity and return nothing for it, which silently destroyed
     * the staged review the gate exists to encourage. A reviewer who published the prose now and came
     * back for the graph a minute later found `N1` missing from the handle map — even though the entry
     * it names existed — so every relation touching it was reported as `dependency_not_accepted`, a
     * code that reads like their own decision. The deferred half could never be completed, and nothing
     * anywhere said so.
     *
     * The ledger therefore records WHICH ENTRY each handle became (`entity:0=<uuid>`), not merely that
     * the operation ran. Re-deriving it from the title would be a guess — `mintSlug` de-collides,
     * titles repeat — and a wrong guess here writes a relation to the wrong person, which is a false
     * fact that reads exactly like a true one.
     *
     * ------------------------------------------------------------------------------------------------
     * THE REVIEWER'S SELECTION REACHES THIS METHOD, VIA THE OPERATIONS THAT NEED THE ENTITY
     *
     * It did not, and that was a hole with a plain reproduction: `entry_ids: []` with
     * `graph_op_keys: []` — a reviewer refusing the entire proposal — still created every declared
     * entity as a real, approved entry. `entity:<n>` was addressable only in the ledger and never on
     * the wire, so the one thing a reviewer could not say was "no".
     *
     * An entity is created when a SELECTED operation names it. That is derived rather than made a
     * second axis of choice, because it matches what the review screen shows: the panel lists relation
     * rows and names the entities each one `depends_on`, so an entity is the consequence of choosing an
     * operation, not a row of its own. Exposing `entity:<n>` keys would let a client select a relation
     * and refuse the person it needs — a combination whose only possible outcome is a skip.
     *
     * `$selected === null` still means "everything", which is what an accept without a selection has
     * always meant. `[]` means nothing, and now genuinely writes nothing.
     *
     * An entity NO operation names is created only under a null selection. Once a reviewer has said
     * which operations they want, an entity nothing points at is a page they did not ask for.
     *
     * @param  array<int, string>|null  $selected  operation keys the reviewer chose; null = all of them
     * @return array<string, KnowledgeEntry>
     */
    public function createDeclaredEntities(
        KnowledgeDraftSession $session,
        KnowledgeBase $base,
        KnowledgeEntryStatus $status,
        ?array $selected = null,
    ): array {
        $needed = $this->entitiesNeededBy($session, $selected);

        $created = [];
        $applied = $this->appliedIndexes($session);

        foreach ($session->graphOps()['entities'] as $index => $entity) {
            $ref = is_string($entity['ref'] ?? null) ? $entity['ref'] : null;
            $key = 'entity:' . $index;

            if ($ref === null) {
                continue;
            }

            if ($this->wasApplied($applied, $key)) {
                $existing = $this->entryAppliedAs($applied, $key, $base);

                if ($existing !== null) {
                    // Created by an EARLIER accept of this session. Handed back so the relations
                    // deferred to this pass can still find it — and not created a second time.
                    $created[$ref] = $existing;
                }

                // If it is gone (trashed or purged since), it is skipped rather than resurrected:
                // re-creating an entry somebody deleted is not what "apply the rest of my proposal"
                // means, and the relations that needed it will report the dependency honestly.
                continue;
            }

            // NOT CHOSEN — no selected operation names this entity, so it is not created. An entity is
            // an endpoint, and creating one nothing points at leaves a stub the reviewer did not ask
            // for. Checked AFTER the ledger so an entity created by an earlier accept is still handed
            // back to this pass's relations.
            if ($needed !== null && !isset($needed[$ref])) {
                continue;
            }

            // THIS HANDLE IS ALREADY A DRAFT — BIND TO IT, DO NOT CREATE A SECOND ENTRY.
            //
            // The composer no longer declares entities; the server synthesises one per CREATE draft
            // and stamps the draft's final slug here (see KnowledgeDraftService::withDeclaredEntities).
            // So by the time this runs, `accept()` has already published the drafts the reviewer chose
            // — it does that BEFORE applying the graph half — and the entry this handle names exists.
            //
            // Creating one anyway is the duplicate this whole design exists to prevent: `mintSlug()`
            // would de-collide it to `paryz-2` without complaint, leaving the base with two entries
            // for one subject and the relations pointing at the one nobody reviewed.
            if (is_string($entity['from_draft_slug'] ?? null)) {
                $published = $this->publishedDraft($base, $entity['from_draft_slug']);

                if ($published !== null) {
                    $created[$ref] = $published;
                    $applied[] = $key . '=' . $published->getKey();
                }

                // NOT FOUND means the reviewer did not accept that draft — they refused the page and
                // this handle has nothing to stand for. Skipped, and every relation naming it reports
                // `dependency_not_accepted`, which is exactly what happened.
                continue;
            }

            $created[$ref] = $this->entries->create($base, new KnowledgeEntryDTO(
                title: (string) ($entity['title'] ?? ''),
                // A new entity's own initial text, if the run wrote any. This is the ONLY thing left in
                // `wiki_updates`, and it needs no review of its own: the entry it belongs to is itself
                // the proposal, and a reviewer accepting one is accepting the other.
                content: $this->initialContentFor($session, $ref),
                metadata: [],
                status: $status,
                staleAt: null,
                slug: is_string($entity['slug'] ?? null) ? $entity['slug'] : null,
                aliases: EntryAliases::normalize($entity['aliases'] ?? []),
                entryType: KnowledgeEntryType::tryFrom((string) ($entity['entry_type'] ?? '')),
            ));

            // The ledger carries the ENTRY ID, not just the fact that the operation ran — see the
            // docblock for why re-deriving it later would be a guess with a false fact at the end of it.
            $applied[] = $key . '=' . $created[$ref]->getKey();
        }

        if ($created !== []) {
            $session->forceFill(['applied_ops' => array_values(array_unique($applied))])->save();
        }

        return $created;
    }

    /** The title this session proposed under a handle, so a skip can name a subject rather than an address. */
    private function declaredTitle(KnowledgeDraftSession $session, string $handle): ?string
    {
        foreach ($session->graphOps()['entities'] as $entity) {
            if (($entity['ref'] ?? null) === $handle) {
                return is_string($entity['title'] ?? null) ? $entity['title'] : null;
            }
        }

        return null;
    }

    /**
     * The LIVE entry a draft became, or null if that draft was never accepted.
     *
     * Read through the ordinary (draft-excluding) scope on purpose: an entry still carrying its
     * `draft_session_id` is a proposal nobody has approved, and binding a relation to one would put an
     * edge on a page the reviewer may yet refuse. Only a published entry answers here.
     */
    private function publishedDraft(KnowledgeBase $base, string $slug): ?KnowledgeEntry
    {
        return KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->where('slug', $slug)
            ->whereNull('targets_entry_id')
            ->first();
    }

    /**
     * The `N<n>` handles the SELECTED operations name, or null when everything applies.
     *
     * Null and `[]` are different answers and the difference is the whole point: null means the client
     * sent no selection at all (accept everything, the long-standing behaviour), while an empty array
     * means the reviewer selected nothing — and nothing is then created.
     *
     * @param  array<int, string>|null  $selected
     * @return array<string, true>|null
     */
    private function entitiesNeededBy(KnowledgeDraftSession $session, ?array $selected): ?array
    {
        if ($selected === null) {
            return null;
        }

        $needed = [];

        foreach ($session->graphOps()['graph_updates'] as $index => $update) {
            if (!in_array('graph:' . $index, $selected, true)) {
                continue;
            }

            foreach (['from', 'to'] as $end) {
                $handle = $update[$end] ?? null;

                if (is_string($handle) && str_starts_with($handle, 'N')) {
                    $needed[$handle] = true;
                }
            }
        }

        return $needed;
    }

    /** Whether this operation key has been applied, whatever payload the ledger recorded with it. */
    private function wasApplied(array $applied, string $key): bool
    {
        foreach ($applied as $entry) {
            if ($entry === $key || str_starts_with($entry, $key . '=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entry an already-applied entity operation created, or null if it is gone.
     *
     * Read through the BASE's own relation, so an id recorded for another base — or one whose entry has
     * since been trashed — simply does not resolve.
     */
    private function entryAppliedAs(array $applied, string $key, KnowledgeBase $base): ?KnowledgeEntry
    {
        foreach ($applied as $entry) {
            if (str_starts_with($entry, $key . '=')) {
                return KnowledgeEntry::query()
                    ->where('knowledge_base_id', $base->getKey())
                    ->whereKey(substr($entry, strlen($key) + 1))
                    ->first();
            }
        }

        return null;
    }

    // ---- helpers ------------------------------------------------------------------------

    /**
     * The entries the session's handles point at RIGHT NOW.
     *
     * @return array<string, KnowledgeEntry>
     */
    private function liveHandles(KnowledgeDraftSession $session, KnowledgeBase $base): array
    {
        $slugs = [];

        foreach ($session->resolutionSet()['entities'] as $entity) {
            if (is_string($entity['handle'] ?? null) && is_string($entity['slug'] ?? null)) {
                $slugs[$entity['handle']] = $entity['slug'];
            }
        }

        if ($slugs === []) {
            return [];
        }

        $rows = KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->whereIn('slug', array_values($slugs))
            ->get()
            ->keyBy('slug');

        $handles = [];

        foreach ($slugs as $handle => $slug) {
            $entry = $rows->get($slug);

            if ($entry !== null) {
                $handles[$handle] = $entry;
            }
        }

        return $handles;
    }

    /** The initial body a run wrote for a NEW entity, or an empty string. */
    private function initialContentFor(KnowledgeDraftSession $session, string $ref): string
    {
        foreach ($session->graphOps()['wiki_updates'] as $update) {
            if (($update['entity'] ?? null) === $ref) {
                return (string) ($update['content'] ?? '');
            }
        }

        return '';
    }

    /** @return array<int, string> */
    private function appliedIndexes(KnowledgeDraftSession $session): array
    {
        $applied = is_array($session->applied_ops) ? $session->applied_ops : [];

        return array_values(array_filter($applied, 'is_string'));
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
