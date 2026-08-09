<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-database (db_mode = own) install of the `vector` extension (pgvector).
 *
 * This is the copy that MATTERS. A provisioned tenant database is created empty and inherits nothing
 * from the central database — extensions are per-database objects — so without this the very next
 * migration would fail on an unknown `vector` type and provisioning would break for own-database
 * workspaces only, which is the kind of failure that surfaces late and in production.
 *
 * Idempotent, and `down()` deliberately does nothing (a database-wide object other tables may use).
 * See the central mirror for the full rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
    }
};
