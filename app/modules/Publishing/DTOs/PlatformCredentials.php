<?php

namespace App\Modules\Publishing\DTOs;

use LogicException;

/**
 * THE DECRYPTED PAIR — an access token, and a refresh token when the platform issued one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A TYPE AND NOT TWO STRING ARGUMENTS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Because the two families renew with DIFFERENT halves of it, and a signature that admitted only a
 * refresh token would have forced the branch this whole arrangement exists to avoid:
 *
 *   GOOGLE  renews with `refreshToken`, which is issued once, on the first consent, and survives every
 *           access-token renewal. A connection without one cannot be renewed at all.
 *   META    issues no refresh token ever. It renews by exchanging the CURRENT LONG-LIVED ACCESS TOKEN
 *           for a new one — so `accessToken` is the renewal credential, and `refreshToken` is
 *           permanently null.
 *
 * {@see \App\Modules\Publishing\Contracts\OAuthProvider::refresh()} therefore takes one of these and
 * each provider reaches for the field its platform actually uses. The difference lives in the two
 * providers, where it is a fact about a platform, rather than in a service, where it would be an `if`
 * somebody has to keep in step with a config file.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT NEVER GOES ANYWHERE A STRING WOULD
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `__debugInfo()` is overridden and `__toString()` deliberately does not exist. That is not decoration:
 * `var_dump`, `dd`, Laravel's own exception renderer and every log context array that receives an
 * object reach for exactly those, and this object exists in the two or three functions in this
 * application that are holding a live credential when something throws.
 *
 * Serialization is refused for the same reason — an object with these fields must not survive into a
 * queue payload or a cache entry, both of which are places tokens have historically ended up in plain
 * text. Anything that needs to persist them writes them through the model's `encrypted` casts.
 */
final readonly class PlatformCredentials
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
    ) {}

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    /**
     * What a dump shows. NOT the tokens.
     *
     * The lengths are there because they are the one thing worth knowing while debugging ("did we store
     * an empty string?") and the one thing that reveals nothing.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[redacted, ' . strlen($this->accessToken) . ' chars]',
            'refreshToken' => $this->refreshToken === null
                ? null
                : '[redacted, ' . strlen($this->refreshToken) . ' chars]',
        ];
    }

    /**
     * Refuses serialization outright. See the class docblock.
     *
     * @return array<int, string>
     */
    public function __sleep(): array
    {
        throw new LogicException(
            'Platform credentials must not be serialized. They belong in the encrypted columns on '
            . 'PlatformConnection, never in a queue payload, a cache entry or a session.'
        );
    }
}
