<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\DTOs\KnowledgeEntryDTO;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Exceptions\KnowledgeSlugConflictException;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Services\KnowledgeEntryService;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * NOBODY WRITES KNOWLEDGE BY HAND — pinned from the outside, as a negative.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY A TEST FOR THINGS THAT DO NOT EXIST
 *
 * Deleting an endpoint is not a decision anybody can see six months later. The route file is edited
 * casually, a controller method is a plausible-looking gap, and "surely a knowledge base needs an edit
 * button" is a reasonable thing for a future reader to think. Without this file the withdrawal is an
 * absence; with it, restoring any of it turns a suite red and somebody has to read why.
 *
 * The rule being defended: a person may APPROVE, REFUSE and DIRECT — accept or reject a proposal,
 * abandon a session, refine the prompt, widen the context, configure the base. A person may not
 * AUTHOR: no writing, editing or deleting an entry or a relation by hand.
 *
 * ------------------------------------------------------------------------------------------------
 * TWO BARRIERS, BOTH PINNED
 *
 * The routes are gone (so a request 404s or 405s at the router), AND the policy abilities deny (so a
 * request that reached a controller another way would still be refused). The second is what makes this
 * robust against a future route being added back without the reasoning.
 */
class KnowledgeAuthoringWithdrawnTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $workspace->id);
        app(TenantContext::class)->set($workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $workspace->id, 'language' => 'pl']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** Any status that is not a success — the router's answer depends on whether the path still exists. */
    private function assertRefused(int $status): void
    {
        $this->assertContains($status, [403, 404, 405], "expected a refusal, got {$status}");
    }

    /** One faked composer run proposing exactly the given entry, so a laundering rule can be observed. */
    private function composeOne(array $entry): KnowledgeDraftSession
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => []], JSON_UNESCAPED_UNICODE));
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => [$entry]], JSON_UNESCAPED_UNICODE));

        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => 'Material zrodlowy.',
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id)->refresh();
    }

    // ---- the routes are gone -----------------------------------------------------------

    public function test_there_is_no_route_that_writes_an_entry(): void
    {
        $entry = $this->makeEntry($this->base, 'Paryż', 'Stolica Francji.', KnowledgeEntryType::PLACE);

        $this->assertRefused($this->postJson("/api/knowledge/bases/{$this->base->id}/entries", [
            'title' => 'Recznie napisany wpis',
            'content' => 'Tresc.',
        ])->status());

        $this->assertRefused($this->patchJson("/api/knowledge/entries/{$entry->id}", [
            'content' => 'Przepisane recznie.',
        ])->status());

        $this->assertRefused($this->deleteJson("/api/knowledge/entries/{$entry->id}")->status());
        $this->assertRefused($this->postJson("/api/knowledge/entries/{$entry->id}/restore")->status());
        $this->assertRefused($this->deleteJson("/api/knowledge/entries/{$entry->id}/force")->status());

        $this->assertRefused($this->postJson("/api/knowledge/bases/{$this->base->id}/entries/reorder", [
            'ids' => [(string) $entry->id],
        ])->status());

        // ...and the entry is untouched by any of it.
        $this->assertSame('Stolica Francji.', (string) $entry->fresh()->content);
        $this->assertNull($entry->fresh()->deleted_at);
    }

    public function test_there_is_no_route_that_writes_a_relation(): void
    {
        $anna = $this->makeEntry($this->base, 'Anna Kowalska', 'Osoba.', KnowledgeEntryType::PERSON);
        $acme = $this->makeEntry($this->base, 'Acme', 'Firma.', KnowledgeEntryType::ORGANIZATION);
        $relation = $this->makeRelation($this->base, $anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $this->assertRefused($this->postJson("/api/knowledge/bases/{$this->base->id}/relations", [
            'from_entry_id' => (string) $anna->id,
            'to_entry_id' => (string) $acme->id,
            'relation_type' => KnowledgeRelationType::KNOWS->value,
        ])->status());

        $this->assertRefused($this->patchJson("/api/knowledge/relations/{$relation->id}", [
            'description' => 'Dopisane recznie.',
        ])->status());

        $this->assertRefused($this->postJson("/api/knowledge/relations/{$relation->id}/end")->status());
        $this->assertRefused($this->deleteJson("/api/knowledge/relations/{$relation->id}")->status());

        $this->assertSame(1, KnowledgeRelation::query()->count(), 'no relation was created or destroyed');
        $this->assertNull($relation->fresh()->valid_to, 'and the one that exists was not ended');
    }

    public function test_there_is_no_route_that_restores_an_old_revision(): void
    {
        $entry = $this->makeEntry($this->base, 'Zwroty', 'Pierwsza wersja.');
        $revision = $entry->revisions()->firstOrFail();

        $this->assertRefused(
            $this->postJson("/api/knowledge/entries/{$entry->id}/revisions/{$revision->id}/restore")->status()
        );
    }

    /** Named routes are what a client and a controller both reach for; none of these may resolve. */
    public function test_the_withdrawn_route_names_do_not_exist(): void
    {
        foreach ([
            'knowledge.entries.store',
            'knowledge.entries.update',
            'knowledge.entries.destroy',
            'knowledge.entries.restore',
            'knowledge.entries.force-destroy',
            'knowledge.entries.reorder',
            'knowledge.relations.store',
            'knowledge.relations.update',
            'knowledge.relations.end',
            'knowledge.relations.destroy',
            'knowledge.revisions.restore',
        ] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), "route {$name} must not exist");
        }
    }

    // ---- the guards that nearly came off with the endpoints ---------------------------

    /**
     * AN ENTRY THAT COULD NEVER BE INDEXED IS REFUSED BEFORE A REVIEWER SEES IT.
     *
     * The guard used to live in the entry FormRequest and answered 422 to the person typing. Removing
     * hand-authorship took the request away and the guard went with it silently — so an over-cap entry
     * was accepted, written, and then failed INDEXING in the background, leaving a page in the base
     * that no search would ever return and no screen would ever account for. The composer asks the
     * question now, on the writer's behalf.
     *
     * The CAP is lowered rather than the fixture inflated: the rule under test is "the count is checked
     * against the configured cap", and a 200-section fixture would be testing the chunker's arithmetic.
     */
    public function test_a_draft_that_could_never_be_indexed_is_dropped_and_reported(): void
    {
        config()->set('knowledge.chunking.max_chunks_per_entry', 2);

        $body = '';

        foreach (range(1, 12) as $i) {
            $body .= "## Sekcja {$i}\n\n" . str_repeat("Zdanie o tresci numer {$i}. ", 40) . "\n\n";
        }

        $session = $this->composeOne([
            'action' => 'create',
            'slug' => 'za-duzy',
            'title' => 'Za duzy wpis',
            'type' => 'concept',
            'content' => $body,
            'metadata' => [],
        ]);

        $this->assertSame(0, $session->drafts()->count(), 'the proposal never reached the review screen');
        $this->assertContains(
            'entry_too_many_chunks',
            array_column($session->notes ?? [], 'code'),
            'and the reviewer is told which rule dropped it',
        );
        $this->assertSame(0, KnowledgeEntry::query()->where('slug', 'za-duzy')->count());
    }

    /** Under the cap the same shape goes through, so the guard is not simply refusing everything. */
    public function test_a_draft_within_the_chunk_cap_is_proposed_normally(): void
    {
        config()->set('knowledge.chunking.max_chunks_per_entry', 50);

        $session = $this->composeOne([
            'action' => 'create',
            'slug' => 'zwykly',
            'title' => 'Zwykly wpis',
            'type' => 'concept',
            'content' => "## Sekcja\n\nKrotka tresc wpisu.",
            'metadata' => [],
        ]);

        $this->assertSame(1, $session->drafts()->count());
        // The chunk rule specifically — not "no notes at all", which would also fail on any unrelated
        // degradation the run happens to record and would make this test about something else.
        $this->assertNotContains('entry_too_many_chunks', array_column($session->notes ?? [], 'code'));
    }

    /**
     * TWO LIVE ENTRIES MAY NOT SHARE AN ADDRESS — refused by the service, not by a constraint.
     *
     * `slug` carries a plain index, and `create()` de-collides through `mintSlug()`, so until this
     * barrier the only thing keeping a rename off an occupied address was that the single caller of
     * `update()` happened to de-collide first. A guarantee made of good manners — and withdrawing
     * hand-authorship removed every other writer who might have tripped over it first.
     *
     * It matters because the slug is what `[[wikilinks]]` resolve against: two entries answering to one
     * address make every link to it a coin toss.
     */
    public function test_renaming_an_entry_onto_a_taken_slug_is_refused(): void
    {
        $paris = $this->makeEntry($this->base, 'Paryż', 'Stolica Francji.', KnowledgeEntryType::PLACE, 'paryz');
        $tokyo = $this->makeEntry($this->base, 'Tokio', 'Stolica Japonii.', KnowledgeEntryType::PLACE, 'tokio');

        try {
            app(KnowledgeEntryService::class)->update($tokyo, new KnowledgeEntryDTO(
                title: 'Tokio',
                content: 'Stolica Japonii.',
                metadata: [],
                status: $tokyo->status,
                staleAt: null,
                slug: 'paryz',
            ));

            $this->fail('the rename onto a taken address should have been refused');
        } catch (KnowledgeSlugConflictException) {
            // expected
        }

        $this->assertSame('paryz', (string) $paris->fresh()->slug, 'the occupant is untouched');
        $this->assertSame('tokio', (string) $tokyo->fresh()->slug, 'and the renamer kept its own address');
    }

    /** Re-saving an entry under the slug it already has is not a collision with itself. */
    public function test_saving_an_entry_under_its_own_slug_is_not_a_conflict(): void
    {
        $entry = $this->makeEntry($this->base, 'Paryż', 'Stolica Francji.', KnowledgeEntryType::PLACE, 'paryz');

        app(KnowledgeEntryService::class)->update($entry, new KnowledgeEntryDTO(
            title: 'Paryż',
            content: 'Stolica Francji, poprawiona.',
            metadata: [],
            status: $entry->status,
            staleAt: null,
            slug: 'paryz',
        ));

        $this->assertSame('paryz', (string) $entry->fresh()->slug);
        $this->assertSame('Stolica Francji, poprawiona.', (string) $entry->fresh()->content);
    }

    // ---- the abilities deny, whatever the router does --------------------------------

    public function test_the_authoring_abilities_are_refused_for_everyone(): void
    {
        $entry = $this->makeEntry($this->base, 'Tokio', 'Miasto.', KnowledgeEntryType::PLACE);
        $other = $this->makeEntry($this->base, 'Japonia', 'Kraj.', KnowledgeEntryType::PLACE);
        $relation = $this->makeRelation($this->base, $entry, $other, KnowledgeRelationType::LOCATED_IN);

        // The workspace OWNER, who is the most privileged person there is here.
        $this->assertFalse($this->user->can('create', KnowledgeEntry::class));
        $this->assertFalse($this->user->can('update', $entry));
        $this->assertFalse($this->user->can('delete', $entry));
        $this->assertFalse($this->user->can('restore', $entry));
        $this->assertFalse($this->user->can('forceDelete', $entry));
        $this->assertFalse($this->user->can('reorder', KnowledgeEntry::class));

        $this->assertFalse($this->user->can('create', KnowledgeRelation::class));
        $this->assertFalse($this->user->can('update', $relation));
        $this->assertFalse($this->user->can('end', $relation));
        $this->assertFalse($this->user->can('delete', $relation));
    }

    // ---- and what a person KEPT still works ------------------------------------------

    /**
     * The other half of the rule, and the reason this file is not simply a list of removals: the review
     * powers are intact. A withdrawal that quietly took `accept` or `reject` with it would satisfy every
     * assertion above and destroy the product.
     */
    public function test_reading_and_the_review_powers_are_untouched(): void
    {
        $entry = $this->makeEntry($this->base, 'Zwroty', 'Polityka zwrotow.');

        $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk();
        $this->getJson("/api/knowledge/entries/{$entry->id}")->assertOk();
        $this->getJson("/api/knowledge/entries/{$entry->id}/revisions")->assertOk();
        $this->getJson("/api/knowledge/entries/{$entry->id}/relations")->assertOk();
        $this->getJson("/api/knowledge/bases/{$this->base->id}/graph")->assertOk();
        $this->getJson("/api/knowledge/bases/{$this->base->id}/compose-availability")->assertOk();

        // The ABILITIES a reviewer needs, named as themselves rather than borrowed from authoring —
        // which is what let the withdrawal briefly take the composer down with it.
        $this->assertTrue($this->user->can('compose', KnowledgeEntry::class));
        $this->assertTrue($this->user->can('retryIndex', $entry));

        // The base is still configurable: its CHARTER has to be editable by hand, because the erasure
        // command tells an operator to go and edit it.
        $this->patchJson("/api/knowledge/bases/{$this->base->id}", [
            'name' => $this->base->name,
            'charter' => 'Baza o zwrotach.',
        ])->assertOk();
        $this->assertSame('Baza o zwrotach.', (string) $this->base->fresh()->charter);
    }
}
