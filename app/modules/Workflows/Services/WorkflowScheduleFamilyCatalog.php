<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowScheduleFamily;

/**
 * Discovery catalog for the schedule-family vocabulary: the family ids plus each family's
 * per-param descriptors, derived from the ONE source of truth (WorkflowScheduleFamily::
 * paramDescriptors). The /workflows/meta/schedule-families endpoint returns this verbatim, so
 * the FE schedule builder AND the future AI-assist batch consume a single contract that cannot
 * drift from what StoreWorkflowRequest accepts or the compiler understands.
 *
 * Labels are intentionally NOT included — the FE supplies its own i18n (mirrors the bot tool
 * registry catalog). Each entry is `{ family, params: [{name, type, required, min?, max?}] }`.
 */
class WorkflowScheduleFamilyCatalog
{
    /**
     * @return array<int, array{family: string, params: array<int, array{name: string, type: string, required: bool, min?: int, max?: int}>}>
     */
    public function all(): array
    {
        return array_map(
            fn (WorkflowScheduleFamily $family) => [
                'family' => $family->value,
                'params' => $family->paramDescriptors(),
            ],
            WorkflowScheduleFamily::cases(),
        );
    }
}
