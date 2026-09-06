<?php

namespace App\Modules\Publishing\Managers;

use App\Modules\Publishing\DTOs\RemoteDraft;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Models\Publication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * THE ONLY THING IN THIS PRODUCT THAT MOVES A PUBLICATION FROM ONE STATE TO ANOTHER.
 *
 * A Manager rather than a Service, per the house layering: a Manager is for complex workflows with
 * internal states, lifecycle and multi-step transitions, and this is the codebase's second one after
 * Changelog. The distinction earns its keep here in one specific way — SINGLE OWNERSHIP. A `status`
 * assignment scattered across a service, a job and a controller is how a state machine stops being one,
 * and each of those lines reads as entirely reasonable on its own. There is one table below, it is
 * data, and nothing else in the module may write the column.
 *
 * That is asserted rather than hoped for: {@see \Tests\Feature\PublishingStateMachineTest} scans the
 * module's own file bytes for a `status` write outside this class.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE TABLE, WRITTEN OUT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   draft            → scheduled
 *   scheduled        → publishing | draft | blocked
 *   publishing       → published | failed | needs_reconcile
 *   published        → (nothing)
 *   failed           → publishing | scheduled | draft | blocked
 *   needs_reconcile  → published | failed
 *   blocked          → scheduled | draft
 *
 * A move to the SAME state is not in the table and is refused. Re-arming a `scheduled` publication for
 * a different minute is an edit of `scheduled_at`, not a transition, and letting it through here would
 * make "how many times has this been armed" unanswerable from the transitions alone.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE TWO FENCES ARE FACTS OF THAT TABLE, NOT CHECKS ON TOP OF IT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * FENCE 1 — `needs_reconcile` HAS NO EDGE TO `publishing`. EVER.
 *
 *   A publication in `needs_reconcile` may ALREADY BE A POST. The worker died between phase 1 and phase
 *   2, the platform timed out after accepting, the connection dropped mid-call — and there is no way to
 *   tell any of those from the outside. An automatic retry here is not a retry, it is a coin flip whose
 *   losing side is a second public artifact that nothing written in this application can delete.
 *
 *   The only two edges out are `published` and `failed`, and each is a CONCLUSION OF RECONCILIATION:
 *     - {@see markPublished()} takes a {@see RemoteRef}, and from this state the only thing that can
 *       produce one is `PlatformAdapter::findExisting()`. The type is the proof.
 *     - {@see markFailed()} means the platform was asked and proved nothing exists — which is what
 *       makes the ordinary `failed → publishing` retry safe again.
 *
 *   So "reconcile before retry" is not a rule somebody has to remember. It is the only path the graph
 *   contains, and B3's queue inherits it without having to agree to it.
 *
 * FENCE 2 — `blocked` HAS NO EDGE TO `publishing` EITHER, and that is what makes it worth having.
 *
 *   `blocked` exists so a broken connection HOLDS its queue. Without it, a revoked token at 08:00 turns
 *   twelve scheduled items into twelve failures at 09:00 — twelve alerts, twelve retry buttons, twelve
 *   rate-limited calls, one cause. The state is entered from `scheduled` or `failed` and left only by
 *   re-arming or by giving up, both of which are things a person does after fixing the connection.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT B1 DOES NOT DO YET, AND WHERE IT WILL GO
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * There is no queue, no due-sweep and no claim contention here — those are B3. What B1 fixes is the
 * VOCABULARY those things will be built on, because a state machine retrofitted under a queue that
 * already ships is a state machine that has to accommodate whatever the queue was already doing.
 *
 * One consequence to name rather than discover: {@see claim()} is not yet atomic against a second
 * worker. B3 makes it a conditional UPDATE (`where status = 'scheduled'`) and treats a zero row-count
 * as "somebody else has it" — the same shape `WorkflowRunManager` already uses. Until then the only
 * caller is a test and a synchronous path.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * NO TRANSACTIONS HERE, AND THAT IS NOT AN OMISSION
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * Every method below writes exactly one row, so there is nothing to make atomic. More importantly, the
 * caller must NOT wrap a publish in one: {@see rememberDraft()} has to survive a later failure of phase
 * 2, and a transaction spanning both phases would roll the handle back and hand the next attempt a
 * clean slate in front of a container that already exists. The publisher states the same rule at its
 * own level.
 */
class PublicationManager
{
    /**
     * The machine, as data. Keyed by the CURRENT status; the value is every status reachable from it.
     *
     * Written with enum values rather than cases because a `const` cannot hold enum instances, and the
     * lookup in {@see allows()} converts once. The absence of an entry is as meaningful as its
     * contents: `published` maps to an empty list, which is how "terminal" is expressed without a
     * second mechanism to keep in step.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'draft' => ['scheduled'],
        'scheduled' => ['publishing', 'draft', 'blocked'],
        'publishing' => ['published', 'failed', 'needs_reconcile'],
        // Terminal. See PublicationStatus::isTerminal().
        'published' => [],
        'failed' => ['publishing', 'scheduled', 'draft', 'blocked'],
        // FENCE 1: no 'publishing'. Both exits are conclusions of a reconciliation.
        'needs_reconcile' => ['published', 'failed'],
        // FENCE 2: no 'publishing'. A hold is left by re-arming or by giving up.
        'blocked' => ['scheduled', 'draft'],
    ];

    /**
     * ARM a publication for a moment. `draft | failed | blocked → scheduled`.
     *
     * The instant is normalized to UTC here because a Manager must not depend on its callers having
     * agreed about zones — the HTTP door resolves a zone-less string against the workspace clock
     * (`CalendarInstantResolver`), and a future workflow step will arrive with something already
     * absolute.
     *
     * Arming CLEARS the previous failure. It does NOT clear `remote_draft_id`: a publication that got a
     * container and then failed still has that container, and re-arming it must resume rather than make
     * a second one. See {@see forgetDraft()} for the one case where dropping it is correct.
     */
    public function arm(Publication $publication, CarbonInterface $scheduledAt): Publication
    {
        return $this->transition($publication, PublicationStatus::SCHEDULED, [
            'scheduled_at' => CarbonImmutable::instance($scheduledAt)->utc(),
            'failure_code' => null,
            'failure_context' => null,
        ]);
    }

    /**
     * DISARM back to editing. `scheduled | failed | blocked → draft`.
     *
     * `scheduled_at` is cleared, because a draft with a moment attached is a row that looks armed on
     * every screen that reads the instant rather than the status — including a calendar source that
     * filters on one and orders by the other.
     */
    public function disarm(Publication $publication): Publication
    {
        return $this->transition($publication, PublicationStatus::DRAFT, [
            'scheduled_at' => null,
        ]);
    }

    /**
     * CLAIM for publishing. `scheduled | failed → publishing`.
     *
     * The attempt counter is incremented HERE rather than by whoever calls the platform, so a worker
     * that dies mid-call has still left a record that an attempt was made. A counter bumped on success
     * would read as zero for exactly the attempts worth counting.
     *
     * NOT YET ATOMIC against a second worker — see the class docblock for what B3 makes of it.
     */
    public function claim(Publication $publication): Publication
    {
        return $this->transition($publication, PublicationStatus::PUBLISHING, [
            'attempts' => $publication->attempts + 1,
            'last_attempt_at' => now(),
            'failure_code' => null,
            'failure_context' => null,
        ]);
    }

    /**
     * IT IS OUT. `publishing | needs_reconcile → published`.
     *
     * From `needs_reconcile` this IS the reconciliation, concluded in the affirmative — and the
     * {@see RemoteRef} argument is what makes that more than a comment. Nothing in this module can
     * conjure a ref: it comes from a completed publish or from `PlatformAdapter::findExisting()`, so a
     * caller cannot assert "it went out" on optimism. That is the type system doing the work a boolean
     * flag would have left to discipline.
     *
     * `published_at` prefers what the PLATFORM said and falls back to now(). The fallback is the honest
     * reading after a reconciliation that ran hours later: we know it is out, we do not know when, and
     * recording the moment we learned is at least a true statement about this system.
     */
    public function markPublished(Publication $publication, RemoteRef $ref): Publication
    {
        return $this->transition($publication, PublicationStatus::PUBLISHED, [
            'remote_id' => $ref->id,
            'remote_url' => $ref->url,
            'published_at' => $ref->publishedAt ?? now(),
            'failure_code' => null,
            'failure_context' => null,
        ]);
    }

    /**
     * IT DEFINITELY DID NOT HAPPEN. `publishing | needs_reconcile → failed`.
     *
     * From `publishing` this means the adapter threw {@see \App\Modules\Publishing\Exceptions\PlatformRefused},
     * whose contract is "the platform said no AND nothing was created".
     *
     * From `needs_reconcile` it means the platform was ASKED and proved nothing exists. That distinction
     * is the whole reconciliation doctrine: it is this edge, and only this edge, that makes the ordinary
     * `failed → publishing` retry safe again. Nothing automatic may take it.
     *
     * @param  array<string, mixed>  $context  never anything derived from a credential
     */
    public function markFailed(Publication $publication, string $failureCode, array $context = []): Publication
    {
        return $this->transition($publication, PublicationStatus::FAILED, [
            'failure_code' => $failureCode,
            'failure_context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * WE DO NOT KNOW. `publishing → needs_reconcile`.
     *
     * The state with no automatic way out. Every throwable that is not a `PlatformRefused` lands here,
     * because "the request timed out" and "the request was rejected" are indistinguishable from the
     * outside and only one of them is safe to retry.
     *
     * `remote_draft_id` is deliberately left in place: it is the strongest evidence available about how
     * far the attempt got, and a reconciliation is far more likely to find the artifact when it can name
     * the container that would have become one.
     *
     * @param  array<string, mixed>  $context  never anything derived from a credential
     */
    public function markNeedsReconcile(Publication $publication, string $failureCode, array $context = []): Publication
    {
        return $this->transition($publication, PublicationStatus::NEEDS_RECONCILE, [
            'failure_code' => $failureCode,
            'failure_context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * HOLD IT — the connection is not usable. `scheduled | failed → blocked`.
     *
     * `scheduled_at` is KEPT, unlike a disarm. The publication still wants to go out at the moment
     * somebody chose; what is missing is a working connection, and throwing away the schedule would
     * make fixing the connection insufficient to recover.
     */
    public function block(Publication $publication, string $failureCode, array $context = []): Publication
    {
        return $this->transition($publication, PublicationStatus::BLOCKED, [
            'failure_code' => $failureCode,
            'failure_context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * PERSIST THE PHASE-1 HANDLE. Not a transition — the status does not move.
     *
     * It lives on the Manager anyway, because it is part of the lifecycle's integrity rather than of
     * anybody's business logic, and because the ONE rule about it has to be stated where the write is:
     * this must land in its own committed write, immediately, before phase 2 is attempted and outside
     * any transaction phase 2 could roll back. A handle that is lost is a container that gets made
     * twice.
     */
    public function rememberDraft(Publication $publication, RemoteDraft $draft): Publication
    {
        $publication->forceFill(['remote_draft_id' => $draft->id])->save();

        return $publication;
    }

    /**
     * FORGET the phase-1 handle.
     *
     * The one correct use is a reconciliation that PROVED the intermediate artifact is gone (expired,
     * or deleted on the platform), so the next attempt must legitimately create a new one. Calling it
     * anywhere else re-opens the exact hole `remote_draft_id` exists to close, which is why it is a
     * named method with this paragraph attached rather than a nullable argument on something else.
     */
    public function forgetDraft(Publication $publication): Publication
    {
        $publication->forceFill(['remote_draft_id' => null])->save();

        return $publication;
    }

    /** Whether the machine contains this edge. The one public reading of the table. */
    public function allows(PublicationStatus $from, PublicationStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * Every state reachable from here, for a caller that wants to offer only the moves that exist.
     *
     * @return array<int, PublicationStatus>
     */
    public function reachableFrom(PublicationStatus $from): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?PublicationStatus => PublicationStatus::tryFrom($value),
            self::TRANSITIONS[$from->value] ?? [],
        )));
    }

    /**
     * The one write. Every method above funnels through it, which is what makes "the Manager owns the
     * status" a structural fact rather than a habit.
     *
     * `forceFill` rather than `update`: the attribute set here is decided by this class, from a fixed
     * vocabulary, and going through mass-assignment protection would mean the machine's own writes were
     * subject to a list maintained for HTTP payloads.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws PublicationTransitionRefused
     */
    private function transition(Publication $publication, PublicationStatus $to, array $attributes = []): Publication
    {
        $from = $publication->status;

        if (!$this->allows($from, $to)) {
            throw PublicationTransitionRefused::for($from, $to);
        }

        $publication->forceFill($attributes + ['status' => $to])->save();

        return $publication;
    }
}
