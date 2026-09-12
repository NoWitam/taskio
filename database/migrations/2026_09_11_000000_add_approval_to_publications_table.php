<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R4 B6 — a publication becomes something that can be REVIEWED BEFORE IT GOES OUT.
 *
 * Two columns, and the second one is the interesting one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * approval_pipeline_id — THE SAME COLUMN `tasks` ALREADY CARRIES, ON PURPOSE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Nullable, `restrict`-free, `nullOnDelete` — a byte-for-byte copy of
 * `2026_04_19_000003_add_approval_pipeline_id_to_tasks_table.php`, because a publication is the SECOND
 * implementation of `App\Modules\Approvals\Interfaces\Approvable` and the whole value of that interface
 * is that the second one looks like the first. Deleting a pipeline must not delete the publications that
 * were reviewed through it: the record of "this went out after review" outlives the pipeline definition,
 * and a cascade here would erase it.
 *
 * `approval_processes` needs no change at all — it addresses its subject polymorphically
 * (`approvable_type`/`approvable_id`), and `publication` is already in the app-wide enforced morph map.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * arm_on_approval_at — THE INTENT A REVIEW IS HOLDING, AND WHY IT IS NOT `scheduled_at`
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication created by a workflow step with a review pipeline attached is a DRAFT that has already
 * decided when it wants to go out. It cannot be armed yet — the whole point of the review is that arming
 * follows the approval — so the moment has to be parked somewhere until the last approver says yes.
 *
 * THE ALTERNATIVE WAS `scheduled_at` ON THE DRAFT, and it was rejected for one reason that decides it:
 * the hook that arms on approval must be able to tell "somebody wanted this armed automatically" from
 * "a person is drafting and will press Schedule themselves". `scheduled_at` on a draft ALREADY MEANS the
 * second thing — `StorePublicationRequest` accepts a moment on create and `PublicationDTO` documents it
 * as intent that is explicitly NOT an arming — so reusing it would have made every hand-drafted
 * publication with a time on it arm itself the moment a review passed. That is the one behaviour the
 * manual path must never gain.
 *
 * So the discriminator and the moment are ONE nullable column rather than a boolean plus a timestamp:
 *
 *   NULL          nothing arms this publication by itself. Every human draft, and every automated one
 *                 with no review pipeline (the step arms those directly and never writes this column).
 *   an instant    when the review concludes in approval, arm for THIS moment.
 *
 * "Publish as soon as it is allowed to" is stored as the instant the row was created — i.e. a moment
 * already past by the time any approver reads it. That is not a lie the reader has to decode: in a module
 * where NOTHING publishes synchronously, "immediately" has always meant "armed for a moment that has
 * passed, claimed by the next due-sweep pass", which is exactly what `POST …/schedule` with `now()`
 * produces for a person. One encoding, one meaning.
 *
 * THE COST, NAMED: a review that concludes AFTER the intended moment arms for a moment already gone, and
 * the sweep claims it within the minute. The publication goes out late rather than not at all. The
 * alternative — refusing to arm a moment that has passed, which is what the HTTP arming door does —
 * would mean an approver pressing Approve and NOTHING HAPPENING, silently, which is strictly worse for
 * the one act in this module whose entire purpose is to be deliberate.
 *
 * NOT INDEXED, and that is the honest state: nothing selects on this column. The hook reads it from a row
 * it already holds. An index for a query nobody has written is a guess about a shape nobody has seen.
 *
 * The own-database mirror is database/migrations/tenant/0001_01_01_000082_add_approval_to_publications_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->foreignUuid('approval_pipeline_id')
                ->nullable()
                ->constrained('approval_pipelines')
                ->nullOnDelete();

            $table->timestamp('arm_on_approval_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_pipeline_id');
            $table->dropColumn('arm_on_approval_at');
        });
    }
};
