<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for user CUSTOM FUNCTIONS (Phase 3a) — a reusable, workspace-scoped
 * variable transform: ONE input type, a list of typed named ARGS, ONE return type, and a saved BODY
 * pipeline over {input + args} that terminates in the return type. A function may reference OTHER
 * functions in its body (nesting); write-time cycle detection keeps the reference graph acyclic.
 *
 * Carries workspace_id for shared-mode isolation via WorkspaceScope; the own-database mirror lives in
 * database/migrations/tenant and omits workspace_id (one tenant DB = one workspace). Identity is the
 * uuid (the wire op id is `fn:<uuid>`); `name` is a user-facing label only and is NOT unique. `args`
 * and `body` are JSON. There is NO catalog surfacing / execution yet (Phase 3b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_functions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // User-facing label + optional description. The name is NOT an identity (no unique) — the
            // wire/raw identity is the uuid (`fn:<uuid>`), so a rename never breaks a stored reference.
            $table->string('name');
            $table->text('description')->nullable();

            // The function signature: one input type + one return type (VariableType ids) and the typed
            // named args ({name, description?, type} list, JSON).
            $table->string('input_type');
            $table->json('args');
            $table->string('return_type');

            // The saved body pipeline over {input + args} (JSON), terminating in the return type.
            $table->json('body');

            // Polymorphic creator per the app-wide HasCreator convention (id + nullable morph type).
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_functions');
    }
};
