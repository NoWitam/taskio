<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a CALENDAR EVENT — the one subject the Calendar module OWNS.
 *
 * R3 B3. Every other square on the grid belongs to a module that had its own reason to exist (a task's
 * deadline, an automation's firing); this is the row a person creates because something is HAPPENING on
 * a date and nothing else in the product would record it. See
 * {@see \App\Modules\Calendar\Models\CalendarEvent} for the definitional fence around what that is
 * allowed to mean — an ANNOTATION on a timeline, never a trigger.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ALL-DAY DISCRIMINATOR IS A SCHEMA FACT, NOT A CONVENTION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `all_day` picks which of two mutually exclusive column groups carries this row's position in time:
 *
 *   all_day = true   → `start_date` (a `date`: no hour, no zone). `starts_at`/`ends_at` are NULL.
 *   all_day = false  → `starts_at` (+ optional `ends_at`), UTC instants. `start_date` is NULL.
 *
 * Both groups are nullable because exactly one of them is used, and NOTHING in the schema can express
 * "exactly one". That invariant is held one layer up and structurally rather than by assertion:
 * {@see \App\Modules\Calendar\DTOs\CalendarEventDTO} has a private constructor and two named
 * constructors that cannot hold each other's data, and every write path goes through it. A row
 * carrying both shapes is a row no reader can interpret — the read side branches on `all_day` and
 * would convert a zone-free day into an instant, which is exactly how a date lands on the wrong square
 * (the argument in full: {@see \App\Modules\Calendar\DTOs\CalendarOccurrence}).
 *
 * There is deliberately no `end_date`: an all-day event is ONE day. The occurrence DTO the read path
 * emits carries a single `startDate`, so a multi-day all-day span has nowhere to go and would be
 * silently flattened to its first day. Adding one means teaching the whole read path about spans
 * first, not adding a column.
 *
 * RECURRENCE IS OUT OF SCOPE, and there is no `repeat` column waiting to be filled in. A recurring
 * event needs a projection engine — and this product already has exactly one (the workflow schedule
 * compiler), which lives in the Workflows module that the Calendar may not name. A second one here
 * would be a duplicate of the thing the plan says not to duplicate.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * subject_type / subject_id — A POINTER, NEVER A DOCUMENT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Optional "this event is about that thing": a morph ALIAS (`'task'`) plus an id. No foreign key, no
 * `morphTo()` on the model, never dereferenced here. This is the same doctrine as `knowledge_bindings`
 * and for the same reason — resolving the alias back to a class would make the Calendar depend on every
 * module it can point at, which is the one property this module is built to protect (pinned literally
 * by CalendarModuleBoundaryTest). The pair is handed to the client, and what to do with it is the
 * client's business. A pointer whose target was deleted is inert: nothing follows it.
 *
 * THERE IS NO `color` COLUMN, and its absence is deliberate rather than an omission. CalendarColor is a
 * vocabulary of MEANINGS — a task deadline colours by priority, a run by how it ended, a projection by
 * being a projection — and an event states no such fact, so a stored colour was decoration wearing the
 * vocabulary the other sources speak. The read source gives every event ONE constant
 * ({@see \App\Modules\Calendar\Sources\EventCalendarSource}). If events ever need to be visually
 * groupable, that is a CATEGORY (a named row whose colour is the category's property), not a colour
 * back on this row.
 *
 * INDEXES cover the two — and only two — ways this table is read: the all-day window scan and the
 * timed window scan. They are separate indexes because they are separate queries
 * ({@see \App\Modules\Calendar\Sources\EventCalendarSource}); the discriminator splits the read path
 * the same way it splits the columns.
 *
 * The own-database mirror omits workspace_id (one tenant DB = one workspace) —
 * database/migrations/tenant/0001_01_01_000075_create_calendar_events_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            $table->string('title');
            $table->text('description')->nullable();

            // THE DISCRIMINATOR. Read it before either column group below.
            $table->boolean('all_day')->default(false);

            // all_day = true: a plain calendar day. Never parsed as an instant, never converted.
            $table->date('start_date')->nullable();

            // all_day = false: UTC instants. `ends_at` stays nullable for an event with no stated end.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // The optional pointer. Alias + id, no FK, no relation — see the docblock.
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            // Polymorphic creator (user | workflow_run | bot) — ADR-0015. Nullable both halves: a
            // legacy/unattributed row resolves to nobody rather than failing a read.
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // The two read patterns, one index each.
            $table->index(['workspace_id', 'start_date']);
            $table->index(['workspace_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
