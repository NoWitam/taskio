<?php

namespace App\Modules\Variables\Support;

use App\Modules\Variables\Contracts\AuthorVoiceResolver;

/**
 * The shipped DEFAULT for the {@see AuthorVoiceResolver} seam (mirrors {@see PassthroughMeteredAiCall}):
 * a null object that resolves NOTHING, so every author id falls through to the session voice / persona
 * tone. It exists so the seam is always resolvable — including in a test or a console context where the
 * module that owns authors is irrelevant — while the real implementation is bound OVER it by that module.
 *
 * Returning `[]` is not a failure mode here: an absent id is the contract's documented fail-SAFE outcome.
 */
final class NullAuthorVoiceResolver implements AuthorVoiceResolver
{
    public function voicesFor(array $authorIds, ?string $workspaceId): array
    {
        return [];
    }
}
