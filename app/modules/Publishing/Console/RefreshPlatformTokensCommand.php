<?php

namespace App\Modules\Publishing\Console;

use App\Modules\Publishing\Console\Concerns\SweepsEveryWorkspace;
use App\Modules\Publishing\Services\TokenRefresher;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * RENEWS EVERY CREDENTIAL THAT IS ABOUT TO LAPSE, ACROSS EVERY DATABASE.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SHAPE IS THE ESTABLISHED PER-TENANT SWEEP, AND IT IS NOW WRITTEN DOWN ONCE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * One pass on the shared database with no tenant set — which, because `WorkspaceScope` leaves a
 * context-less query unconstrained, covers every shared-database workspace in a single sweep — then one
 * pass per own-database workspace with its connection configured.
 *
 * This command wrote that loop out by hand, and it was the FIRST to learn the two details that make it
 * survive contact with reality: a broken tenant is logged and the sweep CONTINUES, and the context is
 * cleared in a `finally` so a long-lived process never inherits a tenant connection. B3 then added two
 * more sweeps to this module and lifted the shape into {@see SweepsEveryWorkspace} — so this command now
 * uses the trait its own bug reports wrote, which is the point of extracting it. Three copies of a loop
 * whose subtlety is entirely in its error handling is three places for one of them to quietly lose it.
 *
 * The per-tenant log lives in the trait and carries the workspace id, the exception class and its code —
 * never the message. A query exception's message embeds its bindings, and the bindings on this table are
 * ciphertext columns.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT MUST RUN, AND WHY HOURLY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A Meta long-lived token cannot be recovered once it lapses: its renewal exchanges the CURRENT token
 * for a new one, so after sixty days there is nothing left to exchange and only a person at a consent
 * screen can repair the connection. The refresh lead (a day) plus an hourly pass gives twenty-four
 * chances to catch it. Any less frequent and a single failed pass eats a meaningful share of the margin.
 *
 * It is cheap when there is nothing to do: the selection is indexed on (status, expires_at) and matches
 * nothing until a token is within the lead.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A FAILED RENEWAL IS NOT AN ERROR OF THIS COMMAND
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * It is an OUTCOME, already applied by the time the refresher returns: the connection is parked in
 * `needs_reauth` and its scheduled publications are on hold. So the command reports the count and exits
 * SUCCESS. Exiting non-zero for a revoked token would make a monitored cron alert on a user's ordinary
 * decision to disconnect their account, and an alert that fires on normal behaviour is one nobody reads.
 */
class RefreshPlatformTokensCommand extends Command
{
    use SweepsEveryWorkspace;

    protected $signature = 'publishing:refresh-tokens';

    protected $description = 'Renew platform access tokens approaching expiry; park the ones that cannot be renewed';

    public function handle(TenantContext $context, TenantManager $tenants, TokenRefresher $refresher): int
    {
        $refreshed = 0;
        $parked = 0;

        $this->acrossEveryWorkspace($context, $tenants, function () use ($refresher, &$refreshed, &$parked): void {
            $result = $refresher->refreshDue();

            $refreshed += $result['refreshed'];
            $parked += $result['parked'];
        });

        $this->info("Renewed {$refreshed} platform connection(s); {$parked} now need re-authorization.");

        return self::SUCCESS;
    }
}
