<?php

use App\Modules\Bot\Http\Controllers\BotActionController;
use App\Modules\Bot\Http\Controllers\BotController;
use App\Modules\Bot\Http\Controllers\BotInboxController;
use App\Modules\Bot\Http\Controllers\BotSessionDelegationController;
use App\Modules\Bot\Http\Controllers\BotToolRegistryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Tool registry discovery for the bot editor (must precede the {bot} resource route).
    Route::get('bots/tool-registry', [BotToolRegistryController::class, 'index'])->name('bots.tool-registry');

    Route::post('bots/{id}/restore', [BotController::class, 'restore'])->name('bots.restore');

    // Status toggle (active|inactive) — the only path that mutates a bot's status.
    Route::patch('bots/{bot}/status', [BotController::class, 'changeStatus'])->name('bots.status');

    // Bot inbox: operational view + cap-exempt manual retry (B7).
    Route::get('bots/{bot}/inbox', [BotInboxController::class, 'index'])->name('bots.inbox');
    Route::post('bots/{bot}/tasks/{task}/retry', [BotInboxController::class, 'retry'])->name('bots.tasks.retry');

    // Bot-action audit log (read-only): per bot and per task.
    Route::get('bots/{bot}/actions', [BotActionController::class, 'index'])->name('bots.actions.index');
    Route::get('tasks/{task}/bot-actions', [BotActionController::class, 'forTask'])->name('tasks.bot-actions.index');

    // Bot ↔ generation-session DELEGATION (R2 sub-stage 3): the ONLY new Bot → Generator edge. Delegate
    // hands an editable session to a bot (compose voice + autonomous slot-fill + stamp the overlay);
    // DELETE undoes it. Owner-only (the session's `update` ability, in the FormRequest); {bot} + {session}
    // are workspace-scoped bindings (a foreign id 404s at bind), so a cross-workspace bot/session is
    // rejected before any work. Declared before the {bot} resource so the deeper path binds cleanly.
    Route::post('bots/{bot}/sessions/{session}/delegate', [BotSessionDelegationController::class, 'store'])
        ->whereUuid('bot')
        ->whereUuid('session')
        ->name('bots.sessions.delegate');
    Route::delete('bots/{bot}/sessions/{session}/delegate', [BotSessionDelegationController::class, 'destroy'])
        ->whereUuid('bot')
        ->whereUuid('session')
        ->name('bots.sessions.undelegate');

    Route::resource('bots', BotController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy'])
        ->parameter('bots', 'bot');
});
