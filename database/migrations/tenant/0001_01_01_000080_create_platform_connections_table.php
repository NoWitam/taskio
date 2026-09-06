<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a PLATFORM CONNECTION. Mirrors central
 * `platform_connections` MINUS `workspace_id` — the whole tenant database IS one workspace, so the
 * column would be a constant, and the account uniqueness that leads with it loses that leading column
 * rather than its meaning.
 *
 * THIS MIRROR IS THE POINT OF THE TABLE BEING A TENANT TABLE AT ALL. A customer on their own database
 * is buying "our data is in our database"; access tokens for their accounts sitting in the shared one
 * would make that untrue about the single most sensitive thing they own. See the central migration
 * (database/migrations/2026_09_06_000002_create_platform_connections_table.php) for the arguments this
 * file deliberately does not repeat: why the two tokens are two separate `encrypted` columns rather than
 * one encrypted blob, what encryption at rest does and does not buy, why the metadata is deliberately in
 * the clear, and why a disconnect keeps the row.
 *
 * The parity is asserted, not hoped for: PublishingTenantDatabaseTest compares the two column sets
 * directly, because the same model reads both databases and a column present on one side only is a
 * defect visible to exactly one workspace — and invisible to everybody testing on the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('platform', 32);

            $table->string('external_account_id');
            $table->string('account_name')->nullable();

            $table->text('access_token');
            $table->text('refresh_token')->nullable();

            $table->jsonb('scopes')->default('[]');

            $table->timestamp('expires_at')->nullable();

            $table->string('status', 32)->default('active');
            $table->timestamp('last_refreshed_at')->nullable();

            $table->string('failure_code', 64)->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['platform', 'external_account_id'], 'platform_connections_account_unique');

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
