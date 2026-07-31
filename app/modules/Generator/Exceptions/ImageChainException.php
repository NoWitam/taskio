<?php

namespace App\Modules\Generator\Exceptions;

use RuntimeException;

/**
 * The base for a DOMAIN failure the image chain can raise (R2 sub-stage 2c). The session executor catches
 * it and turns the affected image part into `{status:'failed', error}`, using {@see messageKey()} for a
 * LOCALIZED, NON-SECRET message — never the resolved prompt (which may embed slot values) nor a provider
 * body. A non-domain Throwable (a corrupt blob, a provider/transport error) falls to the executor's generic
 * image-failure message instead, so a malformed-but-write-validated plan can never 500 a run.
 */
abstract class ImageChainException extends RuntimeException
{
    /** The translation key for the client-facing, non-secret failure message. */
    abstract public function messageKey(): string;

    /**
     * The MACHINE-readable reason next to the human message, or null when the failure has none.
     *
     * Mirrors {@see \App\Modules\Disk\Enums\DiskAiEditStatus::errorCode} — the client should not have to
     * match on a translated sentence to tell "the provider's safety system refused this, change the
     * wardrobe" from "the chain is misconfigured". Null by DEFAULT so it stays additive: a failure only
     * carries a code when there is something specific and actionable to say, and every existing chain
     * failure keeps its exact wire shape.
     */
    public function errorCode(): ?string
    {
        return null;
    }
}
