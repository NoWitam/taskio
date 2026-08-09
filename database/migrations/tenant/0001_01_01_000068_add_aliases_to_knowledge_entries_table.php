<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror of the ALIASES column. See the central migration for why the inflected forms
 * are stored rather than inferred, and why they feed the mention layer only — a `[[wikilink]]` still
 * resolves by slug and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->jsonb('aliases')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
