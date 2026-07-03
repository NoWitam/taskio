<?php

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for bot_actions — the audit log of every step a
 * bot performs while executing a task (task_started, form_filled, commented,
 * submitted_to_test, execution_failed, …). Carries workspace_id for shared-mode
 * isolation; the own-database mirror in database/migrations/tenant omits it.
 *
 * task_id / bot_id carry no FK to keep the table usable from a tenant connection
 * (matches the project-wide no-cross-DB-FK convention); creator_id is nullable
 * because a bot-initiated action may have no originating user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable()->index();
            $table->foreignIdFor(Bot::class, 'bot_id')->index();
            $table->foreignIdFor(Task::class, 'task_id')->nullable()->index();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->string('status')->nullable();
            $table->text('error')->nullable();
            $table->uuid('creator_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_actions');
    }
};
