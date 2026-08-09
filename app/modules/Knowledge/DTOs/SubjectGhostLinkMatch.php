<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * One GHOST edge whose `target_slug` names the subject.
 *
 * This is the deliberate EXCEPTION to the module's standing rule that an incoming link is degraded to
 * a ghost rather than deleted (see {@see \App\Modules\Knowledge\Services\KnowledgeLinkService}). That
 * rule exists so a base can still say what it referred to — but a ghost slug is not a neutral
 * pointer: `[[anna-kowalska]]` IS the person's name, rendered in the UI as a "missing entry" chip on
 * every page that links to it. Keeping it would mean the erasure produced a base that names the
 * subject in more places than before, which is the opposite of what was asked for. So a ghost whose
 * slug matches the phrase is deleted outright; every other ghost is untouched.
 */
final readonly class SubjectGhostLinkMatch
{
    public function __construct(
        public string $id,
        public string $baseId,
        public string $fromEntryId,
        public string $targetSlug,
        public string $source,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->baseId,
            'from_entry_id' => $this->fromEntryId,
            'target_slug' => $this->targetSlug,
            'source' => $this->source,
        ];
    }
}
