<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the central polymorphic-author swap.
 * The tenant comments table carries `author_id` as a plain uuid WITHOUT a FK, so
 * there is no constraint to drop — only the `author_type` column is added and the
 * existing rows back-filled to the 'user' morph alias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('author_type')->nullable()->after('author_id');
            $table->index(['author_type', 'author_id']);
        });

        DB::table('comments')
            ->whereNotNull('author_id')
            ->update(['author_type' => 'user']);
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['author_type', 'author_id']);
            $table->dropColumn('author_type');
        });
    }
};
