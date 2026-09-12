<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\PublicationUnderReview;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * R4 B6 — A PUBLICATION IS SOMETHING YOU CAN BE ASKED TO APPROVE, and the approval means what it says.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE SENTENCE EVERY TEST IN THIS FILE IS A CONSEQUENCE OF
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * AN APPROVER SAYS YES TO EXACTLY WHAT GOES OUT — and what goes out cannot be withdrawn afterwards.
 *
 * Every rule below follows from that and from nothing else:
 *   - editing is refused while a review is live, because an edit would make the approval a statement
 *     about content that no longer exists;
 *   - arming is refused while a review is live, because arming is the act the review exists to gate;
 *   - the persisted snapshot carries the caption, the ORDERED media ids, the destination, the account
 *     and the intended moment — the whole of what would change the artifact;
 *   - a rejection leaves a DRAFT (there is no `rejected` status, and inventing one would have meant
 *     inventing an edge out of it into `scheduled`);
 *   - and approval arms an AUTOMATED publication while leaving a hand-drafted one alone, because a
 *     person pressing Approve has not thereby pressed Schedule.
 */
class PublishingApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $approver;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function asOwner(): self
    {
        parent::actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /** A one-stage pipeline whose approver is a real member of this workspace. */
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

    /**
     * A draft awaiting review. `$armAt` is the parked arming intent — null models the MANUAL path (a
     * person attached a pipeline to their own draft), a value models the automated one.
     */
    private function underReview(?CarbonImmutable $armAt, array $attributes = []): Publication
    {
        $publication = Publication::factory()->create($attributes + [
            'creator_id' => $this->owner->id,
            'approval_pipeline_id' => $this->pipeline()->id,
            'arm_on_approval_at' => $armAt,
        ]);

        app(ApprovalService::class)->startProcess($publication, $this->owner);

        return $publication->fresh();
    }

    // ---------------------------------------------------------------- the hold

    /**
     * EDITING IS REFUSED WHILE A REVIEW IS LIVE — the mechanism copied from `TaskPolicy::update()`.
     *
     * MUTATION PROOF: removing `!$publication->isInApproval()` from `PublicationPolicy::update()` turns
     * this 422 into a 200 and the publication is rewritten under the approver's nose. Nothing else in
     * the suite notices.
     */
    public function test_a_publication_under_review_cannot_be_edited(): void
    {
        $publication = $this->underReview(null);

        $response = $this->asOwner()->putJson('/api/publishing/publications/' . $publication->id, [
            'title' => 'Something else entirely',
            'platform' => PublishingPlatform::DRY_RUN->value,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', PublicationUnderReview::CODE);

        $this->assertSame(
            $publication->title,
            $publication->fresh()->title,
            'the row must be byte-identical: an approver is deciding about it',
        );
    }

    /**
     * ARMING IS REFUSED TOO, and this is the sharper half: arming is precisely what the review gates.
     *
     * MUTATION PROOF: removing `!$publication->isInApproval()` from `PublicationPolicy::schedule()`
     * arms the publication for a real minute while it is still with an approver — i.e. the review
     * becomes advisory and the due-sweep publishes anyway.
     */
    public function test_a_publication_under_review_cannot_be_scheduled(): void
    {
        $publication = $this->underReview(null);

        $this->asOwner()
            ->postJson('/api/publishing/publications/' . $publication->id . '/schedule', [
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', PublicationUnderReview::CODE);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::DRAFT, $fresh->status);
        $this->assertNull($fresh->scheduled_at, 'nothing may be armed while a review is live');
    }

    /**
     * The refusal is a 422 in the module's own shape, NOT a bare 403 — and it says why.
     *
     * A 403 answers "not you", which is false here: the creator, the workspace owner and the approver
     * all get the identical refusal and none of them has a permission problem. The row is busy.
     */
    public function test_the_review_refusal_names_itself_rather_than_reading_as_forbidden(): void
    {
        $publication = $this->underReview(null);

        $response = $this->asOwner()
            ->postJson('/api/publishing/publications/' . $publication->id . '/schedule', [
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', PublicationUnderReview::CODE)
            ->assertJsonPath('context.status', PublicationStatus::DRAFT->value);

        $this->assertSame(__('publishing.approval.under_review'), $response->json('message'));
    }

    /**
     * An ORDINARY refusal keeps the ordinary 403 — the trait must not swallow every authorization
     * failure into a sentence about reviews.
     */
    public function test_an_unrelated_refusal_is_still_a_plain_403(): void
    {
        $published = Publication::factory()->published()->create(['creator_id' => $this->owner->id]);

        $this->asOwner()
            ->putJson('/api/publishing/publications/' . $published->id, [
                'title' => 'Rewriting history',
                'platform' => PublishingPlatform::DRY_RUN->value,
            ])
            ->assertStatus(403);
    }

    /** The capability flags and the write path are the same computation — see the resource's docblock. */
    public function test_the_resource_withholds_both_affordances_while_a_review_is_live(): void
    {
        $publication = $this->underReview(null);

        $this->asOwner()
            ->getJson('/api/publishing/publications/' . $publication->id)
            ->assertOk()
            ->assertJsonPath('data.is_in_approval', true)
            ->assertJsonPath('data.approval_state', ApprovalProcessStatus::Pending->value)
            ->assertJsonPath('data.can_be_edited', false)
            ->assertJsonPath('data.can_be_scheduled', false);
    }

    // ---------------------------------------------------------------- the decisions

    /**
     * APPROVED + A PARKED INTENT = ARMED, for the moment that was parked.
     *
     * The whole automated path in one assertion. MUTATION PROOF: making
     * `Publication::onApprovalCompleted()` a no-op leaves the publication a draft forever — an approver
     * presses Approve, the run stays parked, and nothing anywhere says why.
     */
    public function test_approving_arms_an_automated_publication_for_its_parked_moment(): void
    {
        $armAt = CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC');
        $publication = $this->underReview($armAt);

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $fresh->status);
        $this->assertSame(
            $armAt->toIso8601String(),
            $fresh->scheduled_at->utc()->toIso8601String(),
            'the approval must arm for the moment that was parked, not for now',
        );
    }

    /**
     * APPROVED WITH NO PARKED INTENT = STILL A DRAFT — the manual path's guarantee.
     *
     * A person who attached a pipeline to their own draft gets an approved draft and a Schedule button.
     * Approval lifts the hold; it does not decide on their behalf that it is time to publish.
     *
     * MUTATION PROOF: defaulting the missing intent to `now()` in `onApprovalCompleted()` publishes
     * every hand-drafted publication the moment its review passes.
     */
    public function test_approving_a_hand_drafted_publication_arms_nothing(): void
    {
        $publication = $this->underReview(null);

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::DRAFT, $fresh->status);
        $this->assertNull($fresh->scheduled_at);

        // But the hold is gone: both affordances come back.
        $this->asOwner()
            ->getJson('/api/publishing/publications/' . $publication->id)
            ->assertJsonPath('data.is_in_approval', false)
            ->assertJsonPath('data.approval_state', ApprovalProcessStatus::Approved->value)
            ->assertJsonPath('data.can_be_scheduled', true);
    }

    /**
     * REJECTED = a draft again, and the refusal is VISIBLE.
     *
     * There is no `rejected` publication status and there must not be one, so `status` alone cannot tell
     * a refused publication from one nobody has looked at. `approval_state` is what makes the difference
     * readable — without it the rejection would be invisible on every screen.
     */
    public function test_a_rejected_publication_stays_a_draft_and_says_so(): void
    {
        $publication = $this->underReview(CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC'));

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Rejected,
            'The caption names the wrong product.',
        );

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::DRAFT, $fresh->status);
        $this->assertNull($fresh->scheduled_at, 'a refusal must never arm anything');
        $this->assertTrue($fresh->approvalWasRejected());

        $this->asOwner()
            ->getJson('/api/publishing/publications/' . $publication->id)
            ->assertJsonPath('data.is_in_approval', false)
            ->assertJsonPath('data.approval_state', ApprovalProcessStatus::Rejected->value)
            // Editable again: fixing it and sending it back is the whole point of leaving it a draft.
            ->assertJsonPath('data.can_be_edited', true);
    }

    /**
     * A SECOND review supersedes a first rejection.
     *
     * `approvalWasRejected()` is two halves on purpose — "the latest process is rejected" AND "nothing is
     * pending". Without the second, a publication somebody fixed and sent back would still report itself
     * refused, and anything waiting on it would conclude from a decision that has been superseded.
     */
    public function test_a_re_submitted_publication_no_longer_reports_itself_rejected(): void
    {
        $publication = $this->underReview(null);

        app(ApprovalService::class)->decide($publication->pendingApprovalProcess, ApprovalProcessStatus::Rejected, 'No.');
        $this->assertTrue($publication->fresh()->approvalWasRejected());

        app(ApprovalService::class)->startProcess($publication->fresh(), $this->owner);

        $this->assertFalse(
            $publication->fresh()->approvalWasRejected(),
            'a live second review supersedes the first refusal',
        );
    }

    // ---------------------------------------------------------------- the snapshot

    /**
     * THE SNAPSHOT IS WHAT GOES OUT — per ADR-0009 §1 a snapshot of the ENTITY, never a map of answers.
     *
     * Nothing is restored from it (unlike a task's original assignee): it is written because this is the
     * one approvable whose approval authorizes something irreversible, so "what exactly did they say yes
     * to" has to be answerable from a row afterwards.
     */
    public function test_the_process_carries_the_publication_as_it_would_be_sent(): void
    {
        $connection = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);
        $armAt = CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC');

        $publication = $this->underReview($armAt, [
            'title' => 'Autumn launch teaser',
            'body' => 'Something new is coming.',
            'platform' => PublishingPlatform::YOUTUBE,
            'platform_connection_id' => $connection->id,
            'media' => ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'],
        ]);

        $context = $publication->pendingApprovalProcess->context;

        $this->assertSame('Autumn launch teaser', $context['title']);
        $this->assertSame('Something new is coming.', $context['body']);
        $this->assertSame(PublishingPlatform::YOUTUBE->value, $context['platform']);
        $this->assertSame($connection->id, $context['platform_connection_id']);
        // ORDERED. A carousel is its sequence, and swapping one video for another changes everything
        // about the post and nothing about its caption.
        $this->assertSame([
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
        ], $context['media']);
        $this->assertSame($armAt->toISOString(), $context['intended_publish_at']);
    }

    /** The card an approver reads names the destination, the ACCOUNT and the moment — never a uuid. */
    public function test_the_queue_card_describes_the_destination_and_the_moment(): void
    {
        $connection = PlatformConnection::factory()->create([
            'creator_id' => $this->owner->id,
            'account_name' => 'Taskio Demo Channel',
        ]);

        $publication = Publication::factory()->create([
            'creator_id' => $this->owner->id,
            'title' => 'Autumn launch teaser',
            'body' => 'Something new is coming.',
            'platform' => PublishingPlatform::YOUTUBE,
            'platform_connection_id' => $connection->id,
            'media' => ['11111111-1111-4111-8111-111111111111'],
            'arm_on_approval_at' => CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC'),
        ]);

        $item = $publication->toApprovalQueueItem();

        $this->assertSame(__('approvals.entity_types.publication'), $item->type_label);
        $this->assertSame('Autumn launch teaser', $item->name);
        $this->assertSame('Something new is coming.', $item->description);

        $values = array_column($item->extra_fields, 'value', 'label');

        $this->assertSame(PublishingPlatform::YOUTUBE->label(), $values[__('publishing.approval.fields.platform')]);
        $this->assertSame('Taskio Demo Channel', $values[__('publishing.approval.fields.account')]);
        $this->assertSame('1', $values[__('publishing.approval.fields.media')]);

        // A publication has no form to fill in and no comment thread; both are stated absences rather
        // than a dead link on the one screen where somebody decides whether something goes public.
        $this->assertNull($item->form);
        $this->assertNull($item->comments_url);
    }

    /** A rehearsal destination has no account, and the card says so rather than leaving a blank. */
    public function test_a_publication_with_no_account_says_so_on_the_card(): void
    {
        $publication = Publication::factory()->create(['creator_id' => $this->owner->id]);

        $values = array_column($publication->toApprovalQueueItem()->extra_fields, 'value', 'label');

        $this->assertSame(__('publishing.approval.no_account'), $values[__('publishing.approval.fields.account')]);
        $this->assertSame(__('publishing.approval.no_moment'), $values[__('publishing.approval.fields.planned_for')]);
    }

    // ---------------------------------------------------------------- vocabulary

    /**
     * BOTH CATALOGS, COMPARED AS KEY SETS AND NEVER RENDERED.
     *
     * The lesson ADR-0055 Decision 8 paid for: this installation sets `APP_FALLBACK_LOCALE=pl`, so a key
     * missing from the ENGLISH catalog silently renders a grammatical Polish sentence and every
     * assertion built on `__()` stays green. An English reader would see Polish and no test would say
     * so. Comparing the two files' key sets cannot be fooled, because it never renders a string at all.
     */
    public function test_the_new_review_vocabulary_exists_in_both_languages(): void
    {
        foreach ([
            'publishing.approval' => ['fields', 'no_account', 'no_moment', 'under_review'],
            'publishing.approval.fields' => ['account', 'media', 'planned_for', 'platform'],
            'workflows.steps.publish' => ['connection_invalid', 'connection_unavailable', 'failed', 'gone', 'rejected'],
        ] as $catalogKey => $expected) {
            foreach (['en', 'pl'] as $locale) {
                $keys = array_keys((array) __($catalogKey, [], $locale));
                sort($keys);

                $this->assertSame($expected, $keys, "[{$locale}] {$catalogKey} has drifted from its twin");
            }
        }

        // The two single keys that live inside other modules' catalogs, checked the same way — plus the
        // refusal the deleted-subject guard renders (see test below), which lives in Approvals' own
        // validation catalog.
        foreach (['en', 'pl'] as $locale) {
            $this->assertArrayHasKey('publication', (array) __('approvals.entity_types', [], $locale));
            $this->assertArrayHasKey('publish', (array) __('workflows.step_types', [], $locale));
            $this->assertArrayHasKey('approvable_missing', (array) __('approvals.validation', [], $locale));
        }
    }

    // ---------------------------------------------------------------- the manual path (B6 fix round)

    /**
     * THE PIPELINE IS PART OF THE CONTENT: it attaches on create, travels through update — and the only
     * time it cannot be changed is while the review it names is actually running, because that is just
     * the edit-hold again wearing a different field.
     */
    public function test_a_pipeline_attaches_on_create_and_detaches_only_outside_review(): void
    {
        $pipeline = $this->pipeline();

        $created = $this->asOwner()->postJson('/api/publishing/publications', [
            'title' => 'Reviewed before it ships',
            'platform' => PublishingPlatform::DRY_RUN->value,
            'approval_pipeline_id' => $pipeline->id,
        ]);

        $created->assertStatus(201)->assertJsonPath('data.approval_pipeline_id', $pipeline->id);

        $publication = Publication::findOrFail($created->json('data.id'));

        // Attachment alone starts nothing — the review begins at a lifecycle moment, not at a keystroke.
        $this->assertFalse($publication->isInApproval());

        // Detaching an idle draft is an ordinary edit.
        $this->asOwner()->putJson('/api/publishing/publications/' . $publication->id, [
            'title' => 'Reviewed before it ships',
            'platform' => PublishingPlatform::DRY_RUN->value,
            'approval_pipeline_id' => null,
        ])->assertStatus(200)->assertJsonPath('data.approval_pipeline_id', null);

        // Under a LIVE review the same request is the edit-hold's 422 — otherwise omitting the field
        // would be the quietest way to take the review off the thing being reviewed.
        $underReview = $this->underReview(null);

        $this->asOwner()->putJson('/api/publishing/publications/' . $underReview->id, [
            'title' => $underReview->title,
            'platform' => PublishingPlatform::DRY_RUN->value,
            'approval_pipeline_id' => null,
        ])->assertStatus(422)->assertJsonPath('code', PublicationUnderReview::CODE);

        $this->assertNotNull($underReview->fresh()->approval_pipeline_id);
    }

    /**
     * SCHEDULING A REVIEW-GATED DRAFT IS THE SUBMISSION (deputy decision, B6 fix round).
     *
     * The Task precedent: a review starts at a LIFECYCLE MOMENT (a task entering IN_TEST), not when a
     * pipeline is attached. For a publication that moment is the attempt to arm — so Schedule on a
     * pipeline-attached draft with no standing approval parks the CHOSEN moment and opens the review,
     * and the approval then arms for exactly that moment through the same mechanism the workflow path
     * uses. Before this branch existed, Schedule on such a draft armed it WITHOUT any review — an
     * attached pipeline was decorative on the manual path, which is the quietest defeat of "you approve
     * exactly what goes out" this module could have shipped.
     */
    public function test_scheduling_a_review_gated_draft_submits_it_with_the_chosen_moment(): void
    {
        $pipeline = $this->pipeline();

        $publication = Publication::factory()->create([
            'creator_id' => $this->owner->id,
            'approval_pipeline_id' => $pipeline->id,
        ]);

        $moment = CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC');

        $response = $this->asOwner()->postJson(
            '/api/publishing/publications/' . $publication->id . '/schedule',
            ['scheduled_at' => $moment->toIso8601String()],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PublicationStatus::DRAFT->value)
            ->assertJsonPath('data.is_in_approval', true);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::DRAFT, $fresh->status, 'submission must not arm anything');
        $this->assertSame(
            $moment->toIso8601String(),
            $fresh->arm_on_approval_at->utc()->toIso8601String(),
            'the chosen moment is parked, byte for byte, as the arming intent',
        );

        // The whole loop: the approver's yes arms it for the moment the AUTHOR chose.
        app(ApprovalService::class)->decide(
            $fresh->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $armed = $publication->fresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $armed->status);
        $this->assertSame($moment->toIso8601String(), $armed->scheduled_at->utc()->toIso8601String());
    }

    /**
     * A STANDING APPROVAL FALLS THROUGH TO AN ORDINARY ARMING — approval lifts the hold, and the button
     * then does what it says. And after a REJECTION, pressing Schedule again IS the resubmission: the
     * same branch, entered because the standing answer is not a yes.
     */
    public function test_scheduling_arms_an_approved_draft_and_resubmits_a_rejected_one(): void
    {
        $publication = $this->underReview(null);

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $moment = CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC');

        $this->asOwner()->postJson(
            '/api/publishing/publications/' . $publication->id . '/schedule',
            ['scheduled_at' => $moment->toIso8601String()],
        )->assertStatus(200)->assertJsonPath('data.status', PublicationStatus::SCHEDULED->value);

        // Rejection, then Schedule: a NEW review opens instead of an arming.
        $rejected = $this->underReview(null);

        app(ApprovalService::class)->decide(
            $rejected->pendingApprovalProcess,
            ApprovalProcessStatus::Rejected,
            'Not this wording.',
        );

        $this->asOwner()->postJson(
            '/api/publishing/publications/' . $rejected->id . '/schedule',
            ['scheduled_at' => $moment->toIso8601String()],
        )->assertStatus(200)->assertJsonPath('data.is_in_approval', true);

        $this->assertSame(PublicationStatus::DRAFT, $rejected->fresh()->status);
    }

    // ---------------------------------------------------------------- durability and the deleted subject

    /**
     * THE DECISION IS DURABLE BEFORE ITS EFFECTS — the A1-shaped repair of the B6 review.
     *
     * `ApprovalService::decide()` runs in a transaction on the DEFAULT connection; the arming it
     * triggers writes the publications table, which for an own-database workspace lives on ANOTHER
     * connection entirely. Arming inline would let a late rollback of the decision leave an armed
     * publication whose approval never durably happened — a public artifact behind a divergence. So the
     * effect is deferred with `DB::afterCommit()`, and this test watches the seam directly: inside a
     * wrapping transaction the decision is written but NOTHING is armed; the arming runs at the real
     * commit and not one statement earlier. (The inline version fails the first assertion — the
     * same-connection read would already see the uncommitted `scheduled`.)
     */
    public function test_the_approval_decision_is_durable_before_its_effects(): void
    {
        $armAt = CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC');
        $publication = $this->underReview($armAt);

        DB::beginTransaction();

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $this->assertSame(
            PublicationStatus::DRAFT,
            $publication->fresh()->status,
            'the arming must wait for the decision to be durable — nothing moves inside the transaction',
        );

        DB::commit();

        $this->assertSame(PublicationStatus::SCHEDULED, $publication->fresh()->status);

        // And when the decision is ROLLED BACK, the deferred effect is discarded with it.
        $second = $this->underReview($armAt);

        DB::beginTransaction();

        app(ApprovalService::class)->decide(
            $second->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        DB::rollBack();

        $fresh = $second->fresh();

        $this->assertSame(PublicationStatus::DRAFT, $fresh->status, 'a rolled-back decision arms nothing, ever');
        $this->assertTrue($fresh->isInApproval(), 'the process write rolled back with it — the review is still live');
    }

    /**
     * THE SUBJECT WAS DELETED WHILE IT WAS WITH AN APPROVER — a 422 that says so, never a 500.
     *
     * Deleting is how somebody withdraws a publication, and the policy deliberately allows it during a
     * review. What must not follow is the approver's button crashing on a TypeError several frames deep
     * ({@see \App\Modules\Approvals\Services\ApprovalService::decide()} used to pass the null straight
     * into a `Model&Approvable` parameter). The guard is GENERIC — it names no concrete approvable, and
     * `WorkflowsPublishingBoundaryTest` refuses the alternative module-wide.
     */
    public function test_deciding_about_a_deleted_publication_refuses_politely_instead_of_crashing(): void
    {
        $publication = $this->underReview(null);
        $process = $publication->pendingApprovalProcess;

        $this->asOwner()
            ->deleteJson('/api/publishing/publications/' . $publication->id)
            ->assertStatus(204);

        parent::actingAs($this->approver)->withHeader('X-Workspace-Id', $this->workspace->id);

        $this->postJson('/api/approvals/processes/' . $process->id . '/decide', [
            'decision' => ApprovalProcessStatus::Approved->value,
        ])->assertStatus(422)->assertJsonValidationErrors(['approval']);

        $this->assertSame(
            ApprovalProcessStatus::Pending,
            $process->fresh()->status,
            'the process stays pending — named debt: nothing cancels it yet, for tasks either',
        );
    }

    /**
     * THE QUEUE RENDERS A PUBLICATION — through the real endpoint, batch-load and morph map included —
     * and keeps rendering after the subject is deleted, with a null entity instead of a crash.
     */
    public function test_the_queue_lists_a_publication_and_survives_its_deletion(): void
    {
        $publication = $this->underReview(null);
        $process = $publication->pendingApprovalProcess;

        parent::actingAs($this->approver)->withHeader('X-Workspace-Id', $this->workspace->id);

        $queued = $this->getJson('/api/approvals/queue');

        $queued->assertStatus(200);

        $item = collect($queued->json('data'))->firstWhere('process.id', $process->id);

        $this->assertNotNull($item, 'the publication must reach its approver through the real queue endpoint');
        $this->assertSame('publication', $item['entity']['type']);
        $this->assertSame($publication->id, $item['entity']['id']);

        $this->asOwner()->deleteJson('/api/publishing/publications/' . $publication->id)->assertStatus(204);

        parent::actingAs($this->approver)->withHeader('X-Workspace-Id', $this->workspace->id);

        $survived = $this->getJson('/api/approvals/queue');

        $survived->assertStatus(200);

        $orphan = collect($survived->json('data'))->firstWhere('process.id', $process->id);

        $this->assertNotNull($orphan, 'the orphaned process still renders');
        $this->assertNull($orphan['entity'], 'with a null entity — defended, not exploded');
    }

    // ---------------------------------------------------------------- the intent is consumed on use

    /**
     * A CONSUMED ARMING INTENT NEVER FIRES TWICE. Disarm an approval-armed publication, send it through
     * a SECOND review with no new intent — and the second yes must arm nothing, rather than arming for
     * the FIRST cycle's long-past moment (which would publish immediately, chosen by nobody).
     */
    public function test_a_consumed_arming_intent_never_fires_twice(): void
    {
        $armAt = CarbonImmutable::parse('2099-04-01 07:00:00', 'UTC');
        $publication = $this->underReview($armAt);

        app(ApprovalService::class)->decide(
            $publication->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $fresh->status);
        $this->assertNull($fresh->arm_on_approval_at, 'the intent is consumed by the arming it caused');

        app(PublicationManager::class)->disarm($fresh);

        app(ApprovalService::class)->startProcess($fresh->refresh(), $this->owner);

        app(ApprovalService::class)->decide(
            $fresh->fresh()->pendingApprovalProcess,
            ApprovalProcessStatus::Approved,
        );

        $this->assertSame(
            PublicationStatus::DRAFT,
            $publication->fresh()->status,
            'a second approval with no new intent arms nothing — the first cycle\'s moment is gone',
        );
    }

    // ---------------------------------------------------------------- one live review, whole moments

    /**
     * ONE LIVE REVIEW PER SUBJECT — the second start is refused, not stacked (re-review K1).
     *
     * Measured before the guard existed: two frames submitting the same draft produced TWO pending
     * processes; approving one armed the row while `isInApproval()` still said a review was live, and
     * the second reviewer's rejection then landed on a publication already armed — refused and
     * outgoing at once. A razor-thin insert race remains (closing it is a schema decision on the
     * shared Approvals tables — the owner's), but the reachable path is this one, and it is shut.
     */
    public function test_a_second_review_cannot_open_while_the_first_is_live(): void
    {
        $publication = $this->underReview(null);

        try {
            app(ApprovalService::class)->startProcess($publication->fresh(), $this->owner);
            $this->fail('a second live review must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approval', $e->errors());
        }

        $this->assertSame(
            1,
            $publication->approvalProcesses()->count(),
            'the refusal must leave exactly the one process that was already open',
        );
    }

    /**
     * AN UPDATE WITHOUT A MOMENT NEVER UNSCHEDULES — `scheduled` with a NULL moment is a publication
     * that neither goes out nor fails, invisible to the sweep and to the calendar alike (re-review K2:
     * measured as a plain PUT before the guard). Clearing the moment is the Manager's disarm, not a
     * side effect of fixing a typo.
     */
    public function test_an_update_without_a_moment_never_unschedules_an_armed_publication(): void
    {
        $publication = Publication::factory()->scheduled('2099-04-01 07:00:00')->create([
            'creator_id' => $this->owner->id,
        ]);

        $this->asOwner()->putJson('/api/publishing/publications/' . $publication->id, [
            'title' => 'Same post, better title',
            'platform' => PublishingPlatform::DRY_RUN->value,
        ])->assertStatus(200);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $fresh->status);
        $this->assertNotNull($fresh->scheduled_at, 'there is no such thing as scheduled-for-never');
        $this->assertSame('Same post, better title', $fresh->title);
    }

    /**
     * A REFUSED SUBMISSION LEAVES NO PARKED MOMENT (re-review A1). The intent is written before the
     * process opens — the approver's snapshot must carry the moment — so the refusal path has to take
     * it back out: an intent that outlives a failed submission is armed later by whatever approval
     * eventually succeeds, for an instant nobody chose that day.
     */
    public function test_a_refused_submission_does_not_leave_a_parked_moment(): void
    {
        $stageless = ApprovalPipeline::factory()->create(['creator_id' => $this->owner->id]);

        $publication = Publication::factory()->create([
            'creator_id' => $this->owner->id,
            'approval_pipeline_id' => $stageless->id,
        ]);

        $this->asOwner()->postJson(
            '/api/publishing/publications/' . $publication->id . '/schedule',
            ['scheduled_at' => '2099-04-01T07:00:00Z'],
        )->assertStatus(422);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::DRAFT, $fresh->status);
        $this->assertNull($fresh->arm_on_approval_at, 'the refused submission must take its moment back out');
        $this->assertFalse($fresh->isInApproval());
    }

    // ---------------------------------------------------------------- the same-second tie

    /**
     * TWO PROCESSES IN THE SAME SECOND, AND THE LATEST MUST STILL WIN. `created_at` has second
     * resolution, and a multi-stage pipeline creates its next stage's process in the same instant the
     * previous one is decided — so ordering by timestamp alone leaves "which answer is current" to the
     * planner's whim. `latestApprovalProcess` breaks the tie on `id` (uuid7, time-ordered), and this
     * test freezes the clock so both rows genuinely collide.
     *
     * The stake is not cosmetic: `approvalWasRejected()` is what tells a waiting workflow run to fail
     * and a screen to say "rejected". Reading the stage-1 yes instead of the stage-2 no reports a
     * refused publication as approved.
     */
    public function test_the_latest_process_wins_a_same_second_tie(): void
    {
        CarbonImmutable::setTestNow($frozen = CarbonImmutable::parse('2026-09-12 12:00:00', 'UTC'));

        try {
            $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $this->owner->id]);

            foreach ([1, 2] as $order) {
                $pipeline->stages()->create([
                    'name' => 'Stage ' . $order,
                    'icon' => 'check-circle',
                    'description' => null,
                    'approver_type' => 'user',
                    'approver_id' => $this->approver->id,
                    'order' => $order,
                ]);
            }

            $publication = Publication::factory()->create([
                'creator_id' => $this->owner->id,
                'approval_pipeline_id' => $pipeline->id,
            ]);

            app(ApprovalService::class)->startProcess($publication, $this->owner);

            // Stage 1 says yes; the stage-2 process is created in the SAME frozen second.
            app(ApprovalService::class)->decide(
                $publication->fresh()->pendingApprovalProcess,
                ApprovalProcessStatus::Approved,
            );

            // Stage 2 says no.
            app(ApprovalService::class)->decide(
                $publication->fresh()->pendingApprovalProcess,
                ApprovalProcessStatus::Rejected,
                'Stage two disagrees.',
            );

            $fresh = $publication->fresh();

            $this->assertTrue(
                $fresh->approvalWasRejected(),
                'the stage-2 refusal is the current answer, however the planner orders equal timestamps',
            );

            $this->asOwner()
                ->getJson('/api/publishing/publications/' . $publication->id)
                ->assertJsonPath('data.approval_state', ApprovalProcessStatus::Rejected->value);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
