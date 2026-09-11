<?php

namespace App\Modules\Publishing\Console;

use App\Modules\Publishing\Console\Concerns\SweepsEveryWorkspace;
use App\Modules\Publishing\Services\PublicationQueueService;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * THE RECOVERY SWEEP: find the publications nobody came back for, then ask the platform what actually
 * happened to them. Scheduled every five minutes, matching every other reaper in the product.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * TWO PASSES IN ONE COMMAND, AND THE ORDER IS THE POINT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *   1. REAP    rows stranded in `publishing` past `stale_after` → `needs_reconcile`.
 *   2. PROBE   every row in `needs_reconcile` → `published`, `failed`, or left exactly where it was.
 *
 * They are one command because pass 2 is the ANSWER to pass 1. A reaper on its own converts a row
 * nobody can act on into a different row nobody can act on: `publishing` and `needs_reconcile` are both
 * uneditable, undeletable and without an automatic exit. Running the probe immediately after means the
 * commonest stranding — a worker killed after the platform accepted the post — resolves ITSELF, in the
 * same pass, into `published` with the real remote id. Splitting them would have left that recovery
 * waiting for somebody to notice a screen.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * NEITHER PASS EVER RETRIES ANYTHING. THAT IS NOT A LIMITATION, IT IS THE DESIGN.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The reaper does not re-dispatch, and the machine would refuse it if it tried: `needs_reconcile` has
 * no edge to `publishing`, ever. The probe does not publish; it READS, and the only two conclusions it
 * may draw are conclusions about evidence — the artifact was found, or the platform proved it absent.
 * An adapter that cannot establish either leaves the row untouched, with no attempt limit and no
 * escalation, because a publication nobody can answer for is honestly in the state that says so.
 *
 * So a doubled post cannot come out of this command. What CAN come out of it is a row that stays
 * `needs_reconcile` for as long as it takes — and that is the correct outcome, not a gap.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * FIVE MINUTES HERE, ONE MINUTE FOR THE DUE SWEEP, AND A COOLDOWN INSIDE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Recovery is not time-critical the way a schedule is: nothing is late because a stranded row was found
 * at 09:05 instead of 09:01. Five minutes matches `bots:reap-stale-runs` and the others, which keeps one
 * cadence to reason about.
 *
 * The probe pass is additionally rate-limited per publication (`reconcile_cooldown`, an hour by
 * default), because a probe is an API call and a row may legitimately sit here forever. Without that,
 * five minutes would mean 288 questions a day about a publication nobody will ever be able to answer
 * for. The manual endpoint bypasses the cooldown entirely — a person asking is not a sweep.
 */
class ReconcilePublicationsCommand extends Command
{
    use SweepsEveryWorkspace;

    protected $signature = 'publishing:reconcile';

    protected $description = 'Park publications stranded in "publishing" and ask the platform about every publication awaiting reconciliation, across the shared DB and every own-database workspace.';

    public function handle(PublicationQueueService $queue, TenantContext $context, TenantManager $tenants): int
    {
        $reaped = 0;
        $totals = ['probed' => 0, 'published' => 0, 'failed' => 0, 'unresolved' => 0];

        $this->acrossEveryWorkspace($context, $tenants, function () use ($queue, &$reaped, &$totals): void {
            $reaped += $queue->reapStalePublishing();

            foreach ($queue->reconcilePending() as $key => $count) {
                $totals[$key] += $count;
            }
        });

        $this->info(sprintf(
            'Reconciliation sweep: %d stranded publication(s) parked; %d probed — %d found published, '
            . '%d proven absent, %d still unresolved.',
            $reaped, $totals['probed'], $totals['published'], $totals['failed'], $totals['unresolved'],
        ));

        return self::SUCCESS;
    }
}
