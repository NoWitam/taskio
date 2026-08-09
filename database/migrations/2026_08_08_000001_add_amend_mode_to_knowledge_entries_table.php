<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOW a shadow draft changes the entry it targets — and, for an append, WHERE.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY A SHADOW NEEDS THIS
 *
 * Until now a shadow carried the COMPLETE new body, whatever the proposal meant. For a rewrite that is
 * exactly right. For an append it forced the composition to happen EARLY — at generation time, against
 * the text as it then stood — which had two costs:
 *
 *   the reviewer's diff showed a whole-document replacement for what is really "add one line", and
 *   a person editing the entry in the meantime turned the proposal into a conflict, even though
 *   "add this dated line" means the same thing before and after their edit.
 *
 * With `amend_mode = append` the shadow stores the ADDITION and the section it belongs in, and the
 * body is composed from the LIVE entry at acceptance. So the proposal keeps its review card, its diff
 * and its provenance, AND stays commutative — which is the property that stops it manufacturing 409s
 * for an operation that cannot collide.
 *
 * NULL means "not a shadow, or a shadow that replaces" — the historic behaviour, so nothing existing
 * has to be backfilled. `amend_section` is null for a rewrite and for an append with no named section
 * (which lands at the end of the document).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->string('amend_mode', 20)->nullable()->after('target_revision_id');
            $table->string('amend_section', 120)->nullable()->after('amend_mode');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn(['amend_mode', 'amend_section']);
        });
    }
};
