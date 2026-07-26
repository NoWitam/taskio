<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for workflow GLOBALS — user-created, workspace-scoped, typed
 * LITERAL constants (e.g. nazwa_marki = "Taskio"). A global becomes a `globals.<key>` reference
 * available in EVERY workflow, resolved from its stored value at run time. Carries workspace_id
 * for shared-mode isolation via WorkspaceScope; the own-database mirror lives in
 * database/migrations/tenant and omits workspace_id (one tenant DB = one workspace).
 *
 * `descriptor` is the type descriptor (base + nullable + array + options[enum] + fields[object])
 * from the Phase 1-2 type system; `value` is the literal, shaped to match the descriptor. There is
 * NO cross-variable reference and NO computed evaluation — a global is a stored constant only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_globals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // Human label + the stable slug used in the `globals.<key>` reference path (a dotted
            // path read via Arr::get, so the key is a safe identifier — letters/digits/underscores).
            $table->string('name');
            $table->string('key');

            // The type descriptor (JSON) and the literal value (JSON — scalar, list, or object;
            // nullable when the descriptor is nullable).
            $table->json('descriptor');
            $table->json('value')->nullable();

            // Polymorphic creator (always a human User for a global — no engine authors one), stored
            // as id + nullable morph-type discriminator per the app-wide HasCreator convention.
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            // The key is unique PER workspace (the reference namespace is per-workspace). NOTE:
            // Postgres treats NULLs as distinct, so a null workspace_id (no active workspace) is not
            // covered here — the request-layer scoped uniqueness check is the authoritative guard.
            $table->unique(['workspace_id', 'key']);
            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_globals');
    }
};
