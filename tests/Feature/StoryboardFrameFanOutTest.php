<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Agents\ShotListAgent;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Events\GenerationSessionUpdated;
use App\Modules\Generator\Jobs\RenderStoryboardFrameJob;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GeneratedImageStore;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\StoryboardFrameManager;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Laravel\Ai\Image;
use Tests\TestCase;

/**
 * DISTRIBUTED STORYBOARD FRAMES: each shot's image renders in its OWN queue job instead of inside the session
 * run job. Measured, this was not optional — a storyboard image takes ~33s to generate and a median ~58s to
 * edit against a reference, while the session job's SIGALRM window is a fixed 300s that the whole lock/reaper
 * ordering sits on top of. Eight shots could never fit; even generate-only was already at the edge.
 *
 * These cases pin the guarantees that the split introduces, all of which are invisible in a single-job run:
 * the run stays open until the LAST frame settles (one broadcast, unchanged FE contract), a duplicate or
 * superseded delivery is a clean no-op, the per-run image budget still bounds the WHOLE run now that it
 * spans many processes, concurrent frame write-backs cannot lose each other, a lost frame is recovered by the
 * reaper rather than hanging its run, and a session with no storyboard behaves exactly as it did before.
 */
class StoryboardFrameFanOutTest extends TestCase
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

        // The creative-direction layer would be a REAL provider call; these cases are about the frame
        // machinery, so it stays off (same posture as StoryboardTest).
        config()->set('generator.direction.enabled', false);

        Storage::fake();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function png(int $w, int $h, array $rgb): string
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})"), 'png');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private function store(): GeneratedImageStore
    {
        return app(GeneratedImageStore::class);
    }

    private function frames(): StoryboardFrameManager
    {
        return app(StoryboardFrameManager::class);
    }

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    private function videoScriptContent(): array
    {
        return [
            'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
            'storyboard' => ['style' => ['markdown' => 'flat vector'], 'filters' => []],
        ];
    }

    /**
     * A GENERATING session already past its session job: the shot_list is stored and the storyboard's shots
     * are ANNOUNCED frames with predictable tokens (`frame-<i>`). Lets a case drive the frame machinery
     * directly, without re-running the text half of the pipeline.
     */
    private function pendingFrameSession(array $visuals): GenerationSession
    {
        $shots = [];
        $announced = [];

        foreach (array_values($visuals) as $i => $visual) {
            $shots[] = ['visual' => $visual, 'voiceover' => 'vo ' . $i, 'seconds' => 3];
            $announced[] = [
                'index' => $i,
                'visual' => $visual,
                'voiceover' => 'vo ' . $i,
                'seconds' => 3,
                'image_status' => 'pending',
                'part_key' => 'storyboard.' . $i,
                'frame_token' => 'frame-' . $i,
            ];
        }

        return GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'cats'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'shot_list' => ['kind' => 'shot_list', 'status' => 'ok', 'hook' => 'H', 'shots' => $shots, 'cta' => 'C', 'text' => 'flat', 'parse_ok' => true, 'version' => 1],
                    'storyboard' => ['kind' => 'storyboard', 'status' => 'ok', 'shots' => $announced],
                ],
            ]);
    }

    private function runFrame(GenerationSession $session, int $i, ?string $token = null): void
    {
        (new RenderStoryboardFrameJob($session->id, $this->workspace->id, 'storyboard.' . $i, $token ?? ('frame-' . $i)))
            ->handle($this->frames(), app(GenerationSessionRunManager::class));
    }

    private function runSessionJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    private function shots(GenerationSession $session): array
    {
        return $session->fresh()->results['storyboard']['shots'];
    }

    // ---- the run stays open until the last frame settles ------------------------

    public function test_a_full_run_leaves_the_session_generating_and_the_last_frame_settles_it_with_one_broadcast(): void
    {
        Event::fake([GenerationSessionUpdated::class]);
        Queue::fake();

        ShotListAgent::fake(fn () => json_encode([
            'hook' => 'H',
            'shots' => [
                ['visual' => 'a cat', 'voiceover' => 'v0', 'seconds' => 2],
                ['visual' => 'a dog', 'voiceover' => 'v1', 'seconds' => 2],
                ['visual' => 'a bird', 'voiceover' => 'v2', 'seconds' => 2],
            ],
            'cta' => 'C',
        ]));
        Image::fake([
            base64_encode($this->png(6, 6, [1, 1, 1])),
            base64_encode($this->png(7, 7, [2, 2, 2])),
            base64_encode($this->png(8, 8, [3, 3, 3])),
        ]);

        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'pets'])
            ->create(['creator_id' => $this->user->id]);

        $this->runSessionJob($session);

        // The session job produced the TEXT half and handed the images off: the run is not over, so the
        // status must stay `generating` and NOTHING may be broadcast yet (the FE waits for exactly one).
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Event::assertNotDispatched(GenerationSessionUpdated::class);

        Queue::assertPushed(RenderStoryboardFrameJob::class, 3);

        foreach ($this->shots($session) as $shot) {
            $this->assertSame('pending', $shot['image_status']);
        }

        /** @var array<int, RenderStoryboardFrameJob> $jobs */
        $jobs = Queue::pushed(RenderStoryboardFrameJob::class)->all();

        // The first two frames settle their own shot and leave the run open.
        $jobs[0]->handle($this->frames(), app(GenerationSessionRunManager::class));
        $jobs[1]->handle($this->frames(), app(GenerationSessionRunManager::class));

        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Event::assertNotDispatched(GenerationSessionUpdated::class);

        // The LAST frame is the one that ends the run.
        $jobs[2]->handle($this->frames(), app(GenerationSessionRunManager::class));

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        Event::assertDispatched(GenerationSessionUpdated::class, 1);

        // Every frame produced a stored, versioned image, and the settled shape carries NO in-flight
        // bookkeeping — a settled run must be indistinguishable from one rendered inline.
        foreach ($this->shots($session) as $i => $shot) {
            $this->assertSame('ok', $shot['image_status']);
            $this->assertSame(1, $shot['image']['version']);
            $this->assertSame('storyboard.' . $i, $shot['part_key']);
            $this->assertArrayNotHasKey('frame_token', $shot);
            $this->assertArrayNotHasKey('frame_claimed_at', $shot);
            $this->assertTrue($this->store()->exists($session->id, 'storyboard.' . $i, 1));
        }

        // The descriptive beat survived the hand-off between the two jobs.
        $this->assertSame('a dog', $this->shots($session)[1]['visual']);
    }

    // ---- correlated claim ------------------------------------------------------

    public function test_a_duplicate_frame_delivery_is_a_no_op(): void
    {
        Image::fake([base64_encode($this->png(6, 6, [1, 1, 1]))]);

        $session = $this->pendingFrameSession(['a cat']);

        $this->runFrame($session, 0);

        $this->assertSame('ok', $this->shots($session)[0]['image_status']);
        $this->assertSame(1, $this->shots($session)[0]['image']['version']);
        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);

        // At-least-once delivery: the SAME job arrives again. It must lose the claim (the shot is no longer
        // `pending` and no longer carries a token), not render and bill a second image.
        Event::fake([GenerationSessionUpdated::class]);
        $this->runFrame($session, 0);

        $this->assertSame(1, $this->shots($session)[0]['image']['version']);
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.0', 2));
        Event::assertNotDispatched(GenerationSessionUpdated::class);
    }

    public function test_a_rejected_write_back_does_not_leave_its_paid_image_behind(): void
    {
        // The race the token claim exists for, seen from the LOSER's side: this frame was already given up
        // on (the stale-frame reaper failed it, or a re-claim superseded the run) while its provider call
        // was still in flight. The call lands anyway, stores a versioned blob — and then the write-back is
        // rejected, because the shot is no longer this frame's. Nothing will ever reference that blob: it is
        // not on the shot, not in the history, and not reachable by the serve endpoint. Only the purge would
        // eventually collect it, weeks later.
        Image::fake([base64_encode($this->png(6, 6, [1, 1, 1]))]);

        $session = $this->pendingFrameSession(['a cat']);
        $manager = $this->frames();

        $claimed = $manager->claim($session->id, 'storyboard.0', 'frame-0');
        $this->assertNotNull($claimed);

        // The frame is given up on while the (stale) claimed snapshot is still rendering from it.
        $manager->settle($session->id, 'storyboard.0', 'frame-0', null);
        $this->assertSame('failed', $this->shots($session)[0]['image_status']);

        // The straggler finishes and tries to write back.
        $this->assertFalse($manager->render($claimed, 'storyboard.0', 'frame-0'));

        // The refusal stands...
        $this->assertSame('failed', $this->shots($session)[0]['image_status']);
        $this->assertSame(__('generator.sessions.frame_lost'), $this->shots($session)[0]['image_error']);

        // ...and the image nobody can reach was reclaimed rather than left on the disk forever.
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.0', 1), 'a rejected write-back must not orphan its blob');
    }

    public function test_a_frame_job_carrying_a_superseded_token_is_a_no_op(): void
    {
        Image::fake([base64_encode($this->png(6, 6, [1, 1, 1]))]);

        $session = $this->pendingFrameSession(['a cat']);

        // A straggler from a previous, already-reaped attempt: same frame address, stale token.
        $this->runFrame($session, 0, 'frame-from-a-dead-run');

        $this->assertSame('pending', $this->shots($session)[0]['image_status']);
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.0', 1));
    }

    // ---- the per-run budget must survive the process boundary -------------------

    public function test_the_generate_budget_bounds_the_whole_run_across_separate_frame_jobs(): void
    {
        // THE case the persisted ledger exists for. With the budget counted in memory, each frame job would
        // build its own container, start from zero and happily spend the full ceiling — so a cap of 2 would
        // bound nothing and all three frames would render.
        config()->set('generator.image_generate_max_calls_per_session', 2);

        Image::fake([
            base64_encode($this->png(6, 6, [1, 1, 1])),
            base64_encode($this->png(7, 7, [2, 2, 2])),
        ]);

        $session = $this->pendingFrameSession(['a cat', 'a dog', 'a bird']);

        $this->runFrame($session, 0);
        $this->runFrame($session, 1);
        $this->runFrame($session, 2);

        $shots = $this->shots($session);

        $this->assertSame('ok', $shots[0]['image_status']);
        $this->assertSame('ok', $shots[1]['image_status']);
        $this->assertSame('failed', $shots[2]['image_status']);
        $this->assertSame(__('generator.sessions.image_generate_budget'), $shots[2]['image_error']);

        // The refused frame never spent: no blob, and the ledger stopped exactly at the ceiling.
        $this->assertFalse($this->store()->exists($session->id, 'storyboard.2', 1));
        $this->assertSame(2, $session->fresh()->ai_generate_calls);

        // Fail-soft: the over-budget frame still settles the run (nothing is left outstanding).
        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);
        $this->assertTrue($session->fresh()->hasFailedParts());
    }

    public function test_every_claim_resets_the_run_ledger_so_a_later_run_gets_its_own_budget(): void
    {
        // The counters are per RUN, not per session lifetime — otherwise a session would silently become
        // un-generatable after enough refines. The claim is the one place that defines a run.
        $session = $this->pendingFrameSession(['a cat']);
        $session->update(['status' => GenerationSessionStatus::Ready, 'ai_generate_calls' => 8, 'ai_edit_calls' => 8]);

        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session);

        $session->refresh();
        $this->assertSame(0, $session->ai_generate_calls);
        $this->assertSame(0, $session->ai_edit_calls);
    }

    // ---- concurrent write-back --------------------------------------------------

    public function test_two_frames_settling_against_the_same_results_do_not_lose_each_other(): void
    {
        // Frames render CONCURRENTLY and both write into the same `results` json. Both models below are
        // loaded BEFORE either shot settles, so a write-back built on the model's own captured results would
        // silently discard whichever frame committed first — an image the workspace already paid for.
        Image::fake([
            base64_encode($this->png(6, 6, [1, 1, 1])),
            base64_encode($this->png(7, 7, [2, 2, 2])),
        ]);

        $session = $this->pendingFrameSession(['a cat', 'a dog']);
        $manager = $this->frames();

        $claimedFirst = $manager->claim($session->id, 'storyboard.0', 'frame-0');
        $claimedSecond = $manager->claim($session->id, 'storyboard.1', 'frame-1');

        $this->assertNotNull($claimedFirst);
        $this->assertNotNull($claimedSecond);

        // Settle them in the OPPOSITE order to the claims, each from its own stale snapshot of the row.
        $manager->render($claimedSecond, 'storyboard.1', 'frame-1');
        $settled = $manager->render($claimedFirst, 'storyboard.0', 'frame-0');

        $shots = $this->shots($session);
        $this->assertSame('ok', $shots[0]['image_status'], 'frame 0 must survive frame 1 committing first');
        $this->assertSame('ok', $shots[1]['image_status'], 'frame 1 must not be clobbered by frame 0');

        // Each shot kept ITS OWN image: frame 1 rendered first and took the 6x6, frame 0 the 7x7. Distinct
        // sizes are what proves neither write-back replayed a stale snapshot over the other.
        $this->assertSame(7, $shots[0]['image']['width']);
        $this->assertSame(6, $shots[1]['image']['width']);
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.0', 1));
        $this->assertTrue($this->store()->exists($session->id, 'storyboard.1', 1));

        // Exactly one of them observed an empty outstanding set and therefore settled the run.
        $this->assertTrue($settled);
        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);
    }

    // ---- per-frame fail-soft ----------------------------------------------------

    public function test_one_failing_frame_leaves_the_others_intact_and_the_run_ready(): void
    {
        Image::fake([
            base64_encode($this->png(6, 6, [1, 1, 1])),
            base64_encode($this->png(8, 8, [3, 3, 3])),
        ]);

        // The middle beat has no visual to draw, so its frame fails without reaching a provider.
        $session = $this->pendingFrameSession(['a cat', '', 'a bird']);

        $this->runFrame($session, 0);
        $this->runFrame($session, 1);
        $this->runFrame($session, 2);

        $shots = $this->shots($session);
        $this->assertSame('ok', $shots[0]['image_status']);
        $this->assertSame('failed', $shots[1]['image_status']);
        $this->assertSame('ok', $shots[2]['image_status']);

        // A failed frame carries a localized, non-secret reason and NO servable image reference.
        $this->assertSame(__('generator.sessions.image_failed'), $shots[1]['image_error']);
        $this->assertArrayNotHasKey('image', $shots[1]);
        $this->assertArrayNotHasKey('part_key', $shots[1]);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['storyboard']['status']);
        $this->assertTrue($session->hasFailedParts());
    }

    // ---- the lost-frame reaper --------------------------------------------------

    public function test_the_reaper_fails_a_frame_claimed_but_never_settled_and_settles_its_run(): void
    {
        // A worker SIGKILLed between the claim and the write-back never fires its own failed() hook. Without
        // this sweep the run would hang `generating` until the 30-minute whole-session backstop discarded it
        // — with every other frame already finished and paid for.
        Image::fake([base64_encode($this->png(6, 6, [1, 1, 1]))]);

        $session = $this->pendingFrameSession(['a cat', 'a dog']);

        $this->runFrame($session, 0);
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.1', 'frame-1'));
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);

        // Age the surviving claim past the frame window.
        $results = $session->fresh()->results;
        $results['storyboard']['shots'][1]['frame_claimed_at'] = now()->subHour()->toIso8601String();
        $session->update(['results' => $results]);

        $this->artisan('generator:reap-sessions')->assertSuccessful();

        $session->refresh();
        $shots = $session->results['storyboard']['shots'];

        $this->assertSame('failed', $shots[1]['image_status']);
        $this->assertSame(__('generator.sessions.frame_lost'), $shots[1]['image_error']);
        $this->assertArrayNotHasKey('frame_claimed_at', $shots[1]);

        // The finished frame is untouched and the run is released rather than discarded whole.
        $this->assertSame('ok', $shots[0]['image_status']);
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
    }

    public function test_the_reaper_leaves_a_freshly_claimed_frame_alone(): void
    {
        $session = $this->pendingFrameSession(['a cat']);
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.0', 'frame-0'));

        $this->artisan('generator:reap-sessions')->assertSuccessful();

        $session->refresh();
        $this->assertSame('rendering', $session->results['storyboard']['shots'][0]['image_status']);
        $this->assertSame(GenerationSessionStatus::Generating, $session->status);
    }

    // ---- a run without a storyboard is untouched --------------------------------

    public function test_a_session_without_a_storyboard_queues_nothing_and_settles_in_its_own_job(): void
    {
        Event::fake([GenerationSessionUpdated::class]);
        Queue::fake();

        // The factory default is a plain `post` recipe: one text part, no storyboard, no frames.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->create(['creator_id' => $this->user->id]);

        $this->runSessionJob($session);

        Queue::assertNotPushed(RenderStoryboardFrameJob::class);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertSame('ok', $session->results['body']['status']);
        Event::assertDispatched(GenerationSessionUpdated::class, 1);
    }

    // ---- isolated per-shot ops share the persisted ledger -----------------------

    public function test_an_isolated_frame_refine_charges_the_persisted_ledger(): void
    {
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [7, 7, 7]))]]], 200)]);

        $session = $this->readyShotSession();

        // Run the refine WITHOUT going through claimAndDispatch, so nothing resets the ledger: the spend has
        // to land on the row itself, which is what makes the budget shared with the frame jobs.
        $session->update(['status' => GenerationSessionStatus::Generating]);
        (new RunGenerationSessionJob($session->id, $this->workspace->id, GenerationRunMode::Refine->value, 'storyboard.0', 'make it pop'))
            ->handle(app(GenerationSessionRunManager::class));

        $session->refresh();
        $this->assertSame('ok', $session->last_op_status);
        $this->assertSame(2, $session->results['storyboard']['shots'][0]['image']['version']);
        $this->assertSame(1, $session->ai_edit_calls);
    }

    public function test_an_isolated_frame_refine_is_refused_when_the_run_ledger_is_already_spent(): void
    {
        config()->set('generator.image_edit_max_calls_per_session', 1);
        Http::fake(['*/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(4, 4, [7, 7, 7]))]]], 200)]);

        $session = $this->readyShotSession();
        $session->update(['status' => GenerationSessionStatus::Generating, 'ai_edit_calls' => 1]);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, GenerationRunMode::Refine->value, 'storyboard.0', 'make it pop'))
            ->handle(app(GenerationSessionRunManager::class));

        $session->refresh();
        $this->assertSame('failed', $session->last_op_status);
        $this->assertSame(__('generator.sessions.image_budget'), $session->last_op_error);
        // The current good image is preserved and no provider call was made.
        $this->assertSame(1, $session->results['storyboard']['shots'][0]['image']['version']);
        Http::assertNothingSent();
    }

    /** A ready video_script session whose single shot already carries a stored image at version 1. */
    private function readyShotSession(): GenerationSession
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot('video_script', $this->videoScriptContent(), [$this->topicSlot()], ['topic' => 'cats'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'shot_list' => ['kind' => 'shot_list', 'status' => 'ok', 'hook' => 'H', 'shots' => [['visual' => 'a cat', 'voiceover' => 'vo', 'seconds' => 3]], 'cta' => 'C', 'text' => 'flat', 'parse_ok' => true, 'version' => 1],
                    'storyboard' => ['kind' => 'storyboard', 'status' => 'ok', 'shots' => [
                        ['index' => 0, 'visual' => 'a cat', 'voiceover' => 'vo', 'seconds' => 3, 'image_status' => 'ok', 'image' => ['mime' => 'image/png', 'width' => 8, 'height' => 8, 'version' => 1], 'part_key' => 'storyboard.0'],
                    ]],
                ],
            ]);

        $this->store()->storeVersion($session->id, 'storyboard.0', $this->png(8, 8, [5, 5, 5]));

        return $session;
    }
}
