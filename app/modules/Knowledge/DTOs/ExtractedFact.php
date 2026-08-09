<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * ONE THING THE MATERIAL SAYS HAPPENED — read out by phase one, frozen on the session, and shown to
 * the REVIEWER beside the proposals.
 *
 * ------------------------------------------------------------------------------------------------
 * A CHECKLIST FOR A PERSON, NOT A GATE FOR THE SERVER
 *
 * The obvious design was to have the composer declare which facts it covered and refuse the answer
 * when something was missed. It was rejected for a reason worth keeping written down: a model that
 * writes "2026-08-15: wyjazd do Tajlandii" and leaves out the incident inside that day has covered the
 * fact TRUTHFULLY by any check the server can make. Keyword matching would be a brittle imitation of
 * reading. The failure is semantic, and the only reader who can see it is a human — who spots it
 * instantly when the sentence "Łukasz przesadził z alkoholem i wywołał falę hejtu" sits next to an
 * entry that does not mention it.
 *
 * So this list is EVIDENCE PUT IN FRONT OF THE REVIEWER. `covers` is the model's own first-pass signal
 * about where each fact went, useful for sorting the list and never trusted as a verdict.
 *
 * ------------------------------------------------------------------------------------------------
 * A FACT IS NOT A RELATION
 *
 * These deliberately do not have to map onto `graph_updates`. "Łukasz drank too much and vomited on a
 * tourist's shoes" has one subject and a tourist who will never be an entry — there is no edge to draw
 * and the fact still matters. Requiring a fact to become a relation would quietly redefine coverage as
 * "did you draw an edge", which is a narrower question than the one being asked.
 *
 * `date` IS THE SOURCE'S OWN WORDING ("13 lipca"), never normalised. The point is to show a reviewer
 * what the material said, so that an entry dated differently is visible as a difference rather than
 * silently reconciled.
 */
final readonly class ExtractedFact
{
    public const MAX_TEXT_CHARS = 200;

    public const MAX_DATE_CHARS = 40;

    /** @param  array<int, string>  $subjects */
    public function __construct(
        public string $id,
        public string $text,
        public ?string $date = null,
        public array $subjects = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'date' => $this->date,
            'subjects' => $this->subjects,
        ];
    }
}
