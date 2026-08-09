<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\SubjectCharterMatch;
use App\Modules\Knowledge\DTOs\SubjectEntryMatch;
use App\Modules\Knowledge\DTOs\SubjectGhostLinkMatch;
use App\Modules\Knowledge\DTOs\SubjectPurgeReport;
use App\Modules\Knowledge\DTOs\SubjectRelationEventMatch;
use App\Modules\Knowledge\DTOs\SubjectRelationMatch;
use App\Modules\Knowledge\DTOs\SubjectRevisionMatch;
use App\Modules\Knowledge\DTOs\SubjectSessionMatch;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Models\KnowledgeRelationEvent;
use App\Modules\Knowledge\Support\EntryAliases;
use App\Modules\Knowledge\Support\SubjectPhrases;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finds and erases everything in the KNOWLEDGE MODULE that names a given subject.
 *
 * SCOPE. This is the knowledge module's half of an erasure request and nothing more; the boundary is
 * stated on {@see \App\Modules\Knowledge\Console\PurgeKnowledgeSubjectCommand}.
 *
 * The design has one non-obvious centre: REVISIONS ARE SCANNED SEPARATELY. Clearing a person's name
 * out of an entry's text through the ordinary editor does not erase it — every revision taken before
 * that edit still holds the sentence verbatim, and the API has no way to reach those rows because
 * append-only history is exactly what makes an audit trail worth having. So the scan asks two
 * different questions of two different tables, and answers them with two different remedies:
 *
 *   CURRENT text names the subject  -> the whole entry goes (with its history, its vectors, its edges)
 *   only HISTORY names the subject  -> that one revision goes, and the entry is left standing
 *
 * A single query over both would have to pick one remedy for both cases, and either choice is wrong:
 * purging an entry because a three-year-old draft mentioned someone destroys knowledge nobody asked
 * to lose, while deleting only the current revision would leave the live page still saying the name.
 *
 * SCAN AND APPLY ARE THE SAME REPORT. {@see apply()} acts on the ids the scan already produced rather
 * than re-querying, so the operator deletes precisely what they reviewed. That is what makes the
 * default dry run meaningful instead of merely advisory.
 *
 * TRASHED ROWS COUNT, AND SO DO DRAFTS. The scan reads through BOTH the soft-delete filter and the
 * draft-invisibility scope. An entry in the trash still holds the person's data, and so does an
 * unaccepted AI draft — "nobody can see it" is not the same claim as "it is not there", and an erasure
 * request is about the second one.
 *
 * That symmetry had to be repaired rather than assumed: the scan lifted only the soft-delete filter, so
 * a draft ENTRY was invisible while its own REVISIONS were not (revisions carry no draft column). The
 * result was the worst of both — the history was deleted and the live draft text, name and all, stayed.
 *
 * DRAFTING SESSIONS ARE THEIR OWN CATEGORY. A session stores the raw text a person PASTED, which no
 * entry references and no index reaches; erasing every entry in the workspace would leave it untouched.
 * A matched session is abandoned whole ({@see SubjectSessionMatch} explains why that is the only honest
 * granularity for an opaque blob).
 */
class KnowledgeSubjectPurgeService
{
    public function __construct(
        private KnowledgeEntryService $entries,
        private KnowledgeDraftSessionService $sessions,
    ) {}

    /** Everything the phrases reach, in the order the report renders it. Reads only. */
    public function scan(string $workspaceId, SubjectPhrases $phrases, ?string $baseId = null): SubjectPurgeReport
    {
        $entries = $this->matchedEntries($phrases, $baseId);
        $relations = $this->matchedRelations($phrases, $baseId);

        return new SubjectPurgeReport(
            workspaceId: $workspaceId,
            baseId: $baseId,
            phrases: $phrases->all(),
            entries: $entries,
            revisions: $this->matchedRevisions($phrases, $baseId, array_map(
                static fn (SubjectEntryMatch $match) => $match->id,
                $entries,
            )),
            ghostLinks: $this->matchedGhostLinks($phrases, $baseId),
            sessions: $this->matchedSessions($phrases, $baseId),
            relations: $relations,
            relationEvents: $this->matchedRelationEvents($phrases, $baseId, $relations),
            // REPORT-ONLY. Deliberately has no counterpart in apply() — see matchedCharters().
            charters: $this->matchedCharters($phrases, $baseId),
        );
    }

    /**
     * Destroy exactly what $report lists, in ONE transaction on the workspace's own connection.
     *
     * All-or-nothing is not tidiness here: a half-applied erasure is an erasure that was certified as
     * done and was not, and there is no way to tell from the outside which half ran. The connection is
     * taken from the model rather than assumed, because an own-database workspace's rows live on the
     * tenant connection and a transaction opened on the default one would guard nothing at all.
     *
     * ORDER MATTERS in one place: ghosts are cut BEFORE the entry purges, because a listed ghost drawn
     * BY an entry that is about to be purged would otherwise already be gone, and the report would
     * claim a deletion the log could not account for.
     */
    public function apply(SubjectPurgeReport $report, SubjectPhrases $phrases): SubjectPurgeReport
    {
        $connection = (new KnowledgeEntry)->getConnectionName();

        DB::connection($connection)->transaction(function () use ($report, $phrases) {
            $this->deleteGhostLinks($report);
            // BEFORE the entries, for the same reason as the ghosts: a listed relation whose endpoint
            // is about to be purged would already be gone via the cascade, and the report would claim
            // a deletion nothing could account for.
            $this->deleteRelations($report);
            // AFTER the relations, whose own trail went with them in the cascade above. These are the
            // lines that outlived their subject's removal from a relation that still exists.
            $this->deleteLoneRelationEvents($report);
            $this->deleteLoneRevisions($report);
            $this->purgeEntries($report, $phrases);
            // LAST, because abandoning a session destroys its drafts too: any draft that was ALSO
            // matched on its own has already been purged above, and the abandon path simply finds one
            // fewer row. The other order would leave `purgeEntries` looking for rows a session had
            // already taken — harmless, but it would make the report's entry list read as unfulfilled.
            $this->purgeSessions($report);
        });

        $this->record($report);

        return $report->asApplied();
    }

    // ---- scanning -------------------------------------------------------------

    /** @return list<SubjectEntryMatch> */
    private function matchedEntries(SubjectPhrases $phrases, ?string $baseId): array
    {
        $query = KnowledgeEntry::withTrashed()
            // ...and through the DRAFT scope. An unaccepted draft holds the person's name exactly as a
            // published entry does; leaving it invisible here was the asymmetry that deleted a draft's
            // history while its current text survived.
            ->withDrafts()
            // `chunks` is aliased away from the default `chunks_count`: the entry already HAS a column
            // by that name (the indexer's denormalised fan-out), and a report about what is being
            // destroyed must count the rows that actually exist, not the bookkeeping figure.
            ->withCount(['revisions', 'chunks as chunk_rows_count', 'incomingLinks'])
            ->orderBy('knowledge_base_id')
            ->orderBy('position')
            ->orderBy('id');

        $this->restrictToBase($query, $baseId);

        // ALIASES AND SLUG ARE SCANNED TOO — and the Wiki-Graph stage is what made that urgent.
        //
        // `aliases` is BY DEFINITION a list of other names for the same thing, which on a `person`
        // entry means a maiden name, a nickname, a form with initials. An erasure request that matched
        // only the title reported success while the alias stayed in the database — and the mention
        // scanner went on drawing edges from it, so the erased name kept producing graph structure.
        //
        // `slug` is the same fact in another encoding, and it does NOT follow a rename: an entry
        // retitled "A. K." keeps the slug `anna-kowalska` for good, because a slug is an address and
        // moving it would break every link that points at it.
        $phrases->whereMatchesText($query, ['title', 'content'], ['metadata', 'aliases'], ['slug']);

        return $query->get()->map(fn (KnowledgeEntry $entry) => new SubjectEntryMatch(
            id: (string) $entry->getKey(),
            baseId: (string) $entry->knowledge_base_id,
            slug: (string) $entry->slug,
            title: (string) $entry->title,
            trashed: $entry->trashed(),
            matchedIn: $this->attributeEntry($phrases, $entry),
            revisions: (int) $entry->revisions_count,
            chunks: (int) $entry->chunk_rows_count,
            incomingLinks: (int) $entry->incoming_links_count,
            isDraft: $entry->isDraft(),
        ))->all();
    }

    /**
     * Revisions that name the subject and whose entry does NOT — the history-only category.
     *
     * $purgedEntryIds is excluded rather than intersected: those entries lose their whole history in
     * the cascade anyway, and listing their revisions here as well would double-count every one of
     * them in the confirmation number the operator has to retype.
     *
     * @param  list<string>  $purgedEntryIds
     * @return list<SubjectRevisionMatch>
     */
    private function matchedRevisions(SubjectPhrases $phrases, ?string $baseId, array $purgedEntryIds): array
    {
        $query = KnowledgeEntryRevision::query()
            // The entry is soft-delete aware on both sides: a trashed entry's history is still history.
            ->with(['entry' => fn ($relation) => $relation->withTrashed()])
            ->orderBy('knowledge_entry_id')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($baseId !== null) {
            $query->whereIn(
                'knowledge_entry_id',
                // withDrafts() so a base-scoped scan still reaches a draft's own history — without it
                // the `--base` flag would quietly narrow the erasure differently from a workspace one.
                KnowledgeEntry::withTrashed()->withDrafts()->where('knowledge_base_id', $baseId)->select('id'),
            );
        }

        if ($purgedEntryIds !== []) {
            $query->whereNotIn('knowledge_entry_id', $purgedEntryIds);
        }

        // `change_note` is a person's own sentence about an edit ("usunięto dane Anny Kowalskiej"), so
        // it names people in exactly the situation this command is invoked for — and, being the
        // operator's own note, it is the one field most likely to spell the name out in full.
        $phrases->whereMatchesText($query, ['title', 'content', 'change_note'], ['metadata']);

        return $query->get()->map(fn (KnowledgeEntryRevision $revision) => new SubjectRevisionMatch(
            id: (string) $revision->getKey(),
            entryId: (string) $revision->knowledge_entry_id,
            entrySlug: (string) ($revision->entry?->slug ?? ''),
            createdAt: $revision->created_at?->toIso8601String(),
            matchedIn: $this->attribute($phrases, (string) $revision->title, (string) $revision->content, $revision->metadata),
        ))->all();
    }

    /** @return list<SubjectGhostLinkMatch> */
    private function matchedGhostLinks(SubjectPhrases $phrases, ?string $baseId): array
    {
        $query = KnowledgeLink::query()
            ->ghost()
            ->orderBy('target_slug')
            ->orderBy('id');

        if ($baseId !== null) {
            $query->where('knowledge_base_id', $baseId);
        }

        $phrases->whereMatchesSlug($query, 'target_slug');

        return $query->get()->map(fn (KnowledgeLink $link) => new SubjectGhostLinkMatch(
            id: (string) $link->getKey(),
            baseId: (string) $link->knowledge_base_id,
            fromEntryId: (string) $link->from_entry_id,
            targetSlug: (string) $link->target_slug,
            source: (string) ($link->source?->value ?? ''),
        ))->all();
    }

    /**
     * Drafting sessions whose RAW MATERIAL or INSTRUCTION HISTORY names the subject.
     *
     * The gap this closes: `source_text` is a blob a person pasted. Nothing references it, nothing
     * indexes it, and no amount of purging entries reaches it — so an erasure request could be
     * certified complete while the name sat there. `prompt_history` is scanned for the same reason:
     * "rewrite the part about Jan Kowalski" is the person's name, typed by a user, stored verbatim.
     *
     * @return list<SubjectSessionMatch>
     */
    private function matchedSessions(SubjectPhrases $phrases, ?string $baseId): array
    {
        $query = KnowledgeDraftSession::query()
            ->withCount('drafts')
            ->orderBy('knowledge_base_id')
            ->orderBy('created_at')
            ->orderBy('id');

        $this->restrictToBase($query, $baseId);
        // FIVE jsonb surfaces, not one, and each is a place a person's name genuinely lands:
        //   prompt_history  "rewrite the part about Jan Kowalski" — typed by a user, stored verbatim.
        //   retrieval_set   the FROZEN TEXT of the entries the composer was shown. Since the amendment
        //                   fix this holds up to `amend_full_chars` of an entry's body per candidate,
        //                   so purging that entry now leaves a full copy of it sitting on the session.
        //                   Scanning it was overdue before that change and became urgent with it.
        //   resolution_set  the entries a run resolved its mentions against.
        //   graph_ops       proposed relations, which carry free-text descriptions about people.
        //   notes           the RUN NOTES, which carry entry TITLES and slugs — and for a `person`
        //                   entity a title IS somebody's name. A note reading
        //                   `{"code":"amend_append_only","slug":"anna-kowalska"}` would have survived an
        //                   erasure that reported itself complete, which is the one outcome this command
        //                   exists to make impossible.
        //   relations_cache the proposed-relations panel, which carries entry TITLES and SLUGS — the
        //                   same reasoning as `notes`, one column across.
        //
        // `seed_title` / `seed_slug` are matched as text and slug: "write me the entry this red link
        // points at" seeds a session with the missing entry's NAME, and for a person that is the whole
        // subject of the request sitting in a plain string column.
        $phrases->whereMatchesText(
            $query,
            ['source_text', 'seed_title'],
            ['prompt_history', 'retrieval_set', 'resolution_set', 'graph_ops', 'notes', 'relations_cache'],
            ['seed_slug'],
        );

        return $query->get()->map(fn (KnowledgeDraftSession $session) => new SubjectSessionMatch(
            id: (string) $session->getKey(),
            baseId: (string) $session->knowledge_base_id,
            status: (string) ($session->status?->value ?? ''),
            matchedIn: $this->attributeSession($phrases, $session),
            drafts: (int) $session->drafts_count,
        ))->all();
    }

    /**
     * TYPED RELATIONS whose OWN text names the subject.
     *
     * The category is easy to think unnecessary — purging the two entries takes their relations with
     * them through the cascade — and it is necessary for one case that the cascade cannot reach:
     * `description` and `properties` are free text written ON THE EDGE. "Wprowadzona przez Annę
     * Kowalską" on a relation between two entries that never mention her survives every other remedy
     * in this file, and an erasure would report itself complete with the name still in the database.
     *
     * @return list<SubjectRelationMatch>
     */
    private function matchedRelations(SubjectPhrases $phrases, ?string $baseId): array
    {
        $query = KnowledgeRelation::query()
            // EVERY state, not just active: an ended or retracted relation holds the same sentence, and
            // "nobody is shown it" is not the same claim as "it is not there".
            ->orderBy('knowledge_base_id')
            ->orderBy('created_at')
            ->orderBy('id');

        $this->restrictToBase($query, $baseId);
        $phrases->whereMatchesText($query, ['description'], ['properties']);

        return $query->get()->map(fn (KnowledgeRelation $relation) => new SubjectRelationMatch(
            id: (string) $relation->getKey(),
            baseId: (string) $relation->knowledge_base_id,
            fromEntryId: (string) $relation->from_entry_id,
            toEntryId: (string) $relation->to_entry_id,
            relationType: (string) ($relation->relation_type?->value ?? ''),
            matchedIn: $this->attributeRelation($phrases, $relation),
        ))->all();
    }

    /**
     * BASE CHARTERS that name the subject — FOUND AND REPORTED, NEVER DELETED.
     *
     * The only read in this class with no counterpart in {@see apply()}, and that asymmetry is the
     * whole design. Do not "finish" it by adding one.
     *
     * IT MUST BE FOUND, because a charter is the most ACTIVE copy of the text there is:
     * {@see \App\Modules\Knowledge\Support\KnowledgeCompiler} prepends it to the compiled knowledge
     * block, so a person named in one is sent to the model on every single generation against that
     * base. Leaving it out of the scan meant an operator could read "0 records", certify the erasure,
     * and have the name keep going out to the provider afterwards.
     *
     * IT MUST NOT BE DELETED, because the charter is the BASE's editorial policy and belongs to the
     * base rather than to the person it mentions. Deleting the base would destroy every entry in it;
     * blanking the charter would silently change how every future generation behaves. Removing one
     * sentence and leaving a coherent paragraph is a human's job.
     *
     * @return list<SubjectCharterMatch>
     */
    private function matchedCharters(SubjectPhrases $phrases, ?string $baseId): array
    {
        $query = KnowledgeBase::query()->orderBy('name')->orderBy('id');

        if ($baseId !== null) {
            $query->whereKey($baseId);
        }

        $phrases->whereMatchesText($query, ['charter']);

        return $query->get()->map(fn (KnowledgeBase $base) => new SubjectCharterMatch(
            baseId: (string) $base->getKey(),
            baseName: (string) $base->name,
        ))->all();
    }

    /**
     * AUDIT LINES whose snapshots name the subject while their relation no longer does.
     *
     * The relation-versus-event asymmetry, exactly parallel to entry-versus-revision, and reached by
     * the same responsible act: somebody edits a description to take a name out, and the `before`
     * snapshot of that very edit preserves it word for word. `auditSnapshot()` copies `description`
     * and `properties` into every line, so the trail accumulates a copy per change.
     *
     * $purgedRelations is EXCLUDED rather than intersected — those relations lose their whole trail in
     * the cascade below, and listing their events here as well would double-count each of them in the
     * confirmation number the operator has to retype.
     *
     * There is no `knowledge_base_id` on this table, so a base restriction is applied through the
     * relations it names. A line whose relation has already been deleted cannot be attributed to a base
     * at all — it is reached only by an unrestricted (whole-workspace) run, which is the honest bound:
     * scoping to a base is an optimisation for the operator, not a promise about what exists.
     *
     * @param  list<SubjectRelationMatch>  $purgedRelations
     * @return list<SubjectRelationEventMatch>
     */
    private function matchedRelationEvents(SubjectPhrases $phrases, ?string $baseId, array $purgedRelations): array
    {
        $query = KnowledgeRelationEvent::query()
            ->orderBy('relation_id')
            ->orderBy('created_at')
            ->orderBy('id');

        $phrases->whereMatchesText($query, [], ['before', 'after']);

        $excluded = array_map(static fn (SubjectRelationMatch $match) => $match->id, $purgedRelations);

        if ($excluded !== []) {
            $query->whereNotIn('relation_id', $excluded);
        }

        if ($baseId !== null) {
            $query->whereIn(
                'relation_id',
                KnowledgeRelation::query()->where('knowledge_base_id', $baseId)->select('id'),
            );
        }

        return $query->get()->map(fn (KnowledgeRelationEvent $event) => new SubjectRelationEventMatch(
            id: (string) $event->getKey(),
            relationId: (string) $event->relation_id,
            operation: (string) $event->op,
            createdAt: $event->created_at?->toISOString(),
            matchedIn: $this->attributeSnapshots($phrases, $event),
        ))->all();
    }

    /**
     * WHICH snapshot carried the phrase. Presentation only.
     *
     * @return list<string>
     */
    private function attributeSnapshots(SubjectPhrases $phrases, KnowledgeRelationEvent $event): array
    {
        $fields = [];

        foreach (['before' => $event->before, 'after' => $event->after] as $field => $snapshot) {
            $json = is_array($snapshot) && $snapshot !== []
                ? json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            if (is_string($json) && $phrases->matches($json)) {
                $fields[] = $field;
            }
        }

        return $fields === [] ? ['unattributed'] : $fields;
    }

    /**
     * WHICH of the two stored user texts carried the phrase. Presentation only.
     *
     * @return list<string>
     */
    private function attributeSession(SubjectPhrases $phrases, KnowledgeDraftSession $session): array
    {
        $fields = [];

        if ($phrases->matches((string) $session->source_text)) {
            $fields[] = 'source_text';
        }

        if ($phrases->matches((string) $session->seed_title)) {
            $fields[] = 'seed_title';
        }

        if ($phrases->matchesSlug((string) $session->seed_slug)) {
            $fields[] = 'seed_slug';
        }

        $surfaces = [
            'prompt_history' => $session->instructions(),
            'retrieval_set' => $session->retrieval_set,
            'resolution_set' => $session->resolution_set,
            'graph_ops' => $session->graph_ops,
            'notes' => $session->notes,
            'relations_cache' => $session->relations_cache,
        ];

        foreach ($surfaces as $field => $value) {
            $json = is_array($value) && $value !== []
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            if (is_string($json) && $phrases->matches($json)) {
                $fields[] = $field;
            }
        }

        return $fields === [] ? ['unattributed'] : $fields;
    }

    /** WHICH of the relation's two free-text fields carried the phrase. Presentation only. */
    private function attributeRelation(SubjectPhrases $phrases, KnowledgeRelation $relation): array
    {
        $fields = [];

        if ($phrases->matches((string) $relation->description)) {
            $fields[] = 'description';
        }

        $properties = is_array($relation->properties) && $relation->properties !== []
            ? json_encode($relation->properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        if (is_string($properties) && $phrases->matches($properties)) {
            $fields[] = 'properties';
        }

        return $fields === [] ? ['unattributed'] : $fields;
    }

    private function restrictToBase(Builder $query, ?string $baseId): void
    {
        if ($baseId !== null) {
            $query->where('knowledge_base_id', $baseId);
        }
    }

    /**
     * WHICH of the three authored fields carried the phrase. Presentation only — the SQL predicate
     * already decided that the row matched (see {@see SubjectPhrases::matches()}).
     *
     * Metadata is compared as its JSON serialisation with unicode left intact, because Postgres holds
     * jsonb as UTF-8 text and PHP's default `\uXXXX` escaping would hide every accented phrase.
     *
     * @return list<string>
     */
    private function attribute(SubjectPhrases $phrases, string $title, string $content, mixed $metadata): array
    {
        $fields = [];

        if ($phrases->matches($title)) {
            $fields[] = 'title';
        }

        if ($phrases->matches($content)) {
            $fields[] = 'content';
        }

        $json = is_array($metadata) && $metadata !== []
            ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        if (is_string($json) && $phrases->matches($json)) {
            $fields[] = 'metadata';
        }

        // The row matched in SQL, so this can only be reached if the two engines disagreed about case
        // folding. Say so rather than showing an empty list that reads like a bug.
        return $fields === [] ? ['unattributed'] : $fields;
    }

    /**
     * The same, plus the two name-bearing surfaces only an ENTRY has.
     *
     * Separate from {@see attribute()} because revisions share that method and have neither column: a
     * revision is a snapshot of text, while the alias list and the slug belong to the living entry.
     *
     * @return list<string>
     */
    private function attributeEntry(SubjectPhrases $phrases, KnowledgeEntry $entry): array
    {
        $fields = $this->attribute($phrases, (string) $entry->title, (string) $entry->content, $entry->metadata);
        $fields = $fields === ['unattributed'] ? [] : $fields;

        foreach (EntryAliases::normalize($entry->aliases) as $alias) {
            if ($phrases->matches($alias)) {
                $fields[] = 'aliases';

                break;
            }
        }

        // Matched against the SLUGIFIED phrase as well, for the same reason the SQL does: "Anna
        // Kowalska" is `anna-kowalska` here, and a report that could not name the field would send an
        // operator hunting for a name that is plainly not in the title or the body.
        if ($phrases->matchesSlug((string) $entry->slug)) {
            $fields[] = 'slug';
        }

        return $fields === [] ? ['unattributed'] : $fields;
    }

    // ---- applying -------------------------------------------------------------

    private function deleteGhostLinks(SubjectPurgeReport $report): void
    {
        if ($report->ghostLinks === []) {
            return;
        }

        KnowledgeLink::query()
            ->whereKey(array_map(static fn (SubjectGhostLinkMatch $match) => $match->id, $report->ghostLinks))
            ->delete();
    }

    /**
     * DELETE the matched relations outright — not `end`, not `retract`.
     *
     * Those two exist so a base remembers what was once asserted, which is the right default
     * everywhere except here: remembering is precisely what an erasure request is asking to undo. The
     * relation's audit trail goes with it for the same reason — a `before` snapshot holds the very
     * description being erased, so leaving the log would leave a copy.
     */
    private function deleteRelations(SubjectPurgeReport $report): void
    {
        if ($report->relations === []) {
            return;
        }

        $ids = array_map(static fn (SubjectRelationMatch $match) => $match->id, $report->relations);

        KnowledgeRelationEvent::query()->whereIn('relation_id', $ids)->delete();
        KnowledgeRelation::query()->whereKey($ids)->delete();
    }

    /**
     * Delete the LONE audit lines — the ones whose relation survives.
     *
     * The counterpart of {@see deleteLoneRevisions()}, and needed for the same reason: the relation was
     * cleaned up by an ordinary edit, and the trail kept a verbatim copy of what was removed. The
     * relation and the rest of its history are left alone; only the lines carrying the name go.
     *
     * A gap in an append-only trail is a real cost and it is the accepted one — the same trade the
     * revision category already makes. An erasure request is the one occasion on which the audit trail
     * yields, and it yields at the smallest granularity that answers the request.
     */
    private function deleteLoneRelationEvents(SubjectPurgeReport $report): void
    {
        if ($report->relationEvents === []) {
            return;
        }

        KnowledgeRelationEvent::query()
            ->whereKey(array_map(static fn (SubjectRelationEventMatch $match) => $match->id, $report->relationEvents))
            ->delete();
    }

    /**
     * Delete the history-only revisions, then repair any entry whose `current_revision_id` pointed at
     * one of them.
     *
     * By construction that repair is unreachable: the current revision always carries the entry's
     * current authored text, so a revision that matched while its entry did not cannot be the current
     * one. It is done anyway because the pointer doubles as the OPTIMISTIC LOCK token — a dangling one
     * would make every subsequent save of that entry look like a stale write to its author, with no
     * error anywhere explaining why — and because "unreachable" is a property of today's write paths,
     * not of the table.
     *
     * The entries are NOT restaled for re-indexing: chunks are cut from an entry's CURRENT text, which
     * this does not touch.
     */
    private function deleteLoneRevisions(SubjectPurgeReport $report): void
    {
        if ($report->revisions === []) {
            return;
        }

        $revisionIds = array_map(static fn (SubjectRevisionMatch $match) => $match->id, $report->revisions);
        $entryIds = array_values(array_unique(array_map(
            static fn (SubjectRevisionMatch $match) => $match->entryId,
            $report->revisions,
        )));

        KnowledgeEntryRevision::query()->whereKey($revisionIds)->delete();

        $orphaned = KnowledgeEntry::withTrashed()
            ->withDrafts()
            ->whereKey($entryIds)
            ->whereIn('current_revision_id', $revisionIds)
            ->get();

        foreach ($orphaned as $entry) {
            $newest = KnowledgeEntryRevision::query()
                ->where('knowledge_entry_id', $entry->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');

            $entry->forceFill(['current_revision_id' => $newest])->saveQuietly();
        }
    }

    /**
     * Hard-purge each matched entry through the module's ONE cascade
     * ({@see KnowledgeEntryService::purge()}), extended with the erasure variant for inbound edges.
     */
    private function purgeEntries(SubjectPurgeReport $report, SubjectPhrases $phrases): void
    {
        foreach ($report->entries as $match) {
            // withDrafts(): the scan reported drafts, so the apply has to be able to FIND them —
            // otherwise the report would promise a deletion that silently did not happen.
            $entry = KnowledgeEntry::withTrashed()->withDrafts()->find($match->id);

            if ($entry === null) {
                continue;
            }

            $this->entries->purge($entry, $phrases);
        }
    }

    /**
     * Abandon each matched session — through the SAME path the user's own discard button takes
     * ({@see KnowledgeDraftSessionService::abandon()}), so there is one definition of what abandoning
     * means and an erasure cannot leave a session in a state a discard never produces.
     */
    private function purgeSessions(SubjectPurgeReport $report): void
    {
        foreach ($report->sessions as $match) {
            $session = KnowledgeDraftSession::query()->find($match->id);

            if ($session === null) {
                continue;
            }

            $this->sessions->abandon($session);
        }
    }

    /**
     * The audit line. IDS AND COUNTS ONLY.
     *
     * Not the phrase — the phrase IS the personal data the request is about, and writing it to a
     * shared, long-lived, widely-readable log would mean the act of erasing someone created a fresh
     * copy of their name in the one place nobody thinks to purge. And not the slugs or titles either,
     * for the same reason one step removed: a slug like `anna-kowalska` reproduces the phrase exactly.
     * What remains — who ran it, in which workspace, how much went, and the ids of every row — is
     * enough to reconcile the log against the operator's archived report, which is where the human
     * detail belongs. Pinned by a test that scans every log entry the command emits.
     */
    private function record(SubjectPurgeReport $report): void
    {
        Log::info('Knowledge subject purge applied.', [
            'workspace_id' => $report->workspaceId,
            'knowledge_base_id' => $report->baseId,
            'phrase_count' => count($report->phrases),
            'entries_purged' => count($report->entries),
            'draft_entries_purged' => $report->draftEntriesPurged(),
            'revisions_deleted' => count($report->revisions),
            'ghost_links_deleted' => count($report->ghostLinks),
            'relations_deleted' => count($report->relations),
            'draft_sessions_purged' => count($report->sessions),
            'cascaded_session_drafts' => $report->cascadedSessionDrafts(),
            'cascaded_revisions' => $report->cascadedRevisions(),
            'cascaded_chunks' => $report->cascadedChunks(),
            'entry_ids' => array_map(static fn (SubjectEntryMatch $match) => $match->id, $report->entries),
            'revision_ids' => array_map(static fn (SubjectRevisionMatch $match) => $match->id, $report->revisions),
            'ghost_link_ids' => array_map(static fn (SubjectGhostLinkMatch $match) => $match->id, $report->ghostLinks),
            'relation_ids' => array_map(static fn (SubjectRelationMatch $match) => $match->id, $report->relations),
            'draft_session_ids' => array_map(static fn (SubjectSessionMatch $match) => $match->id, $report->sessions),
        ]);
    }
}
