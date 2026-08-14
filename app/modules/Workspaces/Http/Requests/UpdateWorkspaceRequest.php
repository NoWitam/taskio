<?php

namespace App\Modules\Workspaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('workspace')) ?? false;
    }

    /**
     * A "clear this setting" control sends an empty string, and the `timezone` rule would reject it as
     * an invalid identifier — so the empty string is normalised to the null that MEANS "inherit" before
     * the rules run. The key stays present either way, which is what tells the DTO this request spoke
     * about the timezone at all.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('timezone') && $this->input('timezone') === '') {
            $this->merge(['timezone' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // The workspace's IANA timezone (R3 Calendar) — the one place it can be set, EXTENDING this
            // endpoint rather than growing a second one beside it. Optional and nullable, and the two
            // are different: absent leaves the stored value alone, explicit null clears it back to
            // inheriting config('app.timezone'). See WorkspaceSettingsDTO.
            //
            // Validated with the framework's `timezone` rule so only a real identifier is ever stored —
            // the read side (CalendarTimezoneResolver) then has nothing to sanitize, and a bad value
            // cannot reach the point where it would throw for every member at once.
            'timezone' => ['sometimes', 'nullable', 'timezone'],
        ];
    }
}
