<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Stale-claim reaper: recover bot runs stranded in `running` by a dead worker. Guards the
// async queue (a SIGKILL/OOM bypasses the job's failed() hook). Requires `schedule:run`
// on cron; harmless under a sync queue where runs can't strand.
Schedule::command('bots:reap-stale-runs')->everyFiveMinutes()->withoutOverlapping();

// Stale-claim reaper for WORKFLOW runs: recover runs stranded in `running` by a dead
// worker (same guard as the bot reaper — a SIGKILL/OOM bypasses the job's failed() hook).
Schedule::command('workflows:reap-stale-runs')->everyFiveMinutes()->withoutOverlapping();

// Schedule forward-sweep: fire schedule-triggered workflows whose next_due_at has arrived.
// The ONLY path that starts a schedule run. withoutOverlapping is the first concurrency
// guard; the per-workflow compare-and-swap claim in the sweep is the second (fires once even
// if two sweeps overlap). Requires `schedule:run` on cron.
Schedule::command('workflows:run-scheduled')->everyMinute()->withoutOverlapping();

// Disk housekeeping: delete uploads that were never attached or placed (an abandoned dropzone
// leaves a row + its bytes behind). Hourly is plenty — the retention window is measured in
// days, so this only has to run often enough that nothing piles up.
Schedule::command('disk:prune-temp-files')->hourly()->withoutOverlapping();

// Stale-edit reaper for async Disk AI image edits: fail edits stranded in queued/processing by a
// dead worker (a SIGKILL/OOM bypasses the job's failed() hook) and prune old terminal rows. Every
// five minutes matches the other reapers; harmless under a sync queue where edits can't strand.
Schedule::command('disk:reap-stale-ai-edits')->everyFiveMinutes()->withoutOverlapping();

// Draft reaper for per-user Disk file-edit autosaves: prune drafts (row + storage dir) past their
// 24h retention window, so an abandoned autosave never lingers with its base blobs. Every ten
// minutes — the retention window is measured in hours, so this only has to run often enough to keep
// nothing piling up.
Schedule::command('disk:reap-stale-drafts')->everyTenMinutes()->withoutOverlapping();

// Lifecycle reaper for generation SESSIONS: recover sessions stranded in `generating` by a dead worker
// (a SIGKILL/OOM bypasses the job's failed() hook), trash non-archived idle sessions, and purge old
// trashed ones (force-delete + produced-image blob GC). Every five minutes matches the other reapers so
// a stale run recovers quickly; the day/week/month retention windows make the trash/purge passes cheap
// no-ops most of the time. Archived sessions are exempt. Harmless under a sync queue.
Schedule::command('generator:reap-sessions')->everyFiveMinutes()->withoutOverlapping();

// Knowledge catch-up sweep: dispatch indexing jobs for entries whose embeddings are out of date. The
// entry observer is the fast path; this is the GUARANTEE, and it is the ONLY thing that notices a
// change with no write behind it — bumping `knowledge.chunking.version` or swapping the embedding
// model restales every entry at once. Also how a budget-refused (`pending_budget`/`partial`) or
// provider-failed entry gets finished once the condition clears. Every ten minutes: indexing is not
// interactive, and the per-workspace batch cap means a backlog drains over several passes anyway.
Schedule::command('knowledge:sweep-index')->everyTenMinutes()->withoutOverlapping();

// Stale-claim reaper for knowledge indexing: release entries stranded in `indexing` by a dead worker
// (a SIGKILL/OOM bypasses the job's failed() hook). Load-bearing rather than cosmetic — the sweep
// SKIPS claimed entries, so an abandoned claim would remove that entry from the catch-up path
// permanently. Every five minutes matches the other reapers.
Schedule::command('knowledge:reap-stale-index')->everyFiveMinutes()->withoutOverlapping();

// Abandonment reaper for AI DRAFTING SESSIONS: purge sessions nobody came back to (their drafts and the
// raw material that was pasted into them) past `knowledge.drafting.abandon_after_days`. Unlike the
// reapers above it recovers nothing — it DELETES USER DATA, and that is exactly why it has to be
// scheduled: drafts are invisible by design, so an abandoned session is invisible clutter that no human
// will ever notice and clean up, holding whatever document somebody pasted in. DAILY, not every five
// minutes: the window is fourteen days, so a pass an hour would be fourteen thousand no-ops for every
// one that does something, and this pass walks every own-database workspace.
Schedule::command('knowledge:reap-draft-sessions')->daily()->withoutOverlapping();

// Publishing token renewal: refresh platform access tokens BEFORE they expire, and park the ones that
// cannot be renewed (which holds their scheduled publications instead of letting each fail at its own
// appointed minute). Hourly, and the frequency is set by the platform that cannot be recovered after the
// fact: a Meta long-lived token renews by exchanging the CURRENT token, so once its sixty days lapse
// there is nothing left to exchange and only a person at a consent screen can repair the connection. A
// day of lead plus twenty-four passes is the margin. Cheap when idle — the selection is indexed and
// matches nothing until a token is within the lead. Requires `schedule:run` on cron.
//
// The publishing entries give withoutOverlapping an EXPIRY, deviating from this file's bare convention
// on purpose: the default mutex lives 24 hours, and a schedule:run killed mid-command (deploy, OOM)
// would otherwise silently stop ALL publishing — or eat the token-renewal margin the paragraph above
// calls load-bearing — for a day. The expiry is sized to each cadence; an overlap this permits is
// harmless, because every row-level write below is a conditional claim.
Schedule::command('publishing:refresh-tokens')->hourly()->withoutOverlapping(30);

// Publishing due sweep: the ONLY path that starts a publish. Claims every publication whose armed
// moment has arrived and hands it to a worker. Every minute, because a minute is the resolution people
// schedule at and anything slower turns "publish at 09:00" into "some time after 09:00". Cheap when
// idle — the selection rides the (workspace_id, status, scheduled_at) index and matches nothing until
// something is due. withoutOverlapping is only the FIRST guard: it bounds this command against itself
// on one host, while the per-row conditional claim (scheduled -> publishing, affected=0 means somebody
// else has it) is what holds against a second host or a manual run. Requires `schedule:run` on cron.
Schedule::command('publishing:dispatch-due')->everyMinute()->withoutOverlapping(5);

// Publishing recovery sweep: park publications stranded in `publishing` by a dead worker (a SIGKILL/OOM
// bypasses the job's failed() hook, and `publishing` has no automatic exit — the row would be beyond
// every affordance the product offers), then ASK THE PLATFORM about everything in `needs_reconcile`.
// The probe is read-only and safe; it is the retry that never happens automatically, because a
// publication that may already be live must never be published a second time. Five minutes matches the
// other reapers; the per-publication probe cooldown (publishing.queue.reconcile_cooldown) keeps a row
// nobody can answer for from becoming 288 API calls a day.
Schedule::command('publishing:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
