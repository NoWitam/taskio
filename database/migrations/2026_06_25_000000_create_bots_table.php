<?php

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for bots. Carries workspace_id for shared-mode
 * isolation via WorkspaceScope. The own-database mirror lives in
 * database/migrations/tenant and omits workspace_id (one tenant DB = one workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->text('description')->nullable();

            // Text module (mandatory persona).
            $table->text('persona')->nullable();
            $table->text('style')->nullable();
            $table->json('dictionary')->nullable();
            $table->json('phrases')->nullable();
            $table->json('prohibitions')->nullable();

            // Task-execution module: {enabled: bool, knowledge_source: string|null, tools: string[]}.
            $table->json('task_execution')->nullable();

            // Visual / Voice placeholders (no logic yet).
            $table->json('visual')->nullable();
            $table->json('voice')->nullable();

            $table->foreignIdFor(User::class, 'creator_id');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
