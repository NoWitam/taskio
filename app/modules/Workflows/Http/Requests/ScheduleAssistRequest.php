<?php

namespace App\Modules\Workflows\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a natural-language schedule-assist request (POST /workflows/schedule-assist).
 *
 * Authorization mirrors the schedule-family discovery endpoint: any authenticated workspace member
 * may ask (the assist exposes no tenant data — it only maps free text onto the public family
 * vocabulary). Workspace membership is already enforced upstream by ResolveWorkspace, so there is
 * no per-object policy; a guest is stopped by auth:sanctum with a 401.
 *
 * The `prompt` is UNTRUSTED free text — capped in length here, then treated purely as data by the
 * agent (which is instructed to ignore embedded instructions) and never trusted on its way back
 * (the service re-validates whatever the model proposes).
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
