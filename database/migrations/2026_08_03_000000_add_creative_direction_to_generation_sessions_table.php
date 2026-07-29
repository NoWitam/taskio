<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) column holding a run's derived CREATIVE DIRECTION (the direction layer) — the
 * small normalized frame (message/goal/audience/tone/through-line/arc beats/subject/setting/visual
 * style/target duration/continuity notes) every generation in the session is made to, so N independent AI
 * calls produce ONE coherent piece.
 *
 * Derived ONCE per FULL run (one extra metered `ai_text` call, outside the per-session ai-text part budget),
 * persisted here, then REUSED — never re-derived — by every per-part regenerate/refine, so an isolated op
 * stays inside the same creative frame at zero extra cost. `claimAndDispatch` nulls it on a FULL claim (each
 * full run derives fresh) and PRESERVES it on a part-op claim.
 *
 * ADDITIVE on purpose: the create migration is left untouched so the sessions that motivated this layer
 * survive intact for an A/B comparison. Nullable json; the own-database mirror in database/migrations/tenant
 * is identical (the column carries no workspace_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_sessions', function (Blueprint $table) {
            $table->json('creative_direction')->nullable()->after('bot_delegation');
        });
    }

    public function down(): void
    {
        Schema::table('generation_sessions', function (Blueprint $table) {
            $table->dropColumn('creative_direction');
        });
    }
};
