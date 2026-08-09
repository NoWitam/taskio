<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Knowledge module's FIRST migration in both schema trees: it installs the `vector` extension
 * (pgvector) the knowledge CHUNK table's embedding column and its HNSW index depend on.
 *
 * It is a migration rather than an ops runbook step because the tenant mirror is where this really
 * matters: a freshly provisioned own-database workspace starts from an EMPTY database that inherits
 * nothing from the central one, so without this the very next migration would fail on an unknown
 * `vector` type. Running it centrally too keeps the two trees identical and makes a fresh dev/CI
 * database self-sufficient.
 *
 * `IF NOT EXISTS` makes it idempotent, and `down()` deliberately does NOTHING: an extension is a
 * DATABASE-wide object that other tables (present or future) may depend on, so rolling back one
 * module's migration must never drop it out from under them. Dropping the tables is enough to undo
 * what this module added.
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
        // Intentionally empty — see the class docblock (a shared, database-wide object).
    }
};
