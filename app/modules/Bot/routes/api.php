<?php

use App\Modules\Bot\Http\Controllers\BotActionController;
use App\Modules\Bot\Http\Controllers\BotController;
use App\Modules\Bot\Http\Controllers\BotInboxController;
use App\Modules\Bot\Http\Controllers\BotToolRegistryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Tool registry discovery for the bot editor (must precede the {bot} resource route).
    Route::get('bots/tool-registry', [BotToolRegistryController::class, 'index'])->name('bots.tool-registry');

    Route::post('bots/{id}/restore', [BotController::class, 'restore'])->name('bots.restore');

    // Bot inbox: operational view + cap-exempt manual retry (B7).
    Route::get('bots/{bot}/inbox', [BotInboxController::class, 'index'])->name('bots.inbox');
    Route::post('bots/{bot}/tasks/{task}/retry', [BotInboxController::class, 'retry'])->name('bots.tasks.retry');

    // Bot-action audit log (read-only): per bot and per task.
    Route::get('bots/{bot}/actions', [BotActionController::class, 'index'])->name('bots.actions.index');
    Route::get('tasks/{task}/bot-actions', [BotActionController::class, 'forTask'])->name('tasks.bot-actions.index');

    Route::resource('bots', BotController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy'])
        ->parameter('bots', 'bot');
});
