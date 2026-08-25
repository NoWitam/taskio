<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Support\Recurrence\LegacyScheduleUpgrader;
use App\Support\Recurrence\ScheduleCompiler;
use App\Support\Recurrence\ScheduleEngine;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Workflows-facing face of the schedule engine: it arms a MODEL from a cadence, and it claims a
 * due slot race-safely. Both the write path (create/update/status via WorkflowService) and the
 * due-sweep command go through here, so the cadence is interpreted identically everywhere.
 *
 * The ARITHMETIC is not here. Every pure question — the next fire instant, the previous one, the
 * projections, the resolved timezone — is answered by {@see \App\Support\Recurrence\ScheduleEngine},
 * which knows nothing about Workflows and is therefore reusable by the Calendar for recurring events.
 * This class keeps ONLY the three members that touch a Workflow model (arm, isArmable, claimDue) and
 * delegates the rest verbatim, so its callers and their expectations are unchanged.
 *
 * Read the engine's docblock for the cadence semantics: the two compiled kinds, the exclusions
 * post-filter, the weekday/tz conventions and the pinned DST behaviour.
 */
class WorkflowScheduleService
{
    /** The pure cadence arithmetic every method below defers to. */
    private ScheduleEngine $engine;

    /**
     * The compiler and the read-shim are still accepted POSITIONALLY (the shape the sweep-parity test
     * builds a deliberately cold instance from) and handed straight to the engine, which owns them.
     */
    public function __construct(
        ScheduleCompiler $compiler = new ScheduleCompiler,
        LegacyScheduleUpgrader $upgrader = new LegacyScheduleUpgrader,
    ) {
        $this->engine = new ScheduleEngine($compiler, $upgrader);
    }

    /**
     * The next fire instant STRICTLY AFTER $from, computed in the schedule's tz and returned in UTC
     * for storage — or null when the cadence has NO reachable occurrence within the hard limits (an
     * over-constrained exclusion set). Callers must treat null as "leave next_due_at NULL, do not arm".
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     */
    public function nextDueAt(array $schedule, CarbonInterface $from): ?Carbon
    {
        return $this->engine->nextDueAt($schedule, $from);
    }

    /**
     * The next $count fire instants strictly after $from (default now()), ASCENDING and UTC.
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     * @return array<int, Carbon> ascending UTC instants, at most $count
     */
    public function nextOccurrences(array $schedule, int $count, ?CarbonImmutable $from = null): array
    {
        return $this->engine->nextOccurrences($schedule, $count, $from);
    }

    /**
     * ANCHORED preview seam: the $count fire instants surrounding an $anchor — the occurrence AT or
     * BEFORE the anchor first (when one exists), then the strictly-later ones.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<int, Carbon>
     */
    public function occurrencesFrom(array $schedule, CarbonInterface $anchor, int $count): array
    {
        return $this->engine->occurrencesFrom($schedule, $anchor, $count);
    }

    /**
     * The greatest occurrence AT OR BEFORE $anchor (respecting exclusions), or null when none exists
     * within the horizon. Returned in UTC.
     *
     * @param  array<string, mixed>  $schedule
     */
    public function previousOrAtOccurrence(array $schedule, CarbonInterface $anchor): ?CarbonImmutable
    {
        return $this->engine->previousOrAtOccurrence($schedule, $anchor);
    }

    /**
     * Whether a preview of this schedule is only APPROXIMATE. Always FALSE now: every cadence is a
     * calendar-anchored wall-clock grid, so a preview is always exact. Kept (and always false) so the
     * preview response shape is stable for the FE.
     *
     * @param  array<string, mixed>  $schedule  the validated trigger_config.schedule block
     */
    public function isApproximate(array $schedule): bool
    {
        return $this->engine->isApproximate($schedule);
    }

    /**
     * Arm (or clear) a workflow's next_due_at from its cadence. A schedule workflow that is ACTIVE
     * gets its concrete next fire time; any other workflow (non-schedule, or an inactive schedule) is
     * left NULL so the sweep's WHERE never sees it. A cadence with NO reachable occurrence also leaves
     * next_due_at NULL — the workflow simply never fires, without a crash or a busy-loop.
     */
    public function arm(Workflow $workflow, ?CarbonInterface $from = null): void
    {
        if (!$this->isArmable($workflow)) {
            $workflow->next_due_at = null;

            return;
        }

        $schedule = $workflow->trigger_config['schedule'] ?? [];

        $workflow->next_due_at = $this->nextDueAt($schedule, $from ?? Carbon::now());
    }

    /** A workflow is armable iff it is an ACTIVE schedule-triggered workflow. */
    public function isArmable(Workflow $workflow): bool
    {
        return $workflow->trigger_type === WorkflowTriggerType::SCHEDULE
            && $workflow->status === WorkflowStatus::ACTIVE;
    }

    /**
     * Race-safe compare-and-swap claim of a DUE workflow's slot. Advances next_due_at to the next
     * fire time and stamps last_scheduled_run_at in ONE conditional UPDATE that matches ONLY if
     * next_due_at is STILL the value the sweep read ($expectedDueAt) and the workflow is STILL active.
     * Postgres locks the row, so exactly one concurrent sweep flips it (affected=1 => we WON the slot);
     * a rival that already advanced it sees 0 (LOST — skip). This is the second guard beyond
     * `withoutOverlapping`. When the recomputed next time is null (an exclusion set with no further
     * occurrence), the slot is CLEARED on a winning CAS so the workflow stops firing without looping.
     */
    public function claimDue(Workflow $workflow): ?Carbon
    {
        $expectedRaw = $workflow->getRawOriginal('next_due_at');

        if ($expectedRaw === null) {
            return null;
        }

        $next = $this->nextDueAt($workflow->trigger_config['schedule'] ?? [], $workflow->next_due_at);

        $affected = Workflow::withoutGlobalScopes()
            ->whereKey($workflow->id)
            ->where('next_due_at', $expectedRaw)
            ->where('status', WorkflowStatus::ACTIVE->value)
            ->update([
                'next_due_at' => $next,
                'last_scheduled_run_at' => now(),
            ]);

        return $affected === 1 ? $next : null;
    }

    /**
     * The schedule's timezone, defaulting to the app timezone (UTC). Public so the schedule-preview
     * request can fold a wall-clock `anchor` (an ISO-8601 string without an offset) into the same tz.
     */
    public function timezone(array $schedule): string
    {
        return $this->engine->timezone($schedule);
    }
}
