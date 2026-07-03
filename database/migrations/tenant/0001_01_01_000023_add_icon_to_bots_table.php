<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror: add the bot's general-info `icon` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
