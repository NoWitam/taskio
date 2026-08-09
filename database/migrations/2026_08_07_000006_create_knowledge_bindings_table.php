<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a KNOWLEDGE BINDING — "this consumer reads that base".
 *
 * It is a table in the KNOWLEDGE module rather than a column on each consumer, and that is the whole
 * architectural point of B6. A `knowledge_base_id` on `bots` would work for bots and would have to be
 * invented again on generation sessions, on workflow steps, on whatever comes next — each with its own
 * mode column, its own validation, its own idea of what "auto" means. One binding table lets Knowledge own
 * the answer once, and lets a consumer be wired up by writing a row instead of by migrating its table.
 *
 * `bindable_type` is a MORPH ALIAS (`'bot'`), never an FQCN. That is the inversion that keeps the
 * dependency one-way: Knowledge may not name the Bot module (pinned literally by
 * KnowledgeModuleBoundaryTest), so the consumer hands over primitives — an alias string and a uuid — and
 * Knowledge stores them without ever resolving them back to a class. Nothing here is a polymorphic
 * RELATION; there is deliberately no `morphTo` on the model, because loading the consumer is exactly the
 * dependency the alias exists to avoid.
 *
 * There is NO foreign key on `bindable_id` for the same reason it is an alias: it may point into any
 * module's table (and, in own-database mode, at a row this connection cannot constrain anyway). A binding
 * whose consumer was deleted is harmless — nothing reads it, because nothing asks for it — and it is
 * cleaned up by the consumer's own delete path.
 *
 * UNIQUE [bindable_type, bindable_id]: ONE base per consumer in the MVP. Several bases per bot is a real
 * future feature, but it needs an answer to "in what order, and which one wins on a conflict" that nobody
 * has yet; a unique index makes today's assumption explicit and its removal a deliberate migration rather
 * than an accident. The uuid half already makes the pair globally unique, so the index carries no
 * workspace_id.
 *
 * `mode` is inline | rag | auto (KnowledgeBindingMode), defaulted to `auto` — the mode that picks for
 * itself, so wiring up a base never requires understanding retrieval first.
 *
 * The own-database mirror omits workspace_id (one tenant DB = one workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // The CONSUMER, addressed by morph alias + uuid. No FK, no morphTo — see the docblock.
            $table->string('bindable_type');
            $table->uuid('bindable_id');

            // Real FK: deleting a base for good takes its bindings with it, so a consumer can never be
            // left pointing at a base that no longer exists.
            $table->foreignUuid('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();

            $table->string('mode')->default('auto');

            $table->timestamps();

            $table->unique(['bindable_type', 'bindable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_bindings');
    }
};
