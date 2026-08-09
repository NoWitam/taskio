<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHAT A SEARCH TERM MEANS in {@see \App\Models\Concerns\Searchable}: itself, and nothing else.
 *
 * The scope wraps the caller's term in `%…%` and hands it to `ILIKE`, so until this was closed every
 * search box in the application — 19 call sites across 12 modules — also accepted the two LIKE
 * wildcards. Typing `%` matched every row that existed; `raport_2026` also matched `raport-2026`.
 * Nothing advertised it, nothing documented it and nothing depended on it, which is exactly why it
 * survived: an over-broad result set looks like a bad search, not like a bug.
 *
 * These tests pin the PROPERTY (a term is a literal) rather than the implementation (which characters
 * get a backslash in front of them), so the escaping can be rewritten without rewriting them.
 *
 * `User` is the subject because it is the one Searchable model with no workspace scoping to arrange:
 * {@see \App\Models\Scopes\WorkspaceMemberScope} is inert with no active workspace, so the queries
 * below see every row the test made and nothing else.
 *
 * ONE OF THESE DOUBLES AS A DRIVER CANARY. The escaping leans on the driver's DEFAULT LIKE escape
 * character (backslash on Postgres and MySQL; SQLite has none). If the suite is ever pointed at a
 * driver without one, `test_an_underscore_matches_a_literal_underscore_and_not_a_hyphen` goes red on
 * its POSITIVE assertion — the literal row stops being found at all — rather than silently reverting
 * to wildcard matching.
 */
class SearchableWildcardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> the names the search matched */
    private function searchNames(string $term, bool $allowWildcards = false): array
    {
        return User::query()
            ->search(['name'], $term, $allowWildcards)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private function users(string ...$names): void
    {
        foreach ($names as $name) {
            User::factory()->create(['name' => $name]);
        }
    }

    /**
     * The headline regression: `%` used to match the entire table, in every list in the product.
     */
    public function test_a_per_cent_sign_matches_only_a_literal_per_cent(): void
    {
        $this->users('Raport roczny', 'Zestawienie', 'Sto % pewnosci');

        $this->assertSame(['Sto % pewnosci'], $this->searchNames('%'));
    }

    /**
     * The accepted BEHAVIOUR CHANGE, stated as a test so nobody has to rediscover it: an underscore is
     * a character now, so `raport_2026` stops matching `raport-2026`. That single-character wildcard
     * was never a feature — it was the reason a search for one file name quietly returned its
     * near-namesakes.
     *
     * THIS IS ALSO THE ORDERING TEST. Escape the wildcards before the backslash and `\_` becomes
     * `\\_` — a literal backslash then a wildcard — so the pattern demands a backslash the data does
     * not have and the FIRST assertion goes red: the literal row stops being found. The negative
     * assertion would still pass, which is why the positive one is the one that guards the ordering.
     */
    public function test_an_underscore_matches_a_literal_underscore_and_not_a_hyphen(): void
    {
        $this->users('raport_2026', 'raport-2026', 'raport 2026');

        $this->assertSame(['raport_2026'], $this->searchNames('raport_2026'));
    }

    /**
     * The escape character itself, which a Windows path or a regex pasted into a search box supplies
     * routinely. Two properties: a backslash means a backslash, and a term ENDING in one does not
     * reach the driver as a dangling escape. (Postgres tolerates a trailing `\%` here rather than
     * raising "LIKE pattern must not end with escape character" — this pins that it stays tolerable,
     * on a term shaped to provoke it, instead of trusting that it always will be.)
     */
    public function test_a_term_containing_a_backslash_matches_it_literally_and_does_not_break_the_query(): void
    {
        $this->users('C:\\raporty', 'C:/raporty');

        $this->assertSame(['C:\\raporty'], $this->searchNames('C:\\raporty'));
        $this->assertSame([], $this->searchNames('koniec\\'));
    }

    /**
     * THE ESCAPE HATCH, which exists for a future advanced search and has no caller today. Without
     * this test nothing would notice the day it stopped working — it would simply be a parameter that
     * had quietly become a no-op, discovered by whoever finally tried to use it.
     */
    public function test_the_wildcard_hatch_restores_pattern_matching_when_asked(): void
    {
        $this->users('raport_2026', 'raport-2026', 'raport 2026');

        $this->assertSame(['raport 2026', 'raport-2026', 'raport_2026'], $this->searchNames('raport_2026', allowWildcards: true));
        $this->assertCount(3, $this->searchNames('%', allowWildcards: true));
    }
}
