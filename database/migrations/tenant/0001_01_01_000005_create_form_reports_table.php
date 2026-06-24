<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `form_reports` MINUS
 * workspace_id. The central table keeps form_id as a plain indexed column WITHOUT a FK
 * (no ->constrained()), so it is mirrored the same way here. creator_id references the
 * CENTRAL users table and carries no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('form_id');
            $table->string('name');
            $table->text('guidelines')->nullable();
            $table->json('sources');
            $table->date('submissions_from');
            $table->date('submissions_to');
            $table->timestamp('completed_at')->nullable();
            $table->uuid('creator_id');
            $table->timestamps();
            $table->softDeletes();

            $table->index('form_id');
            $table->index(['form_id', 'completed_at']);
            $table->index(['form_id', 'creator_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_reports');
    }
};
