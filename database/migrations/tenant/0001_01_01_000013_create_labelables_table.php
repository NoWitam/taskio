<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors the central `labelables` pivot that
 * backs the polymorphic Label <-> entity relation. The central pivot carries NO FK on
 * label_id and has no workspace_id (it is isolated through its labels parent), so it is
 * mirrored verbatim. Without this table own-mode labels cannot be attached to anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labelables', function (Blueprint $table) {
            $table->uuid('label_id');
            $table->uuidMorphs('labelable');
            $table->timestamps();

            $table->unique(['label_id', 'labelable_id', 'labelable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labelables');
    }
};
