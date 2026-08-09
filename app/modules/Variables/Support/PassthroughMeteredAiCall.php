<?php

namespace App\Modules\Variables\Support;

use App\Modules\Variables\Contracts\MeteredAiCall;

/**
 * The shipped DEFAULT for the {@see MeteredAiCall} seam (D7): a pure PASS-THROUGH that runs the call and
 * returns its result, with NO metering, budget, or counting. It exists so the seam is DEFINED and
 * resolvable now — every future AI spend will be wrapped through {@see meter()} — while the real cost meter
 * is built in sub-stage 2 by binding a metering implementation in its place. Nothing about the callers
 * changes when that swap happens.
 */
final class PassthroughMeteredAiCall implements MeteredAiCall
{
    public function meter(string $channel, callable $call): mixed
    {
        return $call();
    }

    public function assertWithinBudget(string $channel, float $projectedCost = 0.0): void
    {
        // No budget, no gate — and nothing for a projection to be measured against either.
    }
}
