<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NULL means "this person has not chosen a language" — and that is a different fact from "this person
 * chose English".
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS BECAME URGENT
 *
 * The column carried `default 'en'` from the day it was added, and for that whole time it was
 * WRITE-ONLY: the locale switcher persisted it and nothing ever read it back, so every server response
 * rendered in `APP_LOCALE` regardless. The default was inert, so nobody noticed it disagreed with the
 * installation.
 *
 * The moment `SetUserLocale` started reading the column, that inert default became a live instruction.
 * On a Polish installation a newly invited user — who has never seen the switcher — would have been
 * handed English validation messages and English relation labels, because a migration written years
 * earlier had guessed on their behalf. A fix for "the server ignores your choice" would have shipped
 * with "the server invents a choice for you", which is the same bug pointed the other way.
 *
 * ------------------------------------------------------------------------------------------------
 * NULLABLE RATHER THAN `default(config('app.locale'))`
 *
 * The cheaper alternative is to change the default to the installation's locale. It is rejected
 * because it writes a CHOICE nobody made:
 *
 *   - a stored value is indistinguishable from a preference, so nothing downstream could ever tell
 *     "wants Polish" from "never asked";
 *   - and the value is frozen at INSERT, so changing `APP_LOCALE` later would move new accounts and
 *     leave every existing one behind — the installation's language and its users' would drift apart
 *     silently, one account at a time.
 *
 * Null costs one nullable column and keeps the distinction the whole feature rests on: an explicit
 * choice wins, an absent one follows the installation and keeps following it.
 *
 * ------------------------------------------------------------------------------------------------
 * EXISTING ROWS ARE NOT TOUCHED
 *
 * No backfill. Every current row holds 'en', and this migration cannot tell which of them are real
 * preferences — so it changes none of them. See the accompanying note: whether those rows should be
 * reset to null is a DATA decision about real accounts, not a schema one, and it is not this
 * migration's to make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // THE BACKFILL IS NOT OPTIONAL — without it this method does not merely lose information, it
        // FAILS. Every row written since `up()` ran holds null, and the database refuses a NOT NULL its
        // existing data already violates: the rollback then aborts partway through a batch and leaves
        // the schema in a state nobody planned. A `down()` that throws is worse than a lossy one,
        // because it is reached in exactly the moment somebody is trying to get back to safety.
        //
        // The value it writes is the same wrong guess `up()` removed, said out loud rather than
        // silently accepted: after a rollback, "never chose" and "chose English" are one fact again.
        DB::table('users')->whereNull('locale')->update(['locale' => 'en']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale')->nullable(false)->default('en')->change();
        });
    }
};
