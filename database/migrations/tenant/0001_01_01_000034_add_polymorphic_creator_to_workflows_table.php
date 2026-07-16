<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) workflows: make the creator POLYMORPHIC (User|WorkflowRun|Bot) by adding a nullable
 * `creator_type` discriminator beside the existing `creator_id`. Every current row is a user-authored
 * record, so it is back-filled to the 'user' morph alias; a row whose `creator_id` is NULL
 * (engine-origin) stays NULL and is read as 'user' by HasCreator's NULL-means-user fallback.
 * `creator_id` keeps its current nullability and carries no FK (created with foreignIdFor()/uuid()
 * without ->constrained()), so there is nothing to drop. Phase 1 of the polymorphic-creator
 * conversion: structure + back-fill only; read-site/policy/resource changes are Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('creator_type')->nullable()->after('creator_id');
            $table->index(['creator_type', 'creator_id']);
        });

        DB::table('workflows')
            ->whereNotNull('creator_id')
            ->update(['creator_type' => 'user']);
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropIndex(['creator_type', 'creator_id']);
            $table->dropColumn('creator_type');
        });
    }
};
