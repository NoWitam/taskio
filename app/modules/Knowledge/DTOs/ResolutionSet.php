<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * THE FROZEN ANSWER to "what is this material about, and what does the base already know about it".
 *
 * Four lists, and each answers a different question — which is why they are not one list with a status
 * field. A reviewer and a model do genuinely different things with each:
 *
 *   entities[]    a name in the material IS this entry. Carries the entry's current text and its
 *                 typed relations, so the composer can amend rather than duplicate, and so a later
 *                 stage can propose graph changes against facts that exist.
 *   ambiguous[]   a name matched SEVERAL entries and nothing in the material chose between them. The
 *                 candidates are KEPT. Guessing here is the single most damaging thing this layer
 *                 could do — writing "Łukasz" into the wrong person's entry is worse than not writing
 *                 it at all, and unlike a missing link it is invisible once done.
 *   unresolved[]  a name the base has never heard of. NOT created automatically: an entity minted by
 *                 a mention nobody reviewed is how a base fills with half-facts about proper nouns.
 *   omitted[]     entries that resolved but did not fit the character ceiling, NAMED rather than
 *                 dropped, so nothing downstream mistakes silence for absence.
 *
 * ------------------------------------------------------------------------------------------------
 * HANDLES, NOT IDS
 *
 * Every entity is addressed as `E<n>` and every relation as `R<n>`. The model NEVER sees a database
 * id, and that is a security property rather than tidiness: a handle it invents does not resolve, so
 * the position is dropped. With raw ids in the prompt, a hallucinated uuid is indistinguishable from a
 * real one until it has been written somewhere, and "the AI edited an entry nobody mentioned" is a
 * class of bug that cannot be recovered from by reading the output.
 *
 * Handles are STABLE for the life of a session: the set is frozen at start and refinements read the
 * same one, so `E3` means the same entity in every later pass and in the review report.
 *
 * ------------------------------------------------------------------------------------------------
 * DEGRADATION IS PART OF THE ANSWER
 *
 * `$degraded` is non-null when the resolution ran with less than its full apparatus — the budget was
 * gone, vectors were unavailable, the base is larger than the deterministic scan limit. Every one of
 * those makes "unresolved" mean "I did not look properly" rather than "it is not there, and reporting
 * the two identically is how a user learns to distrust the whole feature.
 */
final readonly class ResolutionSet
{
    public const DEGRADED_DISABLED = 'disabled';

    public const DEGRADED_BUDGET = 'budget';

    public const DEGRADED_VECTORS_UNSUPPORTED = 'vectors_unsupported';

    public const DEGRADED_EMBEDDING_FAILED = 'embedding_failed';

    public const DEGRADED_EXTRACTION_FAILED = 'extraction_failed';

    public const DEGRADED_SCAN_LIMIT = 'scan_limit';

    /**
     * @param  array<int, ResolvedEntity>  $entities
     * @param  array<int, AmbiguousMention>  $ambiguous
     * @param  array<int, ExtractedMention>  $unresolved
     * @param  array<int, string>  $omitted  titles of entities that did not fit the ceiling
     * @param  array<int, string>  $degraded  reason codes; empty when the pass ran whole
     */
    public function __construct(
        public array $entities = [],
        public array $ambiguous = [],
        public array $unresolved = [],
        public array $omitted = [],
        public array $degraded = [],
        /**
         * WHAT THE MATERIAL SAYS HAPPENED — the reviewer's checklist, from the same phase-one call.
         *
         * It lives here rather than in a column of its own for two reasons, the second of which
         * settled it. It is produced by the same call, frozen at the same moment and read for the same
         * session, so a separate column would be a second lifecycle to keep in step for no gain. And
         * `resolution_set` is ALREADY one of the jsonb surfaces `knowledge:purge-subject` scans — while
         * a fact list is dense with personal data ("Łukasz przesadził z alkoholem"). A new column would
         * have been invisible to the erasure command until somebody remembered to add it, which is
         * precisely the class of omission that command exists to make impossible.
         *
         * @var array<int, ExtractedFact>
         */
        public array $facts = [],
        /**
         * WHO THE MATERIAL IS ABOUT — carried by the same route, and here for the same reason: the
         * reading pass produced it, the erasure command already scans this column, and a protagonist's
         * description is as personal as a name.
         *
         * @var array<int, ExtractedProtagonist>
         */
        public array $protagonists = [],
    ) {}

    public static function empty(string ...$degraded): self
    {
        return new self([], [], [], [], array_values($degraded));
    }

    /**
     * The same set with the reading pass's own findings attached — kept apart so the resolution legs
     * stay unaware of them.
     *
     * @param  array<int, ExtractedFact>  $facts
     * @param  array<int, ExtractedProtagonist>  $protagonists
     */
    public function withReading(array $facts, array $protagonists): self
    {
        return new self(
            $this->entities,
            $this->ambiguous,
            $this->unresolved,
            $this->omitted,
            $this->degraded,
            $facts,
            $protagonists,
        );
    }

    /**
     * Whether a reason describes a pass that TRIED AND FELL SHORT, as opposed to one that never ran.
     *
     * The distinction earns its keep in the review notes. `disabled` means an operator switched the
     * layer off — nothing was attempted, nothing is missing, and warning a reviewer about it on every
     * run of every session would be pure noise that trains people to skip the notes entirely. Every
     * other reason means the pass ran and could not see everything, which is exactly when a reviewer
     * must not read "unresolved" as "not in this base".
     */
    public static function isNotable(string $reason): bool
    {
        return $reason !== self::DEGRADED_DISABLED;
    }

    public function isEmpty(): bool
    {
        return $this->entities === [] && $this->ambiguous === [] && $this->unresolved === [];
    }

    /**
     * The jsonb shape stored on the session. Normalized on read by
     * {@see \App\Modules\Knowledge\Models\KnowledgeDraftSession::resolutionSet()}, so both sides of the
     * column agree about what is in it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entities' => array_map(static fn (ResolvedEntity $entity): array => $entity->toArray(), $this->entities),
            'ambiguous' => array_map(static fn (AmbiguousMention $mention): array => $mention->toArray(), $this->ambiguous),
            'unresolved' => array_map(static fn (ExtractedMention $mention): array => $mention->toArray(), $this->unresolved),
            'omitted' => $this->omitted,
            'degraded' => $this->degraded,
            'facts' => array_map(static fn (ExtractedFact $fact): array => $fact->toArray(), $this->facts),
            'protagonists' => array_map(
                static fn (ExtractedProtagonist $protagonist): array => $protagonist->toArray(),
                $this->protagonists,
            ),
        ];
    }
}
