<?php

namespace App\Modules\Bot\Http\Requests;

use App\Modules\Bot\Enums\SlotFillMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates + authorizes a DELEGATE-to-bot request (R2 sub-stage 3). Authorization is the SESSION's `update`
 * ability (creator/owner-only via GenerationSessionPolicy) — delegating changes a session's author + inputs,
 * so it is an owner action, not a bot-owner one. The {session} + {bot} bindings are already workspace-scoped
 * (a foreign id 404s at bind), so a cross-workspace bot/session never reaches here. The session's editable
 * state is guarded in the controller (a `generating` session → 409).
 *
 * Two optional body inputs, both defaulting to the SAFE reading so an existing caller that sends neither keeps
 * today's behavior:
 *   - `auto_generate` — the "gate-przed-wydatkiem" opt-in: leave the session ready-to-review (default) unless
 *     the caller explicitly asks to kick off a run.
 *   - `fill_mode` — the click-time choice of how the bot treats inputs the human already typed
 *     ({@see SlotFillMode}); DEFAULT `gaps`, the non-destructive reading.
 */
class DelegateBotSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('session'));
    }

    public function rules(): array
    {
        return [
            'auto_generate' => ['sometimes', 'boolean'],
            'fill_mode' => ['sometimes', 'string', Rule::in(SlotFillMode::values())],
        ];
    }

    public function autoGenerate(): bool
    {
        return $this->boolean('auto_generate');
    }

    /** The validated fill mode, or the safe default when the caller said nothing. */
    public function fillMode(): SlotFillMode
    {
        $mode = $this->input('fill_mode');

        return SlotFillMode::fromNullable(is_string($mode) ? $mode : null);
    }
}
