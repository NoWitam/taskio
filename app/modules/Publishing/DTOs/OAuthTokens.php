<?php

namespace App\Modules\Publishing\DTOs;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * WHAT A TOKEN ENDPOINT GAVE BACK, normalized — the one shape the rest of the module stores.
 *
 * Each provider parses its platform's dialect into this and nothing downstream ever sees a raw response
 * body. That boundary is worth more than the tidiness: a token response body is the single most
 * dangerous string in this application, and confining it to one method per provider is what makes "it
 * never reaches a log" a claim about three files rather than about the whole module.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `refreshToken` IS NULLABLE, AND A NULL MEANS TWO DIFFERENT THINGS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * "This platform does not issue one" (Meta, always) and "this response did not carry one" (Google, on
 * every refresh — a renewal returns a new access token and no new refresh token). Both are normal, and
 * neither may overwrite a refresh token we already hold.
 *
 * That is why persisting these is written as a MERGE rather than an assignment: writing the whole DTO
 * over the row would replace a perfectly good Google refresh token with null on the first successful
 * renewal, and the connection would work for one more hour and then need a human. The rule lives at the
 * one write site, in `PlatformConnectionManager::storeTokens()`, and it is the defect this paragraph
 * exists to have already prevented.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `expiresAt` IS AN INSTANT, NOT THE `expires_in` THE PLATFORM SENT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Converted at the moment of parsing, against our clock, because a duration is only meaningful relative
 * to when the response arrived and that context is gone one function call later. Null when the platform
 * declined to say — which is a real answer, and the refresh sweep treats it as "unknown" rather than as
 * "expired".
 *
 * Serialization is refused for the same reason it is on {@see PlatformCredentials}: this object holds a
 * live token and must not reach a queue payload or a cache entry.
 */
final readonly class OAuthTokens
{
    /**
     * @param  array<int, string>  $scopes  what the platform GRANTED, which is not always what we asked for
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $scopes = [],
    ) {}

    /**
     * Build from a parsed token response.
     *
     * `$expiresIn` is resolved against now() here, once, at the only moment the duration means anything.
     *
     * @param  array<int, string>  $scopes
     */
    public static function make(
        string $accessToken,
        ?string $refreshToken = null,
        ?int $expiresIn = null,
        array $scopes = [],
    ): self {
        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken !== null && $refreshToken !== '' ? $refreshToken : null,
            // A non-positive `expires_in` is not an expiry in the past, it is a platform saying
            // something we do not understand — recorded as "unknown" rather than as "already dead",
            // which would make the sweep refresh it on every pass forever.
            expiresAt: $expiresIn !== null && $expiresIn > 0
                ? CarbonImmutable::now()->utc()->addSeconds($expiresIn)
                : null,
            scopes: array_values(array_filter($scopes, static fn ($scope): bool => is_string($scope) && $scope !== '')),
        );
    }

    /** What a dump shows. NOT the tokens. */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[redacted, ' . strlen($this->accessToken) . ' chars]',
            'refreshToken' => $this->refreshToken === null ? null : '[redacted]',
            'expiresAt' => $this->expiresAt?->toISOString(),
            'scopes' => $this->scopes,
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
            'OAuth tokens must not be serialized. They belong in the encrypted columns on '
            . 'PlatformConnection, never in a queue payload, a cache entry or a session.'
        );
    }
}
