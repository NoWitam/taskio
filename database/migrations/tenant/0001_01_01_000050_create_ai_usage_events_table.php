<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for the AI cost METER ledger (R2 sub-stage 2a). Mirrors
 * central `ai_usage_events` MINUS workspace_id (the whole tenant DB is one workspace, so the rolling-
 * month gate sums the whole table). `session_id` carries no FK. The R2 sub-stage 4 polymorphic
 * `actor` (`actor_type`/`actor_id`) mirror is here too. See the central migration for the column
 * rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('channel');

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            $table->decimal('estimated_cost', 10, 4)->default(0);

            $table->uuid('session_id')->nullable();

            $table->string('actor_type')->nullable();
            $table->uuid('actor_id')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index('channel');
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
