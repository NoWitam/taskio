<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Enums\KnowledgeEntryType;

/**
 * WHO THE MATERIAL IS ABOUT, even when it never says their name.
 *
 * ------------------------------------------------------------------------------------------------
 * THE DEFECT THIS EXISTS FOR
 *
 * A measured run on real material produced seven entries — Paryż, Tokio, Warszawa, Wieża Eiffla,
 * Tajlandia, Łukasz Barszcz, a contest — and no entry for the woman every sentence was about. The
 * material calls her "influencerka" and never names her.
 *
 * Everything else followed from that. `mentions` lists NAMES, and a description is not a name, so she
 * appeared in no mention, therefore in no `unresolved`, therefore on no list the composer was shown.
 * The prompt did carry a sentence telling the writer to give the protagonist an entry — and a sentence
 * is what this module has now watched fail three times. Meanwhile the cities she visited became the
 * travellers (`Paryż visited Tajlandia`, refused by the matrix three times), the incident had no
 * subject to hang a relation on, and `[[influencerka]]` was written as a link to a page that did not
 * exist.
 *
 * So this is a FIELD IN THE EXTRACTION CONTRACT rather than a paragraph of advice. The reading pass
 * answers it, and the composer receives it the way it receives `unresolved`: a list of subjects that
 * must have entries.
 *
 * `description` is the material's own wording; `title` is what the entry should be called — the proper
 * name where the material gives one, otherwise the description. They differ precisely in the case this
 * was built for.
 */
final readonly class ExtractedProtagonist
{
    public const MAX_CHARS = 120;

    /** More than this is a cast rather than a protagonist; see the agent's instruction. */
    public const MAX = 3;

    public function __construct(
        public string $description,
        public string $title,
        public ?KnowledgeEntryType $kind = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'title' => $this->title,
            'kind' => $this->kind?->value,
        ];
    }
}
