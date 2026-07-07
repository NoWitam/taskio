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
