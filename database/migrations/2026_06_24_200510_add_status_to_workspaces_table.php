<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Provisioning lifecycle for own-database workspaces. Existing rows
            // (and every shared workspace) default to 'ready' so current flows
            // are unaffected.
            $table->string('status')->default('ready')->index()->after('db_mode');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
