<?php

namespace App\Modules\Bot\Http\Requests;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Rules\BotVisualFile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve one of the bot's generated candidates as its likeness. Owner-only (bot `update`).
 *
 * Two layers guard the file: this request refuses anything the bot does not OWN
 * ({@see BotVisualFile} — no disk-native fallback here, an approved likeness is always the bot's
 * own image), and the service additionally requires it to be one of the CANDIDATES (a reference
 * upload is not an iteration).
 */
class ApproveBotVisualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bot')) ?? false;
    }

    public function rules(): array
    {
        return [
            'file_id' => ['required', 'string', new BotVisualFile($this->bot())],
        ];
    }

    private function bot(): ?Bot
    {
        $bot = $this->route('bot');

        return $bot instanceof Bot ? $bot : null;
    }
}
