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
        Schema::create('workspace_user', function (Blueprint $table) {
            $table->foreignIdFor(Workspace::class, 'workspace_id');
            $table->foreignIdFor(User::class, 'user_id');
            $table->timestamps();

            $table->primary(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_user');
    }
};
