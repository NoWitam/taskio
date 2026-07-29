<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for generation SESSIONS (R2 sub-stage 2b). Mirrors central
 * `generation_sessions` MINUS workspace_id (the whole tenant DB is one workspace). `template_id`
 * references the tenant's templates table but carries NO FK (the snapshot is authoritative — a session
 * outlives its template); creator_id references the CENTRAL users table with no cross-DB constraint.
 * See the central migration for the column rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('template_id')->nullable()->index();

            $table->string('name');
            $table->string('content_type');

            $table->json('recipe_snapshot');
            $table->json('slot_values');
            $table->json('results')->nullable();

            $table->string('status')->default('draft')->index();

            $table->json('history')->nullable();
            $table->timestamp('archived_at')->nullable();

            // Bot-AUTHOR delegation overlay (R2 sub-stage 3) — see the central migration. Provenance-only
            // bot id (no cross-module FK) + the whole {author, voice, snapshot_at} overlay.
            $table->uuid('bot_author_id')->nullable()->index();
            $table->json('bot_delegation')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_sessions');
    }
};
