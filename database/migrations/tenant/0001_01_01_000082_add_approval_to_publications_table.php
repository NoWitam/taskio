<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror (db_mode = own) of R4 B6's two publication columns. Identical to central —
 * neither column is workspace-scoped, so nothing is dropped here the way `workspace_id` is elsewhere.
 *
 * See the central migration (database/migrations/2026_09_11_000000_add_approval_to_publications_table.php)
 * for the arguments this file deliberately does not repeat: why the pipeline reference is `nullOnDelete`
 * and never a cascade, and why the arming intent is its own nullable instant rather than `scheduled_at`
 * on the draft.
 *
 * `approval_pipelines` lives in the tenant database too (0001_01_01_000006), so the foreign key is an
 * INTRA-TENANT one exactly like `approval_processes.approval_pipeline_id` — no cross-database reference
 * is created or implied.
 *
 * The parity is not decorative: the same models read both databases, so a column present on one side only
 * is a defect visible to exactly one workspace and invisible to everybody testing on the other.
 * `PublishingTenantDatabaseTest` compares the two column sets directly for that reason, which is also why
 * this file has to land in the same change as its central twin rather than "soon".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->foreignUuid('approval_pipeline_id')
                ->nullable()
                ->constrained('approval_pipelines')
                ->nullOnDelete();

            $table->timestamp('arm_on_approval_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_pipeline_id');
            $table->dropColumn('arm_on_approval_at');
        });
    }
};
