<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEARCHING A JSON COLUMN, in a product written in Polish.
 *
 * `form_submissions.data` is the only column in the application searched through
 * {@see \App\Models\Concerns\Searchable} that holds a JSON DOCUMENT rather than prose, and Eloquent's
 * `array` cast writes that document with every non-ASCII character escaped: `Plaża` is stored
 * as the ASCII bytes `Pla\u017ca`. The scope compiles to `"data"::text ilike ?`, so until this
 * was closed a user typing `plaża` searched escaped text for raw UTF-8 and got NOTHING — for every
 * answer containing ą/ć/ę/ł/ń/ó/ś/ź/ż, which is most Polish answers. Nothing failed; the list simply came
 * back empty, which reads like "no such submission" rather than like a bug.
 *
 * The fix re-spells the term into the stored encoding at the call site
 * ({@see \App\Modules\Forms\Services\FormSubmissionService}), so it works on rows that already exist
 * rather than on rows written after a migration.
 *
 * These tests pin PROPERTIES — a Polish answer is findable; an ASCII term is untouched; the term is
 * still a literal — not the particular escape sequences, so the encoding can be replaced (by a
 * `json:unicode` cast and a data migration, say) without rewriting them.
 */
class FormSubmissionJsonSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->form = Form::factory()->enabled()->create();
    }

    private function submit(array $data): FormSubmission
    {
        return FormSubmission::create([
            'form_id' => $this->form->id,
            'submittable_type' => Form::class,
            'submittable_id' => $this->form->id,
            'data' => $data,
            'approved_at' => now(),
            'creator_id' => $this->user->id,
        ]);
    }

    /** @return array<int, string> the ids the search matched */
    private function searchIds(string $term): array
    {
        return $this->actingAs($this->user)
            ->getJson("/api/forms/{$this->form->id}/submissions?search=" . urlencode($term))
            ->assertOk()
            ->json('data.*.id');
    }

    /**
     * THE STORAGE-FORMAT CANARY, and the reason the fix is shaped the way it is.
     *
     * Everything below depends on the column holding ESCAPED Unicode. Three separate things could
     * change that without anyone noticing: switching the cast to `json:unicode`, registering a
     * custom encoder via `Json::encodeUsing()`, or migrating the column from `json` to `jsonb`
     * (which normalises on write and would hand `::text` back raw UTF-8). Each would silently
     * invert the fix — the search would start missing the rows it now finds. This test goes red
     * first, and says which of the two encodings is actually on disk.
     */
    public function test_the_column_physically_stores_escaped_unicode(): void
    {
        $submission = $this->submit(['miejsce' => 'Plaża']);

        $stored = DB::table('form_submissions')
            ->where('id', $submission->id)
            ->value(DB::raw('data::text'));

        // Pure ASCII on disk — `ż` went in as `\u017c` and is not there as a character…
        $this->assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $stored);
        $this->assertStringNotContainsString('Plaża', $stored);

        // …while still meaning what was submitted. Asserted on the decoded value rather than on the
        // exact escape sequence, so this stays a test of the FORMAT, not of PHP's spelling of it.
        $this->assertSame(['miejsce' => 'Plaża'], json_decode($stored, true));
    }

    /**
     * The headline regression: an answer with Polish characters was unfindable.
     */
    public function test_an_answer_with_polish_characters_is_found(): void
    {
        $lodz = $this->submit(['miejsce' => 'Łódź, ulica Piotrkowska']);
        $this->submit(['miejsce' => 'Gdansk, ulica Dluga']);

        $this->assertSame([$lodz->id], $this->searchIds('Łódź'));
        $this->assertSame([$lodz->id], $this->searchIds('Piotrkowska'));
    }

    /**
     * A term made only of characters JSON does not escape encodes to ITSELF, so those searches
     * behave exactly as they did before the fix. That is a property worth pinning rather than a
     * coincidence worth trusting: it is what makes this change safe for the searches that already
     * worked.
     */
    public function test_an_ascii_term_behaves_exactly_as_before(): void
    {
        $raport = $this->submit(['tytul' => 'raport roczny']);
        $this->submit(['tytul' => 'zestawienie']);

        $this->assertSame([$raport->id], $this->searchIds('raport'));
        $this->assertSame([], $this->searchIds('faktura'));
    }

    /**
     * WHY THE FIX USES `json_encode()` RATHER THAN A UNICODE ESCAPER, stated as a test because the
     * reasoning is otherwise invisible. JSON escapes more than non-ASCII: a quote is stored as `\"`
     * and a forward slash as `\/`. So an encoder that only handled Polish letters would fix the
     * headline bug and leave a quoted phrase and a URL — both ordinary things to type into a search
     * box — still unfindable, in exactly the same silent way. Both of these were zero-hit before.
     */
    public function test_quotes_and_slashes_are_spelled_the_way_the_document_spells_them(): void
    {
        $quoted = $this->submit(['cytat' => 'powiedział "tak" na spotkaniu']);
        $link = $this->submit(['zrodlo' => 'https://taskio.pl/raporty']);

        $this->assertSame([$quoted->id], $this->searchIds('"tak"'));
        $this->assertSame([$link->id], $this->searchIds('https://taskio.pl'));
    }

    /**
     * THIS IS THE ORDERING TEST — the most targeted one, not the only sensitive one. The encoded
     * term carries backslashes, and the column holds those backslashes as literal bytes, so
     * `Searchable`'s wildcard escaping is correct PROVIDED the JSON encoding ran first. Reverse the
     * two and the escaping's own backslash in front of the `_` gets JSON-escaped to `\\`, the scope
     * escapes it again, and the pattern demands a backslash the data does not contain: this
     * assertion goes red because the row stops being found at all.
     *
     * A term carrying BOTH a Polish character and a LIKE wildcard is the sharpest probe, but the
     * failure is broader than that — under reversal the lone backslash of an escape is read by LIKE
     * as escaping the `u`, so the pattern demands the literal text `u0141…`. That takes down
     * `test_an_answer_with_polish_characters_is_found` here and `raport_2026` over in
     * `SearchableWildcardTest` as well. Three independent tests catch the reversal; this one says
     * why.
     */
    public function test_encoding_happens_before_wildcard_escaping(): void
    {
        $tagged = $this->submit(['tag' => 'Łódź_2026']);
        $this->submit(['tag' => 'Łódź-2026']);

        $this->assertSame([$tagged->id], $this->searchIds('Łódź_2026'));
    }

    /**
     * The term stays a LITERAL after encoding — the escaping in `Searchable` is still doing its job
     * through the new spelling. `%` alone must not match every submission the way it once matched
     * every row in every list.
     */
    public function test_the_term_is_still_a_literal_not_a_pattern(): void
    {
        $percent = $this->submit(['rabat' => 'zniżka 100 % ceny']);
        $this->submit(['rabat' => 'brak zniżki']);

        $this->assertSame([$percent->id], $this->searchIds('%'));
    }

    /**
     * A blank term is still "no filter at all" rather than a filter on the empty string — the one
     * way this change could have turned a working list into an empty one for everybody, not just
     * for Polish searches.
     *
     * WHICH BRANCH THIS ACTUALLY REACHES: Laravel's global `TrimStrings` and
     * `ConvertEmptyStringsToNull` are both in the stack, so a whitespace-only query parameter has
     * already become `null` by the time the service sees it — this exercises the non-string branch,
     * not the trim. The trim in `asStoredJsonText()` is defensive for the same reason the scope's
     * own trim is: neither should depend on middleware it does not own.
     */
    public function test_a_blank_term_does_not_filter(): void
    {
        $this->submit(['tytul' => 'pierwszy']);
        $this->submit(['tytul' => 'drugi']);

        $this->assertCount(2, $this->searchIds('   '));
        $this->assertCount(2, $this->searchIds("\t"));
    }

    /**
     * THE CONTAINMENT INVARIANT — the one refactor that would undo this fix everywhere else, and
     * the only test in the suite that would notice.
     *
     * Both docblocks say "do not move the encoding into `Searchable`". Nothing enforced it: every
     * other search term in the test suite is ASCII, so a "tidy-up" that deleted
     * `asStoredJsonText()` and encoded inside `scopeSearch()` would leave every test in this class
     * GREEN — submissions would still get encode-then-escape — while Polish search on tasks, forms,
     * files, bots, knowledge and workspaces silently returned nothing. That is the same failure this
     * whole class exists to fix, moved to eighteen other places.
     *
     * `User.name` is a `varchar` holding raw UTF-8. It is the counterpart to the storage-format
     * canary above: that one pins what the JSON column contains, this one pins what a text column
     * contains, and the fix is only correct because the two differ.
     */
    public function test_a_plain_text_column_is_still_searched_as_raw_utf8(): void
    {
        $lodz = User::factory()->create(['name' => 'Łódź']);
        User::factory()->create(['name' => 'Gdansk']);

        $this->assertSame(
            [$lodz->id],
            User::query()->search(['name'], 'Łódź')->pluck('id')->all()
        );
    }
}
