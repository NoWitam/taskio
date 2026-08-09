<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Exceptions\KnowledgeSlugConflictException;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B1 — the TRASH and the two cascades.
 *
 * The base cascade is reversible on purpose: trashing a base takes its entries with it, and restoring
 * it brings back exactly the ones that FELL WITH IT — an entry somebody had already trashed on its own
 * stays trashed. Nothing in the database can express that distinction, which is why it lives in the
 * service and is pinned here.
 *
 * The purge cascade is the irreversible one, and the property to protect is that other entries do not
 * silently lose what they said: an inbound link is degraded to a GHOST rather than deleted, so the
 * base can still report that something it referred to is now missing.
 *
 * ------------------------------------------------------------------------------------------------
 * THE BASE HALF IS AN HTTP CONTRACT; THE ENTRY HALF IS NOT ANY MORE
 *
 * A base is still trashed, restored and purged by a person over the API — those routes are untouched,
 * because deciding a whole base is finished is a person's call. An ENTRY is not: `DELETE /entries/{id}`,
 * its restore and its force-delete went with hand-authoring. The cascades themselves did not go
 * anywhere — the base cascade runs them, `knowledge:purge-subject` runs them on an erasure request, and
 * the composer's own reject path soft-deletes a draft through the same method — so the entry-level
 * tests below call the SERVICE, which is what all three of those do.
 */
class KnowledgeTrashTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $owner;

    private User $member;

    private Workspace $workspace;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach([$this->owner->id, $this->member->id]);

        app(TenantContext::class)->set($this->workspace);

        $this->base = KnowledgeBase::factory()->create(['creator_id' => $this->owner->id]);

        $this->actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);
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

    // ---- entry trash ------------------------------------------------------------

    public function test_an_entry_can_be_trashed_and_restored(): void
    {
        $entry = $this->create('Cennik');

        $this->trashEntry($entry);
        $this->assertSoftDeleted('knowledge_entries', ['id' => $entry->id]);

        $this->assertSame((string) $entry->id, (string) $this->restoreEntry($entry)->id);

        $this->assertDatabaseHas('knowledge_entries', ['id' => $entry->id, 'deleted_at' => null]);
    }

    public function test_the_trash_does_not_reserve_a_slug_but_restoring_onto_a_taken_one_is_refused(): void
    {
        $first = $this->create('Cennik');

        $this->trashEntry($first);

        // The slug is free again while the original sits in the trash.
        $this->assertSame('cennik', $this->create('Cennik')->slug);

        // Refused rather than silently re-slugged — see KnowledgeSlugConflictException for why. The
        // exception is the contract; the 409 it used to be rendered as was the endpoint's.
        $this->expectException(KnowledgeSlugConflictException::class);

        $this->restoreEntry($first);
    }

    public function test_restoring_an_entry_re_attaches_the_ghosts_waiting_for_its_slug(): void
    {
        $target = $this->create('Rabaty');
        $sourceId = $this->create('Cennik', 'Patrz [[rabaty]].')->id;

        $this->trashEntry($target);
        $this->restoreEntry($target);

        $this->assertSame(
            (string) $target->id,
            (string) KnowledgeLink::query()->where('from_entry_id', $sourceId)->firstOrFail()->to_entry_id,
        );
    }

    // ---- entry purge -------------------------------------------------------------

    public function test_purging_an_entry_removes_its_derived_rows_and_ghosts_its_backlinks(): void
    {
        $target = $this->create('Rabaty', 'Treść rabatów [[cennik]].');
        $sourceId = $this->create('Cennik', 'Patrz [[rabaty]].')->id;

        // Ordinal 99, clear of the chunks the indexer produced when the entry was created (B2a):
        // `(entry, ordinal)` is unique, and this fixture only exists to prove the purge cascade
        // reaches chunk rows — it does not care which ordinal it sits at.
        KnowledgeEntryChunk::factory()->forEntry($target, 99)->create();

        $this->assertSame(1, $target->revisions()->count());

        $this->purgeEntry($target);

        $this->assertDatabaseMissing('knowledge_entries', ['id' => $target->id]);
        $this->assertDatabaseMissing('knowledge_entry_revisions', ['knowledge_entry_id' => $target->id]);
        $this->assertDatabaseMissing('knowledge_entry_chunks', ['knowledge_entry_id' => $target->id]);
        // Its OWN outgoing edges go with it.
        $this->assertDatabaseMissing('knowledge_links', ['from_entry_id' => $target->id]);

        // The other entry still says [[rabaty]] — the edge survives as a ghost.
        $link = KnowledgeLink::query()->where('from_entry_id', $sourceId)->firstOrFail();
        $this->assertNull($link->to_entry_id);
        $this->assertSame('rabaty', $link->target_slug);
    }

    // ---- base cascade --------------------------------------------------------------

    public function test_trashing_a_base_takes_its_live_entries_and_restoring_brings_them_back(): void
    {
        $keptId = $this->create('Cennik')->id;
        $alreadyTrashed = $this->create('Rabaty');

        // Trashed on its own, well BEFORE the base cascade.
        $this->travelTo(now()->subHour());
        $this->trashEntry($alreadyTrashed);
        $this->travelBack();

        $alreadyTrashedId = $alreadyTrashed->id;

        $this->deleteJson("/api/knowledge/bases/{$this->base->id}")->assertNoContent();

        $this->assertSoftDeleted('knowledge_bases', ['id' => $this->base->id]);
        $this->assertSoftDeleted('knowledge_entries', ['id' => $keptId]);

        $this->postJson("/api/knowledge/bases/{$this->base->id}/restore")->assertOk();

        $this->assertDatabaseHas('knowledge_entries', ['id' => $keptId, 'deleted_at' => null]);
        $this->assertSoftDeleted('knowledge_entries', ['id' => $alreadyTrashedId]);
    }

    public function test_the_trashed_base_list_is_served_by_the_index(): void
    {
        $this->deleteJson("/api/knowledge/bases/{$this->base->id}")->assertNoContent();

        $this->getJson('/api/knowledge/bases')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/knowledge/bases?trashed=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->base->id);
    }

    public function test_purging_a_base_destroys_everything_beneath_it(): void
    {
        $target = $this->create('Rabaty');
        $this->create('Cennik', 'Patrz [[rabaty]].');

        KnowledgeEntryChunk::factory()->forEntry($target)->create();

        $this->deleteJson("/api/knowledge/bases/{$this->base->id}")->assertNoContent();
        $this->deleteJson("/api/knowledge/bases/{$this->base->id}/force")->assertNoContent();

        $this->assertDatabaseMissing('knowledge_bases', ['id' => $this->base->id]);
        $this->assertDatabaseCount('knowledge_entries', 0);
        $this->assertDatabaseCount('knowledge_entry_revisions', 0);
        $this->assertDatabaseCount('knowledge_entry_chunks', 0);
        $this->assertDatabaseCount('knowledge_links', 0);
    }

    public function test_a_member_may_not_purge_someone_elses_base(): void
    {
        $this->deleteJson("/api/knowledge/bases/{$this->base->id}")->assertNoContent();

        $this->actingAs($this->member)->withHeader('X-Workspace-Id', $this->workspace->id);

        $this->deleteJson("/api/knowledge/bases/{$this->base->id}/force")->assertForbidden();
    }
}
