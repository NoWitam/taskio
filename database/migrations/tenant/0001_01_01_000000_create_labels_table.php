<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). These tables carry NO workspace_id —
 * the whole database belongs to a single workspace. This file is representative;
 * the full own-mode schema mirrors the central domain tables minus workspace_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
