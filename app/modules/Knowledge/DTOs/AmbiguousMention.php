<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * A NAME THAT MATCHED SEVERAL ENTRIES, with the candidates kept.
 *
 * This is the category the whole resolution layer is judged by. Two people called Łukasz, and a note
 * that says "Łukasz" — the machine cannot know which, and every way of pretending otherwise is worse
 * than admitting it:
 *
 *   picking the best score      writes a fact into the wrong person's entry. Silent, plausible, and
 *                               invisible afterwards — the entry reads perfectly well, it is simply
 *                               about someone else.
 *   dropping it as unresolved   throws away the fact that the base DOES know these people, and invites
 *                               a new duplicate entry for a name it already has twice.
 *   asking every time           is what this is, and it is only bearable because the candidates come
 *                               with it: a question with two named answers is one click, a question
 *                               with no answers is research.
 *
 * So the candidates ride along, and the ambiguity is offered TWICE: to the model (which may resolve it
 * from context the matcher cannot read — "Łukasz z księgowości") and, if it declines, to the human in
 * the review report. The order matters — the model gets first refusal because it has the sentence, and
 * the human gets the last word because they have the workspace.
 */
final readonly class AmbiguousMention
{
    /**
     * @param  array<int, array{handle: string, slug: string, title: string, entry_type: ?string, score: ?float}>  $candidates
     */
    public function __construct(
        public ExtractedMention $mention,
        public array $candidates,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->mention->text,
            'kind' => $this->mention->kind?->value,
            'context' => $this->mention->context,
            'candidates' => $this->candidates,
        ];
    }
}
