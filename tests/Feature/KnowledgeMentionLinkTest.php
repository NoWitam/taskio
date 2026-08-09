<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B10 — the MENTION layer end to end: a base connects itself out of prose, with no `[[wikilinks]]`
 * and no AI call.
 *
 * Everything here runs through the ordinary save → index job → linker pipeline (sync queue), because
 * the property that matters is not "the scanner works" (that is a unit test) but "an author who never
 * heard of wikilinks gets a connected graph".
 */
class KnowledgeMentionLinkTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    private FakeKnowledgeEmbedder $embedder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->embedder = new FakeKnowledgeEmbedder;
        $this->app->instance(KnowledgeEmbedder::class, $this->embedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------

    private function createEntry(string $title, string $content): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $this->base,
            title: $title,
            content: $content,
        );
    }

    /** @return array<int, KnowledgeLink> */
    private function mentionsFrom(KnowledgeEntry $entry): array
    {
        return KnowledgeLink::query()
            ->where('from_entry_id', $entry->getKey())
            ->ofSource(KnowledgeLinkSource::MENTION)
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ---- the owner's case ------------------------------------------------------------

    /** A new entry naming an existing one, in inflected Polish, with no markup at all. */
    public function test_an_entry_that_names_another_draws_a_mention(): void
    {
        $tower = $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu, ukonczona w 1889 roku.');
        $paris = $this->createEntry('Paryz', 'Stolica Francji. Najbardziej znanym symbolem jest wieża Eiffla.');

        $mentions = $this->mentionsFrom($paris);

        $this->assertCount(1, $mentions);
        $this->assertSame((string) $tower->id, (string) $mentions[0]->to_entry_id);
        $this->assertSame((string) $tower->slug, (string) $mentions[0]->target_slug);
        $this->assertNull($mentions[0]->score, 'a mention is not a measurement');

        // The evidence addresses the sentence, in the module's character-offset convention.
        $evidence = $mentions[0]->evidence;
        $this->assertSame(
            'wieża Eiffla',
            mb_substr((string) $paris->content, $evidence['char_start'], $evidence['char_length']),
        );
    }

    /**
     * THE direction that decides whether the feature works at all: entries written BEFORE the target
     * existed must gain the edge the moment the target is created. Otherwise a new entry lands
     * unreachable and stays that way until every older note happens to be re-saved.
     */
    public function test_an_existing_entry_gains_a_mention_when_the_target_is_created_later(): void
    {
        $paris = $this->createEntry('Paryz', 'Stolica Francji. Najbardziej znanym symbolem jest wieża Eiffla.');

        $this->assertSame([], $this->mentionsFrom($paris), 'nothing to mention yet');

        $tower = $this->createEntry('Wieża Eiffla', 'Zelazna wieza ukonczona w 1889 roku.');

        $mentions = $this->mentionsFrom($paris);

        $this->assertCount(1, $mentions);
        $this->assertSame((string) $tower->id, (string) $mentions[0]->to_entry_id);
    }

    public function test_unrelated_entries_are_not_linked(): void
    {
        $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $refunds = $this->createEntry('Zwroty', 'Przyjmujemy zwroty w czternascie dni od zakupu.');

        $this->assertSame([], $this->mentionsFrom($refunds));
    }

    // ---- what a mention defers to ------------------------------------------------------

    /** An authored `[[wikilink]]` is the stronger statement — no duplicate mention beside it. */
    public function test_no_mention_is_drawn_where_a_wikilink_already_points(): void
    {
        $tower = $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $paris = $this->createEntry('Paryz', "Symbolem jest wieża Eiffla, zobacz [[{$tower->slug}]].");

        $this->assertSame([], $this->mentionsFrom($paris));

        $this->assertCount(1, KnowledgeLink::query()
            ->where('from_entry_id', $paris->getKey())
            ->ofSource(KnowledgeLinkSource::WIKILINK)
            ->get());
    }

    /** A dismissal survives re-indexing — "no, that is not a reference" is permanent until undone. */
    public function test_a_dismissed_mention_is_not_redrawn_on_reindex(): void
    {
        $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $paris = $this->createEntry('Paryz', 'Symbolem jest wieża Eiffla.');

        $link = $this->mentionsFrom($paris)[0];
        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk();

        $this->updateEntry($paris, content: 'Zmieniona tresc, ale nadal o wieży Eiffla.');

        $mentions = $this->mentionsFrom($paris);

        $this->assertCount(1, $mentions);
        $this->assertNotNull($mentions[0]->dismissed_at, 'the dismissal must survive the re-index');
    }

    /** Mentions are rewritten from the CURRENT text: a name that is gone takes its edge with it. */
    public function test_a_mention_disappears_when_the_name_is_edited_out(): void
    {
        $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $paris = $this->createEntry('Paryz', 'Symbolem jest wieża Eiffla.');

        $this->assertCount(1, $this->mentionsFrom($paris));

        $this->updateEntry($paris, content: 'Stolica Francji, nad Sekwana.');

        $this->assertSame([], $this->mentionsFrom($paris));
    }

    public function test_mentions_are_capped_per_entry(): void
    {
        config()->set('knowledge.links.max_mentions', 2);

        foreach (['Alfabet', 'Betonowy', 'Cegielnia', 'Dachowka'] as $title) {
            $this->createEntry($title, 'Tresc wpisu o rzeczach.');
        }

        $hub = $this->createEntry('Zbiorczy', 'Alfabet, betonowy, cegielnia i dachowka razem.');

        $this->assertCount(2, $this->mentionsFrom($hub));
    }

    // ---- cost ---------------------------------------------------------------------------

    /**
     * THE property that makes a heuristic change deployable: bumping `links.version` re-derives every
     * base's edges and buys NOTHING. The chunk digests do not move, so every stored vector is reused.
     */
    public function test_relinking_the_whole_base_costs_no_embeddings(): void
    {
        $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu, ukonczona w 1889 roku.');
        $paris = $this->createEntry('Paryz', 'Symbolem jest wieża Eiffla.');

        KnowledgeLink::query()->ofSource(KnowledgeLinkSource::MENTION)->delete();
        $this->embedder->reset();

        // A linker-version bump is what the sweep reacts to.
        config()->set('knowledge.links.version', 99);
        $this->artisan('knowledge:sweep-index')->assertSuccessful();

        $this->assertCount(1, $this->mentionsFrom($paris), 'the sweep must have re-derived the edges');
        $this->assertSame(0, $this->embedder->calls, 'a re-link must reuse every stored vector');
    }

    // ---- integration ----------------------------------------------------------------------

    /**
     * Mentions are in the graph's DEFAULT picture — a base with no wikilinks has nothing else, so
     * hiding them behind a toggle would leave the default view as the empty screen this layer exists
     * to fix.
     *
     * The pair names EACH OTHER here ("wieża Eiffla" in one, "w Paryzu" in the other), so the graph
     * legitimately carries an edge in each direction: a mention is directed — it records who wrote
     * whose name — and collapsing the two would lose that.
     */
    public function test_mentions_appear_in_the_default_graph(): void
    {
        $tower = $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $paris = $this->createEntry('Paryz', 'Symbolem jest wieża Eiffla.');

        $edges = $this->getJson("/api/knowledge/bases/{$this->base->id}/graph?entry={$paris->id}")
            ->assertOk()
            ->json('data.edges');

        $this->assertCount(2, $edges);
        $this->assertSame(['mention', 'mention'], array_column($edges, 'source'));

        // The graph resource shortens the endpoints to `from` / `to` (see KnowledgeGraphResource).
        $pairs = array_map(
            static fn (array $edge): array => [$edge['from'], $edge['to']],
            $edges,
        );

        $this->assertContains([(string) $paris->id, (string) $tower->id], $pairs);
        $this->assertContains([(string) $tower->id, (string) $paris->id], $pairs);
    }

    /** A mention is machine-proposed, so a human may refuse it — the endpoint used to accept similarity only. */
    public function test_a_mention_can_be_dismissed(): void
    {
        $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $paris = $this->createEntry('Paryz', 'Symbolem jest wieża Eiffla.');

        $link = $this->mentionsFrom($paris)[0];

        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk();
        $this->assertNotNull($link->fresh()->dismissed_at);

        $this->deleteJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk();
        $this->assertNull($link->fresh()->dismissed_at);
    }

    /** A wikilink still cannot be dismissed — the widened rule must not have widened too far. */
    public function test_a_wikilink_still_cannot_be_dismissed(): void
    {
        $tower = $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $paris = $this->createEntry('Paryz', "Zobacz [[{$tower->slug}]].");

        $link = KnowledgeLink::query()
            ->where('from_entry_id', $paris->getKey())
            ->ofSource(KnowledgeLinkSource::WIKILINK)
            ->firstOrFail();

        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertJsonValidationErrors('source');
    }

    /** The entry payload carries mentions in `links` / `backlinks` with their dismissibility. */
    public function test_the_entry_resource_carries_mentions_both_ways(): void
    {
        $tower = $this->createEntry('Wieża Eiffla', 'Zelazna wieza w Paryzu.');
        $paris = $this->createEntry('Paryz', 'Symbolem jest wieża Eiffla.');

        $outgoing = $this->getJson("/api/knowledge/entries/{$paris->id}")->assertOk()->json('data.links');
        $this->assertSame('mention', $outgoing[0]['source']);
        $this->assertTrue($outgoing[0]['can_be_dismissed']);
        $this->assertArrayHasKey('char_start', $outgoing[0]['evidence']);

        $backlinks = $this->getJson("/api/knowledge/entries/{$tower->id}")->assertOk()->json('data.backlinks');
        $this->assertSame('mention', $backlinks[0]['source']);
        $this->assertSame((string) $paris->id, $backlinks[0]['from_entry_id']);
    }
}
