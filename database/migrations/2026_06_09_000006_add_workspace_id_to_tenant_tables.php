<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Top-level tenant tables that gain a nullable workspace_id (shared mode).
     * Child/detail tables (approval_stages, form_content_versions) are isolated
     * through their scoped parent and are intentionally left without a column.
     */
    private array $tables = [
        'forms',
        'form_submissions',
        'form_reports',
        'tasks',
        'comments',
        'files',
        'approval_pipelines',
        'approval_processes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignUuid('workspace_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('workspace_id');
            });
        }
    }
};
