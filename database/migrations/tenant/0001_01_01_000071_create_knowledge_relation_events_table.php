<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror of the relation AUDIT TRAIL. One tenant database is one workspace, so there
 * is no workspace_id column.
 *
 * This table's existence here is the first and decisive reason it is not the shared `changelogs`
 * table: `changelogs` has NO tenant mirror at all, so an own-database workspace could not log a
 * relation change anywhere. See the central migration for the other three reasons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_relation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // No FK: the log outlives its subject, which is what makes the `delete` event survivable.
            $table->uuid('relation_id')->index();
            $table->uuid('knowledge_base_id')->index();

            $table->string('op', 20);

            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->uuid('draft_session_id')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_relation_events');
    }
};
