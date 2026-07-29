<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\SessionContentProjector;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server-side assembled-content projection ({@see SessionContentProjector}) and its PARITY with the FE
 * component that has been the only composer of a finished post until now:
 * `resources/js/next/pages/generator/session/FinalPostBody.vue`.
 *
 * The two must stay in sync, so this file pins BOTH halves:
 *   1. FIXTURES — a results fixture per composition (`post_with_image`, `video_script`, a LEGACY
 *      script/scene_plan snapshot) with the exact assembled string, encoding the component's rules:
 *      declared part order, only parts that produced a result, text_body/script → `text`, shot_list → its
 *      readable flattening `text`, scene_plan → every scene's narration, image_plan/storyboard → nothing,
 *      failed/missing → skipped.
 *   2. A DRIFT ALARM — the component still branches on exactly the kinds the projector mirrors. A rename or
 *      removal on the FE side trips this test, which is the moment to update both.
 *
 * The one deliberate divergence is CHROME: the component wraps each block in a localized section heading and
 * per-item captions ("Scene 2", "Shot 3") because it is a visual card; a projection consumed by another
 * system carries the CONTENT only.
 */
class GeneratorSessionContentProjectorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $workspace->users()->attach($this->user->id);

        $this->actingAs($this->user);
        app(TenantContext::class)->set($workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function project(GenerationSession $session): string
    {
        return app(SessionContentProjector::class)->project($session);
    }

    /** A READY session over an explicit snapshot + results. */
    private function readySession(string $contentType, array $content, array $results): GenerationSession
    {
        return GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot($contentType, $content, [], [])
            ->create(['creator_id' => $this->user->id, 'results' => $results]);
    }

    private function okText(string $kind, string $text): array
    {
        return ['kind' => $kind, 'status' => 'ok', 'text' => $text, 'version' => 1];
    }

    // ---- the composed fixtures --------------------------------------------------

    public function test_a_post_with_image_projects_the_body_and_skips_the_image(): void
    {
        $session = $this->readySession('post_with_image', ['body' => [], 'image' => []], [
            'body' => $this->okText('text_body', "Launch day is here.\n\nGrab it now."),
            'image' => ['kind' => 'image_plan', 'status' => 'ok', 'image' => ['mime' => 'image/png', 'width' => 1, 'height' => 1, 'version' => 1], 'version' => 1],
        ]);

        $this->assertSame("Launch day is here.\n\nGrab it now.", $this->project($session));
    }

    public function test_a_video_script_projects_the_shot_lists_readable_flattening_and_skips_the_storyboard(): void
    {
        $flattened = "Stop scrolling.\nShot 1: a hand opens the box / This is the one. (3s)\nShot 2: logo lands / Get yours today. (2s)\nLink in bio.";

        $session = $this->readySession('video_script', ['shot_list' => [], 'storyboard' => []], [
            'shot_list' => [
                'kind' => 'shot_list',
                'status' => 'ok',
                'hook' => 'Stop scrolling.',
                'shots' => [
                    ['visual' => 'a hand opens the box', 'voiceover' => 'This is the one.', 'seconds' => 3],
                    ['visual' => 'logo lands', 'voiceover' => 'Get yours today.', 'seconds' => 2],
                ],
                'cta' => 'Link in bio.',
                'text' => $flattened,
                'parse_ok' => true,
                'version' => 1,
            ],
            // The storyboard is the IMAGE side of the same script — its visuals are already in the
            // flattening above, so it must contribute nothing (no duplication).
            'storyboard' => ['kind' => 'storyboard', 'status' => 'ok', 'shots' => [
                ['index' => 0, 'visual' => 'a hand opens the box', 'voiceover' => 'This is the one.', 'seconds' => 3, 'image_status' => 'ok', 'image' => ['version' => 1], 'part_key' => 'storyboard.0'],
            ]],
        ]);

        $this->assertSame($flattened, $this->project($session));
    }

    public function test_a_legacy_script_and_scene_plan_snapshot_projects_in_declared_order(): void
    {
        // A snapshot created BEFORE the video_script recomposition still renders its own parts, so the
        // projection follows the SNAPSHOT's order: the script, then every scene's narration.
        $session = $this->readySession('video_script', ['script' => [], 'scene_plan' => []], [
            'script' => $this->okText('script', 'The script body.'),
            'scene_plan' => ['kind' => 'scene_plan', 'status' => 'ok', 'scenes' => [
                ['narration' => 'Scene one narration.', 'image_status' => 'ok', 'image' => ['version' => 1], 'part_key' => 'scene_plan.0'],
                ['narration' => '', 'image_status' => 'failed', 'image_error' => 'x'],
                ['narration' => 'Scene three narration.', 'image_status' => 'none'],
            ]],
        ]);

        $this->assertSame(
            "The script body.\n\nScene one narration.\n\nScene three narration.",
            $this->project($session),
        );
    }

    // ---- fail-soft --------------------------------------------------------------

    public function test_a_failed_or_missing_part_is_skipped_never_thrown(): void
    {
        $session = $this->readySession('post_with_image', ['body' => [], 'image' => []], [
            'body' => ['kind' => 'text_body', 'status' => 'failed', 'error' => 'Something went wrong'],
            'image' => ['kind' => 'image_plan', 'status' => 'failed', 'error' => 'Image failed'],
        ]);

        // The component renders failures as red UI prose — chrome, not content.
        $this->assertSame('', $this->project($session));

        $partial = $this->readySession('post_with_image', ['body' => [], 'image' => []], [
            'body' => $this->okText('text_body', 'Only this survived.'),
        ]);
        $this->assertSame('Only this survived.', $this->project($partial));
    }

    public function test_nothing_is_projected_before_a_session_is_ready(): void
    {
        // Mirrors the component's `isReady` gate (status === 'ready' && results != null).
        foreach ([GenerationSessionStatus::Draft, GenerationSessionStatus::Generating, GenerationSessionStatus::Failed] as $status) {
            $session = GenerationSession::factory()
                ->status($status)
                ->snapshot('post', ['body' => []], [], [])
                ->create(['creator_id' => $this->user->id, 'results' => ['body' => $this->okText('text_body', 'Not final yet.')]]);

            $this->assertSame('', $this->project($session), $status->value);
        }

        $noResults = $this->readySession('post', ['body' => []], []);
        $this->assertSame('', $this->project($noResults));
    }

    public function test_an_unknown_content_type_projects_nothing(): void
    {
        $session = $this->readySession('made_up_type', ['body' => []], ['body' => $this->okText('text_body', 'x')]);

        $this->assertSame('', $this->project($session));
    }

    // ---- the FE drift alarm -----------------------------------------------------

    public function test_the_fe_component_still_composes_the_kinds_the_projector_mirrors(): void
    {
        $source = $this->componentSource();

        foreach ([
            "part.kind === 'text_body'",
            "part.kind === 'script'",
            "block.part.kind === 'image_plan'",
            "block.part.kind === 'scene_plan'",
            "block.part.kind === 'shot_list'",
            "block.part.kind === 'storyboard'",
        ] as $branch) {
            $this->assertStringContainsString(
                $branch,
                $source,
                'FinalPostBody.vue changed its composition (' . $branch . ') — SessionContentProjector must be updated with it.',
            );
        }
    }

    /**
     * The COMPOSITION alarm (the branch list above is only a RENAME alarm). The projector's central claim
     * is WHICH arms carry prose: `MarkdownViewer` — the component's one prose renderer — must appear in
     * exactly the arms the projector contributes text for (text_body/script, shot_list, scene_plan) and in
     * NEITHER image-only arm (image_plan, storyboard). Add a narration, caption or script to the storyboard
     * arm and this trips, which is the moment the projector's "image-only kinds contribute nothing" rule
     * stops being true.
     *
     * The storyboard's bare `{{ shot.visual }}` line is the KNOWN exception and is why the assertion is
     * about the prose renderer, not about "any text": those visuals are already inside the sibling
     * shot_list's readable flattening, so projecting them would DUPLICATE the script.
     */
    public function test_no_prose_is_rendered_under_the_image_only_arms_of_the_fe_component(): void
    {
        $arms = $this->componentArms($this->componentSource());

        foreach (['text', 'scene_plan', 'shot_list'] as $textBearing) {
            $this->assertStringContainsString(
                'MarkdownViewer',
                $arms[$textBearing],
                'the ' . $textBearing . ' arm must still render prose — the projector contributes its text.',
            );
        }

        foreach (['image_plan', 'storyboard'] as $imageOnly) {
            $this->assertStringNotContainsString(
                'MarkdownViewer',
                $arms[$imageOnly],
                'FinalPostBody.vue now renders prose in the ' . $imageOnly . ' arm — SessionContentProjector treats that kind as image-only and would DROP it.',
            );
            $this->assertStringNotContainsString(
                'result?.text',
                $arms[$imageOnly],
                'FinalPostBody.vue now renders the result text in the ' . $imageOnly . ' arm — SessionContentProjector treats that kind as image-only and would DROP it.',
            );
        }
    }

    private function componentSource(): string
    {
        $path = resource_path('js/next/pages/generator/session/FinalPostBody.vue');
        $this->assertFileExists($path, 'the projector mirrors this component — keep the two in sync');

        return (string) file_get_contents($path);
    }

    /**
     * The component's per-kind template arms, sliced between their `v-if`/`v-else-if` markers (which appear
     * in this order inside the one `<section v-for>`). Each slice is the markup that kind renders.
     *
     * @return array<string, string>
     */
    private function componentArms(string $source): array
    {
        $markers = [
            'text' => 'v-if="isText(block.part)"',
            'image_plan' => 'v-else-if="block.part.kind === \'image_plan\'"',
            'scene_plan' => 'v-else-if="block.part.kind === \'scene_plan\'"',
            'shot_list' => 'v-else-if="block.part.kind === \'shot_list\'"',
            'storyboard' => 'v-else-if="block.part.kind === \'storyboard\'"',
            'end' => '</section>',
        ];

        $offsets = [];
        $cursor = 0;

        foreach ($markers as $name => $marker) {
            $at = strpos($source, $marker, $cursor);
            $this->assertIsInt($at, 'FinalPostBody.vue no longer declares the ' . $name . ' arm in the expected order — resync the projector.');
            $offsets[$name] = $at;
            $cursor = $at;
        }

        $arms = [];
        $names = array_keys($markers);

        foreach ($names as $index => $name) {
            if ($name === 'end') {
                continue;
            }

            $arms[$name] = substr($source, $offsets[$name], $offsets[$names[$index + 1]] - $offsets[$name]);
        }

        return $arms;
    }
}
