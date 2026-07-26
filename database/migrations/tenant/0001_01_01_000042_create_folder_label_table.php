<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror (db_mode = own) of the central `folder_label` governance pivot: which
 * labels a folder enforces / recommends onto its contents. Mirrored verbatim — the pivot carries
 * no workspace_id (isolated through its folder), and label_id has no cross-DB FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_label', function (Blueprint $table) {
            $table->uuid('folder_id');
            $table->uuid('label_id');
            $table->string('mode');
            $table->timestamps();

            $table->unique(['folder_id', 'label_id']);
            $table->index('label_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_label');
    }
};
