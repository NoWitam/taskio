<?php

namespace Tests\Unit;

use App\Modules\Bot\Tools\Support\SafeUrlGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FixtureHostResolver;

/**
 * SSRF guard unit tests. Public URLs pass; localhost, private/reserved ranges (v4+v6),
 * non-http schemes and credential URLs are blocked.
 *
 * THIS FILE USED TO BE UNABLE TO FAIL. {@see assertBlocked()} called `$this->fail()` INSIDE a
 * `try { … } catch (RuntimeException)`. PHPUnit's `AssertionFailedError` extends
 * `PHPUnit\Framework\Exception`, which extends `RuntimeException` — so the failure the helper
 * raised landed in the helper's own catch and was answered with `assertTrue(true)`. No URL could
 * make it go red. Measured, not deduced: with the guard's checks removed entirely the file still
 * reported 15 passed / 57 assertions / 0 failures, and the 57 (up from 33) was the assertion
 * counter registering the twenty-four swallowed `fail()` calls. A guard test that cannot go red is
 * worse than no test, because it is counted as a satisfied security requirement.
 *
 * The helper now decides OUTSIDE the catch and matches the REFUSAL REASON, the same shape as
 * {@see \Tests\Feature\CalendarEventRecurrenceTest::withFailingInsert()}, so this repository has one
 * convention for "prove the throw was the one you asked for" rather than two.
 */
class SafeUrlGuardTest extends TestCase
{
    /**
     * The guard's refusal messages, verbatim.
     *
     * MATCHING THE MESSAGE IS THE POINT, not an accident of convenience. The guard refuses for
     * several distinct reasons, and one of them — {@see self::UNRESOLVABLE} — is a fail-closed
     * backstop rather than a decision about the address. A test that only counted throws would
     * certify a defence that never fired: if the numeric-host canonicalisation regressed and
     * `0x7f000001` fell through to DNS, the guard would still throw, and "blocked" would still
     * read green while the normalisation under test was gone. The message is the only signal that
     * separates those, so it is deliberately coupled here; the shared word "zablokowano" is not
     * enough, which is why these are compared whole rather than by fragment.
     */
    private const MALFORMED = 'Nieprawidłowy adres URL.';

    private const SCHEME = 'Dozwolone są tylko adresy http/https.';

    private const CREDENTIALS = 'Adres URL nie może zawierać danych logowania.';

    private const LOCALHOST = 'Adres wskazuje na host lokalny — zablokowano.';

    private const RESERVED_RANGE = 'Adres wskazuje na zakres prywatny/zarezerwowany — zablokowano.';

    private const UNRESOLVABLE = 'Nie udało się rozwiązać nazwy hosta — zablokowano.';

    private SafeUrlGuard $guard;

    private FixtureHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        // DNS is the fixture map, not the internet — the repository's standing rule (see
        // Tests\TestCase). Every verdict below is unchanged by this: the fixture answers with
        // documentation-range publics, unknown names answer with nothing, and every DECISION about
        // those addresses is still the guard's. It also makes the "a name that RESOLVES to a private
        // address" cases expressible at all, which is the guard's headline claim.
        $this->dns = new FixtureHostResolver;
        $this->guard = new SafeUrlGuard($this->dns);
    }

    /**
     * Assert the guard refuses $url, AND refuses it for the reason under test.
     *
     * The verdict is reached after the try/catch, never inside it: nothing that can raise an
     * `AssertionFailedError` runs within the `try`, so the catch has no assertion left to swallow.
     */
    private function assertBlocked(string $url, string $expectedReason): void
    {
        $refusal = null;

        try {
            $this->guard->assertSafe($url);
        } catch (RuntimeException $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertNotNull(
            $refusal,
            "[{$url}] passed the guard — it must be refused."
        );

        $this->assertSame(
            $expectedReason,
            $refusal,
            "[{$url}] was refused, but by a different check than the one under test — "
            . 'the defence this case exists to prove did not fire.'
        );
    }

    private function assertAllowed(string $url): void
    {
        // validate() is the entry point that carries the outcome, so the allowed path asserts a real
        // consequence — a pinnable address — instead of the previous assertTrue(true).
        $pin = $this->guard->validate($url);

        $this->assertNotFalse(
            filter_var($pin['ip'], FILTER_VALIDATE_IP),
            "[{$url}] was allowed without a validated address to pin."
        );
    }

    public function test_allows_public_https_url(): void
    {
        $this->assertAllowed('https://example.com/page');
        $this->assertAllowed('http://example.org');
    }

    public function test_blocks_non_http_schemes(): void
    {
        $this->assertBlocked('ftp://example.com', self::SCHEME);
        $this->assertBlocked('gopher://example.com', self::SCHEME);

        // file:///etc/passwd carries no HOST, so it is refused one step earlier than the other two —
        // by the malformed-URL check, before the scheme is ever judged. Same outcome, different
        // defence; naming it is the difference between a test that knows why it passes and one that
        // does not.
        $this->assertBlocked('file:///etc/passwd', self::MALFORMED);
    }

    public function test_blocks_credentials_in_url(): void
    {
        $this->assertBlocked('https://user:pass@example.com', self::CREDENTIALS);

        // ADDED: the username-only form. `user@host` is the shape that reads as a hostname to a
        // human skimming a log line, and it was the one form of credential URL nothing covered.
        $this->assertBlocked('https://user@example.com', self::CREDENTIALS);
    }

    public function test_blocks_localhost_and_loopback(): void
    {
        $this->assertBlocked('http://localhost', self::LOCALHOST);
        $this->assertBlocked('http://127.0.0.1', self::RESERVED_RANGE);
        $this->assertBlocked('http://127.5.5.5', self::RESERVED_RANGE);
        $this->assertBlocked('http://[::1]', self::RESERVED_RANGE);
    }

    public function test_blocks_private_ipv4_ranges(): void
    {
        $this->assertBlocked('http://10.0.0.1', self::RESERVED_RANGE);
        $this->assertBlocked('http://172.16.0.1', self::RESERVED_RANGE);
        $this->assertBlocked('http://192.168.1.1', self::RESERVED_RANGE);
        $this->assertBlocked('http://169.254.1.1', self::RESERVED_RANGE);
        $this->assertBlocked('http://0.0.0.0', self::RESERVED_RANGE);
    }

    public function test_blocks_private_and_linklocal_ipv6(): void
    {
        $this->assertBlocked('http://[fc00::1]', self::RESERVED_RANGE);
        $this->assertBlocked('http://[fe80::1]', self::RESERVED_RANGE);

        // ADDED: fd00::/8 is the half of fc00::/7 that real deployments actually use; fc00::1 alone
        // left the practical unique-local case unproven.
        $this->assertBlocked('http://[fd00::1]', self::RESERVED_RANGE);

        // ADDED: the unspecified address. The guard names '::' explicitly alongside '0.0.0.0', and
        // only the v4 half of that explicit guard was covered.
        $this->assertBlocked('http://[::]', self::RESERVED_RANGE);
    }

    public function test_blocks_unresolvable_host(): void
    {
        $this->assertBlocked('http://this-host-should-not-resolve.invalid', self::UNRESOLVABLE);
    }

    /**
     * ADDED — THE VECTOR THE GUARD LEADS WITH AND NOTHING HERE EXERCISED.
     *
     * Every other blocked case in this file is a literal: the address is visible in the URL. The
     * attack that motivates an SSRF guard is the one where it is not — an ordinary-looking name
     * whose A record points inside. The guard claims "domain names that RESOLVE to any such
     * address", and until now that claim was tested only indirectly, one layer up, through
     * fetch_url's redirect tests.
     */
    public function test_blocks_a_name_that_resolves_to_a_private_address(): void
    {
        $this->dns->to('intranet.example', ['10.0.0.5']);
        $this->dns->to('rebind.example', ['127.0.0.1']);
        $this->dns->to('metadata.example', ['169.254.169.254']);

        $this->assertBlocked('https://intranet.example/admin', self::RESERVED_RANGE);
        $this->assertBlocked('https://rebind.example/', self::RESERVED_RANGE);
        $this->assertBlocked('https://metadata.example/latest/meta-data/', self::RESERVED_RANGE);
    }

    /**
     * ADDED — a name that answers with several addresses, only some of them public.
     *
     * The guard validates ALL answers and pins the first. Checking only the pinned one would be a
     * silent hole: a host controlled by an attacker can answer with a public address first and a
     * loopback second, and any resolution order is fair game. Both orders are asserted so the test
     * cannot pass by virtue of the private address happening to be looked at first.
     */
    public function test_blocks_a_name_whose_addresses_are_only_partly_public(): void
    {
        $this->dns->to('public-first.example', ['93.184.216.34', '127.0.0.1']);
        $this->dns->to('private-first.example', ['10.1.2.3', '93.184.216.34']);

        $this->assertBlocked('https://public-first.example/', self::RESERVED_RANGE);
        $this->assertBlocked('https://private-first.example/', self::RESERVED_RANGE);
    }

    /** ADDED: input that is not a URL at all reaches the malformed-URL check, not a lookup. */
    public function test_blocks_malformed_urls(): void
    {
        $this->assertBlocked('example.com/path', self::MALFORMED);   // no scheme
        $this->assertBlocked('http://', self::MALFORMED);            // no host
        $this->assertBlocked('', self::MALFORMED);
    }

    // --- Hardening pass: previously-bypassable vectors, now blocked BY DESIGN ---------

    /** B1: IPv4-mapped IPv6 must unwrap to its embedded v4 and be blocked. */
    public function test_blocks_ipv4_mapped_ipv6_loopback(): void
    {
        $this->assertBlocked('http://[::ffff:127.0.0.1]', self::RESERVED_RANGE);
        $this->assertBlocked('http://[::ffff:7f00:1]', self::RESERVED_RANGE); // hex-mapped form of 127.0.0.1
    }

    /**
     * B2: the bare host "0" normalizes to 0.0.0.0 and is blocked (not via failed DNS).
     *
     * "Not via failed DNS" is now enforced rather than asserted in prose: the expected reason is the
     * range check, so a regression that dropped the normalisation and let "0" fall through to a
     * lookup would go red here instead of passing on the fail-closed backstop.
     */
    public function test_blocks_zero_host(): void
    {
        $this->assertBlocked('http://0', self::RESERVED_RANGE);
        $this->assertBlocked('http://0/path', self::RESERVED_RANGE);
    }

    /** N3: decimal/octal/hex encodings of loopback normalize to 127.0.0.1 and are blocked. */
    public function test_blocks_numeric_loopback_encodings(): void
    {
        $this->assertBlocked('http://2130706433', self::RESERVED_RANGE);   // decimal 127.0.0.1
        $this->assertBlocked('http://0x7f000001', self::RESERVED_RANGE);   // hex 127.0.0.1
        $this->assertBlocked('http://017700000001', self::RESERVED_RANGE); // octal 127.0.0.1
    }

    /** N3: a numeric encoding of a PUBLIC address is still allowed (normalization is neutral). */
    public function test_allows_numeric_public_address(): void
    {
        // 134744072 = 8.8.8.8 (public). Normalized to the dotted form and validated.
        $this->assertAllowed('http://134744072');
    }

    /** N1: a trailing-dot FQDN is canonicalized; a public one is still allowed. */
    public function test_allows_public_trailing_dot_host(): void
    {
        $this->assertAllowed('https://example.com.');
    }

    /** N1: a trailing-dot loopback literal is still blocked. */
    public function test_blocks_trailing_dot_loopback(): void
    {
        $this->assertBlocked('http://127.0.0.1.', self::RESERVED_RANGE);
    }

    /** validate() returns the pinning target for a public host. */
    public function test_validate_returns_pinning_target(): void
    {
        $pin = $this->guard->validate('https://example.com/path');

        $this->assertSame('example.com', $pin['host']);
        $this->assertSame(443, $pin['port']);
        // The pin is the RESOLVED address, not the name — that is what closes the rebinding window.
        $this->assertSame('93.184.216.34', $pin['ip']);
    }

    /** validate() honours an explicit port for the pin. */
    public function test_validate_uses_explicit_port(): void
    {
        $pin = $this->guard->validate('http://example.com:8080/');

        $this->assertSame(8080, $pin['port']);
    }

    /**
     * ADDED: a guard built with no resolver still guards.
     *
     * Production call sites do `new SafeUrlGuard`, and the class documents a worry about being
     * constructed outside a booted container. This case is an IP literal, which short-circuits
     * before any lookup, so it pins that construction path without reaching for DNS.
     */
    public function test_a_guard_built_without_an_injected_resolver_still_blocks_literals(): void
    {
        $guard = new SafeUrlGuard;

        try {
            $guard->assertSafe('http://127.0.0.1');
            $refusal = null;
        } catch (RuntimeException $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame(self::RESERVED_RANGE, $refusal);
    }
}
