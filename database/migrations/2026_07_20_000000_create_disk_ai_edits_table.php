<?php

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for the Disk preview's ASYNC AI image edits (F2-2). Each row
 * is one queued edit's status record: the browser dispatches (POST), a worker fills result_image,
 * and the browser polls (GET) until done/failed.
 *
 * Carries workspace_id for shared-mode isolation via WorkspaceScope; the own-database mirror in
 * database/migrations/tenant omits it (one tenant DB = one workspace). Rows are transient — the
 * `disk:reap-stale-ai-edits` reaper prunes terminal ones after a short retention window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disk_ai_edits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable()->index();
            // queued -> processing -> done | failed. Indexed: the reaper scans by status + age.
            $table->string('status')->default('queued')->index();
            $table->text('prompt');
            $table->boolean('has_mask')->default(false);
            // A localized, non-secret failure message surfaced by the poll (never the raw provider body).
            $table->text('error')->nullable();
            // The edited image as base64 PNG, present only once done. Transient (pruned).
            $table->longText('result_image')->nullable();
            // Storage paths for the persisted upload the worker reads — the request's temp files are
            // gone by the time the job runs. Cleared once the edit finishes.
            $table->string('input_image_path')->nullable();
            $table->string('input_mask_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disk_ai_edits');
    }
};
