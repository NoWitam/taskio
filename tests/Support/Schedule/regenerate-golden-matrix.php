<?php

/**
 * REGENERATES the schedule engine's golden matrix from the CURRENT working tree.
 *
 *     php tests/Support/Schedule/regenerate-golden-matrix.php
 *
 * Deliberately NOT a test method and NOT wired into `php artisan test`. The matrix is a gate: its
 * only value is that the numbers in it were produced by the engine BEFORE the extraction, so
 * regenerating it is the one operation that destroys what it protects. It has to be a conscious
 * command a human types, never something a suite run can do by accident.
 *
 * Run it ONLY to create the file for the first time, or after a behaviour change that has been
 * reviewed and accepted on its own merits — and then say so in the commit message, because the diff
 * of this fixture IS the record of which schedules moved.
 */

use App\Modules\Workflows\Services\WorkflowScheduleService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Schedule\ScheduleGoldenMatrix;

require __DIR__ . '/../../../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The same two environment pins the test makes, for the same reason: this repo has no
// `.env.testing`, so neither the app timezone nor "now" can be left to the machine.
config(['app.timezone' => ScheduleGoldenMatrix::APP_TIMEZONE]);
Carbon::setTestNow(Carbon::parse(ScheduleGoldenMatrix::FROZEN_NOW));

$service = new WorkflowScheduleService;

$payload = [
    'meta' => [
        'purpose' => 'Absolute-value characterization of the workflow schedule engine, captured BEFORE '
            . 'its extraction into a shared layer. Expectations only — never recompute these from the engine.',
        'generated_at' => date('c'),
        'git_head' => trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
        'app_timezone' => ScheduleGoldenMatrix::APP_TIMEZONE,
        'frozen_now' => ScheduleGoldenMatrix::FROZEN_NOW,
        'occurrences_from_count' => ScheduleGoldenMatrix::OCCURRENCES_FROM_COUNT,
        'next_occurrences_count' => ScheduleGoldenMatrix::NEXT_OCCURRENCES_COUNT,
        'descriptors' => count(ScheduleGoldenMatrix::descriptors()),
        'anchors' => count(ScheduleGoldenMatrix::anchors()),
    ],
    'per_descriptor' => ScheduleGoldenMatrix::perDescriptor($service),
    'cases' => ScheduleGoldenMatrix::cases($service),
];

Carbon::setTestNow();

/**
 * One line per entry rather than pretty-printed JSON: a moved fire instant then shows up as a
 * one-line diff naming its own case, which is the whole point of reviewing this file.
 */
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

$lines = ['{'];
$lines[] = '"meta": ' . json_encode($payload['meta'], $flags) . ',';

foreach (['per_descriptor', 'cases'] as $sectionIndex => $section) {
    $lines[] = '"' . $section . '": {';
    $entries = [];

    foreach ($payload[$section] as $key => $value) {
        $entries[] = json_encode((string) $key, $flags) . ': ' . json_encode($value, $flags);
    }

    $lines[] = implode(",\n", $entries);
    $lines[] = '}' . ($sectionIndex === 0 ? ',' : '');
}

$lines[] = '}';

$json = implode("\n", $lines) . "\n";

if (json_decode($json, true) === null) {
    fwrite(STDERR, "refusing to write: the generated fixture is not valid JSON\n");
    exit(1);
}

file_put_contents(ScheduleGoldenMatrix::FIXTURE, $json);

printf(
    "wrote %s (%d descriptors, %d anchors, %d cases, %.0f KB)\n",
    ScheduleGoldenMatrix::FIXTURE,
    $payload['meta']['descriptors'],
    $payload['meta']['anchors'],
    count($payload['cases']),
    strlen($json) / 1024,
);
