<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the central `templates` content-recipe reconciliation — see
 * the central `..._reshape_templates_to_content_recipe` migration for the rationale. An own-DB tenant that
 * was provisioned before the sub-stage-1 rework kept the old `templates` shape; this closes that drift.
 * Guarded + no-op on a tenant DB already on the new shape. (No workspace_id here — one tenant DB = one
 * workspace.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('templates')) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) {
            if (!Schema::hasColumn('templates', 'content_type')) {
                $table->string('content_type')->nullable();
            }
            if (!Schema::hasColumn('templates', 'content')) {
                $table->json('content')->nullable();
            }
        });

        foreach (['type', 'prompt_body', 'parameters'] as $column) {
            if (Schema::hasColumn('templates', $column)) {
                Schema::table('templates', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('templates')) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) {
            if (!Schema::hasColumn('templates', 'type')) {
                $table->string('type')->nullable();
            }
            if (!Schema::hasColumn('templates', 'prompt_body')) {
                $table->text('prompt_body')->nullable();
            }
            if (!Schema::hasColumn('templates', 'parameters')) {
                $table->json('parameters')->nullable();
            }
        });

        foreach (['content_type', 'content'] as $column) {
            if (Schema::hasColumn('templates', $column)) {
                Schema::table('templates', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
