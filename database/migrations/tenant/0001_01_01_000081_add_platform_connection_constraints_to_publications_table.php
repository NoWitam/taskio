<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database half of the two constraints B1 deferred: the foreign key from a publication to the
 * connection it goes out on, and the per-connection uniqueness of a published artifact.
 *
 * Identical to central (database/migrations/2026_09_06_000003) — neither constraint involves
 * `workspace_id`, so nothing is translated away. See that file for the arguments: why the delete
 * behaviour is `restrict` rather than `cascade` or `nullOnDelete`, and why artifact uniqueness is per
 * connection rather than global.
 *
 * It has to be a SEPARATE migration from the tenant `publications` table for the ordinary reason: the
 * table it points at is created by 0001_01_01_000080, two files earlier, and a foreign key cannot
 * precede its target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->foreign('platform_connection_id')
                ->references('id')
                ->on('platform_connections')
                ->restrictOnDelete();

            $table->unique(['platform_connection_id', 'remote_id'], 'publications_remote_artifact_unique');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropUnique('publications_remote_artifact_unique');
            $table->dropForeign(['platform_connection_id']);
        });
    }
};
