<?php

namespace App\Modules\Publishing\DTOs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A PUBLIC ARTIFACT THAT EXISTS — the platform's own name for something anybody can now see.
 *
 * It is the only way to reach {@see \App\Modules\Publishing\Enums\PublicationStatus::PUBLISHED}, and
 * that is a deliberate use of the type system rather than an accident of signatures. The Manager's
 * `markPublished()` takes one of these, so "this went out" cannot be asserted by a caller that merely
 * believes it: something had to have produced a ref, and the only two producers are a successful
 * publish and {@see \App\Modules\Publishing\Contracts\PlatformAdapter::findExisting()} — the
 * reconciliation. A boolean argument in the same place would have let a retry path mark a row published
 * on optimism.
 *
 * `$url` is a convenience and `$id` is the identity. Platforms change URL formats; the id is what a
 * future unique index, a metrics fetch and a reconciliation all key on.
 */
final readonly class RemoteRef
{
    public function __construct(
        /** The platform's identifier for the published artifact. Its format, not ours. */
        public string $id,
        /** A permalink, when the platform hands one back. Never parsed, only shown. */
        public ?string $url = null,
        /**
         * When the platform says it went out, if it says. Null means "we only know that it did", and
         * the Manager stamps the moment we learned instead — which is the honest reading after a
         * reconciliation that ran hours later.
         */
        public ?CarbonImmutable $publishedAt = null,
    ) {}

    public static function make(string $id, ?string $url = null, ?CarbonInterface $publishedAt = null): self
    {
        return new self(
            id: $id,
            url: $url,
            publishedAt: $publishedAt !== null ? CarbonImmutable::instance($publishedAt)->utc() : null,
        );
    }
}
