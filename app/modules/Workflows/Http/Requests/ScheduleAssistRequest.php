<?php

namespace App\Modules\Workflows\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a natural-language schedule-assist request (POST /workflows/schedule-assist).
 *
 * Authorization mirrors the schedule-preview endpoint: any authenticated workspace member may ask
 * (the assist exposes no tenant data — it only maps free text onto the v2 compositional schedule
 * vocabulary, which is not tenant-specific). The vocabulary itself is not served by any endpoint —
 * ScheduleAssistAgent builds its prompt programmatically from the schedule axis enums
 * (ScheduleTimeMode/ScheduleDayMode/ScheduleMonthMode/ScheduleDaySpecial) and ScheduleLimits, so
 * there is nothing left to discover at runtime. Workspace membership is already enforced upstream
 * by ResolveWorkspace, so there is no per-object policy; a guest is stopped by auth:sanctum with a
 * 401.
 *
 * The `prompt` is UNTRUSTED free text — capped in length here, then treated purely as data by the
 * agent (which is instructed to ignore embedded instructions) and never trusted on its way back —
 * WorkflowScheduleAssistService re-validates whatever the model proposes against the SAME v2 rules
 * (WorkflowScheduleRulesValidator) and compiler (WorkflowScheduleCompiler) the write path uses,
 * upgrading a still-legacy `{ family, params }` proposal through LegacyScheduleUpgrader first.
 */
class ScheduleAssistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:500'],
            'tz' => ['nullable', 'timezone'],
        ];
    }
}
