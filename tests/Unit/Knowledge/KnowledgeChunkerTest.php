<?php

namespace Tests\Unit\Knowledge;

use App\Modules\Knowledge\DTOs\ChunkDraft;
use App\Modules\Knowledge\Exceptions\KnowledgeChunkOverflowException;
use App\Modules\Knowledge\Services\KnowledgeChunker;
use Tests\TestCase;

/**
 * The splitter, as a pure function. No database, no AI, no queue — which is the whole point of keeping
 * {@see KnowledgeChunker} free of them.
 *
 * The properties pinned here are the ones the COST model rests on. Differential indexing only saves
 * money if identical text produces identical digests, and only produces good retrieval if a passage
 * carries its heading trail and does not begin mid-thought. Each of those is a separate test below,
 * because each fails in its own way and a single "it chunks" test would catch none of them.
 */
class KnowledgeChunkerTest extends TestCase
{
    private KnowledgeChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chunker = new KnowledgeChunker;
    }

    /** Text long enough to force a split, built from whole sentences. */
    private function prose(int $sentences, string $stem = 'Zdanie wypelniajace tresc akapitu'): string
    {
        $out = [];

        for ($i = 1; $i <= $sentences; $i++) {
            $out[] = $stem . ' numer ' . $i . '.';
        }

        return implode(' ', $out);
    }

    /** @param array<int, ChunkDraft> $drafts */
    private function paths(array $drafts): array
    {
        return array_map(static fn (ChunkDraft $draft): string => $draft->headingPath, $drafts);
    }

    // ---- determinism ---------------------------------------------------------------

    /**
     * The COST guarantee: two runs over identical text must agree on every digest, offset and path.
     * If they can disagree, every re-index re-buys every vector in the base.
     */
    public function test_chunking_the_same_text_twice_is_byte_identical(): void
    {
        $content = "Wstep.\n\n# Cennik\n\n" . $this->prose(40) . "\n\n## Rabaty\n\n" . $this->prose(40);

        $first = $this->chunker->chunk('Polityka', $content);
        $second = $this->chunker->chunk('Polityka', $content);

        $this->assertGreaterThan(1, count($first), 'this fixture must actually split');
        $this->assertEquals($first, $second);
    }

    /**
     * Every draft is a CONTIGUOUS slice of the source, so a citation can highlight the exact span.
     * Checked across a fixture that exercises all four rungs of the cascade at once.
     */
    public function test_every_chunk_is_an_exact_slice_of_the_source_at_its_offsets(): void
    {
        $content = "Wstep.\n\n# Cennik\n\n" . $this->prose(50) . "\n\n## Rabaty\n\n"
            . str_repeat('X', 4500) . "\n\n### Wolumen\n\n" . $this->prose(30);

        foreach ($this->chunker->chunk('Polityka', $content) as $draft) {
            $this->assertSame(
                $draft->content,
                mb_substr($content, $draft->charStart, $draft->charLength),
                "chunk #{$draft->ordinal} does not match its own offsets",
            );
        }
    }

    public function test_ordinals_are_zero_based_and_gapless(): void
    {
        $content = "# A\n\n" . $this->prose(40) . "\n\n# B\n\n" . $this->prose(40);

        $drafts = $this->chunker->chunk('Tytul', $content);

        $this->assertSame(range(0, count($drafts) - 1), array_column($drafts, 'ordinal'));
    }

    public function test_an_empty_body_produces_no_chunks(): void
    {
        $this->assertSame([], $this->chunker->chunk('Tytul', ''));
        $this->assertSame([], $this->chunker->chunk('Tytul', "\n\n   \n"));
    }

    // ---- heading structure ---------------------------------------------------------

    /**
     * A passage carries its ancestry, rooted at the entry TITLE. The root matters as much as the
     * branch: a chunk labelled only "Rabaty" cannot be attributed to a document.
     */
    public function test_each_chunk_carries_its_heading_trail_rooted_at_the_title(): void
    {
        // Every section is comfortably above `min_chars`, so nothing merges and each keeps its own
        // path. (A short preamble WOULD be fused into the following section and drop to the common
        // ancestor — that behaviour has its own test.)
        $content = $this->prose(15, 'Wstep do dokumentu') . "\n\n"
            . "# Cennik\n\n" . $this->prose(40) . "\n\n"
            . "## Rabaty\n\n" . $this->prose(40) . "\n\n"
            . "### Wolumen\n\n" . $this->prose(40);

        $paths = $this->paths($this->chunker->chunk('Polityka handlowa', $content));

        $this->assertContains('Polityka handlowa > Cennik', $paths);
        $this->assertContains('Polityka handlowa > Cennik > Rabaty', $paths);
        $this->assertContains('Polityka handlowa > Cennik > Rabaty > Wolumen', $paths);

        foreach ($paths as $path) {
            $this->assertStringStartsWith('Polityka handlowa', $path);
        }
    }

    /** A shallower heading closes the deeper ones: "## B" after "### A" is not a child of A. */
    public function test_a_shallower_heading_pops_the_deeper_trail(): void
    {
        $content = "# Jeden\n\n## Dwa\n\n### Trzy\n\n" . $this->prose(40) . "\n\n"
            . "## Cztery\n\n" . $this->prose(40);

        $paths = $this->paths($this->chunker->chunk('T', $content));

        $this->assertContains('T > Jeden > Dwa > Trzy', $paths);
        $this->assertContains('T > Jeden > Cztery', $paths);
        $this->assertNotContains('T > Jeden > Dwa > Cztery', $paths);
    }

    /** `#Foo` without a space is not a heading in CommonMark, and must not be treated as one. */
    public function test_a_hash_without_a_space_is_not_a_heading(): void
    {
        $drafts = $this->chunker->chunk('T', '#NieNaglowek i troche tresci.');

        $this->assertCount(1, $drafts);
        $this->assertSame('T', $drafts[0]->headingPath);
        $this->assertStringContainsString('#NieNaglowek', $drafts[0]->content);
    }

    // ---- merging -------------------------------------------------------------------

    /**
     * Sections below `min_chars` are fused rather than embedded as noise, and the fused passage drops
     * to the two paths' common ancestor — the only heading still true of all of its text.
     */
    public function test_adjacent_undersized_sections_are_merged_under_their_common_ancestor(): void
    {
        $content = "# Cennik\n\n## Rabaty\n\nKrotko.\n\n## Zwroty\n\nTez krotko.";

        $drafts = $this->chunker->chunk('Polityka', $content);

        $this->assertCount(1, $drafts, 'three tiny sections must collapse into one passage');
        $this->assertSame('Polityka > Cennik', $drafts[0]->headingPath);
        // The fused span covers the intervening heading lines, so the sub-headings stay visible.
        $this->assertStringContainsString('Krotko.', $drafts[0]->content);
        $this->assertStringContainsString('Tez krotko.', $drafts[0]->content);
    }

    /** A stub must not swallow a full-size neighbour: merging is refused past the ceiling. */
    public function test_merging_never_pushes_a_passage_past_the_ceiling(): void
    {
        $content = "# Krotka\n\nStub.\n\n# Dluga\n\n" . $this->prose(60);

        foreach ($this->chunker->chunk('T', $content) as $draft) {
            $this->assertLessThanOrEqual(
                (int) config('knowledge.chunking.max_chars'),
                $draft->charLength,
            );
        }
    }

    // ---- sentence handling ---------------------------------------------------------

    /**
     * Polish abbreviations do not end sentences. Without this the splitter shatters a list of
     * examples into fragments — worse retrieval AND more chunks to pay for.
     */
    public function test_polish_abbreviations_do_not_split_a_sentence(): void
    {
        $sentence = 'Dotyczy to np. faktur oraz m.in. korekt, tzn. wszystkich dokumentow, itd. itp. w tym zakresie.';
        $content = $sentence . ' ' . $this->prose(80);

        $drafts = $this->chunker->chunk('T', $content);

        $this->assertGreaterThan(1, count($drafts), 'the fixture must be long enough to split');
        // Whichever passage opens the document must keep the abbreviation-laden sentence whole.
        $this->assertStringContainsString($sentence, $drafts[0]->content);
    }

    // ---- hard cut + overlap --------------------------------------------------------

    /**
     * A single unbroken run of text has no paragraph or sentence boundary to use, so it is cut to
     * width — and ONLY there is overlap applied, because only a hard cut severs a thought.
     */
    public function test_a_hard_cut_repeats_the_previous_window_as_overlap(): void
    {
        $overlap = (int) config('knowledge.chunking.overlap_chars');
        $content = str_repeat('abcdefghij', 600); // 6000 chars, no boundary anywhere

        $drafts = $this->chunker->chunk('T', $content);

        $this->assertGreaterThan(2, count($drafts));

        for ($i = 1; $i < count($drafts); $i++) {
            $previous = $drafts[$i - 1];
            $tail = mb_substr($previous->content, -$overlap);

            $this->assertStringStartsWith(
                $tail,
                $drafts[$i]->content,
                "chunk #{$i} must repeat the previous window's last {$overlap} characters",
            );
            // The ranges genuinely overlap — that is what an overlap window means.
            $this->assertLessThan($previous->charStart + $previous->charLength, $drafts[$i]->charStart);
        }
    }

    /**
     * Overlap must NEVER cross a heading. Repeating one section's tail into the next would file that
     * text under a path it does not belong to — and the path is part of what gets embedded.
     */
    public function test_overlap_is_never_applied_across_a_heading_boundary(): void
    {
        $first = $this->prose(40, 'Pierwsza sekcja mowi o czyms zupelnie innym');
        $second = $this->prose(40, 'Druga sekcja mowi o czyms calkiem odmiennym');
        $content = "# Jeden\n\n" . $first . "\n\n# Dwa\n\n" . $second;

        $drafts = $this->chunker->chunk('T', $content);

        $bySection = [];

        foreach ($drafts as $draft) {
            $bySection[$draft->headingPath][] = $draft;
        }

        $this->assertArrayHasKey('T > Jeden', $bySection);
        $this->assertArrayHasKey('T > Dwa', $bySection);

        // No passage of section Two may contain text belonging to section One, and vice versa.
        foreach ($bySection['T > Dwa'] as $draft) {
            $this->assertStringNotContainsString('Pierwsza sekcja', $draft->content);
        }

        foreach ($bySection['T > Jeden'] as $draft) {
            $this->assertStringNotContainsString('Druga sekcja', $draft->content);
        }
    }

    /** No passage may exceed the hard ceiling, whichever rung of the cascade produced it. */
    public function test_no_chunk_exceeds_the_configured_ceiling(): void
    {
        $max = (int) config('knowledge.chunking.max_chars');

        $content = "# A\n\n" . str_repeat('x', 9000) . "\n\n# B\n\n" . $this->prose(120);

        foreach ($this->chunker->chunk('T', $content) as $draft) {
            $this->assertLessThanOrEqual($max, $draft->charLength);
        }
    }

    // ---- the fan-out cap -----------------------------------------------------------

    /**
     * The per-entry SPEND bound. Reachable inside the 40 000-character limit — many short headed
     * sections do not merge and do not fill a passage — which is exactly why the FormRequest
     * dry-runs this.
     */
    public function test_exceeding_the_chunk_cap_throws(): void
    {
        config()->set('knowledge.chunking.max_chunks_per_entry', 5);

        $content = '';

        for ($i = 1; $i <= 12; $i++) {
            $content .= "# Sekcja {$i}\n\n" . $this->prose(30) . "\n\n";
        }

        $this->expectException(KnowledgeChunkOverflowException::class);

        $this->chunker->chunk('T', $content);
    }

    /** The dry run REPORTS the overflow instead of raising it — the FormRequest needs the number. */
    public function test_the_dry_run_reports_the_count_instead_of_throwing(): void
    {
        config()->set('knowledge.chunking.max_chunks_per_entry', 5);

        $content = '';

        for ($i = 1; $i <= 12; $i++) {
            $content .= "# Sekcja {$i}\n\n" . $this->prose(30) . "\n\n";
        }

        $this->assertGreaterThan(5, $this->chunker->countFor('T', $content));
    }

    /**
     * A 40 000-character entry — the storage cap — must stay inside the fan-out cap when written as
     * ordinary prose. This is the pairing the two limits are supposed to have.
     */
    public function test_a_maximum_length_entry_stays_within_the_fan_out_cap(): void
    {
        $content = '';

        while (mb_strlen($content) < 40000) {
            $content .= $this->prose(20) . "\n\n";
        }

        $content = mb_substr($content, 0, 40000);

        $this->assertLessThanOrEqual(
            (int) config('knowledge.chunking.max_chunks_per_entry'),
            count($this->chunker->chunk('T', $content)),
        );
    }

    // ---- digest semantics ----------------------------------------------------------

    /** The digest covers the heading path, so the same sentence under a different heading differs. */
    public function test_the_digest_covers_the_heading_path_not_only_the_text(): void
    {
        $body = $this->prose(20);

        $underA = $this->chunker->chunk('T', "# Alfa\n\n" . $body);
        $underB = $this->chunker->chunk('T', "# Beta\n\n" . $body);

        $this->assertSame($underA[0]->content, $underB[0]->content);
        $this->assertNotSame($underA[0]->digest, $underB[0]->digest);
    }

    /** Editing one section must leave the OTHER sections' digests untouched — the basis of the diff. */
    public function test_editing_one_section_leaves_the_other_digests_unchanged(): void
    {
        $before = "# Alfa\n\n" . $this->prose(30) . "\n\n# Beta\n\n" . $this->prose(30, 'Beta mowi cos innego');
        $after = "# Alfa\n\n" . $this->prose(30) . "\n\n# Beta\n\n" . $this->prose(30, 'Beta mowi cos zupelnie nowego');

        $digestsBefore = array_column($this->chunker->chunk('T', $before), 'digest');
        $digestsAfter = array_column($this->chunker->chunk('T', $after), 'digest');

        $survivors = array_intersect($digestsBefore, $digestsAfter);

        $this->assertNotEmpty($survivors, 'the untouched section must keep its digests');
    }
}
