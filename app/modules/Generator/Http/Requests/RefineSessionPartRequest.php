<?php

namespace App\Modules\Generator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an instructed REFINE of one session part (R2 sub-stage 2d). Authorization is creator-only
 * ({@see \App\Modules\Generator\Policies\GenerationSessionPolicy::update}, the same gate the generate /
 * regenerate actions use). The free-text `instruction` is trimmed before validation so a blank / whitespace-
 * only instruction is a 422 (there is nothing to refine by). The instruction is user DATA — it is passed into
 * the refine op as data to write about (injection-hardened) and is NEVER logged.
 */
class RefineSessionPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('session')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $instruction = $this->input('instruction');

        if (is_string($instruction)) {
            $this->merge(['instruction' => trim($instruction)]);
        }
    }

    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:2000'],
        ];
    }

    /** The validated, trimmed instruction. */
    public function instruction(): string
    {
        return (string) $this->input('instruction');
    }
}
