<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH FACTS THIS ENTRY CLAIMS TO HAVE ABSORBED — the composer's own `covers` list, persisted.
 *
 * ------------------------------------------------------------------------------------------------
 * A SIGNAL, NOT A GATE, AND THE COLUMN DOES NOT CHANGE THAT
 *
 * The fact list is a CHECKLIST FOR A REVIEWER: the material's own account of what happened, shown
 * beside the proposals so a person can see what is missing. `covers` is the model's first-pass claim
 * about where each fact went. Nothing is refused because of it, and nothing should ever start being
 * refused because of it — a model that writes "2026-08-15: wyjazd do Tajlandii" and leaves the
 * incident inside that day out has covered the fact truthfully by any check code can make. The
 * omission is semantic and the reader is a person.
 *
 * What the column buys is the other half: the reviewer can see WHICH entry claimed each fact, and jump
 * to it. Until now the claim lived only in memory, long enough for the run to count it.
 *
 * ------------------------------------------------------------------------------------------------
 * IDENTIFIERS ONLY
 *
 * This holds `["F1","F7"]` and nothing else — handles into the frozen fact list, which itself lives in
 * `knowledge_draft_sessions.resolution_set` and is already scanned by `knowledge:purge-subject`. So an
 * erasure request reaches the fact TEXT where it lives, and this column carries no personal data to
 * reach. The laundering enforces the shape, so prose cannot arrive here by accident.
 *
 * DEFAULT `[]`, AND NO BACKFILL. Every entry written before this existed has an empty list, which is
 * the truthful answer: nobody asked those entries what they covered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->jsonb('covers')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('covers');
        });
    }
};
