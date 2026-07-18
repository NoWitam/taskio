<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror of the central `files` disk columns (description / folder_id /
 * disk_placed_at / disk_trashed_at). Same shape — these columns are not workspace-scoped, so
 * nothing is dropped here; see the central migration for what each one means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->uuid('folder_id')->nullable()->after('description')->index();
            $table->timestamp('disk_placed_at')->nullable()->after('folder_id')->index();
            $table->timestamp('disk_trashed_at')->nullable()->after('deleted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['description', 'folder_id', 'disk_placed_at', 'disk_trashed_at']);
        });
    }
};
