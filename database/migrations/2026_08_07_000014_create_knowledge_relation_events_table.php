<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The APPEND-ONLY audit trail for typed relations: who asserted what, and who changed or ended it.
 * The own-database mirror omits workspace_id.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY NOT THE `changelogs` TABLE
 *
 * The Changelog module was the obvious reuse and it does not fit, for four independent reasons — the
 * first of which is on its own decisive:
 *
 *   1. `changelogs` EXISTS ONLY IN THE CENTRAL SCHEMA. There is no tenant mirror. A relation belonging
 *      to an own-database workspace resolves to the tenant connection, where that table is simply not
 *      there — so half the product could not log at all, and would find out at runtime.
 *   2. `causer_id` is a NOT NULL foreign key to `users`. A relation asserted by the AI composer has no
 *      user, and the whole point of `origin` is being able to ask later what is only there because a
 *      model said so. Attributing it to whoever happened to click accept would destroy that.
 *   3. The trait is driven by MODEL EVENTS — created/updated/deleted. The verbs here are domain ones:
 *      `end`, `supersede`, `retract`, `promote`. All four look identical to an `updated` hook, which
 *      is exactly the distinction an audit trail exists to preserve.
 *   4. `changelogs` carries `updated_at`. This module's audit posture (see knowledge_entry_revisions)
 *      is append-only WITHOUT one: a record that can be modified is not evidence.
 *
 * ------------------------------------------------------------------------------------------------
 * NO FOREIGN KEY ON relation_id — AND THAT IS THE POINT
 *
 * A cascade would delete a relation's history along with the relation, which would erase precisely the
 * `delete` event that explains where it went. The log OUTLIVES its subject; that is what makes it a
 * log rather than a detail table. Orphan rows are the intended end state, and `relation_id` stays
 * indexed so a relation's history is still one lookup while it exists.
 *
 * `before`/`after` hold the relation's own shape, so the trail is readable without joining anything
 * that may since have moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_relation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            // No FK: the log outlives its subject. See the docblock.
            $table->uuid('relation_id')->index();
            $table->uuid('knowledge_base_id')->index();

            // create | update | end | supersede | retract | delete | promote.
            $table->string('op', 20);

            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            // The drafting session a composer-authored op came from, when there was one.
            $table->uuid('draft_session_id')->nullable();

            // The polymorphic HasCreator pair: on an event the actor IS its creator.
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            // Append-only: creation time only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_relation_events');
    }
};
