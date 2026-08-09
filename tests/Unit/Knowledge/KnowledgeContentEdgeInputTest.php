<?php

namespace Tests\Unit\Knowledge;

use App\Modules\Knowledge\Support\TemplateDirectiveGuard;
use App\Modules\Knowledge\Support\WikilinkParser;
use Tests\TestCase;

/**
 * B7 — the two pure functions that read UNTRUSTED CONTENT, driven with the inputs a real base
 * eventually produces and a hand-written fixture never does: bytes that are not valid UTF-8, a link
 * list far past the bound, and brackets that do not nest the way a writer meant them to.
 *
 * These are separated from the behavioural wikilink/entry tests on purpose. Those describe what the
 * feature DOES; this file describes what neither of them may do when the input is hostile or merely
 * broken, and the two failure modes it exists to prevent are opposite:
 *
 *   {@see WikilinkParser} must FAIL OPEN — an entry whose body confuses the regex is still a valid
 *     document and must save. A parser that threw would make one bad paste unsaveable, with the error
 *     surfacing from a place ("links") that says nothing about the cause.
 *   {@see TemplateDirectiveGuard} must FAIL CLOSED — it is the only door template syntax is refused
 *     at, so anything that makes it return "clean" for content that carries a marker is a hole that
 *     some later, unrelated feature executes on somebody else's behalf.
 *
 * Malformed UTF-8 is where those two meet, and it is the case worth having: the parser's pattern
 * carries `/u` (so PCRE refuses the subject outright) while the guard's patterns do not (so they keep
 * matching bytes). That asymmetry is load-bearing, and nothing else in the suite states it.
 */
class KnowledgeContentEdgeInputTest extends TestCase
{
    private WikilinkParser $parser;

    private TemplateDirectiveGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new WikilinkParser;
        $this->guard = new TemplateDirectiveGuard;
    }

    /** A lone continuation byte: valid Latin-1, invalid UTF-8, and trivially reachable by a paste. */
    private function invalidUtf8(): string
    {
        return "Tresc z uszkodzonym bajtem \xC3\x28 w srodku.";
    }

    // ---- the parser fails OPEN -------------------------------------------------

    /**
     * `preg_match_all` with `/u` REFUSES a subject that is not valid UTF-8 — it returns false and sets
     * PREG_BAD_UTF8_ERROR rather than matching what it can. The parser checks for that explicitly, so
     * the outcome is "this entry links to nothing", not an exception thrown from inside a save.
     */
    public function test_malformed_utf8_yields_no_targets_instead_of_blowing_up(): void
    {
        $content = $this->invalidUtf8() . ' Zobacz [[cennik]].';

        $this->assertSame([], $this->parser->targets($content));
        $this->assertSame(
            PREG_BAD_UTF8_ERROR,
            preg_last_error(),
            'the premise of this test: PCRE really did refuse the subject',
        );
    }

    /** The valid-UTF-8 control, so the test above cannot pass for the wrong reason. */
    public function test_the_same_content_in_valid_utf8_does_find_its_target(): void
    {
        $this->assertSame(['cennik'], $this->parser->targets('Tresc z polskimi znakami: ółźć. Zobacz [[cennik]].'));
    }

    public function test_null_and_empty_content_are_not_errors(): void
    {
        $this->assertSame([], $this->parser->targets(null));
        $this->assertSame([], $this->parser->targets(''));
        $this->assertSame([], $this->parser->targets('Tekst zupelnie bez odnosnikow.'));
    }

    // ---- the bound on how many targets one entry may draw ----------------------

    /**
     * A pathological entry — one link per line, well past {@see WikilinkParser::MAX_TARGETS} — is
     * clamped rather than obeyed. The bound exists because every target becomes a row in the
     * delete+insert a save performs, so an unbounded list turns one keystroke into an unbounded write.
     *
     * The FIRST targets are the ones kept (the parser returns in first-appearance order and stops),
     * which is the only clamp that is stable: keeping an arbitrary subset would make the entry's edges
     * depend on hash ordering and change under it.
     */
    public function test_a_target_list_far_past_the_cap_is_clamped_to_the_first_of_them(): void
    {
        $lines = [];

        for ($i = 1; $i <= WikilinkParser::MAX_TARGETS + 250; $i++) {
            $lines[] = "Punkt {$i}: [[notatka-{$i}]]";
        }

        $targets = $this->parser->targets(implode("\n", $lines));

        $this->assertCount(WikilinkParser::MAX_TARGETS, $targets);
        $this->assertSame('notatka-1', $targets[0]);
        $this->assertSame('notatka-' . WikilinkParser::MAX_TARGETS, end($targets));
    }

    /** Repetition is not volume: a thousand links to one target are one edge, and the cap is untouched. */
    public function test_the_cap_counts_distinct_targets_not_occurrences(): void
    {
        $content = str_repeat("Zobacz [[cennik]] oraz [[rabaty]].\n", 1000);

        $this->assertSame(['cennik', 'rabaty'], $this->parser->targets($content));
    }

    // ---- brackets that do not nest ---------------------------------------------

    /**
     * The bracket zoo, as one table because each case is one line and the interesting part is the
     * COMPARISON between them.
     *
     * The rule underneath all of it: neither part of a link may contain a bracket or a newline, so an
     * unclosed `[[` cannot swallow the rest of the document. That is what stops a stray bracket in a
     * long entry from turning the paragraphs after it into one enormous "target".
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function bracketCases(): array
    {
        return [
            'unclosed opener swallows nothing' => ['Zobacz [[cennik i dalszy tekst dokumentu.', []],
            'a stray closer is inert' => ['Zobacz cennik]] i dalej.', []],
            'doubled brackets resolve the inner pair' => ['Zobacz [[[[cennik]]]].', ['cennik']],
            'an opener inside a link ends the candidate' => ['Zobacz [[cennik [[rabaty]].', ['rabaty']],
            'a newline inside a link is not a link' => ["Zobacz [[cennik\nrabaty]].", []],
            'an empty target is dropped' => ['Zobacz [[]] i [[   ]].', []],
            'an empty target with a label is dropped' => ['Zobacz [[ | etykieta]].', []],
            'a label may be empty' => ['Zobacz [[cennik|]].', ['cennik']],
            'the label never becomes the target' => ['Zobacz [[cennik|Nasze Rabaty]].', ['cennik']],
            'a target of pure punctuation slugs to nothing' => ['Zobacz [[!!!???]].', []],
            'two links on one line both count' => ['[[cennik]] oraz [[rabaty]].', ['cennik', 'rabaty']],
            'a link split across a closer keeps the first' => ['[[cennik]] i ]] [[rabaty]]', ['cennik', 'rabaty']],
        ];
    }

    /**
     * @param  array<int, string>  $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bracketCases')]
    public function test_broken_and_nested_brackets_resolve_predictably(string $content, array $expected): void
    {
        $this->assertSame($expected, $this->parser->targets($content));
    }

    /**
     * The length bound on a single target, from both sides. Without it a document could mint a slug of
     * arbitrary length out of one line of prose that happened to sit between two brackets.
     */
    public function test_a_target_longer_than_the_pattern_allows_is_not_a_link(): void
    {
        $this->assertSame(['a' . str_repeat('b', 199)], $this->parser->targets('[[a' . str_repeat('b', 199) . ']]'));
        $this->assertSame([], $this->parser->targets('[[a' . str_repeat('b', 200) . ']]'));
    }

    // ---- the guard fails CLOSED ------------------------------------------------

    /**
     * The guard's patterns deliberately carry NO `/u`, so they match BYTES and a subject PCRE would
     * refuse under `/u` is still scanned. Were they unicode-mode, malformed UTF-8 would make every
     * pattern return false, `violation()` would answer null, and appending one bad byte to an entry
     * would be enough to walk `{{ workspace.secret }}` straight into the store.
     *
     * This is the single most important assertion in the file.
     */
    public function test_malformed_utf8_does_not_disarm_the_directive_guard(): void
    {
        $cases = [
            'reference' => $this->invalidUtf8() . ' {{ workspace.secret }}',
            'directive' => $this->invalidUtf8() . ' @[variable]("x")',
            'branch' => $this->invalidUtf8() . "\n[[IF something]]",
            'if_block' => $this->invalidUtf8() . "\n```if-block",
        ];

        foreach ($cases as $expected => $content) {
            $this->assertSame(
                $expected,
                $this->guard->violation($content),
                "a broken byte must not hide the {$expected} marker",
            );
            $this->assertFalse($this->guard->isClean($content));
        }
    }

    /** Malformed bytes on their own are not a violation — the guard refuses syntax, not encodings. */
    public function test_malformed_utf8_alone_is_not_a_violation(): void
    {
        $this->assertNull($this->guard->violation($this->invalidUtf8()));
        $this->assertTrue($this->guard->isClean($this->invalidUtf8()));
    }

    /** A NUL byte is refused wherever it sits: it is what would let content forge the injection mask. */
    public function test_a_nul_byte_is_refused_anywhere_in_the_value(): void
    {
        $this->assertSame('nul', $this->guard->violation("Zwykla tresc\x00 z bajtem."));
        $this->assertSame('nul', $this->guard->violation("\x00"));
        $this->assertSame('nul', $this->guard->violationIn(['opiekun' => "Anna\x00Kowalska"]));
        $this->assertSame('nul', $this->guard->violationIn(["klucz\x00" => 'wartosc']));
    }

    /**
     * `violationIn` walks the WHOLE structure — nested lists, nested maps, and keys as well as values.
     * Metadata is arbitrary JSON, so a marker one level down is exactly as executable as one at the
     * top, and a walker that stopped at depth one would close the door and leave the window open.
     */
    public function test_the_deep_walk_reaches_nested_values_and_keys(): void
    {
        $this->assertSame('reference', $this->guard->violationIn([
            'sekcje' => [
                ['tytul' => 'Zwykly', 'tresc' => 'Bez markerow.'],
                ['tytul' => 'Sprytny', 'tresc' => ['zagniezdzone' => ['glebiej' => '{{ secret }}']]],
            ],
        ]));

        $this->assertSame('directive', $this->guard->violationIn([
            'poziom' => ['glebiej' => ['@[variable]("x")' => 'wartosc']],
        ]));

        // Non-strings are simply not text: they cannot carry syntax and must not be stringified into
        // some accidental interpretation of one.
        $this->assertNull($this->guard->violationIn([
            'liczba' => 42,
            'flaga' => true,
            'pusto' => null,
            'lista' => [1, 2.5, false, null],
            'mapa' => ['zagniezdzone' => ['ok' => 'Zwykla tresc.']],
        ]));
    }

    /**
     * The documented FALSE POSITIVE, pinned so it stays a decision instead of becoming a surprise: a
     * whole-line wikilink whose target starts with `IF`/`ELSE` in capitals is refused, because the
     * guard mirrors the engine's branch regex verbatim rather than trying to be cleverer than it.
     *
     * The cost is a link nobody could have followed anyway — minted slugs are lowercase — and the
     * lowercase forms below prove the exception really is that narrow.
     */
    public function test_the_branch_marker_false_positive_is_exactly_as_narrow_as_documented(): void
    {
        $this->assertSame('branch', $this->guard->violation("Tresc\n[[IFRS-standards]]\ndalej"));
        $this->assertSame('branch', $this->guard->violation('[[ELSE_IF cokolwiek]]'));

        // Lowercase — the form a real slug takes — passes.
        $this->assertNull($this->guard->violation("Tresc\n[[ifrs-standards]]\ndalej"));
        // ...and so does the same capitalised link when it shares its line with prose, because the
        // engine's own marker must occupy the whole line to mean anything.
        $this->assertNull($this->guard->violation('Zobacz [[IFRS-standards]] w zalaczniku.'));
    }
}
