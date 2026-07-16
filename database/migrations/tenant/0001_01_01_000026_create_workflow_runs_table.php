<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `workflow_runs` MINUS
 * workspace_id (the whole tenant DB is one workspace). workflow_id / creator_id reference
 * central/local rows and carry no FK (no cross-DB constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->index();

            $table->string('state')->default('pending');
            $table->string('origin')->default('event');
            $table->string('trigger_type');
            $table->json('trigger_payload')->nullable();
            $table->json('context')->nullable();

            $table->unsignedInteger('depth')->default(0);
            $table->uuid('origin_run_id')->nullable();

            $table->uuid('creator_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['state', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
