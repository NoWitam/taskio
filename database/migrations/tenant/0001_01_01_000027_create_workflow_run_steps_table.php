<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `workflow_run_steps` MINUS
 * workspace_id (the whole tenant DB is one workspace). workflow_run_id carries no FK
 * (no cross-DB constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_run_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_run_id')->index();

            $table->unsignedInteger('position');
            $table->string('type');
            $table->string('key');
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_steps');
    }
};
