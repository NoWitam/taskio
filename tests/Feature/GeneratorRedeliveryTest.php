<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Events\GenerationSessionUpdated;
use App\Modules\Generator\Jobs\RenderStoryboardFrameJob;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\StoryboardFrameManager;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * AT-LEAST-ONCE DELIVERY MUST NEVER DESTROY LIVE WORK.
 *
 * Both async generator jobs run for longer than the queue's `retry_after` (a frame was measured at 58–120s
 * and the run job may hold 300s, against a 90s app default), so the SAME payload is redelivered while the
 * original is still talking to the provider. That redelivery is not merely redundant — with `tries = 1` the
 * worker fails it in `markJobAsFailedIfAlreadyExceedsMaxAttempts`, which runs BEFORE `fire()` and therefore
 * before the WithoutOverlapping middleware could release it. So `failed()` fires for a delivery that never
 * entered `handle()`, on a FRESH command instance unserialized from the payload.
 *
 * The correlation TOKEN cannot save us there: it identifies the FRAME, not the DELIVERY, so the duplicate's
 * `failed()` hook looks like the rightful owner of a frame that is at that moment being rendered and paid
 * for. These cases pin the discriminator that closes it, in both directions:
 *
 *   - a delivery that never ran must leave the live frame / run exactly as it found it (no settle, no
 *     status flip, no terminal broadcast, no orphaned paid blob);
 *   - the FIRST delivery — the only one that can have entered handle() under `tries = 1` — must still fail
 *     its own frame / run when it is killed mid-flight, or a SIGALRM would hang the run until the reaper.
 *
 * The third case here is the other half of the same fault: a whole-run failure has to CLOSE the frames it
 * abandons, or the FE waits forever on shots that no worker will ever pick up again.
 */
class GeneratorRedeliveryTest extends TestCase
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

        config()->set('generator.direction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function frames(): StoryboardFrameManager
    {
        return app(StoryboardFrameManager::class);
    }

    /**
     * The queue-side Job of ONE delivery, reporting $attempts. Under `tries = 1` the attempt number IS the
     * delivery's identity as far as this fault is concerned: only attempt 1 can ever have run handle().
     */
    private function delivery(int $attempts): JobContract
    {
        $job = $this->createMock(JobContract::class);
        $job->method('attempts')->willReturn($attempts);

        return $job;
    }

    /** A `generating` video_script session whose two shots are ANNOUNCED frames with predictable tokens. */
    private function pendingFrameSession(): GenerationSession
    {
        $shots = [];
        $announced = [];

        foreach (['a cat', 'a dog'] as $i => $visual) {
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
            ->snapshot('video_script', [
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic')]],
                'storyboard' => ['style' => ['markdown' => 'flat vector'], 'filters' => []],
            ], [
                ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ], ['topic' => 'cats'])
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'shot_list' => ['kind' => 'shot_list', 'status' => 'ok', 'hook' => 'H', 'shots' => $shots, 'cta' => 'C', 'text' => 'flat', 'parse_ok' => true, 'version' => 1],
                    'storyboard' => ['kind' => 'storyboard', 'status' => 'ok', 'shots' => $announced],
                ],
            ]);
    }

    private function shots(GenerationSession $session): array
    {
        return $session->fresh()->results['storyboard']['shots'];
    }

    private function frameJob(GenerationSession $session, int $i): RenderStoryboardFrameJob
    {
        return new RenderStoryboardFrameJob($session->id, $this->workspace->id, 'storyboard.' . $i, 'frame-' . $i);
    }

    // ---- the frame job ---------------------------------------------------------

    public function test_a_redelivery_failed_before_handle_never_kills_the_live_frame(): void
    {
        Event::fake([GenerationSessionUpdated::class]);

        $session = $this->pendingFrameSession();

        // The ORIGINAL delivery won the claim and is, right now, inside a billed provider call.
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.0', 'frame-0'));

        // Its REDELIVERY (the render outlived retry_after) is failed by the worker before it can run — so
        // failed() fires on a fresh instance that never entered handle() and holds no claim of its own.
        $this->frameJob($session, 0)->failed(new MaxAttemptsExceededException('has been attempted too many times.'));

        $shot = $this->shots($session)[0];

        $this->assertSame('rendering', $shot['image_status'], 'a delivery that never ran must not settle a frame another delivery is rendering');
        $this->assertSame('frame-0', $shot['frame_token'], 'the live claim must survive intact');
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Event::assertNotDispatched(GenerationSessionUpdated::class);
    }

    public function test_a_redelivery_bound_to_its_own_queue_job_is_still_a_no_op(): void
    {
        Event::fake([GenerationSessionUpdated::class]);

        $session = $this->pendingFrameSession();
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.0', 'frame-0'));

        // The same duplicate as above, this time carrying the queue Job the framework hands failed(): its
        // SECOND attempt is proof it never ran (only attempt 1 can, under tries = 1).
        $this->frameJob($session, 0)
            ->setJob($this->delivery(attempts: 2))
            ->failed(new MaxAttemptsExceededException('has been attempted too many times.'));

        $this->assertSame('rendering', $this->shots($session)[0]['image_status']);
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Event::assertNotDispatched(GenerationSessionUpdated::class);
    }

    public function test_the_frameworks_own_failed_wiring_reaches_the_delivery_guard(): void
    {
        // The two cases above call failed() directly. This one goes through the REAL entry point — Laravel's
        // Job::failed hands the payload to CallQueuedHandler::failed, which unserializes a FRESH command and
        // attaches the delivery's queue Job to it. That is the wiring the whole guard depends on: the object
        // that ran handle() is never the object that is asked, so the discriminator has to survive a
        // round-trip through the payload.
        Event::fake([GenerationSessionUpdated::class]);

        $session = $this->pendingFrameSession();
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.0', 'frame-0'));

        app(CallQueuedHandler::class)->failed(
            ['command' => serialize($this->frameJob($session, 0))],
            new MaxAttemptsExceededException('has been attempted too many times.'),
            'delivery-uuid',
            $this->delivery(attempts: 2),
        );

        $this->assertSame('rendering', $this->shots($session)[0]['image_status']);
        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status);
        Event::assertNotDispatched(GenerationSessionUpdated::class);
    }

    public function test_the_first_delivery_still_fails_its_own_frame_when_it_is_killed(): void
    {
        Event::fake([GenerationSessionUpdated::class]);

        $session = $this->pendingFrameSession();

        // Both frames are claimed; frame 1 settles normally so only frame 0 is left outstanding.
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.0', 'frame-0'));
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.1', 'frame-1'));
        $this->frames()->settle($session->id, 'storyboard.1', 'frame-1', null);

        // A SIGALRM on the FIRST (and only possible) executing delivery: handle() never reached its own
        // catch, so failed() is what has to release the frame — otherwise the run hangs until the reaper.
        $this->frameJob($session, 0)
            ->setJob($this->delivery(attempts: 1))
            ->failed(new TimeoutExceededException('has timed out.'));

        $this->assertSame('failed', $this->shots($session)[0]['image_status']);
        $this->assertSame(__('generator.sessions.frame_lost'), $this->shots($session)[0]['image_error']);
        $this->assertSame(GenerationSessionStatus::Ready, $session->fresh()->status);
        Event::assertDispatched(GenerationSessionUpdated::class, 1);
    }

    // ---- the run job -----------------------------------------------------------

    public function test_a_redelivery_of_the_run_job_never_fails_the_live_run(): void
    {
        Event::fake([GenerationSessionUpdated::class]);

        $session = $this->pendingFrameSession();

        // The run job carries the same hazard as the frame job: a 300s window against a 90s retry_after.
        (new RunGenerationSessionJob($session->id, $this->workspace->id))
            ->failed(new MaxAttemptsExceededException('has been attempted too many times.'));

        $this->assertSame(GenerationSessionStatus::Generating, $session->fresh()->status, 'a duplicate delivery must not mark a live run failed');
        Event::assertNotDispatched(GenerationSessionUpdated::class);
    }

    public function test_the_first_delivery_of_the_run_job_still_fails_its_run(): void
    {
        Event::fake([GenerationSessionUpdated::class]);

        $session = $this->pendingFrameSession();

        (new RunGenerationSessionJob($session->id, $this->workspace->id))
            ->setJob($this->delivery(attempts: 1))
            ->failed(new TimeoutExceededException('has timed out.'));

        $this->assertSame(GenerationSessionStatus::Failed, $session->fresh()->status);
        Event::assertDispatched(GenerationSessionUpdated::class, 1);
    }

    // ---- a failed run must not strand its frames -------------------------------

    public function test_failing_a_run_closes_the_frames_it_abandons(): void
    {
        $session = $this->pendingFrameSession();

        // One frame is mid-render, the other still queued. Once the RUN is failed nothing will ever claim
        // them again (claim() requires a `generating` session), so leaving them outstanding means the FE
        // shows two shots stuck "pending" for the lifetime of the row.
        $this->assertNotNull($this->frames()->claim($session->id, 'storyboard.0', 'frame-0'));

        app(GenerationSessionRunManager::class)->fail($session->id);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Failed, $session->status);

        foreach ($this->shots($session) as $i => $shot) {
            $this->assertSame('failed', $shot['image_status'], "shot {$i} must not be left outstanding by a failed run");
            $this->assertSame(__('generator.sessions.frame_lost'), $shot['image_error']);
            $this->assertArrayNotHasKey('frame_token', $shot);
            $this->assertArrayNotHasKey('frame_claimed_at', $shot);
        }
    }

    public function test_failing_a_run_without_frames_is_unchanged(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->create(['creator_id' => $this->user->id, 'results' => ['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'x', 'version' => 1]]]);

        app(GenerationSessionRunManager::class)->fail($session->id);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Failed, $session->status);
        $this->assertSame(['body' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'x', 'version' => 1]], $session->results);
    }
}
