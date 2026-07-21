<?php

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for PER-USER autosave DRAFTS of a Disk file edit. One row is one
 * user's in-progress edit of one file: the editor autosaves (POST) so a refresh/crash never loses
 * work, while the MAIN file is overwritten only on explicit Save. The draft's payload (manifest +
 * image base blobs) lives on the tenant Storage disk; this row just indexes it.
 *
 * Carries workspace_id for shared-mode isolation via WorkspaceScope; the own-database mirror in
 * database/migrations/tenant omits it (one tenant DB = one workspace). Rows are transient — the
 * `disk:reap-stale-drafts` reaper prunes drafts past the 24h retention window (row + storage dir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disk_file_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable()->index();
            // The file this draft edits. No cross-DB FK (a draft may reference a file in the tenant
            // DB); indexed for the per-file lookup. Mirrors disk_ai_edits' unconstrained references.
            $table->uuid('file_id')->index();
            // The author of the draft — drafts are strictly per user, never shared.
            $table->uuid('user_id')->index();
            // 'image' | 'text' — which editor produced it (the FE owns the manifest's meaning).
            $table->string('kind');
            // The file's updated_at (ISO) captured when the draft began, so the FE can warn if the
            // file changed underneath the draft. Nullable — a fresh file may have none worth pinning.
            $table->string('base_version')->nullable();
            // Total stored footprint (manifest.json + live base blobs), for quotas/observability.
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->timestamps();
            // One draft per user per file. file_id is globally unique to a file (hence a workspace),
            // so this holds across the shared table without needing workspace_id in the key.
            $table->unique(['file_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disk_file_drafts');
    }
};
