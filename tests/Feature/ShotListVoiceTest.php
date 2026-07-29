<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\ImageChainExecutor;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Variables\Support\AiVoiceContext;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use ReflectionClass;
use Tests\TestCase;

/**
 * The VOICE reaches the STRUCTURED shot_list path too (R2 sub-stage 3 — voice covers the storyboard
 * voiceover) — WITHOUT breaking the strict-JSON output contract — while the storyboard IMAGE prompt stays
 * authored `style` + shot `visual` only (voice never touches image aesthetics).
 */
class ShotListVoiceTest extends TestCase
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

        // Creative-direction layer OFF: these cases pin the VOICE seam, which predates it, and an
        // un-scripted derivation would be a REAL provider call. That the voice WINS on tone over a
        // direction is pinned in CreativeDirectionTest.
        config()->set('generator.direction.enabled', false);

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function png(int $w, int $h, array $rgb): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    public function test_the_shot_list_agent_is_voice_aware_yet_still_parses_under_the_json_contract(): void
    {
        // A DRAFT video_script session — delegate while editable (applyDelegation guards editability), THEN
        // flip it to generating for the run.
        $session = GenerationSession::factory()
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            ], [$this->topicSlot()], ['topic' => 'widgets'])
            ->create(['creator_id' => $this->user->id]);

        app(SessionDelegationService::class)->applyDelegation(
            $session,
            'ALWAYS SPEAK LIKE A PIRATE',
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Cap', 'icon' => 'robot'],
            (string) \Illuminate\Support\Str::uuid(),
        );
        $session->update(['status' => GenerationSessionStatus::Generating]);

        // The voice is ambient during the shot_list call; the model still returns valid JSON, which parses.
        $seenVoice = null;
        ShotListAgent::fake(function () use (&$seenVoice) {
            $seenVoice = app(AiVoiceContext::class)->directive();

            return json_encode([
                'hook' => 'Ahoy',
                'shots' => [['visual' => 'A ship sails', 'voiceover' => 'Set sail', 'seconds' => 3]],
                'cta' => 'Follow',
            ]);
        });
        // The incidental storyboard fan-out gets a faked image (never a real provider).
        Image::fake([base64_encode($this->png(6, 6, [10, 20, 30]))]);

        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));

        $this->assertSame('ALWAYS SPEAK LIKE A PIRATE', $seenVoice, 'the voice must reach the shot_list agent');

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['shot_list']['status']);
        $this->assertTrue($session->results['shot_list']['parse_ok'], 'the strict JSON contract still parses under a voice');
        $this->assertSame('Ahoy', $session->results['shot_list']['hook']);
    }

    public function test_the_image_generation_path_never_consults_the_voice(): void
    {
        // Voice colors CONTENT (text + shot_list wording) only. The image chain — the ONLY image-producing
        // path — must never read the voice context, so a delegated run's storyboard image stays authored
        // `style` + shot `visual` only. Pinned structurally (the same idiom as the module boundary tests).
        $source = (string) file_get_contents((new ReflectionClass(ImageChainExecutor::class))->getFileName());

        $this->assertStringNotContainsString('AiVoiceContext', $source, 'the image chain must never read the voice (image aesthetics stay authored-only)');
    }
}
