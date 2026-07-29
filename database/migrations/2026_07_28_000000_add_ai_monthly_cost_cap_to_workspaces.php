<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2 sub-stage 4 — the per-workspace monthly AI $ budget override (Cap Option B). Lives on the CENTRAL
 * `workspaces` table so it reads correctly in BOTH db modes: shared-mode rows carry it directly, and an
 * own-database workspace still holds its Workspace row centrally (TenantContext::workspace()), so the
 * effective cap is a pure central-model read even while queries route to the tenant connection.
 *
 * Semantics (AiUsageService::cap()): NULL = inherit the env default (ai.meter.monthly_cost_cap_default);
 * a POSITIVE value = this workspace's explicit $ cap; 0.00 = explicit UNLIMITED for this workspace. The
 * gate stays OFF (byte-preserving) while the effective cap is <= 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->decimal('ai_monthly_cost_cap', 10, 2)->nullable()->after('db_password');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('ai_monthly_cost_cap');
        });
    }
};
