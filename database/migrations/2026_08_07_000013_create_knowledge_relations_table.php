<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a KNOWLEDGE RELATION — a TYPED, human-approved statement about
 * two entries: "Anna WORKS ON the refund project", "the outage OCCURRED DURING the migration". The
 * own-database mirror omits workspace_id.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS IS NOT A COLUMN ON knowledge_links
 *
 * Because `knowledge_links` is a CACHE. Its rows are deleted and recomputed on every save, re-index
 * and `links.version` bump — that is the design, and it is what makes the derived graph cheap and
 * self-healing. A relation is the opposite kind of thing: it was proposed by a paid AI call or drawn
 * by a person, and then a human APPROVED it. A sweep that quietly swept those away would destroy
 * curated work with no error, no log line and no way to notice until somebody went looking for a fact
 * that used to be there.
 *
 * A separate table does not merely make that unlikely — it makes it structurally impossible, because
 * the link sweep's delete cannot name this table.
 *
 * ------------------------------------------------------------------------------------------------
 * THE COLUMNS THAT ARE NOT OBVIOUS
 *
 * `to_entry_id` is NOT NULL, unlike a link's. A link may be a GHOST (pointing at a slug nobody has
 * written yet) because that is how prose refers to things; a relation is an assertion about two
 * things that exist, and "Anna works on <nothing>" is not a fact anybody approved.
 *
 * `relation_type` is a plain string validated by an enum IN CODE rather than by a CHECK constraint.
 * The vocabulary will grow — that is the point of having one — and a CHECK would mean a migration in
 * two trees for every new verb, with old rows becoming un-writable the moment one was retired.
 *
 * `state` + `superseded_by_id` are how a relation ENDS. There is no delete in the machine-facing
 * contract at all: facts stop being true, they do not stop having been true, and an AI allowed to
 * retract statements is an AI that can quietly erase a base. `ended` (with `valid_to`) says "this was
 * true until then"; `superseded_by_id` points at the relation that replaced it; `retracted` means a
 * human said it was never right. Hard deletion exists, but only as a person's own affordance.
 *
 * `valid_from`/`valid_to` are DATES, not timestamps. The facts a knowledge base records — employment,
 * membership, a project's span — are known to the day at best, and a timestamp would invite a
 * precision nobody has.
 *
 * `origin` records WHO ASSERTED IT: `composer` (an AI proposal a human approved), `human` (drawn in
 * the UI), `promoted` (a machine SUGGESTION from the link graph that a human upgraded into a typed
 * fact). Provenance is what lets a base later ask "what do we know only because a model said so".
 * `draft_session_id` keeps the trail without an FK — the session is deleted when it is abandoned, and
 * a relation that outlives its session must not go with it.
 *
 * ------------------------------------------------------------------------------------------------
 * DELIBERATELY NOT UNIQUE ON (from, to, relation_type)
 *
 * Two relations of the same type between the same pair are legitimate and common: "met on 2026-08-15"
 * and "met on 2026-09-12" are two facts, not a duplicate. The index on that triple is for LOOKUP. What
 * IS refused — an ACTIVE relation with the same pair, type and `valid_from` — is enforced in code,
 * because it is a statement about the `active` subset that a plain unique index cannot express.
 *
 * The one thing the database does enforce is `from <> to`: a self-relation is never a fact, it is a
 * bug in whatever wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // Denormalized from the entries, exactly as on knowledge_links: every read is per base.
            $table->uuid('knowledge_base_id')->index();

            $table->foreignUuid('from_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            // NOT NULL: a relation asserts something about two entries that exist. See the docblock.
            $table->foreignUuid('to_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();

            // KnowledgeRelationType. Validated in code, not by a CHECK — see the docblock.
            $table->string('relation_type', 40);

            // The human-readable "why", shown next to the edge. Short on purpose: a relation is a
            // statement, and anything that needs a paragraph belongs in an entry.
            $table->string('description', 300)->nullable();

            // Type-specific facts (a role, a share). Keys are allow-listed per relation type.
            $table->jsonb('properties')->default('{}');

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            // active | ended | retracted (KnowledgeRelationState).
            $table->string('state', 20)->default('active');

            // The relation that replaced this one. The FK is added AFTER the table exists (below):
            // Blueprint emits foreign keys before the primary-key constraint, so a SELF-reference
            // declared here fails with "no unique constraint matching given keys".
            $table->uuid('superseded_by_id')->nullable();

            // composer | human | promoted.
            $table->string('origin', 20);

            // Provenance only, deliberately without an FK: an abandoned session is deleted, and a
            // relation a human approved must not be deleted with it.
            $table->uuid('draft_session_id')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);

            // The two reads that matter: this entry's outgoing relations, and its incoming ones. State
            // is in both because the default view is `active` only.
            $table->index(['knowledge_base_id', 'from_entry_id', 'state']);
            $table->index(['knowledge_base_id', 'to_entry_id', 'state']);
            // Duplicate detection and "what do these two have between them". NOT unique — see above.
            $table->index(['from_entry_id', 'to_entry_id', 'relation_type']);
        });

        // The self-reference, once the primary key exists. nullOnDelete rather than cascade: losing a
        // successor must not delete the history that points at it — the whole value of `superseded_by`
        // is that a reader following it arrives somewhere.
        Schema::table('knowledge_relations', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('knowledge_relations')->nullOnDelete();
        });

        // Not portable SQL; the module is pgsql-only by design (the chunk table's vector column already
        // makes that true), so the constraint is added where it can be and skipped where it cannot.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'alter table knowledge_relations add constraint knowledge_relations_not_self
             check (from_entry_id <> to_entry_id)'
        );
    }

    public function down(): void
    {
        DB::statement('alter table knowledge_relations drop constraint if exists knowledge_relations_not_self');

        Schema::dropIfExists('knowledge_relations');
    }
};
