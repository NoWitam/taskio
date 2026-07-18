<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `files` from an attachment store into disk entries:
 *
 * - description   — free-text metadata shown on the file's detail tab.
 * - folder_id     — where the file lives on the disk. NULL = the workspace root, and it stays
 *                   NULL for files owned by another resource (task attachments, report files),
 *                   which the browser surfaces under read-only virtual folders instead.
 * - disk_trashed_at — the DISK's own trash marker, deliberately separate from deleted_at.
 *                   Detaching an attachment already soft-deletes its file (FileService::detach),
 *                   and those must NOT surface in the disk trash; only a delete performed FROM
 *                   the disk sets this. Restoring clears both.
 * - disk_placed_at — set when a file is uploaded straight onto the disk (FileService::store),
 *                   which distinguishes a file placed at the ROOT (folder_id NULL) from an
 *                   in-flight temp upload — structurally identical otherwise. Without it a
 *                   root-level disk file could not be told apart from an unattached temp.
 *
 * No FK on folder_id: it must mirror cleanly into tenant databases, and the app resolves
 * folders through workspace-scoped queries anyway.
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
