<?php

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a workflow RUN: one execution of a workflow's
 * ordered steps. Carries workspace_id for shared-mode isolation via WorkspaceScope; the
 * own-database mirror in database/migrations/tenant omits it.
 *
 * workflow_id carries NO FK (project-wide no-cross-DB-FK convention, matching bot_actions'
 * task_id/bot_id). creator_id is nullable: NULL = engine-started (event/schedule), a uuid
 * = a manual run (Batch 3). The (state, started_at) index backs the stale-claim reaper's
 * `state='running' AND started_at < cutoff` sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable()->index();
            $table->uuid('workflow_id')->index();

            $table->string('state')->default('pending');
            $table->string('origin')->default('event');
            $table->string('trigger_type');
            $table->json('trigger_payload')->nullable();

            // Live step-output accumulator: steps.<key> => output, read by `{{...}}` refs.
            $table->json('context')->nullable();

            // Re-trigger depth guard (a workflow-authored change that re-fires a workflow):
            // origin_run_id links a child run to the run that caused it.
            $table->unsignedInteger('depth')->default(0);
            $table->uuid('origin_run_id')->nullable();

            $table->uuid('creator_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            // The reaper filters running rows by started_at; index the pair it scans.
            $table->index(['state', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
