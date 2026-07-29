<?php

namespace App\Modules\Workflows\Enums;

/**
 * The answer a {@see \App\Modules\Workflows\Contracts\WaitResolver} gives about the external work a
 * `waiting` run is parked on. Deliberately THREE outcomes, because the engine reacts differently to
 * each and collapsing them would strand or wrongly fail runs:
 *
 *   PENDING  the work exists and has not finished — leave the run waiting (the stale-waiting
 *            timeout is the last-resort bound).
 *   SETTLED  the work reached a terminal outcome (success OR failure — the STEP decides what that
 *            means) — dispatch the resume job.
 *   GONE     the work no longer exists / can never settle (deleted row, unknown handle) — fail the
 *            run now with a clear message rather than waiting out the timeout.
 */
enum WaitStatus: string
{
    case PENDING = 'pending';
    case SETTLED = 'settled';
    case GONE = 'gone';
}
