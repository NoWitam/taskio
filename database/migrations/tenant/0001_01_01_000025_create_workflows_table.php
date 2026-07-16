<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `workflows` MINUS
 * workspace_id (the whole tenant DB is one workspace). creator_id references the
 * CENTRAL users table and carries no FK (no cross-DB constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status')->default('inactive');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();

            // Trigger + its per-type config, optional gate conditions, ordered steps.
            $table->string('trigger_type');
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('steps');

            $table->uuid('creator_id');

            // Scheduling (populated by the scheduler in a later batch).
            $table->timestamp('last_scheduled_run_at')->nullable();
            $table->timestamp('next_due_at')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
