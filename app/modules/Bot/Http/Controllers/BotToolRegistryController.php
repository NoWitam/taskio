<?php

namespace App\Modules\Bot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bot\Services\BotToolRegistry;
use Illuminate\Http\JsonResponse;

/**
 * Discovery endpoint for the bot editor: the registry tool ids + their availability, so
 * the FE shows only usable tools (it supplies its own i18n labels).
 */
class BotToolRegistryController extends Controller
{
    public function __construct(
        private BotToolRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->registry->catalog()]);
    }
}
