<?php

namespace Tests\Unit\Knowledge;

use App\Modules\Knowledge\Support\MentionScanner;
use PHPUnit\Framework\TestCase;

/**
 * The B10 mention heuristic, in isolation — the rule that lets a base connect itself out of prose
 * instead of out of `[[wikilinks]]` nobody types.
 *
 * A pure unit test (no framework boot): the scanner touches no container, no config and no database,
 * which is the property that makes the heuristic cheap enough to run on every index pass.
 */
class MentionScannerTest extends TestCase
{
    private MentionScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = new MentionScanner;
    }

    /** THE case from dev: an inflected, multi-word Polish title found in ordinary prose. */
    public function test_it_finds_an_inflected_multi_word_title(): void
    {
        $found = $this->scanner->scan(
            'W 1889 roku ukonczono budowe wieży Eiffla, ktora do dzis zdumiewa.',
            ['t' => 'Wieża Eiffla'],
        );

        $this->assertArrayHasKey('t', $found);
        $this->assertSame(29, $found['t']['char_start']);
        $this->assertSame(12, $found['t']['char_length']);
    }

    /** The offsets are CHARACTER offsets into the content — the module's convention everywhere. */
    public function test_the_evidence_offsets_address_the_mention_in_the_content(): void
    {
        $content = 'Zażółć gęślą jaźń, a potem opisz wieżę Eiffla dokładnie.';

        $found = $this->scanner->scan($content, ['t' => 'Wieża Eiffla']);

        $this->assertSame(
            'wieżę Eiffla',
            mb_substr($content, $found['t']['char_start'], $found['t']['char_length']),
            'mb_substr(content, char_start, char_length) must be the mention itself',
        );
    }

    public function test_diacritics_do_not_have_to_match(): void
    {
        $found = $this->scanner->scan('Zwiedzilismy wieze Eiffla latem.', ['t' => 'Wieża Eiffla']);

        $this->assertArrayHasKey('t', $found);
    }

    public function test_the_match_is_case_insensitive(): void
    {
        $this->assertArrayHasKey('t', $this->scanner->scan('WIEŻA EIFFLA stoi w Paryzu.', ['t' => 'Wieża Eiffla']));
    }

    /**
     * The words must be ADJACENT and in order. Without this, a note mentioning a tower in one sentence
     * and Eiffel in another would be read as naming the entry — which is how a graph fills up with
     * relations nobody would recognise.
     */
    public function test_title_words_scattered_across_the_text_are_not_a_mention(): void
    {
        $this->assertSame([], $this->scanner->scan(
            'Wieża stoi w Paryzu. Gustave Eiffla znal kazdy inzynier.',
            ['t' => 'Wieża Eiffla'],
        ));
    }

    public function test_words_in_the_wrong_order_are_not_a_mention(): void
    {
        $this->assertSame([], $this->scanner->scan('Eiffla wieża', ['t' => 'Wieża Eiffla']));
    }

    /**
     * A title word under four characters is matched EXACTLY — below that a prefix is a coincidence.
     *
     * Demonstrated with a short word ALONGSIDE a long one, because a title that is nothing but one
     * short word is refused outright these days (see the date-stripping guard below).
     */
    public function test_short_title_words_require_an_exact_match(): void
    {
        // "rokowania" is not "rok", however similar it starts.
        $this->assertSame([], $this->scanner->scan('Rokowania szkolne trwaly dlugo.', ['t' => 'Rok szkolny']));

        $this->assertArrayHasKey('t', $this->scanner->scan('Miniony rok szkolny byl trudny.', ['t' => 'Rok szkolny']));
    }

    /** Only the FIRST mention is reported: an edge is one relation however often it is named. */
    public function test_only_the_first_mention_is_reported(): void
    {
        $found = $this->scanner->scan('Paryz, potem znow Paryz i jeszcze raz Paryz.', ['t' => 'Paryz']);

        $this->assertSame(0, $found['t']['char_start']);
    }

    public function test_it_reports_every_matching_title_keyed_by_the_callers_key(): void
    {
        $found = $this->scanner->scan(
            'Wieża Eiffla stoi w Paryzu, a Luwr jest niedaleko.',
            ['a' => 'Wieża Eiffla', 'b' => 'Paryz', 'c' => 'Bazylika Sacre-Coeur'],
        );

        $this->assertSame(['a', 'b'], array_keys($found));
    }

    public function test_unrelated_prose_produces_nothing(): void
    {
        $this->assertSame([], $this->scanner->scan(
            'Zwroty przyjmujemy w czternascie dni od zakupu.',
            ['t' => 'Wieża Eiffla'],
        ));
    }

    public function test_empty_inputs_are_safe(): void
    {
        $this->assertSame([], $this->scanner->scan('', ['t' => 'Wieża Eiffla']));
        $this->assertSame([], $this->scanner->scan(null, ['t' => 'Wieża Eiffla']));
        $this->assertSame([], $this->scanner->scan('Cokolwiek', []));
        $this->assertSame([], $this->scanner->scan('Cokolwiek', ['t' => '']));
    }

    /**
     * THE DOCUMENTED LIMITATION, pinned so it is a known trade rather than a surprise: truncation
     * stemming over-matches, and a title that is an ordinary noun WILL be found inside longer words
     * built from it. The mitigation is that a mention is a dismissible suggestion, not an assertion.
     */
    public function test_stemming_over_matches_and_that_is_known(): void
    {
        $this->assertArrayHasKey('t', $this->scanner->scan('Wzrosl parytet walutowy.', ['t' => 'Paryz']));
    }

    public function test_the_longest_stem_is_the_pre_filter_handle(): void
    {
        $this->assertSame('eiff', $this->scanner->longestStem('Wieża Eiffla'));
        // Every word too short to stem — no usable handle, so the reverse scan stands down.
        $this->assertNull($this->scanner->longestStem('A i o'));
    }

    // ---- aliases ---------------------------------------------------------------------------
    //
    // The cheap substitute for a Polish lemmatiser (there is no usable one for PHP). Instead of
    // inferring the inflected forms, the entry carries them — written by a human or by the composer,
    // which knows Polish inflection far better than a truncation rule ever will.

    /** A target may be named by ANY of its names, and each gets the title's exact mechanics. */
    public function test_an_alias_matches_where_the_title_does_not(): void
    {
        $content = 'Zwiedzilismy najslynniejsza atrakcje Paryza, czyli Eiffel Tower, w samo poludnie.';

        // The title alone does not appear in that sentence.
        $this->assertSame([], $this->scanner->scan($content, ['t' => 'Wieża Eiffla']));

        // ...but the alias does.
        $found = $this->scanner->scan($content, ['t' => ['Wieża Eiffla', 'Eiffel Tower']]);

        $this->assertArrayHasKey('t', $found);
        $this->assertSame('Eiffel Tower', mb_substr($content, $found['t']['char_start'], $found['t']['char_length']));
    }

    /** One relation, not several: the earliest occurrence wins whichever name found it. */
    public function test_a_target_named_by_several_matching_names_yields_one_earliest_mention(): void
    {
        $content = 'Eiffel Tower stoi w Paryzu, a wieża Eiffla jest jej polska nazwa.';

        $found = $this->scanner->scan($content, ['t' => ['Wieża Eiffla', 'Eiffel Tower']]);

        $this->assertCount(1, $found);
        $this->assertSame(0, $found['t']['char_start'], 'the earliest of the matching names is the mention');
    }

    /** An alias is held to the SAME rules a title is — it cannot smuggle in a looser pattern. */
    public function test_an_alias_gets_the_same_date_stripping_and_lone_word_guard(): void
    {
        // Reduces to nothing after date-stripping, so it is not scanned for at all.
        $this->assertSame([], $this->scanner->scan(
            'Lipiec 2026 byl goracy, a rok obfity.',
            ['t' => ['Nieistniejacy tytul', 'Lipiec 2026']],
        ));

        // A dated ALIAS still matches undated prose, exactly as a dated title does.
        $this->assertArrayHasKey('t', $this->scanner->scan(
            'Zoja66 zwiedzala wieżę Eiffla latem.',
            ['t' => ['Cos innego', 'Wieża Eiffla -13 lipiec 2026']],
        ));
    }

    /** Stems are per NAME, so the reverse pass can pre-filter on any of them. */
    public function test_the_pre_filter_handles_cover_every_name(): void
    {
        // `eiff`, not `eiffel`: both words of "Eiffel Tower" stem to four characters, and the tie-break
        // is the longer WORD — the same rule a single title gets.
        $this->assertSame(
            ['wiez', 'eiff'],
            $this->scanner->longestStems(['Wieża', 'Eiffel Tower']),
        );

        // Deduped, and names with nothing to stem simply contribute nothing.
        $this->assertSame(['wiez'], $this->scanner->longestStems(['Wieża', 'wieża', 'A i o']));
    }

    /** A plain string target still works — every pre-alias caller passes one. */
    public function test_a_bare_string_target_is_still_accepted(): void
    {
        $this->assertArrayHasKey('t', $this->scanner->scan('Zwiedzam wieżę Eiffla.', ['t' => 'Wieża Eiffla']));
    }

    // ---- B10.1: dates in titles ---------------------------------------------------------
    //
    // Found on the owner's own dev data. Real titles carry dates ("Zwiedzanie wieży Eiffla -13 lipiec
    // 2026") and prose never repeats them, so under an all-words rule one stray "2026" made the title
    // permanently unmatchable — the layer produced nothing on the first real data it met.

    public function test_a_dated_title_matches_prose_that_carries_no_date(): void
    {
        $found = $this->scanner->scan(
            'Zoja66 16 lipca podczas podróży do Francji zwiedzała wieżę Eiffla.',
            ['t' => 'Zwiedzanie wieży Eiffla -13 lipiec 2026'],
        );

        $this->assertArrayHasKey('t', $found);
        $this->assertSame(
            'zwiedzała wieżę Eiffla',
            mb_substr('Zoja66 16 lipca podczas podróży do Francji zwiedzała wieżę Eiffla.', $found['t']['char_start'], $found['t']['char_length']),
        );
    }

    /** Genitive months are the form a date actually takes, so both cases have to be stripped. */
    public function test_month_names_are_stripped_in_both_case_forms(): void
    {
        foreach (['Raport marzec 2026', 'Raport marca 2026', 'Raport wrzesień 2026', 'Raport września 2026'] as $title) {
            $this->assertArrayHasKey(
                't',
                $this->scanner->scan('Przygotowalismy raport dla zarzadu.', ['t' => $title]),
                "the month in [{$title}] must not be part of the pattern",
            );
        }
    }

    /**
     * ...and what is left must still be a NAME. A title that is only a date has nothing to search for,
     * and a lone short word is a common noun that would match every note using it — the opposite
     * failure from the one being fixed, and much harder to notice.
     *
     * @dataProvider titlesWithNothingToMatch
     */
    public function test_a_title_that_reduces_to_a_date_or_one_short_word_matches_nothing(string $title): void
    {
        $this->assertSame([], $this->scanner->scan(
            'Lipiec 2026 byl goracy, a rok obfity. Podroz do Paryza udala sie znakomicie.',
            ['t' => $title],
        ));

        $this->assertNull($this->scanner->longestStem($title));
    }

    public static function titlesWithNothingToMatch(): array
    {
        return [
            'a bare year' => ['2026'],
            'a month and a year' => ['Lipiec 2026'],
            'a genitive month' => ['lipca'],
            'a day and a month' => ['13 lipca'],
            'one short word left' => ['Rok 2026'],
        ];
    }

    /** One long word IS a name worth looking for — the guard is about short words, not single ones. */
    public function test_a_single_long_word_survives_date_stripping(): void
    {
        $this->assertSame(['t' => ['char_start' => 6, 'char_length' => 6]], $this->scanner->scan(
            'Wieza Eiffla stoi w Paryzu.',
            ['t' => 'Eiffla 2026'],
        ));
    }

    /**
     * The pre-filter handle is taken from the STRIPPED pattern, never from the raw title — otherwise
     * the reverse pass would hunt the whole base for "lipiec" and discard almost every row it read.
     *
     * `zwiedza` rather than `eiff` because the longest stem wins, and a 7-character stem is the more
     * selective `like` of the two.
     */
    public function test_the_pre_filter_handle_ignores_date_tokens(): void
    {
        $this->assertSame('zwiedza', $this->scanner->longestStem('Zwiedzanie wieży Eiffla -13 lipiec 2026'));
        $this->assertSame('eiff', $this->scanner->longestStem('Eiffla lipiec 2026'));
    }
}
