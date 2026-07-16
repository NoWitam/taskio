<?php

namespace Tests\Unit;

use App\Modules\Bot\Tools\Support\SafeUrlGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SSRF guard unit tests. Public URLs pass; localhost, private/reserved ranges (v4+v6),
 * non-http schemes and credential URLs are blocked.
 */
class SafeUrlGuardTest extends TestCase
{
    private SafeUrlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new SafeUrlGuard;
    }

    private function assertBlocked(string $url): void
    {
        try {
            $this->guard->assertSafe($url);
            $this->fail("Expected [{$url}] to be blocked.");
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    private function assertAllowed(string $url): void
    {
        $this->guard->assertSafe($url);
        $this->assertTrue(true);
    }

    public function test_allows_public_https_url(): void
    {
        $this->assertAllowed('https://example.com/page');
        $this->assertAllowed('http://example.org');
    }

    public function test_blocks_non_http_schemes(): void
    {
        $this->assertBlocked('ftp://example.com');
        $this->assertBlocked('file:///etc/passwd');
        $this->assertBlocked('gopher://example.com');
    }

    public function test_blocks_credentials_in_url(): void
    {
        $this->assertBlocked('https://user:pass@example.com');
    }

    public function test_blocks_localhost_and_loopback(): void
    {
        $this->assertBlocked('http://localhost');
        $this->assertBlocked('http://127.0.0.1');
        $this->assertBlocked('http://127.5.5.5');
        $this->assertBlocked('http://[::1]');
    }

    public function test_blocks_private_ipv4_ranges(): void
    {
        $this->assertBlocked('http://10.0.0.1');
        $this->assertBlocked('http://172.16.0.1');
        $this->assertBlocked('http://192.168.1.1');
        $this->assertBlocked('http://169.254.1.1');
        $this->assertBlocked('http://0.0.0.0');
    }

    public function test_blocks_private_and_linklocal_ipv6(): void
    {
        $this->assertBlocked('http://[fc00::1]');
        $this->assertBlocked('http://[fe80::1]');
    }

    public function test_blocks_unresolvable_host(): void
    {
        $this->assertBlocked('http://this-host-should-not-resolve.invalid');
    }

    // --- Hardening pass: previously-bypassable vectors, now blocked BY DESIGN ---------

    /** B1: IPv4-mapped IPv6 must unwrap to its embedded v4 and be blocked. */
    public function test_blocks_ipv4_mapped_ipv6_loopback(): void
    {
        $this->assertBlocked('http://[::ffff:127.0.0.1]');
        $this->assertBlocked('http://[::ffff:7f00:1]'); // hex-mapped form of 127.0.0.1
    }

    /** B2: the bare host "0" normalizes to 0.0.0.0 and is blocked (not via failed DNS). */
    public function test_blocks_zero_host(): void
    {
        $this->assertBlocked('http://0');
        $this->assertBlocked('http://0/path');
    }

    /** N3: decimal/octal/hex encodings of loopback normalize to 127.0.0.1 and are blocked. */
    public function test_blocks_numeric_loopback_encodings(): void
    {
        $this->assertBlocked('http://2130706433');       // decimal 127.0.0.1
        $this->assertBlocked('http://0x7f000001');       // hex 127.0.0.1
        $this->assertBlocked('http://017700000001');     // octal 127.0.0.1
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
        $this->assertBlocked('http://127.0.0.1.');
    }

    /** validate() returns the pinning target for a public host. */
    public function test_validate_returns_pinning_target(): void
    {
        $pin = $this->guard->validate('https://example.com/path');

        $this->assertSame('example.com', $pin['host']);
        $this->assertSame(443, $pin['port']);
        $this->assertNotEmpty($pin['ip']);
        $this->assertNotFalse(filter_var($pin['ip'], FILTER_VALIDATE_IP));
    }

    /** validate() honours an explicit port for the pin. */
    public function test_validate_uses_explicit_port(): void
    {
        $pin = $this->guard->validate('http://example.com:8080/');

        $this->assertSame(8080, $pin['port']);
    }
}
