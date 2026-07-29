<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the central add-last-op-columns migration (R2 sub-stage 2d
 * hardening). Identical to central — these two transient columns surface the most recent per-part
 * refine-loop op's outcome and carry no workspace_id. See the central migration for the rationale.
 *
 *   last_op_status  'ok' | 'failed' | null.
 *   last_op_error   a localized, non-secret failure message on a failed op, else null.
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
