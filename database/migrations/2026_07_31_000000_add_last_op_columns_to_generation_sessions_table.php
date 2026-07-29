<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) columns surfacing the OUTCOME of the most recent per-part refine-loop op
 * (R2 sub-stage 2d hardening). A failed regenerate/refine is otherwise a silent no-op — the FE polls
 * back to `ready` at the same version with no signal — so the run manager stamps these at the
 * applyPartOp → run chokepoint (in the SAME update that sets `ready`) and clears them at the next claim.
 *
 *   last_op_status  'ok' | 'failed' | null (null = no part op since the last claim / a full generate).
 *   last_op_error   a LOCALIZED, NON-SECRET failure message on a failed op, else null (never a prompt /
 *                   instruction / provider body).
 *
 * TRANSIENT signal only, not persisted history — both are wiped by the next claimAndDispatch. The
 * own-database mirror in database/migrations/tenant is identical (these columns carry no workspace_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_sessions', function (Blueprint $table) {
            $table->string('last_op_status')->nullable()->after('history');
            $table->text('last_op_error')->nullable()->after('last_op_status');
        });
    }

    public function down(): void
    {
        Schema::table('generation_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_op_status', 'last_op_error']);
        });
    }
};
