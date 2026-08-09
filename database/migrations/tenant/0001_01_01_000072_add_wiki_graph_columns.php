<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror of the columns the typed-relation layer needs on the three tables it does not
 * own: the entry's TYPE, the base's relation-type ALLOW-LIST, and the three drafting-session columns
 * the composer stages will fill.
 *
 * See the central migration for why `entry_type` stays null on every existing row (and why the
 * validation built on it is therefore advisory), why `relation_types` distinguishes NULL from `[]`,
 * and why all three session columns land in one migration rather than one per stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->string('entry_type', 30)->nullable()->index();
        });

        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->jsonb('relation_types')->nullable();
        });

        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->jsonb('resolution_set')->nullable();
            $table->jsonb('graph_ops')->nullable();
            $table->jsonb('applied_ops')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_draft_sessions', function (Blueprint $table) {
            $table->dropColumn(['resolution_set', 'graph_ops', 'applied_ops']);
        });

        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropColumn('relation_types');
        });

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('entry_type');
        });
    }
};
