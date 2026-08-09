<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a KNOWLEDGE BINDING. Mirrors central `knowledge_bindings`
 * MINUS workspace_id (the whole tenant DB is one workspace). See the central migration for why
 * `bindable_type` is a morph ALIAS with no foreign key and why the pair is unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('bindable_type');
            $table->uuid('bindable_id');

            $table->foreignUuid('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();

            $table->string('mode')->default('auto');

            $table->timestamps();

            $table->unique(['bindable_type', 'bindable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_bindings');
    }
};
