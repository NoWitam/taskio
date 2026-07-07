<?php

namespace App\Modules\Bot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bot\DTOs\BotDTO;
use App\Modules\Bot\Enums\BotStatus;
use App\Modules\Bot\Http\Requests\ChangeBotStatusRequest;
use App\Modules\Bot\Http\Requests\StoreBotRequest;
use App\Modules\Bot\Http\Requests\UpdateBotRequest;
use App\Modules\Bot\Http\Resources\BotListResource;
use App\Modules\Bot\Http\Resources\BotResource;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BotController extends Controller
{
    public function __construct(
        private BotService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Bot::class);

        return BotListResource::collection(
            $this->service->index($request)
        );
    }

    public function store(StoreBotRequest $request): BotResource
    {
        return BotResource::make(
            $this->service->create(BotDTO::fromRequest($request))
                ->loadMissing('creator')
        );
    }

    public function show(Bot $bot): BotResource
    {
        $this->authorize('view', $bot);

        return BotResource::make($bot->loadMissing('creator'));
    }

    public function update(UpdateBotRequest $request, Bot $bot): BotResource
    {
        return BotResource::make(
            $this->service->update($bot, BotDTO::fromRequest($request))
                ->loadMissing('creator')
        );
    }

    public function changeStatus(ChangeBotStatusRequest $request, Bot $bot): BotResource
    {
        return BotResource::make(
            $this->service->changeStatus($bot, $request->enum('status', BotStatus::class))
                ->loadMissing('creator')
        );
    }

    public function destroy(Bot $bot): JsonResponse
    {
        $this->authorize('delete', $bot);

        $this->service->delete($bot);

        return response()->json(['message' => 'Bot deleted successfully']);
    }

    public function restore(string $id): BotResource
    {
        $bot = Bot::withTrashed()->findOrFail($id);

        $this->authorize('restore', $bot);

        return BotResource::make(
            $this->service->restore($bot)->loadMissing('creator')
        );
    }
}
