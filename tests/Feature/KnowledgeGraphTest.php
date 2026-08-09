<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * The LINK GRAPH endpoint (B2b): the ego walk, the overview, the cap, and the invariants a renderer
 * is allowed to rely on.
 *
 * Fixtures are built out of `[[wikilinks]]` rather than similarity edges, so the graph's own rules are
 * what is under test and not the vector layer's. Entries are given distinct bodies precisely so no
 * similarity edge appears by accident; the one test that needs a machine-proposed edge makes it
 * deliberately.
 */
class KnowledgeGraphTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------

    private function createEntry(string $title, string $content = '', ?KnowledgeBase $base = null): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $base ?? $this->base,
            title: $title,
            content: $content,
        );
    }

    private function graph(array $params = [], ?KnowledgeBase $base = null)
    {
        $base ??= $this->base;

        return $this->getJson("/api/knowledge/bases/{$base->id}/graph?" . http_build_query($params));
    }

    /** @return array<int, string> */
    private function nodeIds($response): array
    {
        return array_map(static fn (array $node): string => $node['id'], $response->json('data.nodes'));
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /**
     * hub → a, hub → b, hub → (missing slug); a → c; c → d.
     *
     * @return array<string, KnowledgeEntry>
     */
    private function chain(): array
    {
        $d = $this->createEntry('D', 'Tresc czwarta o zupelnie innym temacie.');
        $c = $this->createEntry('C', 'Tresc trzecia prowadzi do [[d]].');
        $a = $this->createEntry('A', 'Tresc pierwsza prowadzi do [[c]].');
        $b = $this->createEntry('B', 'Tresc druga bez odnosnikow.');
        $hub = $this->createEntry('Hub', 'Centrum wskazuje na [[a]], [[b]] oraz [[nie-istnieje]].');

        return compact('hub', 'a', 'b', 'c', 'd');
    }

    // ---- the ego walk ----------------------------------------------------------------

    public function test_depth_one_returns_the_centre_and_its_immediate_neighbours(): void
    {
        $graph = $this->chain();

        $response = $this->graph(['entry' => $graph['hub']->id, 'depth' => 1])->assertOk();

        $this->assertSame((string) $graph['hub']->id, $response->json('data.center'));
        $this->assertEqualsCanonicalizing(
            [(string) $graph['hub']->id, (string) $graph['a']->id, (string) $graph['b']->id],
            $this->nodeIds($response),
        );

        // The centre is at distance 0 and its neighbours at 1 — what a ring layout is drawn from.
        $distances = collect($response->json('data.nodes'))->pluck('distance', 'id');
        $this->assertSame(0, $distances[(string) $graph['hub']->id]);
        $this->assertSame(1, $distances[(string) $graph['a']->id]);
    }

    public function test_depth_two_reaches_one_hop_further(): void
    {
        $graph = $this->chain();

        $response = $this->graph(['entry' => $graph['hub']->id, 'depth' => 2])->assertOk();
        $ids = $this->nodeIds($response);

        $this->assertContains((string) $graph['c']->id, $ids, 'C is two hops away, through A');
        $this->assertNotContains((string) $graph['d']->id, $ids, 'D is three hops away and must stay out');

        $distances = collect($response->json('data.nodes'))->pluck('distance', 'id');
        $this->assertSame(2, $distances[(string) $graph['c']->id]);
    }

    /**
     * The walk unions BOTH directions. Without this, opening the entry nobody links FROM would show an
     * empty neighbourhood while opening its neighbour would show the relation — the same edge, present
     * or absent by accident of which end you clicked.
     */
    public function test_the_walk_follows_edges_in_both_directions(): void
    {
        $target = $this->createEntry('Solo', 'Ten wpis nie wskazuje na nic.');
        $source = $this->createEntry('Wskazujacy', 'Ten wpis wskazuje na [[solo]].');

        $response = $this->graph(['entry' => $target->id, 'depth' => 1])->assertOk();

        $this->assertContains(
            (string) $source->id,
            $this->nodeIds($response),
            'an entry must see the entries that point AT it',
        );
    }

    /** Every drawn edge has both of its endpoints in `nodes` — the invariant a renderer relies on. */
    public function test_every_edge_endpoint_is_present_as_a_node(): void
    {
        $graph = $this->chain();

        $response = $this->graph(['entry' => $graph['hub']->id, 'depth' => 2])->assertOk();
        $ids = $this->nodeIds($response);

        $this->assertNotEmpty($response->json('data.edges'));

        foreach ($response->json('data.edges') as $edge) {
            $this->assertContains($edge['from'], $ids);
            $this->assertContains($edge['to'], $ids);
        }
    }

    // ---- ghosts -----------------------------------------------------------------------

    /** Unresolved references are aggregated by the slug that was meant, with the ids that meant it. */
    public function test_ghost_links_are_aggregated_by_target_slug(): void
    {
        $first = $this->createEntry('Pierwszy', 'Zobacz [[nie-ma-tego]].');
        $second = $this->createEntry('Drugi', 'Tez zobacz [[nie-ma-tego]] i [[ani-tego]].');

        $response = $this->graph()->assertOk();
        $ghosts = collect($response->json('data.ghosts'))->keyBy('target_slug');

        $this->assertSame(2, $ghosts['nie-ma-tego']['count']);
        $this->assertEqualsCanonicalizing(
            [(string) $first->id, (string) $second->id],
            $ghosts['nie-ma-tego']['from_ids'],
        );
        $this->assertSame(1, $ghosts['ani-tego']['count']);
    }

    /** A ghost stops being one the moment the entry it named exists. */
    public function test_a_ghost_disappears_when_its_target_is_created(): void
    {
        $this->createEntry('Pierwszy', 'Zobacz [[cennik]].');

        $this->assertCount(1, $this->graph()->assertOk()->json('data.ghosts'));

        $this->createEntry('Cennik', 'Tresc cennika.');

        $response = $this->graph()->assertOk();
        $this->assertSame([], $response->json('data.ghosts'));
        $this->assertCount(1, $response->json('data.edges'), 'the ghost became a real edge');
    }

    // ---- the cap -----------------------------------------------------------------------

    /** What the cap removed is REPORTED, never silently dropped. */
    public function test_the_node_cap_truncates_and_says_so(): void
    {
        config()->set('knowledge.graph.max_nodes', 3);

        $links = [];

        for ($i = 1; $i <= 5; $i++) {
            $this->createEntry("Sasiad {$i}", "Tresc sasiada numer {$i}.");
            $links[] = "[[sasiad-{$i}]]";
        }

        $hub = $this->createEntry('Hub', 'Centrum wskazuje na ' . implode(' ', $links) . '.');

        $response = $this->graph(['entry' => $hub->id, 'depth' => 1])->assertOk();

        $this->assertCount(3, $response->json('data.nodes'));
        $this->assertContains((string) $hub->id, $this->nodeIds($response), 'the centre is never cut');
        $this->assertSame(3, $response->json('data.truncated.hidden_nodes'));
        $this->assertSame(3, $response->json('data.truncated.hidden_edges'));
    }

    /**
     * B7 — `truncated.hidden_nodes` TELLS THE TRUTH, checked against the edge table rather than
     * against a number typed into the test.
     *
     * The existing cap test above states 3 and 3 for a fixture built to produce them, which proves the
     * receipt is emitted but not that it is CORRECT: an off-by-one in {@see truncate()} (forgetting
     * that the centre occupies one of the cap's slots, say) produces a plausible number that a
     * hardcoded expectation would happily agree with. So this derives the expected figure from the
     * data — every entry reachable in one hop, counted out of `knowledge_links` — and asserts the
     * identity that has to hold for the receipt to mean anything:
     *
     *     nodes drawn + hidden_nodes == nodes the walk actually found
     *
     * A wrong hidden count is not a cosmetic bug. It is the number the reader uses to decide whether
     * the picture in front of them is worth trusting, and one that under-reports turns a partial map
     * into a map that claims to be complete.
     *
     * `sources=wikilink` isolates the walk from machine-proposed edges, so the count is exact and not
     * a hostage to the similarity linker's threshold.
     */
    public function test_the_hidden_node_count_equals_what_the_walk_found_but_could_not_draw(): void
    {
        $cap = 4;
        config()->set('knowledge.graph.max_nodes', $cap);

        $subjects = [
            'cennik hurtowy dla stalych klientow',
            'reklamacje i procedura zwrotu towaru',
            'harmonogram dostaw w sezonie letnim',
            'zasady przyznawania rabatow lojalnosciowych',
            'obsluga zgloszen serwisowych po gwarancji',
            'polityka prywatnosci i przetwarzanie danych',
            'szkolenia wdrozeniowe dla nowych pracownikow',
            'rozliczenia miedzy oddzialami spolki',
            'magazynowanie towarow latwopsujacych sie',
            'wspolpraca z przewoznikami zagranicznymi',
            'kalkulacja kosztow opakowan zwrotnych',
            'archiwizacja dokumentacji ksiegowej',
        ];

        $links = [];

        foreach ($subjects as $index => $subject) {
            $slug = 'temat-' . ($index + 1);
            $this->createEntry('Temat ' . ($index + 1), "Dokument opisujacy {$subject} w naszej firmie.");
            // The minted slug follows the title, so address the link by it explicitly.
            KnowledgeEntry::query()->where('title', 'Temat ' . ($index + 1))->update(['slug' => $slug]);
            $links[] = "[[{$slug}]]";
        }

        $hub = $this->createEntry('Centrum', 'Spis tresci wskazuje na ' . implode(' ', $links) . '.');

        $response = $this->graph([
            'entry' => $hub->id,
            'depth' => 1,
            'sources' => 'wikilink',
        ])->assertOk();

        // What the walk could see: every distinct entry on a wikilink edge touching the hub, plus the
        // hub itself. Read out of the edge table, so the expectation cannot drift from the fixture.
        $discovered = KnowledgeLink::query()
            ->ofSource(KnowledgeLinkSource::WIKILINK)
            ->whereNotNull('to_entry_id')
            ->where(fn ($query) => $query
                ->where('from_entry_id', $hub->getKey())
                ->orWhere('to_entry_id', $hub->getKey()))
            ->get()
            ->flatMap(fn (KnowledgeLink $link): array => [(string) $link->from_entry_id, (string) $link->to_entry_id])
            ->unique()
            ->count();

        $this->assertSame(count($subjects) + 1, $discovered, 'the fixture must really exceed the cap');

        $drawn = count($response->json('data.nodes'));
        $hidden = $response->json('data.truncated.hidden_nodes');

        $this->assertSame($cap, $drawn, 'the cap is what bounds the response');
        $this->assertSame(
            $discovered - $drawn,
            $hidden,
            'hidden_nodes must be exactly what the walk found and the cap removed',
        );
        $this->assertContains((string) $hub->id, $this->nodeIds($response), 'the centre keeps one of the slots');
    }

    /**
     * The OVERVIEW's baseline is a different claim and needs its own check: the mode says it shows
     * "the base", so an uncapped answer would be every entry in it. The identity to hold is therefore
     * against the entry count, not against a walk.
     */
    public function test_the_overview_hidden_count_is_measured_against_the_whole_base(): void
    {
        $cap = 3;
        config()->set('knowledge.graph.max_nodes', $cap);

        foreach ([
            'zasady fakturowania uslug abonamentowych',
            'obieg dokumentow kadrowych w spolce',
            'serwis maszyn produkcyjnych i przeglady',
            'zamowienia materialow biurowych',
            'plan ciaglosci dzialania po awarii',
            'wytyczne dla podwykonawcow budowlanych',
        ] as $index => $subject) {
            $this->createEntry('Rozdzial ' . ($index + 1), "Opracowanie dotyczace {$subject}.");
        }

        $total = KnowledgeEntry::query()->where('knowledge_base_id', $this->base->id)->count();
        $this->assertSame(6, $total);

        $response = $this->graph(['sources' => 'wikilink'])->assertOk();

        $drawn = count($response->json('data.nodes'));

        $this->assertSame($cap, $drawn);
        $this->assertSame(
            $total - $drawn,
            $response->json('data.truncated.hidden_nodes'),
            'an overview that shows half a base must say how much of it is missing',
        );
    }

    /** Nothing is truncated when everything fits, and the receipt says zero rather than nothing. */
    public function test_an_untruncated_graph_reports_zero(): void
    {
        $graph = $this->chain();

        $response = $this->graph(['entry' => $graph['hub']->id, 'depth' => 1])->assertOk();

        $this->assertSame(0, $response->json('data.truncated.hidden_nodes'));
        $this->assertSame(0, $response->json('data.truncated.hidden_edges'));
    }

    // ---- filters -------------------------------------------------------------------------

    /**
     * A dismissed edge is out of the picture unless it is asked for — and when it is asked for it is
     * FLAGGED, so an "undo" affordance can be drawn instead of the client inferring rejection from
     * absence.
     */
    public function test_dismissed_edges_are_excluded_unless_requested(): void
    {
        $content = str_repeat('Akapit o cenniku hurtowym wypelniajacy ten fragment dokumentu. ', 30);
        $this->createEntry('Cennik', $content);
        $second = $this->createEntry('Cennik', $content);

        $link = KnowledgeLink::query()
            ->where('from_entry_id', $second->getKey())
            ->ofSource(KnowledgeLinkSource::SIMILARITY)
            ->firstOrFail();

        $this->postJson("/api/knowledge/links/{$link->id}/dismiss")->assertOk();

        // Scoped to `similarity`: both fixtures are titled "Cennik" and their bodies say "cenniku", so
        // the B10 mention layer legitimately draws its own edge between them. This test is about
        // DISMISSAL, and letting a second, undismissed edge kind into the picture would make it assert
        // something it does not mean.
        $hidden = $this->graph(['entry' => $second->id, 'sources' => 'similarity'])->assertOk();
        $this->assertSame([], $hidden->json('data.edges'));

        $shown = $this->graph([
            'entry' => $second->id,
            'sources' => 'similarity',
            'include_dismissed' => 1,
        ])->assertOk();
        $this->assertCount(1, $shown->json('data.edges'));
        $this->assertTrue($shown->json('data.edges.0.dismissed'));
        $this->assertNotNull($shown->json('data.edges.0.score'));
        $this->assertNotNull($shown->json('data.edges.0.evidence'));
    }

    /** `sources` narrows what is drawn; an unknown value falls back to the default picture. */
    public function test_the_source_filter_selects_which_kinds_of_edge_are_drawn(): void
    {
        $graph = $this->chain();

        $only = $this->graph(['entry' => $graph['hub']->id, 'sources' => 'similarity'])->assertOk();
        $this->assertSame([], $only->json('data.edges'), 'the fixture has no similarity edges');

        $both = $this->graph(['entry' => $graph['hub']->id, 'sources' => 'wikilink,similarity'])->assertOk();
        $this->assertNotEmpty($both->json('data.edges'));
    }

    /**
     * A numeric floor must never delete the links a human WROTE. Wikilinks carry no score, so raising
     * `min_score` has to leave them exactly where they were.
     */
    public function test_raising_the_minimum_score_does_not_remove_scoreless_edges(): void
    {
        $graph = $this->chain();

        $response = $this->graph([
            'entry' => $graph['hub']->id,
            'min_score' => 1,
            'sources' => 'wikilink,similarity',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.edges'), 'a wikilink has no score and must survive any floor');
    }

    // ---- the overview ---------------------------------------------------------------------

    public function test_the_overview_has_no_centre_and_shows_the_base(): void
    {
        $graph = $this->chain();

        $response = $this->graph()->assertOk();

        $this->assertNull($response->json('data.center'));
        $this->assertContains((string) $graph['d']->id, $this->nodeIds($response));

        foreach ($response->json('data.nodes') as $node) {
            $this->assertNull($node['distance'], 'an overview has no distance from anywhere');
        }
    }

    /** A base nobody has linked yet still draws: isolated nodes beat an empty screen. */
    public function test_the_overview_shows_unlinked_entries_too(): void
    {
        $lonely = $this->createEntry('Samotny', 'Wpis bez zadnych odnosnikow.');

        $response = $this->graph()->assertOk();

        $this->assertSame([(string) $lonely->id], $this->nodeIds($response));
        $this->assertSame([], $response->json('data.edges'));
        $this->assertSame(0, $response->json('data.nodes.0.degree'));
    }

    /** `degree` describes THIS picture, so a node's label always matches the edges drawn around it. */
    public function test_degree_counts_the_edges_present_in_the_response(): void
    {
        $graph = $this->chain();

        $response = $this->graph(['entry' => $graph['hub']->id, 'depth' => 1])->assertOk();
        $degrees = collect($response->json('data.nodes'))->pluck('degree', 'id');

        $this->assertSame(2, $degrees[(string) $graph['hub']->id], 'the hub draws two resolved edges here');
        $this->assertSame(1, $degrees[(string) $graph['a']->id]);
    }

    // ---- cost --------------------------------------------------------------------------------

    /**
     * The query count must not grow with the neighbourhood. An N+1 in a graph endpoint is invisible
     * on a demo base and fatal on a real one, and it is exactly the shape of bug a per-node lookup
     * introduces.
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_neighbours(): void
    {
        $small = $this->hubWith(3);
        $smallCount = $this->countQueries(fn () => $this->graph(['entry' => $small->id, 'depth' => 2])->assertOk());

        $large = $this->hubWith(12);
        $largeCount = $this->countQueries(fn () => $this->graph(['entry' => $large->id, 'depth' => 2])->assertOk());

        $this->assertSame($smallCount, $largeCount, 'the graph must cost the same whatever it contains');
        $this->assertLessThan(20, $largeCount, 'a graph read is a handful of queries, not a page of them');
    }

    /** A hub pointing at `$count` fresh neighbours. */
    private function hubWith(int $count): KnowledgeEntry
    {
        $suffix = bin2hex(random_bytes(3));
        $links = [];

        for ($i = 1; $i <= $count; $i++) {
            $this->createEntry("Wezel {$suffix} {$i}", "Tresc wezla {$suffix} numer {$i}.");
            $links[] = "[[wezel-{$suffix}-{$i}]]";
        }

        return $this->createEntry("Hub {$suffix}", 'Wskazuje na ' . implode(' ', $links) . '.');
    }

    // ---- typed relations in the graph (G2) -----------------------------------------------------

    /**
     * THE WALK MUST FOLLOW RELATIONS TOO.
     *
     * This is the failure the union exists to prevent, and it would be invisible from either side: an
     * entry joined to its neighbour ONLY by an approved relation would be missing from that
     * neighbour's ego graph entirely — the base's most reliable connection would be the one least
     * likely to be drawn, while the database was full of it.
     */
    public function test_the_walk_reaches_a_neighbour_connected_only_by_a_relation(): void
    {
        $anna = $this->createEntry('Anna', 'Tresc o Annie.');
        $acme = $this->createEntry('Acme', 'Tresc o firmie.');

        KnowledgeRelation::factory()->between($anna, $acme)->create([
            'relation_type' => KnowledgeRelationType::MEMBER_OF,
        ]);

        $response = $this->graph(['entry' => $anna->id])->assertOk();

        $this->assertContains((string) $acme->id, $this->nodeIds($response));

        $edge = collect($response->json('data.edges'))->firstWhere('kind', 'relation');

        $this->assertNotNull($edge, 'the relation must be DRAWN, not merely walked');
        $this->assertSame('member_of', $edge['relation_type']);
        // Both readings, translated — asserted as "different and non-empty" rather than against a
        // fixed language, which would only re-state the lang file.
        $this->assertNotSame($edge['label'], $edge['inverse_label']);
        $this->assertNotSame('', $edge['label']);
        $this->assertFalse($edge['can_be_dismissed'], 'an asserted relation is ended or retracted, never dismissed');
        $this->assertNull($edge['source'], 'link-only fields are present and null on a relation');
    }

    /**
     * The SAME invariant the wikilink pin above states, restated for relations. The existing one could
     * never have caught a dangling relation end, because it only ever saw links.
     */
    public function test_every_relation_edge_also_has_both_endpoints_in_the_response(): void
    {
        config()->set('knowledge.graph.max_nodes', 3);

        $hub = $this->createEntry('Hub relacji', 'Tresc huba.');

        // More neighbours than the node cap allows, so truncation definitely bites.
        for ($i = 0; $i < 6; $i++) {
            KnowledgeRelation::factory()
                ->between($hub, $this->createEntry("Sasiad {$i}", "Tresc sasiada numer {$i}."))
                ->create();
        }

        $response = $this->graph(['entry' => $hub->id])->assertOk();
        $ids = $this->nodeIds($response);

        $relations = array_values(array_filter(
            $response->json('data.edges'),
            static fn (array $edge): bool => $edge['kind'] === 'relation',
        ));

        $this->assertNotEmpty($relations);

        foreach ($relations as $edge) {
            $this->assertContains($edge['from'], $ids);
            $this->assertContains($edge['to'], $ids);
        }
    }

    public function test_links_and_relations_arrive_discriminated_in_one_list(): void
    {
        $acme = $this->createEntry('Acme', 'Tresc o firmie.');
        $anna = $this->createEntry('Anna', 'Pisze o [[' . $acme->slug . ']].');

        KnowledgeRelation::factory()->between($anna, $acme)->create([
            'relation_type' => KnowledgeRelationType::MEMBER_OF,
        ]);

        $kinds = array_column($this->graph(['entry' => $anna->id])->assertOk()->json('data.edges'), 'kind');

        $this->assertContains('link', $kinds);
        $this->assertContains('relation', $kinds);
    }

    /** Ended and retracted relations are history: the graph answers "what is true" by default. */
    public function test_historical_relations_are_not_drawn_unless_requested(): void
    {
        $anna = $this->createEntry('Anna', 'Tresc o Annie.');
        $acme = $this->createEntry('Acme', 'Tresc o firmie.');

        KnowledgeRelation::factory()->between($anna, $acme)->ended()->create();

        $this->assertSame([], $this->graph(['entry' => $anna->id])->assertOk()->json('data.edges'));

        $shown = $this->graph(['entry' => $anna->id, 'include_historical' => 1])->assertOk();

        $this->assertCount(1, $shown->json('data.edges'));
        $this->assertSame('ended', $shown->json('data.edges.0.state'));
    }

    public function test_relations_can_be_switched_off(): void
    {
        $anna = $this->createEntry('Anna', 'Tresc o Annie.');
        $acme = $this->createEntry('Acme', 'Tresc o firmie.');

        KnowledgeRelation::factory()->between($anna, $acme)->create();

        $this->assertNotEmpty($this->graph(['entry' => $anna->id])->assertOk()->json('data.edges'));
        $this->assertSame([], $this->graph(['entry' => $anna->id, 'relations' => 0])->assertOk()->json('data.edges'));
    }

    /**
     * A relation carries no score, so a score FLOOR must not delete it — the rule wikilinks and manual
     * edges already live by. Tightening a suggestion threshold must never erase a fact.
     */
    public function test_raising_the_minimum_score_does_not_remove_relations(): void
    {
        $anna = $this->createEntry('Anna', 'Tresc o Annie.');
        $acme = $this->createEntry('Acme', 'Tresc o firmie.');

        KnowledgeRelation::factory()->between($anna, $acme)->create();

        $this->assertNotEmpty($this->graph(['entry' => $anna->id, 'min_score' => 0.99])->assertOk()->json('data.edges'));
    }

    /** Relations count towards a base's hubs — "what is this base shaped like" includes its facts. */
    public function test_the_overview_ranks_relation_connected_entries_as_hubs(): void
    {
        $hub = $this->createEntry('Hub przegladu', 'Tresc huba.');

        for ($i = 0; $i < 3; $i++) {
            KnowledgeRelation::factory()
                ->between($hub, $this->createEntry("Sasiad przegladu {$i}", "Tresc sasiada numer {$i}."))
                ->create();
        }

        $node = collect($this->graph()->assertOk()->json('data.nodes'))->firstWhere('id', (string) $hub->id);

        $this->assertNotNull($node);
        $this->assertSame(3, $node['degree']);
    }

    // ---- tenancy -------------------------------------------------------------------------------

    public function test_the_graph_of_another_workspaces_base_is_not_reachable(): void
    {
        $foreign = Workspace::factory()->create(['owner_id' => $this->user->id]);
        app(TenantContext::class)->set($foreign);
        $foreignBase = KnowledgeBase::factory()->create(['workspace_id' => $foreign->id]);
        app(TenantContext::class)->set($this->workspace);

        $this->graph([], $foreignBase)->assertNotFound();
    }

    /** A centre from a DIFFERENT base 404s rather than answering about the wrong base. */
    public function test_a_centre_from_another_base_is_not_found(): void
    {
        $other = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
        $elsewhere = $this->createEntry('Gdzie indziej', 'Tresc.', $other);

        $this->graph(['entry' => $elsewhere->id])->assertNotFound();
    }

    public function test_a_depth_beyond_the_cap_is_refused(): void
    {
        $graph = $this->chain();

        $this->graph(['entry' => $graph['hub']->id, 'depth' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('depth');
    }
}
