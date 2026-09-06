<?php

namespace App\Modules\Publishing\DTOs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * THE INTERMEDIATE ARTIFACT — an Instagram media container, a YouTube resumable-upload session URI.
 *
 * What phase 1 produced and phase 2 will consume. It is a type of its own rather than a bare string
 * because the thing it names has an EXPIRY that neither this module nor a caller may guess: an
 * Instagram container is good for about a day, a YouTube upload session for about a week, and a resume
 * attempted after the handle went stale fails in a way that looks exactly like a network error — which
 * is the failure most likely to be retried into a duplicate.
 *
 * `$expiresAt` is nullable and B1 stores nothing from it; the dry-run adapter has no expiry to state.
 * It is on the contract now because a field a real adapter cannot express has to be added to the
 * contract, the model, the schema and three call sites at the moment somebody is already deep in an
 * OAuth flow — and that is the moment it gets skipped.
 */
final readonly class RemoteDraft
{
    public function __construct(
        /** The platform's handle for the intermediate artifact. Persist it IMMEDIATELY. */
        public string $id,
        /** When the handle stops being resumable, if the platform says. */
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function make(string $id, ?CarbonInterface $expiresAt = null): self
    {
        return new self(
            id: $id,
            expiresAt: $expiresAt !== null ? CarbonImmutable::instance($expiresAt)->utc() : null,
        );
    }
}
