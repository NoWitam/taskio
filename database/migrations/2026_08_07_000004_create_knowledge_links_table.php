<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a KNOWLEDGE LINK — one directed edge between entries of the
 * same base. The own-database mirror omits workspace_id.
 *
 * `target_slug` is ALWAYS filled and `to_entry_id` may be null: a link whose target does not exist
 * yet is a GHOST. That is a feature, not an error state — writing `[[pricing-policy]]` before the
 * pricing entry exists is how a knowledge base tells you what is missing, and the moment an entry
 * with that slug is created every ghost pointing at it is attached in place. Resolution keys on the
 * slug precisely because the slug never follows a rename.
 *
 * `source` distinguishes how the edge came to exist:
 *   wikilink    parsed from the entry's content; owned by the writer, so it is delete+insert on
 *               every save of the source entry (the content IS the authority).
 *   similarity  proposed by the vector layer (B2b) — `score` + `evidence` carry the why.
 *   manual      drawn by a human in the UI.
 * The unique key is [from_entry_id, target_slug, source], so the three kinds coexist on the same pair
 * without one overwriting another, and a delete+insert of the wikilink set never touches the others.
 *
 * `evidence` is reserved for B2b's `{from_chunk_ordinal, to_chunk_ordinal}` — WHICH passage of each
 * side made the two look related, so a suggestion can be judged instead of trusted. `dismissed_at`
 * remembers a rejected suggestion so it is not proposed again on the next pass.
 *
 * BACKLINKS have no table: they are this table read in the other direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // Denormalized from the source entry: every read of the graph is per base, and it is what
            // makes ghost RESOLUTION a single indexed lookup (base + slug) with no join.
            $table->uuid('knowledge_base_id')->index();

            $table->foreignUuid('from_entry_id')->index()->constrained('knowledge_entries')->cascadeOnDelete();

            // Null = GHOST. nullOnDelete is the database-level echo of the service's explicit
            // degradation on purge: an incoming link outlives its target as a ghost, it is never
            // silently deleted (that would erase the writer's intent along with the target).
            $table->foreignUuid('to_entry_id')->nullable()->index()->constrained('knowledge_entries')->nullOnDelete();

            $table->string('target_slug');

            // wikilink | similarity | manual (KnowledgeLinkSource).
            $table->string('source');

            $table->decimal('score', 5, 4)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            // One edge per (source entry, target slug, kind) — see the class docblock.
            $table->unique(['from_entry_id', 'target_slug', 'source']);

            // Ghost resolution: "every unresolved link in this base pointing at this slug".
            $table->index(['knowledge_base_id', 'target_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_links');
    }
};
