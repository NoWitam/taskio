<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a knowledge entry able to be a DRAFT: a normal row that belongs to a drafting session and is
 * invisible to everything else until a human accepts it.
 *
 * ONE NULLABLE COLUMN, not a second table. A draft has to be an entry, because the moment it is not,
 * every property the drafting flow depends on has to be built twice: revisions (the diff view compares
 * them), slugs and their per-base uniqueness (drafts wikilink each other), metadata validation against
 * the base schema, the template-directive guard, the policy, tenancy. And "accept" becomes a COPY —
 * the single operation where a bug silently loses what the user just reviewed. With this column,
 * accepting is `draft_session_id = null`, which is atomic and cannot half-happen.
 *
 * The price is that every existing read had to stop seeing them. That is paid ONCE, by a global scope
 * on the model (`whereNull('draft_session_id')`) with an explicit opt-in for the drafting endpoints —
 * so the reader, the entry list, search, the graph, the trash and the bot's context compiler needed no
 * change at all and cannot forget. A flag that each query had to remember would have been the opposite
 * trade: cheap here, wrong somewhere within a month.
 *
 * NO FOREIGN KEY to `knowledge_draft_sessions`. The session's own delete path purges its drafts
 * explicitly (and reversibly, through the entry service), and an FK would force a choice between
 * cascading — deleting entries as a side effect of a session row disappearing — and nulling, which
 * would silently PUBLISH every draft of a deleted session. Neither is acceptable; the service decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->uuid('draft_session_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('draft_session_id');
        });
    }
};
