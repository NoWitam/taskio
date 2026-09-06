<?php

namespace App\Modules\Publishing\Exceptions;

use RuntimeException;

/**
 * "THE PLATFORM SAID NO, AND NOTHING WAS CREATED."
 *
 * Both halves of that sentence are the contract, and the second one is the load-bearing half. Throwing
 * this asserts that the call had NO EFFECT on the platform — no container, no upload session, no post.
 * The publisher acts on that assertion by sending the row to `failed`, which is a state a retry may
 * leave.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHEN NOT TO THROW IT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * On a timeout. On a connection reset. On a 5xx. On a response whose shape you did not expect. On
 * anything at all where the honest answer is "I do not know whether that call landed". Let those
 * propagate as whatever they are: the publisher treats an unrecognised throwable as doubt and parks the
 * row in `needs_reconcile`, which no automatic path leaves.
 *
 * The asymmetry is deliberate and it is the point. Being wrong in this direction costs a person looking
 * at a row that turned out fine. Being wrong in the other direction costs a second public post that
 * nothing in this application can delete.
 *
 * `$failureCode` is a STABLE MACHINE STRING the UI translates — `media_missing`, `caption_too_long`,
 * `permission_withdrawn`. Never the platform's own prose: that is composed on their servers in whatever
 * language they choose and cannot be a contract. `$context` is the small structured aside that makes
 * the code actionable, and it carries NOTHING derived from a credential.
 */
class PlatformRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $failureCode,
        public readonly array $context = [],
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'The platform refused this publication: ' . $failureCode);
    }
}
