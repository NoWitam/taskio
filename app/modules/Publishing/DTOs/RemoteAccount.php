<?php

namespace App\Modules\Publishing\DTOs;

/**
 * WHOSE ACCOUNT THIS TOKEN SPEAKS FOR — asked of the platform, never assumed.
 *
 * It is a separate call after the exchange, and it is not optional. An access token on its own says
 * nothing about WHICH channel or page it can post to, and this module's whole uniqueness story
 * (`platform_connections.external_account_id`, and `publications` unique per connection) keys on the
 * answer. Without it a workspace could authorize the same channel twice, hold two tokens for it, and
 * publish through whichever row a picker listed first.
 *
 * `$id` is the identity and comes from the platform. `$name` is for a human to recognise the row by and
 * is nullable — a platform may decline to give one, and a connection nobody can name is still a working
 * connection.
 *
 * Nothing here is a credential, so unlike its two neighbours in this directory this object has no
 * serialization guard: a channel id is printed on the channel's own public page.
 */
final readonly class RemoteAccount
{
    public function __construct(
        public string $id,
        public ?string $name = null,
    ) {}

    public static function make(string $id, ?string $name = null): self
    {
        $name = $name !== null ? trim($name) : null;

        return new self(
            id: $id,
            name: $name !== null && $name !== '' ? $name : null,
        );
    }
}
