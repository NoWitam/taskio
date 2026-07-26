<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rename `workflow_globals` → `consts` (central / shared-database schema). Phase 2 of the Variables
 * module extraction: the persistence for user-created typed LITERAL constants moves from Workflows to
 * the Variables module and the table takes the shorter `consts` name.
 *
 * This is a PURE structural rename — every column, index and the unique(['workspace_id','key'])
 * constraint carries over untouched — and it is REVERSIBLE (down() renames back), NOT a drop/recreate,
 * so no data is lost. The RUNTIME WIRE is unaffected: references still resolve as `globals.<key>` (the
 * wire is deliberately decoupled from the table name). The own-database mirror lives in
 * database/migrations/tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('workflow_globals', 'consts');
    }

    public function down(): void
    {
        Schema::rename('consts', 'workflow_globals');
    }
};
