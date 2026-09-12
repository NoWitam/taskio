<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token store behind "I forgot my password" (R4 / D5).
 *
 * The stock Laravel skeleton created this table alongside `users`; this installation
 * COMMENTED IT OUT because password reset was deliberately deferred (see the R0 plan and
 * the note already sitting in `lang/pl/passwords.php`). It is created here rather than by
 * uncommenting that block, because `0001_01_01_000000_create_users_table` has long since
 * run on every environment and an edit to an applied migration changes nothing.
 *
 * CENTRAL ONLY, and that is not an oversight: `users` is a central (landlord) table — the
 * `User` model is pinned by {@see \App\Traits\UsesCentralConnection} and the tenant
 * migration set contains no `users` table at all. A reset token is keyed by an account,
 * not by a workspace, so there is nothing for an own-database tenant to hold. The broker
 * reads the default connection, and the two reset endpoints are unauthenticated, so
 * `ResolveWorkspace` no-ops and the default connection is never swapped under them.
 *
 * Shape is the framework's, unchanged, because the framework's `DatabaseTokenRepository`
 * is what reads it: `email` is the primary key (one live token per account — requesting a
 * second link invalidates the first), `token` holds a HASH (never the value mailed out),
 * and `created_at` is what both the 60-minute expiry and the 60-second per-address
 * throttle in `config/auth.php` are measured from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
