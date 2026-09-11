<?php

namespace App\Modules\Publishing\Managers;

use App\Modules\Publishing\DTOs\RemoteDraft;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Models\Publication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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
 * B3 ADDED A SECOND WAY IN, AND IT IS THE SAME EDGE SAID DIFFERENTLY
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * B1 said of {@see claim()} that it was not yet atomic against a second worker and that B3 would make
 * it a conditional UPDATE. B3 did — as {@see claimDue()}, a SEPARATE method rather than a rewrite of
 * this one, because the two answer different callers:
 *
 *   claim()     THROWS when it does not get the row. For a caller that believed it held the row alone —
 *               the synchronous publish path and the tests that drive it — and being told is what such a
 *               caller needs, whether the edge was missing or somebody else took it first.
 *   claimDue()  ANSWERS NULL when it does not get the row. For the sweep, where losing the race is
 *               ORDINARY and must not be an error.
 *
 * Collapsing them would have forced one of those two to lie about the other's situation. What they do
 * share is this table: neither can select a row in `needs_reconcile` or `blocked`, so both fences hold
 * on both paths without either method restating them — and, since the fix to {@see transition()}, they
 * also share their mechanism: BOTH put the current status in a `WHERE` clause and neither can write over
 * a row that has moved. The difference is only what they say about it.
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
     * ATOMIC, like everything else that funnels through {@see transition()}: the write carries
     * `WHERE status = <the status this call decided from>`, so a second worker cannot claim a row this
     * one already took. What distinguishes it from {@see claimDue()} is not safety but VOICE — it
     * THROWS when it loses, because its callers (a synchronous publish, a test) believed they held the
     * row and a null would be silently discarded. The sweep, for which losing is routine, uses the other.
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
     * THE DUE-SWEEP'S CLAIM. `scheduled → publishing`, atomically, or nothing at all.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * ONE STATEMENT, BECAUSE TWO WOULD BE A RACE AND THE RACE COSTS A SECOND POST
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * {@see claim()} reads the row, decides, and writes — and between the reading and the writing a
     * second sweep can read the same row and reach the same decision. Both then write `publishing`, both
     * dispatch a job, and one publication becomes two public artifacts. The scheduler's
     * `withoutOverlapping` is not a defence: it bounds one command against ITSELF on one host, and says
     * nothing about a second host, a manual invocation, or a pass that outlived its lock.
     *
     * So the guard is the WHERE CLAUSE. Postgres locks the row for the UPDATE, so of two concurrent
     * callers exactly one sees `status = 'scheduled'` and gets `affected = 1`; the other sees the row
     * already moved and gets 0. A null return is therefore ORDINARY — "somebody else has it" — and the
     * caller skips it without an error. The same shape `BotTaskRunManager::claim()` and
     * `WorkflowScheduleService::claimDue()` already use.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE WHERE CLAUSE IS ALSO WHAT KEEPS BOTH FENCES STANDING ON THIS PATH
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `where status = 'scheduled'` cannot match a row in `needs_reconcile` or in `blocked`, so the sweep
     * inherits Fence 1 and Fence 2 without having to know they exist — it is not that the sweep declines
     * to claim those rows, it is that the statement cannot select them. The `allows()` check above the
     * statement is the second, structural half: if the edge is ever removed from the table this stops
     * working loudly instead of writing a status the machine no longer contains.
     *
     * `attempts` is incremented IN SQL rather than from the in-memory value, for the same reason the
     * status is guarded in SQL: a counter written as `read + 1` by two workers records one attempt for
     * two claims, and this counter's whole job is to be honest about how often we have touched a
     * platform.
     *
     * @return Publication|null the claimed row, refreshed — or null when somebody else claimed it first
     *
     * @throws PublicationTransitionRefused when the machine no longer contains the edge at all
     */
    public function claimDue(Publication $publication): ?Publication
    {
        $from = PublicationStatus::SCHEDULED;
        $to = PublicationStatus::PUBLISHING;

        if (!$this->allows($from, $to)) {
            throw PublicationTransitionRefused::for($from, $to);
        }

        $affected = Publication::query()
            ->whereKey($publication->getKey())
            ->where('status', $from)
            ->update([
                'status' => $to->value,
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'failure_code' => null,
                'failure_context' => null,
            ]);

        return $affected === 1 ? $publication->refresh() : null;
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
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT IS A COMPARE-AND-SWAP, AND THE `WHERE` CLAUSE IS THE ENFORCEMENT
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * This used to read `$publication->status` OUT OF MEMORY, check the edge against it, and then write
     * UNCONDITIONALLY. Every transition in the module was therefore only as correct as the freshness of
     * whichever copy of the row its caller happened to be holding — and in this module holding a stale
     * copy is not an edge case, it is the NORMAL SHAPE OF THE WORK: the sweep keeps the row it claimed
     * while a worker, in another process, publishes from its own copy minutes later.
     *
     * What that cost is worth spelling out, because none of it involves taking an illegal edge:
     *
     *   The queue accepts a job, the job publishes, and the dispatch call then throws. The sweep's
     *   `catch` concludes the row from the copy it claimed — writing `failed`, with the `remote_id` of a
     *   LIVE POST still on the row, and the product then offers to schedule it again. That is the second
     *   public artifact, reached from a line that reads as careful error handling.
     *
     *   The same interleave against a parked row degrades `needs_reconcile` to `failed` — Fence 1
     *   breached by a component that never looked at the row. `failed` ASSERTS that nothing was created;
     *   only a platform can establish that.
     *
     *   And in the isolating case, a copy that still remembers `publishing` overwrites a row that is
     *   already `published` — a state the table calls terminal — with no exception raised anywhere,
     *   because `publishing → failed` is a perfectly legal edge for the status the caller *thought* it
     *   had.
     *
     * So the check moved into the statement: `WHERE id = ? AND status = <from>`. Postgres locks the row
     * for the UPDATE, so of two callers exactly one matches and the other affects zero rows. Zero rows
     * means the row moved between the caller's read and its write, and the caller is TOLD — the
     * alternative, a silent no-op, leaves a caller believing it concluded something it did not, which in
     * this module is how a screen offers a retry for a post that is already out.
     *
     * The payload is taken from {@see \Illuminate\Database\Eloquent\Model::getDirty()} AFTER the
     * `forceFill`, so what reaches the statement is post-cast: `failure_context` is the encoded JSON the
     * `array` cast produces, `status` is the enum's backed value, an instant is a formatted timestamp.
     * Building the array by hand instead would mean re-implementing every cast on this model, silently
     * and wrongly, right here.
     *
     * `forceFill` rather than `fill`: the attribute set is decided by this class from a fixed vocabulary,
     * and going through mass-assignment protection would subject the machine's own writes to a list
     * maintained for HTTP payloads. (`status` is deliberately absent from `$fillable` — that is what
     * stops everything OUTSIDE this module writing it.)
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * WHAT IT DOES AND DOES NOT PROTECT
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT DOES: guarantee that a status write only lands on a row still in the state the caller decided
     * from. Two processes concluding one publication produce one conclusion and one refusal, whichever
     * order they arrive in, and the refusal names where the row actually ended up.
     *
     * IT DOES NOT: order the work. It cannot make the RIGHT process win — if a reaper and a live worker
     * both conclude a publication, the CAS guarantees only that one of them does. That ordering is what
     * `stale_after` (many times the job timeout) and `publish_timeout` (below the queue's `retry_after`)
     * are for, and they remain load-bearing rather than belt-and-braces.
     *
     * IT ALSO DOES NOT make a lost race an error. For most callers here it is ordinary — see the
     * `catch (PublicationTransitionRefused)` in the sweep, the reaper, the job's failure hook and the
     * connection manager's hold/release loops, each of which logs it and carries on.
     *
     * AND IT DOES NOT FIRE MODEL EVENTS. This is a builder UPDATE, not `save()`, so `saving`/`updating`/
     * `updated` never dispatch for a status transition. Nothing observes `Publication` today, but an
     * observer added later will silently miss every transition unless it is wired here instead.
     *
     * {@see claimDue()} predates this and stays a separate method: it answers `null` instead of throwing,
     * because for the due-sweep losing the race is the mechanism working rather than something to report.
     * The two are deliberately kept in step — both put the status in the WHERE clause, and `attempts` /
     * `last_attempt_at` are stamped only by a claim, never here.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws PublicationTransitionRefused when the machine lacks the edge, or when the row moved first
     */
    private function transition(Publication $publication, PublicationStatus $to, array $attributes = []): Publication
    {
        $from = $publication->status;

        if (!$this->allows($from, $to)) {
            throw PublicationTransitionRefused::for($from, $to);
        }

        $publication->forceFill($attributes + ['status' => $to]);

        $affected = Publication::query()
            ->whereKey($publication->getKey())
            ->where('status', $from)
            ->update($publication->getDirty());

        if ($affected !== 1) {
            // Read back before reporting: the caller is handed the row's REAL state, both on the model
            // it passed in and in the refusal, so whatever it decides next is decided from the truth.
            $publication->refresh();

            throw PublicationTransitionRefused::lostRace($publication->status, $to);
        }

        // The statement bumped `updated_at` (and nothing else re-read it), so the in-memory copy would
        // otherwise disagree with the row about when it last changed — which the reconciliation pass
        // orders by. `refresh()` rather than `syncOriginal()` for that reason, and to match `claimDue()`.
        return $publication->refresh();
    }
}
