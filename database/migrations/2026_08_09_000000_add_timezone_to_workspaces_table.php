<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R3 Calendar — the workspace's timezone: the single answer to "whose midnight is this grid drawn
 * against". A shared calendar is a coordination surface, so the day boundary has to belong to the TEAM,
 * not to whichever browser is looking (see CalendarTimezoneResolver for the decision and its cost).
 *
 * CENTRAL ONLY — no tenant mirror, and that is verified rather than assumed: `workspaces` has no
 * migration under database/migrations/tenant/. It cannot have one. A tenant database is what an
 * own-mode workspace's data lives in; the Workspace ROW itself always lives centrally and is read
 * through TenantContext::workspace() regardless of which connection queries are routing to. This is the
 * same shape as `ai_monthly_cost_cap` (R2 sub-stage 4), for the same reason.
 *
 * NULLABLE, and null means INHERIT `config('app.timezone')`. An untouched workspace therefore behaves
 * exactly as it did before this column existed — there is no backfill and no default to get wrong. The
 * value is validated on write with Laravel's `timezone` rule, so only real IANA identifiers land here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('ai_monthly_cost_cap');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
