<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignIdFor(User::class, 'owner_id');
            $table->string('db_mode')->default('shared');

            // Connection details for db_mode = own (populated in Faza 2).
            $table->string('db_host')->nullable();
            $table->string('db_port')->nullable();
            $table->string('db_database')->nullable();
            $table->string('db_username')->nullable();
            $table->text('db_password')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
