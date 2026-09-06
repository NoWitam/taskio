<?php

namespace App\Modules\Publishing\Console;

use App\Modules\Publishing\Services\TokenRefresher;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RENEWS EVERY CREDENTIAL THAT IS ABOUT TO LAPSE, ACROSS EVERY DATABASE.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SHAPE IS THE ESTABLISHED PER-TENANT SWEEP
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * One pass on the ACTIVE connection with no tenant set — which, because `WorkspaceScope` leaves a
 * context-less query unconstrained, covers every shared-database workspace in a single sweep — then one
 * pass per own-database workspace with its connection configured. Copied from
 * `ReapAbandonedDraftSessionsCommand`, including the two details that make it survive contact with
 * reality: a broken tenant is logged and the sweep CONTINUES, and the context is cleared at the end so a
 * long-lived process does not inherit a tenant connection.
 *
 * The per-tenant log carries the workspace id, the exception class and its code — never the message. A
 * query exception's message embeds its bindings, and the bindings on this table are ciphertext columns.
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
    protected $signature = 'publishing:refresh-tokens';

    protected $description = 'Renew platform access tokens approaching expiry; park the ones that cannot be renewed';

    public function handle(TenantContext $context, TenantManager $tenants, TokenRefresher $refresher): int
    {
        $refreshed = 0;
        $parked = 0;

        try {
            // The shared-database pass. With no active workspace the scope is inert, so this is every
            // shared workspace at once rather than one query per workspace.
            $result = $refresher->refreshDue();
            $refreshed += $result['refreshed'];
            $parked += $result['parked'];

            $ownWorkspaces = Workspace::query()
                ->where('db_mode', WorkspaceDbMode::Own)
                ->where('status', WorkspaceStatus::Ready)
                ->get();

            foreach ($ownWorkspaces as $workspace) {
                try {
                    $context->set($workspace);
                    $tenants->configure($workspace);

                    $result = $refresher->refreshDue();
                    $refreshed += $result['refreshed'];
                    $parked += $result['parked'];
                } catch (Throwable $e) {
                    // One broken tenant must not stop the sweep — every workspace behind it still has
                    // credentials expiring. Class + code only: a query exception's message carries its
                    // bindings, and on this table those are ciphertext columns.
                    Log::error('Publishing token refresh failed for workspace; continuing.', [
                        'workspace_id' => $workspace->id,
                        'exception' => $e::class,
                        'code' => $e->getCode(),
                    ]);
                }
            }
        } finally {
            // ALWAYS, the shape `PlatformOAuthCallbackController` already uses. The inner `catch` covers
            // a tenant that breaks DURING its own pass; it does not cover the two statements OUTSIDE the
            // loop — the shared pass and the workspace listing — and an exception from either used to
            // leave the LAST configured tenant connection live on the process. Under a queue worker or a
            // long-lived runtime that is inherited by whatever runs next, which is the quietest possible
            // way for one workspace's data to be written into another's database.
            $context->clear();
            $tenants->forget();
        }

        $this->info("Renewed {$refreshed} platform connection(s); {$parked} now need re-authorization.");

        return self::SUCCESS;
    }
}
