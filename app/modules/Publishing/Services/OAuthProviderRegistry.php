<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Publishing\Contracts\OAuthProvider;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\UnknownOAuthProvider;
use Closure;
use RuntimeException;

/**
 * WHICH DESTINATIONS THIS INSTALLATION CAN AUTHORIZE AN ACCOUNT FOR.
 *
 * A sibling of {@see PlatformAdapterRegistry}, deliberately identical in shape — lazy registration, the
 * register()/boot() split, the same claim check, the same refusal to fail soft. Two registries rather
 * than one because the two things they hold are not in bijection: `dry_run` HAS a publishing adapter and
 * has NO OAuth provider, and Instagram and Facebook are two adapters served by ONE Meta dialect.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A MISSING PROVIDER IS THE ORDINARY CASE, AND IT STILL THROWS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `dry_run` publishes nothing, has no account behind it, and never will. Asking to authorize it is a
 * caller mistake, and {@see has()} is how the HTTP door asks in advance so the user gets a 422 that
 * explains itself rather than an exception.
 *
 * What {@see resolve()} must never do is answer null. This registry's caller is about to send a person
 * to a consent screen and then store a credential against whatever comes back; a soft failure there
 * means a handshake that half-happens, and half a handshake against a real platform can leave an
 * authorized application on somebody's account with nothing on our side pointing at it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * BEING REGISTERED IS NOT BEING CONFIGURED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Every real destination is registered on every boot, including on this installation, where none of the
 * three has credentials yet — the applications do not exist until the owner registers them with Google
 * and Meta. That is why {@see OAuthProvider::isConfigured()} is a separate question asked at the door.
 * Making registration conditional on configuration was the alternative and it is worse: `has()` would
 * then answer "we do not support Instagram" for a missing environment variable, which is the same
 * sentence for a product decision and for a typo.
 */
class OAuthProviderRegistry
{
    /** @var array<string, OAuthProvider|Closure(): OAuthProvider> */
    private array $providers = [];

    /**
     * Register a provider WITHOUT constructing it.
     *
     * @param  Closure(): OAuthProvider  $factory
     */
    public function registerLazy(PublishingPlatform $platform, Closure $factory): void
    {
        $this->guardOverwrite($platform);

        $this->providers[$platform->value] = $factory;
    }

    /** Register an already-built provider. */
    public function register(OAuthProvider $provider): void
    {
        $this->guardOverwrite($provider->platform());

        $this->providers[$provider->platform()->value] = $provider;
    }

    /** Whether this destination is one an account can be connected for at all. */
    public function has(PublishingPlatform $platform): bool
    {
        return array_key_exists($platform->value, $this->providers);
    }

    /**
     * Every destination that can be authorized, in registration order.
     *
     * @return array<int, PublishingPlatform>
     */
    public function platforms(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?PublishingPlatform => PublishingPlatform::tryFrom($value),
            array_keys($this->providers),
        )));
    }

    /**
     * The provider for a destination, constructing and memoizing it on first use.
     *
     * The claim check is the same one the adapter registry makes and closes the same hole with higher
     * stakes: the registration KEY routes the handshake, while the provider's own `platform()` is what
     * it believes it serves. Let them disagree and a token obtained on one platform is stored as a
     * connection to another — a row that will later be handed to an adapter for somewhere else.
     *
     * @throws UnknownOAuthProvider when nothing is registered for this destination
     */
    public function resolve(PublishingPlatform $platform): OAuthProvider
    {
        $provider = $this->providers[$platform->value] ?? null;

        if ($provider === null) {
            throw new UnknownOAuthProvider($platform);
        }

        if ($provider instanceof Closure) {
            $built = $provider();

            if ($built->platform() !== $platform) {
                throw new RuntimeException(
                    'OAuth provider registered under [' . $platform->value . '] claims to serve ['
                    . $built->platform()->value . ']. A handshake routed by the key would store a '
                    . 'credential for the wrong destination.'
                );
            }

            // Assigned only on success, so a transient construction failure does not poison the slot for
            // the rest of the process.
            $provider = $this->providers[$platform->value] = $built;
        }

        return $provider;
    }

    /**
     * Two providers for one destination is a configuration error that would otherwise resolve itself
     * silently in favour of whichever booted last — and "sent the user to the wrong consent screen" is
     * not a class of defect worth discovering against a live platform.
     */
    private function guardOverwrite(PublishingPlatform $platform): void
    {
        if (array_key_exists($platform->value, $this->providers)) {
            throw new RuntimeException(
                'An OAuth provider is already registered for [' . $platform->value . ']. '
                . 'Two providers for one destination is a configuration error, not a fallback.'
            );
        }
    }
}
