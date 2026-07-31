<?php

namespace App\Modules\Generator\Support;

use App\Modules\Generator\Contracts\SessionAuthorIdentityResolver;

/**
 * The shipped DEFAULT for the {@see SessionAuthorIdentityResolver} seam (mirrors
 * {@see \App\Modules\Variables\Support\NullAuthorVoiceResolver}): a null object that resolves NOTHING, so an
 * automation that orders an author gets none. It exists so the seam is always resolvable — including in a
 * test or a console context where the module that owns authors is irrelevant — while the real implementation
 * is bound OVER it by that module.
 *
 * Returning `null` is NOT a silent degradation: the caller treats "no identity" as a refusal to generate
 * (see the contract's fail-CLOSED clause), so an installation without the author module cannot quietly
 * publish anonymous content in a delegated step's place.
 */
final class NullSessionAuthorIdentityResolver implements SessionAuthorIdentityResolver
{
    public function identityFor(string $botId, ?string $workspaceId): ?array
    {
        return null;
    }

    /**
     * Knows NOBODY, consistently with resolving nothing: an installation without the author module cannot
     * save a definition naming an author it could never run. The two answers must agree in the null object
     * for the same reason they must agree in a real implementation — a save that accepts what the run refuses
     * is a definition that fails every time.
     */
    public function knowsAuthor(string $botId, ?string $workspaceId): bool
    {
        return false;
    }
}
