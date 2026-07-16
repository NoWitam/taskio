<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) tasks: turn the single-target `assigned_id` (User FK)
 * into a polymorphic assignee (User|Bot) so a task can be executed by a bot.
 *
 * The polymorphic type stores the MORPH-MAP ALIAS ('user'/'bot'), matching the
 * project-wide convention (AuthModuleServiceProvider registers `'user' => User`,
 * BotModuleServiceProvider registers `'bot' => Bot`). Existing rows are migrated
 * to `assignee_type = 'user'`, preserving every current assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assignee_type')->nullable()->after('assigned_id');
            $table->uuid('assignee_id')->nullable()->after('assignee_type');
            $table->index(['assignee_type', 'assignee_id']);
        });

        // Data-migrate the existing User assignee into the polymorphic columns.
        DB::table('tasks')
            ->whereNotNull('assigned_id')
            ->update([
                'assignee_type' => 'user',
                'assignee_id' => DB::raw('assigned_id'),
            ]);

        Schema::table('tasks', function (Blueprint $table) {
            // assigned_id was created with foreignIdFor() WITHOUT ->constrained(), so it
            // carries no FK constraint — only the column needs dropping.
            $table->dropColumn('assigned_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('assigned_id')->nullable()->after('creator_id');
        });

        DB::table('tasks')
            ->where('assignee_type', 'user')
            ->update(['assigned_id' => DB::raw('assignee_id')]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['assignee_type', 'assignee_id']);
            $table->dropColumn(['assignee_type', 'assignee_id']);
        });
    }
};
