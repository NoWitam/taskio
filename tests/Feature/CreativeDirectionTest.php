<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Agents\CreativeDirectionAgent;
use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Support\CreativeDirectionContext;
use App\Modules\Variables\Agents\AiTextAgent;
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
use Laravel\Ai\Prompts\ImagePrompt;
use RuntimeException;
use Tests\TestCase;

/**
 * The CREATIVE-DIRECTION LAYER end to end: a FULL run derives ONE shared creative frame up front and every
 * generation in that run is made to it, so a session yields ONE coherent piece instead of N mutually blind
 * AI calls (the owner's complaint: a flat 15-second "story" for a 1–2 minute brief, plus five storyboard
 * frames that looked like five different films).
 *
 * The TWO highest-risk properties are pinned first and hardest:
 *   1. KILL-SWITCH BYTE-IDENTITY — with `generator.direction.enabled=false` every composed prompt is
 *      byte-identical to a direction-less run, and nothing is derived, read or injected.
 *   2. EXACTLY ONE derivation per FULL run, OUTSIDE the per-session ai-text budget, and ZERO on every
 *      isolated op (regenerate / refine / per-shot), which reuse the STORED direction.
 *
 * Every AI seam here is SCRIPTED (`Agent::fake` / `Image::fake`) — no case may reach a real provider.
 */
class CreativeDirectionTest extends TestCase
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

        // This class is the one that exercises the layer ON (its default), so it must script the derivation
        // agent in EVERY case — an un-scripted one would be a real provider call.
        config()->set('generator.direction.enabled', true);

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures ---------------------------------------------------------------

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    /** An `@[ai-text]("…")` directive exactly as the editor encodes it. */
    private function aiText(string $id, string $prompt): string
    {
        $payload = json_encode(['v' => 1, 'data' => ['id' => $id, 'personaId' => null, 'prompt' => $prompt, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** The direction JSON the scripted director returns. */
    private function directionJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'message' => 'Espresso at home is easier than you think',
            'goal' => 'Drive trial of the starter kit',
            'audience' => 'Curious beginners',
            'tone' => 'Warm and encouraging',
            'through_line' => 'A nervous beginner pulls their first good shot',
            'arc_beats' => ['Doubt', 'A failed pull', 'One fix', 'The good shot'],
            'subject' => 'a nervous beginner in a red apron',
            'setting' => 'a small sunlit kitchen',
            'visual_style' => ['medium' => 'photoreal', 'palette' => 'warm amber', 'lighting' => 'soft morning', 'camera' => 'handheld close-up'],
            'duration_target_seconds' => 90,
            'continuity_notes' => 'the same red apron in every frame',
        ], $overrides));
    }

    /**
     * Script the direction agent, counting calls and capturing the input. Returns a two-element state array
     * by reference-friendly object so a case can assert both.
     */
    private function scriptDirector(?string $reply = null): object
    {
        $state = new class
        {
            public int $calls = 0;

            public ?string $input = null;
        };

        CreativeDirectionAgent::fake(function (string $prompt) use ($state, $reply): string {
            $state->calls++;
            $state->input = $prompt;

            return $reply ?? $this->directionJson();
        });

        return $state;
    }

    private function png(int $w, int $h, array $rgb): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /** A `post` session whose body carries $count inline ai-text blocks. */
    private function postSession(int $blocks = 1): GenerationSession
    {
        $markdown = [];

        for ($i = 1; $i <= $blocks; $i++) {
            $markdown[] = $this->aiText('ai_' . $i, 'Describe part ' . $i);
        }

        return GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => implode(' ', $markdown)]], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);
    }

    /** A video_script session (shot_list brief + storyboard style), optionally with an authored shot cap. */
    private function videoSession(?int $maxShots = null): GenerationSession
    {
        $storyboard = ['style' => ['markdown' => 'flat vector'], 'filters' => []];

        if ($maxShots !== null) {
            $storyboard['max_shots'] = $maxShots;
        }

        return GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A 90 second video about ' . TemplateFactory::directive('slots.topic')]],
                'storyboard' => $storyboard,
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);
    }

    /** A shot-list JSON reply with $count shots. */
    private function shotListJson(int $count): string
    {
        $shots = [];

        for ($i = 0; $i < $count; $i++) {
            $shots[] = ['visual' => 'visual ' . $i, 'voiceover' => 'vo ' . $i, 'seconds' => 10];
        }

        return (string) json_encode(['hook' => 'H', 'shots' => $shots, 'cta' => 'C']);
    }

    private function runJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    private function claimAndRun(GenerationSession $session, GenerationRunMode $mode, ?string $partKey = null, ?string $instruction = null): GenerationSession
    {
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, $mode, $partKey, $instruction);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, $mode->value, $partKey, $instruction))
            ->handle(app(GenerationSessionRunManager::class));

        return $session->fresh();
    }

    /** Capture every prompt the ai-text agent receives. */
    private function captureAiText(string $reply = 'AI OUT'): object
    {
        $state = new class
        {
            /** @var array<int, string> */
            public array $prompts = [];
        };

        AiTextAgent::fake(function (string $prompt) use ($state, $reply): string {
            $state->prompts[] = $prompt;

            return $reply;
        });

        return $state;
    }

    // ---- HIGH-RISK 1: kill-switch byte-identity ---------------------------------

    public function test_the_kill_switch_derives_nothing_and_leaves_every_composed_prompt_byte_identical(): void
    {
        config()->set('generator.direction.enabled', false);

        $director = $this->scriptDirector();
        $text = $this->captureAiText();

        $shotPrompts = [];
        ShotListAgent::fake(function (string $prompt) use (&$shotPrompts): string {
            $shotPrompts[] = $prompt;

            return $this->shotListJson(2);
        });

        $imagePrompts = [];
        Image::fake(function (ImagePrompt $prompt) use (&$imagePrompts): string {
            $imagePrompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        // A recipe exercising ALL THREE injection points at once: an ai-text block, a shot list, storyboard
        // images.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A 90 second video about ' . TemplateFactory::directive('slots.topic')]],
                'storyboard' => ['style' => ['markdown' => 'flat vector'], 'filters' => []],
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);
        $this->runJob($this->postSession(1));

        // NOTHING was derived, nothing was stored, nothing was metered for a direction.
        $this->assertSame(0, $director->calls);
        $this->assertNull($session->fresh()->creative_direction);

        // The ai-text prompt is the RESOLVED PROMPT AND NOTHING ELSE (the pre-direction composition).
        $this->assertSame(['Describe part 1'], $text->prompts);

        // The shot-list prompt is the pre-direction composition, byte for byte.
        $this->assertSame(
            ["Write the shot list for this creative brief.\n\nCREATIVE BRIEF:\nA 90 second video about Espresso"],
            $shotPrompts,
        );

        // Each image prompt is continuity clause + authored style + the shot's visual — no direction anchor.
        $this->assertSame([
            "Frame 1 of 2 from the SAME film/production — consistent world, palette, medium, lighting and subject across all frames.\n\nflat vector\n\nvisual 0",
            "Frame 2 of 2 from the SAME film/production — consistent world, palette, medium, lighting and subject across all frames.\n\nflat vector\n\nvisual 1",
        ], $imagePrompts);
    }

    public function test_the_kill_switch_also_suppresses_an_already_stored_direction_on_an_isolated_op(): void
    {
        config()->set('generator.direction.enabled', false);

        $director = $this->scriptDirector();
        $text = $this->captureAiText('REVISED');

        // A session that ALREADY carries a stored direction from an earlier run.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => 'Body']], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create([
                'creator_id' => $this->user->id,
                'creative_direction' => json_decode($this->directionJson(), true),
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'CURRENT', 'version' => 1]],
            ]);

        $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'punch it up');

        $this->assertSame(0, $director->calls);
        $this->assertCount(1, $text->prompts);
        $this->assertStringNotContainsString('CREATIVE DIRECTION', $text->prompts[0]);
        $this->assertStringStartsWith('Revise the text below according to the instruction.', $text->prompts[0]);
    }

    // ---- HIGH-RISK 2: exactly one derivation per full run ------------------------

    public function test_a_full_run_derives_exactly_one_direction_and_meters_it_as_one_extra_ai_text_call(): void
    {
        $director = $this->scriptDirector();
        $this->captureAiText();

        $session = $this->postSession(1);
        $this->runJob($session);

        $this->assertSame(1, $director->calls);
        // N+1, not N+2: the meta brief is resolved ONCE (the derivation input is the NO-OP preview, so the
        // nested @[ai-text] is a placeholder, not a second billed call).
        $this->assertSame(2, AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->count());
    }

    public function test_the_derivation_is_not_counted_against_the_per_session_ai_text_budget(): void
    {
        // A 4-block recipe on a budget of exactly 4: all FOUR blocks must still resolve live. If the
        // derivation were charged to that budget, the last block would fall to '' (fail-closed).
        config()->set('generator.ai_text_max_calls_per_session', 4);

        $director = $this->scriptDirector();
        $text = $this->captureAiText('OUT');

        $session = $this->postSession(4);
        $this->runJob($session);

        $this->assertSame(1, $director->calls);
        $this->assertCount(4, $text->prompts, 'every authored ai-text block must still reach the provider');
        $this->assertSame('OUT OUT OUT OUT', $session->fresh()->results['body']['text']);

        // 4 content calls + 1 direction call, all session-tagged.
        $this->assertSame(5, AiUsageEvent::where('channel', 'ai_text')->where('session_id', $session->id)->count());
    }

    public function test_a_regenerate_and_a_refine_derive_nothing_and_reuse_the_stored_direction(): void
    {
        $director = $this->scriptDirector();
        $text = $this->captureAiText();

        $session = $this->postSession(1);
        $this->runJob($session);
        $this->assertSame(1, $director->calls);

        // REGENERATE the body: no derivation, and the stored direction still rides the prompt.
        $session = $this->claimAndRun($session->fresh(), GenerationRunMode::Regenerate, 'body');
        $this->assertSame(1, $director->calls, 'a regenerate must never derive');
        $this->assertStringContainsString('CREATIVE DIRECTION (data', end($text->prompts));
        $this->assertStringContainsString('MESSAGE: Espresso at home is easier than you think', end($text->prompts));

        // REFINE the body: same posture.
        $session = $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'punchier');
        $this->assertSame(1, $director->calls, 'a refine must never derive');
        $this->assertStringContainsString('GOAL: Drive trial of the starter kit', end($text->prompts));

        // The direction survived both part-op claims.
        $this->assertSame('Espresso at home is easier than you think', $session->creative_direction['message']);
    }

    public function test_a_per_shot_storyboard_regenerate_derives_nothing_and_reuses_the_stored_anchor(): void
    {
        $director = $this->scriptDirector();
        ShotListAgent::fake(fn () => $this->shotListJson(2));

        $imagePrompts = [];
        Image::fake(function (ImagePrompt $prompt) use (&$imagePrompts): string {
            $imagePrompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        $session = $this->videoSession();
        $this->runJob($session);
        $this->assertSame(1, $director->calls);

        $imagePrompts = [];
        $this->claimAndRun($session->fresh(), GenerationRunMode::Regenerate, 'storyboard.1');

        $this->assertSame(1, $director->calls, 'a per-shot regenerate must never derive');
        $this->assertCount(1, $imagePrompts);
        // The re-shot frame keeps its place in the set AND the same anchor, so it cannot drift in style.
        $this->assertStringContainsString('Frame 2 of 2', $imagePrompts[0]);
        $this->assertStringContainsString('palette — warm amber', $imagePrompts[0]);
        $this->assertStringContainsString('a nervous beginner in a red apron', $imagePrompts[0]);
    }

    // ---- D8: the derivation input is the previewed AUTHORED recipe ---------------

    public function test_the_derivation_input_is_the_no_op_previewed_recipe_plus_the_slot_values(): void
    {
        $director = $this->scriptDirector();
        $this->captureAiText('THE WRITTEN POST');

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', [
                'body' => ['markdown' => 'Intro. ' . $this->aiText('ai_1', 'Write 3 paragraphs about ' . TemplateFactory::directive('slots.topic'))],
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $input = (string) $director->input;

        // The AUTHORED instruction to the writer, with its slot resolved, as an INERT placeholder — this is
        // the NO-OP preview binding, so the nested ai-text was never billed a second time.
        $this->assertStringContainsString('[AI: Write 3 paragraphs about Espresso]', $input);
        $this->assertStringContainsString('Intro.', $input);
        // The filled slot values ride along as a digest.
        $this->assertStringContainsString('topic: Espresso', $input);
        // ...and NOT the generated output (which does not exist yet when the direction is derived).
        $this->assertStringNotContainsString('THE WRITTEN POST', $input);
    }

    public function test_a_legacy_snapshot_derives_from_its_own_authored_body_not_from_the_slot_digest_alone(): void
    {
        $director = $this->scriptDirector();
        $this->captureAiText();

        // A PRE-Phase-B `video_script` snapshot still carries `script` + `scene_plan`, which the LIVE
        // registry no longer composes. The executor is SNAPSHOT-authoritative, so those legacy parts DO
        // run — the derivation input must follow the same snapshot. Otherwise the recipe renders EMPTY and
        // the one BILLED derivation sees nothing but `topic: Espresso`, and (its own rule being "invent
        // where the recipe is silent") INVENTS a whole frame that is then persisted and injected as BINDING
        // into a legacy run: a behavior regression paid for in real spend.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'script' => ['markdown' => 'A 90 second script about ' . TemplateFactory::directive('slots.topic')],
                'scene_plan' => ['scenes' => [['narration' => ['markdown' => 'Open on the chrome portafilter']]]],
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $input = (string) $director->input;

        $this->assertStringContainsString('A 90 second script about Espresso', $input);
        $this->assertStringContainsString('Open on the chrome portafilter', $input);
        $this->assertStringContainsString('topic: Espresso', $input);

        // ...and the legacy run still renders its own parts, direction in hand.
        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame(['script', 'scene_plan'], array_keys($session->results));
    }

    public function test_an_empty_recipe_derives_nothing_at_all_rather_than_inventing_a_frame_from_the_slot_digest(): void
    {
        $director = $this->scriptDirector();
        $this->captureAiText();

        // NOTHING is authored, so there is no recipe to direct: a derivation could only INVENT a binding
        // frame out of the slot digest. It must not be attempted — and therefore never billed.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => '']], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $this->assertSame(0, $director->calls);
        $this->assertNull($session->fresh()->creative_direction);
        $this->assertSame(0, AiUsageEvent::where('session_id', $session->id)->count());
        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);
    }

    public function test_the_slot_digest_excludes_file_slots_and_non_scalars(): void
    {
        $director = $this->scriptDirector();
        $this->captureAiText();

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post', ['body' => ['markdown' => 'Body']], [
                $this->topicSlot(),
                ['name' => 'photo', 'descriptor' => TemplateFactory::fileDescriptor()],
            ], [
                'topic' => 'Espresso',
                'photo' => 'file-uuid-should-not-be-here',
                'extras' => ['nested' => 'structure'],
            ])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $input = (string) $director->input;

        $this->assertStringContainsString('topic: Espresso', $input);
        $this->assertStringNotContainsString('file-uuid-should-not-be-here', $input);
        $this->assertStringNotContainsString('nested', $input);
    }

    // ---- injection points (D7: the USER message only) ---------------------------

    public function test_every_ai_text_block_in_one_post_carries_the_direction(): void
    {
        $this->scriptDirector();
        $text = $this->captureAiText();

        $this->runJob($this->postSession(2));

        $this->assertCount(2, $text->prompts);

        foreach ($text->prompts as $i => $prompt) {
            $this->assertStringContainsString('CREATIVE DIRECTION (data', $prompt, 'block ' . $i);
            $this->assertStringContainsString('THROUGH-LINE: A nervous beginner pulls their first good shot', $prompt);
            // The direction is a PREFIX, and the author's own resolved prompt still ends the message.
            $this->assertStringEndsWith('Describe part ' . ($i + 1), $prompt);
            // No visual direction in a body prompt.
            $this->assertStringNotContainsString('warm amber', $prompt);
        }
    }

    public function test_the_shot_list_prompt_carries_the_shot_list_projection_including_the_target_duration(): void
    {
        $this->scriptDirector();

        $shotPrompts = [];
        ShotListAgent::fake(function (string $prompt) use (&$shotPrompts): string {
            $shotPrompts[] = $prompt;

            return $this->shotListJson(2);
        });
        Image::fake(fn () => base64_encode($this->png(6, 6, [1, 2, 3])));

        $this->runJob($this->videoSession());

        $this->assertCount(1, $shotPrompts);
        $this->assertStringContainsString('CREATIVE DIRECTION (data', $shotPrompts[0]);
        $this->assertStringContainsString('TARGET DURATION: 90 seconds total', $shotPrompts[0]);
        $this->assertStringContainsString("ARC BEATS:\n- Doubt", $shotPrompts[0]);
        $this->assertStringContainsString('SUBJECT: a nervous beginner in a red apron', $shotPrompts[0]);
        // The author's brief still follows the block.
        $this->assertStringEndsWith("CREATIVE BRIEF:\nA 90 second video about Espresso", $shotPrompts[0]);
        // No marketing framing in the shot-list projection.
        $this->assertStringNotContainsString('GOAL:', $shotPrompts[0]);
    }

    public function test_a_shot_list_refine_stays_inside_the_stored_direction(): void
    {
        $this->scriptDirector();

        $shotPrompts = [];
        ShotListAgent::fake(function (string $prompt) use (&$shotPrompts): string {
            $shotPrompts[] = $prompt;

            return $this->shotListJson(2);
        });
        Image::fake(fn () => base64_encode($this->png(6, 6, [1, 2, 3])));

        $session = $this->videoSession();
        $this->runJob($session);

        $shotPrompts = [];
        $this->claimAndRun($session->fresh(), GenerationRunMode::Refine, 'shot_list', 'make the hook punchier');

        $this->assertCount(1, $shotPrompts);
        $this->assertStringContainsString('TARGET DURATION: 90 seconds total', $shotPrompts[0]);
        $this->assertStringContainsString('make the hook punchier', $shotPrompts[0]);
    }

    public function test_every_storyboard_frame_carries_the_continuity_clause_and_the_same_anchor(): void
    {
        $this->scriptDirector();
        ShotListAgent::fake(fn () => $this->shotListJson(3));

        $imagePrompts = [];
        Image::fake(function (ImagePrompt $prompt) use (&$imagePrompts): string {
            $imagePrompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        $this->runJob($this->videoSession());

        $this->assertCount(3, $imagePrompts);

        foreach ($imagePrompts as $i => $prompt) {
            // The per-frame continuity clause leads, and it knows its place in the set.
            $this->assertStringStartsWith('Frame ' . ($i + 1) . ' of 3 from the SAME film/production', $prompt);
            // The SAME art-direction anchor + recurring subject in EVERY frame (the five-different-films fix).
            $this->assertStringContainsString('Consistent art direction across all frames: medium — photoreal; palette — warm amber', $prompt);
            $this->assertStringContainsString('Recurring subject, identical in every frame: a nervous beginner in a red apron', $prompt);
            $this->assertStringContainsString('Continuity: the same red apron in every frame', $prompt);
            // The authored style still comes AFTER the derived anchor (an author can override it) and the
            // shot's own visual is last.
            $this->assertLessThan(strpos($prompt, 'flat vector'), strpos($prompt, 'palette — warm amber'));
            $this->assertStringEndsWith('visual ' . $i, $prompt);
            // Never the marketing fields — an image model would draw them.
            $this->assertStringNotContainsString('Drive trial', $prompt);
        }

        // The composed base is DATA to draw: it reaches the provider verbatim (never round-tripped through
        // the directive resolver), which is the identity-resolver posture.
        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === $imagePrompts[0]);
    }

    public function test_a_post_with_image_run_anchors_its_image_to_the_same_direction_as_its_body(): void
    {
        // THE content type whose whole point is "a post AND a matching image". Without the anchor the two
        // halves are mutually blind — exactly the incoherence this layer exists to remove.
        $this->scriptDirector();
        $text = $this->captureAiText();

        $imagePrompts = [];
        Image::fake(function (ImagePrompt $prompt) use (&$imagePrompts): string {
            $imagePrompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post_with_image', [
                'body' => ['markdown' => $this->aiText('ai_1', 'Write the post about ' . TemplateFactory::directive('slots.topic'))],
                'image' => [
                    'base' => ['kind' => 'ai_generate', 'prompt' => 'a product shot of ' . TemplateFactory::directive('slots.topic')],
                    'filters' => [],
                ],
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $this->assertCount(1, $imagePrompts);

        // The image carries the SAME visual frame the body was written to...
        $this->assertStringContainsString('Consistent art direction across all frames: medium — photoreal; palette — warm amber', $imagePrompts[0]);
        $this->assertStringContainsString('Recurring subject, identical in every frame: a nervous beginner in a red apron', $imagePrompts[0]);
        // ...AHEAD of the authored base prompt, which still resolves its slots and still has the last word.
        $this->assertLessThan(strpos($imagePrompts[0], 'a product shot of Espresso'), strpos($imagePrompts[0], 'palette — warm amber'));
        $this->assertStringEndsWith('a product shot of Espresso', $imagePrompts[0]);
        // Never a marketing field — an image model would draw it as words.
        $this->assertStringNotContainsString('Drive trial', $imagePrompts[0]);

        // ...and the body of the same run was made to that same frame (the two halves are no longer blind).
        $this->assertStringContainsString('MESSAGE: Espresso at home is easier than you think', $text->prompts[0]);
    }

    public function test_a_directive_looking_subject_is_inert_in_a_plain_image_base_too(): void
    {
        // The identity-resolver posture must hold for the plain image path exactly as for the storyboard:
        // the closure compares against the FINAL composed string, so a derived subject that LOOKS like an
        // authoring directive reaches the provider verbatim instead of being expanded.
        $this->scriptDirector($this->directionJson([
            'subject' => 'a barista holding ' . TemplateFactory::directive('slots.topic'),
        ]));
        $this->captureAiText();

        $imagePrompts = [];
        Image::fake(function (ImagePrompt $prompt) use (&$imagePrompts): string {
            $imagePrompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('post_with_image', [
                'body' => ['markdown' => 'Body'],
                'image' => ['base' => ['kind' => 'ai_generate', 'prompt' => 'a shot of ' . TemplateFactory::directive('slots.topic')], 'filters' => []],
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $this->assertCount(1, $imagePrompts);
        $this->assertStringContainsString('@[variable]', $imagePrompts[0], 'the derived directive must ride as inert DATA');
        $this->assertStringNotContainsString('holding Espresso', $imagePrompts[0]);
        // The AUTHORED prompt is still resolved normally — only the composed base is identity-resolved.
        $this->assertStringEndsWith('a shot of Espresso', $imagePrompts[0]);
    }

    public function test_a_scene_plan_image_carries_the_direction_anchor_too(): void
    {
        $this->scriptDirector();
        $this->captureAiText();

        $imagePrompts = [];
        Image::fake(function (ImagePrompt $prompt) use (&$imagePrompts): string {
            $imagePrompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        // A legacy snapshot's scene images are produced by the SAME chain, so they take the SAME anchor.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', [
                'script' => ['markdown' => 'A 90 second script about ' . TemplateFactory::directive('slots.topic')],
                'scene_plan' => ['scenes' => [[
                    'narration' => ['markdown' => 'Open on the chrome portafilter'],
                    'image_plan' => ['base' => ['kind' => 'ai_generate', 'prompt' => 'the portafilter'], 'filters' => []],
                ]]],
            ], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $this->assertCount(1, $imagePrompts);
        $this->assertStringContainsString('Consistent art direction across all frames: medium — photoreal', $imagePrompts[0]);
        $this->assertStringContainsString('Recurring subject, identical in every frame: a nervous beginner in a red apron', $imagePrompts[0]);
        $this->assertStringEndsWith('the portafilter', $imagePrompts[0]);
        $this->assertSame('ok', $session->fresh()->results['scene_plan']['scenes'][0]['image_status']);
    }

    public function test_a_directive_looking_subject_in_the_direction_is_never_re_resolved(): void
    {
        // The identity-resolver posture under adversarial input: a derived subject that LOOKS like an
        // authoring directive must reach the image provider verbatim, never expanded by the resolver.
        $this->scriptDirector($this->directionJson([
            'subject' => 'a barista holding ' . TemplateFactory::directive('slots.topic'),
        ]));
        ShotListAgent::fake(fn () => $this->shotListJson(1));

        $imagePrompts = [];
        Image::fake(function (ImagePrompt $prompt) use (&$imagePrompts): string {
            $imagePrompts[] = $prompt->prompt;

            return base64_encode($this->png(6, 6, [1, 2, 3]));
        });

        $this->runJob($this->videoSession());

        $this->assertCount(1, $imagePrompts);
        $this->assertStringContainsString('@[variable]', $imagePrompts[0], 'the directive must ride as inert DATA');
        $this->assertStringNotContainsString('holding Espresso', $imagePrompts[0]);
    }

    // ---- voice composition -------------------------------------------------------

    public function test_a_delegated_run_drops_the_direction_tone_so_the_bot_voice_wins(): void
    {
        $this->scriptDirector();
        $text = $this->captureAiText();

        $session = $this->postSession(1);
        $session->update([
            'bot_author_id' => (string) \Illuminate\Support\Str::uuid(),
            'bot_delegation' => ['voice' => 'ALWAYS SPEAK LIKE A PIRATE', 'author' => ['id' => 'b', 'name' => 'Bot', 'icon' => null]],
        ]);

        $this->runJob($session);

        $this->assertCount(1, $text->prompts);
        // The rest of the frame still rides the prompt...
        $this->assertStringContainsString('MESSAGE: Espresso at home is easier', $text->prompts[0]);
        // ...but the tone does not compete with the bot's voice (which lives in the system instruction).
        $this->assertStringNotContainsString('TONE:', $text->prompts[0]);
        $this->assertStringNotContainsString('Warm and encouraging', $text->prompts[0]);
    }

    // ---- fail-soft ----------------------------------------------------------------

    public function test_a_blank_derivation_leaves_the_run_identical_to_a_direction_less_run(): void
    {
        $director = $this->scriptDirector('');
        $text = $this->captureAiText();

        $session = $this->postSession(1);
        $this->runJob($session);

        $this->assertSame(1, $director->calls);
        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('AI OUT', $session->results['body']['text']);
        $this->assertNull($session->creative_direction);
        $this->assertSame(['Describe part 1'], $text->prompts, 'the prompt must be byte-identical to a direction-less run');
    }

    public function test_an_unparseable_or_empty_object_derivation_is_fail_soft(): void
    {
        foreach (['I cannot do that.', '{}', '{"unknown_key": "x"}', 'not json {oops'] as $reply) {
            $director = $this->scriptDirector($reply);
            $text = $this->captureAiText();

            $session = $this->postSession(1);
            $this->runJob($session);

            $this->assertSame(1, $director->calls, $reply);
            $session->refresh();
            $this->assertSame(GenerationSessionStatus::Ready, $session->status, $reply);
            $this->assertNull($session->creative_direction, $reply);
            $this->assertSame(['Describe part 1'], $text->prompts, $reply);
        }
    }

    public function test_a_throwing_provider_on_the_derivation_is_fail_soft(): void
    {
        CreativeDirectionAgent::fake(function (): string {
            throw new RuntimeException('provider exploded');
        });
        $text = $this->captureAiText();

        $session = $this->postSession(1);
        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('AI OUT', $session->results['body']['text']);
        $this->assertNull($session->creative_direction);
        $this->assertSame(['Describe part 1'], $text->prompts);
    }

    // ---- the budget gate ----------------------------------------------------------

    public function test_an_over_cap_workspace_derives_nothing_and_the_run_proceeds_direction_less(): void
    {
        $this->workspace->update(['ai_monthly_cost_cap' => 1.00]);
        app(TenantContext::class)->set($this->workspace);
        AiUsageEvent::create(['channel' => 'ai_text', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'estimated_cost' => 1.50]);

        $director = $this->scriptDirector();
        $text = $this->captureAiText();

        $session = $this->postSession(1);
        $this->runJob($session);

        // GATE BEFORE SPEND: neither the derivation nor the content call reached a provider.
        $this->assertSame(0, $director->calls, 'the derivation must be gated before spend');
        $this->assertSame([], $text->prompts);
        $this->assertSame(0, AiUsageEvent::where('session_id', $session->id)->count());

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertNull($session->creative_direction);
    }

    // ---- lifecycle / persistence / wire ------------------------------------------

    public function test_the_direction_is_persisted_normalized_and_emitted_on_the_resource(): void
    {
        $this->scriptDirector($this->directionJson(['ROGUE' => 'dropped', 'duration_target_seconds' => 999999]));
        $this->captureAiText();

        $session = $this->postSession(1);
        $this->runJob($session);

        $stored = $session->fresh()->creative_direction;
        $this->assertIsArray($stored);
        $this->assertArrayNotHasKey('ROGUE', $stored);
        $this->assertNull($stored['duration_target_seconds'], 'an out-of-range duration is dropped by the normalizer');

        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.creative_direction.message', 'Espresso at home is easier than you think')
            ->assertJsonPath('data.creative_direction.subject', 'a nervous beginner in a red apron')
            ->assertJsonPath('data.creative_direction.visual_style.palette', 'warm amber');
    }

    public function test_a_full_claim_nulls_the_direction_while_a_part_op_claim_preserves_it(): void
    {
        Queue::fake();

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => 'Body']], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create([
                'creator_id' => $this->user->id,
                'creative_direction' => json_decode($this->directionJson(), true),
                'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'CURRENT', 'version' => 1]],
            ]);

        // A PART-OP claim preserves it (a coherent refine).
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, GenerationRunMode::Regenerate, 'body');
        $this->assertIsArray($session->fresh()->creative_direction);

        // A FULL claim nulls it (the next run derives a fresh frame).
        $session->update(['status' => GenerationSessionStatus::Ready]);
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, GenerationRunMode::Full);
        $this->assertNull($session->fresh()->creative_direction);
    }

    public function test_a_re_delivered_full_run_reuses_the_stored_direction_instead_of_paying_twice(): void
    {
        $director = $this->scriptDirector();
        $this->captureAiText();

        $session = $this->postSession(1);
        $this->runJob($session);
        $this->assertSame(1, $director->calls);

        // The SAME claim runs again (an at-least-once redelivery that got past the lock): the direction is
        // read from the column, never re-derived.
        $session->update(['status' => GenerationSessionStatus::Generating]);
        $this->runJob($session->fresh());

        $this->assertSame(1, $director->calls);
    }

    // ---- ambient context hygiene --------------------------------------------------

    public function test_the_ambient_direction_is_cleared_after_every_entry_point(): void
    {
        $this->scriptDirector();
        $this->captureAiText();

        $context = app(CreativeDirectionContext::class);

        $session = $this->postSession(1);
        $this->runJob($session);
        $this->assertNull($context->direction(), 'the full run must clear the ambient direction in its finally');

        $session = $this->claimAndRun($session->fresh(), GenerationRunMode::Regenerate, 'body');
        $this->assertNull($context->direction(), 'a regenerate must clear it too');

        $this->claimAndRun($session, GenerationRunMode::Refine, 'body', 'punchier');
        $this->assertNull($context->direction(), 'a refine must clear it too');
    }

    // ---- adaptive shot cap ---------------------------------------------------------

    public function test_an_authored_max_shots_bounds_the_instruction_the_parse_and_the_fan_out(): void
    {
        $this->scriptDirector();
        ShotListAgent::fake(fn () => $this->shotListJson(6)); // the model over-produces
        Image::fake(fn () => base64_encode($this->png(6, 6, [1, 2, 3])));

        $session = $this->videoSession(3);
        $this->runJob($session);

        $session->refresh();
        $this->assertCount(3, $session->results['shot_list']['shots'], 'the parse clamps to the effective cap');
        $this->assertCount(3, $session->results['storyboard']['shots'], 'the fan-out uses the SAME cap');

        // ...and the AGENT was INSTRUCTED with that same effective cap, so the model writes FOR 3 beats
        // instead of writing 8 and having 5 silently truncated. One value, three consumers.
        ShotListAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'between 3 and 3'),
        );
        ShotListAgent::assertNotPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'between 3 and 8'),
        );
    }

    public function test_an_authored_cap_over_the_platform_ceiling_cannot_be_persisted(): void
    {
        // The effective cap is min(authored, ceiling), so an over-ceiling value could never take effect —
        // it is refused at WRITE instead of being silently truncated at run time.
        $this->postJson('/api/generator/templates', [
            'name' => 'T',
            'content_type' => 'video_script',
            'slots' => [$this->topicSlot()],
            'content' => [
                'shot_list' => ['brief' => ['markdown' => 'A video']],
                'storyboard' => ['max_shots' => 99],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['content.storyboard.max_shots']);
    }

    // ---- D7 end to end + the threaded call shape ---------------------------------

    public function test_the_derived_direction_never_reaches_a_system_instruction(): void
    {
        $this->scriptDirector();
        $this->captureAiText();
        ShotListAgent::fake(fn () => $this->shotListJson(1));
        Image::fake(fn () => base64_encode($this->png(4, 4, [1, 2, 3])));

        $this->runJob($this->videoSession());
        $this->runJob($this->postSession(1));

        // The direction's own words are in the USER message of both content agents...
        ShotListAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'a nervous beginner in a red apron'));
        AiTextAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Espresso at home is easier'));

        // ...and NOWHERE in either SYSTEM instruction — it is model-derived, untrusted-laundered content, so
        // it may only ever ride inside the prompt-is-DATA hardening's scope.
        foreach (['a nervous beginner in a red apron', 'Espresso at home is easier', 'warm amber', 'Drive trial of the starter kit'] as $derived) {
            ShotListAgent::assertNotPrompted(
                fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), $derived),
            );
            AiTextAgent::assertNotPrompted(
                fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), $derived),
            );
        }

        // The trusted, content-free framing clause IS in the shot-list system instruction.
        ShotListAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'CREATIVE DIRECTION — the request carries'),
        );
    }

    public function test_the_derivation_call_uses_its_own_tighter_timeout(): void
    {
        config()->set('ai.direction_timeout', 17);

        $this->scriptDirector();
        $this->captureAiText();

        $this->runJob($this->postSession(1));

        // The derivation is bounded by ai.direction_timeout — that is what keeps the run job's 300s window
        // intact without raising it.
        CreativeDirectionAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->timeout === 17);
        // Content calls keep laravel/ai's own default (no timeout threaded) — an unchanged call shape.
        AiTextAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->timeout === 60);
    }

    public function test_a_generator_ai_text_call_carries_the_post_depth_guidance(): void
    {
        $this->scriptDirector();
        $this->captureAiText();

        $this->runJob($this->postSession(1));

        // B1.2: the generator replaces the shared agent's WORKFLOW-FIELD length prior, which suppressed depth.
        AiTextAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'Write a COMPLETE piece of social-media content'),
        );
        AiTextAgent::assertNotPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'Keep it appropriate in length for a single field'),
        );

        // ...but the rule SCALES to the request instead of always demanding depth. A system rule beats the
        // author's brief, so "develop it fully" alone would bloat a deliberately short field — the purpose
        // hint names "a caption", and a six-word caption must stay six words.
        AiTextAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'when it asks for something short'),
        );
    }

    public function test_the_direction_is_detail_only_and_never_weighs_down_the_index(): void
    {
        // Read-only provenance for the CHAT surface: re-normalizing it (a dozen regex passes) on EVERY
        // index row is pure weight the list never reads. The detail payload is unchanged.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('post', ['body' => ['markdown' => 'Body']], [$this->topicSlot()], ['topic' => 'Espresso'])
            ->create([
                'creator_id' => $this->user->id,
                'creative_direction' => json_decode($this->directionJson(), true),
            ]);

        $this->getJson('/api/generator/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonMissingPath('data.0.creative_direction');

        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.creative_direction.message', 'Espresso at home is easier than you think');
    }

    public function test_an_absent_max_shots_uses_the_raised_platform_ceiling(): void
    {
        $this->scriptDirector();
        ShotListAgent::fake(fn () => $this->shotListJson(12));
        Image::fake(fn () => base64_encode($this->png(4, 4, [1, 2, 3])));

        $session = $this->videoSession();
        $this->runJob($session);

        $session->refresh();
        // The default ceiling is 8 (raised from 5 for adaptive, longer-form pieces) and the generate budget
        // is in lock-step, so all 8 shots are rendered — never a frameless listed beat.
        $this->assertCount(8, $session->results['shot_list']['shots']);
        $this->assertCount(8, $session->results['storyboard']['shots']);

        foreach ($session->results['storyboard']['shots'] as $shot) {
            $this->assertSame('ok', $shot['image_status']);
        }
    }

    public function test_a_per_shot_op_still_addresses_a_high_index_shot(): void
    {
        $this->scriptDirector();
        ShotListAgent::fake(fn () => $this->shotListJson(8));
        Image::fake(fn () => base64_encode($this->png(4, 4, [1, 2, 3])));

        $session = $this->videoSession();
        $this->runJob($session);

        $this->postJson("/api/generator/sessions/{$session->id}/parts/storyboard.6/regenerate")->assertStatus(202);
    }
}
