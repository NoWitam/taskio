<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Bot\Services\BotKnowledgeReader;
use App\Modules\Bot\Services\BotTaskContextBuilder;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Knowledge\Support\KnowledgeFence;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * B6 — the FIRST AI consumer of the knowledge base: a bot reads one.
 *
 * The single most important assertion in this file is the BYTE FREEZE of the un-bound path. Every bot in
 * every workspace already has a `knowledge` JSON module, and this batch must not change what any of them
 * reads until a human deliberately binds a base. "It looks the same" is not a claim a reviewer can check
 * on a prompt; a frozen fixture is.
 */
class BotKnowledgeTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

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
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------------------------

    private function bot(array $attributes = []): Bot
    {
        return Bot::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'creator_id' => $this->user->id,
        ], $attributes));
    }

    private function task(Bot $bot, array $attributes = []): Task
    {
        return Task::factory()->assignedToBot($bot)->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'creator_id' => $this->user->id,
            'title' => 'Odpowiedz klientowi',
            'description' => 'Klient pyta o zwrot.',
        ], $attributes));
    }

    private function base(array $attributes = []): KnowledgeBase
    {
        return KnowledgeBase::factory()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'name' => 'Baza obslugi',
            'charter' => null,
        ], $attributes));
    }

    /** An entry written WITHOUT the auto-indexer, so tests that need passages control them exactly. */
    private function entry(KnowledgeBase $base, string $title, string $content, int $position = 0): KnowledgeEntry
    {
        $enabled = config('knowledge.index.enabled');
        config()->set('knowledge.index.enabled', false);

        try {
            return KnowledgeEntry::factory()->create([
                'workspace_id' => $this->workspace->id,
                'knowledge_base_id' => $base->id,
                'title' => $title,
                'content' => $content,
                'status' => KnowledgeEntryStatus::APPROVED,
                'position' => $position,
            ]);
        } finally {
            config()->set('knowledge.index.enabled', $enabled);
        }
    }

    private function bind(Bot $bot, KnowledgeBase $base, KnowledgeBindingMode $mode): KnowledgeBinding
    {
        return KnowledgeBinding::factory()->create([
            'workspace_id' => $this->workspace->id,
            'bindable_type' => BotKnowledgeReader::BINDABLE_TYPE,
            'bindable_id' => $bot->id,
            'knowledge_base_id' => $base->id,
            'mode' => $mode,
        ]);
    }

    private function context(Bot $bot, Task $task): string
    {
        return app(BotTaskContextBuilder::class)->build(
            $task,
            $bot,
            app(BotKnowledgeReader::class)->read($bot, $task),
        );
    }

    // ---- the byte freeze ---------------------------------------------------------------

    /**
     * A bot NOBODY has migrated must build exactly the context it built before B6 existed — same header,
     * same bullet shape, same section order, byte for byte. This fixture is the contract; if it fails, the
     * batch changed the behaviour of every existing bot and must be fixed rather than re-baselined.
     */
    public function test_a_bot_with_no_binding_builds_the_legacy_context_byte_for_byte(): void
    {
        $bot = $this->bot([
            'knowledge' => [
                'enabled' => true,
                'entries' => [
                    ['title' => 'Zwroty', 'content' => 'Przyjmujemy w 14 dni.'],
                    ['title' => 'Reklamacje', 'content' => 'Rozpatrujemy w 30 dni.'],
                ],
            ],
        ]);

        $task = $this->task($bot);

        // Everything is a literal EXCEPT the description, which is a rich-text tree whose serialization
        // this batch does not touch — pinning its JSON here would make an unrelated editor change look
        // like a B6 regression. The knowledge section's bytes, the section ORDER and the blank-line
        // separators — the three things B6 could plausibly have changed — are all frozen.
        $this->assertSame(
            "WIEDZA BOTA:\n"
            . "- Zwroty: Przyjmujemy w 14 dni.\n"
            . "- Reklamacje: Rozpatrujemy w 30 dni.\n\n"
            . "ZADANIE:\n"
            . "Tytuł: Odpowiedz klientowi\n"
            . 'Opis: ' . $task->description . "\n\n"
            . "FORMULARZ: brak.\n\n"
            . "KOMENTARZE (rozmowa): brak.\n\n"
            . 'HISTORIA ZATWIERDZANIA: brak.',
            $this->context($bot, $task),
        );
    }

    /** The other half of the freeze: an un-bound bot never reaches the Knowledge module at all. */
    public function test_an_unbound_bot_makes_no_knowledge_read(): void
    {
        $bot = $this->bot();
        $task = $this->task($bot);

        $this->assertNull(app(BotKnowledgeReader::class)->read($bot, $task));
        $this->assertSame(0, $this->embedder->calls);
    }

    /** A disabled built-in module still omits the section entirely — unchanged. */
    public function test_a_bot_with_a_disabled_module_and_no_binding_omits_the_section(): void
    {
        $bot = $this->bot([
            'knowledge' => ['enabled' => false, 'entries' => [['title' => 'Zwroty', 'content' => 'Nieaktywne.']]],
        ]);

        $this->assertStringNotContainsString('WIEDZA BOTA', $this->context($bot, $this->task($bot)));
    }

    // ---- a bound bot ---------------------------------------------------------------------

    /**
     * A binding takes precedence: the fenced base replaces the built-in module, which is KEPT but not
     * injected (so un-binding restores the old behaviour exactly).
     */
    public function test_an_inline_binding_replaces_the_builtin_module_with_a_fenced_block(): void
    {
        $bot = $this->bot([
            'knowledge' => ['enabled' => true, 'entries' => [['title' => 'Stare', 'content' => 'Stara tresc.']]],
        ]);

        $base = $this->base(['charter' => 'Wszystko o zwrotach.']);
        $this->entry($base, 'Zwroty', 'Przyjmujemy w 14 dni.');
        $this->bind($bot, $base, KnowledgeBindingMode::INLINE);

        $context = $this->context($bot, $this->task($bot));
        $fence = KnowledgeFence::block();

        $this->assertStringContainsString(KnowledgeFence::LABEL, $context);
        $this->assertStringContainsString($fence->open(), $context);
        $this->assertStringContainsString('## Zwroty', $context);
        $this->assertStringContainsString('CHARTER: Wszystko o zwrotach.', $context);

        // The built-in module is no longer injected — but it is still stored.
        $this->assertStringNotContainsString('WIEDZA BOTA', $context);
        $this->assertStringNotContainsString('Stara tresc.', $context);
        $this->assertSame('Stara tresc.', $bot->fresh()->knowledgeEntries()[0]['content']);

        // The task's own sections still follow the knowledge block.
        $this->assertStringContainsString('ZADANIE:', $context);
    }

    /** A `rag` binding injects PASSAGES, each carrying its citation address. */
    public function test_a_rag_binding_injects_passages_with_citations(): void
    {
        $bot = $this->bot();
        $base = $this->base();
        $entry = $this->entry($base, 'Zwroty', 'Pelna tresc wpisu.');

        $task = $this->task($bot, ['title' => 'Polityka zwrotow']);

        $chunk = KnowledgeEntryChunk::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_entry_id' => $entry->id,
            'knowledge_base_id' => $base->id,
            'ordinal' => 0,
            'content' => 'Zwroty przyjmujemy w 14 dni od zakupu.',
        ]);

        // Align the passage with the query the reader will compose for this task.
        ChunkVector::write(
            (string) $chunk->id,
            FakeKnowledgeEmbedder::vectorFor(
                app(BotKnowledgeReader::class)->query($task),
                (int) config('knowledge.embedding.dimensions'),
            ),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        $this->bind($bot, $base, KnowledgeBindingMode::RAG);

        $context = $this->context($bot, $task);

        $this->assertStringContainsString('Zwroty przyjmujemy w 14 dni od zakupu.', $context);
        $this->assertStringContainsString('[' . $entry->id . '#0]', $context);
        $this->assertSame(1, $this->embedder->calls);
    }

    /** The retrieval query is composed on the BOT side, from the task the bot is working on. */
    public function test_the_retrieval_query_is_composed_from_the_task(): void
    {
        $bot = $this->bot();
        $task = $this->task($bot, ['title' => 'Zwrot butow', 'description' => 'Klient kupil w marcu.']);

        $query = app(BotKnowledgeReader::class)->query($task);

        $this->assertStringContainsString('Zwrot butow', $query);
        $this->assertStringContainsString('Klient kupil w marcu.', $query);
    }

    // ---- audit ---------------------------------------------------------------------------

    /**
     * The run records WHAT the bot saw. A bot reads its base live, so without the receipt an answer given
     * today cannot be explained after the base changes.
     */
    public function test_a_run_records_the_knowledge_it_read(): void
    {
        $bot = $this->bot(['status' => 'active', 'task_execution' => ['enabled' => true, 'tools' => []]]);
        $base = $this->base();
        $entry = $this->entry($base, 'Zwroty', 'Przyjmujemy w 14 dni.');
        $this->bind($bot, $base, KnowledgeBindingMode::INLINE);

        $this->scriptBotRun([['post_comment', ['text' => 'Sprawdzam.']], ['finish']]);

        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Zwrot butow',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ])->assertCreated()->json('data.id');

        $action = BotAction::query()
            ->where('task_id', $taskId)
            ->where('type', BotActionType::KnowledgeRead)
            ->first();

        $this->assertNotNull($action, 'the run must record which knowledge it read');
        $this->assertSame((string) $base->id, $action->payload['knowledge_base_id']);
        $this->assertSame('inline', $action->payload['mode']);
        $this->assertSame([(string) $entry->id], $action->payload['entry_ids']);
        $this->assertArrayHasKey('revision_ids', $action->payload);
    }

    /** No binding, no audit row — the un-bound path is untouched down to the log. */
    public function test_an_unbound_run_records_no_knowledge_read(): void
    {
        $bot = $this->bot([
            'status' => 'active',
            'task_execution' => ['enabled' => true, 'tools' => []],
            'knowledge' => ['enabled' => true, 'entries' => [['title' => 'Zwroty', 'content' => 'W 14 dni.']]],
        ]);

        $this->scriptBotRun([['finish']]);

        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Zwrot butow',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseMissing('bot_actions', [
            'task_id' => $taskId,
            'type' => BotActionType::KnowledgeRead->value,
        ]);
    }

    // ---- the HTTP surface -------------------------------------------------------------------

    public function test_binding_a_base_is_an_upsert(): void
    {
        $bot = $this->bot();
        $first = $this->base();
        $second = $this->base(['name' => 'Druga baza']);

        $this->putJson("/api/bots/{$bot->id}/knowledge-binding", [
            'knowledge_base_id' => $first->id,
            'mode' => 'inline',
        ])->assertOk()->assertJsonPath('data.knowledge_binding.knowledge_base_id', (string) $first->id)
            ->assertJsonPath('data.knowledge_binding.mode', 'inline');

        $this->putJson("/api/bots/{$bot->id}/knowledge-binding", [
            'knowledge_base_id' => $second->id,
            'mode' => 'rag',
        ])->assertOk()->assertJsonPath('data.knowledge_binding.knowledge_base_id', (string) $second->id)
            ->assertJsonPath('data.knowledge_binding.mode', 'rag');

        // One base per consumer: the second bind REPLACED the first, it did not add to it.
        $this->assertSame(1, KnowledgeBinding::query()->where('bindable_id', $bot->id)->count());
    }

    public function test_unbinding_returns_the_bot_without_a_binding(): void
    {
        $bot = $this->bot();
        $base = $this->base();
        $this->bind($bot, $base, KnowledgeBindingMode::AUTO);

        $this->deleteJson("/api/bots/{$bot->id}/knowledge-binding")
            ->assertOk()
            ->assertJsonPath('data.knowledge_binding', null);

        $this->assertSame(0, KnowledgeBinding::query()->where('bindable_id', $bot->id)->count());
    }

    public function test_unbinding_a_bot_that_reads_nothing_is_a_no_op(): void
    {
        $bot = $this->bot();

        $this->deleteJson("/api/bots/{$bot->id}/knowledge-binding")->assertOk();
    }

    public function test_the_bot_resource_reports_no_binding_by_default(): void
    {
        $bot = $this->bot();

        $this->getJson("/api/bots/{$bot->id}")
            ->assertOk()
            ->assertJsonPath('data.knowledge_binding', null);
    }

    /** Another workspace's base is not found — the tenancy boundary, decided in the Knowledge module. */
    public function test_a_base_from_another_workspace_cannot_be_bound(): void
    {
        $bot = $this->bot();

        $otherOwner = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreign = KnowledgeBase::factory()->create(['workspace_id' => $other->id]);

        $this->putJson("/api/bots/{$bot->id}/knowledge-binding", [
            'knowledge_base_id' => $foreign->id,
            'mode' => 'auto',
        ])->assertNotFound();

        $this->assertSame(0, KnowledgeBinding::query()->where('bindable_id', $bot->id)->count());
    }

    /** Choosing what a bot reads is an edit OF THE BOT: a member who cannot edit it cannot rewire it. */
    public function test_a_non_owner_cannot_bind_a_base(): void
    {
        $bot = $this->bot(['creator_id' => User::factory()->create()->id]);
        $base = $this->base();

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->putJson("/api/bots/{$bot->id}/knowledge-binding", [
                'knowledge_base_id' => $base->id,
                'mode' => 'auto',
            ])->assertForbidden();
    }

    public function test_the_mode_must_be_one_of_the_known_modes(): void
    {
        $bot = $this->bot();
        $base = $this->base();

        $this->putJson("/api/bots/{$bot->id}/knowledge-binding", [
            'knowledge_base_id' => $base->id,
            'mode' => 'telepathy',
        ])->assertJsonValidationErrors('mode');
    }

    // ---- migration ---------------------------------------------------------------------------

    public function test_migrating_creates_an_approved_base_and_binds_it(): void
    {
        $bot = $this->bot([
            'name' => 'Asystent',
            'knowledge' => [
                'enabled' => true,
                'entries' => [
                    ['title' => 'Zwroty', 'content' => 'Przyjmujemy w 14 dni.'],
                    ['title' => 'Reklamacje', 'content' => 'Rozpatrujemy w 30 dni.'],
                ],
            ],
        ]);

        $response = $this->postJson("/api/bots/{$bot->id}/knowledge/migrate")
            ->assertCreated()
            ->assertJsonPath('entries_count', 2)
            ->assertJsonPath('mode', 'auto');

        $baseId = $response->json('knowledge_base_id');

        $entries = KnowledgeEntry::query()->where('knowledge_base_id', $baseId)->orderBy('position')->get();
        $this->assertSame(['Zwroty', 'Reklamacje'], $entries->pluck('title')->all());
        // Approved, because they were ALREADY being injected verbatim — calling them drafts would make
        // the migrated bot read nothing.
        $this->assertTrue($entries->every(fn ($entry) => $entry->status === KnowledgeEntryStatus::APPROVED));
        $this->assertSame($this->user->id, $entries->first()->creator_id);

        $binding = KnowledgeBinding::query()->where('bindable_id', $bot->id)->firstOrFail();
        $this->assertSame($baseId, (string) $binding->knowledge_base_id);
        $this->assertSame(KnowledgeBindingMode::AUTO, $binding->mode);

        // ADDITIVE: the bot's own column is untouched, so un-binding restores the old behaviour whole.
        $this->assertCount(2, $bot->fresh()->knowledgeEntries());
    }

    public function test_migrating_a_bot_with_no_builtin_entries_is_refused(): void
    {
        $bot = $this->bot();

        $this->postJson("/api/bots/{$bot->id}/knowledge/migrate")
            ->assertJsonValidationErrors('knowledge');
    }

    /**
     * FAIL-CLOSED: the knowledge store refuses template syntax, and the bot's own column never did. The
     * whole migration is refused (naming the offenders) rather than silently dropping three entries.
     */
    public function test_migrating_entries_carrying_template_syntax_is_refused_whole(): void
    {
        $bot = $this->bot([
            'knowledge' => [
                'enabled' => true,
                'entries' => [
                    ['title' => 'Zwroty', 'content' => 'Przyjmujemy w 14 dni.'],
                    ['title' => 'Sprytny', 'content' => 'Zobacz {{ workspace.secret }}.'],
                ],
            ],
        ]);

        $this->postJson("/api/bots/{$bot->id}/knowledge/migrate")
            ->assertJsonValidationErrors('knowledge');

        $this->assertSame(0, KnowledgeBase::query()->count());
        $this->assertSame(0, KnowledgeBinding::query()->count());
    }

    /**
     * B7 — MIGRATING A BOT THAT IS ALREADY BOUND. Pinned because it is the one migration case the
     * happy path does not describe and the one a user can reach by accident (the button stays on the
     * screen after a binding exists; the client only warns).
     *
     * What actually happens, and why it is defensible rather than a bug: a SECOND base is created from
     * the bot's untouched legacy column, and the binding — an upsert on `(bindable_type, bindable_id)`
     * — is RE-POINTED at it. A bot reads exactly one base, so there is no second-binding case to
     * consider; the unique pair makes it unrepresentable.
     *
     * The cost is the part worth writing down: the previously bound base is ORPHANED, not deleted. It
     * keeps its entries, its vectors and its edges, and only stops being read. That is the right
     * default for an irreversible-looking action — destroying a base somebody may have spent a week
     * curating because they pressed "migrate" twice would be far worse — but it does mean a workspace
     * can accumulate unreferenced bases, which is a housekeeping question for B8/B9, not a defect.
     */
    public function test_migrating_a_bot_that_is_already_bound_repoints_it_and_orphans_the_old_base(): void
    {
        $bot = $this->bot([
            'name' => 'Asystent',
            'knowledge' => [
                'enabled' => true,
                'entries' => [['title' => 'Zwroty', 'content' => 'Przyjmujemy w 14 dni.']],
            ],
        ]);

        $existing = $this->base(['name' => 'Recznie zrobiona baza']);
        $this->entry($existing, 'Wpis reczny', 'Tresc recznie napisana.');
        $this->bind($bot, $existing, KnowledgeBindingMode::INLINE);

        $migratedId = $this->postJson("/api/bots/{$bot->id}/knowledge/migrate")
            ->assertCreated()
            ->assertJsonPath('entries_count', 1)
            ->json('knowledge_base_id');

        $this->assertNotSame((string) $existing->id, (string) $migratedId, 'migration always mints a new base');

        // Still exactly ONE binding, now pointing at the new base — and its mode was reset to the
        // migration's own default rather than inheriting the one that was there.
        $bindings = KnowledgeBinding::query()->where('bindable_id', $bot->id)->get();
        $this->assertCount(1, $bindings, 'the unique pair makes a second binding unrepresentable');
        $this->assertSame((string) $migratedId, (string) $bindings->first()->knowledge_base_id);
        $this->assertSame(KnowledgeBindingMode::AUTO, $bindings->first()->mode);

        // The old base survives untouched — orphaned, not destroyed.
        $this->assertNotNull(KnowledgeBase::query()->find($existing->id));
        $this->assertSame(1, KnowledgeEntry::query()->where('knowledge_base_id', $existing->id)->count());
    }

    // ---- the legacy column stays writable while a binding exists ------------------------------

    /**
     * B7 — the ADDITIVE promise, from the WRITE side.
     *
     * The migration docblock says the bot's `knowledge` column is left exactly as it was so unbinding
     * restores the old behaviour perfectly. That claim is only true if the column also stays EDITABLE:
     * a bound bot whose built-in module had silently become read-only (or, worse, whose saves were
     * accepted and dropped) would make "unbind to get the old behaviour back" restore something the
     * user no longer recognises.
     *
     * So the write path for `bots.knowledge` is unchanged by a binding — pinned end to end on the bot
     * update endpoint, not on the service.
     */
    public function test_the_builtin_knowledge_module_is_still_writable_while_a_binding_is_active(): void
    {
        $bot = $this->bot([
            'knowledge' => [
                'enabled' => true,
                'entries' => [['title' => 'Stary wpis', 'content' => 'Stara tresc wbudowana.']],
            ],
        ]);

        $base = $this->base();
        $this->entry($base, 'Wpis z bazy', 'Tresc z prawdziwej bazy wiedzy.');
        $this->bind($bot, $base, KnowledgeBindingMode::INLINE);

        $this->putJson("/api/bots/{$bot->id}", [
            'name' => $bot->name,
            'persona' => $bot->persona,
            'knowledge' => [
                'enabled' => true,
                'entries' => [
                    ['title' => 'Stary wpis', 'content' => 'Tresc wbudowana PO edycji.'],
                    ['title' => 'Nowy wpis', 'content' => 'Dopisany przy aktywnym bindingu.'],
                ],
            ],
        ])->assertOk();

        $stored = $bot->fresh()->knowledgeEntries();
        $this->assertCount(2, $stored, 'the built-in module accepts writes exactly as before');
        $this->assertSame('Tresc wbudowana PO edycji.', $stored[0]['content']);

        // The binding is untouched by an edit of the column...
        $this->assertSame(
            (string) $base->id,
            (string) KnowledgeBinding::query()->where('bindable_id', $bot->id)->value('knowledge_base_id'),
        );

        // ...and it still WINS: what the bot reads is the base, not the freshly edited column.
        $context = $this->context($bot->fresh(), $this->task($bot));

        $this->assertStringContainsString('Tresc z prawdziwej bazy wiedzy.', $context);
        $this->assertStringNotContainsString('Tresc wbudowana PO edycji.', $context);
        $this->assertStringNotContainsString('Dopisany przy aktywnym bindingu.', $context);
    }

    public function test_a_non_owner_cannot_migrate(): void
    {
        $bot = $this->bot([
            'creator_id' => User::factory()->create()->id,
            'knowledge' => ['enabled' => true, 'entries' => [['title' => 'Zwroty', 'content' => 'W 14 dni.']]],
        ]);

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        $this->actingAs($member)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson("/api/bots/{$bot->id}/knowledge/migrate")
            ->assertForbidden();
    }
}
