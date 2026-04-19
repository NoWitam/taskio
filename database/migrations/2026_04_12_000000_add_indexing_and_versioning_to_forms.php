<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // Indexing axis (independent from activation)
            $table->timestamp('indexed_at')->nullable()->after('enabled_at')->index();

            // Content versioning — incremented on each content change when enabled
            $table->unsignedInteger('content_version')->default(0)->after('indexed_at');

            // Backup of content from before disabling (allows restore on re-enable)
            $table->json('content_backup')->nullable()->after('content_version');

            // Internal-only backup of index metadata before unindexing
            $table->json('index_backup')->nullable()->after('content_backup');
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            // Track which content version a submission was created against
            $table->unsignedInteger('content_version')->default(0)->after('data');
        });
    }
};
