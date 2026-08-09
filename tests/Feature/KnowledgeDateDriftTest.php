<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Support\DraftRunNotes;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * THE SERVER COMPARES THE DATES ITSELF, instead of asking the model whether it behaved.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY A MECHANISM AND NOT ANOTHER INSTRUCTION
 *
 * The composer is told to correct a date the context makes impossible AND to declare the correction.
 * On the owner's material it did the first and skipped the second: sushi dated 13 July in the source
 * became 13 September in the entry — the right answer — with `unresolved` empty and nothing anywhere
 * saying a date had moved. Three rounds of asking more firmly produced diminishing returns.
 *
 * The reason is structural, and it is the point of this file: every reporting channel in the pipeline
 * is filled in by the same model whose work is being reported on. A model that will not admit a
 * correction will not admit failing to report it either. This check reads two texts and compares them,
 * so it cannot be talked out of it.
 *
 * ------------------------------------------------------------------------------------------------
 * (DAY, MONTH), AND THE BLIND SPOT THAT BUYS
 *
 * Polish prose writes "13 lipca" with no year and the entry writes `2026-09-13`, so there is no year
 * to compare on. Two different years sharing a day are therefore indistinguishable — irrelevant for
 * one episode, a real gap for a chronicle spanning years. Stated here because it is the kind of limit
 * that is invisible until it bites.
 */
class KnowledgeDateDriftTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id);
        app(TenantContext::class)->set($workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $workspace->id, 'language' => 'pl']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** One faked run: this source in, these entries out. */
    private function compose(string $source, array $entries): KnowledgeDraftSession
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => []], JSON_UNESCAPED_UNICODE));
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE));

        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => $source,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id)->refresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function notes(KnowledgeDraftSession $session, string $code): array
    {
        return array_values(array_filter(
            $session->notes ?? [],
            static fn (array $note): bool => ($note['code'] ?? null) === $code,
        ));
    }

    private function entry(string $slug, string $content): array
    {
        return [
            'action' => 'create',
            'ref' => 'N1',
            'slug' => $slug,
            'title' => ucfirst($slug),
            'type' => 'place',
            'content' => $content,
            'metadata' => [],
        ];
    }

    // ---- the correction it hid --------------------------------------------------------

    /**
     * THE OWNER'S CASE. The material says 13 July; the entry says 13 September; nobody declared it.
     *
     * The correction is very likely RIGHT — she reaches Tokyo on 12 September — which is exactly why
     * this is a note and not a refusal. What was missing was anyone knowing it happened.
     */
    public function test_a_date_the_material_never_names_is_reported(): void
    {
        $session = $this->compose(
            '13 lipca influencerka spotkala Lukasza podczas wyprawy na sushi w Tokio.',
            [$this->entry('tokio', "Miasto.\n\n## Kalendarium\n\n- 2026-09-13: sushi z Lukaszem.")],
        );

        $reported = $this->notes($session, DraftRunNotes::DATE_NOT_IN_SOURCE);

        $this->assertCount(1, $reported, 'the moved date is reported');
        $this->assertSame('2026-09-13', $reported[0]['date']);
        $this->assertSame('tokio', $reported[0]['slug'], 'and the reviewer is told which entry carries it');
    }

    /** A date the entry copies faithfully raises nothing — the check must not shout at correct work. */
    public function test_a_faithful_date_is_silent(): void
    {
        $session = $this->compose(
            '13 lipca influencerka zwiedzala Wieze Eiffla.',
            [$this->entry('paryz', "Miasto.\n\n## Kalendarium\n\n- 2026-07-13: Wieza Eiffla.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_NOT_IN_SOURCE));
        $this->assertSame([], $this->notes($session, DraftRunNotes::SOURCE_DATE_UNUSED));
    }

    // ---- the fact that went nowhere ---------------------------------------------------

    /**
     * THE OTHER DEFECT, from the other direction: the alcohol incident and the wave of criticism
     * reached no entry at all, while another entry referred to "the incident in Thailand" as though it
     * had been recorded somewhere.
     *
     * A dated event that no entry carries is the machine-checkable shadow of that failure.
     */
    public function test_a_material_date_no_entry_wrote_down_is_reported(): void
    {
        $session = $this->compose(
            '12 wrzesnia przyleciala do Tokio. 15 sierpnia doszlo do incydentu w Tajlandii.',
            [$this->entry('tokio', "Miasto.\n\n## Kalendarium\n\n- 2026-09-12: przylot.")],
        );

        $unused = $this->notes($session, DraftRunNotes::SOURCE_DATE_UNUSED);

        $this->assertCount(1, $unused, 'the incident nobody recorded is visible');
        $this->assertSame('15.08', $unused[0]['date']);
    }

    // ---- and it stays quiet when it has nothing to say --------------------------------

    /** A material with no dates makes this check silent, rather than suspicious of every entry. */
    public function test_a_material_without_dates_reports_nothing(): void
    {
        $session = $this->compose(
            'Influencerka odwiedzila Paryz i zwiedzala Wieze Eiffla.',
            [$this->entry('paryz', 'Miasto, ktore odwiedzila.')],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_NOT_IN_SOURCE));
        $this->assertSame([], $this->notes($session, DraftRunNotes::SOURCE_DATE_UNUSED));
    }

    /**
     * A DATE RANGE NAMES BOTH ITS ENDS — "od 12 do 15 lipca".
     *
     * A measured false alarm: the single-day pattern requires the day to sit next to the month, so the
     * FIRST end of a range was invisible and an entry correctly recording 12 July was reported as
     * carrying a date the source never mentions. This check is worth only as much as it is trusted, so
     * a false positive costs more than a missed one.
     */
    public function test_both_ends_of_a_date_range_count_as_named(): void
    {
        $session = $this->compose(
            'Influencerka odwiedzila Paryz od 12 do 15 lipca.',
            [$this->entry('paryz', "Miasto.\n\n## Kalendarium\n\n- 2026-07-12: przylot.\n- 2026-07-15: wylot.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_NOT_IN_SOURCE));
        $this->assertSame([], $this->notes($session, DraftRunNotes::SOURCE_DATE_UNUSED));
    }

    /** The dashed form too — "13-15 lipca". */
    public function test_a_dashed_range_names_both_ends(): void
    {
        $session = $this->compose(
            'Wyjazd trwal 13-15 lipca.',
            [$this->entry('wyjazd', "Tresc.\n\n## Kalendarium\n\n- 2026-07-13: start.\n- 2026-07-15: koniec.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_NOT_IN_SOURCE));
    }

    // ---- what the answer wrote, judged on its own ------------------------------------

    /**
     * A PLACEHOLDER IS NOT A DATE — `- 2026-08-??: Incydent z alkoholem`.
     *
     * Measured on real material. The composer knew the month and not the day, and produced a line every
     * control in the module was blind to: not an ISO date, so the scanner skipped it, so nothing
     * compared it, so the base gained a fact that no question about time will ever return.
     *
     * Reported, not refused: the line carries a real fact whose only defect is an unknown day, and
     * dropping it would destroy content to punish a formatting choice.
     */
    public function test_a_placeholder_date_is_reported(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- 2026-08-??: Incydent z alkoholem.")],
        );

        $reported = $this->notes($session, DraftRunNotes::DATE_INCOMPLETE);

        $this->assertCount(1, $reported);
        $this->assertSame('2026-08-??', $reported[0]['date']);
        $this->assertSame('tajlandia', $reported[0]['slug']);
    }

    /**
     * THE SAME HOLE, SPELLED DIFFERENTLY — `- 2026-08-XX:`.
     *
     * The control was built against the one spelling the composer happened to use that day, and
     * ADR-0050 recorded the narrowness rather than papering over it: a placeholder written any other
     * way passed through exactly as invisibly as `??` did before any of this existed. `X` is the other
     * spelling a writer reaches for, in either case.
     */
    public function test_an_x_placeholder_is_reported(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- 2026-08-XX: Incydent.\n- 2026-08-xx: Powrot.")],
        );

        $reported = $this->notes($session, DraftRunNotes::DATE_INCOMPLETE);

        $this->assertSame(
            ['2026-08-XX', '2026-08-xx'],
            array_column($reported, 'date'),
            'both cases of the same placeholder are read',
        );
    }

    /**
     * A DATE THAT SIMPLY STOPS — `- 2026-08: …`.
     *
     * Nothing here is written wrong; something is left off, and the result is the same unreadable
     * chronicle line. Worth catching for the reason the placeholder is: the entry LOOKS dated to a
     * reviewer skimming it, and is not.
     */
    public function test_a_date_that_stops_at_the_month_is_reported(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- 2026-08: Incydent.")],
        );

        $reported = $this->notes($session, DraftRunNotes::DATE_INCOMPLETE);

        $this->assertCount(1, $reported);
        $this->assertSame('2026-08', $reported[0]['date']);
        $this->assertSame('tajlandia', $reported[0]['slug']);
    }

    /** The prose spelling of the same omission — "- sierpień 2026:" — read through the month table. */
    public function test_a_month_named_beside_a_year_is_reported(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- sierpień 2026: Incydent.\n- wrzesnia 2026: Powrot.")],
        );

        $this->assertSame(
            ['sierpień 2026', 'wrzesnia 2026'],
            array_column($this->notes($session, DraftRunNotes::DATE_INCOMPLETE), 'date'),
            'nominative and genitive are the same month',
        );
    }

    /**
     * A SPAN OF YEARS IS NOT A TRUNCATED DATE — "- 2026-27: sezon".
     *
     * The month is range-checked precisely so this line stays out. A control that reports ordinary
     * prose as broken is a control people learn to skip, which costs more than the misses it buys.
     */
    public function test_a_year_span_is_not_a_truncated_date(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- 2026-27: sezon zdjeciowy.\n- 2026-13: numer wydania.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_INCOMPLETE));
    }

    /** And a WORD beside a year is only a date when it names a month — "- Tajlandia 2026:" is a heading. */
    public function test_a_word_that_is_not_a_month_is_not_a_date(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- Tajlandia 2026: caly wyjazd.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_INCOMPLETE));
    }

    /** An ordinary undated bullet is a sentence, not a broken date, and is left alone. */
    public function test_an_undated_bullet_is_not_a_malformed_date(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- Lukasz wygral konkurs.\n- 2026-08-15: wylot.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_INCOMPLETE));
    }

    /**
     * A YEAR NOTHING SUPPORTS. The same material produced 2026 on one measured run and 2023 on the
     * next, so a relation carried `valid_from` with an invented year — which reads as a fact.
     *
     * The server supplies its own year as DATA when the material gives none, and reports an answer that
     * went elsewhere anyway. A REPORT, because a material may be about the past ("three years ago") and
     * an entry dating it correctly would trip exactly this.
     */
    public function test_a_year_the_material_does_not_support_is_reported(): void
    {
        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- 2019-08-15: wylot.")],
        );

        $reported = $this->notes($session, DraftRunNotes::DATE_YEAR_UNSUPPORTED);

        $this->assertCount(1, $reported);
        $this->assertSame(2019, $reported[0]['year']);
    }

    /** The session's own year passes without comment — it is the reference the prompt was given. */
    public function test_the_reference_year_is_not_reported(): void
    {
        $year = now()->year;

        $session = $this->compose(
            '15 sierpnia doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- {$year}-08-15: wylot.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_YEAR_UNSUPPORTED));
    }

    /**
     * WHEN THE MATERIAL STATES A YEAR THE DOCUMENT IS THE AUTHORITY — no reference is injected and no
     * year is second-guessed.
     */
    public function test_a_material_with_its_own_year_is_left_alone(): void
    {
        $session = $this->compose(
            'W 2019 roku, 15 sierpnia, doszlo do incydentu.',
            [$this->entry('tajlandia', "Miasto.\n\n## Kalendarium\n\n- 2019-08-15: wylot.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_YEAR_UNSUPPORTED));
    }

    /** An ISO source is read too, so a material that already writes dates properly is compared as well. */
    public function test_an_iso_date_in_the_source_counts_as_named(): void
    {
        $session = $this->compose(
            'W dniu 2026-07-13 odbyla sie wizyta.',
            [$this->entry('paryz', "Miasto.\n\n## Kalendarium\n\n- 2026-07-13: wizyta.")],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::DATE_NOT_IN_SOURCE));
        $this->assertSame([], $this->notes($session, DraftRunNotes::SOURCE_DATE_UNUSED));
    }
}
