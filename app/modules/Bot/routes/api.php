<?php

use App\Http\Middleware\RequireWorkspace;
use App\Modules\Bot\Http\Controllers\BotActionController;
use App\Modules\Bot\Http\Controllers\BotController;
use App\Modules\Bot\Http\Controllers\BotInboxController;
use App\Modules\Bot\Http\Controllers\BotKnowledgeController;
use App\Modules\Bot\Http\Controllers\BotSessionDelegationController;
use App\Modules\Bot\Http\Controllers\BotToolRegistryController;
use App\Modules\Bot\Http\Controllers\BotVisualController;
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

    // Bot KNOWLEDGE (B6): which knowledge base this bot reads, and the one-time lift of its built-in
    // entries into a real base. The ONLY new Bot → Knowledge edge on the HTTP surface.
    //
    // RequireWorkspace is attached here for the same reason it guards the visual routes and the whole
    // Knowledge module: these touch workspace-owned KNOWLEDGE BASES, and ResolveWorkspace deliberately
    // no-ops without the X-Workspace-Id header — which would leave WorkspaceScope inert and let a bot be
    // bound to another workspace's base by id. Fail closed.
    //
    // Declared before the {bot} resource so the deeper paths bind cleanly.
    Route::middleware(RequireWorkspace::class)->group(function () {
        Route::put('bots/{bot}/knowledge-binding', [BotKnowledgeController::class, 'update'])
            ->whereUuid('bot')
            ->name('bots.knowledge-binding.update');
        Route::delete('bots/{bot}/knowledge-binding', [BotKnowledgeController::class, 'destroy'])
            ->whereUuid('bot')
            ->name('bots.knowledge-binding.destroy');
        Route::post('bots/{bot}/knowledge/migrate', [BotKnowledgeController::class, 'migrate'])
            ->whereUuid('bot')
            ->name('bots.knowledge.migrate');
    });

    // Bot VISUAL identity (the "Wygląd" module): create a likeness, approve one, drop one. Declared
    // before the {bot} resource so the deeper paths bind cleanly.
    //
    // RequireWorkspace again, for the same reason as the knowledge-binding group above. (This comment
    // used to say it was attached "HERE and nowhere else in this file" — untrue from the moment that
    // group landed, and the kind of claim a reader trusts instead of checking.) These are the bot
    // routes that touch workspace-owned BINARIES, and without an active tenant the File lookups would
    // run unscoped. Fail closed, exactly like the Disk routes.
    //
    // Generation rides its own TIGHT throttle bucket — a long, provider-billed call — with the key
    // prefix that keeps it from sharing a counter with every other throttled surface.
    Route::middleware(RequireWorkspace::class)->group(function () {
        Route::post('bots/{bot}/visual/generate', [BotVisualController::class, 'generate'])
            ->whereUuid('bot')
            ->middleware('throttle:10,1,bot-visual')
            ->name('bots.visual.generate');
        Route::post('bots/{bot}/visual/approve', [BotVisualController::class, 'approve'])
            ->whereUuid('bot')
            ->name('bots.visual.approve');
        Route::delete('bots/{bot}/visual/candidates/{file}', [BotVisualController::class, 'destroyCandidate'])
            ->whereUuid('bot')
            ->whereUuid('file')
            ->name('bots.visual.candidates.destroy');
    });

    Route::resource('bots', BotController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy'])
        ->parameter('bots', 'bot');
});
