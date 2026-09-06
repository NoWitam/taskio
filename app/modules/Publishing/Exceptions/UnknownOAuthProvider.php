<?php

namespace App\Modules\Publishing\Exceptions;

use App\Modules\Publishing\Enums\PublishingPlatform;
use RuntimeException;

/**
 * No OAuth provider is registered for a destination.
 *
 * The sibling of {@see UnknownPlatformAdapter}, and it is reached from TWO very different places, which
 * is why the HTTP door asks `OAuthProviderRegistry::has()` first rather than catching this.
 *
 *   THE ORDINARY CASE — somebody asked to connect an account for `dry_run`. That destination publishes
 *   nothing, has no account behind it, and never will. It is a caller mistake with an obvious answer, so
 *   `AuthorizePlatformConnectionRequest` refuses it as a 422 with a translated sentence, and this
 *   exception is never constructed.
 *
 *   THE CONFIGURATION ERROR — a real destination whose provider failed to register, which is a broken
 *   build. It throws, because the alternative is sending somebody to a consent screen for a platform
 *   this installation cannot complete a handshake with. That leaves an authorized application sitting on
 *   their account with nothing on our side pointing at it, and no screen from which to revoke it.
 */
class UnknownOAuthProvider extends RuntimeException
{
    public function __construct(public readonly PublishingPlatform $platform)
    {
        parent::__construct(
            'No OAuth provider is registered for [' . $platform->value . ']. '
            . 'Destinations without one cannot be connected; register from PublishingModuleServiceProvider::boot().'
        );
    }
}
