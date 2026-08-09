<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHADOW DRAFTS: a draft that proposes a CHANGE to an entry that already exists, rather than a new one.
 *
 * The composer is given real material and real context, so a large share of what it should produce is
 * not "here is a new entry" but "this existing entry is now out of date". Without somewhere to put
 * that, the only options were to write a near-duplicate entry (which is how a base rots) or to lose the
 * proposal entirely.
 *
 * `targets_entry_id` is the entry being amended. `target_revision_id` is the revision the composer
 * ACTUALLY SAW — frozen at generation time, and the reason acceptance can be safe: it is replayed as
 * the optimistic-lock token, so a human who edited the target meanwhile gets a conflict instead of a
 * silent overwrite of their work by a model that never saw it.
 *
 * THE INVARIANT — a shadow is always a draft (`targets_entry_id IS NOT NULL ⇒ draft_session_id IS NOT
 * NULL`) — is enforced by a CHECK constraint, not only in the service. It is the one rule whose breach
 * would be catastrophic and invisible: a row with a target but no session escapes the draft-invisibility
 * scope, so it would appear in the base as an ordinary entry carrying a reserved `__shadow-…` slug and
 * a pointer at another entry. Postgres is the only place that rule cannot be forgotten, and both the
 * central and tenant schemas are pgsql, so the mirror costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->uuid('targets_entry_id')->nullable()->index();
            $table->uuid('target_revision_id')->nullable();
        });

        // Not portable SQL; the module is pgsql-only by design (the chunk table's vector column already
        // makes that true), so the constraint is added where it can be and skipped where it cannot.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'alter table knowledge_entries add constraint knowledge_entries_shadow_is_draft
             check (targets_entry_id is null or draft_session_id is not null)'
        );
    }

    public function down(): void
    {
        DB::statement('alter table knowledge_entries drop constraint if exists knowledge_entries_shadow_is_draft');

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn(['targets_entry_id', 'target_revision_id']);
        });
    }
};
