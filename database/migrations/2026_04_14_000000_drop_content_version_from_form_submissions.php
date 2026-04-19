<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove the deprecated content_version integer column from form_submissions.
     * Replaced by the form_content_version_id FK to form_content_versions table.
     */
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropColumn('content_version');
        });
    }
};
