<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a PUBLICATION. Mirrors central `publications` MINUS
 * `workspace_id` — the whole tenant database IS one workspace, so the column would be a constant.
 *
 * The two indexes lose that leading column with it and cover the same two reads: the due-sweep plus
 * the counts group-by (status first), and the calendar window scan (the instant alone).
 *
 * See the central migration (database/migrations/2026_09_06_000000_create_publications_table.php) for
 * the arguments this file deliberately does not repeat: why `remote_id` and `remote_draft_id` both
 * exist from day one and why neither is unique yet, why `platform_connection_id` carries no foreign
 * key until B2, why `scheduled_at` is an instant and never a day, why `media` is an ordered list of
 * Disk file ids with no foreign key, and why nothing derived from a credential may ever reach
 * `failure_context`.
 *
 * The parity is not decorative: the same models read both databases, so a column present on one side
 * only would be a defect visible to exactly one workspace — and invisible to everybody testing on the
 * other. PublishingTenantDatabaseTest compares the two column sets directly for that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('title');
            $table->text('body')->nullable();

            $table->string('platform', 32);
            $table->uuid('platform_connection_id')->nullable();

            $table->string('status', 32)->default('draft');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->jsonb('media')->default('[]');
            $table->jsonb('options')->default('{}');

            $table->string('remote_id')->nullable();
            $table->string('remote_draft_id')->nullable();
            $table->string('remote_url')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->jsonb('failure_context')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
