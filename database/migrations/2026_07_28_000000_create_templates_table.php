<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for generation TEMPLATES (R2 sub-stage 1) — a reusable,
 * workspace-scoped RECIPE for a finished post: an identity (name, description), a `content_type`
 * (a ContentTypeRegistry id — post / post_with_image / video_script), a per-part `content` map
 * (D3: keyed by the type's part keys — a text_body/script part `{markdown}` carrying `@[variable]`
 * directives, an image_plan part `{base, filters[]}`, a scene_plan part `{scenes:[…]}`), and a
 * `slots` list of DECLARED typed inputs ({name, description?, descriptor}) the content references.
 *
 * Mirrors the Constant / CustomFunction persistence: identity is the uuid, `name` is a user-facing
 * label only (NOT unique), `slots` + `content` ride the json 'array' cast. Carries workspace_id
 * for shared-mode isolation via WorkspaceScope; the own-database mirror lives in
 * database/migrations/tenant and omits workspace_id (one tenant DB = one workspace). A template is a
 * DEFINITION only in this sub-stage: no generation Session runs yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // User-facing label + optional description. The name is NOT an identity (no unique) — the
            // identity is the uuid, so a rename never breaks a stored reference to the template.
            $table->string('name');
            $table->text('description')->nullable();

            // The recipe SHAPE (a ContentTypeRegistry id) — its parts drive the editor / validation /
            // render. The DECLARED typed slots list ({name, description?, descriptor}) and the per-part
            // authored `content` map (both JSON).
            $table->string('content_type');
            $table->json('slots');
            $table->json('content');

            // Polymorphic creator per the app-wide HasCreator convention (id + nullable morph type).
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
