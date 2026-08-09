<?php

namespace App\Modules\Bot\Tools\Support;

/**
 * The PRODUCTION resolver: the system's own DNS, exactly as {@see SafeUrlGuard} always asked it.
 *
 * Moved here VERBATIM — same functions, same order, same fallback, same suppression operator — so the
 * extraction of the seam changes nothing an attacker could notice. `dns_get_record()` first because it
 * returns both A and AAAA records (an IPv6 address that the guard must also range-check), and
 * `gethostbynamel()` after it because some hardened PHP builds ship without the former.
 *
 * Returning `[]` when nothing resolves is the contract: the REFUSAL is the guard's decision to make,
 * not this class's.
 */
class SystemHostResolver implements HostResolver
{
    public function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        // Fallback for environments where dns_get_record is unavailable/empty.
        if ($ips === []) {
            $resolved = gethostbynamel($host);

            if ($resolved !== false) {
                $ips = $resolved;
            }
        }

        return $ips;
    }
}
