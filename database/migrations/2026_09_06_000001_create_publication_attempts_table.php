<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT WE TOLD A PLATFORM, AND WHAT IT SAID BACK — one append-only row per call.
 *
 * R4 B1. This is the evidence trail behind an irreversible act, and it is a TABLE rather than a column
 * on `publications` for three reasons, in order of weight:
 *
 *   A RETRY IS A SECOND ATTEMPT. A column would be overwritten by it, destroying precisely the record
 *     needed to answer "did the FIRST attempt create something?". That question is the whole content of
 *     `needs_reconcile`, and answering it from a row that the retry has already overwritten is not
 *     possible.
 *
 *   THE PHASES ARE SEPARATE EVENTS. A publish is two calls plus, sometimes, a reconciliation enquiry.
 *     Collapsing them loses the one fact a crash makes interesting — how far we got — which the
 *     `phase` column states directly.
 *
 *   THE PAYLOAD GROWS AND THE LIST QUERY SHOULD NOT PAY FOR IT. `request` holds what would have been
 *     sent; the publications list reads none of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * APPEND-ONLY, AND NEVER ROLLED BACK
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `created_at` alone, no `updated_at`, no soft delete — the same shape `knowledge_relation_events`
 * uses, for the same reason. A row here says "this happened", and something that happened does not get
 * amended.
 *
 * The consequence worth stating: a write here is deliberately NOT wrapped in a transaction with the
 * status change that follows it. An attempt that was made and then failed to update the row's status
 * must still be on record — rolling the evidence back with the outcome is how a system ends up unable
 * to explain its own state.
 *
 * NO FOREIGN KEY on `publication_id`. The log OUTLIVES its subject: a purged publication's trail is
 * what remains to explain a post still sitting on somebody's timeline. Orphans are the intended end
 * state; the column stays indexed so a publication's history is one lookup while it exists.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `request` — WHAT WOULD HAVE BEEN SENT, WITH NOTHING THAT COULD AUTHENTICATE IT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The caption, the media ids, the options, the destination. NOT the Authorization header, NOT an access
 * or refresh token, NOT a signed upload URL that embeds one, NOT a cookie. B1 has no tokens to leak,
 * which is exactly why the rule is written down now: the shape is being set while the temptation does
 * not exist yet, so B2's adapters inherit it rather than argue with it.
 *
 * The own-database mirror omits workspace_id —
 * database/migrations/tenant/0001_01_01_000079_create_publication_attempts_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // No FK: the log outlives its subject. See the docblock.
            $table->uuid('publication_id')->index();

            $table->string('platform', 32);
            // PublicationAttemptPhase: draft | publish | reconcile.
            $table->string('phase', 16);

            // A BOOLEAN rather than an outcome vocabulary, because there are exactly two answers and a
            // third would be a different thing entirely (an attempt still in flight is not a row here —
            // it is the publication sitting in `publishing`).
            $table->boolean('succeeded');

            // Which attempt of this publication this was — `publications.attempts` at the time. Carried
            // on the row so the trail reads without recomputing anything from timestamps.
            $table->unsignedSmallInteger('attempt')->default(1);

            // What the call produced, when it produced an identifier.
            $table->string('remote_draft_id')->nullable();
            $table->string('remote_id')->nullable();

            // What would have been sent. NEVER a credential — see the docblock.
            $table->jsonb('request')->nullable();

            // A stable code, never the platform's own prose.
            $table->string('failure_code', 64)->nullable();
            $table->jsonb('failure_context')->nullable();

            // The polymorphic actor: on an attempt, whoever (or whatever) caused the call.
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            // Append-only: creation time only, no updated_at.
            $table->timestamp('created_at')->nullable();

            // The one read: a publication's trail, newest last.
            $table->index(['publication_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_attempts');
    }
};
