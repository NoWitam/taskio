<?php

namespace App\Modules\Bot\Http\Requests;

use App\Modules\Bot\Enums\BotTool;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Rules\BotVisualFile;
use App\Modules\Bot\Services\BotVisualIdentityService;
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
            // status is NOT accepted here — a bot is created inactive and toggled via
            // PATCH /bots/{bot}/status only. Any status sent in the body is ignored.
            'description' => ['nullable', 'string', 'max:2500'],
            // General-info icon (an icon identifier for the always-visible header).
            'icon' => ['nullable', 'string', 'max:100'],

            // Text module (mandatory — persona drives the character).
            'persona' => ['required', 'string', 'max:10000'],
            'style' => ['nullable', 'string', 'max:5000'],
            // dictionary: word/expression + what it means (the bot's slang / "gwara").
            'dictionary' => ['nullable', 'array', 'max:100'],
            'dictionary.*.term' => ['required', 'string', 'max:255'],
            'dictionary.*.meaning' => ['required', 'string', 'max:500'],
            // phrases: signature catchphrase/hook + optional context.
            'phrases' => ['nullable', 'array', 'max:100'],
            'phrases.*.phrase' => ['required', 'string', 'max:255'],
            'phrases.*.context' => ['nullable', 'string', 'max:500'],
            // prohibitions: plain list of topics/behaviours to avoid (unchanged).
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

            // Visual module: the bot's LOOK. Like `knowledge` it is an explicitly-enabled module —
            // and like `task_execution` an ABSENT key leaves the stored module untouched (see
            // BotDTO::normalizeVisual), so a client that knows nothing about `visual` can PUT a bot
            // without wiping the identity the async generator files onto it.
            'visual' => ['nullable', 'array'],
            'visual.enabled' => ['nullable', 'boolean'],
            // Deliberately SHORT: it is one line of a composed subject, not a second persona.
            'visual.descriptor' => ['nullable', 'string', 'max:240'],
            'visual.aesthetic' => ['nullable', 'string', 'max:2000'],
            // The steerable outfit — the only real defense against the provider's output-side
            // moderation wall, so it is its own field rather than prose inside `aesthetic`.
            'visual.wardrobe' => ['nullable', 'string', 'max:500'],
            'visual.prohibitions' => ['nullable', 'array', 'max:50'],
            'visual.prohibitions.*' => ['string', 'max:255'],
            // File ids are validated for OWNERSHIP, not shape: candidates and the approved likeness
            // must belong to THIS bot; the (re)generation source may also be a disk-native file the
            // user picked from their Disk. See BotVisualFile.
            'visual.reference_file_id' => ['nullable', 'string', new BotVisualFile($this->visualBot(), allowDiskNative: true)],
            'visual.candidates' => ['nullable', 'array', 'max:' . BotVisualIdentityService::MAX_CANDIDATES],
            'visual.candidates.*' => ['string', new BotVisualFile($this->visualBot())],
            // MEMBERSHIP, not just ownership: the approved likeness must be one of the submitted
            // candidates — the same rule the approve endpoint enforces. Without it a hand-crafted PUT
            // could store a canonical the strip does not show (no legitimate flow produces that state:
            // eviction never removes the canonical and its DELETE is refused), and the two write paths
            // would disagree about what an "approved likeness" is.
            'visual.canonical_file_id' => ['nullable', 'string', 'in_array:visual.candidates.*', new BotVisualFile($this->visualBot())],
            'visual.prompt' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The bot the visual file ids must belong to — the route's bot on update, NULL on create
     * (nothing can be owned by a bot that does not exist yet).
     */
    private function visualBot(): ?Bot
    {
        $bot = $this->route('bot');

        return $bot instanceof Bot ? $bot : null;
    }
}
