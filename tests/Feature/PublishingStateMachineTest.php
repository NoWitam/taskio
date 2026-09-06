<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\DTOs\RemoteDraft;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * R4 B1 — THE TRANSITION TABLE, EXHAUSTIVELY, plus the structural guarantee that it is the only one.
 *
 * The reason this file asserts the WHOLE matrix rather than a handful of happy paths: a state machine's
 * defects are almost always in the edges nobody wrote a test for, and in this module two of those edges
 * cost a public artifact that cannot be withdrawn. So every (from, to) pair in the product of the seven
 * statuses is checked against an expectation written out below, and a change to the table that nobody
 * meant fails here rather than in production.
 */
class PublishingStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private PublicationManager $manager;

    /**
     * THE TABLE, RESTATED INDEPENDENTLY OF THE IMPLEMENTATION.
     *
     * Deliberately a second copy rather than a read of `PublicationManager`'s own constant. A test that
     * asked the class what it allows and then asserted that it allows it is a tautology; this is a
     * SPECIFICATION, written here, that the implementation is measured against. When the two disagree,
     * somebody has to decide which is right — which is exactly the conversation a change to this graph
     * should force.
     *
     * @var array<string, array<int, string>>
     */
    private const EXPECTED = [
        'draft' => ['scheduled'],
        'scheduled' => ['publishing', 'draft', 'blocked'],
        'publishing' => ['published', 'failed', 'needs_reconcile'],
        'published' => [],
        'failed' => ['publishing', 'scheduled', 'draft', 'blocked'],
        'needs_reconcile' => ['published', 'failed'],
        'blocked' => ['scheduled', 'draft'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);

        $this->manager = app(PublicationManager::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * EVERY PAIR. Forty-nine of them, including the seven identity pairs.
     */
    public function test_the_whole_transition_matrix_matches_the_specification(): void
    {
        foreach (PublicationStatus::cases() as $from) {
            foreach (PublicationStatus::cases() as $to) {
                $expected = in_array($to->value, self::EXPECTED[$from->value], true);

                $this->assertSame(
                    $expected,
                    $this->manager->allows($from, $to),
                    ($expected ? 'expected' : 'did not expect')
                    . " the machine to allow {$from->value} -> {$to->value}",
                );
            }
        }
    }

    /**
     * A move to the SAME state is not a move, and is refused.
     *
     * Re-arming a scheduled publication for a different minute is an EDIT of `scheduled_at`, not a
     * transition. Allowing the identity edge would make "how many times has this been armed"
     * unanswerable from the transitions alone, and would let a claim be taken twice by two workers each
     * seeing a legal move.
     */
    public function test_a_status_cannot_transition_to_itself(): void
    {
        foreach (PublicationStatus::cases() as $status) {
            $this->assertFalse(
                $this->manager->allows($status, $status),
                "{$status->value} -> {$status->value} must not be a transition",
            );
        }
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * FENCE 1 — the single most important assertion in this module.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * A publication in `needs_reconcile` may already be a live post. Publishing it again is not a retry,
     * it is a coin flip whose losing side is a second public artifact that nothing in this application
     * can delete. There is no edge, and the refusal names itself so a client can say WHY rather than
     * merely that something failed.
     */
    public function test_a_publication_needing_reconciliation_can_never_go_straight_back_to_publishing(): void
    {
        $publication = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);

        $this->assertFalse($this->manager->allows(PublicationStatus::NEEDS_RECONCILE, PublicationStatus::PUBLISHING));

        try {
            $this->manager->claim($publication);
            $this->fail('claiming a publication that needs reconciliation must be refused');
        } catch (PublicationTransitionRefused $e) {
            $this->assertSame(PublicationTransitionRefused::RECONCILE_BEFORE_RETRY, $e->reason);
        }

        // And nothing was written on the way out.
        $this->assertSame(PublicationStatus::NEEDS_RECONCILE, $publication->fresh()->status);
        $this->assertSame(1, $publication->fresh()->attempts, 'a refused claim must not count as an attempt');
    }

    /**
     * The two ways OUT of `needs_reconcile`, and what each is for.
     *
     * `markPublished` requires a {@see RemoteRef}, which from this state only `findExisting()` can
     * produce — so "it was out all along" cannot be asserted on optimism. `markFailed` is the
     * conclusion that the platform was asked and proved nothing exists, and it is what re-opens the
     * ordinary retry path.
     */
    public function test_reconciliation_is_the_only_way_out_and_failing_re_opens_the_retry(): void
    {
        $found = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);
        $this->manager->markPublished($found, RemoteRef::make('dryrun_found_it'));
        $this->assertSame(PublicationStatus::PUBLISHED, $found->fresh()->status);
        $this->assertSame('dryrun_found_it', $found->fresh()->remote_id);

        $absent = Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id]);
        $this->manager->markFailed($absent, 'reconciled_absent');
        $this->assertSame(PublicationStatus::FAILED, $absent->fresh()->status);

        // THE POINT OF THE WHOLE ARRANGEMENT: only now is a retry a legal move.
        $this->manager->claim($absent);
        $this->assertSame(PublicationStatus::PUBLISHING, $absent->fresh()->status);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * FENCE 2 — a broken connection HOLDS its queue.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * Without this state, a token revoked at 08:00 turns twelve scheduled items into twelve failures at
     * 09:00: twelve alerts, twelve retry buttons, twelve rate-limited calls, one cause. `blocked` is
     * left by re-arming or by giving up — both things a person does after fixing the connection.
     */
    public function test_a_blocked_publication_cannot_be_published_and_keeps_its_schedule(): void
    {
        $publication = Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'creator_id' => $this->owner->id,
        ]);

        $this->manager->block($publication, 'connection_unusable');

        $this->assertSame(PublicationStatus::BLOCKED, $publication->fresh()->status);

        // THE SCHEDULE SURVIVES. Throwing it away would make fixing the connection insufficient to
        // recover — the publication still wants to go out at the moment somebody chose.
        $this->assertSame(
            '2026-09-10T09:00:00+00:00',
            $publication->fresh()->scheduled_at->utc()->toIso8601String(),
        );

        try {
            $this->manager->claim($publication);
            $this->fail('a blocked publication must not be claimable');
        } catch (PublicationTransitionRefused $e) {
            $this->assertSame(PublicationTransitionRefused::BLOCKED_HOLDS, $e->reason);
        }

        // Re-arming after the connection is repaired is the way out.
        $this->manager->arm($publication, now()->addDay());
        $this->assertSame(PublicationStatus::SCHEDULED, $publication->fresh()->status);
    }

    /** Published is terminal in the strong sense: nothing this application writes can recall a post. */
    public function test_a_published_publication_never_leaves_that_state(): void
    {
        $publication = Publication::factory()->published()->create(['creator_id' => $this->owner->id]);

        foreach (PublicationStatus::cases() as $to) {
            $this->assertFalse(
                $this->manager->allows(PublicationStatus::PUBLISHED, $to),
                "published -> {$to->value} must not exist",
            );
        }

        try {
            $this->manager->disarm($publication);
            $this->fail('a published publication must not be movable back to draft');
        } catch (PublicationTransitionRefused $e) {
            $this->assertSame(PublicationTransitionRefused::TERMINAL, $e->reason);
        }
    }

    /** Arming clears the previous failure but NEVER the phase-1 handle — that handle is a container. */
    public function test_re_arming_a_failed_publication_keeps_its_remote_draft(): void
    {
        $publication = Publication::factory()->failed()->create([
            'creator_id' => $this->owner->id,
            'remote_draft_id' => 'dryrun_draft_keepme',
        ]);

        $this->manager->arm($publication, now()->addDay());

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::SCHEDULED, $fresh->status);
        $this->assertNull($fresh->failure_code, 'arming clears the previous failure');
        $this->assertSame(
            'dryrun_draft_keepme',
            $fresh->remote_draft_id,
            'the phase-1 handle must survive: it names a container that already exists, and dropping it '
            . 'is how a second one gets made',
        );
    }

    /** A claim counts the attempt BEFORE the call, so a worker that dies mid-call still left a record. */
    public function test_claiming_counts_the_attempt_before_anything_is_attempted(): void
    {
        $publication = Publication::factory()->scheduled()->create(['creator_id' => $this->owner->id]);

        $this->manager->claim($publication);

        $fresh = $publication->fresh();

        $this->assertSame(1, $fresh->attempts);
        $this->assertNotNull($fresh->last_attempt_at);
    }

    /** The phase-1 handle is persisted by its own write, and does not move the status. */
    public function test_remembering_a_draft_handle_is_not_a_transition(): void
    {
        $publication = Publication::factory()->publishing()->create(['creator_id' => $this->owner->id]);

        $this->manager->rememberDraft($publication, RemoteDraft::make('dryrun_draft_abc'));

        $fresh = $publication->fresh();

        $this->assertSame('dryrun_draft_abc', $fresh->remote_draft_id);
        $this->assertSame(PublicationStatus::PUBLISHING, $fresh->status);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE STRUCTURAL GUARANTEE: nothing else in the module writes `status`.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * Every assertion above is only as strong as this one. A machine whose states can also be assigned
     * from a service, a job or a controller is not a machine — it is a suggestion — and the line that
     * breaks it ("just set it to failed here") reads as entirely reasonable in every code review.
     *
     * The scan is LITERAL over the file bytes, in the same spirit as `CalendarModuleBoundaryTest`, but
     * it hunts an ASSIGNMENT rather than the noun. That distinction is forced by the module itself:
     * `'status' =>` is a perfectly good array key in a validation rule set, in a resource payload, in a
     * cast map and in a column default, and forbidding it outright would make this test unpassable
     * rather than strict. What is forbidden is the column reaching a WRITE — a status-keyed array handed
     * to `create`/`update`/`fill`/`forceFill`, or a direct property assignment.
     *
     * THE LIMIT, NAMED: matching "a write verb, then a `'status'` key within the next few hundred
     * characters" can in principle fire on an unrelated pair that happens to sit close together. It errs
     * STRICT, which is the correct direction for a guard to be wrong in — a false positive costs a
     * conversation, and a false negative costs the property this whole file exists to protect.
     *
     * IF YOU ARE HERE BECAUSE THIS FAILED: do not add your file to the exemption list. Add a method to
     * `PublicationManager` that names the transition you want, and call that. If the move you need is
     * not in the table, the question to answer is whether it should be — which is the conversation this
     * test exists to force.
     *
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE SECOND EXEMPTION IS A LINE, NOT A FILE — AND THE DIFFERENCE IS THE WHOLE POINT
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * B2 added `platform_connections`, which has a `status` of its own with its own lifecycle and its
     * own Manager. The scan is LITERAL over file bytes and cannot tell one `status` column from
     * another, so it fired on `PlatformConnectionManager` writing ITS OWN model — the false positive
     * the paragraph above predicted, and the case it did not anticipate.
     *
     * B2 answered that by exempting the FILE, and B2's review showed what that bought: three ways to
     * move a publication that the guard would then have waved through from inside the one file with a
     * hole in it —
     *
     *     foreach ($publications as $row) { $row->status = …; }
     *     $connection->publications()->update(['status' => …]);
     *     DB::table('publications')->update(['status' => …]);
     *
     * — none of which the narrow counter-assertion below matches either, because it looks for a
     * variable literally named `$publication`.
     *
     * So the exemption is now the SINGLE LINE that trips the scan (`$connection->status = $to;`, the
     * Manager's one write onto its own model). Everything else in that file is scanned like everything
     * else in the module, and all three bypasses above fail. The exemption is also checked to still
     * APPLY: if that line is ever reworded, this test says so rather than silently scanning a file it
     * believes it has exempted — or silently exempting nothing.
     *
     * The rule for a THIRD entry: a new state-bearing MODEL in this module gets its own Manager, and its
     * Manager's own status write gets a LINE here. Never a file.
     */
    public function test_only_the_manager_writes_a_publication_status(): void
    {
        $root = app_path('modules/Publishing');
        $this->assertDirectoryExists($root);

        // The one file that owns `publications.status`. No test-only carve-outs: the factories live
        // under database/ and are outside this scan by construction (each with its own docblock arguing
        // why a fixture may start in a state rather than walk to it).
        $allowed = [
            'Managers/PublicationManager.php',
        ];

        // Lines cut from a file's source BEFORE it is scanned, because they write a DIFFERENT model's
        // status column and a literal scan cannot tell the two apart. Spelled exactly, one line each.
        $exemptLines = [
            'Managers/PlatformConnectionManager.php' => [
                '$connection->status = $to;',
            ],
        ];

        $patterns = [
            // A status-keyed array on its way into a write.
            '/(?:->update|->updateQuietly|->forceFill|->fill|::create|->create)\s*\(.{0,600}?[\'"]status[\'"]\s*=>/s',
            // A direct property write. `=[^=>]` so `==`, `===` and `=>` are reads, not writes.
            '/->status\s*=[^=>]/',
        ];

        $scanned = 0;
        $violations = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $scanned++;
            $source = (string) file_get_contents($file->getPathname());

            foreach ($exemptLines[$relative] ?? [] as $line) {
                $this->assertStringContainsString(
                    $line,
                    $source,
                    "The line exemption for [{$relative}] no longer matches anything: [{$line}]. Either "
                    . 'the write was reworded — in which case update the exemption — or it is gone, in '
                    . 'which case delete it. An exemption that matches nothing is a file nobody is '
                    . 'checking as carefully as they think.',
                );

                $source = str_replace($line, '', $source);
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = $relative;

                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Something outside PublicationManager assigns a publication's status. The Manager owns every "
            . "transition; give it a method that names the move instead. Offenders:\n  - "
            . implode("\n  - ", $violations),
        );

        $this->assertGreaterThan(0, $scanned, 'expected to scan the Publishing module source files');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE MECHANISM IS STILL THERE — the half no prohibition can express.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * Everything above says what `PlatformConnectionManager` may not do, and since B2's review the
     * module-wide scan says most of it: only the one line writing its own model's status is cut, so the
     * three mass-write shapes a file-level exemption used to hide are now caught there.
     *
     * What a prohibition cannot say is that the thing still HAPPENS. A refactor that quietly stopped
     * holding publications — deleted the loop, renamed the call, returned early — would leave every
     * negative assertion in this file passing and the fence gone. So the two named calls that ARE the
     * mechanism are asserted positively, and the narrow negatives are kept beside them as a second,
     * differently-shaped net around publications specifically.
     */
    public function test_the_connection_manager_moves_publications_only_through_the_publication_manager(): void
    {
        $path = app_path('modules/Publishing/Managers/PlatformConnectionManager.php');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertSame(
            0,
            preg_match('/\$publication[a-zA-Z_]*\s*->\s*status\s*=[^=>]/', $source),
            'PlatformConnectionManager assigned a status onto a publication. It may only ask '
            . 'PublicationManager to move one — that class owns the transition table.',
        );

        $this->assertSame(
            0,
            preg_match('/Publication::.{0,200}?(?:->update|->create|->forceFill|->fill)\s*\(.{0,300}?[\'"]status[\'"]\s*=>/s', $source),
            'PlatformConnectionManager mass-wrote a status onto publications.',
        );

        // THE POSITIVE HALF. The hold and the release exist, and they are the Manager's own edges.
        $this->assertStringContainsString(
            '$this->publications->block(',
            $source,
            'the hold must go through PublicationManager::block() — the scheduled -> blocked edge',
        );
        $this->assertStringContainsString(
            '$this->publications->arm(',
            $source,
            'the release must go through PublicationManager::arm() — the blocked -> scheduled edge',
        );
    }
}
