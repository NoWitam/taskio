<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `bots` MINUS
 * workspace_id (the whole tenant DB is one workspace). creator_id references the
 * CENTRAL users table and carries no FK (no cross-DB constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->text('description')->nullable();

            // Text module (mandatory persona).
            $table->text('persona')->nullable();
            $table->text('style')->nullable();
            $table->json('dictionary')->nullable();
            $table->json('phrases')->nullable();
            $table->json('prohibitions')->nullable();

            // Task-execution module: {enabled: bool, knowledge_source: string|null, tools: string[]}.
            $table->json('task_execution')->nullable();

            // Visual / Voice placeholders (no logic yet).
            $table->json('visual')->nullable();
            $table->json('voice')->nullable();

            $table->uuid('creator_id');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
