<?php

namespace Tests\Support;

use App\Modules\Bot\Tools\Support\HostResolver;

/**
 * DNS for the test suite: a fixed map, no network.
 *
 * The `fetch_url` tests used to depend on live DNS, so offline they all failed — and they failed
 * fail-CLOSED, which reads exactly like a real SSRF regression. This replaces the lookup and nothing
 * else: every decision the guard makes about the addresses it gets back is still the guard's.
 *
 * UNKNOWN HOSTS RESOLVE TO NOTHING, which the guard turns into a refusal. That is deliberate — it
 * preserves the `.invalid` case that pins the fail-closed backstop, and it means a test that reaches
 * for a host nobody declared is blocked rather than quietly passing against whatever the internet
 * happens to answer today.
 *
 * The mapped addresses are documentation-range publics (RFC 5737 / the well-known 8.8.8.8), so a test
 * that accidentally connected for real would go nowhere useful.
 */
class FixtureHostResolver implements HostResolver
{
    /** @var array<string, array<int, string>> */
    public array $map = [
        'example.com' => ['93.184.216.34'],
        'example.org' => ['93.184.216.34'],
        'a.example' => ['93.184.216.34'],
        'b.example' => ['93.184.216.34'],
    ];

    /** Point a host somewhere for one test — e.g. at a private range, to exercise the block. */
    public function to(string $host, array $addresses): static
    {
        $this->map[strtolower($host)] = $addresses;

        return $this;
    }

    public function lookup(string $host): array
    {
        return $this->map[strtolower($host)] ?? [];
    }
}
