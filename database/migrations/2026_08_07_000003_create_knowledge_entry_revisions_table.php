<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a KNOWLEDGE ENTRY REVISION — an APPEND-ONLY snapshot of an
 * entry's authored state (title + content + metadata) taken on every mutation, including the create.
 * The own-database mirror omits workspace_id.
 *
 * Append-only is the whole design, and it is why this table carries `created_at` but NO `updated_at`
 * and NO soft deletes: a revision that can be edited or hidden is not an audit trail. "Restoring" an
 * old revision therefore does not rewind history — it writes a NEW revision carrying the old content,
 * authored by whoever pressed restore.
 *
 * The newest revision's id is mirrored onto knowledge_entries.current_revision_id, which is also the
 * optimistic-lock token a writer echoes back.
 *
 * `author` is the polymorphic HasCreator pair under a domain name (author_id/author_type) — the same
 * override the Disk file makes for its uploader — because on a revision the actor is the AUTHOR of
 * that version, not the creator of a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entry_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // Real FK + its own index: revisions are ALWAYS read by entry, and there is no composite
            // index here to piggyback on. Cascade is the safety net under the service's purge.
            $table->foreignUuid('knowledge_entry_id')->index()->constrained('knowledge_entries')->cascadeOnDelete();

            $table->string('title');
            $table->text('content');
            $table->jsonb('metadata')->default('{}');

            // Optional free-text "why" supplied by the writer.
            $table->string('change_note')->nullable();

            $table->uuid('author_id')->nullable();
            $table->string('author_type')->nullable();

            // Append-only: creation time only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['author_type', 'author_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entry_revisions');
    }
};
