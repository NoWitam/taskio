<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Enums\KnowledgeEntryType;

/**
 * ONE NAME the extraction pass found in the raw material — before anything is known about whether the
 * base has ever heard of it.
 *
 * `$text` is the surface form exactly as the material wrote it ("Łukasz"), not a normalization: the
 * whole job of resolution is to get from that form to an entry, and normalizing here would throw away
 * the evidence the later passes match on.
 *
 * `$kind` is a HINT and never more than one. It steers resolution (a name the model calls a person
 * should not resolve to a place) and it is the proposed type for an entity that turns out to be new —
 * but it NEVER overwrites the `entry_type` of an entry that already exists. A model's guess about
 * something the workspace has already classified is worth strictly less than the classification, and
 * letting it win would mean pasting a note could silently re-type an entry nobody was editing.
 *
 * `$context` is the fragment the name appeared in, capped short. It exists so an AMBIGUOUS name can be
 * put to a human as a question they can actually answer ("Łukasz — 'spotkanie z Łukaszem o cenniku'")
 * rather than as a bare string with two candidates and no way to choose.
 */
final readonly class ExtractedMention
{
    /** How much of the surrounding sentence is kept. Enough to disambiguate, short enough to read. */
    public const MAX_CONTEXT_CHARS = 120;

    /** Cap on the surface form itself. A "name" longer than this is a sentence the model mislabelled. */
    public const MAX_TEXT_CHARS = 120;

    public function __construct(
        public string $text,
        public ?KnowledgeEntryType $kind,
        public string $context,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'kind' => $this->kind?->value,
            'context' => $this->context,
        ];
    }
}
