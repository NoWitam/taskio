<?php

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_tabs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(User::class, 'user_id')->index();
            $table->foreignIdFor(Workspace::class, 'workspace_id')->nullable()->index();
            $table->string('context');
            $table->string('name');
            $table->string('icon')->nullable();
            $table->json('filters');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'workspace_id', 'context']);
            $table->index(['user_id', 'workspace_id', 'context', 'sort_order']);
            $table->unique(['user_id', 'workspace_id', 'context', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_tabs');
    }
};
