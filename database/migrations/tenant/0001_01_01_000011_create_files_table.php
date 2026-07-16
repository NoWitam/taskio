<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `files` (create_files_table +
 * uploader_id from update_files_table) MINUS workspace_id. The fileable morph is
 * polymorphic (no FK). uploader_id references the CENTRAL users table and carries no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('path');
            $table->string('type');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->nullableUuidMorphs('fileable');
            $table->uuid('uploader_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
