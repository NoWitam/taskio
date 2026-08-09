<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Events\KnowledgeDraftSessionUpdated;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Knowledge\Support\ShadowSlug;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * THE SHADOW'S LIFE AFTER THE HAPPY PATH (B16).
 *
 * {@see KnowledgeShadowDraftTest} proves a shadow is produced, applied, conflicted and rebased. This
 * file covers the states that only appear once shadows meet TIME and OTHER PEOPLE — the ones nothing
 * exercised, each of which has a branch in production code standing ready for it:
 *
 *   TWO PROPOSALS, ONE ENTRY     The wire carries `amended_by` as a LIST and the accept loop reports
 *                                conflicts one by one, both explicitly "in case it ever happens". It
 *                                happens the moment two people compose against the same base.
 *   A PURGED FROZEN REVISION     The diff falls back to the target's newest revision. An erasure
 *                                request can delete exactly that row, so the fallback is reachable.
 *   THE REAPER MEETS A SHADOW    Abandoning purges drafts through the entry service's cascade. A
 *                                shadow POINTS AT a live entry, so "purge the draft" must not become
 *                                "purge what it points at".
 *   THE SETTLE AFTER A REFINE    The composer waits on a broadcast instead of polling. A second run
 *                                that settled silently would leave the board spinning forever.
 */
class KnowledgeShadowLifecycleTest extends TestCase
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

        $this->base = KnowledgeBase::factory()->create([
            'workspace_id' => $this->workspace->id,
            'language' => 'pl',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------------------------------

    private function fakeComposer(string $reply): void
    {
        KnowledgeDraftAgent::fake(fn () => $reply);
    }

    private function reply(array $entries): string
    {
        return json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE);
    }

    /** A real, indexed entry whose passage is an exact vector match for the source material. */
    private function retrievableEntry(string $title, string $slug, string $content, string $matches): KnowledgeEntry
    {
        $id = (string) $this->makeEntry(base: $this->base, title: $title, content: $content)->id;

        $entry = KnowledgeEntry::query()->findOrFail($id);
        $entry->forceFill(['slug' => $slug])->save();

        $chunk = KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->id)
            ->orderBy('ordinal')
            ->firstOrFail();

        ChunkVector::write(
            (string) $chunk->id,
            FakeKnowledgeEmbedder::vectorFor($matches, (int) config('knowledge.embedding.dimensions')),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        return $entry->refresh();
    }

    private function start(string $sourceText): KnowledgeDraftSession
    {
        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => $sourceText,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id);
    }

    /** @return array<int, KnowledgeEntry> */
    private function draftsOf(KnowledgeDraftSession $session): array
    {
        return $session->drafts()->get()->all();
    }

    private function accept(KnowledgeDraftSession $session, array $entryIds): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => array_map('strval', $entryIds),
            'status' => 'approved',
        ]);
    }

    // ---- two proposals, one entry ------------------------------------------------------------

    /**
     * WITHIN ONE SESSION the composer refuses the second proposal for an entry and DEGRADES it to a
     * create, because two shadows on one target cannot both be applied and offering both as if they
     * could is worse than offering one.
     *
     * Pinned because it is the rule that makes the ordinary board honest — and because the tests below
     * deliberately step around it, so the reader needs to know it exists.
     */
    public function test_one_session_never_mints_two_shadows_for_the_same_entry(): void
    {
        $source = 'Zwroty w 30 dni.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Pierwsza propozycja.', 'metadata' => []],
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty inaczej', 'content' => 'Druga propozycja.', 'metadata' => []],
        ]));

        $drafts = $this->draftsOf($this->start($source));

        $shadows = array_values(array_filter($drafts, fn (KnowledgeEntry $d): bool => $d->isShadow()));
        $creates = array_values(array_filter($drafts, fn (KnowledgeEntry $d): bool => !$d->isShadow()));

        $this->assertCount(1, $shadows, 'only the first proposal may target the entry');
        $this->assertCount(1, $creates, 'the second must survive as a create rather than be dropped');
        $this->assertSame((string) $target->id, (string) $shadows[0]->targets_entry_id);
        $this->assertFalse(ShadowSlug::is($creates[0]->slug), 'the degraded proposal takes a real address');
    }

    /**
     * TWO SESSIONS, one entry — the way it really happens: two people compose against the same base at
     * the same time, and each is offered an amendment of the same entry.
     *
     * The first acceptance wins. The second is not merely refused: it comes back as a CONFLICT carrying
     * the revision it now has to be rebased onto, which is exactly the shape the composer's board reads
     * to offer "rebase" instead of "it failed".
     */
    public function test_the_second_sessions_amendment_of_the_same_entry_conflicts_after_the_first_is_accepted(): void
    {
        $source = 'Zwroty w 30 dni.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Wersja z sesji A.', 'metadata' => []],
        ]));
        $sessionA = $this->start($source);
        $shadowA = $this->draftsOf($sessionA)[0];

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Wersja z sesji B.', 'metadata' => []],
        ]));
        $sessionB = $this->start($source);
        $shadowB = $this->draftsOf($sessionB)[0];

        // Both froze the SAME revision — neither is stale yet.
        $this->assertSame($shadowA->target_revision_id, $shadowB->target_revision_id);
        $this->assertFalse($shadowB->fresh()->targetRevisionIsStale());

        $this->accept($sessionA, [$shadowA->id])->assertOk()->assertJsonCount(1, 'accepted')->assertJsonCount(0, 'conflicts');
        $this->assertSame('Wersja z sesji A.', $target->fresh()->content);

        // B's token now names a revision that is no longer current.
        $this->assertTrue($shadowB->fresh()->targetRevisionIsStale());

        $conflicted = $this->accept($sessionB, [$shadowB->id])->assertOk();

        $this->assertSame([], $conflicted->json('accepted'));
        $this->assertCount(1, $conflicted->json('conflicts'));
        $this->assertSame((string) $shadowB->id, $conflicted->json('conflicts.0.entry_id'));
        $this->assertSame((string) $target->id, $conflicted->json('conflicts.0.targets_entry_id'));
        // THE VALUE THE CLIENT REBASES ONTO — asserted by equality, not merely as "not null": the board
        // uses it to decide whether the card it is holding is already current.
        $this->assertSame(
            (string) $target->fresh()->current_revision_id,
            (string) $conflicted->json('conflicts.0.current_revision_id'),
        );

        // A's text stands; B's proposal is still on the table, awaiting a rebase.
        $this->assertSame('Wersja z sesji A.', $target->fresh()->content);
        $this->assertNotNull($shadowB->fresh());

        // And the rebase makes it applicable again, over A's version.
        $this->postJson("/api/knowledge/draft-sessions/{$sessionB->id}/rebase", ['entry_id' => (string) $shadowB->id])
            ->assertOk()
            ->assertJsonPath('data.target_revision_stale', false);

        $this->accept($sessionB, [$shadowB->id])->assertOk()->assertJsonCount(1, 'accepted')->assertJsonCount(0, 'conflicts');
        $this->assertSame('Wersja z sesji B.', $target->fresh()->content);
    }

    /**
     * The `amended_by` LIST, exercised with the two entries it was shaped to hold.
     *
     * The composer will not produce this state (see the first test in this file), so the second shadow
     * is written directly. That is the point: the payload key is a list precisely so the shape does not
     * have to change the day something else produces two, and a list nothing has ever put two items in
     * is a list that has never been rendered.
     */
    public function test_two_shadows_on_one_target_are_both_reported_in_the_amendment_annotation(): void
    {
        $source = 'Zwroty w 30 dni.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Pierwsza propozycja zmiany.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $first = $this->draftsOf($session)[0];

        $second = KnowledgeEntry::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'draft_session_id' => $session->id,
            'targets_entry_id' => $target->id,
            'target_revision_id' => $first->target_revision_id,
            'slug' => ShadowSlug::mint(),
            'title' => 'Zwroty',
            'content' => 'Druga propozycja zmiany.',
        ]);

        $relations = $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")->assertOk()->json('data');

        $node = collect($relations['nodes'])->firstWhere('id', (string) $target->id);

        $this->assertNotNull($node, 'the amended entry is still the node — neither shadow becomes one');
        $this->assertEqualsCanonicalizing(
            [(string) $first->id, (string) $second->id],
            array_column($node['amended_by'], 'draft_id'),
        );

        // Neither shadow is a node of its own, and no edge points at one.
        $nodeIds = array_column($relations['nodes'], 'id');
        $this->assertNotContains((string) $first->id, $nodeIds);
        $this->assertNotContains((string) $second->id, $nodeIds);

        foreach ($relations['edges'] as $edge) {
            $this->assertNotContains($edge['from'], [(string) $first->id, (string) $second->id]);
            $this->assertNotContains($edge['to'], [(string) $first->id, (string) $second->id]);
        }

        // Accepting one applies it; the OTHER is now stale and says so in the same batch shape.
        $result = $this->accept($session, [$first->id, $second->id])->assertOk();

        $this->assertCount(1, $result->json('accepted'));
        $this->assertCount(1, $result->json('conflicts'));
        $this->assertSame((string) $second->id, $result->json('conflicts.0.entry_id'));
        $this->assertSame('Pierwsza propozycja zmiany.', $target->fresh()->content);
    }

    // ---- a frozen revision that no longer exists ------------------------------------------------

    /**
     * THE PURGED-HISTORY FALLBACK. `target_revision_id` is a plain uuid, not a foreign key, so the row
     * it names can genuinely disappear — an erasure request deletes exactly the revisions that mention
     * a person, and one of them may be the version the composer read.
     *
     * The diff then falls back to the target's NEWEST revision rather than answering with nothing: the
     * reviewer still gets an honest "here is what is there now, here is what is proposed". The staleness
     * flag stays true, because a comparison against a different version is precisely the situation a
     * rebase exists for.
     */
    public function test_a_diff_survives_the_frozen_revision_being_purged_and_falls_back_to_the_newest(): void
    {
        $source = 'Zwroty w 30 dni.';
        $original = str_repeat('Polityka zwrotow. ', 20);
        $target = $this->retrievableEntry('Zwroty', 'zwroty', $original, $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Propozycja AI.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $shadow = $this->draftsOf($session)[0];
        $frozen = (string) $shadow->target_revision_id;

        // Another accepted amendment lands, so the frozen revision is no longer the newest...
        $this->updateEntry($target, content: 'Nowsza wersja targetu.');
        // ...and then an erasure request deletes the frozen one outright.
        KnowledgeEntryRevision::query()->whereKey($frozen)->delete();

        $this->assertNull(KnowledgeEntryRevision::query()->find($frozen));
        $this->assertSame($frozen, (string) $shadow->fresh()->target_revision_id, 'the shadow still names it');

        $diff = $this->getJson("/api/knowledge/entries/{$shadow->id}/draft-diff?baseline=target")->assertOk()->json();

        $this->assertSame('target', $diff['baseline']);
        $this->assertNotNull($diff['from'], 'the panel must still have something honest to show');
        $this->assertSame(
            (string) $target->fresh()->current_revision_id,
            (string) $diff['from']['revision_id'],
            'the fallback is the target\'s newest revision',
        );
        $this->assertStringContainsString('Nowsza wersja targetu.', $diff['from']['content']);
        $this->assertSame('Propozycja AI.', $diff['to']['content']);
        $this->assertTrue($diff['target_revision_stale']);

        // A rebase still resolves it, and the proposal then applies.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/rebase", ['entry_id' => (string) $shadow->id])
            ->assertOk()
            ->assertJsonPath('data.target_revision_stale', false);

        $this->accept($session, [$shadow->id])->assertOk()->assertJsonCount(0, 'conflicts');
        $this->assertSame('Propozycja AI.', $target->fresh()->content);
    }

    // ---- the reaper --------------------------------------------------------------------------

    /**
     * ABANDONING A SESSION THAT HOLDS A SHADOW MUST NOT TOUCH WHAT THE SHADOW POINTS AT.
     *
     * The reaper purges drafts through the entry service's cascade — the same path that destroys an
     * entry with its revisions, its chunks and its edges. A shadow is the one draft kind that holds a
     * reference to a LIVE entry, so this is the one place where "delete the draft" could plausibly be
     * read as "delete the thing it names". Losing an approved entry because nobody came back to a
     * drafting session would be the worst possible outcome of a housekeeping job.
     */
    public function test_reaping_an_abandoned_session_destroys_its_shadow_and_leaves_the_target_standing(): void
    {
        $source = 'Zwroty w 30 dni oraz nowy temat.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);
        $targetRevisions = $target->revisions()->count();

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Propozycja AI.', 'metadata' => []],
            ['action' => 'create', 'slug' => 'nowy', 'title' => 'Nowy', 'content' => 'Zupelnie nowa tresc.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $drafts = $this->draftsOf($session);
        $this->assertCount(2, $drafts);

        $draftIds = array_map(fn (KnowledgeEntry $d): string => (string) $d->id, $drafts);
        $this->assertGreaterThan(0, KnowledgeEntryRevision::query()->whereIn('knowledge_entry_id', $draftIds)->count());

        $days = max(1, (int) config('knowledge.drafting.abandon_after_days'));
        $session->forceFill(['updated_at' => now()->subDays($days + 1)])->saveQuietly();

        $this->artisan('knowledge:reap-draft-sessions')->assertSuccessful();

        // The session and BOTH drafts are gone, with their history.
        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
        $this->assertSame(0, KnowledgeEntry::query()->withDrafts()->withTrashed()->whereKey($draftIds)->count());
        $this->assertSame(0, KnowledgeEntryRevision::query()->whereIn('knowledge_entry_id', $draftIds)->count());

        // The TARGET is untouched — row, text, history and all.
        $fresh = $target->fresh();
        $this->assertNotNull($fresh, 'the amended entry must survive its proposal being reaped');
        $this->assertSame('zwroty', $fresh->slug);
        $this->assertStringContainsString('Polityka zwrotow.', (string) $fresh->content);
        $this->assertSame($targetRevisions, $fresh->revisions()->count());
        $this->assertGreaterThan(0, KnowledgeEntryChunk::query()->where('knowledge_entry_id', $fresh->id)->count());
    }

    // ---- the settle event --------------------------------------------------------------------

    /**
     * EVERY settle broadcasts, not only the first.
     *
     * The composer has no polling at all, so a refinement whose completion went unannounced would leave
     * the board spinning until the user reloaded — a failure mode that looks exactly like a hung worker.
     * The payload stays status-only on the second run too: the drafts are the user's material and the
     * channel fans out to every open composer in the workspace.
     */
    public function test_a_refinement_broadcasts_its_own_settle_with_the_same_status_only_payload(): void
    {
        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Pierwsza tresc.', 'metadata' => []],
        ]));

        $session = $this->start('Material zrodlowy o zwrotach.');

        Event::fake([KnowledgeDraftSessionUpdated::class]);

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'TRESC PO POPRAWCE, ktora nie moze trafic na kanal.', 'metadata' => []],
        ]));

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'krocej'])->assertOk();

        Event::assertDispatchedTimes(KnowledgeDraftSessionUpdated::class, 1);
        Event::assertDispatched(KnowledgeDraftSessionUpdated::class, function (KnowledgeDraftSessionUpdated $event) use ($session): bool {
            return $event->sessionId === (string) $session->id
                && $event->workspaceId === (string) $this->workspace->id
                && $event->status === 'ready'
                && $event->broadcastWith() === ['id' => (string) $session->id, 'status' => 'ready'];
        });

        // A failing refinement settles too, or the board would spin on the error case instead.
        Event::fake([KnowledgeDraftSessionUpdated::class]);
        $this->fakeComposer('nie-json');
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'jeszcze krocej'])->assertOk();

        Event::assertDispatched(
            KnowledgeDraftSessionUpdated::class,
            fn (KnowledgeDraftSessionUpdated $event): bool => $event->status === 'failed'
                && $event->broadcastWith() === ['id' => (string) $session->id, 'status' => 'failed'],
        );
    }

    /**
     * THE CHANNEL'S DOOR. The payload is harmless, but the channel still says who is composing in a
     * workspace and how often — and the authorization callback is the only thing standing in front of
     * it, since `/broadcasting/auth` is the one request that arrives WITHOUT the workspace header.
     *
     * Exercised against the real callback registered by routes/channels.php: the test broadcaster is
     * `null`, whose `auth()` is a no-op, so driving it through the HTTP route would assert nothing.
     */
    public function test_only_a_member_of_the_workspace_may_subscribe_to_its_composer_channel(): void
    {
        $authorize = (fn () => $this->channels)->call(Broadcast::driver())['knowledge.workspace.{workspaceId}'];

        $stranger = User::factory()->create();

        $this->assertTrue($authorize($this->user, (string) $this->workspace->id));
        $this->assertFalse($authorize($stranger, (string) $this->workspace->id), 'a non-member must be refused');
        $this->assertFalse($authorize($this->user, (string) Str::uuid()), 'an unknown workspace leaks nothing');
        $this->assertFalse($authorize(null, (string) $this->workspace->id), 'and an unauthenticated request is a clean denial');
    }
}
