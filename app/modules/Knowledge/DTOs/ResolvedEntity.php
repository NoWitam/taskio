<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * ONE ENTRY the material turned out to be about, frozen with everything a later pass needs to reason
 * about it WITHOUT another query — and without ever seeing its database id.
 *
 * `$handle` (`E<n>`) is the entity's only address. See {@see ResolutionSet} for why that indirection is
 * a safety property and not ceremony.
 *
 * `$mentions` records which raw surface forms landed here ("Łukasz", "Ł. Barszcz"). It is what makes a
 * resolution AUDITABLE: a reviewer looking at a proposal about `E3` can see the words in their own
 * document that caused it, which is the only way to catch a confident wrong match.
 *
 * `$content` follows the SAME rule the amendment fix established for retrieval — the entities a run may
 * amend are shown WHOLE up to `drafting.amend_full_chars`, everything else gets an excerpt — and
 * `$truncated` says which happened. A model that rewrites a document it was shown three quarters of
 * deletes the rest, and the only defence that does not depend on the model cooperating is knowing what
 * it was given.
 *
 * `$relations` is the entity's CURRENT typed graph, active statements only. History is deliberately
 * left out: a composer being told what is true now is being helped, and one told every membership the
 * person ever held is being asked to work out which of them still applies — a judgement the base has
 * already made and stored in `state`.
 */
final readonly class ResolvedEntity
{
    /**
     * @param  array<int, string>  $aliases
     * @param  array<int, string>  $mentions  the raw surface forms that resolved to this entity
     * @param  array<int, ResolvedRelation>  $relations
     */
    public function __construct(
        public string $handle,
        public string $slug,
        public string $title,
        public array $aliases,
        public ?string $entryType,
        public array $mentions,
        public string $content,
        public bool $truncated,
        public array $relations,
        /** How the match was made — exact, lexical, edit distance, vector. Auditing, not logic. */
        public string $matchedBy,
        /**
         * The revision the composer is about to READ, replayed as the optimistic-lock token when a
         * proposal against this entity is accepted.
         *
         * It is here because this set REPLACES the retrieval set once extraction is on, and that token
         * is the whole mechanism that turns "somebody edited the entry while the model was writing" into
         * a conflict rather than a silent overwrite. Carrying the content without it would have moved
         * the amendment path onto a source that had quietly dropped its own safety interlock.
         */
        public ?string $currentRevisionId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'slug' => $this->slug,
            'title' => $this->title,
            'aliases' => $this->aliases,
            'entry_type' => $this->entryType,
            'mentions' => $this->mentions,
            'content' => $this->content,
            'truncated' => $this->truncated,
            'matched_by' => $this->matchedBy,
            'current_revision_id' => $this->currentRevisionId,
            'relations' => array_map(
                static fn (ResolvedRelation $relation): array => $relation->toArray(),
                $this->relations,
            ),
        ];
    }
}
