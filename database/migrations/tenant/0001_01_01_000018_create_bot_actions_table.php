<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of bot_actions. Mirrors the central table
 * MINUS workspace_id (one tenant DB = one workspace). bot_id / task_id / creator_id
 * are plain uuids without FKs (bots live in this tenant DB; creator references the
 * central users table — no cross-database FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bot_id')->index();
            $table->uuid('task_id')->nullable()->index();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->string('status')->nullable();
            $table->text('error')->nullable();
            $table->uuid('creator_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_actions');
    }
};
