<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationState;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * THE SUBJECT PARADIGM, on the owner's own material, from an EMPTY base.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT THIS FILE CAN AND CANNOT PROVE
 *
 * The composer is faked, so nothing here proves the model DIVIDES a document by subject — no test can,
 * and pretending otherwise would be the worst kind of green. What it proves is everything around that
 * judgement, which is where every one of these defects actually lived:
 *
 *   - the INSTRUCTION asks for the right thing, unconditionally, on an empty base (the deadlock);
 *   - a subject-shaped answer SURVIVES the pipeline — types are kept, handles resolve, relations are
 *     written between the entries the reviewer published (the dead matrix, the two channels);
 *   - the things the first pass already found REACH the prompt (the wasted resolution call);
 *   - an episode is stored as an episode — dated, and `ended` when it is over (the present-tense graph).
 *
 * The four owner requirements map onto it directly: entities are subjects, edges carry properties, the
 * date correction is reported rather than silent, and the reversal at the end of the material lands in
 * both the prose and the graph.
 *
 * ------------------------------------------------------------------------------------------------
 * THE COLD START IS THE POINT
 *
 * Every previous graph test started from a base with entries in it, so the one case that mattered most
 * was the one nothing covered: a base with nothing in it produces no "KNOWN ENTITIES" block, the whole
 * entity and relation contract used to hang under a heading that began "WHEN THE REQUEST CARRIES A
 * KNOWN ENTITIES BLOCK", and the model therefore skipped it. Empty base, no entities, no relations,
 * still an empty base — for ever.
 */
class KnowledgeSubjectParadigmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    /** The owner's material, shortened to the beats that carry the requirements. */
    private const MATERIAL = <<<'TEXT'
    Influencerka poleciała do Paryża 12 lipca i została do 15 lipca. 13 lipca weszła na Wieżę Eiffla.
    Ogłosiła wtedy Konkurs Wakacyjny — nagrodą był wyjazd do Tajlandii. Wygrał go Łukasz Barszcz.
    15 sierpnia poleciała do Tajlandii, gdzie doszło do incydentu, za który Łukasz ją publicznie
    skrytykował. 12 września przyleciała do Tokio. 13 lipca zjedli razem sushi w Tokio i Łukasz ją
    przeprosił.
    TEXT;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create([
            'workspace_id' => $this->workspace->id,
            'language' => 'pl',
        ]);

        config()->set('knowledge.graph_extraction.enabled', true);

        // THE CLOCK IS FIXED AFTER THE WHOLE STORY, and it has to be.
        //
        // A relation is born `ended` only when its `valid_to` is already in the past, so a fixture
        // dated near the real "today" would assert one thing this month and the opposite next — the
        // September dates below are in the future as this is written and will not be for long. Freezing
        // the clock is what makes "a finished trip is finished" a statement about the rule rather than
        // about the day the suite happens to run.
        $this->travelTo('2026-12-01 09:00:00');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------------------------------

    /** @param  array<int, array<string, mixed>>  $mentions */
    private function compose(array $reply, array $mentions = []): KnowledgeDraftSession
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => $mentions], JSON_UNESCAPED_UNICODE));
        KnowledgeDraftAgent::fake(fn (): string => json_encode($reply, JSON_UNESCAPED_UNICODE));

        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => self::MATERIAL,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id)->refresh();
    }

    /**
     * The answer a correctly-behaving composer returns for this material: SUBJECTS, each typed, each
     * with a handle, and the episodes as dated relations between them.
     *
     * @return array<string, mixed>
     */
    private function subjectShapedReply(): array
    {
        $entry = fn (string $ref, string $slug, string $title, string $type, string $content): array => [
            'action' => 'create',
            'ref' => $ref,
            'slug' => $slug,
            'title' => $title,
            'type' => $type,
            'aliases' => [],
            'content' => $content,
            'metadata' => [],
        ];

        return [
            'entries' => [
                $entry('N1', 'influencerka', 'Influencerka', 'person', "Autorka materiału.\n\n## Kalendarium\n\n- 2026-07-12: Paryż.\n- 2026-09-13: sushi z [[lukasz-barszcz|Łukaszem]]."),
                $entry('N2', 'lukasz-barszcz', 'Łukasz Barszcz', 'person', "Zwycięzca konkursu.\n\n## Kalendarium\n\n- 2026-09-13: przeprosił Influencerkę."),
                $entry('N3', 'paryz', 'Paryż', 'place', 'Stolica Francji.'),
                $entry('N4', 'wieza-eiffla', 'Wieża Eiffla', 'place', 'Wieża w [[paryz|Paryżu]].'),
                $entry('N5', 'tajlandia', 'Tajlandia', 'place', 'Kraj w Azji.'),
                $entry('N6', 'tokio', 'Tokio', 'place', 'Stolica Japonii.'),
                $entry('N7', 'konkurs-wakacyjny', 'Konkurs Wakacyjny', 'event', 'Konkurs z nagrodą w postaci wyjazdu.'),
            ],
            'graph_updates' => [
                ['op' => 'create', 'from' => 'N1', 'to' => 'N3', 'type' => 'visited', 'valid_from' => '2026-07-12', 'valid_to' => '2026-07-15', 'description' => 'Pobyt w Paryżu.'],
                ['op' => 'create', 'from' => 'N4', 'to' => 'N3', 'type' => 'located_in', 'description' => 'Wieża stoi w Paryżu.'],
                ['op' => 'create', 'from' => 'N1', 'to' => 'N7', 'type' => 'organized', 'valid_from' => '2026-07-13', 'description' => 'Ogłosiła konkurs.'],
                ['op' => 'create', 'from' => 'N2', 'to' => 'N7', 'type' => 'won', 'valid_from' => '2026-07-13', 'description' => 'Wygrał konkurs.'],
                ['op' => 'create', 'from' => 'N1', 'to' => 'N5', 'type' => 'visited', 'valid_from' => '2026-08-15', 'description' => 'Wyjazd do Tajlandii.'],
                ['op' => 'create', 'from' => 'N1', 'to' => 'N6', 'type' => 'visited', 'valid_from' => '2026-09-12', 'description' => 'Przylot do Tokio.'],
                // THE REVERSAL AT THE END OF THE MATERIAL — the criticism, then the apology that
                // replaced it. Both directed, both from the actor, both carrying sentiment.
                ['op' => 'create', 'from' => 'N2', 'to' => 'N1', 'type' => 'interacted_with', 'valid_from' => '2026-08-15', 'valid_to' => '2026-09-13', 'description' => 'Publiczna krytyka.', 'properties' => ['act' => 'criticised', 'sentiment' => 'negative']],
                ['op' => 'create', 'from' => 'N2', 'to' => 'N1', 'type' => 'interacted_with', 'valid_from' => '2026-09-13', 'description' => 'Przeprosiny przy sushi.', 'properties' => ['act' => 'apologised', 'sentiment' => 'positive']],
            ],
            // THE EDITORIAL CORRECTION, declared. The material dates the sushi 13 July, which cannot be
            // true: she reaches Tokyo on 12 September and the apology is for something that happened in
            // August.
            'unresolved' => [[
                'mention' => '13 lipca — sushi w Tokio',
                'note' => 'poprawiono na 13 września: pobyt w Tokio zaczyna się 12 września',
            ]],
        ];
    }

    // ---- the prompt ------------------------------------------------------------------------

    /**
     * THE COLD-START DEADLOCK: the contract is asked for on a base with nothing in it.
     *
     * The entity and relation contract used to live under "WHEN THE REQUEST CARRIES A KNOWN ENTITIES
     * BLOCK", and that block is rendered only from a non-empty resolution set. On an empty base the
     * model was shown a conditional whose condition was false, skipped the section, returned prose
     * only — and so the base stayed empty and the condition stayed false. For ever.
     */
    public function test_the_instruction_asks_for_relations_on_an_empty_base(): void
    {
        $this->assertSame(0, KnowledgeEntry::query()->count(), 'the base is empty');

        $this->compose(['entries' => [[
            'action' => 'create', 'ref' => 'N1', 'slug' => 'x', 'title' => 'X',
            'type' => 'concept', 'content' => 'Tresc.', 'metadata' => [],
        ]]]);

        KnowledgeDraftAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'graph_updates')
                // Stated UNCONDITIONALLY — the old heading made the whole section conditional.
                && str_contains($instructions, 'WHETHER OR NOT THE REQUEST CARRIES')
                && str_contains($instructions, 'AN EMPTY BASE IS THE NORMAL CASE')
                // The doctrine, and the ban on episode-shaped entries.
                && str_contains($instructions, 'AN ENTRY IS A SUBJECT, NOT A STORY')
                && str_contains($instructions, 'NEVER title an entry after an episode')
                // The matrix the model is now judged against.
                && str_contains($instructions, 'visited          person|organization -> place|event')
                && str_contains($instructions, 'interacted_with');
        });
    }

    /**
     * THE ENTRY IS A CHRONICLE, NOT AN ENCYCLOPEDIA ARTICLE — pinned on the instruction.
     *
     * This is the regression the subject doctrine itself caused, and it is pinned here because nothing
     * downstream can catch it: laundering cannot tell "Warszawa to stolica Polski" from a fact, and it
     * must not try. The only defence is the instruction, so the instruction is what is under test.
     *
     * On the first real run every entry came back correctly typed and correctly chosen — and written
     * from the model's own knowledge of the world. "Warszawa to stolica Polski, często stanowiąca
     * punkt przesiadkowy…" while the material said she met Łukasz there on 14 August before the
     * flight. The subject was right; the content recorded nothing that had happened.
     */
    public function test_the_instruction_forbids_knowledge_from_outside_the_material(): void
    {
        $this->compose(['entries' => [[
            'action' => 'create', 'ref' => 'N1', 'slug' => 'x', 'title' => 'X',
            'type' => 'concept', 'content' => 'Tresc.', 'metadata' => [],
        ]]]);

        KnowledgeDraftAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'A CHRONICLE OF THE MATERIAL, NOT AN ARTICLE ABOUT THE SUBJECT')
                // The operational test a model can apply to its own sentence.
                && str_contains($instructions, 'THE REMOVAL TEST')
                && str_contains($instructions, 'IT DOES NOT BELONG IN THE ENTRY')
                // The worked counter-example, in the language the base is written in.
                && str_contains($instructions, 'Warszawa to stolica Polski')
                // The uncomfortable half of the material is the half most often dropped.
                && str_contains($instructions, 'THE DRAMA IS THE CONTENT')
                && str_contains($instructions, 'PADDING WITH WHAT YOU KNOW IS INVENTING');
        });
    }

    /**
     * NO "KNOWN ENTITIES" BLOCK MEANS NO `E` HANDLES — pinned on the instruction.
     *
     * The second real run invented `E1` for the main subject on a base with nothing in it, and all
     * eight of its relations were discarded as `unknown_handle`. The discard was correct; the invention
     * was the defect, and the instruction had invited it by offering `E` handles "when the request
     * carries one" — which reads as a note about availability rather than as a prohibition.
     */
    public function test_the_instruction_forbids_inventing_entity_handles(): void
    {
        $this->compose(['entries' => [[
            'action' => 'create', 'ref' => 'N1', 'slug' => 'x', 'title' => 'X',
            'type' => 'concept', 'content' => 'Tresc.', 'metadata' => [],
        ]]]);

        KnowledgeDraftAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'IF THERE IS NO "KNOWN ENTITIES" BLOCK, THERE ARE NO `E` HANDLES')
                // Standing facts, not only episodes — the edges the first run never proposed at all.
                && str_contains($instructions, 'RELATIONS ARE NOT ONLY EPISODES')
                && str_contains($instructions, 'take the main verbs of the material');
        });
    }

    /**
     * THE FIRST PASS'S FINDINGS REACH THE SECOND PASS.
     *
     * Entity resolution already reads the material, names what it finds and judges each one's kind, on
     * a metered call the user has paid for. Nothing downstream ever opened `unresolved` — so the
     * composer had to re-derive the list of subjects from prose it was reading for the first time,
     * which is precisely the judgement it was getting wrong.
     */
    public function test_the_subjects_the_first_pass_found_reach_the_prompt(): void
    {
        $this->compose(
            ['entries' => [[
                'action' => 'create', 'ref' => 'N1', 'slug' => 'x', 'title' => 'X',
                'type' => 'concept', 'content' => 'Tresc.', 'metadata' => [],
            ]]],
            [
                ['text' => 'Łukasz Barszcz', 'kind' => 'person', 'context' => 'Łukasz wygrał konkurs'],
                ['text' => 'Wieża Eiffla', 'kind' => 'place', 'context' => 'weszła na Wieżę Eiffla'],
            ],
        );

        KnowledgeDraftAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => $prompt->contains('THINGS THIS MATERIAL NAMES')
                && $prompt->contains('- Łukasz Barszcz (person)')
                && $prompt->contains('- Wieża Eiffla (place)'),
        );
    }

    // ---- the pipeline ----------------------------------------------------------------------

    /**
     * THE OWNER'S ACCEPTANCE SCENARIO, end to end: subjects in, a live graph out.
     *
     * Every assertion here failed before this work, and each for its own reason — the types were
     * dropped in laundering, the handles resolved to nothing on an empty base, the episodes were
     * written as present-tense facts, and `sentiment` was refused by every verb in the vocabulary.
     */
    public function test_a_subject_shaped_answer_produces_typed_entries_and_a_dated_graph(): void
    {
        $session = $this->compose($this->subjectShapedReply(), [
            ['text' => 'Łukasz Barszcz', 'kind' => 'person', 'context' => 'konkurs'],
        ]);

        // --- the proposal ------------------------------------------------------------------
        $drafts = $session->drafts()->get();

        $this->assertCount(7, $drafts, 'one draft per subject — the entry cap no longer truncates them');

        // EVERY ENTRY IS TYPED. This is what makes the pair matrix mean anything; before it, 100% of
        // composed entries were untyped and the matrix was never consulted once.
        $this->assertSame(
            [],
            $drafts->filter(fn (KnowledgeEntry $draft): bool => $draft->entry_type === null)->all(),
            'no entry may reach a reviewer untyped',
        );
        $this->assertSame(KnowledgeEntryType::PERSON, $drafts->firstWhere('title', 'Influencerka')->entry_type);
        $this->assertSame(KnowledgeEntryType::PLACE, $drafts->firstWhere('title', 'Wieża Eiffla')->entry_type);

        // NO NARRATIVE ENTRIES. The failure mode this whole refactor is against: an episode wearing a
        // title. Checked over the titles the reviewer is actually shown.
        foreach ($drafts as $draft) {
            foreach (['Pobyt', 'Podróż', 'Wyjazd', 'Spotkanie', 'Incydent'] as $episode) {
                $this->assertStringNotContainsString($episode, (string) $draft->title);
            }
        }

        // EXACTLY ONE `event`, and it is the thing with a name of its own.
        $events = $drafts->filter(fn (KnowledgeEntry $draft): bool => $draft->entry_type === KnowledgeEntryType::EVENT);

        $this->assertCount(1, $events);
        $this->assertSame('Konkurs Wakacyjny', $events->first()->title);

        // THE EDITORIAL CORRECTION IS REPORTED rather than made silently.
        $unresolved = $session->graphOps()['unresolved'];

        $this->assertCount(1, $unresolved);
        $this->assertStringContainsString('13 września', $unresolved[0]['note']);

        // --- the acceptance ----------------------------------------------------------------
        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => $drafts->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'status' => 'approved',
        ])->assertOk();

        $this->assertCount(7, $response->json('accepted'));
        $this->assertCount(8, $response->json('relations'), 'every relation found both of its ends');

        // ONE ENTRY PER SUBJECT — no `paryz-2`. The single-channel rule, from the outside.
        $this->assertSame(7, KnowledgeEntry::query()->count());
        $this->assertSame(1, KnowledgeEntry::query()->where('slug', 'paryz')->count());
        $this->assertSame(0, KnowledgeEntry::query()->where('slug', 'like', '%-2')->count());

        // --- the graph a reader gets --------------------------------------------------------
        $influencer = KnowledgeEntry::query()->where('slug', 'influencerka')->firstOrFail();
        $paris = KnowledgeEntry::query()->where('slug', 'paryz')->firstOrFail();

        $trip = KnowledgeRelation::query()
            ->where('from_entry_id', $influencer->id)
            ->where('to_entry_id', $paris->id)
            ->firstOrFail();

        $this->assertSame(KnowledgeRelationType::VISITED, $trip->relation_type);
        $this->assertSame('2026-07-12', $trip->valid_from?->toDateString());
        $this->assertSame('2026-07-15', $trip->valid_to?->toDateString());

        // A FINISHED TRIP IS FINISHED. Born `ended`, so the graph does not assert in the present tense
        // that she is in Paris — and so the closed episode stops consuming her relation budget.
        $this->assertSame(KnowledgeRelationState::ENDED, $trip->state);

        // ...while the geography that outlives everybody stays active.
        $tower = KnowledgeRelation::query()->where('relation_type', KnowledgeRelationType::LOCATED_IN->value)->firstOrFail();

        $this->assertSame(KnowledgeRelationState::ACTIVE, $tower->state);

        // --- THE REVERSAL AT THE END OF THE MATERIAL ----------------------------------------
        //
        // The owner's fourth requirement: the last paragraph is the one that changes what is true, and
        // it has to reach the graph as well as the prose. Both acts are recorded, both DIRECTED from
        // Łukasz, and they are told apart by their sentiment rather than by their prose.
        $lukasz = KnowledgeEntry::query()->where('slug', 'lukasz-barszcz')->firstOrFail();

        $acts = KnowledgeRelation::query()
            ->where('relation_type', KnowledgeRelationType::INTERACTED_WITH->value)
            ->where('from_entry_id', $lukasz->id)
            ->orderBy('valid_from')
            ->get();

        $this->assertCount(2, $acts, 'the criticism and the apology are two facts, not one edited one');
        $this->assertSame('negative', $acts[0]->properties['sentiment']);
        $this->assertSame(KnowledgeRelationState::ENDED, $acts[0]->state, 'the conflict is over');
        $this->assertSame('positive', $acts[1]->properties['sentiment']);
        $this->assertSame('apologised', $acts[1]->properties['act']);
        $this->assertSame(KnowledgeRelationState::ACTIVE, $acts[1]->state);

        // ...and it is in the prose too, in both people's entries.
        $this->assertStringContainsString('2026-09-13', (string) $influencer->content);
        $this->assertStringContainsString('przeprosił', (string) $lukasz->content);
    }

    /**
     * A SUBJECT THE REVIEWER REFUSED TAKES ITS RELATIONS WITH IT.
     *
     * The counterpart of the single-channel rule, and the reason it is safe: the graph half can no
     * longer mint its own copy of a page the reviewer declined, because the handle resolves by finding
     * the PUBLISHED draft. No draft, no entity, and the relation says so.
     */
    public function test_a_relation_whose_subject_was_refused_is_reported_not_invented(): void
    {
        $session = $this->compose([
            'entries' => [[
                'action' => 'create', 'ref' => 'N1', 'slug' => 'paryz', 'title' => 'Paryż',
                'type' => 'place', 'content' => 'Stolica Francji.', 'metadata' => [],
            ], [
                'action' => 'create', 'ref' => 'N2', 'slug' => 'influencerka', 'title' => 'Influencerka',
                'type' => 'person', 'content' => 'Autorka.', 'metadata' => [],
            ]],
            'graph_updates' => [
                ['op' => 'create', 'from' => 'N2', 'to' => 'N1', 'type' => 'visited', 'valid_from' => '2026-07-12'],
            ],
        ]);

        // Only the person is accepted; the city is refused.
        $person = $session->drafts()->where('slug', 'influencerka')->firstOrFail();

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $person->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame([], $response->json('relations'));
        $this->assertNull(KnowledgeEntry::query()->where('slug', 'paryz')->first(), 'no page was invented');
        $this->assertSame(0, KnowledgeRelation::query()->count());
        $this->assertSame(
            ['dependency_not_accepted'],
            array_column($response->json('skipped'), 'code'),
            'and the reviewer is told why, in terms of their own decision',
        );

        // THE MISSING SUBJECT IS NAMED. `N1` means nothing to somebody who just unticked a card, and in
        // a subject graph one refused card can take every edge with it — which is what happened on the
        // owner's first run: nine subjects, eight accepted, and a response that said
        // `dependency_not_accepted` eight times without once saying whose page was missing.
        $this->assertSame(
            ['N1' => 'Paryż'],
            $response->json('skipped')[0]['missing'],
        );
    }

    /**
     * AN INVENTED `E` HANDLE IS REFUSED — the behaviour behind the instruction above.
     *
     * The base is empty, so no "KNOWN ENTITIES" block exists and `E1` addresses nothing. Laundering
     * already dropped it correctly on the owner's second run; pinned here as a regression, because this
     * is the barrier that stops a hallucinated address from being written against whatever entry
     * happened to sort first.
     */
    public function test_a_relation_naming_an_entity_handle_on_an_empty_base_is_refused(): void
    {
        $session = $this->compose([
            'entries' => [[
                'action' => 'create', 'ref' => 'N1', 'slug' => 'tokio', 'title' => 'Tokio',
                'type' => 'place', 'content' => 'Miejsce spotkania.', 'metadata' => [],
            ]],
            'graph_updates' => [
                // The hallucination: `E1` was never shown, because nothing was ever shown.
                ['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'visited', 'valid_from' => '2026-09-12'],
                // ...while a relation between handles this answer actually minted survives.
                ['op' => 'create', 'from' => 'N1', 'to' => 'N1', 'type' => 'related_to'],
            ],
        ]);

        $ops = $session->graphOps();

        $this->assertSame([], $ops['graph_updates'], 'neither operation was kept');
        $this->assertContains('unknown_handle', array_column($ops['rejected'], 'code'));
        // The self-loop is refused on its own terms, so the two rejections are distinguishable.
        $this->assertContains('self_loop', array_column($ops['rejected'], 'code'));
    }
}
