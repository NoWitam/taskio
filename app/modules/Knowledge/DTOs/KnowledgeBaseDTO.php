<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Http\Requests\StoreKnowledgeBaseRequest;

/**
 * Carries a validated KNOWLEDGE BASE from the request into the service. The request has already
 * proved the metadata schema is a list of well-formed authorable types, so the DTO only shapes the
 * persisted attributes.
 *
 * Every field is read through the request's `resolved*()` accessors, so "absent means unchanged" on
 * update is decided in ONE place. That matters more here than it looks: resolving an absent charter
 * or schema to empty would let a plain rename WIPE the base's governance data — see
 * {@see \App\Modules\Knowledge\Http\Requests\UpdateKnowledgeBaseRequest}.
 *
 * `language` is resolved the same way rather than defaulted in the database, because the sensible
 * default on create is the request's locale — which the schema layer cannot see.
 */
class KnowledgeBaseDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $charter,
        /** @var array<int, array{key: string, label?: string, descriptor: array<string, mixed>}> */
        public readonly array $metadataSchema,
        public readonly string $language,
        /**
         * The relation verbs this base allows, or NULL for the whole vocabulary. The null is carried
         * all the way to the column rather than expanded here: storing today's fifteen ids would freeze
         * the vocabulary into every base, so a sixteenth verb would silently be disallowed everywhere
         * it was never explicitly named.
         *
         * @var array<int, string>|null
         */
        public readonly ?array $relationTypes = null,
    ) {}

    public static function fromRequest(StoreKnowledgeBaseRequest $request): self
    {
        return new self(
            name: trim($request->string('name')->value()),
            description: $request->resolvedDescription(),
            charter: $request->resolvedCharter(),
            metadataSchema: $request->resolvedMetadataSchema(),
            language: $request->resolvedLanguage(),
            relationTypes: $request->resolvedRelationTypes(),
        );
    }
}
