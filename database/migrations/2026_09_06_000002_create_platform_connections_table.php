<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) schema for a PLATFORM CONNECTION — one authorized account this workspace
 * may publish through.
 *
 * R4 B2. This is the most sensitive table in the application: two of its columns are live credentials
 * for somebody else's account, and a leak here is not a data-protection incident about our users, it is
 * the ability to post publicly as them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT IS A TENANT TABLE, AND THAT IS A PRODUCT DECISION BEFORE IT IS A TECHNICAL ONE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * There is a mirror at database/migrations/tenant/0001_01_01_000080. It would have been simpler to keep
 * every connection centrally — one place to sweep, one connection for the refresher — and it would have
 * been wrong. A customer who pays for their own database is buying the statement "our data is in our
 * database"; holding their access tokens in the shared one while their publications live in theirs makes
 * that statement false about the single most sensitive thing they own.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * TWO SEPARATE `encrypted` COLUMNS, NOT ONE ENCRYPTED BLOB OF JSON
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `workspaces.db_password` is the ONE existing precedent for encryption in this repository, and it is a
 * single column with an `'encrypted'` cast. This copies it rather than inventing a shape.
 *
 * The alternative that suggests itself — one `credentials` json column, encrypted whole — was rejected
 * for a specific reason: the two tokens have DIFFERENT LIFETIMES and different renewal paths. A Google
 * refresh token is issued once and survives every access-token renewal; a Meta connection has no
 * refresh token at all. A blob makes "write the new access token and keep the refresh token" a
 * read-modify-write of a decrypted structure, which is exactly the operation that loses a refresh token
 * to a race or to a partially-populated array. Two columns make each write name what it writes.
 *
 * `text`, not `string`: a Google refresh token is comfortably over 255 characters BEFORE Laravel's
 * envelope encryption wraps it in base64 with an IV and a MAC, which roughly doubles it again.
 *
 * WHAT ENCRYPTION AT REST ACTUALLY BUYS HERE, stated honestly because it is easy to overstate: the
 * application decrypts with APP_KEY, so anything that can run our code can read these. What it defends
 * against is every path where the bytes travel WITHOUT the code — a database dump in a backup bucket, a
 * replica, a support export, and — the one that bit this repository's own reasoning — the global query
 * log. `LogMiddleware` writes every statement with its values interpolated; because the cast encrypts on
 * the way into the attribute, what that log receives is ciphertext. A json column encrypted by hand at
 * a call site would have leaked the moment one write forgot.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY IN THE CLEAR
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `external_account_id`, `account_name`, `scopes`, `expires_at`, `status`. None of them authenticates
 * anything, all of them are needed to render a list, to decide whether a refresh is due, and to key the
 * uniqueness below. Encrypting them would make the table unqueryable in exchange for hiding a channel
 * id that is printed on the channel's own public page.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * UNIQUENESS: ONE ACCOUNT, ONE CONNECTION, PER WORKSPACE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * (workspace_id, platform, external_account_id). Connecting the same channel twice would give a
 * workspace two rows with two tokens for one account, of which one is stale — and a publication would
 * be routed by whichever the UI happened to list first. Re-connecting therefore UPDATES the existing
 * row, including a soft-deleted one, which is why the index has to survive a disconnect.
 *
 * It does survive: a disconnect is a soft delete, so the row stays and the unique index keeps holding
 * it. That is the intended cost. A partial index excluding trashed rows would let a disconnect-then-
 * reconnect leave two rows and a dangling `platform_connection_id` on every publication that pointed at
 * the first — which is the history this module exists to keep.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * NO FOREIGN KEY TO `users` ON `creator_id`
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The polymorphic creator (ADR-0015) is an id plus a morph alias and never carries one, here as
 * everywhere else. A connection outlives the person who made it, on purpose: an account authorized by
 * somebody who has left the company is still the workspace's account.
 *
 * The own-database mirror omits workspace_id (one tenant DB = one workspace) and drops it from the
 * unique index with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // PublishingPlatform, minus `dry_run` — a destination that publishes nothing has no account
            // to connect. Nothing in the schema can express that; the OAuth provider registry does.
            $table->string('platform', 32);

            // The PLATFORM's identifier for the account: a YouTube channel id, a Facebook page id. Its
            // format, not ours, so a plain string rather than a uuid.
            $table->string('external_account_id');
            // What to show a human. Nullable because a platform may decline to tell us, and a connection
            // with no display name is still a working connection.
            $table->string('account_name')->nullable();

            // THE CREDENTIALS. See the docblock for why these are two columns and why `text`.
            $table->text('access_token');
            // Null is the NORMAL state for a Meta connection, which issues no refresh token at all.
            $table->text('refresh_token')->nullable();

            // What the platform actually granted — not what we asked for. The two differ when a user
            // unticks a permission on the consent screen, and the difference is the reason a publish
            // will later fail in a way nobody could have predicted from our own config.
            $table->jsonb('scopes')->default('[]');

            // When the ACCESS token stops working. Null means the platform did not say — which is a
            // real answer for some grants, and is why the refresh sweep selects on NOT NULL rather than
            // treating null as "expired long ago" and hammering the token endpoint forever.
            $table->timestamp('expires_at')->nullable();

            // PlatformConnectionStatus. Written only by PlatformConnectionManager.
            $table->string('status', 32)->default('active');
            $table->timestamp('last_refreshed_at')->nullable();

            // WHY it stopped working, as a stable code the UI translates — `refresh_failed`,
            // `credentials_unreadable`, `refresh_unsupported`, `disconnected_by_user`. Never the
            // platform's own prose, and never anything derived from a credential: a token endpoint's
            // error response is the one body in this application most likely to contain a token.
            $table->string('failure_code', 64)->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['workspace_id', 'platform', 'external_account_id'], 'platform_connections_account_unique');

            // The refresh sweep: everything active whose token expires before the lead time.
            $table->index(['workspace_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
