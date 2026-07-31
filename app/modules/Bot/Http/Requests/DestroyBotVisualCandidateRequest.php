<?php

namespace App\Modules\Bot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delete one of the bot's generated candidates. Owner-only (bot `update`), exactly like its two siblings
 * ({@see GenerateBotVisualRequest}, {@see ApproveBotVisualRequest}) — a FormRequest rather than an inline
 * controller authorize() so all three curation endpoints declare their guard in the same place, and reading
 * one of them tells you the rule for all.
 *
 * NO RULES: the {file} arrives as a workspace-scoped route binding (a foreign id 404s at bind) and the
 * ownership question that remains — is it a CANDIDATE of THIS bot, and is it the approved likeness? — is the
 * service's, which refuses both cases. There is nothing in the BODY to validate.
 */
class DestroyBotVisualCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bot')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
