<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a labelable row as ENFORCED — materialized onto a file by a folder's governance (F3),
 * as opposed to a manual label the user attached. Enforced rows are recomputed from the ancestor
 * folders' enforced labels and are locked in the UI; manual rows stay user-controlled.
 *
 * The pivot is SHARED with Task, which never writes this — its rows stay `false` (the default),
 * so tasks are entirely unaffected. Indexed so the enforcer's "the file's enforced rows" reads
 * stay cheap.
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
