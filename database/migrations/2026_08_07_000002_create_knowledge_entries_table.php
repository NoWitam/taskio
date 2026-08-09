<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a KNOWLEDGE ENTRY — one durable fact/topic inside a base.
 * Mirrors the knowledge_bases tenancy/uuid/creator posture; the own-database mirror omits workspace_id.
 *
 * IDENTITY. `slug` is the entry's STABLE handle: it is derived from the FIRST title and then never
 * follows a rename, because it is what `[[wikilinks]]` in other entries point at — a slug that
 * tracked the title would silently break every inbound link on a rename. Unique per base among
 * NON-deleted rows, enforced in the service rather than by a partial unique index: the trash must be
 * able to hold a same-slug row (that is the whole point of a restorable trash), and a partial index
 * would also have to be duplicated in the tenant tree and re-derived on every restore.
 *
 * INDEXING (the columns B2a's embedder fills; nothing writes them here beyond the digest):
 *   index_digest     sha256 over everything that changes the MEANING of the entry AND the parameters
 *                    it would be embedded with (title + content + metadata + chunker version +
 *                    embedding model + dimensions). Recomputed on every write.
 *   indexed_digest   the digest that was actually indexed. `index_digest != indexed_digest` IS the
 *                    "needs re-indexing" predicate — no separate dirty flag can drift from it.
 *   index_status     pending | indexing | indexed | partial | pending_budget | failed.
 *   index_started_at when the current `indexing` claim was taken. The stale-index reaper's ONLY
 *                    clock: a worker killed by SIGKILL/OOM never runs the job's failed() hook, so
 *                    without a claim timestamp an entry would hold `indexing` forever and the sweep
 *                    — which skips claimed entries — would never look at it again. It is a separate
 *                    column rather than a reuse of `updated_at` because `updated_at` is the entry's
 *                    user-visible "last edited" (both API resources expose it), and a background
 *                    re-index must never make a document look as though someone had just edited it.
 *   index_params     fingerprint of the PIPELINE the entry was last stamped against (chunker version
 *                    + embedding model + width). `index_digest` already folds those in, but it is
 *                    only recomputed when someone SAVES the entry — so on its own it cannot notice a
 *                    config change, and bumping the chunker would leave every existing entry on the
 *                    old pipeline forever. This column makes that difference SQL-comparable, so the
 *                    sweep finds every out-of-pipeline entry with one predicate instead of rehashing
 *                    40 000 characters per row.
 *   chunks_count     denormalized fan-out size, so a list never counts rows per entry.
 *
 * There is deliberately NO `embedding` column here: vectors live on knowledge_entry_chunks. An entry
 * is up to 40k characters — far past what one embedding can represent without losing the specifics
 * retrieval exists to find — so the unit of retrieval is the chunk, and the entry is its owner.
 *
 * `current_revision_id` points at the newest append-only revision and doubles as the OPTIMISTIC LOCK
 * token: a writer sends the revision it read, and a mismatch is a stale write (409). No FK, because
 * revisions reference the entry and a circular FK pair cannot be created or torn down in one order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // Real FK: purging a base hard-deletes its entries as the database-level safety net under
            // the service's explicit, transactional purge. Its INDEX is the composite
            // [knowledge_base_id, position] below (the list's own ordering) — a standalone index on the
            // same leading column would only cost writes.
            $table->foreignUuid('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();

            $table->string('title');
            $table->string('slug')->index();
            $table->text('content');

            // The `{key: literal}` map validated against the base's metadata_schema descriptors.
            $table->jsonb('metadata')->default('{}');

            // draft | proposed | approved | archived (KnowledgeEntryStatus).
            $table->string('status')->default('draft')->index();

            // When set (and in the past) the entry is flagged for review — the "this fact may have
            // rotted" marker a knowledge base needs to stay trustworthy.
            $table->timestamp('stale_at')->nullable()->index();

            // Manual ordering inside the base (reorder endpoint).
            $table->integer('position')->default(0);

            // --- indexing bookkeeping (filled by B2a) ---
            $table->smallInteger('chunks_count')->default(0);
            $table->char('index_digest', 64)->nullable();
            $table->char('indexed_digest', 64)->nullable();
            $table->string('index_status')->default('pending')->index();
            $table->timestamp('index_started_at')->nullable();
            $table->char('index_params', 16)->nullable();

            // The newest revision = the optimistic-lock token. No FK (circular with revisions).
            $table->uuid('current_revision_id')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['creator_type', 'creator_id']);
            // The list's default ordering inside one base.
            $table->index(['knowledge_base_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entries');
    }
};
