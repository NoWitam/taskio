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
use App\Modules\Publishing\Exceptions\UnknownPlatformAdapter;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublicationAttempt;
use App\Modules\Publishing\Services\PlatformAdapterRegistry;
use App\Modules\Publishing\Services\PublicationPublisher;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * R4 B1 — THE WHOLE PUBLISH, on the dry-run adapter, plus every way it can go wrong.
 *
 * The adapter under test is not a double: it is the shipped `DryRunPlatformAdapter`, registered in the
 * container by the module's own provider. That matters — a stub wired up only for tests would exercise
 * machinery that does not ship, and this suite is the thing standing between the module and a duplicate
 * post.
 *
 * The theme running through most of these is IDEMPOTENCY ACROSS A CRASH. The `remote_draft_id` column,
 * the deliberate absence of a transaction around the two phases, and the whole `needs_reconcile` state
 * exist for one scenario: a worker dies between "the platform accepted our container" and "we recorded
 * that it did". Every test below is a variation on where exactly it died.
 */
class PublishingDryRunPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private PublicationPublisher $publisher;

    private PublicationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);

        $this->publisher = app(PublicationPublisher::class);
        $this->manager = app(PublicationManager::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * THE ACCEPTANCE CRITERION: draft → scheduled → publishing → published, on a real adapter.
     */
    public function test_a_publication_goes_all_the_way_through_on_the_dry_run_adapter(): void
    {
        $publication = Publication::factory()->create(['creator_id' => $this->owner->id]);

        $this->assertSame(PublicationStatus::DRAFT, $publication->status);

        $this->manager->arm($publication, now()->addHour());
        $this->assertSame(PublicationStatus::SCHEDULED, $publication->fresh()->status);

        $this->publisher->publish($publication);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertTrue($fresh->isPublicArtifact(), 'a published publication must carry a remote id');
        $this->assertStringStartsWith('dryrun_', $fresh->remote_id);
        $this->assertNotNull($fresh->published_at);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->failure_code);

        // BOTH PHASES really ran, and are on record as separate events. A single combined call would
        // pass every assertion above while losing the one fact a crash makes interesting.
        $phases = PublicationAttempt::query()
            ->where('publication_id', $publication->id)
            ->orderBy('created_at')
            ->pluck('phase')
            ->map(fn (PublicationAttemptPhase $phase): string => $phase->value)
            ->all();

        $this->assertSame(['draft', 'publish'], $phases);
    }

    /**
     * THE HANDLE IS ON DISK BEFORE PHASE 2 RUNS — the assertion the whole column exists for.
     *
     * The probe adapter completes phase 1 and then THROWS in phase 2, which is exactly the shape of a
     * worker dying mid-publish. If `remote_draft_id` were written in the same transaction as the
     * outcome, or only on success, it would be gone by the time this reads it — and the next attempt
     * would create a SECOND container.
     */
    public function test_the_phase_one_handle_survives_a_phase_two_failure(): void
    {
        $this->useAdapter(new class implements PlatformAdapter
        {
            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::DRY_RUN;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                return RemoteDraft::make('container_from_phase_one');
            }

            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                throw new RuntimeException('the worker died here');
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                return null;
            }
        });

        $publication = Publication::factory()->scheduled()->create(['creator_id' => $this->owner->id]);

        $this->publisher->publish($publication);

        $fresh = $publication->fresh();

        // An unrecognised throwable is DOUBT, not failure.
        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $fresh->status);
        $this->assertSame('publish_outcome_unknown', $fresh->failure_code);

        // AND THE HANDLE IS STILL THERE.
        $this->assertSame(
            'container_from_phase_one',
            $fresh->remote_draft_id,
            'the container this attempt created must survive, or the next attempt makes a second one',
        );
    }

    /**
     * A RESUME PUBLISHES THE STORED CONTAINER AND DOES NOT MAKE A NEW ONE.
     *
     * The publication arrives already carrying a handle — the state the previous test leaves behind —
     * and the adapter records which id phase 2 was actually given.
     */
    public function test_a_resume_reuses_the_stored_handle_instead_of_creating_a_second_container(): void
    {
        $publication = Publication::factory()->failed()->create([
            'creator_id' => $this->owner->id,
            'remote_draft_id' => 'container_from_the_first_attempt',
        ]);

        $this->publisher->publish($publication);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);

        // NO SECOND PHASE-1 CALL. This is the assertion; everything else here is setup for it.
        $this->assertSame(
            0,
            PublicationAttempt::query()
                ->where('publication_id', $publication->id)
                ->where('phase', PublicationAttemptPhase::DRAFT)
                ->count(),
            'a resume must not create a second container',
        );

        // And phase 2 was handed the id that was on disk, not one it re-derived.
        $this->assertSame(
            'container_from_the_first_attempt',
            PublicationAttempt::query()
                ->where('publication_id', $publication->id)
                ->where('phase', PublicationAttemptPhase::PUBLISH)
                ->value('remote_draft_id'),
        );
    }

    /**
     * A DEFINITE refusal is `failed`, and a retry from there is allowed.
     *
     * `PlatformRefused` carries "and nothing was created" in its contract, which is the whole licence
     * for the ordinary retry path.
     */
    public function test_a_definite_platform_refusal_becomes_failed_and_stays_retryable(): void
    {
        // The dry-run adapter's one real refusal: nothing to send.
        $publication = Publication::factory()->scheduled()->create([
            'creator_id' => $this->owner->id,
            'title' => '',
        ]);

        $this->publisher->publish($publication);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::FAILED, $fresh->status);
        $this->assertSame('title_missing', $fresh->failure_code);

        // It was refused BEFORE anything was minted, so the contract's "nothing was created" clause is
        // literally true of the row.
        $this->assertNull($fresh->remote_draft_id);
        $this->assertNull($fresh->remote_id);

        // The refused attempt is nonetheless on record. Evidence is not rolled back with the outcome.
        $this->assertSame(
            1,
            PublicationAttempt::query()
                ->where('publication_id', $publication->id)
                ->where('succeeded', false)
                ->count(),
        );

        // And a retry is a legal move from here — which is the entire difference from needs_reconcile.
        $this->manager->claim($fresh);
        $this->assertSame(PublicationStatus::PUBLISHING, $fresh->fresh()->status);
    }

    /**
     * AMBIGUITY IS `needs_reconcile`, NOT `failed` — and the module leans that way on purpose.
     *
     * A `PlatformRefused` and a timeout look identical from the caller's side; only one of them is safe
     * to retry. Anything that is not the former is treated as the latter.
     */
    public function test_an_ambiguous_failure_parks_the_publication_for_a_person(): void
    {
        $this->useAdapter(new class implements PlatformAdapter
        {
            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::DRY_RUN;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                throw new RuntimeException('connection reset by peer');
            }

            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                throw new RuntimeException('unreachable');
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                return null;
            }
        });

        $publication = Publication::factory()->scheduled()->create(['creator_id' => $this->owner->id]);

        $this->publisher->publish($publication);

        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $publication->fresh()->status);
    }

    /**
     * RECONCILIATION FINDS A POST THAT WAS THERE ALL ALONG.
     *
     * The scenario: phase 2 succeeded on the platform and the process died before the status write. The
     * attempt row exists, `publications.remote_id` does not — and the dry-run adapter answers from the
     * ATTEMPT LOG rather than from the row, which is the only reading that can contradict what we
     * already believed.
     */
    public function test_reconciliation_finds_an_artifact_the_row_never_recorded(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        // The evidence a crashed worker would have left: the platform call landed, the status write did
        // not.
        PublicationAttempt::create([
            'publication_id' => $publication->id,
            'platform' => PublishingPlatform::DRY_RUN,
            'phase' => PublicationAttemptPhase::PUBLISH,
            'succeeded' => true,
            'attempt' => 1,
            'remote_draft_id' => $publication->remote_draft_id,
            'remote_id' => 'dryrun_it_was_out_all_along',
        ]);

        $this->assertNull($publication->remote_id, 'the row must NOT know about it yet — that is the scenario');

        $this->publisher->reconcile($publication);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertSame('dryrun_it_was_out_all_along', $fresh->remote_id);
    }

    /** Reconciliation that PROVES absence sends the row to `failed`, which re-opens the retry. */
    public function test_reconciliation_that_proves_absence_re_opens_the_retry(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        $this->publisher->reconcile($publication);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::FAILED, $fresh->status);
        $this->assertSame('reconciled_absent', $fresh->failure_code);

        // The enquiry itself is on record — evidence about an irreversible act belongs in the trail.
        $this->assertSame(
            1,
            PublicationAttempt::query()
                ->where('publication_id', $publication->id)
                ->where('phase', PublicationAttemptPhase::RECONCILE)
                ->count(),
        );
    }

    /**
     * A RECONCILIATION THAT LEARNS NOTHING MOVES NOTHING.
     *
     * An adapter whose platform cannot answer must throw rather than return null (null means PROVEN
     * absent). The row then stays exactly where a person can see it — which is the correct outcome, not
     * a gap.
     */
    public function test_a_reconciliation_that_cannot_answer_leaves_the_publication_alone(): void
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

        $this->publisher->reconcile($publication);

        $this->assertSame(
            PublicationStatus::NEEDS_RECONCILE,
            $publication->fresh()->status,
            'nothing was learned, so nothing may move',
        );
    }

    /** The attempt log records what would have gone out — and nothing that could authenticate it. */
    public function test_the_attempt_log_records_the_payload_as_ids_and_carries_no_credentials(): void
    {
        $mediaId = (string) \Illuminate\Support\Str::uuid();

        $publication = Publication::factory()
            ->scheduled()
            ->withMedia([$mediaId])
            ->create(['creator_id' => $this->owner->id, 'title' => 'Premiera odcinka']);

        $this->publisher->publish($publication);

        $request = PublicationAttempt::query()
            ->where('publication_id', $publication->id)
            ->where('phase', PublicationAttemptPhase::PUBLISH)
            ->value('request');

        $this->assertSame('Premiera odcinka', $request['title']);
        // IDS ONLY. A resolved path or a signed URL would be both a Disk dependency and, later, a
        // credential sitting in a database.
        $this->assertSame([$mediaId], $request['media']);

        foreach (['token', 'access_token', 'refresh_token', 'authorization', 'secret'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $request);
        }
    }

    /**
     * A DESTINATION WITH NO ADAPTER IS LOUD — deliberately unlike the Calendar's fail-soft registry.
     *
     * There is nothing to degrade to: a publication whose platform this installation does not implement
     * cannot be half-published, and skipping it silently would leave a row armed for a moment that
     * passes, forever, with somebody waiting for the post.
     */
    public function test_an_unregistered_platform_is_a_loud_configuration_error(): void
    {
        $registry = app(PlatformAdapterRegistry::class);

        $this->assertFalse($registry->has(PublishingPlatform::YOUTUBE));

        $this->expectException(UnknownPlatformAdapter::class);

        $registry->resolve(PublishingPlatform::YOUTUBE);
    }

    /** An adapter registered under a destination it does not claim would post to the wrong account. */
    public function test_an_adapter_registered_under_a_platform_it_does_not_claim_is_refused(): void
    {
        $registry = new PlatformAdapterRegistry;

        $registry->registerLazy(PublishingPlatform::YOUTUBE, fn (): PlatformAdapter => new class implements PlatformAdapter
        {
            public function platform(): PublishingPlatform
            {
                return PublishingPlatform::INSTAGRAM;
            }

            public function createDraft(Publication $publication): RemoteDraft
            {
                throw new PlatformRefused('unreachable');
            }

            public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
            {
                throw new PlatformRefused('unreachable');
            }

            public function findExisting(Publication $publication): ?RemoteRef
            {
                return null;
            }
        });

        $this->expectException(RuntimeException::class);

        $registry->resolve(PublishingPlatform::YOUTUBE);
    }

    /** Two adapters for one destination is a configuration error, not a last-one-wins fallback. */
    public function test_registering_two_adapters_for_one_platform_is_refused(): void
    {
        $registry = new PlatformAdapterRegistry;

        $registry->registerLazy(PublishingPlatform::DRY_RUN, fn () => app(\App\Modules\Publishing\Adapters\DryRunPlatformAdapter::class));

        $this->expectException(RuntimeException::class);

        $registry->registerLazy(PublishingPlatform::DRY_RUN, fn () => app(\App\Modules\Publishing\Adapters\DryRunPlatformAdapter::class));
    }

    /**
     * Swap the dry-run slot for a probe adapter.
     *
     * A fresh registry is bound rather than the shipped one mutated, because the registry refuses a
     * second registration for one destination — which is itself a property under test above.
     */
    private function useAdapter(PlatformAdapter $adapter): void
    {
        $registry = new PlatformAdapterRegistry;
        $registry->register($adapter);

        $this->app->instance(PlatformAdapterRegistry::class, $registry);

        $this->publisher = $this->app->make(PublicationPublisher::class);
    }
}
