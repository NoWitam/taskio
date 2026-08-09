<?php

namespace App\Modules\Bot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bot\Http\Requests\DestroyBotKnowledgeBindingRequest;
use App\Modules\Bot\Http\Requests\MigrateBotKnowledgeRequest;
use App\Modules\Bot\Http\Requests\UpdateBotKnowledgeBindingRequest;
use App\Modules\Bot\Http\Resources\BotResource;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotKnowledgeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a bot READS: its knowledge-base binding, and the one-time lift of its built-in entries into a base.
 *
 * Binding is a PUT rather than a POST because a bot reads exactly one base: "read that one" is the same
 * request whether or not it already read another, and an upsert spares every client the read-then-choose-a-
 * verb dance. Both mutations answer with the whole bot, matching the visual-identity endpoints — the client
 * that changed one field of a bot gets the bot back, and never has to merge a partial response by hand.
 */
class BotKnowledgeController extends Controller
{
    public function __construct(
        private BotKnowledgeService $service,
    ) {}

    /** Bind (or re-bind) this bot to a base. A base outside the workspace is simply not found (404). */
    public function update(UpdateBotKnowledgeBindingRequest $request, Bot $bot): BotResource
    {
        $this->service->bind(
            $bot,
            $request->string('knowledge_base_id')->value(),
            $request->resolvedMode(),
        );

        return BotResource::make($bot->loadMissing('creator'));
    }

    /** Unbind. The bot falls back to its own built-in knowledge module, unchanged. */
    public function destroy(DestroyBotKnowledgeBindingRequest $request, Bot $bot): BotResource
    {
        $this->service->unbind($bot);

        return BotResource::make($bot->loadMissing('creator'));
    }

    /**
     * Create a base from this bot's built-in entries, fill it, bind it. 201 with the base's identity and
     * how many entries were carried over — enough for the client to link straight to the new base, without
     * shipping a second serializer for a resource the Knowledge module already exposes properly.
     */
    public function migrate(MigrateBotKnowledgeRequest $request, Bot $bot): JsonResponse
    {
        $result = $this->service->migrateLegacy($bot);

        return response()->json([
            'knowledge_base_id' => (string) $result['base']->getKey(),
            'name' => $result['base']->name,
            'entries_count' => $result['entries_count'],
            'mode' => $result['binding']->mode->value,
        ], Response::HTTP_CREATED);
    }
}
