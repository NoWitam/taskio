<?php

namespace App\Modules\Bot\Tools\Support;

/**
 * The DNS LOOKUP seam of {@see SafeUrlGuard} — and deliberately nothing more.
 *
 * The guard is an SSRF defence, so the temptation to make it "testable" by widening the seam is
 * exactly the temptation to weaken it. What is behind this interface is only the question "what
 * addresses does this name have"; everything that DECIDES — the numeric-host canonicalisation, the
 * IP-literal short circuit, the private/reserved range checks, and the fail-closed refusal when a name
 * has no addresses at all — stays in the guard, where it cannot be swapped out.
 *
 * WHY IT EXISTS: the guard performed a live `dns_get_record()`, so roughly ten `fetch_url` tests
 * depended on working DNS. Offline — on a plane, in a locked-down CI runner — every one of them failed,
 * and they failed for a reason that had nothing to do with what they were testing. Worse, the failure
 * was fail-CLOSED, so the suite went red in the one way that looks like a real security regression.
 *
 * An empty return is a legitimate answer meaning "this name has no addresses", and the guard turns it
 * into a refusal. An implementation must never throw to signal that.
 */
interface HostResolver
{
    /**
     * The addresses $host resolves to, or an empty array when it resolves to none.
     *
     * @return array<int, string>
     */
    public function lookup(string $host): array;
}
