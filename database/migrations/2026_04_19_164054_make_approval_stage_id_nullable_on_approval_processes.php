<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_processes', function (Blueprint $table) {
            $table->uuid('approval_stage_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('approval_processes', function (Blueprint $table) {
            $table->uuid('approval_stage_id')->nullable(false)->change();
        });
    }
};
