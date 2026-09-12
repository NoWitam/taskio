<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Contracts\PlatformAdapter;
use App\Modules\Publishing\DTOs\RemoteDraft;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationAttemptPhase;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\PlatformRefused;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Jobs\PublishPublicationJob;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublicationAttempt;
use App\Modules\Publishing\Services\PlatformAdapterRegistry;
use App\Modules\Publishing\Services\PublicationPublisher;
use App\Modules\Publishing\Services\PublicationQueueService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * R4 B3 — THE QUEUE: the claim, the worker, the reaper, and the reconciliation.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE IS ACTUALLY DEFENDING
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * One sentence, and every test below is a consequence of it: A DOUBLY-PUBLISHED POST IS A PUBLIC
 * ARTIFACT THAT NOTHING IN THIS APPLICATION CAN WITHDRAW.
 *
 * B1 built a state machine whose graph makes that impossible to reach by taking an edge. B3 added the
 * machinery that runs OUTSIDE the graph — a sweep, a worker, a queue that retries by default, a reaper
 * that fires long after the fact — and every one of those is a way to publish something twice WITHOUT
 * taking an illegal edge:
 *
 *   TWO SWEEPS both read one due row and both dispatch. Two jobs, one publication, two posts.
 *   THE QUEUE retries a job that died mid-call. The platform had already accepted; now it accepts again.
 *   THE REAPER finds a stranded claim and helpfully re-queues it. Same coin flip, on a schedule.
 *   THE HOOK marks a dead publish `failed` — which asserts nothing was created — and re-opens a retry.
 *
 * None of those is an illegal transition. Each is a perfectly reasonable-looking line. So the
 * assertions here are about the SHAPE of the machinery rather than about the graph, which
 * `PublishingStateMachineTest` already owns.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE ONE THAT LOOKS LIKE IT IS TESTING NOTHING
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * {@see test_the_publish_job_never_retries} reads a property and asserts it equals 1. That is not a
 * tautology dressed as a test — it is the only place in the system where "somebody raised tries to 3
 * while chasing a flaky platform" goes red. Nothing else would: the suite would still pass, the module
 * would still work, and the failure would arrive as a duplicate post on a customer's channel months
 * later with no way to attribute it.
 */
class PublishingQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private PublicationManager $manager;

    private PublicationQueueService $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);

        $this->manager = app(PublicationManager::class);
        $this->queue = app(PublicationQueueService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ══ tries = 1 ═══════════════════════════════════════════════════════════════════════════════

    /**
     * THE DOCTRINE, AS A PROPERTY. The queue may never retry a publish.
     *
     * A retry is not a retry when the previous attempt's outcome is unknown — it is a coin flip whose
     * losing side is a second public post. Every automatic recovery path in this module therefore ends
     * in `needs_reconcile` (ask the platform) rather than in another attempt.
     *
     * `$backoff` and `retryUntil()` are asserted absent for the same reason as `$tries`: either one
     * silently re-opens the door that `tries = 1` closes, and both are the natural thing to reach for
     * when a platform is being flaky.
     */
    public function test_the_publish_job_never_retries(): void
    {
        $job = new PublishPublicationJob('some-id', $this->workspace->id);

        $this->assertSame(
            1,
            $job->tries,
            'PublishPublicationJob::$tries must stay 1. A queue retry after an unknown outcome is how one '
            . 'scheduled post becomes two public artifacts, and nothing in this application can withdraw '
            . 'the second one. If a platform is flaky, the answer is reconciliation — not another attempt.',
        );

        $reflection = new ReflectionClass($job);

        $this->assertFalse(
            $reflection->hasProperty('backoff'),
            'a backoff is a retry schedule; this job must not have one',
        );
        $this->assertFalse(
            $reflection->hasMethod('retryUntil'),
            'retryUntil() re-opens retries regardless of $tries',
        );

        // And the alarm is ordered against the queue's own redelivery window — above it, the same
        // payload is redelivered while the original is still talking to a platform.
        $this->assertGreaterThan(0, $job->timeout);
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after', 90),
            $job->timeout,
            'publish_timeout must stay below the connection retry_after — see config/publishing.php',
        );
    }

    // ══ the atomic claim ════════════════════════════════════════════════════════════════════════

    /**
     * THE RACE, DIRECTLY. Two claims on one row; exactly one wins.
     *
     * This is the assertion a read-then-write claim fails. `PublicationManager::claim()` decides from an
     * in-memory status and would let BOTH callers through here, each seeing `scheduled`, each writing
     * `publishing`, each returning a row that looks claimed. `claimDue()` puts the decision in the WHERE
     * clause, so the second caller's UPDATE matches nothing.
     *
     * The attempt counter is the second half and is checked deliberately: it is incremented in SQL, so
     * two claims cannot both read 0 and both write 1.
     */
    public function test_only_one_of_two_concurrent_claims_can_take_a_due_publication(): void
    {
        $publication = Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'creator_id' => $this->owner->id,
        ]);

        $first = $this->manager->claimDue($publication);
        $second = $this->manager->claimDue(Publication::query()->findOrFail($publication->id));

        $this->assertNotNull($first, 'the first claim must win');
        $this->assertNull($second, 'the second claim must be told the row is gone, not handed it again');

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHING, $fresh->status);
        $this->assertSame(1, $fresh->attempts, 'two claims must not be recorded as one attempt each');
        $this->assertNotNull($fresh->last_attempt_at, 'the claim stamps its own time, or the reaper is blind');
    }

    /**
     * TWO SWEEPS, ONE DUE PUBLICATION, ONE JOB.
     *
     * The same property one level up, where it actually matters: the scheduler's `withoutOverlapping`
     * bounds a command against itself on one host and says nothing about a second host or a manual run.
     * What makes a second pass harmless is the claim, not the lock.
     */
    public function test_two_overlapping_sweeps_dispatch_one_job_for_one_publication(): void
    {
        Queue::fake();

        Publication::factory()->scheduled('2026-09-10 09:00:00')->create(['creator_id' => $this->owner->id]);

        $this->travelTo('2026-09-10 09:01:00');

        $this->runCommand('publishing:dispatch-due');
        $this->runCommand('publishing:dispatch-due');

        Queue::assertPushed(PublishPublicationJob::class, 1);
    }

    /**
     * FENCE 1, ON THE SWEEP'S PATH. A publication awaiting reconciliation is never claimed.
     *
     * It is not that the sweep declines to claim it — it is that the statement CANNOT SELECT it: the due
     * query asks for `scheduled`, and the claim's WHERE clause asks again. A publication in
     * `needs_reconcile` may already be a live post, and the machinery that runs unattended every minute
     * is the last thing that should be deciding to publish it again.
     */
    public function test_the_sweep_can_never_claim_a_publication_awaiting_reconciliation(): void
    {
        Queue::fake();

        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        $this->travelTo('2026-09-10 09:01:00');

        $this->runCommand('publishing:dispatch-due');

        Queue::assertNothingPushed();
        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $publication->fresh()->status);

        // And the claim itself refuses it even when handed the row directly.
        $this->assertNull(
            $this->manager->claimDue($publication->fresh()),
            'claimDue() must not move a row out of needs_reconcile — there is no such edge',
        );
        $this->assertSame(1, $publication->fresh()->attempts, 'a refused claim is not an attempt');
    }

    /** FENCE 2, on the same path: a held publication keeps its schedule and is not claimed. */
    public function test_the_sweep_can_never_claim_a_blocked_publication(): void
    {
        Queue::fake();

        $publication = Publication::factory()->blocked()->create(['creator_id' => $this->owner->id]);

        $this->travelTo('2026-09-10 09:01:00');

        $this->runCommand('publishing:dispatch-due');

        Queue::assertNothingPushed();
        $this->assertSame(PublicationStatus::BLOCKED, $publication->fresh()->status);
        $this->assertNull($this->manager->claimDue($publication->fresh()));
    }

    /** A moment that has not arrived is not due, and a draft has no moment at all. */
    public function test_the_sweep_claims_what_is_due_and_nothing_else(): void
    {
        Queue::fake();

        $due = Publication::factory()->scheduled('2026-09-10 09:00:00')->create(['creator_id' => $this->owner->id]);
        $later = Publication::factory()->scheduled('2026-09-10 18:00:00')->create(['creator_id' => $this->owner->id]);
        // A DRAFT carrying a moment. It is not armed, and the difference is the entire point of arming.
        $draft = Publication::factory()->withScheduledAt('2026-09-10 08:00:00')->create([
            'creator_id' => $this->owner->id,
        ]);

        $this->travelTo('2026-09-10 09:01:00');

        $this->runCommand('publishing:dispatch-due');

        Queue::assertPushed(
            PublishPublicationJob::class,
            fn (PublishPublicationJob $job): bool => $job->publicationId === $due->id,
        );
        Queue::assertPushed(PublishPublicationJob::class, 1);

        $this->assertSame(PublicationStatus::PUBLISHING, $due->fresh()->status);
        $this->assertSame(PublicationStatus::SCHEDULED, $later->fresh()->status);
        $this->assertSame(PublicationStatus::DRAFT, $draft->fresh()->status);
    }

    /**
     * THE JOB CARRIES ITS OWN WORKSPACE, read off the row rather than off the context.
     *
     * The shared pass runs deliberately UNSCOPED — that is what makes one query cover every shared
     * workspace — so at dispatch time there is no active tenant for `QueueTenancy` to stamp. A job that
     * relied on that stamp would restore no tenancy at all, and for an own-database workspace it would
     * read and write the CENTRAL tables.
     */
    public function test_the_dispatched_job_names_the_workspace_the_publication_belongs_to(): void
    {
        Queue::fake();

        Publication::factory()->scheduled('2026-09-10 09:00:00')->create(['creator_id' => $this->owner->id]);

        $this->travelTo('2026-09-10 09:01:00');

        $this->runCommand('publishing:dispatch-due');

        Queue::assertPushed(
            PublishPublicationJob::class,
            fn (PublishPublicationJob $job): bool => $job->workspaceId === $this->workspace->id,
        );
    }

    // ══ the worker ══════════════════════════════════════════════════════════════════════════════

    /** THE HAPPY PATH, end to end through the sweep, the job and the shipped dry-run adapter. */
    public function test_a_due_publication_goes_all_the_way_out_through_the_queue(): void
    {
        $publication = Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'creator_id' => $this->owner->id,
        ]);

        $this->travelTo('2026-09-10 09:01:00');

        // No Queue::fake: the test connection is `sync`, so the job really runs.
        $this->runCommand('publishing:dispatch-due');

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertTrue($fresh->isPublicArtifact());
        $this->assertSame(1, $fresh->attempts, 'the sweep claimed once; the job must not claim again');

        $this->assertSame(
            ['draft', 'publish'],
            PublicationAttempt::query()
                ->where('publication_id', $publication->id)
                ->orderBy('created_at')
                ->pluck('phase')
                ->map(fn (PublicationAttemptPhase $phase): string => $phase->value)
                ->all(),
        );
    }

    /**
     * A ROW THE WORKER NO LONGER HOLDS IS NOT PUBLISHED.
     *
     * The worker arrives with an id, minutes after the claim, and an id is not proof of a claim. If
     * something else concluded the attempt in the meantime — a reaper, a person, an earlier delivery —
     * publishing now is the duplicate this module exists to prevent.
     */
    public function test_the_publisher_refuses_a_row_that_was_never_claimed(): void
    {
        $publication = Publication::factory()->scheduled()->create(['creator_id' => $this->owner->id]);

        $this->expectException(LogicException::class);

        app(PublicationPublisher::class)->publishClaimed($publication);
    }

    /**
     * AND A WORKER THAT ARRIVES LATE DOES NOTHING AT ALL — it does not even reach the guard.
     *
     * The realistic version of the same hazard: the job is delivered (or redelivered) for a publication
     * a reaper has since parked. It must return quietly rather than publish, and rather than throw —
     * throwing would route to `failed()`, which would then have an opinion about a row that is not its
     * business.
     */
    public function test_a_job_whose_publication_was_already_concluded_does_nothing(): void
    {
        $parked = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        (new PublishPublicationJob($parked->id, $this->workspace->id))->handle(app(PublicationPublisher::class));

        $fresh = $parked->fresh();

        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $fresh->status);
        $this->assertNull($fresh->remote_id, 'nothing may have been published');
        $this->assertSame(
            0,
            PublicationAttempt::query()->where('publication_id', $parked->id)->count(),
            'no platform may have been touched at all',
        );
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE HOOK PARKS. IT DOES NOT FAIL.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The single most consequential line in the job, and the one whose wrong version looks completely
     * natural: a dead job marking its row `failed`. `failed` is a CLAIM ABOUT THE WORLD — "the platform
     * refused and nothing was created" — and it is what re-opens the ordinary retry. A worker whose
     * process has just been killed is in no position to make that claim: it may have died a millisecond
     * after the platform accepted the post.
     *
     * So the hook parks the row in `needs_reconcile`, which no automatic path leaves, and the retry
     * stays behind a question somebody has to ask the platform.
     */
    public function test_a_dead_publish_is_parked_for_reconciliation_and_never_marked_failed(): void
    {
        $publication = Publication::factory()->publishing()->create(['creator_id' => $this->owner->id]);

        (new PublishPublicationJob($publication->id, $this->workspace->id))
            ->failed(new RuntimeException('the worker was killed'));

        $fresh = $publication->fresh();

        $this->assertSame(
            PublicationStatus::NEEDS_RECONCILE,
            $fresh->status,
            'a job that died holding a claim must park the row, not fail it — failing it asserts nothing '
            . 'was created, which a dead process cannot know, and it re-opens an automatic retry',
        );
        $this->assertSame('publish_worker_failed', $fresh->failure_code);

        // And from here the machine itself refuses the retry, whatever anybody intended.
        $this->expectException(PublicationTransitionRefused::class);
        $this->manager->claim($fresh);
    }

    /**
     * THE HOOK NEVER OVERWRITES A CONCLUSION SOMEBODY ELSE REACHED.
     *
     * It can fire late, and it can fire for a redelivery of a job whose original is still running or has
     * already finished. Only a row still sitting in `publishing` is this delivery's to conclude.
     */
    public function test_the_failure_hook_leaves_a_publication_that_is_already_resolved(): void
    {
        $published = Publication::factory()->published()->create(['creator_id' => $this->owner->id]);

        (new PublishPublicationJob($published->id, $this->workspace->id))
            ->failed(new RuntimeException('a late redelivery'));

        $this->assertSame(
            PublicationStatus::PUBLISHED,
            $published->fresh()->status,
            'the hook must not touch a row something else already concluded',
        );
    }

    // ══ the compare-and-swap ════════════════════════════════════════════════════════════════════

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * A CONCLUSION REACHED ON AN OUT-OF-DATE COPY IS REFUSED. THE MANAGER, DIRECTLY.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The defect class this whole section is about: the transition used to check the edge against the
     * status IT HELD IN MEMORY and then write unconditionally. Two in-memory copies of one row are
     * ORDINARY here — the sweep holds one while a worker holds another, minutes and a process apart —
     * so the older copy could write straight over a decision made with more information, without ever
     * taking an illegal edge on paper.
     *
     * This is the isolating form, and it uses the sharpest pair available: `published` is TERMINAL, and
     * a copy that still remembers `publishing` must not be able to move it anywhere at all. The edge
     * `publishing → failed` exists, which is precisely why an in-memory check waves this through.
     */
    public function test_a_stale_copy_cannot_write_over_a_publication_that_was_already_published(): void
    {
        $publication = Publication::factory()->publishing()->create(['creator_id' => $this->owner->id]);

        // A SECOND copy of the same row, taken while it is still `publishing`. This is the sweep's
        // `$claimed` while the worker holds its own.
        $stale = Publication::query()->findOrFail($publication->id);

        $this->manager->markPublished($publication, RemoteRef::make('dryrun_it_really_went_out'));

        try {
            $this->manager->markFailed($stale, PublicationQueueService::FAILURE_DISPATCH);

            $this->fail(
                'a transition decided on an out-of-date copy must be refused, not written. This one '
                . 'rewrote a PUBLISHED row — a public artifact — as failed.',
            );
        } catch (PublicationTransitionRefused $e) {
            $this->assertSame(PublicationTransitionRefused::LOST_RACE, $e->reason);
        }

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertSame('dryrun_it_really_went_out', $fresh->remote_id);
        $this->assertNull($fresh->failure_code, 'nothing may have been written on the refused path');

        $this->assertSame(
            PublicationStatus::PUBLISHED,
            $stale->status,
            'a refused caller must be left holding the truth, not the copy it guessed from — otherwise '
            . 'the next thing it does is decided from the same stale value',
        );
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE QUEUE ACCEPTED THE JOB, THE JOB PUBLISHED, AND THEN THE DISPATCH THREW.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The realistic form of the defect above, and the one with a customer-visible ending. Under a queue
     * that runs the job inline, `dispatch()` returns only AFTER the publish has happened — so a throw
     * from anywhere in that call is a throw about a publication that is already out. The sweep's
     * `catch` then concluded the row from the copy it had claimed minutes of work earlier.
     *
     * The result was a row reading `failed` while carrying the `remote_id` OF A LIVE POST, which the
     * product then offers to schedule again. That is the doubled public artifact, reached without a
     * single illegal edge.
     */
    public function test_a_dispatch_that_throws_after_the_job_published_never_rewrites_the_published_row(): void
    {
        $publication = Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'creator_id' => $this->owner->id,
        ]);

        $this->travelTo('2026-09-10 09:01:00');

        // The queue write fails AFTER the job has fired. `JobProcessed` is the faithful seam: under the
        // sync driver it is raised once the job has run, and a throw from it propagates out of
        // `dispatch()` exactly as a queue-side failure would.
        Queue::after(function (): void {
            throw new RuntimeException('the queue went away after the job had already run');
        });

        $this->runCommand('publishing:dispatch-due');

        $fresh = $publication->fresh();

        $this->assertSame(
            PublicationStatus::PUBLISHED,
            $fresh->status,
            'the publication went out. A sweep that then wrote `failed` from its own stale copy would '
            . 'be offering to publish a live post a second time.',
        );
        $this->assertNotNull($fresh->remote_id, 'the post exists and the row must keep saying so');
        $this->assertNull(
            $fresh->failure_code,
            'a row that is published carries no failure — least of all `dispatch_failed`, which asserts '
            . 'that nothing was sent anywhere',
        );
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE SAME RACE AGAINST `needs_reconcile`, WHICH IS A BREACH OF FENCE 1.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * Here the job parked the row — it does not know whether the platform received anything — and the
     * sweep's stale copy then degraded that park to `failed`. `failed` is a CLAIM ABOUT THE WORLD
     * ("nothing was created") and it is what re-opens the ordinary retry, so this is the one edge the
     * module exists to make unreachable, taken by a component that never looked at the row.
     */
    public function test_a_dispatch_that_throws_after_the_job_parked_never_degrades_the_park_to_failed(): void
    {
        // A platform that accepts the container and then goes silent: the publisher cannot classify it,
        // so the row is parked rather than failed.
        $this->useAdapter(new class implements PlatformAdapter
        {
            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::DRY_RUN;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                return RemoteDraft::make('dryrun_draft_then_silence');
            }

            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                throw new RuntimeException('the platform stopped answering mid-call');
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                return null;
            }
        });

        $publication = Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'creator_id' => $this->owner->id,
        ]);

        $this->travelTo('2026-09-10 09:01:00');

        Queue::after(function (): void {
            throw new RuntimeException('the queue went away after the job had already run');
        });

        $this->runCommand('publishing:dispatch-due');

        $fresh = $publication->fresh();

        $this->assertSame(
            PublicationStatus::NEEDS_RECONCILE,
            $fresh->status,
            'the worker could not tell whether the platform received the post, so the row was parked. '
            . 'Rewriting that as `failed` asserts absence nobody established and re-opens the retry.',
        );
        $this->assertSame(
            'publish_outcome_unknown',
            $fresh->failure_code,
            'the reason must stay the one the worker recorded, not the sweep\'s `dispatch_failed`',
        );
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * A REDELIVERY PARKS A LIVE PUBLISH, AND THE ORIGINAL JOB STANDS DOWN QUIETLY.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The interleave `config/publishing.php` orders `publish_timeout` against `retry_after` to avoid,
     * and which the job's docblock used to claim "the status guard keeps safe" while the guard read an
     * in-memory value. Under `tries = 1` a redelivery is failed BEFORE any middleware runs, so its
     * `failed()` hook fires against a publish that is still in flight and parks the row. The original
     * job then finishes and reaches its own conclusion.
     *
     * TWO PROPERTIES, and the second is why this test exists at all:
     *   1. the park stands — the original's `failed` must not overwrite it (Fence 1 again);
     *   2. the original job does not blow up. It has been beaten to the conclusion, which is not an
     *      error it can do anything about; throwing would fail a delivery and log noise for a row that
     *      is already in exactly the right state.
     */
    public function test_a_redelivery_that_parks_a_live_publish_wins_and_the_original_job_stands_down(): void
    {
        $publication = Publication::factory()->publishing()->create(['creator_id' => $this->owner->id]);

        $this->useAdapter(new class($publication->id, $this->workspace->id) implements PlatformAdapter
        {
            public function __construct(
                private string $publicationId,
                private string $workspaceId,
            ) {}

            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::DRY_RUN;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                return RemoteDraft::make('dryrun_draft_mid_flight');
            }

            /** The redelivery's hook fires WHILE this publish is in flight. Deterministically. */
            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                (new PublishPublicationJob($this->publicationId, $this->workspaceId))
                    ->failed(new RuntimeException('a redelivery, failed before any middleware ran'));

                throw new PlatformRefused('platform_said_no');
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                return null;
            }
        });

        // No expectException and no try/catch: anything escaping here fails the test, which IS
        // property 2.
        (new PublishPublicationJob($publication->id, $this->workspace->id))
            ->handle(app(PublicationPublisher::class));

        $fresh = $publication->fresh();

        $this->assertSame(
            PublicationStatus::NEEDS_RECONCILE,
            $fresh->status,
            'the redelivery parked a publish whose outcome nobody knows; the original job\'s own '
            . '`failed` must not overwrite that with an assertion of absence',
        );
        $this->assertSame('publish_worker_failed', $fresh->failure_code);
    }

    /**
     * THE TWIN OF THE TEST ABOVE, WITH THE OPPOSITE ENDING: the live publish SUCCEEDS after the park.
     *
     * The one interleave nobody scripted, and the one where the module used to lie in its log: the
     * worker, beaten to the row by a redelivery's park, finished with a RemoteRef in hand — proof of
     * a live post — and the tail of that success fell into the unknown-state catch, which logged an
     * ERROR claiming ignorance about an outcome this frame knew precisely, then threw a second,
     * misleading refusal (from == to) out of the follow-up park.
     *
     * The row's END STATE is identical either way — `needs_reconcile`, park intact — which is exactly
     * why this test asserts on the LOG: it is the only observable that separates "the machinery
     * noticed it lost a race" from "the machinery claims not to know what happened to a post it is
     * holding the id of". And the id is not lost: the final assertions walk the recovery, and the
     * probe concludes the very artifact the worker published.
     */
    public function test_a_live_publish_that_succeeds_after_being_parked_stands_down_without_an_error(): void
    {
        $publication = Publication::factory()->publishing()->create(['creator_id' => $this->owner->id]);

        $this->useAdapter(new class($publication->id, $this->workspace->id) implements PlatformAdapter
        {
            public function __construct(
                private string $publicationId,
                private string $workspaceId,
            ) {}

            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::DRY_RUN;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                return RemoteDraft::make('dryrun_draft_mid_flight');
            }

            /** The redelivery parks mid-flight — and then this publish SUCCEEDS anyway. */
            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                (new PublishPublicationJob($this->publicationId, $this->workspaceId))
                    ->failed(new RuntimeException('a redelivery, failed before any middleware ran'));

                return new RemoteRef('dryrun_live_despite_the_park');
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                return new RemoteRef('dryrun_live_despite_the_park');
            }
        });

        Log::spy();

        // No try/catch: an unhandled refusal escaping the job fails the test.
        (new PublishPublicationJob($publication->id, $this->workspace->id))
            ->handle(app(PublicationPublisher::class));

        // The redelivery's own hook legitimately logs an ERROR (a job DID die, as far as it knows).
        // What must never appear is the publisher claiming an "unknown state" about a publish whose
        // outcome its own frame held — that line pages a person about a fiction.
        Log::shouldNotHaveReceived('error', [
            Mockery::on(static fn (string $message): bool => str_contains($message, 'unknown state')),
            Mockery::any(),
        ]);
        Log::shouldHaveReceived('info', [
            Mockery::on(static fn (string $message): bool => str_contains($message, 'lost the race')),
            Mockery::any(),
        ]);

        $fresh = $publication->fresh();
        $this->assertSame(
            PublicationStatus::NEEDS_RECONCILE,
            $fresh->status,
            'the park must stand — the live publish\'s own success may not overwrite it',
        );

        // The proof the worker held is not lost: the probe concludes the same artifact.
        $resolved = app(PublicationPublisher::class)->reconcile($fresh);
        $this->assertSame(PublicationStatus::PUBLISHED, $resolved->status);
        $this->assertSame('dryrun_live_despite_the_park', $resolved->remote_id);
    }

    // ══ the reaper ══════════════════════════════════════════════════════════════════════════════

    /**
     * A CLAIM NOBODY CAME BACK FOR.
     *
     * SIGKILL, OOM, a worker restart mid-call: none of them reach the job's `failed()` hook, so the row
     * keeps its claim forever — and `publishing` is neither editable nor deletable and has no automatic
     * exit, so the publication is beyond every affordance the product offers until something moves it.
     *
     * The reaper moves it to `needs_reconcile` and stops there. It knows only that a claim is old.
     */
    public function test_the_reaper_parks_a_publication_stranded_in_publishing(): void
    {
        $stranded = Publication::factory()->publishing()->create([
            'creator_id' => $this->owner->id,
            'last_attempt_at' => now()->subSeconds((int) config('publishing.queue.stale_after') + 60),
        ]);

        $this->assertSame(1, $this->queue->reapStalePublishing());

        $fresh = $stranded->fresh();

        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $fresh->status);
        $this->assertSame(
            PublicationQueueService::FAILURE_REAPED,
            $fresh->failure_code,
            'the reason has to say a claim went stale, not that the platform refused — nobody asked a platform',
        );
    }

    /**
     * A SLOW PUBLISH IS NOT A DEAD ONE.
     *
     * The threshold is many times the job's own timeout precisely so this cannot happen: reaping a row
     * whose platform call is still in flight would park a publication that is about to succeed, and — in
     * the window before the live job's own write — invite a probe about an artifact mid-creation.
     */
    public function test_the_reaper_leaves_a_claim_that_is_merely_recent(): void
    {
        $live = Publication::factory()->publishing()->create([
            'creator_id' => $this->owner->id,
            'last_attempt_at' => now()->subSeconds(5),
        ]);

        $this->assertSame(0, $this->queue->reapStalePublishing());
        $this->assertSame(PublicationStatus::PUBLISHING, $live->fresh()->status);
    }

    /**
     * THE REAPER NEVER RE-QUEUES ANYTHING — the version of this mechanism that would be wrong.
     *
     * Every other stale-claim reaper in this product RELEASES the claim so the work can run again, and
     * that is right for a bot run or an index. Here the same helpful instinct republishes a post that
     * may already exist. So the reaped row is left where no sweep can select it, and this test asserts
     * the absence: a pass immediately afterwards dispatches no publish.
     *
     * IT NAMES THE JOB RATHER THAN ASSERTING AN EMPTY QUEUE, and the distinction earned itself. The first
     * version asserted `assertNothingPushed()`, which was true only while this module's single queue user
     * was the publish job — so D4's failure letter (this very row concludes `failed`, which is the
     * conclusion a letter is written for) turned it red without anything about the reaper having changed.
     * "The reaper does not republish" is the property; "nothing whatsoever reaches the queue" was a
     * coincidence standing in for it, and a coincidence that would also have hidden the next legitimate
     * side effect behind a failure in this file.
     */
    public function test_a_reaped_publication_is_never_handed_back_to_the_queue(): void
    {
        Queue::fake();

        $stranded = Publication::factory()->publishing()->create([
            'creator_id' => $this->owner->id,
            'last_attempt_at' => now()->subSeconds((int) config('publishing.queue.stale_after') + 60),
        ]);

        $this->runCommand('publishing:reconcile');

        Queue::assertNotPushed(PublishPublicationJob::class);

        $this->runCommand('publishing:dispatch-due');

        Queue::assertNotPushed(PublishPublicationJob::class);

        // NAMED, AND NAMED WITH ITS REASON — `assertNotSame(PUBLISHING, …)` passed for every other
        // status in the enum, including the one that would be a defect.
        //
        // The end state is `failed`, and it is reached legitimately: this command reaps and THEN probes,
        // and the dry-run adapter finds no evidence of a post, so the probe PROVES ABSENCE. What the
        // failure code pins is WHICH of the two passes concluded the row. `reconciled_absent` means a
        // platform was asked. `reaper_stale` here would mean the reaper had degraded its own park into
        // an assertion that nothing was created — which it has no way to know and which re-opens the
        // automatic retry.
        $fresh = $stranded->fresh();

        $this->assertSame(PublicationStatus::FAILED, $fresh->status);
        $this->assertSame('reconciled_absent', $fresh->failure_code);
    }

    // ══ reconciliation ══════════════════════════════════════════════════════════════════════════

    /**
     * THE RECOVERY THAT MAKES THE WHOLE ARRANGEMENT WORTH HAVING.
     *
     * The commonest real stranding: the platform accepted the post and the process died before the
     * status write. The evidence is an attempt row; `publications.remote_id` is empty, which is exactly
     * why the adapter answers from the trail rather than from the row it is being asked about.
     *
     * The sweep reaps it and then, in the same pass, resolves it to `published` with the real remote id
     * — with nobody having looked at a screen.
     */
    public function test_the_sweep_reaps_a_stranded_publish_and_then_finds_the_post_it_had_already_made(): void
    {
        $stranded = Publication::factory()->publishing()->create([
            'creator_id' => $this->owner->id,
            'last_attempt_at' => now()->subSeconds((int) config('publishing.queue.stale_after') + 60),
            'remote_draft_id' => 'dryrun_draft_the_container',
        ]);

        PublicationAttempt::create([
            'publication_id' => $stranded->id,
            'platform' => PublishingPlatform::DRY_RUN,
            'phase' => PublicationAttemptPhase::PUBLISH,
            'succeeded' => true,
            'attempt' => 1,
            'remote_draft_id' => 'dryrun_draft_the_container',
            'remote_id' => 'dryrun_it_was_out_all_along',
        ]);

        $this->assertNull($stranded->remote_id, 'the row must not know about it yet — that is the scenario');

        $this->runCommand('publishing:reconcile');

        $fresh = $stranded->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertSame('dryrun_it_was_out_all_along', $fresh->remote_id);
    }

    /**
     * PROVEN ABSENT re-opens the ordinary retry — and it is a PERSON who takes it.
     *
     * `failed` is reachable automatically only because the platform was asked and answered. From there
     * `POST /schedule` is a legal move, which is why B3 ships no retry endpoint: the retry somebody
     * wants is the arming they already have, reached through the one door that establishes it is safe.
     */
    public function test_a_reconciliation_that_proves_absence_re_opens_the_ordinary_retry(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        $this->runCommand('publishing:reconcile');

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::FAILED, $fresh->status);
        $this->assertSame('reconciled_absent', $fresh->failure_code);

        // Arming it again is now a legal move — which it was not one line ago.
        $this->manager->arm($fresh, now()->addDay());
        $this->assertSame(PublicationStatus::SCHEDULED, $fresh->fresh()->status);
    }

    /**
     * A PROBE THAT LEARNS NOTHING MOVES NOTHING, FOREVER, WITHOUT ESCALATING.
     *
     * There is no attempt limit and no eventual give-up here, and that is the design rather than an
     * omission: a publication nobody can answer for is honestly in the state that says so. The failure
     * this guards against is a future "after N tries, give up and mark it failed" — which would assert
     * absence that nothing established.
     */
    public function test_a_reconciliation_that_cannot_answer_leaves_the_publication_exactly_where_it_was(): void
    {
        $this->useAdapter(new class implements PlatformAdapter
        {
            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::DRY_RUN;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                throw new RuntimeException('unreachable');
            }

            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                throw new RuntimeException('unreachable');
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                throw new RuntimeException('the platform has no way to tell us');
            }
        });

        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        // Twice, and the cooldown is cleared between them, so "it did not move" is not merely "it was
        // never asked".
        $this->queue->reconcilePending();
        $this->forgetProbeCooldown($publication);
        $this->queue->reconcilePending();

        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $publication->fresh()->status);
        $this->assertSame(
            'publish_outcome_unknown',
            $publication->fresh()->failure_code,
            'nothing was learned, so not even the reason may be rewritten',
        );
    }

    /**
     * THE AUTOMATIC PROBE IS RATE-LIMITED PER PUBLICATION.
     *
     * `needs_reconcile` has no exit and no limit on attempts, so without a cooldown a row nobody can
     * ever answer for becomes a question asked every five minutes forever — 288 API calls a day against
     * somebody's rate limit, on behalf of a publication that is not going to resolve. The cooldown is
     * the reason "no limit on attempts" is affordable.
     */
    public function test_the_automatic_probe_asks_a_platform_at_most_once_per_cooldown(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        // The adapter proves absence, so the row leaves needs_reconcile on the first pass — put it back
        // to make the second pass's restraint the only thing under test.
        $this->queue->reconcilePending();
        $this->manager->arm($publication->fresh(), now()->addDay());
        $this->manager->claim($publication->fresh());
        $this->manager->markNeedsReconcile($publication->fresh(), 'publish_outcome_unknown');

        $counts = $this->queue->reconcilePending();

        $this->assertSame(0, $counts['probed'], 'a second probe inside the cooldown must not be made');
        $this->assertSame(
            1,
            PublicationAttempt::query()
                ->where('publication_id', $publication->id)
                ->where('phase', PublicationAttemptPhase::RECONCILE)
                ->count(),
            'the platform must have been asked exactly once',
        );
    }

    // ══ the manual endpoint ═════════════════════════════════════════════════════════════════════

    /** A PERSON ASKING IS NOT A SWEEP: the endpoint answers immediately and ignores the cooldown. */
    public function test_a_person_can_reconcile_a_publication_through_the_endpoint(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        PublicationAttempt::create([
            'publication_id' => $publication->id,
            'platform' => PublishingPlatform::DRY_RUN,
            'phase' => PublicationAttemptPhase::PUBLISH,
            'succeeded' => true,
            'attempt' => 1,
            'remote_id' => 'dryrun_found_by_a_person',
        ]);

        // A sweep asked five minutes ago, so the cooldown is live. It must not silence a person.
        cache()->put('publishing:reconcile-probe:' . $publication->id, true, 3600);

        $this->asOwner()
            ->postJson("/api/publishing/publications/{$publication->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', PublicationStatus::PUBLISHED->value)
            ->assertJsonPath('data.remote_id', 'dryrun_found_by_a_person');
    }

    /**
     * "A PERSON ASKING IS NOT A SWEEP" DOES NOT MEAN "A PERSON IS UNLIMITED".
     *
     * The endpoint skips the probe cooldown by design, and every call spends the PLATFORM's rate
     * limit — which is per-application, so a held-down button (or a loop around this route) degrades
     * publishing for every workspace on the install, and each publication it knocks into
     * `needs_reconcile` becomes further probe traffic of its own. The route throttle is the bound.
     *
     * The burst below goes through the REAL route stack on one authenticated user; the concluding 403s
     * after the first call still count against the bucket (the throttle runs before authorization),
     * which is exactly right — a refused question was still a question.
     */
    public function test_the_manual_reconcile_endpoint_is_throttled(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        for ($i = 1; $i <= 6; $i++) {
            $status = $this->asOwner()
                ->postJson("/api/publishing/publications/{$publication->id}/reconcile")
                ->status();

            $this->assertNotSame(429, $status, "request {$i} of 6 must still be inside the throttle");
        }

        $this->asOwner()
            ->postJson("/api/publishing/publications/{$publication->id}/reconcile")
            ->assertStatus(429);
    }

    /**
     * THE ENDPOINT IS OFFERED FOR EXACTLY ONE STATUS, and the capability flag says the same thing.
     *
     * A reconcile button on a `scheduled` row would be a button whose only possible outcome is a 422
     * from the transition table. The policy and the flag are one computation, so the UI cannot offer
     * what the write path refuses.
     */
    public function test_reconciling_is_refused_for_a_publication_that_is_not_awaiting_reconciliation(): void
    {
        $scheduled = Publication::factory()->scheduled()->create(['creator_id' => $this->owner->id]);

        $this->asOwner()
            ->getJson("/api/publishing/publications/{$scheduled->id}")
            ->assertOk()
            ->assertJsonPath('data.can_be_reconciled', false);

        $this->asOwner()
            ->postJson("/api/publishing/publications/{$scheduled->id}/reconcile")
            ->assertForbidden();

        $this->assertSame(PublicationStatus::SCHEDULED, $scheduled->fresh()->status);
    }

    /** And it IS offered for the one status that has it — otherwise the assertion above is vacuous. */
    public function test_a_publication_awaiting_reconciliation_advertises_the_capability(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        $this->asOwner()
            ->getJson("/api/publishing/publications/{$publication->id}")
            ->assertOk()
            ->assertJsonPath('data.can_be_reconciled', true)
            // The other two affordances are deliberately absent in this state: a row that may correspond
            // to a live post must not be edited into disagreeing with it, nor deleted into being
            // unattributable.
            ->assertJsonPath('data.can_be_edited', false)
            ->assertJsonPath('data.can_be_deleted', false);
    }

    // ══ the scheduler ═══════════════════════════════════════════════════════════════════════════

    /**
     * THE SWEEPS EXIST ONLY IF SOMETHING RUNS THEM. Deleting a line from routes/console.php produces
     * no error, no failing command test (the commands still work when invoked), and no symptom except
     * publishing quietly stopping — the exact bug shape ScheduledMaintenanceCommandsTest exists for,
     * except these commands are the product's engine, not maintenance, so its derived pin (reap/sweep/
     * prune in the name) never sees them. Hence a named pin here, in the module's own suite.
     *
     * The mutex EXPIRY is asserted alongside the cadence, because it is a deliberate deviation from the
     * file's bare `withoutOverlapping()` convention: the default lock lives 24 hours, and a
     * schedule:run killed mid-command would otherwise stop all publishing — silently — for a day.
     */
    public function test_the_publishing_sweeps_are_on_the_scheduler_with_expiring_locks(): void
    {
        $expected = [
            'publishing:dispatch-due' => ['* * * * *', 5],
            'publishing:reconcile' => ['*/5 * * * *', 10],
            'publishing:refresh-tokens' => ['0 * * * *', 30],
        ];

        $events = app(Schedule::class)->events();

        foreach ($expected as $command => [$cron, $lockMinutes]) {
            $matches = array_values(array_filter(
                $events,
                static fn ($event): bool => str_contains((string) $event->command, $command),
            ));

            $this->assertCount(1, $matches, "{$command} must be registered on the scheduler exactly once");
            $this->assertSame($cron, $matches[0]->expression, "{$command} runs on the wrong cadence");
            $this->assertSame($lockMinutes, $matches[0]->expiresAt, "{$command} must hold its overlap lock for {$lockMinutes} minutes, not the 24-hour default");
        }
    }

    // ══ mass assignment ═════════════════════════════════════════════════════════════════════════

    /**
     * `status` IS NOT MASS-ASSIGNABLE, AND THE SCAN CANNOT SEE WHY THAT MATTERS.
     *
     * `PublishingStateMachineTest` reads the module's own bytes for a status write. The door this closes
     * is the one OUTSIDE the module: any caller anywhere handing a status-shaped key to `create()`,
     * `update()` or `fill()` — a workflow step, a future importer, a controller in another module — is a
     * write the scan will never see, because the offending line is not in a file it scans.
     *
     * Both entrances are checked: the create path (where the column default is what should win) and the
     * update path (where the value should simply be discarded).
     */
    public function test_a_publication_status_cannot_be_mass_assigned(): void
    {
        $created = Publication::create([
            'title' => 'Zapowiedź',
            'platform' => PublishingPlatform::DRY_RUN,
            'status' => PublicationStatus::PUBLISHED->value,
            'creator_id' => $this->owner->id,
        ]);

        $this->assertSame(
            PublicationStatus::DRAFT,
            $created->fresh()->status,
            'a create carrying a status must produce a DRAFT — the column default wins, not the payload',
        );

        $scheduled = Publication::factory()->scheduled()->create(['creator_id' => $this->owner->id]);

        $scheduled->update(['title' => 'Nowy tytuł', 'status' => PublicationStatus::PUBLISHED->value]);

        $fresh = $scheduled->fresh();

        $this->assertSame('Nowy tytuł', $fresh->title, 'the legitimate half of the payload must still apply');
        $this->assertSame(
            PublicationStatus::SCHEDULED,
            $fresh->status,
            'the status half must be discarded — only PublicationManager moves a publication',
        );

        // The Manager is unaffected: it writes with forceFill, which is exempt by design.
        $this->manager->claimDue($fresh);
        $this->assertSame(PublicationStatus::PUBLISHING, $fresh->fresh()->status);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    private function asOwner(): self
    {
        parent::actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /**
     * Run a sweep and put the workspace back.
     *
     * The commands clear the tenant context in a `finally` — deliberately, so a long-lived process never
     * inherits one — which would otherwise leave every assertion after them running unscoped.
     */
    private function runCommand(string $signature): void
    {
        $this->artisan($signature)->assertSuccessful();

        app(TenantContext::class)->set($this->workspace);
    }

    /** Clear the per-publication probe cooldown, so a second pass really asks. */
    private function forgetProbeCooldown(Publication $publication): void
    {
        cache()->forget('publishing:reconcile-probe:' . $publication->id);
    }

    /** Swap the dry-run slot for a probe adapter — the registry refuses a second registration. */
    private function useAdapter(PlatformAdapter $adapter): void
    {
        $registry = new PlatformAdapterRegistry;
        $registry->register($adapter);

        $this->app->instance(PlatformAdapterRegistry::class, $registry);

        $this->queue = $this->app->make(PublicationQueueService::class);
    }
}
