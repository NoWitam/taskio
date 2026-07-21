<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror (db_mode = own) of the central folders metadata columns: a folder's
 * optional `description` and `icon`. Identical to central — folders carry no workspace_id in a
 * tenant DB, and these columns don't either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('icon')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn(['description', 'icon']);
        });
    }
};
