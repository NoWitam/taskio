<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) comments: make the author polymorphic (User|Bot) so a
 * bot can author a comment when it executes a task. `author_id` already exists as a
 * uuid; we add `author_type` and back-fill it to the 'user' morph alias for every
 * existing comment. The User FK on author_id is dropped because the column is now
 * polymorphic (its target table varies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // author_id was created with foreignIdFor() WITHOUT ->constrained(), so it
            // carries no FK constraint — it simply becomes the polymorphic id alongside
            // the new author_type discriminator.
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
