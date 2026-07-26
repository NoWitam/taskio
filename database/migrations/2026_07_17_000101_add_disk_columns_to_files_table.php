<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `files` from an attachment store into disk entries:
 *
 * - description   — free-text metadata shown on the file's detail tab.
 * - disk_trashed_at — the DISK's own trash marker, deliberately separate from deleted_at.
 *                   Detaching an attachment already soft-deletes its file (FileService::detach),
 *                   and those must NOT surface in the disk trash; only a delete performed FROM
 *                   the disk sets this. Restoring clears both.
 *
 * A file's CONTAINER is the polymorphic `fileable` (already on the table): a disk file has
 * `fileable_type = 'folder'` with `fileable_id` = the folder (NULL = the workspace root); a
 * resource-owned file (task attachment, report) has the owning morph; a temp upload has
 * `fileable_type` NULL. So no `folder_id` / `disk_placed_at` columns are needed — the fileable
 * fully expresses placement (see ADR-0018).
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
