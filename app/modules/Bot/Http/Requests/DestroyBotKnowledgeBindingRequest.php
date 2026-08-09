<?php

namespace App\Modules\Bot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stop a bot reading a knowledge base. Same ability as binding it — one decision ("what this bot knows"),
 * so one gate. Idempotent: unbinding a bot that reads nothing is a no-op, not a 404.
 */
class DestroyBotKnowledgeBindingRequest extends FormRequest
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
