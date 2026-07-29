<?php

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for the AI cost METER ledger (R2 sub-stage 2a). Each row is one
 * recorded AI spend written by LedgerMeteredAiCall after a metered provider call, across every spender
 * (`ai_text`, `ai_image_edit`, generation sessions later). The rolling-month token gate SUMs
 * `total_tokens` per workspace and refuses further spend once the config cap is met.
 *
 * Carries workspace_id for shared-mode isolation via WorkspaceScope; the own-database mirror in
 * database/migrations/tenant omits it (one tenant DB = one workspace). `session_id` is a future
 * generation session (null for workflow/disk spend) and carries NO FK — the ledger outlives a
 * trashed/purged session. `estimated_cost` is the DOLLAR figure the R2 sub-stage 4 $-gate sums and
 * refuses over (an ESTIMATE from the per-channel price map; never billed on).
 *
 * R2 sub-stage 4 also adds a POLYMORPHIC `actor` (`actor_type`/`actor_id`, mirroring HasCreator's
 * user|workflow_run|bot morph map) tagged ambiently at record time so spend can be summarized PER
 * ACTOR. Both nullable + no FK — the actor survives a session purge / a departed member, and the
 * name is resolved at READ time (never stored). Indexed for the per-actor GROUP BY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable();

            // Coarse spender label (e.g. ai_text, ai_image_edit) — bucketed in reporting.
            $table->string('channel');

            // Provider token counts (0 for an opaque result); total is the gated spend figure.
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            // Advisory $ from a config price map. Wide enough for fractions of a cent per call.
            $table->decimal('estimated_cost', 10, 4)->default(0);

            // The generation session that drove the spend, if any (no FK — see the class docblock).
            $table->uuid('session_id')->nullable();

            // The POLYMORPHIC actor the spend attributes to (user|workflow_run|bot; both nullable, no
            // FK — the attribution outlives the actor, and names resolve at read time, never stored).
            $table->string('actor_type')->nullable();
            $table->uuid('actor_id')->nullable();

            // Small non-secret context (e.g. the model). NEVER the prompt.
            $table->json('meta')->nullable();

            $table->timestamps();

            // The gate's hot path: this workspace, this month.
            $table->index(['workspace_id', 'created_at']);
            $table->index('channel');

            // The per-actor summary GROUP BY (this workspace, this month, by actor).
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
