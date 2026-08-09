<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTES on a drafting run: the things the server DID TO the model's answer that a reviewer has to
 * know about before they accept it. The own-database mirror is identical (the table has no
 * workspace_id of its own to omit).
 *
 * `failure_reason` already carries the terminal outcome, and it is the wrong shape for this: a run
 * that succeeded can still have been quietly altered. The first case is the one G1 exists for — an
 * entry too long to show the composer in full may only be APPENDED to, so a proposal to rewrite it is
 * degraded to an append. That is the right call (the alternative silently deletes the part the model
 * never read), but a reviewer looking at "content changed" with no explanation would reasonably
 * conclude the composer had chosen to add rather than replace. It did not; the server decided.
 *
 * A LIST of `{code, ...context}` objects, rewritten wholesale on every run, because the notes describe
 * THE SET CURRENTLY ON THE TABLE. Accumulating them across refinements would leave a reviewer reading
 * warnings about proposals that no longer exist — which is worse than no warnings at all, since the
 * only reasonable response to a warning you cannot locate is to stop trusting the rest.
 *
 * Deliberately not prose: the client owns the wording and the locale, exactly as it does for
 * `failure_reason`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->jsonb('notes')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
