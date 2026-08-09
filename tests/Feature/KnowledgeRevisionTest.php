<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Exceptions\StaleKnowledgeWriteException;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B1 — the append-only REVISION history and the optimistic lock built on top of it.
 *
 * The property pinned here is invisible when it breaks: a revision is appended for every change to the
 * AUTHORED state and for nothing else, so the history is a list of real edits rather than a log of
 * every save. Status flips and review dates are not versions of a document.
 *
 * ------------------------------------------------------------------------------------------------
 * THE RESTORE IS GONE, AND SO ARE ITS TESTS
 *
 * This file used to pin a second property — that restoring an old revision APPENDS rather than rewinds
 * — plus the validation of the lock token that restore read from a raw Request. Both are gone with
 * `POST /entries/{id}/revisions/{id}/restore`, and gone from the SERVICE too: `restoreRevision()` was
 * removed, not merely unrouted, because republishing an old body under the current entry is authoring
 * its text by another name. Nothing else in the module calls it, so there is no live path left to
 * assert it on. Its absence is pinned from the outside by
 * {@see KnowledgeAuthoringWithdrawnTest::test_there_is_no_route_that_restores_an_old_revision()}.
 *
 * What is left is the history itself, which is still READ over HTTP — it is the record of what the AI
 * wrote and a human approved — and the lock, which is what {@see KnowledgeDraftSessionService::publishAmendment()}
 * uses to refuse a rewrite composed against a revision somebody has since replaced.
 */
class KnowledgeRevisionTest extends TestCase
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

    private function create(string $title = 'Cennik', string $content = 'Wersja pierwsza.'): KnowledgeEntry
    {
        return $this->makeEntry(base: $this->base, title: $title, content: $content);
    }

    public function test_creating_an_entry_writes_the_first_revision(): void
    {
        $entry = $this->create();

        $this->assertNotNull($entry->current_revision_id);
        $this->assertDatabaseHas('knowledge_entry_revisions', [
            'id' => $entry->current_revision_id,
            'knowledge_entry_id' => $entry->id,
            'content' => 'Wersja pierwsza.',
            'author_id' => $this->user->id,
        ]);
    }

    public function test_each_content_change_appends_a_revision_and_moves_the_pointer(): void
    {
        $entry = $this->create();
        $first = $entry->current_revision_id;

        $second = $this->updateEntry($entry, content: 'Wersja druga.', changeNote: 'poprawka cen')
            ->current_revision_id;

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $entry->fresh()->revisions()->count());
        $this->assertDatabaseHas('knowledge_entry_revisions', [
            'id' => $second,
            'change_note' => 'poprawka cen',
        ]);
    }

    public function test_a_status_only_change_does_not_append_a_revision(): void
    {
        $entry = $this->create();
        $first = $entry->current_revision_id;

        $updated = $this->updateEntry($entry, status: KnowledgeEntryStatus::APPROVED);

        $this->assertSame(KnowledgeEntryStatus::APPROVED, $updated->status);
        $this->assertSame($first, $updated->current_revision_id);
        $this->assertSame(1, $entry->fresh()->revisions()->count());
    }

    public function test_the_history_lists_revisions_newest_first_with_their_author(): void
    {
        $entry = $this->create();

        $this->updateEntry($entry, content: 'Wersja druga.');

        $this->getJson("/api/knowledge/entries/{$entry->id}/revisions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.content', 'Wersja druga.')
            ->assertJsonPath('data.1.content', 'Wersja pierwsza.')
            ->assertJsonPath('data.0.author.id', $this->user->id);
    }

    // ---- optimistic locking -----------------------------------------------------
    //
    // The token is compared in the SERVICE, which is where it always was; the 409 was the endpoint's
    // rendering of the exception. That rendering is still exercised — an amendment composed against a
    // revision somebody has since replaced surfaces exactly this exception through the accept endpoint
    // (see KnowledgeShadowDraftTest) — so what is pinned here is the rule, at the layer that holds it.

    public function test_a_stale_expected_revision_is_refused(): void
    {
        $entry = $this->create();
        $stale = $entry->current_revision_id;

        // Somebody else saves first.
        $this->updateEntry($entry, content: 'Cudza wersja.');

        $current = $entry->fresh()->current_revision_id;
        $refused = null;

        try {
            $this->updateEntry($entry->fresh(), content: 'Moja wersja.', expectedRevisionId: $stale);
        } catch (StaleKnowledgeWriteException $exception) {
            $refused = $exception;
        }

        $this->assertNotNull($refused, 'a stale token must refuse the write');
        $this->assertSame('Cudza wersja.', (string) $entry->fresh()->content, 'and nothing was overwritten');

        // The exception renders itself, and the shape is part of the contract: 409 because the payload
        // was valid and the STATE moved, plus the revision the caller missed so a client can merge.
        $rendered = $refused->render();

        $this->assertSame(409, $rendered->getStatusCode());
        $this->assertSame(StaleKnowledgeWriteException::CODE, $rendered->getData(true)['code']);
        $this->assertSame($current, $rendered->getData(true)['current_revision_id']);
    }

    public function test_the_current_expected_revision_is_accepted(): void
    {
        $entry = $this->create();

        $this->updateEntry($entry, content: 'Moja wersja.', expectedRevisionId: $entry->current_revision_id);

        $this->assertSame('Moja wersja.', (string) $entry->fresh()->content);
    }

    /**
     * A null token means the caller did not ASK for the check, and that is deliberate: an append-mode
     * amendment composes late against the live text and has nothing to lock against, so requiring a
     * token would only teach callers to send one they never compared.
     */
    public function test_a_write_without_a_token_is_not_gated(): void
    {
        $entry = $this->create();

        $this->updateEntry($entry, content: 'Bez tokenu.');

        $this->assertSame('Bez tokenu.', (string) $entry->fresh()->content);
    }
}
