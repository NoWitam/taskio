<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two constraints B1 could not write because the table they refer to did not exist yet.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * 1. THE FOREIGN KEY IS `restrict`. NEVER `cascade`. AND NOT `nullOnDelete` EITHER.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `cascade` is the one that is actually dangerous and it is the one a default would have chosen:
 * disconnecting an account would delete the record of everything ever published through it — a set of
 * rows that are our only account of artifacts that still exist in public, on somebody's timeline, with
 * our text on them.
 *
 * `nullOnDelete` was the obvious safe answer and is still weaker than what is here. It keeps the rows
 * and severs the attribution, which for a PUBLISHED publication means the record survives without being
 * able to say WHICH ACCOUNT it went out on. On a workspace with two YouTube channels that is the only
 * fact that distinguishes two otherwise identical rows.
 *
 * `restrict` says the harder and more honest thing: a connection with publications behind it cannot be
 * erased at all. In normal operation nothing ever tests it, because a disconnect is a SOFT delete
 * (see PlatformConnectionManager::revoke) and a soft delete is an UPDATE that no foreign key notices.
 * What it catches is the other path — a purge, a console `forceDelete`, a future data-retention job —
 * where it converts a silent loss of history into a loud refusal that somebody has to decide about.
 *
 * The cost, named: a genuine erasure request against a connection will have to deal with its
 * publications explicitly. That is the correct amount of friction for deleting the record of public
 * artifacts, and it is a conversation, not a defect.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * 2. UNIQUENESS IS (connection, remote_id) — PER CONNECTION, NEVER GLOBAL
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * This is the database-level half of the doctrine the whole module is arranged around: a published post
 * cannot be un-published, so the second one must be impossible rather than unlikely. `remote_id` is the
 * platform's name for the artifact; two rows claiming the same artifact on the same connection is
 * either a double publish or a bookkeeping error, and both are things to find out about at the moment
 * of the write.
 *
 * PER CONNECTION, because the same identifier on two channels is two different artifacts. Platforms do
 * not coordinate their id spaces with each other and often not with themselves across accounts, so a
 * global unique index on `remote_id` would refuse a perfectly legitimate second publication the first
 * time two connections happened to collide — and would do it at 09:00, on a schedule, with no way to
 * proceed.
 *
 * WHAT IT DOES NOT COVER, stated rather than assumed: SQL treats NULLs as distinct in a unique index, so
 * rows with no `remote_id` (every draft, every scheduled item — the overwhelming majority) are
 * unconstrained, and so are rows with no connection (`dry_run`, which has no account and never will).
 * That is the correct shape. The index exists to make a DUPLICATE PUBLISHED ARTIFACT impossible on a
 * real account, and it does exactly that and nothing more.
 *
 * The tenant mirror is database/migrations/tenant/0001_01_01_000081 and carries the same two
 * constraints; `workspace_id` is part of neither, so nothing is lost in the translation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->foreign('platform_connection_id')
                ->references('id')
                ->on('platform_connections')
                ->restrictOnDelete();

            $table->unique(['platform_connection_id', 'remote_id'], 'publications_remote_artifact_unique');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropUnique('publications_remote_artifact_unique');
            $table->dropForeign(['platform_connection_id']);
        });
    }
};
