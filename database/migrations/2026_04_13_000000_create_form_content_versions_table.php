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
        Schema::create('form_content_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('content');
            $table->json('json_schema');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['form_id', 'version']);
            $table->index(['form_id', 'created_at']);
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->timestamp('content_updated_at')->nullable()->after('content_version');
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->foreignUuid('form_content_version_id')
                ->nullable()
                ->after('content_version')
                ->constrained('form_content_versions')
                ->nullOnDelete();
        });
    }
};
