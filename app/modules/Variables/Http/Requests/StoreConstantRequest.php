<?php

namespace App\Modules\Variables\Http\Requests;

use App\Modules\Variables\Models\Constant;
use App\Modules\Variables\Services\ConstantTypeValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates a CONSTANT on create.
 *
 * A constant is a user-authored, workspace-scoped, typed LITERAL constant. Validation splits into:
 *   - identity: `name` (required); `key` — a stable slug used in the `globals.<key>` reference path,
 *     so it must be a SAFE identifier (letters/digits/underscores, not leading-digit) and UNIQUE per
 *     workspace. It may be given explicitly or is slugged from the name (resolvedKey()).
 *   - type: `descriptor` must be a well-formed type from the descriptor system, and `value` must be a
 *     LITERAL matching it. Both are delegated to the shared ConstantTypeValidator (the ONE place
 *     value-vs-descriptor validation lives, shared with any future importer), so the accepted shape can
 *     never drift from what the resolver/catalog understand.
 *
 * The workspace-scoped uniqueness check queries THROUGH the model (WorkspaceScope in shared mode, the
 * tenant connection in own mode), so it is correct in BOTH db_modes without a workspace_id branch.
 */
class StoreConstantRequest extends FormRequest
{
    /** A safe identifier for the `globals.<key>` dotted reference path (read via Arr::get). */
    private const SAFE_KEY = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    public function authorize(): bool
    {
        return $this->user()->can('create', Constant::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The key is optional (slugged from the name when absent); its charset + per-workspace
            // uniqueness are enforced in withValidator() against the RESOLVED key so an explicit key
            // and a slugged one go through the identical gate.
            'key' => ['nullable', 'string', 'max:63'],
            'descriptor' => ['required', 'array'],
            // A value may legitimately be null (a nullable-typed constant); its presence/shape is
            // decided by the type validator against the descriptor, not a flat rule.
            'value' => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateKey($validator);

            // Type-check the descriptor + value only when the descriptor arrived as an array (its
            // own rule reports a non-array); otherwise the type errors would be noise.
            if (is_array($this->input('descriptor'))) {
                app(ConstantTypeValidator::class)->validate(
                    $validator,
                    $this->input('descriptor'),
                    $this->input('value'),
                );
            }
        });
    }

    /**
     * The stable reference key: an explicit `key`, else the name slugged with underscores. Trimmed;
     * empty when neither yields anything usable (reported by validateKey()).
     */
    public function resolvedKey(): string
    {
        $explicit = $this->input('key');

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        return Str::slug((string) $this->input('name'), '_');
    }

    /** Validate the resolved key is a safe identifier and unique within the active workspace. */
    private function validateKey(Validator $validator): void
    {
        $key = $this->resolvedKey();

        if (preg_match(self::SAFE_KEY, $key) !== 1) {
            $validator->errors()->add(
                'key',
                'The key must be a valid identifier (letters, digits and underscores, not starting with a digit). Provide an explicit key.',
            );

            return;
        }

        $exists = Constant::query()
            ->where('key', $key)
            ->when($this->currentConstantId(), fn ($query, string $id) => $query->whereKeyNot($id))
            ->exists();

        if ($exists) {
            $validator->errors()->add('key', 'A constant with this key already exists in the workspace.');
        }
    }

    /** The id of the constant being updated (to exclude itself from the uniqueness check); null on create. */
    protected function currentConstantId(): ?string
    {
        return null;
    }
}
