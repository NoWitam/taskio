<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Agents\BotSlotFillAgent;
use App\Modules\Bot\Models\Bot;
use App\Modules\Disk\Services\FileService;
use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RenderStoryboardFrameJob;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionLifecycleService;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Generator\Services\SessionIdentityImageStore;
use App\Modules\Generator\Services\StoryboardFrameManager;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Tests\TestCase;

/**
 * THE CHARACTER'S LOOK, carried from the bot into what a delegated session actually DRAWS.
 *
 * A session delegated to a bot already renders its text in that bot's frozen VOICE. This is the same
 * contract for the picture, in three moves:
 *
 *   B3  FREEZE      delegating copies the written identity into the `bot_delegation.visual` overlay and the
 *                   approved likeness's BYTES into a store of their own. The bot may then be edited, its
 *                   likeness re-approved or the whole bot deleted, and the session keeps drawing the person
 *                   it was handed — the exact guarantee the voice already gives. Undo takes both back.
 *   B4  WHICH BEATS the shot-list writer is told who the on-screen creator is and answers, per shot, whether
 *                   that person is in the frame. The storyboard needs that: it is the difference between a
 *                   (billed) reference edit and a plain generation.
 *   B5  DRAW        a flagged frame — and, by default, an authored image of a delegated session — is produced
 *                   by EDITING the frozen likeness rather than generating from a description, and the
 *                   character's aesthetic + prohibitions ride EVERY image of the session.
 *
 * THE PINS THAT MATTER MOST ARE THE NEGATIVE ONES. Four situations must produce prompts and provider calls
 * BYTE-IDENTICAL to a run from before this layer existed: an undelegated session, a delegation to a bot with
 * no approved likeness, the kill switch off, and a stored shot list written before the flag existed. They are
 * asserted against the literal composed prompt, not against a substring, because "the character leaked into
 * every prompt" is precisely the regression that a substring assertion would not catch.
 *
 * Every AI seam is SCRIPTED (`Agent::fake`, `Image::fake`, `Http::fake` for the edit endpoint) — no case may
 * reach a real provider.
 */
class GeneratorVisualIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    /** The frozen likeness's raw bytes — distinctive, so "was THIS image sent to the provider?" is decidable. */
    private const LIKENESS = 'LIKENESS-BYTES-PNG';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // The direction layer is OFF unless a case turns it on: it predates this phase and an unscripted
        // derivation would be a REAL provider call.
        config()->set('generator.direction.enabled', false);

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function png(int $w, int $h, array $rgb = [1, 2, 3]): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private function identityStore(): SessionIdentityImageStore
    {
        return app(SessionIdentityImageStore::class);
    }

    /**
     * A bot whose visual module is configured. $withLikeness attaches an APPROVED bot-owned image file with
     * {@see LIKENESS} as its bytes — the thing the session copies.
     */
    private function bot(bool $withLikeness = true, bool $enabled = true, array $visual = []): Bot
    {
        $bot = Bot::factory()->withVisual($visual, $enabled)->create(['creator_id' => $this->user->id, 'icon' => 'robot']);

        if (!$withLikeness) {
            return $bot;
        }

        $file = app(FileService::class)->storeContent(self::LIKENESS, 'likeness.png', 'image/png', $bot);

        $bot->update(['visual' => array_merge($bot->visualIdentity(), [
            'candidates' => [$file->id],
            'canonical_file_id' => $file->id,
        ])]);

        return $bot->fresh();
    }

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    /** The video_script snapshot content: a shot_list brief + a storyboard style/filters. */
    private function videoScriptContent(): array
    {
        return [
            'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            'storyboard' => ['style' => ['markdown' => 'flat vector'], 'filters' => []],
        ];
    }

    private function videoSession(): GenerationSession
    {
        return GenerationSession::factory()
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'pets'])
            ->create(['creator_id' => $this->user->id]);
    }

    /** A `post_with_image` session whose image is an authored ai_generate base. */
    private function imageSession(array $imagePlan = []): GenerationSession
    {
        return GenerationSession::factory()
            ->snapshot('post_with_image', [
                'body' => ['markdown' => 'Body'],
                'image' => array_merge(['base' => ['kind' => 'ai_generate', 'prompt' => 'a mug on a desk'], 'filters' => []], $imagePlan),
            ], [$this->topicSlot()], ['topic' => 'pets'])
            ->create(['creator_id' => $this->user->id]);
    }

    /** Delegate $session to $bot through the real endpoint (no slot fill needed — the agent answers empty). */
    private function delegate(Bot $bot, GenerationSession $session): void
    {
        BotSlotFillAgent::fake(fn () => '{}');

        $this->postJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")->assertOk();

        $session->refresh();
    }

    /** Script the shot list, optionally flagging which shots show the creator. */
    private function fakeShotList(array $featuresCharacter = [false, false]): void
    {
        $shots = [];

        foreach (array_values($featuresCharacter) as $i => $flag) {
            $shots[] = ['visual' => 'visual ' . $i, 'voiceover' => 'vo ' . $i, 'seconds' => 3, 'features_character' => $flag];
        }

        ShotListAgent::fake(fn () => json_encode(['hook' => 'H', 'shots' => $shots, 'cta' => 'C']));
    }

    /**
     * Capture every text→image GENERATE prompt into $prompts (by reference, and initialized here so a case
     * can hand in a fresh variable), so the exact composed base is assertable.
     */
    private function captureGenerates(mixed &$prompts): void
    {
        $prompts = [];

        Image::fake(function (ImagePrompt $prompt) use (&$prompts): string {
            $prompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6));
        });
    }

    /** Fake the reference-EDIT endpoint (the provider call a character frame makes instead of generating). */
    private function fakeEdit(): void
    {
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(7, 7))]]], 200)]);
    }

    /** Fake the reference-EDIT endpoint REFUSING the rendered content (output-side moderation). */
    private function fakeEditRefused(): void
    {
        Http::fake(['*/images/edits' => Http::response(['error' => ['code' => 'moderation_blocked']], 400)]);
    }

    /** The raw multipart bodies of every images/edits call made. */
    private function editBodies(): array
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/images/edits'))
            ->map(fn (array $pair): string => (string) $pair[0]->body())
            ->values()
            ->all();
    }

    private function runJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    /** Run a whole session (claiming it first) and drain the storyboard frame jobs it queued. */
    private function runWholeSession(GenerationSession $session): GenerationSession
    {
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, GenerationRunMode::Full);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, GenerationRunMode::Full->value))
            ->handle(app(GenerationSessionRunManager::class));

        foreach (Queue::pushed(RenderStoryboardFrameJob::class) as $job) {
            $job->handle(app(StoryboardFrameManager::class), app(GenerationSessionRunManager::class));
        }

        return $session->fresh();
    }

    // ---- B3: the freeze --------------------------------------------------------

    public function test_delegating_freezes_the_written_identity_and_the_likeness_bytes(): void
    {
        $bot = $this->bot();
        $session = $this->videoSession();

        $this->delegate($bot, $session);

        $visual = $session->botVisualIdentity();
        $this->assertIsArray($visual);
        $this->assertTrue($visual['enabled']);
        $this->assertSame('A cheerful red-haired illustrator in her late twenties.', $visual['descriptor']);
        $this->assertSame('Soft flat illustration, warm palette, gentle rim light.', $visual['aesthetic']);
        $this->assertSame('A simple green summer dress.', $visual['wardrobe']);
        $this->assertTrue($visual['has_character_image']);
        $this->assertSame($bot->visualIdentity()['canonical_file_id'], $visual['source_file_id']);
        $this->assertNotNull($visual['snapshot_at']);

        // The BYTES were copied, not merely referenced.
        $this->assertTrue($session->hasCharacterImage());
        $this->assertSame(self::LIKENESS, $this->identityStore()->get($session->id, $bot->id));

        // The wire says a character is in play — a flag only, never the identity text itself.
        $body = $this->getJson("/api/generator/sessions/{$session->id}")->assertOk()->json('data');
        $this->assertTrue($body['has_character_image']);
        $this->assertArrayNotHasKey('bot_delegation', $body);
        $this->assertStringNotContainsString('red-haired', json_encode($body));
    }

    public function test_a_bot_with_the_module_off_freezes_nothing_at_all(): void
    {
        $session = $this->videoSession();

        $this->delegate($this->bot(enabled: false), $session);

        $this->assertTrue($session->isDelegated());
        $this->assertNull($session->botVisualIdentity());
        $this->assertFalse($session->hasCharacterImage());
        $this->assertArrayNotHasKey('visual', $session->bot_delegation);
    }

    public function test_a_bot_with_no_approved_likeness_freezes_the_text_but_records_no_image(): void
    {
        $session = $this->videoSession();

        $this->delegate($this->bot(withLikeness: false), $session);

        $visual = $session->botVisualIdentity();
        $this->assertSame('A cheerful red-haired illustrator in her late twenties.', $visual['descriptor']);
        $this->assertFalse($visual['has_character_image']);
        // Never name a file that was not frozen.
        $this->assertNull($visual['source_file_id']);
        $this->assertNull($session->characterImageKey());
    }

    public function test_editing_re_approving_or_deleting_the_bot_never_changes_a_frozen_look(): void
    {
        $bot = $this->bot();
        $session = $this->videoSession();

        $this->delegate($bot, $session);
        $frozen = $session->botVisualIdentity();

        // Rewrite the identity, drop the approval, then delete the bot outright.
        $bot->update(['visual' => array_merge($bot->visualIdentity(), [
            'descriptor' => 'A COMPLETELY DIFFERENT PERSON',
            'aesthetic' => 'CHANGED',
            'canonical_file_id' => null,
        ])]);
        $bot->delete();

        $reloaded = $session->fresh();
        $this->assertSame($frozen, $reloaded->botVisualIdentity());
        $this->assertStringNotContainsString('DIFFERENT', json_encode($reloaded->botVisualIdentity()));
        // And the likeness the session draws from is still there, byte for byte.
        $this->assertSame(self::LIKENESS, $this->identityStore()->get($session->id, $bot->id));
    }

    public function test_undoing_the_delegation_deletes_the_frozen_likeness(): void
    {
        $bot = $this->bot();
        $session = $this->videoSession();

        $this->delegate($bot, $session);
        $this->assertTrue($this->identityStore()->exists($session->id, $bot->id));

        $this->deleteJson("/api/bots/{$bot->id}/sessions/{$session->id}/delegate")->assertOk();

        $session->refresh();
        $this->assertNull($session->botVisualIdentity());
        $this->assertFalse($session->hasCharacterImage());
        $this->assertFalse($this->identityStore()->exists($session->id, $bot->id));
    }

    public function test_re_delegating_to_another_bot_leaves_no_trace_of_the_first_likeness(): void
    {
        $first = $this->bot();
        $second = $this->bot();
        $session = $this->videoSession();

        $this->delegate($first, $session);
        $this->delegate($second, $session);

        $this->assertFalse($this->identityStore()->exists($session->id, $first->id));
        $this->assertTrue($this->identityStore()->exists($session->id, $second->id));
        $this->assertSame($second->id, $session->characterImageKey());
    }

    /**
     * A re-delegation is TWO writes that must not be able to leave the session naming a likeness that no
     * longer exists: the new author's bytes, and the row that points at them. The old author's file is
     * per-author (`<botId>.png`), so the new write cannot overwrite it — which means dropping it FIRST buys
     * nothing and costs everything if the rest of the re-delegation then fails.
     */
    public function test_a_failed_re_delegation_never_destroys_the_previous_authors_likeness(): void
    {
        $first = $this->bot();
        $second = $this->bot();
        $session = $this->videoSession();

        $this->delegate($first, $session);
        $this->assertSame(self::LIKENESS, $this->identityStore()->get($session->id, $first->id));

        // Freezing the NEW likeness fails (a full disk, a storage outage) — a crash exactly between letting
        // go of the old delegation and committing the new one.
        app()->instance(SessionIdentityImageStore::class, new class(app(TenantContext::class)) extends SessionIdentityImageStore
        {
            public function put(string $sessionId, string $characterKey, string $bytes): void
            {
                throw new \RuntimeException('storage is full');
            }
        });

        try {
            app(SessionDelegationService::class)->applyDelegation(
                $session->fresh(),
                'a voice',
                ['id' => $second->id, 'name' => $second->name, 'icon' => $second->icon],
                $second->id,
                ['descriptor' => 'Someone else', 'aesthetic' => null, 'wardrobe' => null, 'prohibitions' => [], 'source_file_id' => null],
                'NEW-LIKENESS-BYTES',
            );
            $this->fail('the storage failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('storage is full', $e->getMessage());
        }

        // The session still names the FIRST author — and the likeness it names is still there to draw.
        $reloaded = $session->fresh();
        $this->assertSame($first->id, $reloaded->characterImageKey());
        $this->assertSame('A cheerful red-haired illustrator in her late twenties.', $reloaded->botVisualIdentity()['descriptor']);
        $this->assertSame(self::LIKENESS, $this->identityStore()->get($session->id, $first->id));
    }

    /**
     * THE pin for the store's separate root. A full (re)run wipes the session's PRODUCED-image prefix so the
     * run starts clean — and if the likeness lived in that prefix, every re-run of a delegated session would
     * silently destroy the character and then draw the rest of the run without it.
     */
    public function test_a_full_re_run_wipes_the_produced_images_but_never_the_frozen_likeness(): void
    {
        $bot = $this->bot();
        $session = $this->videoSession();
        $this->delegate($bot, $session);

        $this->fakeShotList([false]);
        $this->captureGenerates($prompts);
        $session = $this->runWholeSession($session);

        $this->assertTrue(app(\App\Modules\Generator\Services\GeneratedImageStore::class)->exists($session->id, 'storyboard.0', 1));
        $this->assertTrue($this->identityStore()->exists($session->id, $bot->id));

        // Re-run the whole session: produced images are cleared at claim, the likeness is not.
        $this->fakeShotList([false]);
        $this->captureGenerates($prompts);
        $session = $this->runWholeSession($session);

        $this->assertSame(self::LIKENESS, $this->identityStore()->get($session->id, $bot->id));
    }

    public function test_the_lifecycle_purge_collects_the_frozen_likeness_with_the_session(): void
    {
        $bot = $this->bot();
        $session = $this->videoSession();
        $this->delegate($bot, $session);

        $path = $this->identityStore()->path($session->id, $bot->id);
        Storage::assertExists($path);

        $session->delete();
        // `deleted_at` is not fillable — age it past the purge window on the query, as the lifecycle suite does.
        GenerationSession::withTrashed()->whereKey($session->id)->update(['deleted_at' => now()->subMonths(2)]);

        app(GenerationSessionLifecycleService::class)->purgeTrashed();

        $this->assertSame(0, GenerationSession::withTrashed()->whereKey($session->id)->count());
        Storage::assertMissing($path);
    }

    // ---- B4: which beats show the creator --------------------------------------

    public function test_the_shot_list_is_told_who_the_creator_is_only_when_one_can_be_drawn(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(), $session);

        ShotListAgent::fake(fn () => json_encode(['hook' => 'H', 'shots' => [], 'cta' => 'C']));

        $this->captureGenerates($prompts);
        $this->runWholeSession($session);

        // The recorded prompt carries the AGENT that was built for it, so the instruction the executor
        // actually threaded is assertable end to end (not just at the agent's own unit boundary).
        ShotListAgent::assertPrompted(function ($prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'ON-SCREEN CREATOR')
                && str_contains($instructions, 'A cheerful red-haired illustrator')
                && str_contains($instructions, 'Wearing: A simple green summer dress.')
                && str_contains($instructions, '"features_character": boolean');
        });
    }

    public function test_an_undelegated_run_never_mentions_a_creator_to_the_shot_list(): void
    {
        ShotListAgent::fake(fn () => json_encode(['hook' => 'H', 'shots' => [], 'cta' => 'C']));

        $this->captureGenerates($prompts);
        $this->runWholeSession($this->videoSession());

        ShotListAgent::assertPrompted(function ($prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return !str_contains($instructions, 'ON-SCREEN CREATOR')
                && !str_contains($instructions, 'features_character');
        });
    }

    public function test_the_renderer_defaults_the_flag_to_false_and_reads_the_models_deviations(): void
    {
        $renderer = app(\App\Modules\Generator\Services\ShotListRenderer::class);

        ShotListAgent::fake(fn () => json_encode(['hook' => 'H', 'cta' => 'C', 'shots' => [
            ['visual' => 'a', 'voiceover' => 'v', 'seconds' => 1],                                 // absent
            ['visual' => 'b', 'voiceover' => 'v', 'seconds' => 1, 'features_character' => true],   // real bool
            ['visual' => 'c', 'voiceover' => 'v', 'seconds' => 1, 'features_character' => 'true'], // stringified
            ['visual' => 'd', 'voiceover' => 'v', 'seconds' => 1, 'features_character' => 1],      // numeric
            ['visual' => 'e', 'voiceover' => 'v', 'seconds' => 1, 'features_character' => 'nope'], // nonsense
            ['visual' => 'f', 'voiceover' => 'v', 'seconds' => 1, 'features_character' => ['x']],  // not scalar
        ]]));

        $shots = $renderer->generate('brief', 8)['shots'];

        $this->assertSame(
            [false, true, true, true, false, false],
            array_column($shots, 'features_character'),
        );
    }

    public function test_the_flag_reaches_the_wire_on_both_the_shot_list_and_the_storyboard(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(), $session);

        $this->fakeShotList([true, false]);
        $this->captureGenerates($prompts);
        $this->fakeEdit();
        $session = $this->runWholeSession($session);

        $this->assertSame([true, false], array_column($session->results['shot_list']['shots'], 'features_character'));
        $this->assertSame([true, false], array_column($session->results['storyboard']['shots'], 'features_character'));

        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.results.storyboard.shots.0.features_character', true)
            ->assertJsonPath('data.results.storyboard.shots.1.features_character', false);
    }

    // ---- B5: drawing the character ---------------------------------------------

    public function test_a_flagged_frame_edits_the_likeness_while_an_unflagged_one_still_generates(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(), $session);

        $this->fakeShotList([true, false]);
        $this->captureGenerates($generates);
        $this->fakeEdit();

        $session = $this->runWholeSession($session);

        // Exactly ONE reference edit (the flagged frame) and ONE plain generate (the unflagged one).
        $edits = $this->editBodies();
        $this->assertCount(1, $edits);
        $this->assertCount(1, $generates);

        // The edit was posted with the FROZEN likeness as its source image...
        $this->assertStringContainsString(self::LIKENESS, $edits[0]);
        // ...and the character is named as the binding subject of that frame.
        $this->assertStringContainsString('Recurring subject, identical in every frame: A cheerful red-haired illustrator', $edits[0]);
        $this->assertStringContainsString('THIS description wins', $edits[0]);

        // The unflagged frame carries no subject anchor — nobody was put in the product shot.
        $this->assertStringNotContainsString('Recurring subject', $generates[0]);

        // Both frames settled with a real image.
        $this->assertSame(['ok', 'ok'], array_column($session->results['storyboard']['shots'], 'image_status'));
    }

    /**
     * The reference frame reserves the GENERATE ledger but meters as an EDIT — deliberately two different
     * books, because they answer different questions. The ledger bounds the per-run FAN-OUT (it is still the
     * shot's base; charging it to the edit ledger would let the bases eat the budget an authored `ai_edit`
     * filter needs on every shot, and the tail of a storyboard would silently lose the author's look). The
     * meter records the call actually made, which is what is actually billed.
     */
    public function test_a_reference_frame_reserves_a_generate_but_is_billed_as_an_edit(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(), $session);

        $this->fakeShotList([true, false]);
        $this->captureGenerates($generates);
        $this->fakeEdit();

        $session = $this->runWholeSession($session);

        // Two shots, two bases → two GENERATE reservations, even though one of them was an edit call.
        $this->assertSame(2, $session->ai_generate_calls);
        $this->assertSame(0, $session->ai_edit_calls);

        // But the money is recorded on the channel that was actually used, tagged with this session.
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_image_edit')->where('session_id', $session->id)->count());
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_image_generate')->where('session_id', $session->id)->count());
    }

    public function test_the_characters_aesthetic_and_prohibitions_ride_every_image_including_unflagged_ones(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(visual: ['prohibitions' => ['alcohol', 'logos']]), $session);

        $this->fakeShotList([false, false]);
        $this->captureGenerates($generates);

        $this->runWholeSession($session);

        $this->assertCount(2, $generates);

        foreach ($generates as $prompt) {
            $this->assertStringContainsString('Style: Soft flat illustration, warm palette, gentle rim light.', $prompt);
            $this->assertStringContainsString('Never show: alcohol, logos', $prompt);
            // Still nobody in the frame — the guardrails are about the picture, not the person.
            $this->assertStringNotContainsString('Recurring subject', $prompt);
        }
    }

    public function test_an_authored_image_of_a_delegated_session_draws_the_character_by_default(): void
    {
        $session = $this->imageSession();
        $this->delegate($this->bot(), $session);

        AiTextAgent::fake(fn () => 'body');
        $this->fakeEdit();

        $session = $this->runWholeSession($session);

        $edits = $this->editBodies();
        $this->assertCount(1, $edits);
        $this->assertStringContainsString(self::LIKENESS, $edits[0]);
        // The author's own prompt still has the last word, after the anchor + guardrails.
        $this->assertStringContainsString('a mug on a desk', $edits[0]);
        $this->assertSame('ok', $session->results['image']['status']);
    }

    /**
     * A LIKENESS-ONLY identity is reachable: the character can be generated from a one-off instruction with
     * every written field left blank, and then approved. The picture is then the WHOLE description — there
     * is no subject line and no guardrail to compose — so an authored image that has nothing to PREPEND
     * must still be drawn FROM the frozen likeness. The storyboard path already does; this is the plain
     * image path, where "there is no text to add" was being read as "there is no character".
     */
    public function test_an_identity_that_is_only_a_likeness_still_draws_from_the_reference(): void
    {
        $session = $this->imageSession();
        $this->delegate($this->bot(visual: [
            'descriptor' => null,
            'aesthetic' => null,
            'wardrobe' => null,
            'prohibitions' => [],
        ]), $session);

        // The overlay carries the likeness and nothing else.
        $this->assertTrue($session->hasCharacterImage());
        $this->assertNull($session->botVisualIdentity()['descriptor']);

        AiTextAgent::fake(fn () => 'body');
        $this->captureGenerates($generates);
        $this->fakeEdit();

        $session = $this->runWholeSession($session);

        $edits = $this->editBodies();
        $this->assertCount(1, $edits, 'a delegated image must be edited from the likeness, not generated from text');
        $this->assertStringContainsString(self::LIKENESS, $edits[0]);
        $this->assertStringContainsString('a mug on a desk', $edits[0]);
        $this->assertSame([], $generates);
        $this->assertSame('ok', $session->results['image']['status']);
    }

    public function test_an_image_part_marked_character_never_stays_a_plain_generation(): void
    {
        $session = $this->imageSession(['character' => 'never']);
        $this->delegate($this->bot(), $session);

        AiTextAgent::fake(fn () => 'body');
        $this->captureGenerates($generates);
        $this->fakeEdit();

        $this->runWholeSession($session);

        // No reference edit at all; the base was generated from text.
        $this->assertSame([], $this->editBodies());
        $this->assertCount(1, $generates);
        $this->assertStringNotContainsString('Recurring subject', $generates[0]);
        // The guardrails still apply — `never` is about the PERSON, not about the look of the set.
        $this->assertStringContainsString('Style: Soft flat illustration', $generates[0]);
    }

    public function test_a_write_rejects_an_unknown_character_mode(): void
    {
        $payload = [
            'name' => 'T',
            'content_type' => 'post_with_image',
            'slots' => [],
            'content' => [
                'body' => ['markdown' => 'Body'],
                'image' => ['base' => ['kind' => 'ai_generate', 'prompt' => 'x'], 'character' => 'none'],
            ],
        ];

        $this->postJson('/api/generator/templates', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.image.character']);

        // The two real modes are accepted.
        foreach (['auto', 'never'] as $mode) {
            $payload['content']['image']['character'] = $mode;
            $this->postJson('/api/generator/templates', $payload)->assertCreated();
        }
    }

    public function test_a_provider_moderation_refusal_fails_that_frame_with_its_own_actionable_code(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(), $session);

        $this->fakeShotList([true]);
        $this->fakeEditRefused();

        $session = $this->runWholeSession($session);

        $shot = $session->results['storyboard']['shots'][0];
        $this->assertSame('failed', $shot['image_status']);
        $this->assertSame(__('generator.sessions.image_safety'), $shot['image_error']);
        // NOT the generic "check its base and filters" message, which points at the wrong fix.
        $this->assertNotSame(__('generator.sessions.image_failed'), $shot['image_error']);
        // Fail-soft: the run itself still settles.
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);

        // The reason also travels as a MACHINE-readable code, exactly as a Disk edit's does, so the UI can
        // offer the fix ("change the wardrobe") instead of matching on a translated sentence.
        $this->assertSame('image_safety', $shot['image_error_code']);

        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.results.storyboard.shots.0.image_error_code', 'image_safety');
    }

    public function test_an_authored_image_refused_by_moderation_carries_the_same_code(): void
    {
        $session = $this->imageSession();
        $this->delegate($this->bot(), $session);

        AiTextAgent::fake(fn () => 'body');
        $this->fakeEditRefused();

        $session = $this->runWholeSession($session);

        $this->assertSame('failed', $session->results['image']['status']);
        $this->assertSame(__('generator.sessions.image_safety'), $session->results['image']['error']);
        $this->assertSame('image_safety', $session->results['image']['error_code']);

        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.results.image.error_code', 'image_safety');
    }

    public function test_an_ordinary_image_failure_carries_no_code_at_all(): void
    {
        // Additive means ABSENT when there is nothing machine-readable to say — a consumer must be able to
        // treat "has a code" as meaningful rather than reading a catch-all.
        $session = $this->videoSession();

        // A beat with no visual to draw fails without ever reaching a provider.
        $this->fakeShotList([false]);
        ShotListAgent::fake(fn () => json_encode(['hook' => 'H', 'cta' => 'C', 'shots' => [
            ['visual' => '', 'voiceover' => 'vo', 'seconds' => 3],
        ]]));
        $this->captureGenerates($generates);

        $session = $this->runWholeSession($session);

        $shot = $session->results['storyboard']['shots'][0];
        $this->assertSame('failed', $shot['image_status']);
        $this->assertArrayNotHasKey('image_error_code', $shot);
    }

    /**
     * The identity is composed into an ALREADY-RESOLVED prompt string, never resolved as one. A descriptor
     * carrying an `@[ai-text]` directive must therefore be DRAWN, never executed — otherwise a bot's own
     * profile text would be an arbitrary, billed AI call inside every image of every session it authors.
     */
    public function test_a_directive_inside_the_descriptor_is_drawn_never_executed(): void
    {
        $directive = TemplateFactory::directive('slots.topic');
        $session = $this->videoSession();
        $this->delegate($this->bot(visual: ['descriptor' => 'A person ' . $directive, 'wardrobe' => null]), $session);

        $this->fakeShotList([true]);
        $this->fakeEdit();

        AiTextAgent::fake(fn () => 'SHOULD NEVER BE CALLED');

        $session = $this->runWholeSession($session);

        $body = $this->editBodies()[0];
        // The raw directive text reached the provider verbatim; nothing expanded it.
        $this->assertStringContainsString('A person', $body);
        $this->assertStringNotContainsString('pets', $body);
        $this->assertSame('ok', $session->results['storyboard']['shots'][0]['image_status']);
    }

    // ---- the byte-identity pins ------------------------------------------------

    /** The literal composed base prompt of a two-shot storyboard, as it has always been. */
    private function untouchedStoryboardPrompts(): array
    {
        return [
            "Frame 1 of 2 from the SAME film/production — consistent world, palette, medium, lighting and subject across all frames.\n\nflat vector\n\nvisual 0",
            "Frame 2 of 2 from the SAME film/production — consistent world, palette, medium, lighting and subject across all frames.\n\nflat vector\n\nvisual 1",
        ];
    }

    public function test_an_undelegated_session_composes_exactly_the_prompts_it_always_did(): void
    {
        $this->fakeShotList([true, true]); // even flagged shots change nothing — there is no character
        $this->captureGenerates($generates);
        $this->fakeEdit();

        $this->runWholeSession($this->videoSession());

        $this->assertSame($this->untouchedStoryboardPrompts(), $generates);
        $this->assertSame([], $this->editBodies());
    }

    public function test_a_delegation_without_a_likeness_composes_exactly_the_prompts_it_always_did(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(withLikeness: false), $session);

        $this->fakeShotList([true, true]);
        $this->captureGenerates($generates);
        $this->fakeEdit();

        $this->runWholeSession($session);

        $this->assertSame($this->untouchedStoryboardPrompts(), $generates);
        $this->assertSame([], $this->editBodies());
    }

    public function test_the_kill_switch_restores_the_prompts_byte_for_byte(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(), $session);

        config()->set('generator.visual_identity.enabled', false);

        $this->fakeShotList([true, true]);
        $this->captureGenerates($generates);
        $this->fakeEdit();

        $this->runWholeSession($session);

        $this->assertSame($this->untouchedStoryboardPrompts(), $generates);
        $this->assertSame([], $this->editBodies());
    }

    /**
     * A shot list stored BEFORE the flag existed carries no `features_character` at all. Regenerating one of
     * its frames must read that as "no character in this frame" — the pre-feature rendering — rather than
     * putting a person into a beat nobody asked to have one.
     */
    public function test_a_stored_shot_list_without_the_flag_regenerates_as_a_plain_generation(): void
    {
        $session = $this->videoSession();
        $this->delegate($this->bot(), $session);

        $session->update([
            'status' => GenerationSessionStatus::Ready,
            'results' => [
                'shot_list' => ['kind' => 'shot_list', 'status' => 'ok', 'hook' => 'H', 'cta' => 'C', 'text' => 't', 'parse_ok' => true, 'version' => 1, 'shots' => [
                    ['visual' => 'legacy visual', 'voiceover' => 'vo', 'seconds' => 3],
                ]],
                'storyboard' => ['kind' => 'storyboard', 'status' => 'ok', 'shots' => [
                    ['index' => 0, 'visual' => 'legacy visual', 'voiceover' => 'vo', 'seconds' => 3, 'image_status' => 'failed', 'image_error' => 'x'],
                ]],
            ],
        ]);

        $this->captureGenerates($generates);
        $this->fakeEdit();

        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session->fresh(), GenerationRunMode::Regenerate, 'storyboard.0');
        (new RunGenerationSessionJob($session->id, $this->workspace->id, GenerationRunMode::Regenerate->value, 'storyboard.0'))
            ->handle(app(GenerationSessionRunManager::class));

        $this->assertSame([], $this->editBodies());
        $this->assertCount(1, $generates);
        $this->assertStringNotContainsString('Recurring subject', $generates[0]);
    }

    // ---- wire hygiene ----------------------------------------------------------

    public function test_the_in_flight_frame_bookkeeping_never_reaches_the_wire(): void
    {
        $session = $this->videoSession();

        $this->fakeShotList([false, false]);

        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, GenerationRunMode::Full);
        (new RunGenerationSessionJob($session->id, $this->workspace->id, GenerationRunMode::Full->value))
            ->handle(app(GenerationSessionRunManager::class));

        // The frames are announced but NOT drained: the row genuinely holds the transient keys right now.
        $stored = $session->fresh()->results['storyboard']['shots'][0];
        $this->assertArrayHasKey('frame_token', $stored);

        $response = $this->getJson("/api/generator/sessions/{$session->id}")->assertOk();

        $shot = $response->json('data.results.storyboard.shots.0');
        $this->assertSame('pending', $shot['image_status']);
        $this->assertArrayNotHasKey('frame_token', $shot);
        $this->assertArrayNotHasKey('frame_claimed_at', $shot);
    }
}
