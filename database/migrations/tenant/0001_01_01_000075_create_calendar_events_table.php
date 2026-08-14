<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a CALENDAR EVENT. Mirrors central `calendar_events` MINUS
 * `workspace_id` — the whole tenant database IS one workspace, so the column would be a constant.
 *
 * The two window indexes lose that leading column with it and index the date/instant alone; they still
 * cover exactly the two reads {@see \App\Modules\Calendar\Sources\EventCalendarSource} performs.
 *
 * See the central migration
 * (database/migrations/2026_08_09_000001_create_calendar_events_table.php) for why `all_day` picks
 * between two mutually exclusive column groups, why there is no `end_date`, no `repeat` and no `color`,
 * and why `subject_type`/`subject_id` is a pointer with no foreign key and no relation.
 *
 * The parity is not decorative: the same models read both databases, so a column present on one side
 * only would be a defect visible to exactly one workspace. CalendarTenantDatabaseTest compares the two
 * column sets directly for that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('title');
            $table->text('description')->nullable();

            $table->boolean('all_day')->default(false);

            $table->date('start_date')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('start_date');
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
