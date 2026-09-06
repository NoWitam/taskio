<?php

namespace App\Modules\Publishing\Exceptions;

use RuntimeException;

/**
 * A `state` THAT DID NOT SURVIVE VERIFICATION — thrown, never worked around.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SIX REASONS ARE FIVE SECURITY REFUSALS AND ONE ORDINARY ONE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   MALFORMED       The string is not the shape we mint. Nothing was checked beyond that; it is where
 *                   garbage and truncation land.
 *   BAD_SIGNATURE   The payload does not match its HMAC. This is the one that means SOMEBODY TRIED —
 *                   the payload names a user and a workspace, and forging it would let an attacker
 *                   attach an account they control to a workspace they cannot see, or attribute one
 *                   they authorized to somebody else.
 *   EXPIRED         The signed payload's own `exp` has passed. Checked BEFORE the ledger, so an
 *                   ordinary abandoned handshake reports as expired rather than as "already used".
 *   ALREADY_USED    The signature is good and the payload is live, but the single-use ledger has no
 *                   entry. There is only one way to reach that: this nonce has been redeemed. A back
 *                   button, a prefetching browser, or a replay.
 *   PLATFORM_MISMATCH
 *                   The state was minted for one destination and presented at another's callback. Cheap
 *                   to check and worth checking: without it, a code obtained for a low-privilege
 *                   destination could be redeemed against a high-privilege one's exchange.
 *   BROWSER_MISMATCH
 *                   The handshake is being finished by a client that cannot prove it is the one that
 *                   started it. The state is signed and single-use, but neither property says anything
 *                   about WHO is holding it, so this code covers TWO opposite attacks:
 *
 *                     A STOLEN STATE — obtained over a shoulder, from a shared screen or a copied link —
 *                     completed with the ATTACKER'S account, landing a channel they control in somebody
 *                     else's workspace. Not the harmless "donating an account" it looks like: the channel
 *                     appears in the victim's destination picker, and content scheduled to it publishes
 *                     to an account the attacker owns.
 *
 *                     A PLANTED STATE — the attacker mints one for THEIR workspace and gets a victim to
 *                     follow it, so the victim's own channel is connected into the attacker's workspace.
 *                     Ordinary OAuth CSRF, which a self-contained signed state cannot catch, because the
 *                     state is genuine.
 *
 *                   The remedy for both is the cookie set when the state was minted. It carries a SECRET;
 *                   the state carries only its digest, so it can be neither computed from a stolen state
 *                   nor planted in a victim's browser. See {@see OAuthStateService}.
 *
 *                   IT IS ALSO THE ORDINARY FAILURE OF A BROWSER THAT DROPS COOKIES, and of a deployment
 *                   whose callback host is not the host serving the application — the cookie is
 *                   host-only. Both present as this code with nothing wrong at the caller's end, which is
 *                   why the frontend's sentence for it offers "try connecting again" rather than alarm.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A REJECTION IS ALLOWED TO SAY OUT LOUD
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The reason code, and that is all. It is put in a query parameter on the redirect back to the SPA, so
 * a person gets "that link has already been used, please connect again" rather than a blank screen —
 * and the code is stable, so the wording stays the frontend's to own.
 *
 * The raw `state` is NOT carried on this exception and never logged. It is not itself a credential, but
 * it is the thing an attacker is probing, and an exception message containing it would put it into a
 * log aggregator alongside a stack trace that says which check it failed — which is a working oracle.
 */
class OAuthStateRejected extends RuntimeException
{
    public const MALFORMED = 'oauth_state_malformed';

    public const BAD_SIGNATURE = 'oauth_state_bad_signature';

    public const EXPIRED = 'oauth_state_expired';

    public const ALREADY_USED = 'oauth_state_already_used';

    public const PLATFORM_MISMATCH = 'oauth_state_platform_mismatch';

    public const BROWSER_MISMATCH = 'oauth_browser_mismatch';

    /**
     * `$reason`, not `$code` — `Exception::$code` already exists and is an int, so a readonly string of
     * that name is a fatal redeclaration. The same choice `PublicationTransitionRefused` made.
     */
    public function __construct(public readonly string $reason)
    {
        // The message names the check and NOTHING about the value that failed it. See the docblock.
        parent::__construct('The OAuth state was rejected: ' . $reason);
    }

    public static function malformed(): self
    {
        return new self(self::MALFORMED);
    }

    public static function badSignature(): self
    {
        return new self(self::BAD_SIGNATURE);
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }

    public static function alreadyUsed(): self
    {
        return new self(self::ALREADY_USED);
    }

    public static function platformMismatch(): self
    {
        return new self(self::PLATFORM_MISMATCH);
    }

    public static function browserMismatch(): self
    {
        return new self(self::BROWSER_MISMATCH);
    }
}
