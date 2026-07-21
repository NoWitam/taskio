<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror (db_mode = own) of the central `labelables.enforced` flag: whether a
 * file's label row was materialized by a folder's governance rather than attached by hand.
 * Mirrored verbatim — default false, so tenant tasks are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labelables', function (Blueprint $table) {
            $table->boolean('enforced')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('labelables', function (Blueprint $table) {
            $table->dropColumn('enforced');
        });
    }
};
