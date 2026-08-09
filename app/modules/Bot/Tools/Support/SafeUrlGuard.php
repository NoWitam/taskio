<?php

namespace App\Modules\Bot\Tools\Support;

use RuntimeException;

/**
 * SSRF guard for outbound fetches (fetch_url). Standalone and testable so both the
 * initial URL and EVERY redirect target are validated through the same code.
 *
 * Rejects, BY DESIGN (not by accident of failed DNS):
 *   - non-http(s) schemes,
 *   - URLs carrying credentials (user:pass@),
 *   - the literal hostname "localhost",
 *   - numeric hosts in ANY form (dotted, decimal, octal, hex, IPv6, v4-mapped-v6)
 *     that normalize to a private / reserved / loopback / link-local address,
 *   - domain names that RESOLVE to any such address.
 *
 * Hardening notes:
 *   - Every IP is canonicalized via inet_pton before range checks, so v4-mapped IPv6
 *     (::ffff:127.0.0.1) is unwrapped and re-checked as its embedded v4 (B1).
 *   - Numeric hosts (0, 2130706433, 0x7f000001, 017700000001) are normalized to a
 *     canonical IP and treated as literals — blocked explicitly, not via failed DNS (B2/N3).
 *   - Trailing-dot hostnames (example.com.) are canonicalized before DNS (N1).
 *   - assertSafe() also RETURNS the validated IP so the caller can PIN it into the
 *     connection (CURLOPT_RESOLVE), closing the DNS-rebinding TOCTOU window (B3).
 *
 * Allows normal public http/https URLs.
 */
class SafeUrlGuard
{
    /**
     * $resolver is the DNS seam only ({@see HostResolver}). NULL — every production call site — resolves
     * it from the container, which binds {@see SystemHostResolver}, so the live behaviour is byte-for-byte
     * what it was before the seam existed. A concrete fallback is kept for the case where the guard is
     * constructed outside a booted container, so `new SafeUrlGuard` never silently loses its resolution.
     */
    public function __construct(
        private ?HostResolver $resolver = null,
    ) {}

    private function resolver(): HostResolver
    {
        return $this->resolver ??= app()->bound(HostResolver::class)
            ? app(HostResolver::class)
            : new SystemHostResolver;
    }

    /**
     * Validate the URL and return the pinning target so the caller connects to exactly
     * what was validated.
     *
     * @return array{host: string, ip: string, port: int} the canonical host, the single
     *                                                    validated IP to pin, and the effective port.
     *
     * @throws RuntimeException with an instructive message when the URL is unsafe.
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Nieprawidłowy adres URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Dozwolone są tylko adresy http/https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Adres URL nie może zawierać danych logowania.');
        }

        $host = $this->canonicalizeHost($parts['host']);

        if (strtolower($host) === 'localhost') {
            throw new RuntimeException('Adres wskazuje na host lokalny — zablokowano.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        // Resolve the host to its IP(s), validate ALL of them, and keep the first as the
        // pinning target. A host that is itself a numeric/IP literal resolves to one IP.
        $ips = $this->resolveAddresses($host);

        foreach ($ips as $ip) {
            if ($this->isBlockedAddress($ip)) {
                throw new RuntimeException('Adres wskazuje na zakres prywatny/zarezerwowany — zablokowano.');
            }
        }

        return ['host' => $host, 'ip' => $ips[0], 'port' => (int) $port];
    }

    /**
     * Back-compat guard entry point that only asserts safety (no pinning). Prefer
     * validate() when the caller pins the connection.
     *
     * @throws RuntimeException when unsafe.
     */
    public function assertSafe(string $url): void
    {
        $this->validate($url);
    }

    /**
     * Canonicalize the host: strip IPv6 brackets and a trailing dot (N1), and normalize
     * numeric hosts (decimal/octal/hex/dotted/IPv6) to a canonical IP literal (B2/N3) so
     * they are treated as literals rather than falling through to DNS.
     */
    private function canonicalizeHost(string $host): string
    {
        $host = trim($host, '[]');
        $host = rtrim($host, '.'); // trailing-dot FQDN (example.com. / 127.0.0.1.)

        // Already a valid IP literal (v4 or v6)?
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // Numeric host in a non-dotted form (decimal 2130706433, hex 0x7f000001,
        // octal 017700000001) → normalize to the canonical dotted-quad if it maps to a
        // 32-bit value. This makes such loopback/private encodings blockable literals.
        $asInt = $this->numericHostToInt($host);
        if ($asInt !== null) {
            return long2ip($asInt);
        }

        return $host;
    }

    /**
     * Interpret a bare-number host as a 32-bit IPv4 integer. Handles decimal, 0x-hex and
     * 0-octal. Returns null when the host is not a single integer literal.
     */
    private function numericHostToInt(string $host): ?int
    {
        if (!preg_match('/^(0x[0-9a-f]+|0[0-7]*|[1-9][0-9]*)$/i', $host)) {
            return null;
        }

        // intval with base 0 honours the 0x / 0 prefixes.
        $value = intval($host, 0);

        if ($value < 0 || $value > 0xFFFFFFFF) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<int, string> the canonicalized IP addresses to validate for the host
     *                            (the host itself if it is already an IP literal).
     */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$this->canonicalizeIp($host)];
        }

        // The LOOKUP is the only part behind a seam ({@see HostResolver}); every DECISION below and
        // above stays here. Extracted so the fetch_url tests stop depending on live DNS — offline they
        // failed fail-CLOSED, which is indistinguishable from a real security regression at a glance.
        $ips = $this->resolver()->lookup($host);

        // If the host cannot be resolved at all, block by default (fail closed) — this is
        // a backstop, NOT the primary defense (numeric forms are already normalized).
        if ($ips === []) {
            throw new RuntimeException('Nie udało się rozwiązać nazwy hosta — zablokowano.');
        }

        return array_map(fn (string $ip) => $this->canonicalizeIp($ip), $ips);
    }

    /**
     * Canonicalize an IP via inet_pton/inet_ntop, and unwrap a v4-mapped IPv6 address
     * (::ffff:a.b.c.d, incl. the hex form ::ffff:7f00:1) to its embedded IPv4 so the v4
     * range checks apply (B1).
     */
    private function canonicalizeIp(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return $ip; // not a valid IP; caller treats it as blocked via isBlockedAddress
        }

        // 16-byte (IPv6) packed value whose first 12 bytes are the v4-mapped prefix
        // (::ffff:) — extract the trailing 4 bytes as an IPv4 address.
        if (strlen($packed) === 16) {
            $v4MappedPrefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
            if (str_starts_with($packed, $v4MappedPrefix)) {
                return inet_ntop(substr($packed, 12));
            }
        }

        return inet_ntop($packed);
    }

    /**
     * Reject private / reserved / loopback / link-local addresses. Uses PHP's built-in
     * range flags (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7,
     * fe80::/10 and other reserved blocks) plus explicit guards for the unspecified
     * addresses. Input is expected to be already canonicalized.
     */
    private function isBlockedAddress(string $ip): bool
    {
        if ($ip === '0.0.0.0' || $ip === '::' || $ip === '::1') {
            return true;
        }

        // A non-canonicalizable value never reached here as a real IP — block it.
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Valid public unicast addresses survive this filter; anything private or
        // reserved is rejected (returns false -> blocked).
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isPublic === false;
    }
}
