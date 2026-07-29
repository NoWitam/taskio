<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for generation SESSIONS (R2 sub-stage 2b) — one execution of a
 * Template RECIPE into concrete content. Mirrors the `templates` tenancy/uuid/creator posture:
 * carries workspace_id for shared-mode isolation via WorkspaceScope; the own-database mirror in
 * database/migrations/tenant OMITS it (one tenant DB = one workspace).
 *
 * A session SNAPSHOTS its template at creation (`recipe_snapshot` = the full {content_type, slots,
 * content}) so it is reproducible and survives a later template edit/delete — `template_id` is
 * PROVENANCE only (nullable, NO FK). `slot_values` is the user's filled inputs; `results` the per-part
 * outcome map ({<partKey>: {kind, status, text?, error?}}); `status` a GenerationSessionStatus string
 * (draft/generating/ready/failed). `history` (bounded stack) + `archived_at` are RESERVED for 2d
 * (columns now, behavior later). Soft-deletes give a trash the 2d reaper will purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // Provenance only — the snapshot is authoritative, so no hard FK to templates (a session
            // outlives the template it was cut from).
            $table->uuid('template_id')->nullable()->index();

            $table->string('name');

            // The content-type id snapshot (mirrors recipe_snapshot.content_type) for cheap listing /
            // filtering without decoding the snapshot json.
            $table->string('content_type');

            // The immutable recipe captured at creation + the user's filled slot inputs + the per-part
            // outcome map. All ride the model's json 'array' cast.
            $table->json('recipe_snapshot');
            $table->json('slot_values');
            $table->json('results')->nullable();

            // draft | generating | ready | failed (GenerationSessionStatus). Indexed for the list filter.
            $table->string('status')->default('draft')->index();

            // RESERVED for 2d: a bounded history stack + the archive marker (disables cleanup). Present
            // now so the model + wire shape are stable; no behavior keys on them in 2b.
            $table->json('history')->nullable();
            $table->timestamp('archived_at')->nullable();

            // Bot-AUTHOR delegation overlay (R2 sub-stage 3): a REVERSIBLE, SNAPSHOTTED handoff of the
            // session's content VOICE + authorship to a bot. `bot_author_id` is PROVENANCE only (nullable,
            // INDEXED, NO cross-module FK — like template_id); `bot_delegation` is the whole overlay
            // ({author, voice, snapshot_at}), all-or-nothing. The human `creator` stays the OWNER; both
            // null = undelegated (the session renders in the human's own voice).
            $table->uuid('bot_author_id')->nullable()->index();
            $table->json('bot_delegation')->nullable();

            // Polymorphic creator per the app-wide HasCreator convention (a session may later be created
            // by a workflow_run / bot).
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_sessions');
    }
};
