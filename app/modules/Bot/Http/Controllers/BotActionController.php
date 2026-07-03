<?php

namespace App\Modules\Bot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bot\Http\Resources\BotActionResource;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotActionService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BotActionController extends Controller
{
    public function __construct(
        private BotActionService $service,
    ) {}

    /** Cursor-paginated action history for a single bot. */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        return BotActionResource::collection(
            $this->service->indexForBot($bot, $request)
        );
    }

    /** Action history for a single task (task detail / approval view). */
    public function forTask(Request $request, string $task): AnonymousResourceCollection
    {
        $task = Task::withTrashed()->findOrFail($task);

        return BotActionResource::collection(
            $this->service->indexForTask($task, $request)
        );
    }
}
