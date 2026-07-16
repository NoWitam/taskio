<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `approval_pipelines` MINUS
 * workspace_id. creator_id references the CENTRAL users table and carries no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_pipelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->uuid('creator_id');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_pipelines');
    }
};
