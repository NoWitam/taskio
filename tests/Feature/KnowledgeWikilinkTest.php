<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B1 — the `[[wikilink]]` graph.
 *
 * The behaviour worth pinning is the GHOST lifecycle: writing a link to an entry that does not exist
 * is not an error, it is how a base surfaces what is missing, and the ghost must attach by itself the
 * moment the target appears. That only works because resolution keys on the SLUG (which never follows
 * a rename) — so these tests are also the practical justification for the slug's stability.
 *
 * Entries are built through the SERVICE ({@see CreatesKnowledgeFixtures}) rather than posted, because
 * nobody writes an entry by hand any more. Nothing about the link graph changed with that: wikilinks
 * are parsed and ghosts adopted inside `KnowledgeEntryService::create()`/`update()`, which is what both
 * the composer and the acceptance path call, so these are the same passes the product runs.
 */
class KnowledgeWikilinkTest extends TestCase
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

        app(TenantContext::class)->set($this->workspace);

        $this->base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function create(string $title, string $content = ''): KnowledgeEntry
    {
        return $this->makeEntry(base: $this->base, title: $title, content: $content);
    }

    // ---- parsing ---------------------------------------------------------------

    public function test_a_link_to_an_existing_entry_resolves(): void
    {
        $target = $this->create('Rabaty')->id;
        $sourceId = $this->create('Cennik', 'Patrz [[rabaty]].')->id;

        $this->assertDatabaseHas('knowledge_links', [
            'from_entry_id' => $sourceId,
            'to_entry_id' => $target,
            'target_slug' => 'rabaty',
            'source' => KnowledgeLinkSource::WIKILINK->value,
        ]);
    }

    public function test_the_labelled_form_stores_the_target_not_the_label(): void
    {
        $this->create('Rabaty');
        $sourceId = $this->create('Cennik', 'Patrz [[rabaty|nasze rabaty]].')->id;

        $link = KnowledgeLink::query()->where('from_entry_id', $sourceId)->firstOrFail();

        $this->assertSame('rabaty', $link->target_slug);
    }

    public function test_a_target_written_as_a_title_is_normalized_to_the_slug(): void
    {
        $target = $this->create('Polityka Cenowa')->id;
        $sourceId = $this->create('Cennik', 'Patrz [[Polityka Cenowa]].')->id;

        $this->assertDatabaseHas('knowledge_links', [
            'from_entry_id' => $sourceId,
            'to_entry_id' => $target,
            'target_slug' => 'polityka-cenowa',
        ]);
    }

    public function test_repeated_links_to_the_same_target_produce_one_edge(): void
    {
        $this->create('Rabaty');
        $sourceId = $this->create('Cennik', 'Patrz [[rabaty]] i jeszcze raz [[rabaty]].')->id;

        $this->assertSame(1, KnowledgeLink::query()->where('from_entry_id', $sourceId)->count());
    }

    // ---- ghosts ----------------------------------------------------------------

    public function test_a_link_to_a_missing_entry_is_stored_as_a_ghost(): void
    {
        $sourceId = $this->create('Cennik', 'Patrz [[jeszcze-nie-istnieje]].')->id;

        $this->assertDatabaseHas('knowledge_links', [
            'from_entry_id' => $sourceId,
            'to_entry_id' => null,
            'target_slug' => 'jeszcze-nie-istnieje',
        ]);
    }

    public function test_a_ghost_attaches_when_its_target_is_created(): void
    {
        $sourceId = $this->create('Cennik', 'Patrz [[rabaty]].')->id;

        $this->assertNull(KnowledgeLink::query()->where('from_entry_id', $sourceId)->firstOrFail()->to_entry_id);

        $targetId = $this->create('Rabaty')->id;

        $this->assertSame(
            $targetId,
            KnowledgeLink::query()->where('from_entry_id', $sourceId)->firstOrFail()->to_entry_id,
        );
    }

    /**
     * A RENAME still degrades and re-adopts, and it is still worth pinning although no person can
     * trigger one: the slug is what the composer writes into `entities[].slug` when it declares an
     * entity, so the applier can mint a handle onto a slug a ghost has been waiting for. The pass being
     * tested — `attachGhosts` on a slug change — is the same one either way.
     */
    public function test_a_ghost_attaches_when_an_entry_is_renamed_onto_its_slug(): void
    {
        $sourceId = $this->create('Cennik', 'Patrz [[polityka-cenowa]].')->id;
        $target = $this->create('Rabaty');

        $this->updateEntry($target, content: '', slug: 'polityka-cenowa');

        $this->assertSame(
            $target->id,
            KnowledgeLink::query()->where('from_entry_id', $sourceId)->firstOrFail()->to_entry_id,
        );
    }

    public function test_renaming_a_target_degrades_the_links_that_named_its_old_slug(): void
    {
        $target = $this->create('Rabaty');
        $sourceId = $this->create('Cennik', 'Patrz [[rabaty]].')->id;

        $this->updateEntry($target, content: '', slug: 'znizki');

        $link = KnowledgeLink::query()->where('from_entry_id', $sourceId)->firstOrFail();

        $this->assertNull($link->to_entry_id, 'the old slug stopped existing, so the edge is a ghost again');
        $this->assertSame('rabaty', $link->target_slug, 'the writer still said [[rabaty]]');
    }

    // ---- re-sync on edit ---------------------------------------------------------

    public function test_editing_the_body_replaces_the_wikilink_set(): void
    {
        $this->create('Rabaty');
        $this->create('Dostawa');

        $source = $this->create('Cennik', 'Patrz [[rabaty]].');

        $this->updateEntry($source, content: 'Teraz patrz [[dostawa]].');

        $slugs = KnowledgeLink::query()->where('from_entry_id', $source->id)->pluck('target_slug')->all();

        $this->assertSame(['dostawa'], $slugs);
    }

    public function test_a_manual_edge_survives_a_wikilink_resync(): void
    {
        $target = $this->create('Rabaty');
        $source = $this->create('Cennik', 'Patrz [[rabaty]].');

        $manual = KnowledgeLink::factory()
            ->between($source, $target)
            ->source(KnowledgeLinkSource::MANUAL)
            ->create();

        $this->updateEntry($source, content: 'Bez linków.');

        $this->assertDatabaseHas('knowledge_links', ['id' => $manual->id]);
        $this->assertSame(
            0,
            KnowledgeLink::query()->where('from_entry_id', $source->id)->ofSource(KnowledgeLinkSource::WIKILINK)->count(),
        );
    }

    // ---- backlinks + exposure ------------------------------------------------------

    public function test_the_entry_payload_exposes_links_and_backlinks(): void
    {
        $targetId = $this->create('Rabaty')->id;
        $this->create('Cennik', 'Patrz [[rabaty]] oraz [[brak]].');

        $this->getJson("/api/knowledge/entries/{$targetId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.backlinks')
            ->assertJsonPath('data.backlinks.0.source_entry.slug', 'cennik')
            ->assertJsonPath('data.backlinks.0.is_ghost', false);

        $sourceId = KnowledgeEntry::query()->where('slug', 'cennik')->firstOrFail()->id;

        $response = $this->getJson("/api/knowledge/entries/{$sourceId}")->assertOk();

        $ghosts = collect($response->json('data.links'))->where('is_ghost', true)->values();

        $this->assertCount(1, $ghosts);
        $this->assertSame('brak', $ghosts[0]['target_slug']);
    }
}
