<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `disk_file_drafts` MINUS workspace_id
 * (the whole tenant DB is one workspace). See the central migration for column semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disk_file_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('file_id')->index();
            $table->uuid('user_id')->index();
            $table->string('kind');
            $table->string('base_version')->nullable();
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->timestamps();
            $table->unique(['file_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disk_file_drafts');
    }
};
