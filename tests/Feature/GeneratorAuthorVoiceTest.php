<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotVoiceComposer;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\SessionAutomationService;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Contracts\AuthorVoiceResolver;
use App\Modules\Variables\Enums\AiPersona;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * PER-BLOCK `@[ai-text]` AUTHORS in the GENERATOR: an authored block names its own author (a bot), whose
 * opaque voice colors just that block.
 *
 * The properties pinned here are the ones the feature lives or dies by:
 *   - the authors of a recipe are FROZEN into `recipe_snapshot.author_voices` at CREATION, gathered from
 *     EVERY part of the content — so editing or deleting the bot afterwards cannot change what an existing
 *     session produces (the same snapshot-not-live rule `bot_delegation.voice` follows);
 *   - the TENANT boundary holds on BOTH creation paths, including the queued/automated one;
 *   - an author that resolves to NOTHING degrades the TONE and never the TEXT (fail-SAFE);
 *   - PRECEDENCE: block author > delegated session voice > persona line, with the no-author/no-voice case
 *     byte-identical to before the feature existed;
 *   - a REFINE of an authored part keeps speaking in its author's voice;
 *   - the creative direction's TONE is suppressed exactly for the blocks that have an effective voice;
 *   - the COST-METER actor is NOT affected by a per-block author (a negative pin).
 *
 * Setup mirrors GenerationSessionGenerateTest: a real workspace + active tenancy, so the real
 * LedgerMeteredAiCall records the rows the metering assertions read.
 */
class GeneratorAuthorVoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // Creative-direction layer OFF by default: an un-scripted derivation would be a REAL provider call.
        // The two direction cases below turn it on and seed a STORED direction (which is never re-derived).
        config()->set('generator.direction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    /** An `@[ai-text]("…")` directive exactly as the editor encodes it, optionally naming an AUTHOR. */
    private function aiText(string $prompt, ?string $authorId = null, string $id = 'ai_1'): string
    {
        $payload = json_encode(['v' => 1, 'data' => [
            'id' => $id,
            'personaId' => null,
            'authorId' => $authorId,
            'prompt' => $prompt,
            'labels' => [],
        ]]);

        return '@[ai-text]("' . str_replace('"', '\\"', (string) $payload) . '")';
    }

    private function bot(array $overrides = []): Bot
    {
        return Bot::factory()->create($overrides + ['creator_id' => $this->user->id, 'icon' => 'robot']);
    }

    /** Build inside $workspace as the ACTIVE shared tenant, so created rows are stamped with its id. */
    private function within(Workspace $workspace, callable $build)
    {
        $context = app(TenantContext::class);
        $previous = $context->workspace();
        $context->set($workspace);

        try {
            return $build();
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    /** Create a session through the REAL interactive path (request → DTO → service), returning the row. */
    private function createSessionFrom(Template $template): GenerationSession
    {
        $response = $this->postJson('/api/generator/sessions', [
            'template_id' => $template->id,
            'slot_values' => ['topic' => 'launch day'],
        ])->assertCreated();

        return GenerationSession::findOrFail($response->json('data.id'));
    }

    /** Run a session's FULL generation the way the worker does. */
    private function runJob(GenerationSession $session): void
    {
        $session->update(['status' => GenerationSessionStatus::Generating]);

        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    /** Claim + run ONE part op (the async refine/regenerate path minus the queue). */
    private function claimAndRun(GenerationSession $session, GenerationRunMode $mode, string $partKey, ?string $instruction = null): GenerationSession
    {
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, $mode, $partKey, $instruction);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, $mode->value, $partKey, $instruction))
            ->handle(app(GenerationSessionRunManager::class));

        return $session->fresh();
    }

    // ---- freezing at creation --------------------------------------------------

    /**
     * THE freeze pin: authors named in DIFFERENT parts of a recipe (and nested inside another block's
     * prompt) are all collected and resolved into the snapshot at creation. The walk is content-shape
     * agnostic, which is why a `shot_list` brief and a `storyboard` style are covered without either kind
     * being named anywhere in the snapshotter.
     */
    public function test_author_ids_are_collected_from_every_content_part_and_frozen_at_creation(): void
    {
        $writer = $this->bot(['persona' => 'THE WRITER PERSONA']);
        $narrator = $this->bot(['persona' => 'THE NARRATOR PERSONA']);
        $nested = $this->bot(['persona' => 'THE NESTED PERSONA']);

        $template = Template::factory()->definitionOf('video_script', [
            'shot_list' => ['brief' => ['markdown' => 'Brief ' . $this->aiText('hook', $writer->id)]],
            'storyboard' => [
                'style' => ['markdown' => 'Style ' . $this->aiText(
                    'outer ' . $this->aiText('inner', $nested->id, 'ai_3'),
                    $narrator->id,
                    'ai_2',
                )],
                'filters' => [],
            ],
        ], [$this->topicSlot()])->create(['creator_id' => $this->user->id]);

        $session = $this->createSessionFrom($template);
        $voices = $session->recipe_snapshot['author_voices'];

        $this->assertEqualsCanonicalizing([$writer->id, $narrator->id, $nested->id], array_keys($voices));

        // The frozen directive is the SAME one a delegated session speaks in — composed, never forked.
        $this->assertSame(app(BotVoiceComposer::class)->compose($writer), $voices[$writer->id]);
        $this->assertStringContainsString('THE NESTED PERSONA', $voices[$nested->id]);
    }

    /** A recipe naming no author freezes an EMPTY map and costs no lookup — the overwhelmingly common case. */
    public function test_a_recipe_without_authors_freezes_an_empty_map(): void
    {
        $template = Template::factory()->create(['creator_id' => $this->user->id]);

        $this->assertSame([], $this->createSessionFrom($template)->recipe_snapshot['author_voices']);
    }

    /**
     * The whole reason the map is FROZEN rather than resolved live, mirroring the delegated-voice pin in
     * BotSessionDelegationTest: a bot edited (or deleted outright) AFTER a session exists must not be able
     * to change what that session renders — not on its first run, not on a re-run weeks later.
     */
    public function test_a_bot_edited_or_deleted_after_creation_never_changes_the_sessions_voice(): void
    {
        $bot = $this->bot(['persona' => 'ORIGINAL PERSONA']);

        $template = Template::factory()->content([
            'body' => ['markdown' => 'Post ' . $this->aiText('write the post', $bot->id)],
        ])->create(['creator_id' => $this->user->id]);

        $session = $this->createSessionFrom($template);
        $frozen = $session->authorVoices()[$bot->id];
        $this->assertStringContainsString('ORIGINAL PERSONA', $frozen);

        $bot->update(['persona' => 'CHANGED PERSONA']);
        $bot->delete();

        // The snapshot is the authority; nothing is re-derived from the (now gone) bot.
        $reloaded = $session->fresh();
        $this->assertSame($frozen, $reloaded->authorVoices()[$bot->id]);
        $this->assertStringNotContainsString('CHANGED PERSONA', $reloaded->authorVoices()[$bot->id]);

        // And the RUN still renders in the frozen voice — the deleted bot is never consulted.
        AiTextAgent::fake(fn () => 'OUT');
        $this->runJob($reloaded);

        AiTextAgent::assertPrompted(
            fn ($prompt): bool => str_contains((string) $prompt->agent->instructions(), 'ORIGINAL PERSONA'),
        );
        $this->assertSame('Post OUT', $reloaded->fresh()->results['body']['text']);
    }

    // ---- tenancy on BOTH creation paths ----------------------------------------

    /** A bot from ANOTHER workspace can never be frozen into this workspace's session (interactive path). */
    public function test_a_foreign_workspace_bot_never_reaches_the_frozen_map_on_the_interactive_path(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $foreign = $this->within($other, fn () => $this->bot(['persona' => 'FOREIGN PERSONA']));
        $own = $this->bot(['persona' => 'OWN PERSONA']);

        $template = Template::factory()->content([
            'body' => ['markdown' => $this->aiText('a', $own->id) . ' ' . $this->aiText('b', $foreign->id, 'ai_2')],
        ])->create(['creator_id' => $this->user->id]);

        $voices = $this->createSessionFrom($template)->authorVoices();

        $this->assertSame([$own->id], array_keys($voices));
        $this->assertArrayNotHasKey($foreign->id, $voices, 'a foreign bot must never lend its voice');
    }

    /**
     * The SAME boundary on the QUEUED automation path (the `generate_content` workflow step's create), which
     * is the dangerous one: it runs with no HTTP request behind it, so the explicit workspace pin is all
     * there is. Both paths go through the ONE choke point, which is what makes this true by construction.
     */
    public function test_a_foreign_workspace_bot_never_reaches_the_frozen_map_on_the_automated_path(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $foreign = $this->within($other, fn () => $this->bot(['persona' => 'FOREIGN PERSONA']));
        $own = $this->bot(['persona' => 'OWN PERSONA']);

        $template = Template::factory()->content([
            'body' => ['markdown' => $this->aiText('a', $own->id) . ' ' . $this->aiText('b', $foreign->id, 'ai_2')],
        ])->create(['creator_id' => $this->user->id]);

        $session = app(SessionAutomationService::class)->createFromTemplate($template, ['topic' => 'x']);

        $this->assertSame($this->workspace->id, $session->workspace_id);
        $this->assertSame([$own->id], array_keys($session->authorVoices()));
    }

    // ---- fail-SAFE --------------------------------------------------------------

    /**
     * THE fail-SAFE pin, end to end: a block whose author cannot be resolved (deleted before the session was
     * ever created, so it is absent from the frozen map) still RENDERS — with the block's persona tone. A
     * vanished author may cost a run its intended tone; it may never cost it its text.
     */
    public function test_an_unresolvable_author_still_renders_the_block_with_the_persona_tone(): void
    {
        $ghost = (string) Str::uuid();

        $template = Template::factory()->content([
            'body' => ['markdown' => 'Post ' . $this->aiText('write the post', $ghost)],
        ])->create(['creator_id' => $this->user->id]);

        $session = $this->createSessionFrom($template);
        $this->assertSame([], $session->authorVoices(), 'an unresolvable author is ABSENT, never present-and-null');

        AiTextAgent::fake(fn () => 'REAL TEXT');
        $this->runJob($session);

        $result = $session->fresh()->results['body'];
        $this->assertSame('ok', $result['status']);
        $this->assertSame('Post REAL TEXT', $result['text'], 'a missing author must never blank the part');

        AiTextAgent::assertPrompted(
            fn ($prompt): bool => str_contains((string) $prompt->agent->instructions(), AiPersona::NEUTRAL->styleInstruction()),
        );
    }

    /** A legacy session (snapshot created before the feature, so no `author_voices` key) reads as empty. */
    public function test_a_legacy_snapshot_without_the_key_reads_as_an_empty_map(): void
    {
        $session = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => 'Body']], [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        $this->assertSame([], $session->authorVoices());
    }

    /**
     * The contract PROMISES never-throws — but the promise is upheld by the CONSUMER, not merely trusted.
     * A violating implementation (or a DB fault inside a well-behaved one) on the freeze path must cost the
     * authored TONE, never the session: this runs on the INTERACTIVE create, so an escaping Throwable would
     * 500 a plain "create session" for a recipe that merely NAMES an author — turning a fail-safe
     * degradation into a hard outage. The twin of the same guard in WorkflowStepRunner.
     */
    public function test_a_throwing_author_lookup_still_creates_a_runnable_session(): void
    {
        $bot = $this->bot();

        $template = Template::factory()->content([
            'body' => ['markdown' => 'Post ' . $this->aiText('write the post', $bot->id)],
        ])->create(['creator_id' => $this->user->id]);

        $this->app->instance(AuthorVoiceResolver::class, new class implements AuthorVoiceResolver
        {
            public function voicesFor(array $authorIds, ?string $workspaceId): array
            {
                throw new RuntimeException('author voice lookup exploded');
            }
        });

        $session = $this->createSessionFrom($template);

        $this->assertSame([], $session->authorVoices(), 'a failed lookup freezes an EMPTY map, it does not blow up the create');

        AiTextAgent::fake(fn () => 'REAL TEXT');
        $this->runJob($session);

        $result = $session->fresh()->results['body'];
        $this->assertSame('ok', $result['status']);
        $this->assertSame('Post REAL TEXT', $result['text'], 'a failed author lookup must never blank the part');
    }

    // ---- precedence -------------------------------------------------------------

    /**
     * The full precedence ladder in ONE run: a session DELEGATED to bot A whose body has one block authored
     * by bot B and one with no author. B's block speaks as B, the un-authored block speaks as A. The frozen
     * map is what the executor publishes; AiVoiceContext ranks the two.
     */
    public function test_a_block_author_outranks_the_delegated_session_voice_while_an_unauthored_block_keeps_it(): void
    {
        $authorB = $this->bot();

        $session = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => $this->aiText('authored', $authorB->id, 'ai_1') . ' | ' . $this->aiText('plain', null, 'ai_2'),
            ]], [$this->topicSlot()], ['topic' => 'x'])
            ->authorVoices([$authorB->id => 'SPEAK AS AUTHOR B'])
            ->create(['creator_id' => $this->user->id]);

        app(SessionDelegationService::class)->applyDelegation(
            $session,
            'SPEAK AS SESSION BOT A',
            ['id' => $authorB->id, 'name' => 'A', 'icon' => 'robot'],
            $authorB->id,
        );

        $seen = [];
        AiTextAgent::fake(function (string $prompt) use (&$seen) {
            $seen[] = $prompt;

            return 'OUT';
        });

        $this->runJob($session->fresh());

        // Both blocks generated, and each with the voice that applies to IT.
        $this->assertCount(2, $seen);
        AiTextAgent::assertPrompted(fn ($p): bool => str_contains((string) $p->agent->instructions(), 'SPEAK AS AUTHOR B'));
        AiTextAgent::assertPrompted(fn ($p): bool => str_contains((string) $p->agent->instructions(), 'SPEAK AS SESSION BOT A'));
    }

    /**
     * BYTE PIN: a block with NO author and a block whose author resolved to NOTHING must produce the very
     * same agent instruction — i.e. the whole author layer is INERT unless a voice actually resolves. This
     * is what keeps every recipe authored before the feature (and every author box left empty) unchanged.
     */
    public function test_an_unresolved_author_leaves_the_instruction_byte_identical_to_no_author_at_all(): void
    {
        $capture = function (string $markdown): string {
            $session = GenerationSession::factory()
                ->snapshot('post', ['body' => ['markdown' => $markdown]], [$this->topicSlot()], ['topic' => 'x'])
                ->create(['creator_id' => $this->user->id]);

            $seen = '';
            AiTextAgent::fake(function () {
                return 'OUT';
            });

            $this->runJob($session);

            AiTextAgent::assertPrompted(function ($prompt) use (&$seen): bool {
                $seen = (string) $prompt->agent->instructions();

                return true;
            });

            return $seen;
        };

        $withoutAuthor = $capture($this->aiText('write'));
        $withGhostAuthor = $capture($this->aiText('write', (string) Str::uuid()));

        $this->assertNotSame('', $withoutAuthor);
        $this->assertSame($withoutAuthor, $withGhostAuthor);
        $this->assertStringContainsString(AiPersona::NEUTRAL->styleInstruction(), $withoutAuthor);
    }

    // ---- refine keeps the author -------------------------------------------------

    /**
     * A refine is a SECOND call, composed by the executor rather than by the block — so without threading the
     * part's author it would silently drop back to the persona/session tone and the text would stop sounding
     * like its author the moment anyone touched it.
     */
    public function test_refining_an_authored_part_keeps_speaking_in_its_authors_voice(): void
    {
        $author = $this->bot();

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => 'Post ' . $this->aiText('write', $author->id)]], [$this->topicSlot()], ['topic' => 'x'])
            ->authorVoices([$author->id => 'SPEAK AS THE AUTHOR'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'CURRENT TEXT', 'version' => 1]],
            ]);

        AiTextAgent::fake(fn () => 'REVISED TEXT');
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'make it punchier');

        $this->assertSame('REVISED TEXT', $session->results['body']['text']);
        AiTextAgent::assertPrompted(
            fn ($p): bool => str_contains((string) $p->agent->instructions(), 'SPEAK AS THE AUTHOR'),
        );
    }

    /**
     * A refine rewrites the part's WHOLE output, so it has exactly ONE voice to speak in. When the part's
     * blocks name TWO DIFFERENT authors there is no such voice, and picking one — whichever the scanner
     * happened to reach first — would hand a stranger's tone to the other author's text with nothing in the
     * UI saying so. An AMBIGUOUS part therefore falls back to the run-wide voice: here, the DELEGATED
     * session's, which is the one voice the whole part legitimately shares.
     */
    public function test_refining_a_part_with_two_different_authors_falls_back_to_the_session_voice(): void
    {
        $first = $this->bot();
        $second = $this->bot();

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => $this->aiText('one', $first->id, 'ai_1') . ' | ' . $this->aiText('two', $second->id, 'ai_2')]], [$this->topicSlot()], ['topic' => 'x'])
            ->authorVoices([$first->id => 'SPEAK AS THE FIRST AUTHOR', $second->id => 'SPEAK AS THE SECOND AUTHOR'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'CURRENT TEXT', 'version' => 1]],
            ]);

        app(SessionDelegationService::class)->applyDelegation(
            $session,
            'SPEAK AS THE SESSION BOT',
            ['id' => $first->id, 'name' => 'A', 'icon' => 'robot'],
            $first->id,
        );

        AiTextAgent::fake(fn () => 'REVISED TEXT');
        $session = $this->claimAndRun($session->fresh(), GenerationRunMode::Refine, 'body', 'make it punchier');

        $this->assertSame('REVISED TEXT', $session->results['body']['text']);

        AiTextAgent::assertPrompted(function ($p): bool {
            $instructions = (string) $p->agent->instructions();

            return str_contains($instructions, 'SPEAK AS THE SESSION BOT')
                && !str_contains($instructions, 'SPEAK AS THE FIRST AUTHOR')
                && !str_contains($instructions, 'SPEAK AS THE SECOND AUTHOR');
        });
    }

    /** An UNauthored part refines exactly as before — nothing to inherit, so the persona line stands. */
    public function test_refining_an_unauthored_part_is_unchanged(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => 'Post ' . $this->aiText('write')]], [$this->topicSlot()], ['topic' => 'x'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'CURRENT TEXT', 'version' => 1]],
            ]);

        AiTextAgent::fake(fn () => 'REVISED TEXT');
        $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'make it punchier');

        AiTextAgent::assertPrompted(
            fn ($p): bool => str_contains((string) $p->agent->instructions(), AiPersona::NEUTRAL->styleInstruction()),
        );
    }

    // ---- creative direction: tone suppressed PER BLOCK ---------------------------

    /**
     * ADR-0038's "the voice wins on tone", now per block: in ONE undelegated run, the AUTHORED block's
     * prompt must NOT carry the direction's TONE (its author already owns the tone), while the un-authored
     * block's prompt still does. Before this, tone suppression keyed off the SESSION voice alone, so an
     * authored block in an undelegated run got both and the two competed.
     */
    public function test_the_direction_tone_is_suppressed_only_for_blocks_with_an_effective_voice(): void
    {
        config()->set('generator.direction.enabled', true);

        $author = $this->bot();

        $session = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => $this->aiText('AUTHORED-BLOCK', $author->id, 'ai_1') . ' | ' . $this->aiText('PLAIN-BLOCK', null, 'ai_2'),
            ]], [$this->topicSlot()], ['topic' => 'x'])
            ->authorVoices([$author->id => 'SPEAK AS THE AUTHOR'])
            ->create([
                'creator_id' => $this->user->id,
                // A STORED direction is reused verbatim by a full run — nothing is derived, so no provider call.
                'creative_direction' => ['message' => 'Ship it', 'tone' => 'DIRECTION-TONE-MARKER'],
            ]);

        $prompts = [];
        AiTextAgent::fake(function (string $prompt) use (&$prompts) {
            $prompts[] = $prompt;

            return 'OUT';
        });

        $this->runJob($session);

        $authored = array_values(array_filter($prompts, fn (string $p): bool => str_contains($p, 'AUTHORED-BLOCK')));
        $plain = array_values(array_filter($prompts, fn (string $p): bool => str_contains($p, 'PLAIN-BLOCK')));

        $this->assertCount(1, $authored);
        $this->assertCount(1, $plain);

        $this->assertStringContainsString('Ship it', $authored[0], 'the rest of the direction still rides along');
        $this->assertStringNotContainsString('DIRECTION-TONE-MARKER', $authored[0], 'the author voice wins on tone');
        $this->assertStringContainsString('DIRECTION-TONE-MARKER', $plain[0], 'a voice-less block keeps the full projection');
    }

    // ---- the cost meter is NOT touched by a per-block author ---------------------

    /**
     * NEGATIVE pin. A per-block author changes the VOICE and nothing else. Spend attribution stays what
     * R2 sub-stage 4 decided: the session's actor — the delegated BOT author when the SESSION is delegated,
     * else the human owner. A block-level author must never become a meter actor "while we're at it", or
     * one recipe's spend would fan out across several actors' budgets and the per-actor cap would stop
     * meaning anything.
     */
    public function test_a_per_block_author_never_changes_the_cost_meter_actor(): void
    {
        $author = $this->bot();

        $session = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => $this->aiText('write', $author->id)]], [$this->topicSlot()], ['topic' => 'x'])
            ->authorVoices([$author->id => 'SPEAK AS THE AUTHOR'])
            ->create(['creator_id' => $this->user->id]);

        AiTextAgent::fake(fn () => 'OUT');
        $this->runJob($session);

        $event = AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->sole();

        $this->assertSame('user', $event->actor_type, 'an undelegated session spends as its human OWNER');
        $this->assertSame($this->user->id, $event->actor_id);
        $this->assertSame(0, AiUsageEvent::where('actor_type', 'bot')->count(), 'a block author is not a spender');
    }

    /** And when the SESSION is delegated, the actor is the SESSION's bot — not the block's author. */
    public function test_a_delegated_session_still_meters_to_its_own_bot_not_the_block_author(): void
    {
        $sessionBot = $this->bot();
        $blockAuthor = $this->bot();

        $session = GenerationSession::factory()
            ->snapshot('post', ['body' => ['markdown' => $this->aiText('write', $blockAuthor->id)]], [$this->topicSlot()], ['topic' => 'x'])
            ->authorVoices([$blockAuthor->id => 'SPEAK AS THE AUTHOR'])
            ->create(['creator_id' => $this->user->id]);

        app(SessionDelegationService::class)->applyDelegation(
            $session,
            'SPEAK AS THE SESSION BOT',
            ['id' => $sessionBot->id, 'name' => $sessionBot->name, 'icon' => 'robot'],
            $sessionBot->id,
        );

        AiTextAgent::fake(fn () => 'OUT');
        $this->runJob($session->fresh());

        $event = AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->sole();

        $this->assertSame('bot', $event->actor_type);
        $this->assertSame($sessionBot->id, $event->actor_id, 'the SESSION bot pays, never the block author');
    }
}
