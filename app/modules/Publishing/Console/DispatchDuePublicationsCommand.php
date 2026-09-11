<?php

namespace App\Modules\Publishing\Console;

use App\Modules\Publishing\Console\Concerns\SweepsEveryWorkspace;
use App\Modules\Publishing\Services\PublicationQueueService;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * THE ONLY PATH THAT STARTS A PUBLISH. Scheduled every minute.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A `scheduled_at` IS NOT A CRON ENTRY, AND THIS COMMAND IS WHY THAT SENTENCE IS TRUE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The publications table triggers nothing and executes nothing by itself — the fence `CalendarEvent`
 * carries, restated on the model. A publication goes out because THIS sweep read it, claimed it and
 * handed it to a worker. That is what keeps the module's one irreversible act reachable from exactly
 * one place, and it is why there is no second entrance: no observer, no model event, no scheduler
 * entry per row.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * EVERY MINUTE, AND WHAT THAT COSTS WHEN THERE IS NOTHING TO DO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A minute is the resolution people schedule at, and a slower cadence would make "publish at 09:00"
 * mean "some time after 09:00" for no benefit. The pass is cheap when idle: the selection is
 * `status = scheduled AND scheduled_at <= now()`, served by the `(workspace_id, status, scheduled_at)`
 * index the table already carries, and it matches nothing until something is due.
 *
 * `withoutOverlapping` on the schedule entry is the FIRST concurrency guard and the weaker one — it
 * bounds this command against itself, on one host. The real guard is the per-row conditional claim in
 * {@see PublicationQueueService::dispatchDue()}, which holds against a second host, a manual invocation
 * and a pass that outlived its lock. Exactly the two-guard arrangement `workflows:run-scheduled` uses.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT EXITS SUCCESS EVEN WHEN PUBLICATIONS FAILED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication that cannot be dispatched, or one another sweep claimed first, is an OUTCOME already
 * recorded on the row — not an error of this command. Exiting non-zero would make a monitored cron
 * alert on ordinary operation, and an alert that fires on normal behaviour is one nobody reads. The
 * same posture `publishing:refresh-tokens` takes for a revoked token.
 */
class DispatchDuePublicationsCommand extends Command
{
    use SweepsEveryWorkspace;

    protected $signature = 'publishing:dispatch-due';

    protected $description = 'Claim every publication whose moment has arrived and hand it to a worker, across the shared DB and every own-database workspace.';

    public function handle(PublicationQueueService $queue, TenantContext $context, TenantManager $tenants): int
    {
        $totals = ['claimed' => 0, 'lost' => 0, 'undispatchable' => 0];

        $this->acrossEveryWorkspace($context, $tenants, function () use ($queue, &$totals): void {
            foreach ($queue->dispatchDue() as $key => $count) {
                $totals[$key] += $count;
            }
        });

        $this->info(sprintf(
            'Due sweep: %d claimed and dispatched, %d already claimed elsewhere, %d could not be dispatched.',
            $totals['claimed'], $totals['lost'], $totals['undispatchable'],
        ));

        return self::SUCCESS;
    }
}
