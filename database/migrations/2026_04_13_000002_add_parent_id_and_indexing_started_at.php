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
        Schema::table('form_content_versions', function (Blueprint $table) {
            $table->foreignUuid('parent_id')
                ->nullable()
                ->after('form_id')
                ->constrained('form_content_versions')
                ->nullOnDelete();
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->timestamp('indexing_started_at')->nullable()->after('indexed_at');
        });
    }
};
