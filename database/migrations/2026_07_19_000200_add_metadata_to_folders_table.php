<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders become first-class, previewable items: a folder now carries the same optional
 * metadata a file does — a free-text `description` and a chosen `icon` (an IconEnum value,
 * rendered inside the folder glyph). Both nullable: a plain folder has neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('icon')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn(['description', 'icon']);
        });
    }
};
