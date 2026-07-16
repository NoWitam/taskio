<?php

namespace App\Modules\Bot\Http\Requests;

use App\Modules\Bot\Enums\BotStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Toggle a bot's status (active|inactive). Authorization is creator-only (mirrors
 * update) via BotPolicy::changeStatus.
 */
class ChangeBotStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('bot'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(BotStatus::class)],
        ];
    }
}
