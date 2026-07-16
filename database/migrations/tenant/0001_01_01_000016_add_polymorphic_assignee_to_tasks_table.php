<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the central polymorphic-assignee swap.
 * The tenant tasks table carries `assigned_id` as a plain uuid WITHOUT a FK (no
 * cross-database FKs to the central users table), so there is no constraint to drop.
 * Existing rows are back-filled to the 'user' morph alias, then `assigned_id` is dropped.
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

        DB::table('tasks')
            ->whereNotNull('assigned_id')
            ->update([
                'assignee_type' => 'user',
                'assignee_id' => DB::raw('assigned_id'),
            ]);

        Schema::table('tasks', function (Blueprint $table) {
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
