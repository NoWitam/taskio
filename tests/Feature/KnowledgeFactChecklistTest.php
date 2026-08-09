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
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * THE FACT CHECKLIST: what the material says happened, put in front of the REVIEWER.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY A CHECKLIST AND NOT A GATE
 *
 * The obvious design was to have the composer declare what it covered and refuse an answer that missed
 * something. It was rejected because it cannot work: a model that writes "2026-08-15: wyjazd do
 * Tajlandii" and leaves out the alcohol incident inside that day has covered the fact TRUTHFULLY by any
 * check code can make. Keyword matching would be a brittle imitation of reading, and the failure is
 * semantic — the only reader who can see it is a person, who spots it instantly when the sentence sits
 * beside the entry that lacks it.
 *
 * So the server counts the model's own `covers` claims, says which facts nobody claimed, and stops.
 * That is a weaker guarantee than a gate and an honest one, and it is the axis the whole module turns
 * on: the human approves.
 *
 * The model CAN lie — tick a fact it did not write. That is a different and rarer failure than a silent
 * omission, and unlike a silent omission it leaves a number somebody can watch.
 */
class KnowledgeFactChecklistTest extends TestCase
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

        config()->set('knowledge.graph_extraction.enabled', true);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** @param  array<int, array<string, mixed>>  $facts */
    private function compose(array $facts, array $entries, string $source = 'Material zrodlowy.', array $protagonists = []): KnowledgeDraftSession
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(
            ['mentions' => [], 'facts' => $facts, 'protagonists' => $protagonists],
            JSON_UNESCAPED_UNICODE,
        ));
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

    private function entry(string $slug, array $covers = [], string $content = 'Tresc wpisu.'): array
    {
        return [
            'action' => 'create',
            'ref' => 'N1',
            'slug' => $slug,
            'title' => ucfirst($slug),
            'type' => 'concept',
            'content' => $content,
            'metadata' => [],
            'covers' => $covers,
        ];
    }

    // ---- the checklist reaches both readers -------------------------------------------

    /** The composer is shown the list while it divides — the working material, not a promise to sign. */
    public function test_the_facts_reach_the_composer_prompt(): void
    {
        $this->compose(
            [['id' => 'F1', 'text' => 'Lukasz przesadzil z alkoholem', 'date' => '15 sierpnia', 'subjects' => ['Lukasz']]],
            [$this->entry('tajlandia', ['F1'])],
        );

        KnowledgeDraftAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => $prompt->contains('WHAT THIS MATERIAL SAYS HAPPENED')
                && $prompt->contains('- F1 [15 sierpnia] Lukasz przesadzil z alkoholem'),
        );
    }

    /** ...and the reviewer is shown it on the session, with the source's own wording for the date. */
    public function test_the_facts_reach_the_review_payload(): void
    {
        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Lukasz wygral konkurs', 'date' => '13 lipca', 'subjects' => ['Lukasz']]],
            [$this->entry('konkurs', ['F1'])],
        );

        $facts = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.facts');

        $this->assertCount(1, $facts);
        $this->assertSame('F1', $facts[0]['id']);
        $this->assertSame('Lukasz wygral konkurs', $facts[0]['text']);
        $this->assertSame('13 lipca', $facts[0]['date'], 'the source wording, never normalised');
        $this->assertSame(['Lukasz'], $facts[0]['subjects']);
    }

    // ---- who the material is about ----------------------------------------------------

    /**
     * THE PROTAGONIST REACHES THE WRITER AS A REQUIREMENT, not as a paragraph of advice.
     *
     * The measured failure: a material about a woman it only ever calls "influencerka" produced seven
     * entries — every city, the man she met, the contest — and none for her. "Influencerka" is a
     * description, not a name, so she appeared in no `mentions`, therefore in no `unresolved`,
     * therefore on no list the composer was shown. The doctrine did carry a sentence about it, and a
     * sentence is what has now failed three times.
     */
    public function test_the_protagonist_reaches_the_prompt_as_a_requirement(): void
    {
        $this->compose(
            [],
            [$this->entry('influencerka')],
            'Influencerka odwiedzila Paryz.',
            [['description' => 'Influencerka', 'title' => 'Influencerka', 'kind' => 'person']],
        );

        KnowledgeDraftAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => $prompt->contains('WHO THIS MATERIAL IS ABOUT')
                && $prompt->contains('MUST have an entry of its own')
                && $prompt->contains('- Influencerka (person)'),
        );
    }

    /**
     * AND THE SERVER CHECKS WHETHER IT GOT ONE — the half that makes this a mechanism.
     *
     * The reading pass says who the material is about; the composer either writes that entry or does
     * not; comparing the two is a string comparison the server makes on its own, with nothing asked of
     * the model.
     */
    public function test_a_protagonist_with_no_entry_is_reported(): void
    {
        $session = $this->compose(
            [],
            // Seven entries and not one for her — the measured shape of the defect.
            [$this->entry('paryz'), $this->entry('tokio')],
            'Influencerka odwiedzila Paryz i Tokio.',
            [['description' => 'Influencerka', 'title' => 'Influencerka', 'kind' => 'person']],
        );

        $reported = $this->notes($session, DraftRunNotes::PROTAGONIST_WITHOUT_ENTRY);

        $this->assertCount(1, $reported);
        $this->assertSame('Influencerka', $reported[0]['title']);
    }

    /** ...and stays quiet when the entry IS there, matched on the slugified title. */
    public function test_a_protagonist_that_got_an_entry_is_not_reported(): void
    {
        $session = $this->compose(
            [],
            [[
                'action' => 'create', 'ref' => 'N1', 'slug' => 'ignorowany', 'title' => 'Influencerka',
                'type' => 'person', 'content' => 'Tresc.', 'metadata' => [], 'covers' => [],
            ]],
            'Influencerka odwiedzila Paryz.',
            [['description' => 'Influencerka', 'title' => 'Influencerka', 'kind' => 'person']],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::PROTAGONIST_WITHOUT_ENTRY));
    }

    // ---- the accusation, withdrawn ----------------------------------------------------

    /**
     * THE SERVER NO LONGER CLAIMS A FACT WAS MISSED — inverted from the pin that asserted it did.
     *
     * On the first real run the model returned NO `covers` at all, so this note fired for 9 facts out
     * of 9 — including the several the entries plainly recorded. A false alarm at that rate is worse
     * than no alarm: it is the interface asserting something it cannot know, and what it teaches is to
     * stop reading the panel.
     *
     * The list itself keeps its value and stays: it is what surfaced the alcohol incident in the first
     * place. What is gone is the sentence claiming nobody wrote it down.
     */
    public function test_the_server_no_longer_asserts_that_a_fact_was_missed(): void
    {
        $session = $this->compose(
            [
                ['id' => 'F1', 'text' => 'Influencerka poleciala do Tajlandii', 'date' => '15 sierpnia'],
                ['id' => 'F2', 'text' => 'Lukasz przesadzil z alkoholem i wywolal fale hejtu', 'date' => null],
            ],
            [$this->entry('tajlandia', ['F1'])],
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::FACTS_NOT_COVERED));

        // ...and the facts are still all there to be read, which is the half that earned its keep.
        $facts = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.facts');

        $this->assertCount(2, $facts);
        $this->assertStringContainsString('hejtu', $facts[1]['text']);
    }

    /**
     * A CLAIM THAT IS MADE IS STILL REPORTED. The plumbing works when the model feeds it — only the
     * accusation of absence was withdrawn, not the machinery.
     */
    public function test_a_claim_that_is_made_is_still_carried(): void
    {
        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Influencerka poleciala do Tajlandii', 'date' => '15 sierpnia']],
            [$this->entry('tajlandia', ['F1'], "Tresc.\n\n## Kalendarium\n\n- 2026-08-15: wylot.")],
            '15 sierpnia influencerka poleciala do Tajlandii.',
        );

        $fact = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.facts')[0];

        $this->assertTrue($fact['covered']);
        $this->assertSame('tajlandia', $fact['covered_by'][0]['slug']);
    }

    /**
     * WITH THE FACT NOTE GONE, THE DATE CHECK SPEAKS FOR ITSELF AGAIN.
     *
     * The suppression existed so one omission produced one note; there is no longer a richer note to
     * defer to, so the date channel reports what it can prove on its own.
     */
    public function test_an_unused_source_date_is_reported_by_the_date_check(): void
    {
        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Lukasz przesadzil z alkoholem', 'date' => '15 sierpnia']],
            [$this->entry('tajlandia', [])],
            '15 sierpnia doszlo do incydentu w Tajlandii.',
        );

        $this->assertSame([], $this->notes($session, DraftRunNotes::FACTS_NOT_COVERED));
        $this->assertCount(1, $this->notes($session, DraftRunNotes::SOURCE_DATE_UNUSED));
    }

    // ---- the claim, persisted ---------------------------------------------------------

    /** The claim survives onto the draft row — which is what lets the panel say where a fact went. */
    public function test_the_claim_is_stored_on_the_draft(): void
    {
        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Cos sie wydarzylo', 'date' => null]],
            [$this->entry('gdzies', ['F1'])],
        );

        $this->assertSame(['F1'], $session->drafts()->firstOrFail()->covers);
    }

    /**
     * `covered_by` IS A LIST, and that is the point rather than an implementation detail: one fact
     * between two people belongs in BOTH their chronicles. Naming only the first entry would make a
     * complete answer look partial.
     */
    public function test_a_fact_claimed_by_two_entries_names_both(): void
    {
        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Lukasz przeprosil influencerke', 'date' => '13 wrzesnia']],
            [
                ['action' => 'create', 'ref' => 'N1', 'slug' => 'lukasz', 'title' => 'Lukasz', 'type' => 'person', 'content' => 'Tresc.', 'metadata' => [], 'covers' => ['F1']],
                ['action' => 'create', 'ref' => 'N2', 'slug' => 'influencerka', 'title' => 'Influencerka', 'type' => 'person', 'content' => 'Tresc.', 'metadata' => [], 'covers' => ['F1']],
            ],
        );

        $fact = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.facts')[0];

        $this->assertTrue($fact['covered']);
        $this->assertCount(2, $fact['covered_by']);
        $this->assertEqualsCanonicalizing(
            ['lukasz', 'influencerka'],
            array_column($fact['covered_by'], 'slug'),
        );
    }

    /** A fact nobody claimed says so on the wire too, not only in the notes. */
    public function test_an_unclaimed_fact_is_marked_uncovered_on_the_wire(): void
    {
        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Nikt tego nie zapisal', 'date' => null]],
            [$this->entry('gdzies', [])],
        );

        $fact = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.facts')[0];

        $this->assertFalse($fact['covered']);
        $this->assertSame([], $fact['covered_by']);
    }

    /**
     * A HANDLE NAMING NO FACT is dropped and REPORTED — and the entry stands, because a bad footnote is
     * no reason to throw away good writing.
     */
    public function test_a_handle_outside_the_frozen_list_is_refused_with_a_note(): void
    {
        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Jedyny fakt', 'date' => null]],
            [$this->entry('gdzies', ['F1', 'F9'])],
        );

        $this->assertSame(['F1'], $session->drafts()->firstOrFail()->covers, 'the invented handle is gone');
        $this->assertSame(1, $session->drafts()->count(), 'and the entry stands');

        $reported = $this->notes($session, DraftRunNotes::UNKNOWN_FACT_HANDLE);

        $this->assertCount(1, $reported);
        $this->assertSame('F9', $reported[0]['handle']);
    }

    /** An entry written before any of this exists claims nothing, and nothing falls over. */
    public function test_an_entry_from_before_the_column_claims_nothing(): void
    {
        $entry = $this->makeEntry($this->base, 'Stary wpis', 'Tresc sprzed zmiany.');

        $this->assertSame([], $entry->fresh()->covers);
    }

    // ---- fail-soft --------------------------------------------------------------------

    /** No facts and a pass that never ran: composition proceeds exactly as before, silently. */
    public function test_a_run_without_facts_still_composes(): void
    {
        config()->set('knowledge.graph_extraction.enabled', false);

        $session = $this->compose([], [$this->entry('cokolwiek')]);

        $this->assertSame(1, $session->drafts()->count(), 'the composition is untouched');
        $this->assertSame([], $this->notes($session, DraftRunNotes::FACTS_NOT_COVERED));
        $this->assertSame(
            [],
            $this->notes($session, DraftRunNotes::FACTS_UNAVAILABLE),
            'a layer switched off attempted nothing, so nothing is missing',
        );
    }

    /** A pass that RAN and produced nothing is different, and the reviewer is told so. */
    public function test_a_reading_that_produced_no_facts_says_so(): void
    {
        $session = $this->compose([], [$this->entry('cokolwiek')]);

        $this->assertSame(1, $session->drafts()->count());
        $this->assertCount(
            1,
            $this->notes($session, DraftRunNotes::FACTS_UNAVAILABLE),
            'silence here would read as "nothing was missed"',
        );
    }

    /** An entry that omits `covers` entirely is a perfectly good entry — the field is a signal, not a gate. */
    public function test_an_entry_without_covers_is_not_refused(): void
    {
        $entry = $this->entry('cokolwiek');
        unset($entry['covers']);

        $session = $this->compose(
            [['id' => 'F1', 'text' => 'Cos sie wydarzylo', 'date' => null]],
            [$entry],
        );

        $this->assertSame(1, $session->drafts()->count(), 'the draft stands');
        $this->assertSame([], $session->drafts()->firstOrFail()->covers, 'and it simply claims nothing');
        $this->assertSame([], $this->notes($session, DraftRunNotes::FACTS_NOT_COVERED), 'with no accusation attached');
    }
}
