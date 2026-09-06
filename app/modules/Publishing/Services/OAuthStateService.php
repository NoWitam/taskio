<?php

namespace App\Modules\Publishing\Services;

use App\Models\User;
use App\Modules\Publishing\DTOs\OAuthState;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\OAuthStateRejected;
use App\Modules\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * MINTS AND REDEEMS THE `state` THAT CARRIES A HANDSHAKE ACROSS AN UNAUTHENTICATED REDIRECT.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE PROBLEM THIS SOLVES, STATED PLAINLY
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Everything else in this application authenticates the same way: the SPA sends a bearer token from
 * `localStorage` and names its workspace in `X-Workspace-Id` (`resources/js/next/app/lib/token.ts`),
 * and `ResolveWorkspace` turns that header into a tenant.
 *
 * An OAuth callback has NEITHER. It is a browser navigation initiated by Google or Meta, with no
 * Authorization header, no workspace header, and no JavaScript of ours running. The request arrives
 * knowing nothing, and it has to decide which USER to attribute a connection to and which DATABASE to
 * write it into.
 *
 * The only channel available is the `state` parameter, which the platform echoes back verbatim. So the
 * design is: put the facts in `state`, SIGN them so they cannot be invented, and make them redeemable
 * ONCE so they cannot be replayed.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE SHAPE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *     base64url( json payload ) . "." . base64url( hmac-sha256 of that base64url text )
 *
 * The payload is `{v, jti, u, w, p, iat, exp, bh}` — version, nonce id, user, workspace, platform, two
 * timestamps, and the DIGEST of the browser binding. Nothing secret is in it, because a `state` travels
 * in a URL through the platform's servers, their access logs, and the browser's history. The signature is
 * what makes those identifiers MEAN something; it is not what hides them.
 *
 * THE MAC IS OVER THE ENCODED TEXT, NOT OVER THE DECODED JSON. Signing the decoded form would mean
 * verification had to decode-then-re-encode to reproduce the signed bytes, and any difference in that
 * round trip — key order, escaping, a `+` the platform normalized — either breaks every valid state or,
 * worse, admits two different payloads under one signature. Verifying the exact bytes that were signed
 * removes the question.
 *
 * `hash_equals` for the comparison: a byte-at-a-time `===` on a MAC is a timing oracle, and this one is
 * reachable by anybody who can hit the callback URL.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE KEY IS DERIVED FROM APP_KEY, NOT APP_KEY ITSELF
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `hash_hmac('sha256', 'taskio.publishing.oauth-state.v1', APP_KEY)` — one HMAC to derive, then the real
 * one. This costs nothing and buys domain separation: the application key also encrypts sessions,
 * cookies, and the token columns two files over. Using it raw for a second, differently-shaped purpose
 * is how one construction's quirks become another's vulnerability, and the label makes a signature
 * minted here meaningless anywhere else.
 *
 * A ROTATED APP_KEY invalidates every in-flight handshake. That is correct and costs one re-click; the
 * alternative — a separate secret with its own lifecycle — is a second thing to configure and forget.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHERE SINGLE-USE LIVES: THE CACHE. THE REASONING IS ABOUT OWN-DATABASE WORKSPACES.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A table was the obvious alternative and it is the wrong one HERE, for a reason specific to this
 * application's tenancy.
 *
 * A row proving "this nonce has not been used" would have to live somewhere. Put it in a TENANT table
 * and the callback cannot reach it: choosing the tenant database requires knowing the workspace, and the
 * workspace is a claim inside the very state we have not finished verifying. We would have to trust the
 * payload to select a database, and only then ask that database whether the payload was trustworthy —
 * an ordering that makes the check answerable by the thing being checked. Put it in the CENTRAL table
 * instead and the single-use ledger for an own-database customer lives outside their database, which is
 * the exact posture the connections table itself refuses.
 *
 * The cache sidesteps the dilemma because it is TENANCY-AGNOSTIC by construction. It is not a tenant
 * store, so there is no wrong database to choose; the ledger is consulted BEFORE any workspace is
 * resolved, and the same code path holds for shared and own-database workspaces alike. It also holds
 * nothing sensitive — a random id and a `true` — so its being outside the tenant database says nothing
 * about anybody's data. And expiry is the store's job, so there is no reaper for a table of dead nonces.
 *
 * WHAT THAT CHOICE COSTS, NAMED RATHER THAN DISCOVERED:
 *   • With `CACHE_STORE=file` (this repository's `.env`) the ledger is per-server. A multi-server
 *     deployment MUST move the cache to a shared store — database or redis — or a callback landing on a
 *     different node than the authorize call will report `already_used` for a first attempt. Both are
 *     already configured stores; this is a deployment note, not a code change.
 *   • `php artisan cache:clear` invalidates in-flight handshakes. The user clicks connect again.
 *   • {@see Cache::pull()} is get-then-forget rather than a single atomic operation, so two callbacks
 *     racing on the same nonce could in principle both pass. The consequence is bounded to nothing: the
 *     authorization CODE is itself single-use at the platform, so the second exchange fails there. This
 *     is the second of two independent guards, not the only one.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE STATE IS ALSO BOUND TO THE BROWSER THAT STARTED THE HANDSHAKE — IN BOTH DIRECTIONS
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A signature proves the state was minted by us. Single use proves it is redeemed once. NEITHER SAYS
 * ANYTHING ABOUT WHO IS HOLDING IT, and a state is not a secret by construction: it travels in a URL,
 * through the platform's servers, into their access logs and into the browser's history.
 *
 * There are TWO attacks, they point opposite ways, and the same cookie answers both:
 *
 *   THE STOLEN STATE. Somebody obtains a state inside its ten minutes — over a shoulder, from a shared
 *   screen, out of a copied link — walks through the consent screen with THEIR OWN account, and finishes
 *   the handshake against somebody else's workspace. The reading that this is harmless ("the attacker is
 *   donating their own account") is wrong, and naming the error matters because it is the reading that
 *   let the gap stand: the connection lands in the victim's workspace and appears in its destination
 *   picker like any other, so everything subsequently scheduled to it is PUBLISHED TO A CHANNEL THE
 *   ATTACKER OWNS. It is a content exfiltration path with a publish button on it.
 *
 *   THE PLANTED STATE — ordinary OAuth CSRF, which a self-contained signed state does NOT catch, because
 *   the state is genuine. The attacker mints one for THEIR OWN workspace and gets the victim to follow
 *   it; the victim consents with THEIR account, and the token to the victim's channel lands in the
 *   attacker's workspace, to publish from at leisure.
 *
 * So minting also sets a short-lived, `HttpOnly`, `SameSite=Lax` cookie, and the callback refuses a
 * handshake whose cookie does not match the state. The SPA mints over XHR and then navigates the SAME
 * window, so the cookie is present on the return leg; `Lax` is what allows it to be sent on a top-level
 * cross-site GET, which is exactly the shape of an OAuth redirect and nothing else.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE COOKIE CARRIES A SECRET AND THE STATE CARRIES ITS DIGEST. THE ASYMMETRY IS THE MECHANISM.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The cookie value used to be the `jti` — which is IN the signed payload, in the URL, in plain base64url.
 * The argument for that was "an attacker holding the state cannot SET a cookie on the victim's origin in
 * their own browser", and it is FALSE. `HttpOnly` stops a script on somebody else's page from READING a
 * cookie; it says nothing about an attacker SETTING one in a client they control, where there is no
 * origin to respect because the request is theirs. `curl -H 'Cookie: taskio_publishing_handshake=<the
 * jti out of the state>'` is the entire technique, and a reviewer's probe walked a client with no prior
 * contact with this application straight through it. The binding covered the planted state and did
 * nothing at all about the stolen one.
 *
 * So: `issue()` draws 32 bytes from the CSPRNG, the COOKIE carries those bytes, and the signed payload
 * carries only `bh = sha256(secret)`. A thief holding the state holds a digest, and inverting SHA-256 of
 * 256 random bits is not a thing anybody does. `consume()` hashes what the browser sent and compares.
 * Both directions are now closed: the secret cannot be computed from a stolen state, and it cannot be
 * planted in a victim's browser.
 *
 * THE BOUNDARY, NAMED HONESTLY: an attacker who captures BOTH the state AND the cookie secret — a full
 * read of the authorize RESPONSE, which is an authenticated XHR over TLS — still wins. That is outside
 * this model; somebody reading that response has the caller's bearer token as well, and a browser binding
 * is not what answers an attacker who is already inside the session.
 *
 * THE CONSEQUENCE, ON PURPOSE: an authorize URL copied into another browser — or another profile, or a
 * private window — stops working. That is the feature. What it costs is a person who starts a handshake
 * on their laptop and finishes it on their phone, which is not a flow this product offers, and a browser
 * configured to drop cookies outright, which gets `oauth_browser_mismatch` and a link to try again.
 *
 * AND ONE DEPLOYMENT COUPLING, because its symptom points at the wrong thing: the cookie is HOST-ONLY
 * (no `domain`), so the host the callback returns to must be the host the application is served from, or
 * covered by `SESSION_DOMAIN`. Point `publishing.oauth.redirect_base` at a different host and EVERY
 * legitimate connect fails as `oauth_browser_mismatch` — which reads as a defect in this file rather than
 * as a URL somebody typed. See the note beside `redirect_base` in `config/publishing.php`.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE ORDER OF THE CHECKS IS PART OF THE DESIGN
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * shape → signature → platform → expiry → browser → ledger.
 *
 * SIGNATURE BEFORE ANYTHING IS READ from the payload, so a forged state never reaches a database or even
 * a cache lookup.
 *
 * PLATFORM BEFORE THE LEDGER, so a state presented at the wrong destination's callback is refused
 * WITHOUT being spent — it is still redeemable at the one it was minted for, which is the correct
 * outcome for a mis-routed redirect.
 *
 * EXPIRY FROM THE SIGNED PAYLOAD, BEFORE THE LEDGER, so an ordinary abandoned handshake reports
 * `expired` instead of the alarming `already_used`. The ledger entry outlives the payload by
 * `state_ledger_grace` seconds precisely to keep those two distinguishable: past the payload's own
 * expiry, a ledger miss could otherwise mean either thing, and the two want different sentences.
 *
 * BROWSER BEFORE THE LEDGER, for the same reason as the platform check: a stolen state presented from
 * the wrong browser is refused WITHOUT being spent, so the person who actually started the handshake can
 * still finish it. Refusing after the ledger would let a thief who cannot complete the flow at least
 * destroy it, which is a denial of service handed out for free.
 *
 * THAT ORDER IS NOT AN ORACLE, which is the question the browser check raises now that it is the strong
 * one. An attacker without the secret gets `oauth_browser_mismatch` whatever the ledger holds, so the
 * refusal tells them nothing they could not compute anyway: the states that ARE distinguished earlier —
 * malformed, bad signature, wrong platform, expired — are all decidable from the plaintext payload they
 * are already holding.
 */
class OAuthStateService
{
    /**
     * Bumped when the payload's shape changes. An old-format state then fails as MALFORMED instead of
     * being misread by a parser that has moved on — which is the failure mode a version field exists to
     * prevent, and it costs one integer.
     *
     * 1 → 2 ADDED `bh`, the browser-binding digest. A v1 payload has none, and a v1 COOKIE was the `jti`,
     * so accepting one would mean accepting the binding this version exists to replace. In-flight
     * handshakes at deploy cost one re-click; `PublishingOAuthStateTest` pins that they fail as malformed
     * rather than as something a reader would go looking for a cause for.
     */
    private const VERSION = 2;

    /**
     * Domain separation for the derived signing key. See the class docblock.
     *
     * The `.v1` is the KEY's label, not the payload's version, and it deliberately did NOT move with
     * VERSION above: rotating it would invalidate nothing extra (the version check already refuses every
     * old payload) while changing every signature this installation can verify, for no property.
     */
    private const KEY_PURPOSE = 'taskio.publishing.oauth-state.v1';

    private const CACHE_PREFIX = 'publishing:oauth-state:';

    /**
     * The cookie that binds a handshake to the browser that started it. See the class docblock.
     *
     * ITS VALUE IS A SECRET — 32 random bytes that appear nowhere else, least of all in the `state`.
     *
     * It is listed in `bootstrap/app.php`'s `encryptCookies(except: …)` and that is not optional: it is
     * SET from the `api` group, which has no cookie encryption, and READ in the `web` group, which does —
     * so without the exemption `EncryptCookies` would find an undecryptable value on the way in and null
     * it, and every legitimate callback would report `oauth_browser_mismatch`.
     *
     * What the exemption costs, now that the value IS a secret: nothing that application-level encryption
     * would have bought. The ciphertext would be just as redeemable as the plaintext to anybody holding
     * it — encryption here defends against a client reading its own cookie, which is not the threat. What
     * actually protects it is that it is `HttpOnly`, `SameSite=Lax`, `Secure` wherever the session is,
     * single-use, and dead in ten minutes.
     */
    public const HANDSHAKE_COOKIE = 'taskio_publishing_handshake';

    /**
     * How many bytes of CSPRNG output the browser binding is, before hex encoding.
     *
     * 32 because that is the width of the digest it is compared through; more would be hashed down to the
     * same 256 bits and less would be the weakest link in a chain whose other links are all 256.
     */
    private const BROWSER_SECRET_BYTES = 32;

    /**
     * Mint a state for a handshake that is about to start.
     *
     * The caller is an AUTHENTICATED API request, which is the whole reason this is a two-endpoint dance:
     * the authorize call proves who is asking while it still can, and hands the callback the proof.
     */
    public function issue(User $user, Workspace $workspace, PublishingPlatform $platform): OAuthState
    {
        $now = CarbonImmutable::now()->utc();

        // THE BROWSER BINDING. `random_bytes` and not `Str::uuid()`, `uniqid()` or the `jti`: this is the
        // ONE value in the handshake that has to be unguessable, because everything else about a state
        // travels in a URL. Hex rather than raw bytes so it survives a cookie round trip unchanged — the
        // comparison is over the digest, but a value mangled in transport would fail the digest too.
        $browserSecret = bin2hex(random_bytes(self::BROWSER_SECRET_BYTES));

        $state = new OAuthState(
            // A version-4 uuid: 16 bytes, 122 of them random, from the framework's CSPRNG. Guessing one
            // is not the attack this defends against — the signature is — but a predictable jti would
            // make the ledger exhaustible by an attacker who could pre-consume nonces, and 122 bits is
            // far past the point where that is arithmetic anybody can do.
            jti: (string) Str::uuid(),
            userId: $user->id,
            workspaceId: $workspace->id,
            platform: $platform,
            issuedAt: $now,
            expiresAt: $now->addSeconds($this->ttl()),
            // THE DIGEST TRAVELS. The secret does not — see the class docblock.
            browserHash: hash('sha256', $browserSecret),
            browserSecret: $browserSecret,
        );

        // The ledger entry is what makes the nonce single-use. Written BEFORE the URL is handed out, so
        // there is no window in which a state exists and is not yet redeemable.
        Cache::put(
            self::CACHE_PREFIX . $state->jti,
            true,
            $this->ttl() + $this->ledgerGrace(),
        );

        return $state;
    }

    /**
     * The cookie that must come back with the callback for this state to be redeemable.
     *
     * IT CARRIES THE SECRET, which is why it can only be minted from a state this process just ISSUED. A
     * state decoded from the wire has the digest and nothing else; asking for its cookie is a programming
     * error rather than a runtime condition, so it is refused loudly instead of producing a cookie no
     * browser could ever redeem.
     *
     * `HttpOnly` because no script of ours reads it and a script of somebody else's must not.
     * `SameSite=Lax` because the return leg is a top-level cross-site GET — the one navigation `Lax`
     * permits — while `Strict` would drop it and refuse every legitimate handshake, and `None` would
     * hand it to every cross-site request there is.
     *
     * `secure` is left to the session configuration (`SESSION_SECURE_COOKIE`) rather than forced, so it
     * follows the same rule as every other cookie this application sets: on in production behind HTTPS,
     * off on a developer's plain-HTTP localhost, where forcing it would mean the cookie is never stored
     * and the whole flow refuses itself.
     *
     * It outlives the state by the ledger grace, so that a handshake finishing at the very edge of its
     * TTL fails on EXPIRY — which says something true and actionable — rather than on a cookie that
     * vanished a second earlier and would report the alarming browser mismatch instead.
     */
    public function handshakeCookie(OAuthState $state): Cookie
    {
        if ($state->browserSecret === null) {
            throw new RuntimeException(
                'A handshake cookie can only be minted from a freshly issued state. This one was decoded '
                . 'from the wire, which carries the binding digest and never the secret behind it.'
            );
        }

        return cookie(
            name: self::HANDSHAKE_COOKIE,
            value: $state->browserSecret,
            minutes: (int) max(1, ceil(($this->ttl() + $this->ledgerGrace()) / 60)),
            path: '/',
            domain: null,
            secure: null,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /** The wire form of a minted state — what goes in the `state` query parameter. */
    public function encode(OAuthState $state): string
    {
        $payload = $this->base64UrlEncode((string) json_encode([
            'v' => self::VERSION,
            'jti' => $state->jti,
            'u' => $state->userId,
            'w' => $state->workspaceId,
            'p' => $state->platform->value,
            'iat' => $state->issuedAt->getTimestamp(),
            'exp' => $state->expiresAt->getTimestamp(),
            // THE DIGEST, NEVER `browserSecret`. This string ends up in a platform's access log.
            'bh' => $state->browserHash,
        ], JSON_UNESCAPED_SLASHES));

        return $payload . '.' . $this->base64UrlEncode($this->sign($payload));
    }

    /**
     * REDEEM a state: verify it, consume its single use, and return what it says.
     *
     * Consuming is not separable from verifying, and that is why there is no public `verify()` beside
     * this. A caller that could check a state without spending it would eventually check one, do some
     * work, and check it again — and the second check would pass. One method, one outcome.
     *
     * The browser binding is checked HERE and not by the caller, for the same reason consuming is not
     * separable from verifying: a check a caller can forget is a check that will eventually be forgotten,
     * and this one is the whole of what stands between a leaked URL and a channel somebody else controls
     * appearing in a workspace's destination picker.
     *
     * @param  PublishingPlatform  $expected  the destination whose callback URL this arrived at
     * @param  string|null  $handshake  the {@see HANDSHAKE_COOKIE} secret the browser sent back, if any
     *
     * @throws OAuthStateRejected on every failure, naming which check refused it
     */
    public function consume(string $state, PublishingPlatform $expected, ?string $handshake = null): OAuthState
    {
        $decoded = $this->verify($state);

        if ($decoded->platform !== $expected) {
            throw OAuthStateRejected::platformMismatch();
        }

        // From the SIGNED payload, before the ledger — so an abandoned handshake says `expired` rather
        // than the alarming `already_used`, and so expiry holds even on a cache that was flushed or
        // misconfigured.
        if ($decoded->hasExpired()) {
            throw OAuthStateRejected::expired();
        }

        // SAME BROWSER. The cookie holds the SECRET; the state holds its digest — so what is compared is
        // `sha256(what the browser sent)` against `bh`, and a client that has only the state has nothing
        // to put in the cookie. `hash_equals` because the right-hand side is attacker-supplied, which is
        // the definition of a place not to use `===`. A MISSING cookie fails here too: "no binding" is
        // not a weaker form of "the right binding", and treating it as one would make the whole check
        // optional to anybody who simply declines to send it.
        if ($handshake === null || !hash_equals($decoded->browserHash, hash('sha256', $handshake))) {
            throw OAuthStateRejected::browserMismatch();
        }

        // THE SINGLE USE. `pull` is get-and-forget: whoever gets a non-null value is the one redemption
        // this nonce has, and every later presentation of the same string finds nothing.
        if (Cache::pull(self::CACHE_PREFIX . $decoded->jti) === null) {
            throw OAuthStateRejected::alreadyUsed();
        }

        return $decoded;
    }

    /**
     * Shape and signature. Everything here happens before a single field is trusted.
     *
     * @throws OAuthStateRejected
     */
    private function verify(string $state): OAuthState
    {
        $parts = explode('.', $state);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw OAuthStateRejected::malformed();
        }

        [$payload, $signature] = $parts;

        // THE GATE. Timing-safe, over the exact bytes that were signed.
        if (!hash_equals($this->sign($payload), (string) $this->base64UrlDecode($signature))) {
            throw OAuthStateRejected::badSignature();
        }

        $claims = json_decode((string) $this->base64UrlDecode($payload), true);

        if (!is_array($claims) || ($claims['v'] ?? null) !== self::VERSION) {
            throw OAuthStateRejected::malformed();
        }

        $platform = PublishingPlatform::tryFrom((string) ($claims['p'] ?? ''));

        // A signed payload whose fields are unusable is still MALFORMED rather than a signature failure:
        // it means we minted something wrong, or the version guard above needs bumping. Distinguishing
        // the two is what keeps `bad_signature` meaning "somebody tried".
        if ($platform === null
            || !is_string($claims['jti'] ?? null)
            || !is_string($claims['u'] ?? null)
            || !is_string($claims['w'] ?? null)
            || !is_int($claims['iat'] ?? null)
            || !is_int($claims['exp'] ?? null)
            // An empty `bh` would compare equal to the digest of nothing in particular; requiring it to
            // be a non-empty string keeps "the binding is absent" from ever being a state this can be in.
            || !is_string($claims['bh'] ?? null)
            || $claims['bh'] === ''
        ) {
            throw OAuthStateRejected::malformed();
        }

        return new OAuthState(
            jti: $claims['jti'],
            userId: $claims['u'],
            workspaceId: $claims['w'],
            platform: $platform,
            issuedAt: CarbonImmutable::createFromTimestampUTC($claims['iat']),
            expiresAt: CarbonImmutable::createFromTimestampUTC($claims['exp']),
            browserHash: $claims['bh'],
            // NEVER from the wire. A decoded state can be checked against a cookie and can never mint one.
            browserSecret: null,
        );
    }

    /** Raw HMAC bytes over the encoded payload, under a key derived from APP_KEY for this purpose only. */
    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->signingKey(), true);
    }

    /**
     * The derived signing key.
     *
     * APP_KEY arrives base64-prefixed from `.env`; the raw bytes are what the derivation runs on, so a
     * key stored in either form produces the same result. A missing APP_KEY is a broken installation and
     * would leave every signature computed under an empty string — so it is refused here rather than
     * silently accepted, which would make every forged state valid.
     */
    private function signingKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException(
                'APP_KEY is not set. OAuth state cannot be signed, and an unsigned state is a forgeable '
                . 'claim about which workspace an account belongs to.'
            );
        }

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return hash_hmac('sha256', self::KEY_PURPOSE, $key, true);
    }

    /** Seconds a handshake may stay open. */
    private function ttl(): int
    {
        return max(60, (int) config('publishing.oauth.state_ttl', 600));
    }

    /** Extra seconds the ledger entry — and the browser cookie — outlive the signed payload. */
    private function ledgerGrace(): int
    {
        return max(0, (int) config('publishing.oauth.state_ledger_grace', 120));
    }

    /**
     * base64url, not base64.
     *
     * `+` and `/` are legal base64 and are both meaningful in a URL — `+` is a space to a form decoder,
     * `/` is a path separator to anything that ever splits one. A state that survived our own encoding
     * and then decoded differently on the far side of a platform's redirect would fail its signature
     * check and be indistinguishable from an attack.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
