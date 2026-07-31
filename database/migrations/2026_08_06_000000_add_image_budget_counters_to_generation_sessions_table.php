<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERSIST the per-run image call budgets on the session (the DISTRIBUTED STORYBOARD FRAMES stage).
 *
 * Until now {@see \App\Modules\Generator\Services\ImageChainExecutor} counted its `ai_generate` /
 * `ai_edit` provider calls in INSTANCE state, which was correct precisely because ONE run was ONE job:
 * the executor was resolved fresh per run, so the counters naturally scoped to it. Fanning the storyboard
 * out into one job PER FRAME breaks that assumption at its root — every frame job builds its own
 * container, so every frame would start at 0 and the per-run ceilings
 * (`generator.image_generate_max_calls_per_session` / `image_edit_max_calls_per_session`) would stop
 * bounding anything at all. The budget has to live where the RUN lives, and the run lives in the row.
 *
 * Two plain INTEGER columns rather than a key inside an existing json column (`results`, `history`), for a
 * reason that is not stylistic: the guard and the increment MUST be one atomic statement, because N frame
 * jobs reserve concurrently. An integer column expresses exactly that —
 *
 *   UPDATE generation_sessions SET ai_generate_calls = ai_generate_calls + 1
 *   WHERE id = ? AND ai_generate_calls < ?
 *
 * — as a single conditional UPDATE whose affected-row count IS the answer ("you reserved it" / "you are
 * over budget"), the same guarded-UPDATE discipline the run claim and the workflow resume claim already
 * use. A json counter would force read-modify-write and lose reservations under exactly the concurrency
 * this stage introduces. `results` is additionally the FE wire contract and `history` the undo stack —
 * neither is a home for run bookkeeping.
 *
 * Both counters are RESET to 0 by every claim ({@see
 * \App\Modules\Generator\Services\GenerationSessionRunManager::claimAndDispatch}), full run or part op, so
 * the semantics stay exactly what the config documents today — a budget per RUN — with "run" now spanning
 * the session job plus its frame jobs instead of a single job.
 *
 * Guarded by hasColumn (mirrors the bot-delegation forward migration) so it is a no-op on a database that
 * already has the shape. Defaulting to 0 makes every existing row read as "nothing spent yet", which is
 * true: no run is in flight across a deployment boundary that these counters would have to explain.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_generate_calls', 'ai_edit_calls'] as $column) {
            if (!Schema::hasColumn('generation_sessions', $column)) {
                Schema::table('generation_sessions', function (Blueprint $table) use ($column) {
                    $table->unsignedInteger($column)->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['ai_edit_calls', 'ai_generate_calls'] as $column) {
            if (Schema::hasColumn('generation_sessions', $column)) {
                Schema::table('generation_sessions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
