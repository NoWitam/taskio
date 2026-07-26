<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rename `workflow_globals` → `consts` (tenant / own-database schema). The own-DB mirror of the
 * central rename: Phase 2 moves the constants persistence into the Variables module. A PURE,
 * REVERSIBLE structural rename (down() renames back) — the tenant table keeps its columns, its
 * unique `key`, and the creator index. The runtime wire stays `globals.<key>`.
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
