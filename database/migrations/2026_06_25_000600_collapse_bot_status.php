<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) collapse of the bot status from three states
 * (draft|active|disabled) to two (active|inactive):
 *   draft    -> inactive
 *   disabled -> inactive
 *   active   -> active (unchanged)
 * and change the column default from 'draft' to 'inactive'.
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

        // Best-effort reverse: the draft/disabled distinction was lost, so inactive -> draft.
        DB::table('bots')->where('status', 'inactive')->update(['status' => 'draft']);
    }
};
