<?php

namespace App\Modules\Generator\Exceptions;

/**
 * A DEFENSIVE fallback for an `ai_generate` (text→image) base. Sub-stage 6 shipped the generate path, so
 * {@see \App\Modules\Generator\Services\ImageChainExecutor} now intercepts `ai_generate` UPSTREAM (metered,
 * budgeted) and this exception is no longer reached on the normal run path. It remains only for a direct
 * {@see \App\Modules\Generator\Services\ImageBaseResolver::resolve()} call with an `ai_generate` base, which
 * fails the image part with a clear localized message rather than crashing.
 */
class ImageBaseUnsupported extends ImageChainException
{
    public function messageKey(): string
    {
        return 'generator.sessions.ai_generate_unsupported';
    }
}
