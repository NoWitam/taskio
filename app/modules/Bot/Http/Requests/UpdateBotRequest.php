<?php

namespace App\Modules\Bot\Http\Requests;

/**
 * Update shares the Store validation rules; only the authorization target
 * differs (an existing bot resolved from the route).
 */
class UpdateBotRequest extends StoreBotRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('bot'));
    }
}
