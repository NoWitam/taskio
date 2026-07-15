<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) files: make the creator POLYMORPHIC (User|WorkflowRun|Bot) by adding a nullable
 * `uploader_type` discriminator beside the existing `uploader_id`. Every current row is a user-authored
 * record, so it is back-filled to the 'user' morph alias; a row whose `uploader_id` is NULL
 * (engine-origin) stays NULL and is read as 'user' by HasCreator's NULL-means-user fallback.
 * `uploader_id` keeps its current nullability and carries no FK (created with foreignIdFor()/uuid()
 * without ->constrained()), so there is nothing to drop. Phase 1 of the polymorphic-creator
 * conversion: structure + back-fill only; read-site/policy/resource changes are Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('uploader_type')->nullable()->after('uploader_id');
            $table->index(['uploader_type', 'uploader_id']);
        });

        DB::table('files')
            ->whereNotNull('uploader_id')
            ->update(['uploader_type' => 'user']);
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['uploader_type', 'uploader_id']);
            $table->dropColumn('uploader_type');
        });
    }
};
