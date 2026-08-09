<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * What an erasure request found, and — once applied — what it destroyed.
 *
 * This object IS the operator's evidence. An erasure request has to be answerable months later with
 * "here is what we searched for, here is everything it reached, here is what was deleted and when",
 * so the report carries the phrases and the human-readable slugs/titles alongside the ids. That is
 * the exact opposite of the rule the LOG obeys (ids and counts only, never a phrase, never a slug):
 * the report is printed to the operator's terminal for them to archive against the request, while
 * the application log is a shared, long-lived, widely-readable surface that must not accumulate the
 * personal data it was asked to erase. The asymmetry is deliberate, and it is pinned by a test.
 *
 * The same document is rendered for a DRY RUN and for an APPLY — `mode` is the only difference —
 * because the value of the dry run comes entirely from it being the same report, produced by the same
 * query, that the apply will act on.
 */
final class SubjectPurgeReport
{
    /**
     * @param  list<string>  $phrases
     * @param  list<SubjectEntryMatch>  $entries
     * @param  list<SubjectRevisionMatch>  $revisions
     * @param  list<SubjectGhostLinkMatch>  $ghostLinks
     * @param  list<SubjectSessionMatch>  $sessions
     * @param  list<SubjectRelationMatch>  $relations
     * @param  list<SubjectRelationEventMatch>  $relationEvents
     * @param  list<SubjectCharterMatch>  $charters  REPORT-ONLY. Never deleted, never applied, and
     *                                               deliberately absent from `totalMatches()`.
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly ?string $baseId,
        public readonly array $phrases,
        public readonly array $entries,
        public readonly array $revisions,
        public readonly array $ghostLinks,
        public readonly array $sessions = [],
        public readonly array $relations = [],
        public readonly array $relationEvents = [],
        public readonly array $charters = [],
        public readonly bool $applied = false,
        public readonly ?string $refused = null,
    ) {}

    public function asApplied(): self
    {
        return new self(
            $this->workspaceId,
            $this->baseId,
            $this->phrases,
            $this->entries,
            $this->revisions,
            $this->ghostLinks,
            $this->sessions,
            $this->relations,
            $this->relationEvents,
            // Carried through an APPLY unchanged, and that is the point: the charters are still
            // outstanding after the erasure ran, so the archived "applied" report has to keep saying so.
            $this->charters,
            true,
            $this->refused,
        );
    }

    public function refusedAs(string $reason): self
    {
        return new self(
            $this->workspaceId,
            $this->baseId,
            $this->phrases,
            $this->entries,
            $this->revisions,
            $this->ghostLinks,
            $this->sessions,
            $this->relations,
            $this->relationEvents,
            $this->charters,
            $this->applied,
            $reason,
        );
    }

    /**
     * The number the operator has to type back to confirm an apply — and the one the breadth cap reads.
     *
     * It counts the DECIDED categories and not the cascade beneath them. The point of retyping it is to
     * force a reading of the report, and a number that moves for reasons the report explains (an entry
     * happens to have 40 revisions) teaches the operator to copy it rather than read it.
     *
     * CHARTERS ARE DELIBERATELY NOT COUNTED, and the reason is not that they matter less.
     *
     * This number does two jobs, and both are about DESTRUCTION: it confirms an apply, and it trips the
     * breadth cap that refuses one. A charter is never destroyed by this command, so including it would
     * let a report-only finding refuse an apply — and the only way past that refusal is `--force`, which
     * ALSO skips the typed confirmation. The operator would be pushed into weakening the guard on the
     * categories that DO get deleted, for a reason having nothing to do with them.
     *
     * The "operator should see the whole picture" argument is right and is answered elsewhere: the
     * charter section prints in every report, and {@see hasCharters()} keeps the command from ever
     * saying "nothing matched" while one is outstanding.
     */
    public function totalMatches(): int
    {
        return count($this->entries)
            + count($this->revisions)
            + count($this->ghostLinks)
            + count($this->sessions)
            + count($this->relations)
            + count($this->relationEvents);
    }

    /**
     * How many of the purged entries are unaccepted AI DRAFTS.
     *
     * Reported separately because the operator is certifying the destruction of two different kinds of
     * thing: published knowledge someone wrote, and machine output nobody approved. A single "entries"
     * figure would hide the second inside the first.
     */
    public function draftEntriesPurged(): int
    {
        return count(array_filter($this->entries, static fn (SubjectEntryMatch $entry): bool => $entry->isDraft));
    }

    /** Drafts destroyed as part of an abandoned session — apart from the ones matched on their own. */
    public function cascadedSessionDrafts(): int
    {
        return array_sum(array_map(static fn (SubjectSessionMatch $session) => $session->drafts, $this->sessions));
    }

    /** Revisions destroyed as part of a purged entry — reported apart from the ones deleted alone. */
    public function cascadedRevisions(): int
    {
        return array_sum(array_map(static fn (SubjectEntryMatch $entry) => $entry->revisions, $this->entries));
    }

    /** Embedded passages (vectors) destroyed with the purged entries. */
    public function cascadedChunks(): int
    {
        return array_sum(array_map(static fn (SubjectEntryMatch $entry) => $entry->chunks, $this->entries));
    }

    /**
     * Nothing for this command to DESTROY.
     *
     * Not the same question as "nothing was found" — a charter can be flagged while this is true, which
     * is why the command's wording branches on {@see hasCharters()} as well. Saying "nothing matched"
     * with a charter outstanding is exactly the false all-clear this category was added to prevent.
     */
    public function isEmpty(): bool
    {
        return $this->totalMatches() === 0;
    }

    /** Whether any base charter names the subject and is waiting on a human to edit it. */
    public function hasCharters(): bool
    {
        return $this->charters !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'command' => 'knowledge:purge-subject',
            'scope' => 'knowledge',
            'generated_at' => now()->toIso8601String(),
            'workspace_id' => $this->workspaceId,
            'knowledge_base_id' => $this->baseId,
            'phrases' => $this->phrases,
            'mode' => $this->applied ? 'applied' : 'dry-run',
            'refused' => $this->refused,
            'totals' => [
                'matches' => $this->totalMatches(),
                'entries_purged' => count($this->entries),
                // Of the entries above, how many were unaccepted AI drafts. A SUBSET, not an addition:
                // an operator must be able to see that machine output was reached too, without the
                // headline count double-reporting it.
                'draft_entries_purged' => $this->draftEntriesPurged(),
                'revisions_deleted' => count($this->revisions),
                'ghost_links_deleted' => count($this->ghostLinks),
                'draft_sessions_purged' => count($this->sessions),
                // Relations whose OWN text names the subject. Almost always zero — the usual case is
                // that purging the two entries takes the relation with them — and non-zero exactly in
                // the case that would otherwise be missed: a name written on the edge and nowhere else.
                'relations_deleted' => count($this->relations),
                // AUDIT LINES whose snapshots name the subject while their relation does not — the
                // residue left by the responsible remedy (somebody edited the name out, and the
                // `before` snapshot of that very edit kept it). Counted apart from the cascade: the
                // events of a relation being deleted above go with it and are not listed here.
                'relation_events_deleted' => count($this->relationEvents),
                // `_flagged`, NOT `_deleted`, and it is the only counter in this block with that
                // suffix. Every other number here says what was (or would be) destroyed; this one says
                // what a human still has to go and edit. A reader skimming an archived report must not
                // be able to mistake the two, so the two are not named alike.
                'charters_flagged' => count($this->charters),
                'cascaded_revisions' => $this->cascadedRevisions(),
                'cascaded_chunks' => $this->cascadedChunks(),
                'cascaded_session_drafts' => $this->cascadedSessionDrafts(),
            ],
            'entries' => array_map(static fn (SubjectEntryMatch $match) => $match->toArray(), $this->entries),
            'revisions' => array_map(static fn (SubjectRevisionMatch $match) => $match->toArray(), $this->revisions),
            'ghost_links' => array_map(static fn (SubjectGhostLinkMatch $match) => $match->toArray(), $this->ghostLinks),
            // The raw material somebody PASTED into the composer. Not reachable from any entry, never
            // indexed — so without this section an erasure request could be certified complete while the
            // person's name sat in a `source_text` blob nobody had looked at.
            'draft_sessions' => array_map(static fn (SubjectSessionMatch $match) => $match->toArray(), $this->sessions),
            // TYPED RELATIONS whose `description` or `properties` name the subject. The edge itself is
            // free text somebody wrote, so it can name a person neither of its two entries mentions —
            // in which case nothing else in this report would reach it.
            'relations' => array_map(static fn (SubjectRelationMatch $match) => $match->toArray(), $this->relations),
            // The relation AUDIT TRAIL, which has no foreign key to the relation on purpose (so that
            // deleting a relation cannot delete the record of its deletion) and no API that can rewrite
            // a line. Both are right, and both are why nothing else in this report reaches these rows.
            'relation_events' => array_map(static fn (SubjectRelationEventMatch $match) => $match->toArray(), $this->relationEvents),

            // REPORT-ONLY, and the only section here that is. A charter is prepended to the compiled
            // knowledge block on EVERY generation against its base, so a person named in one is being
            // sent to the provider continuously — but the charter belongs to the base rather than to
            // them, and neither deleting the base nor blanking its editorial policy is a decision this
            // command may take. It names the base and stops; a human edits the sentence.
            'charters' => array_map(static fn (SubjectCharterMatch $match) => $match->toArray(), $this->charters),

            // FUTURE-PROOFING, not decoration. A generation session persists a `knowledge_frame`
            // snapshot of the knowledge it was handed, and from Stage 2 that snapshot starts carrying
            // knowledge text VERBATIM — at which point erasing an entry here would leave the same
            // sentences sitting in every session that ever quoted it, and this command would silently
            // stop being a complete answer for its own module. Reporting the section as a hard zero
            // NOW means the gap is a line in every archived report and a failing expectation the day
            // snapshots go verbatim, instead of a discovery made after an erasure request was already
            // certified as fulfilled. Whoever implements that stage fills this in.
            'generation_session_snapshots' => 0,
        ];
    }
}
