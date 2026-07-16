<?php

use App\Modules\Auth\Models\Group;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_permission', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Group::class, 'group_id');
            $table->string('permission');

            $table->unique(['group_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_permission');
    }
};
