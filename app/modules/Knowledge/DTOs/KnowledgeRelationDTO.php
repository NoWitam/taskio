<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Enums\KnowledgeRelationOrigin;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use Illuminate\Support\Carbon;

/**
 * Carries a validated TYPED RELATION into the service.
 *
 * There is no `fromRequest()` any more, and that absence is the contract: relations are not written by
 * people, so nothing builds one of these from an HTTP payload. The only caller is
 * {@see \App\Modules\Knowledge\Services\KnowledgeGraphOpsApplier}, applying a proposal a human accepted.
 *
 * `origin` therefore arrives already decided, as it always did — `composer` for everything the applier
 * writes. It exists so a reader can tell where a statement came from, which is a claim the source has
 * to make about itself rather than one a caller gets to choose.
 *
 * Everything else is the statement: who, what verb, whom, and when it was true.
 */
class KnowledgeRelationDTO
{
    public function __construct(
        public readonly string $fromEntryId,
        public readonly string $toEntryId,
        public readonly KnowledgeRelationType $type,
        public readonly ?string $description,
        /** @var array<string, mixed> */
        public readonly array $properties,
        public readonly ?Carbon $validFrom,
        public readonly ?Carbon $validTo,
        public readonly KnowledgeRelationOrigin $origin,
        /** Provenance for a composer-authored relation; null everywhere else. */
        public readonly ?string $draftSessionId = null,
    ) {}
}
