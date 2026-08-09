<?php

namespace App\Modules\Bot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lift a bot's built-in knowledge entries into a real knowledge base and bind it.
 *
 * Gated on the bot's `update` ability rather than on the knowledge base's `create`. It CREATES a base, so
 * the wider gate is tempting — but knowledge-base creation is open to every workspace member by policy
 * ({@see \App\Modules\Knowledge\Policies\KnowledgeBasePolicy::create}), while editing this bot is not. The
 * narrower of the two gates is the honest one, and it is the one that matches what the user is doing:
 * changing this bot.
 *
 * No body. The source is the bot's own column; making the caller restate it would only create a way for
 * the request and the record to disagree.
 */
class MigrateBotKnowledgeRequest extends FormRequest
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
