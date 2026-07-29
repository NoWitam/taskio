<?php

namespace App\Support\Meter;

use App\Models\User;

/**
 * Resolves the polymorphic ACTOR to attribute one AI spend to, MIRRORING {@see \App\Traits\HasCreator}'s
 * precedence so Disk/Workflow/session spend is attributed by DEFAULT with no per-call wiring:
 *
 *   1. an EXPLICIT actor already tagged on the {@see \App\Modules\Variables\Support\MeterContext}
 *      (the queued session run, or the autonomous bot fill — neither has an auth() or a run of its own);
 *   2. an active WorkflowRun on this worker  -> the run (a system record — workflow ai-text spend);
 *   3. an authenticated user                 -> that user (Disk edit, any request-bound spend);
 *   4. otherwise                             -> null (unattributed).
 *
 * It lives in App\Support (like HasCreator in App\Traits), NOT under app/modules/Variables, precisely so
 * it can name the Workflows run-context binding as a compile-time ::class string without tripping the
 * Variables one-way-boundary scan — the meter (a Variables class) delegates here rather than naming a
 * sibling module. The run lookup is LAZY + guarded (app()->bound) so nothing hard-depends on Workflows
 * being booted, exactly as HasCreator does.
 */
class MeterActorResolver
{
    /**
     * The Workflows run-context binding, referenced as a compile-time ::class string (no `use` import)
     * so this carries no runtime dependency on the module and never autoloads it. Mirrors HasCreator.
     */
    private const WORKFLOW_RUN_CONTEXT = \App\Modules\Workflows\Services\WorkflowRunContext::class;

    /**
     * Resolve [actorType, actorId] for the current spend, given the ambient MeterContext's explicit tag.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function resolve(?string $explicitType, ?string $explicitId): array
    {
        // 1. An explicit MeterContext actor wins. Default the type to the user alias when only an id was
        //    tagged, exactly as HasCreator defaults an explicitly-set id.
        if ($explicitId !== null) {
            return [$explicitType ?? $this->userAlias(), $explicitId];
        }

        // 2. A workflow run is executing on this worker (queue path, usually no auth): attribute to it.
        $run = app()->bound(self::WORKFLOW_RUN_CONTEXT)
            ? app(self::WORKFLOW_RUN_CONTEXT)->current()
            : null;

        if ($run !== null) {
            return [$run->getMorphClass(), $run->getKey()];
        }

        // 3. An authenticated user drove it.
        if (auth()->id() !== null) {
            return [$this->userAlias(), (string) auth()->id()];
        }

        // 4. Unattributed.
        return [null, null];
    }

    /** The registered morph alias for a human actor (kept in sync with the morph map). */
    private function userAlias(): string
    {
        return (new User)->getMorphClass();
    }
}
