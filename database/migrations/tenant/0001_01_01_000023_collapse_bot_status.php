<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the bot status collapse:
 * draft|disabled -> inactive; active unchanged; column default -> 'inactive'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bots')->whereIn('status', ['draft', 'disabled'])->update(['status' => 'inactive']);

        Schema::table('bots', function (Blueprint $table) {
            $table->string('status')->default('inactive')->change();
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });

        DB::table('bots')->where('status', 'inactive')->update(['status' => 'draft']);
    }
};
