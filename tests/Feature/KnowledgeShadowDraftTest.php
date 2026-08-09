<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeDraftSessionStatus;
use App\Modules\Knowledge\Events\KnowledgeDraftSessionUpdated;
use App\Modules\Knowledge\Jobs\GenerateKnowledgeDraftsJob;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Services\KnowledgeDraftService;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Knowledge\Support\ShadowSlug;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B11b — SHADOW DRAFTS: the composer proposing a CHANGE to an entry that already exists.
 *
 * The two rules everything else depends on, and the reason each is not merely nice to have:
 *
 *   THE ALLOW-LIST. A shadow may only target an entry the composer was actually SHOWN. An invented
 *   target is an overwrite of an entry nobody offered it, which is the single most damaging thing this
 *   feature could do — so it degrades to a new entry rather than being trusted.
 *
 *   THE FROZEN REVISION. The target's revision at retrieval time is replayed as the optimistic-lock
 *   token on acceptance, so a human who edited the target meanwhile gets a conflict instead of having
 *   their work silently overwritten by a model that never read it.
 */
class KnowledgeShadowDraftTest extends TestCase
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

        // THIS FILE TESTS THE RETRIEVAL BRANCH, so it says which branch it wants.
        //
        // `freezeContext()` chooses between retrieval and entity resolution on this flag, and writes
        // `retrieval_set => null` when resolution wins — so every assertion here depends on it. It was
        // never set, which meant this file passed or failed according to whatever was in the
        // developer's `.env`, and it duly went red the day somebody enabled extraction to try the graph
        // by hand. A suite whose result depends on an untracked file is not a suite.
        config()->set('knowledge.graph_extraction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------

    private function fakeComposer(string $reply): void
    {
        KnowledgeDraftAgent::fake(fn () => $reply);
    }

    private function reply(array $entries): string
    {
        return json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE);
    }

    /** A real, indexed entry whose passage is an exact vector match for the source text below. */
    private function retrievableEntry(string $title, string $slug, string $content, string $matches): KnowledgeEntry
    {
        $id = (string) $this->makeEntry(base: $this->base, title: $title, content: $content)->id;

        $entry = KnowledgeEntry::query()->findOrFail($id);
        $entry->forceFill(['slug' => $slug])->save();

        // Align its stored passage with the vector the fake embedder produces for the source material,
        // so retrieval finds it for real rather than by stubbing the seam.
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

    // ---- retrieval ---------------------------------------------------------------------

    public function test_the_session_freezes_the_entries_its_material_touches(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow towaru. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $set = $session->refresh()->retrievalSet();

        $this->assertCount(1, $set);
        $this->assertSame('zwroty', $set[0]['slug']);
        $this->assertSame($target->current_revision_id, $set[0]['current_revision_id']);
        $this->assertNotSame('', $set[0]['excerpt']);
    }

    /** Retrieval is a nicety; the composer must work without it. */
    public function test_a_session_still_runs_when_retrieval_is_unavailable(): void
    {
        config()->set('knowledge.index.enabled', false);

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'T.', 'metadata' => []],
        ]));

        // The kill switch stops the composer entirely, so prove the softer path: no indexed base at all.
        config()->set('knowledge.index.enabled', true);

        $session = $this->start('Material o czyms zupelnie nowym.');

        $this->assertSame([], $session->refresh()->retrievalSet());
        $this->assertCount(1, $this->draftsOf($session));
    }

    // ---- the amendment ---------------------------------------------------------------------

    public function test_the_composer_can_propose_an_amendment_to_a_retrieved_entry(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow towaru. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Zwroty w 30 dni.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $drafts = $this->draftsOf($session);

        $this->assertCount(1, $drafts);
        $this->assertTrue($drafts[0]->isShadow());
        $this->assertSame((string) $target->id, (string) $drafts[0]->targets_entry_id);
        $this->assertSame($target->current_revision_id, $drafts[0]->target_revision_id);
        // A reserved, synthetic address — never the target's.
        $this->assertTrue(ShadowSlug::is($drafts[0]->slug));
        $this->assertNotSame('zwroty', $drafts[0]->slug);
    }

    /**
     * THE hard gate. A target the composer was never shown is an invented address, and honouring it
     * would overwrite an entry nobody offered — so the proposal DEGRADES to a new entry rather than
     * being trusted or silently dropped.
     */
    public function test_an_amendment_naming_an_unretrieved_entry_degrades_to_a_new_entry(): void
    {
        $victim = $this->retrievableEntry('Sekret', 'sekret', str_repeat('Tajna tresc ktora nie moze zostac nadpisana. ', 10), 'zupelnie inny temat');

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'sekret', 'title' => 'Nowy wpis', 'content' => 'Podmieniona tresc.', 'metadata' => []],
        ]));

        $session = $this->start('Material o kotach i psach.');
        $drafts = $this->draftsOf($session);

        $this->assertCount(1, $drafts);
        $this->assertFalse($drafts[0]->isShadow(), 'an unretrieved target must not become an amendment');
        $this->assertNull($drafts[0]->targets_entry_id);
        $this->assertSame('nowy-wpis', $drafts[0]->slug);

        // ...and the entry it tried to name is untouched.
        $this->assertStringContainsString('Tajna tresc', (string) $victim->fresh()->content);
    }

    public function test_shadow_proposals_are_capped_per_session(): void
    {
        config()->set('knowledge.drafting.max_shadow_per_session', 1);

        $source = 'Aktualizacja polityki zwrotow i reklamacji.';
        $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);
        $this->retrievableEntry('Reklamacje', 'reklamacje', str_repeat('Polityka reklamacji. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Nowe.', 'metadata' => []],
            ['action' => 'update', 'targets_slug' => 'reklamacje', 'title' => 'Reklamacje', 'content' => 'Nowe.', 'metadata' => []],
        ]));

        $session = $this->start($source);

        $shadows = array_filter($this->draftsOf($session), fn (KnowledgeEntry $d): bool => $d->isShadow());
        $this->assertCount(1, $shadows);
    }

    // ---- invisibility -----------------------------------------------------------------------

    /** A shadow's reserved slug must never leak, and must never steal the target's address. */
    public function test_a_shadow_is_invisible_and_never_occupies_the_targets_slug(): void
    {
        $source = 'Zwroty w 30 dni.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Nowe.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $shadow = $this->draftsOf($session)[0];

        // Invisible to the list, to search, and to a plain model query.
        $listed = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk()->json('data');
        $this->assertSame([(string) $target->id], array_column($listed, 'id'));
        $this->assertNull(KnowledgeEntry::query()->find($shadow->id));

        // The target keeps its own address.
        $this->assertSame('zwroty', $target->fresh()->slug);
    }

    /**
     * A draft must not ADOPT a ghost. Adoption writes into another entry's row, so a real entry's red
     * `[[…]]` would silently resolve to something the reader cannot open.
     */
    public function test_a_draft_never_adopts_a_ghost_link_or_draws_edges(): void
    {
        $writer = $this->retrievableEntry('Pisarz', 'pisarz', 'Zobacz [[cennik]] po szczegoly. ' . str_repeat('Tresc. ', 30), 'nic');

        $ghost = KnowledgeLink::query()->where('from_entry_id', $writer->id)->firstOrFail();
        $this->assertNull($ghost->to_entry_id, 'the fixture must start as a ghost');

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'cennik', 'title' => 'Cennik', 'content' => 'Ceny. [[pisarz]]', 'metadata' => []],
        ]));

        $session = $this->start('Material o cenniku.');
        $draft = $this->draftsOf($session)[0];

        $this->assertSame('cennik', $draft->slug);
        $this->assertNull($ghost->fresh()->to_entry_id, 'a ghost must not resolve to an invisible draft');
        $this->assertSame(0, KnowledgeLink::query()->where('from_entry_id', $draft->id)->count());

        // ...and accepting it wires everything up at once.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $draft->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame((string) $draft->id, (string) $ghost->fresh()->to_entry_id);
        $this->assertSame(1, KnowledgeLink::query()->where('from_entry_id', $draft->id)->count());
    }

    // ---- acceptance --------------------------------------------------------------------------

    public function test_accepting_an_amendment_edits_the_target_and_destroys_the_shadow(): void
    {
        $source = 'Zwroty w 30 dni.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);
        $revisionsBefore = $target->revisions()->count();

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Zwroty przyjmujemy w 30 dni.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $shadow = $this->draftsOf($session)[0];

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $shadow->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame([], $response->json('conflicts'));
        $this->assertCount(1, $response->json('accepted'));

        $target->refresh();
        $this->assertSame('Zwroty przyjmujemy w 30 dni.', $target->content);
        $this->assertSame($revisionsBefore + 1, $target->revisions()->count(), 'an amendment is a normal edit');

        // The proposal is gone — no orphan pointing at a revision that is no longer current.
        $this->assertNull(KnowledgeEntry::query()->withDrafts()->withTrashed()->find($shadow->id));
    }

    /**
     * THE conflict case, and why the revision is frozen at all: a human edited the target after the
     * composer read it, so applying the proposal would overwrite work the model never saw.
     */
    public function test_a_human_edit_after_retrieval_produces_a_conflict_not_an_overwrite(): void
    {
        $source = 'Zwroty w 30 dni.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Wersja od AI.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $shadow = $this->draftsOf($session)[0];

        // The target moves underneath the proposal. It used to be a person saving it; with hand-editing
        // withdrawn it is another accepted amendment — the same write, through the same service, and the
        // reason the conflict check cannot be dropped along with the editor.
        $this->updateEntry($target, content: 'Wersja z innej sesji.');

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $shadow->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame([], $response->json('accepted'));
        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame((string) $shadow->id, $response->json('conflicts.0.entry_id'));
        $this->assertSame((string) $target->id, $response->json('conflicts.0.targets_entry_id'));
        $this->assertNotNull($response->json('conflicts.0.current_revision_id'));

        // The earlier write stands, and the proposal is still on the table.
        $this->assertSame('Wersja z innej sesji.', $target->fresh()->content);
        $this->assertNotNull($shadow->fresh());
    }

    /** A conflict must not take the rest of the batch with it. */
    public function test_a_conflicting_amendment_does_not_block_the_other_accepted_drafts(): void
    {
        $source = 'Zwroty w 30 dni oraz nowy temat.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Wersja od AI.', 'metadata' => []],
            ['action' => 'create', 'slug' => 'nowy', 'title' => 'Nowy', 'content' => 'Zupelnie nowa tresc.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $drafts = $this->draftsOf($session);

        $this->updateEntry($target, content: 'Ktos byl pierwszy.');

        $response = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => array_map(fn (KnowledgeEntry $d): string => (string) $d->id, $drafts),
            'status' => 'approved',
        ])->assertOk();

        $this->assertCount(1, $response->json('accepted'));
        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame('Nowy', $response->json('accepted.0.title'));
    }

    // ---- rebase ------------------------------------------------------------------------------

    public function test_rebasing_points_the_shadow_at_the_current_revision_without_touching_its_text(): void
    {
        $source = 'Zwroty w 30 dni.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Wersja od AI.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $shadow = $this->draftsOf($session)[0];

        $this->updateEntry($target, content: 'Nowsza wersja targetu.');

        $this->assertTrue($shadow->fresh()->targetRevisionIsStale());

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/rebase", ['entry_id' => (string) $shadow->id])
            ->assertOk()
            ->assertJsonPath('data.target_revision_stale', false);

        $shadow->refresh();
        $this->assertSame($target->fresh()->current_revision_id, $shadow->target_revision_id);
        $this->assertSame('Wersja od AI.', $shadow->content, 'rebasing must not touch the proposal itself');

        // ...and now it applies.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $shadow->id],
            'status' => 'approved',
        ])->assertOk()->assertJsonCount(0, 'conflicts');
    }

    // ---- the composer writes links and aliases -------------------------------------------------
    //
    // The research settled the WP:CONTEXTBOT objection: the ban on auto-linking is about mutating
    // SOMEBODY ELSE'S text after the fact. An agent AUTHORING a new entry writes links the way a human
    // author does — and the draft-plus-diff gate means a person still approves every one.

    /**
     * A draft that names a co-draft AND an existing entry links to both — with the co-draft link
     * surviving slug de-collision, and the existing-entry link left alone by it.
     */
    public function test_the_composer_links_co_drafts_and_existing_entries(): void
    {
        $existing = $this->retrievableEntry('Paryz', 'paryz', str_repeat('Stolica Francji. ', 20), 'nic');

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'wieza-eiffla', 'title' => 'Wieża Eiffla', 'content' => 'Zelazna wieza.', 'metadata' => []],
            [
                'action' => 'create',
                'slug' => 'zwiedzanie',
                'title' => 'Zwiedzanie',
                'content' => 'Najpierw [[wieza-eiffla]], potem reszta [[paryz|Paryża]].',
                'metadata' => [],
            ],
        ]));

        $session = $this->start('Material o Paryzu.');
        $drafts = $this->draftsOf($session);

        $body = (string) collect($drafts)->firstWhere('slug', 'zwiedzanie')->content;

        $this->assertStringContainsString('[[wieza-eiffla]]', $body, 'the co-draft link survives');
        $this->assertStringContainsString('[[paryz|Paryża]]', $body, 'the existing-entry link and its label survive');
    }

    /**
     * A co-draft renamed by de-collision takes every link to it along.
     *
     * THE COLLISION IS NOW MADE BY THE TITLE. The slug is derived from it and a model-supplied slug is
     * no longer taken as given — that is what produced a base addressed `tajlandii` and `warszawie` —
     * so the fixture collides the only way a collision can still occur: two entries genuinely called
     * the same thing.
     */
    public function test_de_collision_rewrites_links_to_a_renamed_co_draft(): void
    {
        // An entry already owns `paryz`, so the draft whose title slugifies to it is pushed aside.
        $this->retrievableEntry('Paryz', 'paryz', str_repeat('Stolica Francji. ', 20), 'nic');

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'paryz', 'title' => 'Paryz', 'content' => 'Nowa tresc.', 'metadata' => []],
            ['action' => 'create', 'slug' => 'plan', 'title' => 'Plan', 'content' => 'Zobacz [[paryz]].', 'metadata' => []],
        ]));

        $session = $this->start('Material.');
        $drafts = $this->draftsOf($session);

        $this->assertNotNull(
            collect($drafts)->firstWhere('slug', 'paryz-2'),
            'the live entry keeps the address and the draft was pushed aside',
        );

        $this->assertStringContainsString(
            '[[paryz-2]]',
            (string) collect($drafts)->firstWhere('slug', 'plan')->content,
            'the link followed the rename rather than pointing at the live entry',
        );
    }

    /** Aliases come back from the composer, capped and de-duplicated. */
    public function test_composer_aliases_are_stored_and_capped(): void
    {
        $many = [];

        for ($i = 1; $i <= 15; $i++) {
            $many[] = 'forma numer ' . $i;
        }

        $this->fakeComposer($this->reply([
            [
                'action' => 'create',
                'slug' => 'wieza-eiffla',
                'title' => 'Wieża Eiffla',
                'content' => 'Zelazna wieza.',
                'aliases' => $many,
                'metadata' => [],
            ],
        ]));

        $session = $this->start('Material.');
        $draft = $this->draftsOf($session)[0];

        $this->assertCount(10, $draft->aliases, 'fifteen aliases are truncated, not refused');
        $this->assertSame('forma numer 1', $draft->aliases[0]);
    }

    /** A hostile alias loses ITSELF, not the entry that carried it. */
    public function test_an_alias_with_template_syntax_is_dropped_without_losing_the_draft(): void
    {
        $this->fakeComposer($this->reply([
            [
                'action' => 'create',
                'slug' => 'wieza',
                'title' => 'Wieża',
                'content' => 'Zelazna wieza.',
                'aliases' => ['wieży', '{{ workspace.secret }}', 'wieżą'],
                'metadata' => [],
            ],
        ]));

        $session = $this->start('Material.');
        $draft = $this->draftsOf($session)[0];

        $this->assertSame(['wieży', 'wieżą'], $draft->aliases);
    }

    /** ...and the stored aliases actually make the mention layer see the entry. */
    public function test_an_accepted_entrys_aliases_are_matched_by_the_mention_layer(): void
    {
        $this->fakeComposer($this->reply([
            [
                'action' => 'create',
                'slug' => 'wieza-eiffla',
                'title' => 'Wieża Eiffla',
                'content' => 'Zelazna wieza w Paryzu.',
                'aliases' => ['Eiffel Tower'],
                'metadata' => [],
            ],
        ]));

        $session = $this->start('Material o wiezy.');

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $this->draftsOf($session)[0]->id],
            'status' => 'approved',
        ])->assertOk();

        // A note using ONLY the alias — the title's stem does not appear in it at all.
        $this->makeEntry($this->base, 'Notatka z podrozy', 'Widzielismy Eiffel Tower o zachodzie slonca.');

        $mention = KnowledgeLink::query()
            ->ofSource(\App\Modules\Knowledge\Enums\KnowledgeLinkSource::MENTION)
            ->whereHas('toEntry', fn ($query) => $query->where('slug', 'wieza-eiffla'))
            ->first();

        $this->assertNotNull($mention, 'the alias must be recognised in prose the title never appears in');
    }

    // ---- expanding context is once per round ------------------------------------------------------
    //
    // `expand-context` spends an embedding, and the "already done" state used to live only in the
    // browser that pressed the button — so a reload or a second reviewer was offered the paid action
    // again, and taking it bought the identical retrieval set.

    public function test_expanding_context_stamps_the_session(): void
    {
        $source = 'Zwroty w 30 dni.';
        $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $this->assertNull($session->refresh()->context_expanded_at);

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/expand-context")
            ->assertOk()
            ->assertJsonPath('data.context_expanded_at', fn ($value): bool => $value !== null);

        $this->assertNotNull($session->refresh()->context_expanded_at);
    }

    /** A SECOND expansion against unchanged inputs is refused server-side, not merely disabled in a tab. */
    public function test_a_second_expansion_is_refused_until_a_revision_consumes_it(): void
    {
        $source = 'Zwroty w 30 dni.';
        $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start($source);

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/expand-context")->assertOk();

        $this->embedder->reset();

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/expand-context")
            ->assertStatus(422)
            ->assertJsonPath('code', 'knowledge_context_already_expanded');

        $this->assertSame(0, $this->embedder->calls, 'a refused expansion must not reach the provider');
    }

    /** ...and a refinement is the way forward: it consumes the widened context and re-opens the action. */
    public function test_a_refinement_clears_the_expansion_flag(): void
    {
        $source = 'Zwroty w 30 dni.';
        $this->retrievableEntry('Zwroty', 'zwroty', str_repeat('Polityka zwrotow. ', 20), $source);

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/expand-context")->assertOk();

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Poprawione.', 'metadata' => []],
        ]));

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'Skroc.'])
            ->assertOk()
            ->assertJsonPath('data.context_expanded_at', null);

        $this->assertNull($session->refresh()->context_expanded_at);

        // ...and expanding is available again.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/expand-context")->assertOk();
    }

    // ---- the settle event -----------------------------------------------------------------------

    /** ZERO polling: the browser is told, on a workspace-private channel, with no content in the payload. */
    public function test_a_settled_session_broadcasts_status_only(): void
    {
        Event::fake([KnowledgeDraftSessionUpdated::class]);

        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Tresc ktora nie moze trafic na kanal.', 'metadata' => []],
        ]));

        $session = $this->start('Material.');

        Event::assertDispatched(KnowledgeDraftSessionUpdated::class, function (KnowledgeDraftSessionUpdated $event) use ($session): bool {
            $payload = $event->broadcastWith();

            return $event->sessionId === (string) $session->id
                && $event->workspaceId === (string) $this->workspace->id
                && $payload === ['id' => (string) $session->id, 'status' => 'ready'];
        });
    }

    /**
     * THE JOB'S OWN settle paths broadcast too — the invariant is "a transition is never persisted
     * without the push", and it has to hold on every path or it is just a habit of one of them.
     *
     * The kill switch flipped AFTER the job was queued: the run stands down, but the session was
     * already claimed, so something has to release it and say so.
     */
    public function test_the_kill_switch_release_broadcasts_the_failure(): void
    {
        Event::fake([KnowledgeDraftSessionUpdated::class]);

        $session = KnowledgeDraftSession::factory()->generating()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
        ]);

        config()->set('knowledge.index.enabled', false);

        (new GenerateKnowledgeDraftsJob((string) $session->id, (string) $this->workspace->id))
            ->handle(app(KnowledgeDraftService::class));

        $this->assertSame(KnowledgeDraftSessionStatus::FAILED, $session->refresh()->status);

        Event::assertDispatched(
            KnowledgeDraftSessionUpdated::class,
            fn (KnowledgeDraftSessionUpdated $event): bool => $event->sessionId === (string) $session->id
                && $event->status === 'failed',
        );
    }

    /**
     * ...and the failed() hook — the path a job TIMEOUT takes. Without the push the composer waits out
     * the whole 300-second job timeout plus the run window on a failure the server already knows about.
     */
    public function test_the_failed_hook_broadcasts_the_failure(): void
    {
        Event::fake([KnowledgeDraftSessionUpdated::class]);

        $session = KnowledgeDraftSession::factory()->generating()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
        ]);

        (new GenerateKnowledgeDraftsJob((string) $session->id, (string) $this->workspace->id))
            ->failed(new \RuntimeException('worker died'));

        $session->refresh();
        $this->assertSame(KnowledgeDraftSessionStatus::FAILED, $session->status);
        $this->assertNull($session->claimed_at, 'the claim is released with the status');

        Event::assertDispatched(
            KnowledgeDraftSessionUpdated::class,
            fn (KnowledgeDraftSessionUpdated $event): bool => $event->sessionId === (string) $session->id
                && $event->status === 'failed',
        );
    }

    /** A settled session is never re-settled: a redelivery must not overwrite a real outcome. */
    public function test_an_already_settled_session_is_not_broadcast_again(): void
    {
        $this->fakeComposer($this->reply([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'T.', 'metadata' => []],
        ]));

        $session = $this->start('Material.');
        $this->assertSame(KnowledgeDraftSessionStatus::READY, $session->refresh()->status);

        Event::fake([KnowledgeDraftSessionUpdated::class]);

        (new GenerateKnowledgeDraftsJob((string) $session->id, (string) $this->workspace->id))
            ->failed(new \RuntimeException('redelivered after the run already finished'));

        $this->assertSame(KnowledgeDraftSessionStatus::READY, $session->refresh()->status);
        Event::assertNotDispatched(KnowledgeDraftSessionUpdated::class);
    }

    public function test_a_failed_session_also_broadcasts(): void
    {
        Event::fake([KnowledgeDraftSessionUpdated::class]);

        $this->fakeComposer('nie-json');
        $this->start('Material.');

        Event::assertDispatched(
            KnowledgeDraftSessionUpdated::class,
            fn (KnowledgeDraftSessionUpdated $event): bool => $event->status === 'failed',
        );
    }

    // ---- the invariant ----------------------------------------------------------------------------

    /** A shadow is ALWAYS a draft — enforced by the database, not only by the service. */
    public function test_the_database_refuses_a_shadow_that_is_not_a_draft(): void
    {
        $target = KnowledgeEntry::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        KnowledgeEntry::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'targets_entry_id' => $target->id,
            'draft_session_id' => null,
        ]);
    }

    // ---- the diff -----------------------------------------------------------------------------------

    public function test_the_diff_compares_a_shadow_against_the_revision_the_composer_read(): void
    {
        $source = 'Zwroty w 30 dni.';
        $original = str_repeat('Polityka zwrotow. ', 20);
        $target = $this->retrievableEntry('Zwroty', 'zwroty', $original, $source);

        $this->fakeComposer($this->reply([
            ['action' => 'update', 'targets_slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Zwroty w 30 dni.', 'metadata' => []],
        ]));

        $session = $this->start($source);
        $shadow = $this->draftsOf($session)[0];

        $diff = $this->getJson("/api/knowledge/entries/{$shadow->id}/draft-diff?baseline=target")
            ->assertOk()
            ->json();

        $this->assertSame('target', $diff['baseline']);
        $this->assertFalse($diff['target_revision_stale']);
        $this->assertStringContainsString('Polityka zwrotow', $diff['from']['content']);
        $this->assertSame('Zwroty w 30 dni.', $diff['to']['content']);
    }
}
