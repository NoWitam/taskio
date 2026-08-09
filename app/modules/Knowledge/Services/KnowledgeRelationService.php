<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\KnowledgeRelationDTO;
use App\Modules\Knowledge\Enums\KnowledgeRelationState;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Exceptions\KnowledgeRelationRefused;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Models\KnowledgeRelationEvent;
use App\Modules\Knowledge\Support\RelationVerdict;
use App\Modules\Knowledge\Support\RelationVocabulary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business logic + persistence for TYPED RELATIONS: the approved statements a base records about pairs
 * of entries.
 *
 * ------------------------------------------------------------------------------------------------
 * FOUR GATES, ALL OF WHICH NEED A QUERY
 *
 * That is why they live here, in the service — and it is now the only place they could live, because
 * there is no relation FormRequest any more: there is no hand-written relation. What a request layer
 * used to judge (shapes, formats, date ordering) is judged in the LAUNDERING, which is what stands
 * between a model's answer and this class.
 *
 *   VOCABULARY  the base may have narrowed the verb list.
 *   PAIR        the type matrix, ADVISORY where an end is untyped. See RelationVocabulary for why an
 *               unknown type must not refuse: every entry written before `entry_type` existed carries
 *               null, and refusing on a guess would reject correct relations across a whole base.
 *   DUPLICATE   an identical ACTIVE relation (same pair, verb, start date). Refused rather than
 *               silently merged: two people asserting the same fact is worth telling the second about,
 *               and merging would hide that the base already knew.
 *   CAP         `relations.max_relations_per_entry`, counted over ACTIVE relations on both ends.
 *
 * ------------------------------------------------------------------------------------------------
 * NOTHING IS EVER DELETED BY A MACHINE
 *
 * `end`, `supersede` and `retract` are the only ways a relation stops being asserted, and all three
 * KEEP the row. A fact that stopped being true is still a fact about the past — "who worked on this in
 * 2025" is exactly what a knowledge base is asked — and a retracted one is the record that a wrong
 * claim was made and rejected, which is what stops it being proposed and accepted again next month.
 *
 * {@see delete()} exists and is reachable from ONE place: the erasure command, destroying relations
 * that name a subject who has asked to be forgotten. It is on no HTTP route and no composer path — a
 * model able to delete statements is a model able to quietly empty a base, and a person able to do it
 * is a person authoring the graph, which is the thing this module no longer offers.
 *
 * ------------------------------------------------------------------------------------------------
 * EVERY WRITE HERE COMES FROM AN ACCEPTED PROPOSAL
 *
 * `create`, `update`, `end` and `supersede` are called by {@see KnowledgeGraphOpsApplier} when a human
 * accepts what the composer proposed. There is no HTTP surface for any of them any more; see
 * {@see \App\Modules\Knowledge\Policies\KnowledgeRelationPolicy} for the second barrier and the reason.
 *
 * ------------------------------------------------------------------------------------------------
 * EVERY MUTATION IS LOGGED, IN THE SAME TRANSACTION AS THE MUTATION
 *
 * Append-only, to this module's own table — see the migration for the four reasons the shared
 * `changelogs` table cannot serve (the first being that it has no tenant mirror at all, so half the
 * product could not log). One transaction, because a change without its audit line is the state an
 * audit trail exists to make impossible.
 */
class KnowledgeRelationService
{
    // ---- reads ----------------------------------------------------------------

    /**
     * Every relation touching this entry, in either direction.
     *
     * Both directions in one list because that is how a reader thinks about it: "what do we know about
     * Anna" does not distinguish the relations she is the subject of from the ones she is the object
     * of. The RESOURCE marks which end this entry is on, so the panel can word each line correctly.
     *
     * @return Collection<int, KnowledgeRelation>
     */
    public function forEntry(KnowledgeEntry $entry, bool $includeHistorical = false): Collection
    {
        return KnowledgeRelation::query()
            ->touching((string) $entry->getKey())
            ->when(!$includeHistorical, fn ($query) => $query->current())
            ->with(['fromEntry:id,title,slug,entry_type', 'toEntry:id,title,slug,entry_type', 'creator'])
            ->orderBy('relation_type')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();
    }

    // ---- writes ---------------------------------------------------------------

    /**
     * Assert a new relation.
     *
     * @throws KnowledgeRelationRefused
     */
    public function create(KnowledgeBase $base, KnowledgeRelationDTO $dto): KnowledgeRelation
    {
        [$from, $to] = $this->endpoints($base, $dto->fromEntryId, $dto->toEntryId, $dto->type);

        $this->assertAllowed($base, $dto->type);
        $this->assertPair($dto->type, $from, $to);
        $this->assertDates($dto->validFrom, $dto->validTo);
        $properties = $this->cleanProperties($dto->type, $dto->properties);
        $this->assertNoDuplicate($base, $from, $to, $dto->type, $dto->validFrom);
        $this->assertUnderCap($from, $to);

        return DB::transaction(function () use ($base, $dto, $from, $to, $properties): KnowledgeRelation {
            $relation = KnowledgeRelation::create([
                'knowledge_base_id' => $base->getKey(),
                'from_entry_id' => $from->getKey(),
                'to_entry_id' => $to->getKey(),
                'relation_type' => $dto->type->value,
                'description' => $dto->description,
                'properties' => $properties,
                'valid_from' => $dto->validFrom,
                'valid_to' => $dto->validTo,
                // A RELATION THAT ALREADY ENDED IS BORN `ended`.
                //
                // This stamped ACTIVE unconditionally, which was harmless while relations described
                // standing states and nobody backdated them. It stopped being harmless the moment the
                // composer started recording EPISODES: "visited Paris, 12-15 July" was written as an
                // active fact, so the graph asserted — in the present tense, forever — that she is in
                // Paris, and in Bangkok, and in Tokyo.
                //
                // `scopeCurrent()` filters on `state`, not on dates, so the state is where this has to
                // be said. The pleasant side effect is that a closed episode stops consuming the
                // per-entry relation cap, which a subject-shaped graph would otherwise exhaust on
                // history alone.
                'state' => self::stateFor($dto->validTo)->value,
                'origin' => $dto->origin->value,
                'draft_session_id' => $dto->draftSessionId,
            ]);

            // NO PROMOTION BRANCH. Promoting a machine suggestion into a hard relation was a person's
            // action, through `POST /relations`, and that endpoint no longer exists — so every relation
            // written here comes from an accepted proposal and is logged as an ordinary `create`.
            //
            // The `OP_PROMOTE` constant is gone too: it was kept on the assumption that historical rows
            // carried it, and they do not (see {@see KnowledgeRelationEvent}).
            $this->record(
                $relation,
                KnowledgeRelationEvent::OP_CREATE,
                null,
                $relation->auditSnapshot(),
                $dto->draftSessionId,
            );

            return $relation;
        });
    }

    /**
     * Edit the STATEMENT — its description, properties and validity dates.
     *
     * The two ENTRIES and the VERB are deliberately not editable. Changing either would turn this row
     * into an assertion about something else while keeping its id, its history and its approval — so
     * the audit trail would say a fact was "updated" when in truth a different fact replaced it. The
     * honest path for that is `supersede`, which is why it exists.
     *
     * @param  array<string, mixed>|null  $properties
     *
     * @throws KnowledgeRelationRefused
     */
    public function update(
        KnowledgeRelation $relation,
        ?string $description,
        ?array $properties,
        ?Carbon $validFrom,
        ?Carbon $validTo,
    ): KnowledgeRelation {
        $before = $relation->auditSnapshot();

        $this->assertDates($validFrom, $validTo);

        $clean = $properties === null
            ? ($relation->properties ?? [])
            : $this->cleanProperties($relation->relation_type, $properties);

        return DB::transaction(function () use ($relation, $description, $clean, $validFrom, $validTo, $before): KnowledgeRelation {
            $relation->forceFill([
                'description' => $description,
                'properties' => $clean,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
            ])->save();

            $this->record($relation, KnowledgeRelationEvent::OP_UPDATE, $before, $relation->auditSnapshot());

            return $relation->refresh();
        });
    }

    /**
     * This stopped being true. The relation is KEPT, marked `ended`, with the date it stopped.
     *
     * `valid_to` defaults to today rather than being required: "she left" is a complete thought, and
     * forcing a date on somebody who does not have one is how you get a wrong date instead of a
     * missing one.
     */
    public function end(KnowledgeRelation $relation, ?Carbon $validTo = null): KnowledgeRelation
    {
        $before = $relation->auditSnapshot();

        return DB::transaction(function () use ($relation, $validTo, $before): KnowledgeRelation {
            $relation->forceFill([
                'state' => KnowledgeRelationState::ENDED->value,
                'valid_to' => $validTo ?? now()->toDateString(),
            ])->save();

            $this->record($relation, KnowledgeRelationEvent::OP_END, $before, $relation->auditSnapshot());

            return $relation->refresh();
        });
    }

    /**
     * This was replaced by that. The old relation ends AND points at its successor, so a reader
     * following the history reaches the current fact instead of a dead end.
     */
    public function supersede(KnowledgeRelation $relation, KnowledgeRelation $successor): KnowledgeRelation
    {
        $before = $relation->auditSnapshot();

        return DB::transaction(function () use ($relation, $successor, $before): KnowledgeRelation {
            $relation->forceFill([
                'state' => KnowledgeRelationState::ENDED->value,
                'valid_to' => $relation->valid_to ?? now()->toDateString(),
                'superseded_by_id' => $successor->getKey(),
            ])->save();

            $this->record($relation, KnowledgeRelationEvent::OP_SUPERSEDE, $before, $relation->auditSnapshot());

            return $relation->refresh();
        });
    }

    /**
     * This was NEVER true. Kept rather than deleted so the base remembers that the claim was made and
     * rejected — which is what stops the same wrong relation being re-proposed and re-accepted.
     */
    public function retract(KnowledgeRelation $relation): KnowledgeRelation
    {
        $before = $relation->auditSnapshot();

        return DB::transaction(function () use ($relation, $before): KnowledgeRelation {
            $relation->forceFill(['state' => KnowledgeRelationState::RETRACTED->value])->save();

            $this->record($relation, KnowledgeRelationEvent::OP_RETRACT, $before, $relation->auditSnapshot());

            return $relation->refresh();
        });
    }

    /**
     * Destroy the row. A PERSON'S affordance only — see the class docblock.
     *
     * The audit line is written FIRST and survives, because it has no foreign key to the relation. That
     * is the whole reason the log has none: a cascade would delete the record of the deletion.
     */
    public function delete(KnowledgeRelation $relation): void
    {
        DB::transaction(function () use ($relation): void {
            $this->record($relation, KnowledgeRelationEvent::OP_DELETE, $relation->auditSnapshot(), null);

            $relation->delete();
        });
    }

    // ---- gates ----------------------------------------------------------------

    /**
     * Both ends, resolved inside THIS base and canonically ordered for a symmetric verb.
     *
     * Resolved through the base's own relation rather than by plain id lookup: an entry from another
     * base (or another workspace) is simply not found, which surfaces as a 404 rather than as a
     * relation quietly joining two bases together.
     *
     * @return array{0: KnowledgeEntry, 1: KnowledgeEntry}
     */
    private function endpoints(KnowledgeBase $base, string $fromId, string $toId, KnowledgeRelationType $type): array
    {
        [$fromId, $toId] = KnowledgeRelation::canonicalPair($type, $fromId, $toId);

        $entries = $base->entries()->whereKey([$fromId, $toId])->get()->keyBy('id');

        $from = $entries->get($fromId);
        $to = $entries->get($toId);

        abort_if($from === null || $to === null, 404);

        return [$from, $to];
    }

    /**
     * The state a relation is BORN in, from its own dates.
     *
     * Only a `valid_to` in the PAST decides anything. A future one is a planned end — the fact is true
     * today and saying otherwise would hide it from every present-tense question — and a missing one
     * means nobody said it ended, which is not the same as knowing it has not.
     */
    private static function stateFor(?Carbon $validTo): KnowledgeRelationState
    {
        return $validTo !== null && $validTo->isBefore(Carbon::now()->startOfDay())
            ? KnowledgeRelationState::ENDED
            : KnowledgeRelationState::ACTIVE;
    }

    /**
     * A relation cannot stop being true before it starts.
     *
     * ENFORCED HERE BECAUSE THE AI PATH HAS NO FORMREQUEST. `after_or_equal:valid_from` in the store
     * and update requests covers a human, but the composer's operations arrive through
     * {@see KnowledgeGraphOpsApplier}, which builds its DTO from a laundered model answer — so an
     * invented interval like "from 2026-07, to 2024-01" was refused for a person and written for a
     * model. The laundering cannot catch it either: each date is individually well-formed, and only
     * the pair is wrong.
     *
     * An `end` is deliberately NOT checked against this. Ending a relation records when a fact stopped
     * being true, and a date before its `valid_from` there means the start was wrong rather than the
     * ending — refusing would leave a statement nobody can retire. That is a correction, and it belongs
     * to `update`, which does check.
     *
     * @throws KnowledgeRelationRefused
     */
    private function assertDates(?Carbon $validFrom, ?Carbon $validTo): void
    {
        if ($validFrom === null || $validTo === null) {
            return;
        }

        if ($validTo->lessThan($validFrom)) {
            throw KnowledgeRelationRefused::dates($validFrom->toDateString(), $validTo->toDateString());
        }
    }

    /** @throws KnowledgeRelationRefused */
    private function assertAllowed(KnowledgeBase $base, KnowledgeRelationType $type): void
    {
        if (!RelationVocabulary::allows($base, $type)) {
            throw KnowledgeRelationRefused::vocabulary($type->value);
        }
    }

    /** @throws KnowledgeRelationRefused */
    private function assertPair(KnowledgeRelationType $type, KnowledgeEntry $from, KnowledgeEntry $to): void
    {
        // Only a REFUSED verdict stops a write. UNKNOWN_TYPES is the normal state of an untyped base
        // and is carried to the reviewer as a warning by the composer stage instead.
        if (RelationVocabulary::check($type, $from, $to) === RelationVerdict::REFUSED) {
            throw KnowledgeRelationRefused::pair(
                $type->value,
                RelationVocabulary::typeOf($from)?->value,
                RelationVocabulary::typeOf($to)?->value,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     *
     * @throws KnowledgeRelationRefused
     */
    private function cleanProperties(KnowledgeRelationType $type, array $properties): array
    {
        [$clean, $offending] = RelationVocabulary::checkProperties($type, $properties);

        if ($offending !== null) {
            throw KnowledgeRelationRefused::property($offending);
        }

        return $clean;
    }

    /**
     * Refuse a second relation of the same verb, between the same pair, starting on the same day.
     *
     * The date is part of the key, and that is the point of not making this a unique index: "met on
     * 2026-08-15" and "met on 2026-09-12" are two facts about the same pair, not a duplicate. Two
     * ACTIVE relations with NO start date are treated as the same claim — there is nothing to tell them
     * apart by, so the second one adds nothing.
     *
     * @throws KnowledgeRelationRefused
     */
    private function assertNoDuplicate(
        KnowledgeBase $base,
        KnowledgeEntry $from,
        KnowledgeEntry $to,
        KnowledgeRelationType $type,
        ?Carbon $validFrom,
    ): void {
        // A DATED statement is compared in ANY state; an UNDATED one only against the active ones.
        //
        // The asymmetry is the whole rule, and both halves are needed.
        //
        // WITH a date, the date IS the identity. "Visited Paris on 12 July" is one fact, and since a
        // closed episode is now born `ended` (see stateFor()), asking `current()` would mean it stopped
        // being a duplicate of itself the instant it was written — so re-running the same material
        // would stack a fresh copy of every episode on every pass.
        //
        // WITHOUT a date there is nothing to tell two assertions apart except whether the first is
        // still standing, and "she left Acme, then rejoined" has to remain expressible. An ended
        // undated relation is a fact nobody is asserting any more, so asserting it again is new.
        $existing = KnowledgeRelation::query()
            ->where('knowledge_base_id', $base->getKey())
            ->where('from_entry_id', $from->getKey())
            ->where('to_entry_id', $to->getKey())
            ->where('relation_type', $type->value)
            ->when(
                $validFrom === null,
                fn ($query) => $query->whereNull('valid_from')->current(),
                fn ($query) => $query->whereDate('valid_from', $validFrom),
            )
            ->first();

        if ($existing !== null) {
            throw KnowledgeRelationRefused::duplicate((string) $existing->getKey());
        }
    }

    /**
     * Refuse when either end is already at its relation budget.
     *
     * ACTIVE relations only. History does not compete for the budget — an entry that has accumulated
     * forty ended relations over five years is a well-documented entry, not an over-connected one, and
     * counting them would eventually make it impossible to record anything new about it.
     *
     * @throws KnowledgeRelationRefused
     */
    private function assertUnderCap(KnowledgeEntry $from, KnowledgeEntry $to): void
    {
        $max = max(1, (int) config('knowledge.relations.max_relations_per_entry'));

        foreach ([$from, $to] as $entry) {
            $count = KnowledgeRelation::query()
                ->touching((string) $entry->getKey())
                ->current()
                ->count();

            if ($count >= $max) {
                throw KnowledgeRelationRefused::cap((string) $entry->getKey(), $max);
            }
        }
    }

    // ---- the audit trail ------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(
        KnowledgeRelation $relation,
        string $op,
        ?array $before,
        ?array $after,
        ?string $sessionId = null,
    ): void {
        KnowledgeRelationEvent::create([
            'relation_id' => $relation->getKey(),
            'knowledge_base_id' => $relation->knowledge_base_id,
            'op' => $op,
            'before' => $before,
            'after' => $after,
            'draft_session_id' => $sessionId ?? $relation->draft_session_id,
            'created_at' => now(),
        ]);
    }
}
