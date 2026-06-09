<?php

use App\Models\User;
use App\Modules\Auth\Models\Group;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_user', function (Blueprint $table) {
            $table->foreignIdFor(Group::class, 'group_id');
            $table->foreignIdFor(User::class, 'user_id');
            $table->timestamps();

            $table->primary(['group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_user');
    }
};
