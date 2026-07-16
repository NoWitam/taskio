<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) restructure of the bot into its 5 explicit modules (B6):
 *   1. tekstowy (text)        — unchanged (persona/style/dictionary/phrases/prohibitions)
 *   2. wykonywania zadań      — task_execution (enabled + tools[]); knowledge_source dropped
 *   3. wizualny (visual)      — unchanged placeholder
 *   4. audio                  — RENAME the empty `voice` placeholder column -> `audio`
 *   5. wiedzy (knowledge)     — NEW `knowledge` json column (array of {title, content})
 *
 * `voice` is a read-only null placeholder, so the rename carries no data concerns. The
 * legacy `task_execution.knowledge_source` key inside the JSON is simply no longer read
 * or written (the knowledge module replaces it) — no data migration needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->renameColumn('voice', 'audio');
        });

        Schema::table('bots', function (Blueprint $table) {
            // Knowledge module: array of { title, content } entries, injected into the
            // execution context. Kept as a JSON column (consistent with the other module
            // columns); a future app-wide Knowledge module may absorb these.
            $table->json('knowledge')->nullable()->after('audio');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('knowledge');
        });

        Schema::table('bots', function (Blueprint $table) {
            $table->renameColumn('audio', 'voice');
        });
    }
};
