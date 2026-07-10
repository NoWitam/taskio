<?php

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for workflow DEFINITIONS. Carries workspace_id for
 * shared-mode isolation via WorkspaceScope. The own-database mirror lives in
 * database/migrations/tenant and omits workspace_id (one tenant DB = one workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default('inactive');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();

            // Trigger + its per-type config, optional gate conditions, ordered steps.
            $table->string('trigger_type');
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('steps');

            $table->foreignIdFor(User::class, 'creator_id');

            // Scheduling (populated by the scheduler in a later batch).
            $table->timestamp('last_scheduled_run_at')->nullable();
            $table->timestamp('next_due_at')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
