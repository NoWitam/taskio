<?php

namespace App\Modules\Bot\Http\Requests;

use App\Modules\Bot\Enums\BotVisualMode;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Rules\BotVisualFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Start a visual-identity generation. Authorized as a bot UPDATE (owner-only, via BotPolicy): it
 * spends real provider budget and writes to the bot, so it is not a member-level read.
 *
 * The {bot} binding is workspace-scoped (a foreign id 404s at bind), so a cross-workspace bot never
 * reaches here; the reference file id is separately checked for OWNERSHIP ({@see BotVisualFile}).
 */
class GenerateBotVisualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bot')) ?? false;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(BotVisualMode::values())],

            // Reference mode sources — exactly one of them (enforced in after()).
            // The upload limit mirrors the Disk AI editor's canvas limit.
            'reference' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:25600'],
            'reference_file_id' => ['nullable', 'string', new BotVisualFile($this->bot(), allowDiskNative: true)],

            // A one-off instruction for THIS run ("looking left", "close-up"); the identity itself
            // is read from the saved module, not from the wire.
            'instruction' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Cross-field rules the per-field ones cannot express: a reference run needs exactly one source,
     * and a description run must not smuggle one in.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $sources = (int) $this->hasFile('reference') + (int) filled($this->input('reference_file_id'));

                if ($this->input('mode') === BotVisualMode::Reference->value && $sources !== 1) {
                    $validator->errors()->add('reference', __('bot.visual.reference_required'));
                }
            },
        ];
    }

    private function bot(): ?Bot
    {
        $bot = $this->route('bot');

        return $bot instanceof Bot ? $bot : null;
    }
}
