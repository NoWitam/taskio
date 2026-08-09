<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a KNOWLEDGE DRAFTING SESSION — one sitting of the AI composer:
 * raw material in, a set of proposed entries out, refined by instruction until a human accepts or
 * abandons it.
 *
 * A SESSION rather than a one-shot call because drafting is a CONVERSATION with state: the raw source
 * has to survive every refinement (the model re-reads it each time), the instructions accumulate into
 * a history that shapes the next pass, and the whole thing has to be resumable after a page reload and
 * abandonable without leaving anything behind. None of that fits in a request.
 *
 * WHAT IS NOT HERE: the drafts themselves. They are ordinary `knowledge_entries` rows carrying this
 * session's id, which is what buys them revisions, slugs, metadata validation, the directive guard, the
 * policy and tenancy for free. A parallel `draft_entries` table would have had to re-earn every one of
 * those, and the accept step would have been a copy — the one operation where a bug loses a user's work.
 *
 * `status` is the CLAIM as well as the state: `idle|generating|ready|failed`, moved by a guarded UPDATE
 * so two clicks cannot start two runs against one session.
 *
 * `seed_slug` / `seed_title` carry the "write me the entry this red link points at" case: when set, the
 * run must produce exactly one entry at that slug, enforced server-side after the model answers.
 *
 * `retrieval_set` is declared now and filled in B11b (shadow drafts targeting existing entries need to
 * record which entries were retrieved as context). Declared early on purpose: adding a jsonb column
 * later is a migration against a table that may hold live sessions, and the column costs nothing empty.
 *
 * `relations_cache` is B12's (the proposed-relations panel). Same reasoning.
 *
 * The own-database mirror omits workspace_id (one tenant DB = one workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_draft_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // Real FK: a base's destruction takes its unfinished drafting sessions with it. The drafts
            // themselves are entries and already cascade through their own base FK.
            $table->foreignUuid('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();

            // The raw material the human pasted. Re-read on EVERY refinement — the model never edits its
            // own previous answer in place, it re-derives the whole set from the source plus the
            // instruction history, which is what keeps a refinement from compounding earlier mistakes.
            $table->text('source_text');

            // idle | generating | ready | failed (KnowledgeDraftSessionStatus).
            $table->string('status')->default('idle')->index();

            // When the current `generating` claim was taken — the reaper's clock, exactly as
            // `knowledge_entries.index_started_at` is for indexing.
            $table->timestamp('claimed_at')->nullable();

            // Why the last run failed, as prose a human can act on. Never a provider message (those
            // carry prompt bindings); one of a small set of stated reasons.
            $table->string('failure_reason')->nullable();

            // [{at, instruction}] — every refinement asked for, in order. Capped in the service
            // (`drafting.max_prompt_history`) because it is composed into the next prompt.
            $table->jsonb('prompt_history')->default('[]');

            // B12: the proposed-relations panel's computed edges, cached per session.
            $table->jsonb('relations_cache')->nullable();

            // B11b: which existing entries were retrieved as context for a shadow draft.
            $table->jsonb('retrieval_set')->nullable();

            // "Write the entry this red link points at" — exactly one draft, at this slug.
            $table->string('seed_slug')->nullable();
            $table->string('seed_title')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);
            // The reaper's sweep: abandoned sessions, oldest first.
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_draft_sessions');
    }
};
