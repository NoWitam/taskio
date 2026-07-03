<?php

namespace App\Modules\Bot\Http\Requests;

use App\Modules\Bot\Enums\BotStatus;
use App\Modules\Bot\Enums\BotTool;
use App\Modules\Bot\Models\Bot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Bot::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(BotStatus::class)],
            'description' => ['nullable', 'string', 'max:2500'],
            // General-info icon (an icon identifier for the always-visible header).
            'icon' => ['nullable', 'string', 'max:100'],

            // Text module (mandatory — persona drives the character).
            'persona' => ['required', 'string', 'max:10000'],
            'style' => ['nullable', 'string', 'max:5000'],
            'dictionary' => ['nullable', 'array'],
            'dictionary.*' => ['string', 'max:255'],
            'phrases' => ['nullable', 'array'],
            'phrases.*' => ['string', 'max:255'],
            'prohibitions' => ['nullable', 'array'],
            'prohibitions.*' => ['string', 'max:255'],

            // Task-execution module (optional config). knowledge_source removed (B6):
            // the knowledge module replaces it; an old client's value is silently ignored.
            'task_execution' => ['nullable', 'array'],
            'task_execution.enabled' => ['nullable', 'boolean'],
            // Registry-validated: only known tool ids may be granted (no dead options).
            'task_execution.tools' => ['nullable', 'array'],
            'task_execution.tools.*' => ['string', Rule::in(BotTool::ids())],

            // Knowledge module: an explicitly-enabled module holding repeatable
            // { title, content } entries injected into the execution context (only when
            // enabled). Entries capped so a huge knowledge base can't be persisted.
            'knowledge' => ['nullable', 'array'],
            'knowledge.enabled' => ['nullable', 'boolean'],
            'knowledge.entries' => ['nullable', 'array', 'max:50'],
            'knowledge.entries.*.title' => ['required', 'string', 'max:255'],
            'knowledge.entries.*.content' => ['required', 'string', 'max:5000'],
        ];
    }
}
