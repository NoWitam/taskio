<?php

namespace App\Modules\Generator\DTOs;

use App\Modules\Generator\Http\Requests\StoreGenerationSessionRequest;

/**
 * Carries a validated NEW generation session from the request into the service. The request has already
 * resolved + authorized the source template (workspace-scoped) and stashed it, so this DTO SNAPSHOTS its
 * recipe — `{content_type, slots, content}` — into the immutable payload the session persists, alongside
 * the user's filled `slotValues` and a display `name` (defaulting to the template's own name).
 *
 * The persisted snapshot carries ONE more key the DTO does not: `author_voices`, the frozen per-block
 * `@[ai-text]` author voices. It is added by {@see \App\Modules\Generator\Services\GenerationSessionService::create}
 * — the one choke point BOTH creation paths pass through — because deriving it needs a tenant-scoped
 * lookup, which is service work, not request work.
 */
class CreateGenerationSessionDTO
{
    public function __construct(
        public readonly string $templateId,
        public readonly string $name,
        public readonly string $contentType,
        /** @var array{content_type: string, slots: array<int, mixed>, content: array<string, mixed>, author_voices?: array<string, string>} */
        public readonly array $recipeSnapshot,
        /** @var array<string, mixed> the user's filled slot inputs, keyed by slot name */
        public readonly array $slotValues,
    ) {}

    public static function fromRequest(StoreGenerationSessionRequest $request): self
    {
        $template = $request->template();

        $slots = is_array($template->slots) ? $template->slots : [];
        $content = is_array($template->content) ? $template->content : [];
        $contentType = (string) $template->content_type;

        $name = $request->input('name');

        return new self(
            templateId: (string) $template->id,
            name: is_string($name) && trim($name) !== '' ? $name : (string) $template->name,
            contentType: $contentType,
            recipeSnapshot: [
                'content_type' => $contentType,
                'slots' => $slots,
                'content' => $content,
            ],
            slotValues: $request->array('slot_values'),
        );
    }
}
