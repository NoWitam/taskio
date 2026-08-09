<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use Illuminate\Support\Carbon;

/**
 * Carries a validated KNOWLEDGE ENTRY from the request into the service.
 *
 * Two fields are not entry DATA but WRITE PROTOCOL, and they ride along because they belong to the
 * same save:
 *   - `expectedRevisionId` is the optimistic-lock token the writer echoes back (null = no check
 *     requested; see the update request for why that is allowed);
 *   - `changeNote` is the "why", recorded on the revision this save appends rather than on the entry.
 *
 * `slug` is null on create — the service mints it from the title — and is only ever non-null when a
 * user DELIBERATELY renames the handle on update. That asymmetry is what keeps the slug stable
 * through ordinary title edits.
 *
 * Everything — the authored text included — is read through the request's `resolved*()` accessors, so
 * "absent means unchanged" on update is decided in ONE place (the request) instead of being re-derived
 * by every consumer. The DTO therefore always carries the entry's RESULTING state, never a partial one:
 * the service, the digest and the revision comparison never have to know a PATCH happened.
 */
class KnowledgeEntryDTO
{
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        /** @var array<string, mixed> */
        public readonly array $metadata,
        public readonly KnowledgeEntryStatus $status,
        public readonly ?Carbon $staleAt,
        public readonly ?string $slug = null,
        public readonly ?string $changeNote = null,
        public readonly ?string $expectedRevisionId = null,
        /**
         * Other surface forms this entry is called by — the mention layer's substitute for a Polish
         * lemmatiser.
         *
         * LAST and defaulted, not beside `metadata` where it belongs by meaning: every parameter after
         * an optional one must itself be optional, and `status`/`staleAt` are required. Position here
         * is a language constraint, not a statement about importance — every call site names its
         * arguments, so nothing reads worse for it.
         *
         * @var array<int, string>
         */
        public readonly array $aliases = [],
        /**
         * WHAT KIND of thing this entry is about, or null when nobody has classified it — which is
         * most entries, permanently. Read only by the typed-relation matrix; see KnowledgeEntryType
         * for why null is a different answer from `other`.
         */
        public readonly ?KnowledgeEntryType $entryType = null,
        /**
         * The fact handles this entry claims to have absorbed — `["F1","F7"]`.
         *
         * NULL MEANS "LEAVE THE COLUMN ALONE", which is a different statement from `[]` meaning "this
         * entry claims nothing". Every caller that is not the composer passes null, so an ordinary save
         * cannot silently erase a claim.
         *
         * The distinction is here because this module has already been bitten by its absence:
         * `publishAmendment()` built its DTO without `entry_type`, and the write path duly set that
         * column to null on every accepted amendment. A field that is always written is a field that
         * is always wiped by whoever forgets it.
         *
         * @var array<int, string>|null
         */
        public readonly ?array $covers = null,
    ) {}
}
