<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a PUBLICATION — one piece of content, going to one destination,
 * at one moment.
 *
 * R4 B1. See {@see \App\Modules\Publishing\Models\Publication} for what a publication IS; this file is
 * about why the columns are the columns, and every non-obvious one below exists because of a defect
 * that is UNRECOVERABLE rather than merely annoying.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DOCTRINE THIS SCHEMA IS SHAPED BY: A PUBLISHED POST CANNOT BE UN-PUBLISHED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Every other queue in this product retries on doubt, because the worst case is a duplicate ROW and a
 * row can be deleted. Here the worst case is a duplicate POST — a public artifact, seen, possibly
 * already shared, and removable only by hand and only sometimes. So the schema pays for certainty in
 * advance, in two columns that would look redundant in any other table:
 *
 *   remote_id        The published artifact's identifier ON THE PLATFORM. Written once, when the
 *                    publication demonstrably went out. It is the answer to "did this already happen",
 *                    and it is what a future unique index (with the connection — B2, when the
 *                    connections table exists to be unique WITH) will make impossible to have twice.
 *
 *   remote_draft_id  The identifier of the INTERMEDIATE artifact — an Instagram media container, a
 *                    YouTube resumable-upload session URI. Both platform families publish in two calls,
 *                    and the whole risk lives in the gap between them.
 *
 *                    IT IS PERSISTED IMMEDIATELY AFTER PHASE 1, in its own write, OUTSIDE any
 *                    transaction that phase 2 could roll back. That is the entire point of the column.
 *                    A worker killed between the two phases must resume by publishing the container it
 *                    already made; a worker that had kept the handle in memory would start over and
 *                    create a SECOND container, and the second one publishes just as publicly as the
 *                    first. Wrapping the two phases in one transaction would undo the handle on a
 *                    phase-2 failure and reintroduce exactly this — which is why the publisher
 *                    deliberately does not.
 *
 * Both are plain nullable strings and neither is indexed yet. Two decisions in that sentence:
 *   • NOT a uuid — these are the PLATFORM's identifiers, in the platform's format, and a column typed
 *     for ours would reject them.
 *   • NO unique index in B1. Uniqueness is per (connection, remote_id) — the same video id on two
 *     YouTube channels is two different artifacts — and `platform_connections` does not exist until
 *     B2. A unique index on `remote_id` alone would be wrong the day a second connection is added, and
 *     wrong indexes are harder to remove than absent ones.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * platform_connection_id — A COLUMN WITH NO FOREIGN KEY, ON PURPOSE AND TEMPORARILY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The table it points at arrives in B2. The column is here now because the state machine already needs
 * it: `blocked` exists to hold a queue whose CONNECTION is unusable, and a status that cannot say which
 * connection it is blocked on is a status nobody can un-block. Nullable, because a `dry_run` publication
 * has no account behind it and never will.
 *
 * When B2 lands the constraint it adds must be `nullOnDelete` or `restrict`, never `cascadeOnDelete`:
 * disconnecting an account must not silently erase the record of everything ever published through it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * scheduled_at IS AN INSTANT — a moment in UTC, never a day
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The opposite of `tasks.deadline` and of an all-day `calendar_events.start_date`, and the distinction
 * is the one R3 spent a chapter on. "Post at 9" is a wall-clock time a person picked, which is resolved
 * against the WORKSPACE's timezone at write time (the module reuses `CalendarInstantResolver` rather
 * than restating the rule) and stored as the absolute moment it named. A day column would have nothing
 * to say about the hour, and the hour is the entire content of a posting schedule.
 *
 * NULL means "not armed". Every `draft` has a null here, which is also why a draft has no square on the
 * calendar: no instant, no place on an axis of time.
 *
 * `published_at` is separate and is NOT a copy of it — it is when the artifact actually went out, which
 * differs from the plan by the queue's latency and, after a `needs_reconcile`, can differ by hours.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * media — AN ORDERED LIST OF DISK FILE IDS, AND NOTHING ELSE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A jsonb ARRAY of `files.id` values, in the order the platform will receive them. Four reasons for
 * this shape over the two alternatives:
 *
 *   ORDER IS DATA. A carousel is its sequence. A pivot table would need a `position` column that every
 *     read has to remember to sort by; an array IS the order, and cannot be read out of it.
 *
 *   POINTING IS NOT OWNING. The Disk's own `attachToModel()` sets `files.fileable`, which makes the
 *     file BELONG to the model and removes it from the disk tree. Publishing must never do that: the
 *     media are Generator output that lives on the Disk and is reused across destinations — one video
 *     to YouTube and to Instagram is one file and two publications.
 *
 *   NO DEREFERENCE HERE. The module stores ids and does not name the Disk module at all. The bytes are
 *     needed exactly once, at publish time, inside the adapter — which is also the only moment when a
 *     missing file is a failure somebody can act on, with a state to go to and a reason to show, rather
 *     than a null nobody notices at read time.
 *
 *   IT IS THE DOCTRINE ALREADY IN USE. `calendar_events.subject_*` and `knowledge_bindings` both
 *     address a consumer by primitives, store them, and never resolve them back to a class.
 *
 * THE COST, NAMED: no foreign key, so a trashed file leaves a dangling id. That is the correct end
 * state here. A cascade would silently rewrite what a scheduled publication is about; a dangling id
 * fails loudly at publish, with a reason, on a row somebody can fix.
 *
 * `options` is the per-destination extras — a YouTube privacy setting, a title, tags. Deliberately
 * schema-less and deliberately NOT validated here: what belongs in it is the adapter's knowledge, and
 * B1 has one adapter that needs none of it. Default `{}` so a reader never branches on null.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE FAILURE COLUMNS CARRY CODES, NEVER PROSE — AND NEVER CREDENTIALS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `failure_code` is a stable machine string the UI translates; a platform's own message is composed in
 * whatever language its servers feel like and cannot be a contract. `failure_context` is the small
 * structured aside that makes a code actionable (which file was missing, which scope was withdrawn).
 *
 * NOTHING DERIVED FROM A CREDENTIAL EVER GOES IN EITHER. Not a token, not a refresh token, not an
 * authorization header, not a signed URL that carries one. This is stated in the schema because the
 * tempting shortcut — dumping the failed request for debugging — is exactly how a token ends up in a
 * database, a log aggregator and a support ticket at once.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * INDEXES: three reads, and no speculation
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   (workspace_id, status, scheduled_at)  serves BOTH the due-sweep (`status = 'scheduled' AND
 *       scheduled_at <= now()`) and the counts endpoint, which groups by status on its
 *       (workspace_id, status) prefix.
 *   (workspace_id, scheduled_at)          the calendar window scan, which ranges over the instant
 *       across several statuses at once and would use the composite above poorly.
 *
 * There is deliberately NO index on `platform_connection_id`, though B2 will certainly want one for
 * "hold everything on this connection". Nothing in B1 reads that column, and an index added for a query
 * nobody has written yet is a guess about a shape nobody has seen. It belongs in the migration that
 * creates the table it points at.
 *
 * The own-database mirror omits workspace_id (one tenant DB = one workspace) —
 * database/migrations/tenant/0001_01_01_000078_create_publications_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            $table->string('title');
            // The caption / description. `text`, not `string`: a YouTube description is 5000 characters.
            $table->text('body')->nullable();

            // The closed vocabulary — PublishingPlatform. A string rather than a native enum type so a
            // destination can be added without an ALTER TYPE on every tenant database.
            $table->string('platform', 32);

            // No FK: `platform_connections` arrives in B2. See the docblock, including which delete
            // behaviour that constraint may and may not use.
            $table->uuid('platform_connection_id')->nullable();

            // PublicationStatus. The Manager is its only writer.
            $table->string('status', 32)->default('draft');

            // A MOMENT in UTC, never a day. Null = not armed.
            $table->timestamp('scheduled_at')->nullable();
            // When it actually went out — not a copy of the line above.
            $table->timestamp('published_at')->nullable();

            // Ordered Disk file ids. Pointers, not ownership. See the docblock.
            $table->jsonb('media')->default('[]');
            // Per-destination extras, shaped by whichever adapter reads them.
            $table->jsonb('options')->default('{}');

            // THE TWO IDEMPOTENCY COLUMNS. Platform-shaped strings, not uuids; not unique until B2 has
            // a connection to be unique with.
            $table->string('remote_id')->nullable();
            $table->string('remote_draft_id')->nullable();
            // The permalink, when the platform hands one back. Convenience only — `remote_id` is the
            // identity, and a URL format is the platform's to change.
            $table->string('remote_url')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();

            // A CODE the UI translates, plus a structured aside. NEVER a credential — see the docblock.
            $table->string('failure_code', 64)->nullable();
            $table->jsonb('failure_context')->nullable();

            // Polymorphic creator (user | workflow_run | bot) — ADR-0015. Nullable both halves so an
            // unattributed row resolves to nobody rather than failing a read.
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // The due-sweep and the counts endpoint.
            $table->index(['workspace_id', 'status', 'scheduled_at']);
            // The calendar window scan.
            $table->index(['workspace_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
