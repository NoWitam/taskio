<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the bot 5-module restructure (B6):
 * rename the `voice` placeholder -> `audio` and add the `knowledge` json column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->renameColumn('voice', 'audio');
        });

        Schema::table('bots', function (Blueprint $table) {
            $table->json('knowledge')->nullable()->after('audio');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('knowledge');
        });

        Schema::table('bots', function (Blueprint $table) {
            $table->renameColumn('audio', 'voice');
        });
    }
};
