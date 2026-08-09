<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a drafting session's context has ALREADY been expanded — server-side, because the fact
 * belongs to the session rather than to whoever happens to be looking at it.
 *
 * `expand-context` spends an embedding. Until now the "already done" state lived only in the browser
 * that pressed the button, so a reload, a second tab, or a colleague reviewing the same session was
 * offered the paid action again — and taking it bought the same retrieval twice. A disabled button is
 * a hint, not a guard.
 *
 * CLEARED when a refinement claims the session: a revision consumes the widened context (the composer
 * re-derives the whole set against it), so after that a further expansion is genuinely new work. That
 * is the same rule the front end had adopted locally; putting it here makes it true for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->timestamp('context_expanded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->dropColumn('context_expanded_at');
        });
    }
};
