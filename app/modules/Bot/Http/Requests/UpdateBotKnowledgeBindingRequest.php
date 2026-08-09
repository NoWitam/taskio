<?php

namespace App\Modules\Bot\Http\Requests;

use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Point a bot at a knowledge base.
 *
 * Authorization is the BOT's `update` ability, not any knowledge ability, and the distinction is the point:
 * choosing what a bot reads is a change to the BOT. A member who may read a base but not edit this bot must
 * not be able to change what it knows, and — conversely — the base's own policy already decides who may
 * read it, one layer down.
 *
 * That the base EXISTS in this workspace is proved by the Knowledge module when the binding is written
 * (a foreign or trashed id is not found, which surfaces as a 404). It is deliberately not re-checked here
 * as an `exists` rule: a validation rule would have to name the table and would then be a second, weaker
 * copy of a tenancy check the model scope already performs.
 */
class UpdateBotKnowledgeBindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bot')) ?? false;
    }

    public function rules(): array
    {
        return [
            'knowledge_base_id' => ['required', 'uuid'],
            'mode' => ['required', 'string', Rule::in(KnowledgeBindingMode::ids())],
        ];
    }

    public function resolvedMode(): KnowledgeBindingMode
    {
        return KnowledgeBindingMode::from($this->string('mode')->value());
    }
}
