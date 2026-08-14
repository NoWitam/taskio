<?php

namespace App\Modules\Workspaces\DTOs;

use App\Modules\Workspaces\Http\Requests\UpdateWorkspaceRequest;

/**
 * The editable settings of an existing workspace.
 *
 * PATCH semantics are the whole difficulty here, and they are carried explicitly rather than inferred.
 * `timezone` is nullable AND optional, and those mean different things: an ABSENT key leaves the stored
 * value alone, while an explicit `null` clears it back to "inherit the application timezone". Collapsing
 * the two — the natural thing to do with a plain nullable property — would silently reset every
 * workspace's timezone the first time somebody renamed one from a client that does not send the field.
 */
final readonly class WorkspaceSettingsDTO
{
    private function __construct(
        public ?string $name,
        public ?string $timezone,
        public bool $timezoneProvided,
    ) {}

    public static function fromRequest(UpdateWorkspaceRequest $request): self
    {
        // VALIDATED data, not raw input: a `sometimes` rule that did not fire leaves the key out of this
        // array entirely, which is exactly the absent/present distinction below turns on — and it stays
        // correct on the day an unvalidated key joins the payload.
        $validated = $request->validated();

        return new self(
            // NOT trimmed. The previous rename path wrote the string as given, and quietly changing what
            // a rename stores is not this batch's to do.
            name: array_key_exists('name', $validated) ? (string) $validated['name'] : null,
            // The empty string a "clear this field" control sends is normalised to null in
            // prepareForValidation(), before the `timezone` rule would reject it.
            timezone: $validated['timezone'] ?? null,
            timezoneProvided: array_key_exists('timezone', $validated),
        );
    }

    /**
     * The attributes to write — only the ones this request actually spoke about.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = [];

        if ($this->name !== null) {
            $attributes['name'] = $this->name;
        }

        if ($this->timezoneProvided) {
            $attributes['timezone'] = $this->timezone;
        }

        return $attributes;
    }
}
