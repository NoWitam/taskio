<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a KNOWLEDGE BASE — the container a workspace teaches the
 * product ONCE and every AI consumer later reads back. A base owns its entries, the METADATA SCHEMA
 * those entries are validated against, and the CHARTER (free prose describing what belongs in it).
 *
 * Carries workspace_id for shared-mode isolation via WorkspaceScope; the own-database mirror in
 * database/migrations/tenant OMITS it (one tenant DB = one workspace).
 *
 * `metadata_schema` is an ordered list of `{key, label, descriptor}` where `descriptor` is a
 * descriptor from the shared Variables type system (`{base, nullable, array, options?, fields?}`) —
 * the SAME authority a constant's type uses, so an entry's metadata can never carry a shape the
 * type system cannot describe. Stored as jsonb rather than a side table because it is read whole,
 * written whole, and never queried by field.
 *
 * `language` is a short BCP-47-ish tag (`pl`, `en`, `pt-BR`) defaulted from the app locale at write
 * time: retrieval quality depends on knowing which language a base is written in, and a mixed-language
 * base is a base, not an entry, level decision.
 *
 * Soft-deleted so a base lands in a TRASH the owner can restore whole (its entries are cascaded by
 * the service, not by the database, so the cascade is reversible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            $table->string('name');
            $table->text('description')->nullable();

            // Free prose: what this base is FOR and what belongs in it. Read by a human today and
            // (later) injected as retrieval framing — never parsed.
            $table->text('charter')->nullable();

            // The ordered {key, label, descriptor} list every entry's `metadata` is validated against.
            $table->jsonb('metadata_schema')->default('[]');

            $table->string('language', 5);

            // Polymorphic creator per the app-wide HasCreator convention.
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_bases');
    }
};
