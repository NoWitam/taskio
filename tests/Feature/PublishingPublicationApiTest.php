<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R4 B1 — the HTTP surface: CRUD, arming, counts, and the things a payload may not say.
 *
 * Two themes worth naming before the tests, because most of them are instances of one or the other:
 *
 *   THE MACHINE'S COLUMNS ARE NOT THE CALLER'S. `status`, `remote_id`, `remote_draft_id`,
 *   `published_at` and `attempts` are all `prohibited`, and each is refused with a 422 rather than
 *   quietly dropped. A client that could set any of them would have a second entrance to the state
 *   machine, and the most dangerous of them (`remote_id`) would let a caller mark a row as already
 *   published and suppress the real publish entirely.
 *
 *   ARMING IS NOT EDITING. Creating a publication with a `scheduled_at` produces a DRAFT that carries a
 *   moment; it does not schedule anything. That separation is what lets somebody pick a time while
 *   still writing without the act of saving becoming irreversible.
 */
class PublishingPublicationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $outsider;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->outsider = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->owner->id,
            'timezone' => 'Europe/Warsaw',
        ]);
        $this->workspace->users()->attach([$this->owner->id, $this->member->id]);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function asUser(?User $user = null): self
    {
        parent::actingAs($user ?? $this->member)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Zapowiedź nowego odcinka',
            'body' => 'Wychodzi w czwartek.',
            'platform' => PublishingPlatform::DRY_RUN->value,
        ];
    }

    public function test_a_publication_is_created_as_a_draft(): void
    {
        $this->asUser()
            ->postJson('/api/publishing/publications', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scheduled_at', null)
            // The dry-run destination says so on the wire, so a UI can mark it as a rehearsal without
            // knowing which platform value means that.
            ->assertJsonPath('data.publishes_publicly', false)
            ->assertJsonPath('data.can_be_edited', true)
            ->assertJsonPath('data.can_be_scheduled', true);
    }

    /**
     * A ZONE-LESS TIME IS THE WORKSPACE'S TIME.
     *
     * The workspace is Europe/Warsaw, +02:00 in September, so 09:00 must be stored as 07:00Z. Parsing
     * it through `config('app.timezone')` (a hard 'UTC') instead would publish this two hours late,
     * every time, with nothing disagreeing out loud — the same defect the calendar's write path was
     * fixed for, arriving through a different door. This module reuses that module's resolver rather
     * than restating the rule.
     */
    public function test_a_zone_less_time_is_read_on_the_workspace_clock(): void
    {
        $id = $this->asUser()
            ->postJson('/api/publishing/publications', $this->payload(['scheduled_at' => '2026-09-10T09:00:00']))
            ->assertCreated()
            // STILL A DRAFT. Setting a time is not arming.
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->assertSame(
            '2026-09-10T07:00:00+00:00',
            Publication::findOrFail($id)->scheduled_at->utc()->toIso8601String(),
        );
    }

    /** A time that names its own zone is taken exactly as given — silence is interpreted, speech is not. */
    public function test_an_explicit_zone_is_honoured(): void
    {
        $id = $this->asUser()
            ->postJson('/api/publishing/publications', $this->payload(['scheduled_at' => '2026-09-10T09:00:00Z']))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            '2026-09-10T09:00:00+00:00',
            Publication::findOrFail($id)->scheduled_at->utc()->toIso8601String(),
        );
    }

    /** The machine's own columns are refused, never dropped. */
    public function test_the_machines_columns_cannot_be_set_by_a_caller(): void
    {
        $this->asUser()
            ->postJson('/api/publishing/publications', $this->payload([
                'status' => 'published',
                'remote_id' => 'someone_elses_video',
                'remote_draft_id' => 'someone_elses_container',
                'published_at' => '2026-09-10T09:00:00Z',
                'attempts' => 99,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'remote_id', 'remote_draft_id', 'published_at', 'attempts']);
    }

    public function test_an_unknown_destination_is_refused(): void
    {
        $this->asUser()
            ->postJson('/api/publishing/publications', $this->payload(['platform' => 'tiktok']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);
    }

    /**
     * MEDIA IS AN ORDERED LIST OF IDS, STORED VERBATIM.
     *
     * The order is data — it is the order the platform receives them in — so it survives the round trip
     * unchanged rather than being sorted or de-duplicated into something else.
     */
    public function test_media_ids_keep_their_order(): void
    {
        $ids = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $stored = $this->asUser()
            ->postJson('/api/publishing/publications', $this->payload(['media' => $ids]))
            ->assertCreated()
            ->json('data.media');

        $this->assertSame($ids, $stored);
    }

    public function test_the_same_media_file_twice_is_refused(): void
    {
        $id = (string) Str::uuid();

        $this->asUser()
            ->postJson('/api/publishing/publications', $this->payload(['media' => [$id, $id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media.0']);
    }

    /** ARMING: the one transition a person makes in B1. */
    public function test_arming_moves_a_draft_to_scheduled(): void
    {
        $publication = Publication::factory()->create(['creator_id' => $this->member->id]);

        $this->asUser()
            ->postJson('/api/publishing/publications/' . $publication->id . '/schedule', [
                'scheduled_at' => now()->addDay()->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
    }

    /**
     * Arming for a moment that has gone is refused, not silently corrected to now().
     *
     * The due-sweep would claim it on its very next pass, so "schedule for last Tuesday" would mean
     * "publish immediately" — which is not what anybody typing a past date is asking for, and publishing
     * at a moment the caller did not name is exactly the surprise this module must not produce.
     */
    public function test_arming_for_a_moment_that_has_passed_is_refused(): void
    {
        $publication = Publication::factory()->create(['creator_id' => $this->member->id]);

        $this->asUser()
            ->postJson('/api/publishing/publications/' . $publication->id . '/schedule', [
                'scheduled_at' => now()->subDays(3)->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    /**
     * A REFUSED TRANSITION IS A 422 THAT NAMES ITSELF — the reconciliation doctrine, over HTTP.
     *
     * The code, not the sentence, is what a client branches on; the sentence is what tells a person to
     * go and look at the platform before doing anything else.
     */
    public function test_arming_a_publication_that_needs_reconciliation_answers_with_the_reconcile_code(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->member->id]);

        // The POLICY stops it first — `needs_reconcile` is not editable, because the row may correspond
        // to a live post and rewriting it would make our record disagree with the world.
        $this->asUser()
            ->postJson('/api/publishing/publications/' . $publication->id . '/schedule', [
                'scheduled_at' => now()->addDay()->toISOString(),
            ])
            ->assertForbidden();

        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $publication->fresh()->status);
    }

    /** A publication in flight, out in the world, or of unknown outcome cannot be rewritten. */
    public function test_the_uneditable_states_refuse_an_update(): void
    {
        foreach (['publishing', 'published', 'needsReconcile'] as $state) {
            $publication = Publication::factory()->{$state}()->create([
                'creator_id' => $this->member->id,
                'title' => 'Oryginał',
            ]);

            $this->asUser()
                ->putJson('/api/publishing/publications/' . $publication->id, $this->payload(['title' => 'rewritten']))
                ->assertForbidden();

            // The 403 alone would pass even if the write had happened first, so the row is checked.
            $this->assertSame('Oryginał', $publication->fresh()->title, "a {$state} publication must not have been rewritten");

            // And the capability flag agrees with the enforcement — the whole reason the state test
            // lives in the policy rather than in the service.
            $this->assertFalse(
                $this->member->can('update', $publication->fresh()),
                "can_be_edited must be false for a {$state} publication",
            );
        }
    }

    /**
     * Delete refuses the two states where the row is the only handle onto something in flight or
     * unknown — and ALLOWS `published`, which looks inconsistent and is not.
     *
     * Deleting a published row hides our record and changes nothing in the world; the post stays up,
     * because nothing written here can recall it. Editing one is refused for the opposite reason: an
     * edited record would actively lie about a post that still exists.
     */
    public function test_delete_refuses_in_flight_and_unknown_but_allows_published(): void
    {
        foreach (['publishing', 'needsReconcile'] as $state) {
            $publication = Publication::factory()->{$state}()->create(['creator_id' => $this->member->id]);

            $this->asUser()
                ->deleteJson('/api/publishing/publications/' . $publication->id)
                ->assertForbidden();
        }

        $published = Publication::factory()->published()->create(['creator_id' => $this->member->id]);

        $this->asUser()
            ->deleteJson('/api/publishing/publications/' . $published->id)
            ->assertNoContent();

        $this->assertSoftDeleted('publications', ['id' => $published->id]);
    }

    /**
     * The WORKSPACE OWNER may correct anybody's publication.
     *
     * One step wider than the app-wide ownership rule, and the argument is sharper here than it was for
     * a calendar annotation: a publication left armed by somebody who has gone on holiday must not be
     * un-cancellable, because what happens if nobody can intervene is a post appearing publicly that
     * nobody present wanted.
     */
    public function test_the_workspace_owner_can_edit_a_publication_they_did_not_create(): void
    {
        $publication = Publication::factory()->create(['creator_id' => $this->member->id]);

        $this->asUser($this->owner)
            ->putJson('/api/publishing/publications/' . $publication->id, $this->payload(['title' => 'Poprawione']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Poprawione');
    }

    /** An ordinary member may not rewrite somebody else's publication. */
    public function test_a_member_cannot_edit_another_members_publication(): void
    {
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);

        $publication = Publication::factory()->create(['creator_id' => $other->id]);

        $this->asUser($this->member)
            ->putJson('/api/publishing/publications/' . $publication->id, $this->payload(['title' => 'nope']))
            ->assertForbidden();
    }

    /** A request with no active workspace is refused before anything is looked up. */
    public function test_a_request_without_a_workspace_is_refused(): void
    {
        app(TenantContext::class)->clear();

        $this->actingAs($this->member)
            ->getJson('/api/publishing/publications')
            ->assertStatus(400);
    }

    /** A publication belonging to another workspace does not resolve at all. */
    public function test_another_workspaces_publication_is_not_reachable(): void
    {
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $this->outsider->id]);
        $otherWorkspace->users()->attach($this->outsider->id);

        app(TenantContext::class)->set($otherWorkspace);
        $foreign = Publication::factory()->create(['creator_id' => $this->outsider->id]);
        app(TenantContext::class)->set($this->workspace);

        $this->asUser()
            ->getJson('/api/publishing/publications/' . $foreign->id)
            ->assertNotFound();
    }

    /**
     * COUNTS: every status present, plus the badge number the client does not have to compose.
     *
     * `needs_attention` is server-computed for the same reason a calendar badge is prose: a client
     * summing a list it maintains would be right on the day it was written and silently wrong the moment
     * an eighth status arrived.
     */
    public function test_counts_carries_every_status_and_the_attention_number(): void
    {
        Publication::factory()->count(2)->create(['creator_id' => $this->member->id]);
        Publication::factory()->scheduled()->create(['creator_id' => $this->member->id]);
        Publication::factory()->published()->create(['creator_id' => $this->member->id]);
        Publication::factory()->failed()->create(['creator_id' => $this->member->id]);
        Publication::factory()->needsReconcile()->create(['creator_id' => $this->member->id]);
        Publication::factory()->blocked()->create(['creator_id' => $this->member->id]);

        $this->asUser()
            ->getJson('/api/publishing/counts')
            ->assertOk()
            ->assertJsonPath('data.counts.draft', 2)
            ->assertJsonPath('data.counts.scheduled', 1)
            // Present and zero, never absent: "no rows" and "no such status" are different statements.
            ->assertJsonPath('data.counts.publishing', 0)
            ->assertJsonPath('data.counts.published', 1)
            ->assertJsonPath('data.counts.failed', 1)
            ->assertJsonPath('data.counts.needs_reconcile', 1)
            ->assertJsonPath('data.counts.blocked', 1)
            ->assertJsonPath('data.total', 7)
            // failed + needs_reconcile + blocked.
            ->assertJsonPath('data.needs_attention', 3);
    }

    /** The counts honour the list's own filters, minus status — otherwise each count counts itself. */
    public function test_counts_honour_the_active_search(): void
    {
        Publication::factory()->create(['creator_id' => $this->member->id, 'title' => 'Wywiad z gościem']);
        Publication::factory()->create(['creator_id' => $this->member->id, 'title' => 'Zapowiedź sezonu']);

        $this->asUser()
            ->getJson('/api/publishing/counts?search=Wywiad')
            ->assertOk()
            ->assertJsonPath('data.counts.draft', 1)
            ->assertJsonPath('data.total', 1);
    }

    /** `remote_draft_id` is internal recovery state and is never advertised. */
    public function test_the_phase_one_handle_is_not_published_on_the_wire(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->member->id]);

        $this->assertNotNull($publication->remote_draft_id, 'the fixture must actually have one, or this proves nothing');

        $data = $this->asUser()
            ->getJson('/api/publishing/publications/' . $publication->id)
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('remote_draft_id', $data);
        // The public identity IS published — it is the proof a post exists and the way to go and look.
        $this->assertArrayHasKey('remote_id', $data);
    }
}
