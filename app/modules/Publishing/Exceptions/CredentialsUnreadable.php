<?php

namespace App\Modules\Publishing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * THE STORED CIPHERTEXT WILL NOT OPEN WITH THIS INSTALLATION'S KEY.
 *
 * One cause, and it is not exotic: APP_KEY was rotated, or this database was restored beside a
 * different application. Either way it is not one bad row — it is EVERY row at once, and the moment it
 * happens is the moment this application has to behave well rather than loudly.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT IS TRANSLATED AT ALL, INSTEAD OF LETTING DecryptException THROUGH
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Because a raw `DecryptException` reaching the top of a request is a 500 on the connections screen —
 * the screen somebody would use to fix the problem — and reaching the top of a worker is a stack trace
 * with no state change, so the refresh sweep would fail on the same row every pass and never conclude
 * anything.
 *
 * Named, it becomes an OUTCOME. `TokenRefresher` catches it and sends the connection to `needs_reauth`,
 * which is the honest reading — nobody can use this credential, and a person re-connecting is the only
 * repair — and which holds the connection's scheduled publications instead of letting them fail one by
 * one at their appointed minutes.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT CARRIES AN ID AND NOT A VALUE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The connection id, which is ours and identifies the row to look at. Never the ciphertext, never a
 * length, never a fragment: this exception is thrown while the application is holding a string it
 * believes to be an encrypted token, and an exception message is the shortest path from a held string
 * to a log file. The previous exception is chained for a stack trace, and `DecryptException`'s own
 * message says only that the payload is invalid.
 */
class CredentialsUnreadable extends RuntimeException
{
    /** The stable code the connection is parked under. */
    public const FAILURE_CODE = 'credentials_unreadable';

    public function __construct(public readonly string $connectionId, ?Throwable $previous = null)
    {
        parent::__construct(
            'The stored credentials for platform connection [' . $connectionId . '] could not be '
            . 'decrypted with this application key.',
            0,
            $previous,
        );
    }

    public static function for(string $connectionId, ?Throwable $previous = null): self
    {
        return new self($connectionId, $previous);
    }
}
