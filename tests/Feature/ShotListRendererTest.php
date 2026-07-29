<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\ShotListRenderer;
use App\Modules\Generator\Support\CreativeDirection;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * The STRUCTURED shot_list seam (video_script rework Phase B): the {@see ShotListRenderer}'s defensive
 * prompt-and-parse (native structured output exists in laravel/ai but we route through the metered ai-text
 * seam + parse — see the SPIKE note on {@see ShotListAgent}), and the executor's shot_list part (an `ok`
 * structured result / a fail-soft on a blank model reply / a slot-carrying brief / a metered+budgeted call)
 * and the shot_list REFINE (a directed structured revision; a blank/unparseable revision is a no-op).
 *
 * Setup mirrors GenerationSessionGenerateTest: a real workspace + active tenancy so the metered ai-text seam
 * records the session-tagged rows the budget/meter assertions read.
 */
class ShotListRendererTest extends TestCase
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

        // Creative-direction layer OFF by DEFAULT here: most cases pin run behavior that PREDATES it, and an
        // un-scripted derivation would be a REAL provider call. The cases that exercise the layer turn it on
        // and script CreativeDirectionAgent explicitly (see the "creative direction" section below).
        config()->set('generator.direction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    /** A one-shot valid shot_list JSON object as the model would return it. */
    private function shotListJson(string $hook = 'Watch this', int $seconds = 3): string
    {
        return json_encode([
            'hook' => $hook,
            'shots' => [['visual' => 'A cat waves', 'voiceover' => 'Meet the cat', 'seconds' => $seconds]],
            'cta' => 'Follow for more',
        ]);
    }

    private function renderer(): ShotListRenderer
    {
        return app(ShotListRenderer::class);
    }

    /** Raw PNG bytes (mirrors StoryboardTest) so a faked storyboard image is a real decodable blob. */
    private function png(int $w, int $h, array $rgb): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /** A ready video_script session with a stored ok shot_list result at $version. */
    private function readyShotListSession(array $shotListResult, int $version = 1): GenerationSession
    {
        return GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            ], [$this->topicSlot()], ['topic' => 'cats'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => ['shot_list' => $shotListResult + ['kind' => 'shot_list', 'status' => 'ok', 'version' => $version]],
            ]);
    }

    private function claimAndRun(GenerationSession $session, GenerationRunMode $mode, string $partKey, ?string $instruction = null): GenerationSession
    {
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, $mode, $partKey, $instruction);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, $mode->value, $partKey, $instruction))
            ->handle(app(GenerationSessionRunManager::class));

        return $session->fresh();
    }

    // ---- renderer parse matrix -------------------------------------------------

    public function test_a_json_object_normalizes_to_hook_shots_cta_and_a_readable_text(): void
    {
        ShotListAgent::fake(fn () => $this->shotListJson('Hook line', 4));

        $result = $this->renderer()->generate('a brief', 5);

        $this->assertTrue($result['parse_ok']);
        $this->assertSame('Hook line', $result['hook']);
        $this->assertSame('Follow for more', $result['cta']);
        $this->assertCount(1, $result['shots']);
        $this->assertSame('A cat waves', $result['shots'][0]['visual']);
        $this->assertSame(4, $result['shots'][0]['seconds']);
        // The readable flattening carries the hook + the beat + the cta.
        $this->assertStringContainsString('Hook line', $result['text']);
        $this->assertStringContainsString('Shot 1: A cat waves / Meet the cat (4s)', $result['text']);
        $this->assertStringContainsString('Follow for more', $result['text']);
    }

    public function test_a_json_fenced_object_is_parsed(): void
    {
        ShotListAgent::fake(fn () => "```json\n" . $this->shotListJson() . "\n```");

        $result = $this->renderer()->generate('a brief', 5);

        $this->assertTrue($result['parse_ok']);
        $this->assertCount(1, $result['shots']);
    }

    public function test_a_non_blank_non_json_reply_is_parse_ok_false_with_the_raw_text_and_no_shots(): void
    {
        ShotListAgent::fake(fn () => 'Sorry, I could not do that.');

        $result = $this->renderer()->generate('a brief', 5);

        $this->assertFalse($result['parse_ok']);
        $this->assertSame('Sorry, I could not do that.', $result['text']);
        $this->assertSame([], $result['shots']);
    }

    public function test_a_blank_reply_returns_null(): void
    {
        ShotListAgent::fake(fn () => '   ');

        $this->assertNull($this->renderer()->generate('a brief', 5));
    }

    public function test_shots_are_clamped_to_the_effective_cap_and_malformed_entries_dropped_and_seconds_coerced(): void
    {
        // The clamp is now the EFFECTIVE cap the caller passes (the executor's single source of truth) —
        // not a config read inside the renderer, so the instruction bound and this clamp cannot drift.
        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [
                ['visual' => 'one', 'voiceover' => 'v1', 'seconds' => '5'],   // seconds as a numeric string → 5
                ['visual' => 'two', 'voiceover' => 'v2'],                       // no seconds → 0
                ['visual' => 'three', 'voiceover' => 'v3', 'seconds' => 3],     // over the clamp of 2 → dropped
                ['voiceover' => 'no visual'],                                   // malformed → dropped
            ],
            'cta' => 'C',
        ]));

        $result = $this->renderer()->generate('a brief', 2);

        $this->assertCount(2, $result['shots']);
        $this->assertSame(5, $result['shots'][0]['seconds']);
        $this->assertSame(0, $result['shots'][1]['seconds']);
        $this->assertSame('two', $result['shots'][1]['visual']);
    }

    public function test_a_bare_top_level_array_reply_is_parsed_as_the_shots_list(): void
    {
        // A plausible model deviation from the {hook,shots,cta} object contract: a BARE top-level JSON ARRAY.
        // The object-scanner would grab the first inner {…} and read ONE shot as the root (silently shots=[]);
        // the bare-array escape hatch instead normalizes the whole list as the shots.
        ShotListAgent::fake(fn () => json_encode([
            ['visual' => 'A cat waves', 'voiceover' => 'Meet the cat', 'seconds' => 3],
            ['visual' => 'A dog barks', 'voiceover' => 'And the dog', 'seconds' => 2],
        ]));

        $result = $this->renderer()->generate('a brief', 5);

        $this->assertTrue($result['parse_ok']);
        $this->assertSame('', $result['hook']);
        $this->assertSame('', $result['cta']);
        $this->assertCount(2, $result['shots']);
        $this->assertSame('A cat waves', $result['shots'][0]['visual']);
        $this->assertSame(3, $result['shots'][0]['seconds']);
        $this->assertStringContainsString('Shot 1: A cat waves / Meet the cat (3s)', $result['text']);
    }

    public function test_a_bare_array_of_non_shot_junk_is_parse_ok_false_with_the_raw_text(): void
    {
        // A bare array that is NOT shots (no valid visual/voiceover entries) yields zero shots → the reply is
        // treated as non-blank-unparseable (raw kept), NEVER a silent parse_ok:true with shots=[].
        ShotListAgent::fake(fn () => '["just", "some", "strings"]');

        $result = $this->renderer()->generate('a brief', 5);

        $this->assertFalse($result['parse_ok']);
        $this->assertSame([], $result['shots']);
        $this->assertSame('["just", "some", "strings"]', $result['text']);
    }

    // ---- executeShotListPart (via a real run) ----------------------------------

    public function test_a_whole_run_renders_an_ok_structured_shot_list_and_meters_one_ai_text_call(): void
    {
        ShotListAgent::fake(fn () => $this->shotListJson('Big hook'));
        // The snapshot omits the storyboard CONTENT, but video_script's definition still declares the storyboard
        // part, so it renders and fans out one image over the shot_list's shot. Fake the image provider + storage
        // so that fan-out never hits a real provider/disk (it is incidental here — only the shot_list is asserted).
        Storage::fake();
        Image::fake([base64_encode($this->png(6, 6, [10, 20, 30]))]);

        // A video_script recipe authoring ONLY the shot_list brief (no storyboard style/filters).
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            ], [$this->topicSlot()], ['topic' => 'widgets'])
            ->create(['creator_id' => $this->user->id]);

        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['shot_list']['status']);
        $this->assertSame('shot_list', $session->results['shot_list']['kind']);
        $this->assertSame('Big hook', $session->results['shot_list']['hook']);
        $this->assertSame(1, $session->results['shot_list']['version']);
        $this->assertTrue($session->results['shot_list']['parse_ok']);

        // Metered as ONE ai_text call, session-tagged.
        $this->assertSame(1, AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->count());
    }

    public function test_a_brief_resolves_its_slot_before_the_model_sees_it(): void
    {
        $captured = null;
        ShotListAgent::fake(function (string $prompt) use (&$captured) {
            $captured = $prompt;

            return $this->shotListJson();
        });

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));

        // The model saw the RESOLVED brief (slot substituted), never the raw directive markdown.
        $this->assertNotNull($captured);
        $this->assertStringContainsString('Espresso', $captured);
        $this->assertStringNotContainsString('slots.topic', $captured);
    }

    public function test_a_blank_model_reply_fails_the_shot_list_part_soft(): void
    {
        ShotListAgent::fake(fn () => '');

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            ], [$this->topicSlot()], ['topic' => 'x'])
            ->create(['creator_id' => $this->user->id]);

        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('failed', $session->results['shot_list']['status']);
        $this->assertSame(__('generator.sessions.part_failed'), $session->results['shot_list']['error']);
    }

    public function test_regenerating_the_shot_list_bumps_the_version(): void
    {
        ShotListAgent::fake(fn () => $this->shotListJson('Fresh hook'));

        $session = $this->readyShotListSession(['hook' => 'Old hook', 'shots' => [], 'cta' => '', 'text' => 'Old', 'parse_ok' => true], 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'shot_list');

        $this->assertSame('Fresh hook', $session->results['shot_list']['hook']);
        $this->assertSame(2, $session->results['shot_list']['version']);
        // The prior is pushed to history.
        $this->assertSame('Old hook', $session->history['shot_list'][0]['hook']);
    }

    // ---- shot_list refine ------------------------------------------------------

    public function test_refining_the_shot_list_returns_a_new_structured_list(): void
    {
        $captured = null;
        ShotListAgent::fake(function (string $prompt) use (&$captured) {
            $captured = $prompt;

            return $this->shotListJson('Punchier hook');
        });

        $session = $this->readyShotListSession(['hook' => 'Bland hook', 'shots' => [['visual' => 'v', 'voiceover' => 'vo', 'seconds' => 2]], 'cta' => 'c', 'text' => 'Bland', 'parse_ok' => true], 1);
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'shot_list', 'make the hook punchier');

        // The instruction + the current list rode the revise prompt as DATA.
        $this->assertStringContainsString('make the hook punchier', $captured);
        $this->assertStringContainsString('Bland hook', $captured);

        $this->assertSame('Punchier hook', $session->results['shot_list']['hook']);
        $this->assertSame(2, $session->results['shot_list']['version']);
        $this->assertSame('Bland hook', $session->history['shot_list'][0]['hook']);
    }

    public function test_a_blank_or_unparseable_shot_list_revision_is_a_failed_no_op(): void
    {
        // An unparseable (non-JSON) revision must NOT clobber the good current list, and must not churn history.
        ShotListAgent::fake(fn () => 'I cannot revise that.');

        $session = $this->readyShotListSession(['hook' => 'KEEP ME', 'shots' => [], 'cta' => '', 'text' => 'KEEP ME', 'parse_ok' => true], 3);
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'shot_list', 'do something');

        $this->assertSame('KEEP ME', $session->results['shot_list']['hook']);
        $this->assertSame(3, $session->results['shot_list']['version']);
        $this->assertArrayNotHasKey('shot_list', $session->history ?? []);
        $this->assertSame('failed', $session->last_op_status);
        $this->assertSame(__('generator.sessions.part_failed'), $session->last_op_error);
    }

    // ---- creative direction (the renderer seam) --------------------------------

    public function test_the_renderer_threads_the_effective_cap_into_the_agent_instruction(): void
    {
        // The cap the CALLER passes is what the agent is instructed with — the renderer reads no config of
        // its own, so the instructed bound, the parse clamp and the storyboard fan-out share ONE value.
        ShotListAgent::fake(fn () => $this->shotListJson());

        $this->renderer()->generate('a brief', 3);

        ShotListAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'between 3 and 3'),
        );
    }

    public function test_a_direction_rides_the_user_message_of_both_generate_and_revise(): void
    {
        $prompts = [];
        ShotListAgent::fake(function (string $prompt) use (&$prompts): string {
            $prompts[] = $prompt;

            return $this->shotListJson();
        });

        $direction = CreativeDirection::fromArray([
            'through_line' => 'A beginner pulls their first good shot',
            'arc_beats' => ['Doubt', 'The good shot'],
            'duration_target_seconds' => 90,
            'goal' => 'Drive trial',
            'visual_style' => ['palette' => 'warm amber'],
        ]);

        $this->renderer()->generate('a brief', 5, $direction);
        $this->renderer()->revise(['hook' => 'H', 'shots' => [], 'cta' => 'C'], 'punchier', 5, $direction);

        // The agent is told a block rides the request ONLY because one actually does.
        ShotListAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'CREATIVE DIRECTION — the request carries'),
        );

        $this->assertCount(2, $prompts);

        foreach ($prompts as $prompt) {
            $this->assertStringContainsString('CREATIVE DIRECTION (data', $prompt);
            $this->assertStringContainsString('THROUGH-LINE: A beginner pulls their first good shot', $prompt);
            $this->assertStringContainsString('TARGET DURATION: 90 seconds total', $prompt);
            // The shot-list projection only: no marketing goal, no palette.
            $this->assertStringNotContainsString('Drive trial', $prompt);
            $this->assertStringNotContainsString('warm amber', $prompt);
        }

        // The author's own brief / instruction still closes the message.
        $this->assertStringEndsWith("CREATIVE BRIEF:\na brief", $prompts[0]);
        $this->assertStringContainsString('punchier', $prompts[1]);
    }

    public function test_no_usable_direction_leaves_the_prompt_byte_identical_and_the_agent_unaware(): void
    {
        $prompts = [];
        ShotListAgent::fake(function (string $prompt) use (&$prompts): string {
            $prompts[] = $prompt;

            return $this->shotListJson();
        });

        // No direction at all...
        $this->renderer()->generate('a brief', 5);

        // ...and a direction whose fields are ALL visual (nothing this consumer projects): the prompt must
        // still be byte-identical, and the agent must NOT be told a block rides the request.
        $this->renderer()->generate('a brief', 5, CreativeDirection::fromArray(['visual_style' => ['palette' => 'warm amber']]));

        $expected = "Write the shot list for this creative brief.\n\nCREATIVE BRIEF:\na brief";
        $this->assertSame([$expected, $expected], $prompts);

        ShotListAgent::assertNotPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'CREATIVE DIRECTION — the request carries'),
        );
    }

    public function test_the_shot_list_is_refinable_but_the_bare_storyboard_is_not(): void
    {
        $session = $this->readyShotListSession(['hook' => 'H', 'shots' => [], 'cta' => '', 'text' => 'H', 'parse_ok' => true], 1);

        // shot_list refine is accepted (claims + queues).
        Queue::fake();
        $this->postJson("/api/generator/sessions/{$session->id}/parts/shot_list/refine", ['instruction' => 'tweak it'])
            ->assertStatus(202);

        // A bare storyboard free-text refine is unsupported → 422.
        $this->postJson("/api/generator/sessions/{$session->id}/parts/storyboard/refine", ['instruction' => 'tweak it'])
            ->assertStatus(422);
    }
}
