<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * The BASE CARD contract (B2b): `entries_count`, `ghost_links_count` and `index_summary`.
 *
 * These exist to close an N+1 the front end cannot avoid on its own — a card shows an index badge and
 * a red-link chip, so without them a page of 25 bases is 25 extra requests. The tests therefore assert
 * two different things, and both matter: that the numbers are RIGHT, and that getting them costs the
 * same whether the page holds one base or ten.
 */
class KnowledgeBaseAggregatesTest extends TestCase
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

    private function createEntry(string $title, string $content = 'Tresc wpisu.', ?KnowledgeBase $base = null): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $base ?? $this->base,
            title: $title,
            content: $content,
        );
    }

    private function stampIndexStatus(KnowledgeEntry $entry, KnowledgeIndexStatus $status): void
    {
        KnowledgeEntry::query()->whereKey($entry->getKey())->update(['index_status' => $status->value]);
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

    // ---- the numbers ---------------------------------------------------------------

    /**
     * `pending_budget` has its own bucket. Folding it into `failed` would send an operator hunting a
     * bug that does not exist — nothing is broken, and raising the workspace's AI cap fixes it.
     */
    public function test_the_index_summary_counts_entries_by_state(): void
    {
        $this->stampIndexStatus($this->createEntry('Pierwszy'), KnowledgeIndexStatus::INDEXED);
        $this->stampIndexStatus($this->createEntry('Drugi'), KnowledgeIndexStatus::INDEXED);
        $this->stampIndexStatus($this->createEntry('Trzeci'), KnowledgeIndexStatus::PENDING);
        $this->stampIndexStatus($this->createEntry('Czwarty'), KnowledgeIndexStatus::PENDING_BUDGET);
        $this->stampIndexStatus($this->createEntry('Piaty'), KnowledgeIndexStatus::FAILED);

        $summary = $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.index_summary');

        $this->assertSame(5, $summary['total']);
        $this->assertSame(2, $summary['indexed']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(1, $summary['pending_budget']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['indexing']);
        $this->assertSame(0, $summary['partial']);
    }

    /** The base-level unit is ENTRIES, and `total` agrees with `entries_count` so a badge needs one number. */
    public function test_the_summary_total_matches_the_entry_count_and_ignores_the_trash(): void
    {
        $this->createEntry('Zostaje');
        $trashed = $this->createEntry('Do kosza');

        $this->trashEntry($trashed);

        $response = $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk();

        $this->assertSame(1, $response->json('data.entries_count'));
        $this->assertSame(1, $response->json('data.index_summary.total'));
    }

    /** Red links: unresolved, not dismissed, and not drawn by something that is in the trash. */
    public function test_the_ghost_count_reports_actionable_red_links_only(): void
    {
        $first = $this->createEntry('Pierwszy', 'Zobacz [[nie-ma-tego]] oraz [[ani-tego]].');
        $this->createEntry('Drugi', 'Tez zobacz [[nie-ma-tego]].');

        $this->assertSame(3, $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.ghost_links_count'));

        // A ghost inside a trashed entry is not something anyone can act on.
        $this->trashEntry($first);

        $this->assertSame(1, $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.ghost_links_count'));
    }

    /** A resolved link is not a red link — the chip must fall to zero when the entry is written. */
    public function test_creating_the_missing_entry_clears_the_ghost_count(): void
    {
        $this->createEntry('Pierwszy', 'Zobacz [[cennik]].');
        $this->assertSame(1, $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.ghost_links_count'));

        $this->createEntry('Cennik');

        $this->assertSame(0, $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.ghost_links_count'));
    }

    /** A dismissed ghost is a decision, not an outstanding task. */
    public function test_a_dismissed_ghost_is_not_counted(): void
    {
        $entry = $this->createEntry('Pierwszy', 'Zobacz [[nie-ma-tego]].');

        KnowledgeLink::query()
            ->where('from_entry_id', $entry->getKey())
            ->update(['dismissed_at' => now()]);

        $this->assertSame(0, $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.ghost_links_count'));
    }

    // ---- the shape ------------------------------------------------------------------

    /**
     * Every single-base response carries the same fields. A client that had to check whether
     * `index_summary` came back is a client that will eventually forget to.
     */
    public function test_every_base_response_carries_the_card_contract(): void
    {
        $keys = ['entries_count', 'ghost_links_count', 'index_summary'];

        $created = $this->postJson('/api/knowledge/bases', ['name' => 'Nowa baza'])->assertCreated();
        $created->assertJsonStructure(['data' => $keys]);
        $this->assertSame(0, $created->json('data.index_summary.total'));

        $id = $created->json('data.id');

        $this->patchJson("/api/knowledge/bases/{$id}", ['name' => 'Zmieniona'])->assertOk()
            ->assertJsonStructure(['data' => $keys]);

        $this->getJson("/api/knowledge/bases/{$id}")->assertOk()
            ->assertJsonStructure(['data' => $keys]);

        $this->getJson('/api/knowledge/bases')->assertOk()
            ->assertJsonStructure(['data' => ['*' => $keys]]);
    }

    // ---- the cost --------------------------------------------------------------------

    /**
     * The aggregates are two grouped queries for the WHOLE page. If they were ever computed per base,
     * this is where it would show — and nowhere else, because a demo workspace has one base.
     */
    public function test_listing_bases_costs_the_same_whatever_the_page_holds(): void
    {
        $this->createEntry('Wpis', 'Zobacz [[nie-ma-tego]].');

        $one = $this->countQueries(fn () => $this->getJson('/api/knowledge/bases')->assertOk());

        for ($i = 1; $i <= 8; $i++) {
            $base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
            $this->createEntry("Wpis {$i}", 'Zobacz [[tez-nie-ma]].', $base);
        }

        $many = $this->countQueries(fn () => $this->getJson('/api/knowledge/bases')->assertOk());

        $this->assertSame($one, $many, 'the base list must not issue work per base');
    }
}
