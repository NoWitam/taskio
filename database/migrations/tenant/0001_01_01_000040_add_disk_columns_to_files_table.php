<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror of the central `files` disk columns (description / disk_trashed_at).
 * A file's container is the polymorphic `fileable` (`fileable_type = 'folder'` for disk files),
 * so there is no folder_id / disk_placed_at — see the central migration and ADR-0018.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->timestamp('disk_trashed_at')->nullable()->after('deleted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['description', 'disk_trashed_at']);
        });
    }
};
