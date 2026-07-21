<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `disk_ai_edits` MINUS workspace_id
 * (the whole tenant DB is one workspace). See the central migration for column semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disk_ai_edits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status')->default('queued')->index();
            $table->text('prompt');
            $table->boolean('has_mask')->default(false);
            $table->text('error')->nullable();
            $table->longText('result_image')->nullable();
            $table->string('input_image_path')->nullable();
            $table->string('input_mask_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disk_ai_edits');
    }
};
