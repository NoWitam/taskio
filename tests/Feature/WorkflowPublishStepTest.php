<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Events\PublicationConcluded;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workflows\Steps\PublishStep;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/**
 * R4 B6 — the `publish` workflow step: the second suspending step, and the only one whose settled work
 * is visible to the public.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE DEFENDS, IN ORDER OF HOW MUCH IT COSTS TO GET WRONG
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *  1. THE STEP NEVER PUBLISHES. It arms a row and the Publishing module's due-sweep does the rest,
 *     through the atomic claim that is the only thing stopping one publication becoming two posts.
 *     Pinned structurally (a byte scan) as well as behaviourally, because the behavioural version can
 *     only ever observe the paths a test happens to drive.
 *  2. `needs_reconcile` DOES NOT WAKE THE RUN. It is the absence of an answer, not a bad one — waking on
 *     it would have a workflow conclude "failed" about a publication that may be live on somebody's
 *     timeline.
 *  3. A REVIEW REALLY HOLDS IT. With a pipeline attached nothing is armed until the last approver says
 *     yes, and a refusal stops the run instead of letting every later step act as though something had
 *     gone out.
 *  4. THE MOMENT IS READ ON THE WORKSPACE'S CLOCK. Publishing at the wrong hour is not the same class
 *     of mistake as drawing an event on the wrong square: nothing recalls it.
 */
class WorkflowPublishStepTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $approver;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Named, never inherited — the suite reads the developer's `.env`, and the assertions about
        // whose clock a zone-less string is on must not take their baseline from whatever somebody last
        // switched on by hand.
        config(['app.timezone' => 'UTC']);

        $this->owner = User::factory()->create();
        $this->approver = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->owner->id,
            'timezone' => 'Europe/Warsaw',
        ]);
        $this->workspace->users()->attach([$this->owner->id, $this->approver->id]);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $config */
    private function workflowWith(array $config): Workflow
    {
        return Workflow::factory()->create([
            'creator_id' => $this->owner->id,
            'steps' => [
                ['type' => WorkflowStepType::PUBLISH->value, 'key' => 'post_it', 'config' => $config],
            ],
        ]);
    }

    /** Run the first pass. The step suspends, so the run comes back parked. */
    private function startRun(Workflow $workflow): WorkflowRun
    {
        $run = WorkflowRun::factory()->running()->create([
            'workflow_id' => $workflow->id,
            'context' => [],
            'trigger_payload' => [],
        ]);

        app(WorkflowStepRunner::class)->run($run);

        return $run->refresh();
    }

    /** The second pass, through the real resume job (claim included). */
    private function resume(WorkflowRun $run): WorkflowRun
    {
        (new WorkflowRunResumeJob($run->id, $this->workspace->id, $run->waiting_key))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );

        return $run->fresh();
    }

    /** The publication a parked run is waiting on. */
    private function waitedPublication(WorkflowRun $run): Publication
    {
        return Publication::findOrFail($run->waiting_on['payload']['publication_id']);
    }

    /** Take a scheduled publication all the way out, the way the queue would. */
    private function publish(Publication $publication, string $remoteId = 'dryrun_live_1'): void
    {
        $manager = app(PublicationManager::class);

        $manager->claimDue($publication);
        $manager->markPublished($publication->refresh(), RemoteRef::make($remoteId, 'https://example.test/' . $remoteId));
    }

    private function pipeline(): ApprovalPipeline
    {
        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $this->owner->id]);

        $pipeline->stages()->create([
            'name' => 'Review',
            'icon' => 'check-circle',
            'description' => null,
            'approver_type' => 'user',
            'approver_id' => $this->approver->id,
            'order' => 1,
        ]);

        return $pipeline;
    }

    // ---------------------------------------------------------------- arming and parking

    /**
     * THE ORDINARY PASS: a publication is created, ARMED, and the run parks on it.
     *
     * Three separate facts in one test because they are one act: the row exists and is `scheduled` (not
     * published — see the structural pin below), its moment is the author's text read on the WORKSPACE's
     * clock, and the run is parked under the correlation key the resolver and the listener both build
     * from the same helper.
     */
    public function test_the_step_arms_a_publication_and_parks_the_run(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
            'body' => 'Something new is coming.',
            // Zone-less, on a Europe/Warsaw workspace: 09:00 there is 07:00Z. Under `config('app.timezone')`
            // (UTC, set in setUp) it would be 09:00Z — so this assertion has an opinion about which rule
            // the step follows.
            'publish_at' => '2099-04-01 09:00',
        ]));

        $this->assertSame(WorkflowRunState::WAITING, $run->state);

        $publication = $this->waitedPublication($run);

        $this->assertSame('Autumn teaser', $publication->title);
        $this->assertSame('Something new is coming.', $publication->body);
        $this->assertSame(PublicationStatus::SCHEDULED, $publication->status);
        $this->assertSame(
            '2099-04-01T07:00:00+00:00',
            $publication->scheduled_at->utc()->toIso8601String(),
            "a zone-less moment is the WORKSPACE's wall time, not the server's",
        );

        // ATTRIBUTION IS AUTOMATIC, which is exactly why it is pinned: nothing in the step sets a
        // creator — HasCreator stamps the executing run because the step runner publishes it.
        $this->assertSame('workflow_run', $publication->creator_type);
        $this->assertSame($run->id, $publication->creator_id);

        $this->assertSame(PublishStep::correlationKey($publication->id), $run->waiting_key);
        $this->assertSame(PublishStep::WAIT_KIND, $run->waiting_on['kind']);
    }

    /** No stated moment means "as soon as it may" — armed for a moment the next sweep pass takes. */
    public function test_a_step_with_no_moment_arms_for_now(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Right now',
        ]));

        $publication = $this->waitedPublication($run);

        $this->assertSame(PublicationStatus::SCHEDULED, $publication->status);
        $this->assertTrue(
            $publication->scheduled_at->lessThanOrEqualTo(now()->addSeconds(5)),
            'an unstated moment is already due, which is what "immediately" means in this module',
        );
    }

    /** The ordered media ids are stored VERBATIM — pointers into the Disk, never copies. */
    public function test_media_ids_are_stored_in_order_and_never_copied(): void
    {
        $ids = [
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
        ];

        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'With media',
            'media' => $ids,
        ]));

        // Order is data: a carousel IS its sequence.
        $this->assertSame($ids, $this->waitedPublication($run)->media);
    }

    // ---------------------------------------------------------------- the outcomes

    /** PUBLISHED: the run resumes, collects the five outputs, and the workflow finishes. */
    public function test_the_run_resumes_with_the_published_outputs(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
        ]));

        $publication = $this->waitedPublication($run);
        $this->publish($publication);

        $run = $this->resume($run);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);

        $output = $run->context['steps']['post_it'];

        $this->assertSame($publication->id, $output['publication_id']);
        $this->assertSame(PublicationStatus::PUBLISHED->value, $output['status']);
        $this->assertSame('dryrun_live_1', $output['remote_id']);
        $this->assertSame('https://example.test/dryrun_live_1', $output['url']);
        $this->assertNotSame('', $output['published_at']);
    }

    /**
     * FAILED: the step stops the run and names the module's stable failure code.
     *
     * Not a soft completion. Every step after this one would otherwise run as though something had been
     * published — and in a chapter whose whole subject is irreversible artifacts, "carry on as if" is the
     * wrong default.
     */
    public function test_a_failed_publication_fails_the_step(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
        ]));

        $publication = $this->waitedPublication($run);

        $manager = app(PublicationManager::class);
        $manager->claimDue($publication);
        $manager->markFailed($publication->refresh(), 'title_missing');

        $run = $this->resume($run);

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertStringContainsString('title_missing', (string) $run->error);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * `needs_reconcile` DOES NOT WAKE THE RUN — the most important assertion in this file.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * A publication in that state MAY ALREADY BE A POST. Treating it as a conclusion would have a
     * workflow decide something from a non-answer: "failed" about an artifact that may be live, or
     * "published" with a remote id nobody established. So the resolver reports PENDING, the sweep
     * dispatches nothing, and the run waits for a probe or a person — bounded only by `wait_timeout`,
     * which is late and honest rather than early and wrong.
     *
     * MUTATION PROOF: adding `needs_reconcile` to `PublicationConcluded::concludingStatuses()` turns
     * this green assertion into a dispatched resume and a run that concludes from a state the module
     * itself calls unknown.
     */
    public function test_a_publication_needing_reconciliation_never_wakes_the_run(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
        ]));

        $publication = $this->waitedPublication($run);

        $manager = app(PublicationManager::class);
        $manager->claimDue($publication);

        Queue::fake();
        $manager->markNeedsReconcile($publication->refresh(), 'publish_outcome_unknown');

        // The conclusion event is not raised at all for this status, so no fast path fires…
        Queue::assertNothingPushed();

        // …and the waiting-run sweep, which is the backstop, also leaves it alone.
        $this->assertSame(0, app(WorkflowRunManager::class)->reapWaitingRuns());
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);

        // A resume forced anyway (a stale redelivery) re-parks rather than concluding.
        $run = $this->resume($run);
        $this->assertSame(WorkflowRunState::WAITING, $run->state);
    }

    /** A real conclusion DOES wake it, without waiting for a sweep. */
    public function test_a_conclusion_dispatches_a_correlated_resume(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
        ]));

        $publication = $this->waitedPublication($run);

        Queue::fake();
        $this->publish($publication);

        Queue::assertPushed(
            WorkflowRunResumeJob::class,
            fn (WorkflowRunResumeJob $job): bool => $job->runId === $run->id
                && $job->workspaceId === $this->workspace->id
                && $job->waitingKey === PublishStep::correlationKey($publication->id),
        );
    }

    // ---------------------------------------------------------------- with a review

    /**
     * WITH A PIPELINE, NOTHING IS ARMED until the last approver says yes — and the run is parked on the
     * publication's outcome throughout, never on the approval.
     *
     * This is the D2 decision in one test: an approval is not a trigger. No new run starts; the existing
     * one keeps waiting for the same thing it was always waiting for.
     */
    public function test_a_review_holds_the_arming_and_the_approval_performs_it(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
            'publish_at' => '2099-04-01 09:00',
            'approval_pipeline_id' => $this->pipeline()->id,
        ]));

        $publication = $this->waitedPublication($run);

        $this->assertSame(PublicationStatus::DRAFT, $publication->status);
        $this->assertNull($publication->scheduled_at, 'a review must hold the arming');
        $this->assertSame(
            '2099-04-01T07:00:00+00:00',
            $publication->arm_on_approval_at->utc()->toIso8601String(),
            'the intent is parked on the row, on the workspace clock',
        );
        $this->assertTrue($publication->isInApproval());
        $this->assertSame(WorkflowRunState::WAITING, $run->state);

        // The approval arms it. The run is STILL waiting — an armed publication is not an outcome.
        app(ApprovalService::class)->decide($publication->pendingApprovalProcess, ApprovalProcessStatus::Approved);

        $publication->refresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $publication->status);
        $this->assertSame('2099-04-01T07:00:00+00:00', $publication->scheduled_at->utc()->toIso8601String());
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);

        // And the ordinary queue path finishes the job.
        $this->publish($publication, 'dryrun_reviewed');

        $this->assertSame(WorkflowRunState::COMPLETED, $this->resume($run->fresh())->state);
    }

    /**
     * A REFUSAL FAILS THE STEP — and leaves a draft somebody can fix and send again.
     *
     * MUTATION PROOF: dropping `reviewRejected` from `PublicationOutcome::isConcluded()` leaves this run
     * parked on a decision that has already been made, until the wait timeout reports the wrong cause.
     */
    public function test_a_rejected_review_fails_the_run(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
            'approval_pipeline_id' => $this->pipeline()->id,
        ]));

        $publication = $this->waitedPublication($run);

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Rejected,
            'The caption names the wrong product.',
        );

        // THE FAST PATH, end to end and with nothing faked: the refusal raises the conclusion event, the
        // listener finds the run by its indexed `waiting_key`, and the resume job runs it. (Inline here —
        // the test queue is `sync` — which is also what makes this an assertion about the whole chain
        // rather than about a dispatch.) No sweep was needed, and none is left with anything to do.
        $run = $run->fresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.steps.publish.rejected'), $run->error);
        $this->assertSame(0, app(WorkflowRunManager::class)->reapWaitingRuns(), 'nothing is left parked');

        // Still a draft, still fixable. There is no `rejected` publication status and there must not be.
        $this->assertSame(PublicationStatus::DRAFT, $publication->fresh()->status);
        $this->assertNull($publication->fresh()->scheduled_at);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE BACKSTOP, WITH THE FAST PATH TAKEN AWAY — and this is the test the file needed most.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The test above proves the LISTENER wakes a refused run, and it proves it so thoroughly that it
     * exercises nothing else: the listener dispatches without asking the wait resolver at all, and the
     * step reads the rejection off the row itself. So the whole SWEEP path — resolver → seam → outcome →
     * SETTLED — was covered by neither, and a refused run whose event was missed (a listener that threw,
     * an event raised in a process whose dispatch was lost) would have sat parked until the wait timeout
     * reported the wrong cause.
     *
     * Here the event is suppressed on purpose and the sweep has to do it alone.
     *
     * MUTATION PROOF: dropping `reviewRejected` from `PublicationOutcome::isConcluded()` leaves this run
     * parked — and leaves the sibling test above green, which is exactly why this one exists.
     */
    public function test_the_sweep_wakes_a_refused_run_even_when_the_fast_path_is_missed(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
            'approval_pipeline_id' => $this->pipeline()->id,
        ]));

        $publication = $this->waitedPublication($run);

        // The conclusion signal never reaches the listener.
        Event::fake([PublicationConcluded::class]);

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Rejected,
            'The caption names the wrong product.',
        );

        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state, 'no fast path fired');

        // The sweep asks the resolver, which asks the module's seam, which reports a refused review.
        $this->assertSame(1, app(WorkflowRunManager::class)->reapWaitingRuns());

        $run = $run->fresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.steps.publish.rejected'), $run->error);
    }

    /** A publication deleted while the run waited can never settle — the step says so rather than timing out. */
    public function test_a_vanished_publication_fails_the_step(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
        ]));

        $this->waitedPublication($run)->delete();

        $run = $this->resume($run);

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.steps.publish.gone'), $run->error);
    }

    // ---------------------------------------------------------------- refusals before anything exists

    /**
     * AN UNUSABLE ACCOUNT IS REFUSED BEFORE THE PUBLICATION EXISTS.
     *
     * The check is re-asked at run time although the save already asked it, because a token can be
     * revoked while a definition sleeps. Refusing first is what keeps a broken account from leaving one
     * orphan draft per run, forever.
     */
    public function test_a_destination_with_no_usable_account_refuses_before_creating_anything(): void
    {
        $run = $this->startRun($this->workflowWith([
            // A public destination with no connection named: the step refuses rather than creating a row
            // that would fail at publish time on every single run.
            'platform' => PublishingPlatform::YOUTUBE->value,
            'title' => 'Autumn teaser',
        ]));

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.steps.publish.connection_unavailable'), $run->error);
        $this->assertSame(0, Publication::count(), 'a refusal must leave nothing behind');
    }

    /** A blank resolved title hard-fails: the adapters refuse a titleless publication anyway. */
    public function test_a_blank_title_fails_before_anything_is_created(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => '   ',
        ]));

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(0, Publication::count());
    }

    // ---------------------------------------------------------------- the conclusion and the CAS

    /**
     * A LOST RACE ANNOUNCES NOTHING — the conclusion event is pinned to the WINNING compare-and-swap.
     *
     * The Manager's docblock states it as a fact ("two processes concluding one publication produce one
     * conclusion and one announcement, never two"); the B6 review found the fact untested: moving the
     * announcement before the CAS, or announcing in the refusal branch, left the whole suite green. The
     * stake grows with every listener — today a duplicate announcement is a resume job that no-ops on
     * its claim; after B8 adds a broadcast, it is a screen telling a person "failed" about a post that
     * is live.
     */
    public function test_a_lost_race_never_announces_a_conclusion(): void
    {
        Event::fake([PublicationConcluded::class]);

        $publication = Publication::factory()->publishing()->create(['creator_id' => $this->owner->id]);

        // A second frame holding the SAME row at its pre-conclusion status.
        $stale = Publication::findOrFail($publication->id);

        $manager = app(PublicationManager::class);

        $manager->markPublished($publication, RemoteRef::make('dryrun_winner', 'https://example.test/w'));

        try {
            $manager->markFailed($stale, 'platform_said_no');
            $this->fail('the stale copy must be refused by the CAS');
        } catch (PublicationTransitionRefused) {
            // The refusal is the mechanism working; what this test is about is the line below.
        }

        Event::assertDispatchedTimes(PublicationConcluded::class, 1);
    }

    /**
     * A SECOND DELIVERY OF THE CONCLUSION RESUMES NOTHING TWICE. The fast path listens to an event that
     * at-least-once infrastructure may hand over again; the resume job's CLAIM (on the correlation key
     * the run was observed parked under) is what makes the duplicate a clean no-op — the run stays
     * concluded once, with the outputs written once.
     */
    public function test_a_second_delivery_of_the_conclusion_resumes_nothing_twice(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
        ]));

        $waitingKey = $run->waiting_key;

        $this->publish($this->waitedPublication($run));

        $first = $this->resume($run);

        $this->assertSame(WorkflowRunState::COMPLETED, $first->state);

        $outputs = $first->context['steps']['post_it'];

        // The redelivery: the same resume, for the same observed key, arrives again.
        (new WorkflowRunResumeJob($run->id, $this->workspace->id, $waitingKey))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );

        $second = $run->fresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $second->state);
        $this->assertSame($outputs, $second->context['steps']['post_it'], 'the outputs are written once, not twice');
    }

    // ---------------------------------------------------------------- the structural pin

    // "The step cannot publish" is pinned by byte-scan in {@see WorkflowsPublishingBoundaryTest} —
    // the file that owns EVERY structural assertion about the Workflows↔Publishing↔Approvals edges,
    // extended there to also refuse direct use of the publishing job (dispatchSync is the same escape).

    /** The wait kind is one string, built in one place, shared by the step, the resolver and the listener. */
    public function test_the_wait_kind_and_correlation_key_have_a_single_source(): void
    {
        $this->assertSame('publication', PublishStep::WAIT_KIND);
        $this->assertSame('publication:abc', PublishStep::correlationKey('abc'));
    }

    /**
     * The step's declared outputs and what `resume()` actually publishes are the same set.
     *
     * They are two lists in two methods, and the descriptors are what the variable catalog (and the
     * editor's picker) build a later step's references from — so a descriptor with no value behind it is
     * a reference that silently resolves to nothing.
     */
    public function test_every_declared_output_is_actually_published(): void
    {
        $run = $this->startRun($this->workflowWith([
            'platform' => PublishingPlatform::DRY_RUN->value,
            'title' => 'Autumn teaser',
        ]));

        $this->publish($this->waitedPublication($run));

        $declared = array_column(PublishStep::outputDescriptors(), 'name');
        sort($declared);

        $published = array_keys($this->resume($run)->context['steps']['post_it']);
        sort($published);

        $this->assertSame($declared, $published);
        $this->assertCount(5, $declared, 'the descriptor list and this count move together');
    }

    /** The step is suspendable and reachable from the factory — a type nothing can build is a dead case. */
    public function test_the_step_type_is_registered_and_suspendable(): void
    {
        $step = app(\App\Modules\Workflows\Services\WorkflowStepFactory::class)
            ->make(WorkflowStepType::PUBLISH);

        $this->assertInstanceOf(PublishStep::class, $step);
        $this->assertInstanceOf(\App\Modules\Workflows\Steps\SuspendableWorkflowStep::class, $step);
        $this->assertTrue((new ReflectionClass($step))->implementsInterface(\App\Modules\Workflows\Steps\SuspendableWorkflowStep::class));
    }
}
