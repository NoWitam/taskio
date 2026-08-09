<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the TYPED-RELATION layer needs on the three tables it does not own. Central schema; the
 * own-database mirror is identical.
 *
 * `knowledge_entries.entry_type` — person, organization, event, place, product, work, concept, other
 * (KnowledgeEntryType). NULLABLE and null for every existing row, permanently: a base written before
 * types existed is not wrong, it is untyped, and a backfill would have to guess. That is why the
 * pair-validation built on this column is ADVISORY wherever a type is unknown — see
 * {@see \App\Modules\Knowledge\Support\RelationVocabulary}. Indexed because the natural browse of a
 * typed base is "show me the people".
 *
 * `knowledge_bases.relation_types` — an ALLOW-LIST, a subset of the global vocabulary. NULL means the
 * whole vocabulary, which is deliberately different from `[]` (nothing is allowed): a base that has
 * never thought about relation types must not be quietly narrower than one that chose to allow none.
 *
 * The three session columns are declared NOW rather than in three later migrations, because they are
 * one feature arriving in stages and a column added per stage is a migration pair per stage across two
 * schema trees:
 *   `resolution_set` — the entries a composer run resolved its mentions against, frozen like
 *                      `retrieval_set` and for the same reason.
 *   `graph_ops`      — the relation operations a run PROPOSED, before a human accepted them.
 *   `applied_ops`    — the ones actually applied, so re-accepting a session cannot double-apply.
 *
 * All three carry TEXT ABOUT PEOPLE and are therefore already wired into the erasure scan; a column
 * that holds personal data and is invisible to the purge is the failure mode that layer exists to
 * prevent, and adding them unwired would have meant a window in which that was true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->string('entry_type', 30)->nullable()->index();
        });

        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->jsonb('relation_types')->nullable();
        });

        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->jsonb('resolution_set')->nullable();
            $table->jsonb('graph_ops')->nullable();
            $table->jsonb('applied_ops')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->dropColumn(['resolution_set', 'graph_ops', 'applied_ops']);
        });

        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropColumn('relation_types');
        });

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('entry_type');
        });
    }
};
