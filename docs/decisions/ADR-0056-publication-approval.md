# ADR-0056 — Publication approval: an Approvable, not a trigger, and a parked arming intent consumed on use

**Date:** 2026-09-12
**Status:** Accepted
**Module:** `App\Modules\Publishing` (`Models\Publication`, `Services\{PublicationService,PublicationAutomationService}`,
`Managers\PublicationManager`, `Events\PublicationConcluded`, `Exceptions\PublicationUnderReview`,
`Http\Requests\{SchedulePublicationRequest,StorePublicationRequest,UpdatePublicationRequest,Concerns\ExplainsAReviewHold}`,
`Http\Resources\PublicationResource`, `Policies\PublicationPolicy`), `App\Modules\Workflows`
(`Steps\PublishStep`, `Services\PublicationWaitResolver`, `Listeners\ResumeWaitingRunOnPublicationConcluded`,
`Enums\WorkflowStepType`, `Http\Requests\StoreWorkflowRequest`, `WorkflowsModuleServiceProvider`),
`App\Modules\Approvals\Services\ApprovalService` (the one-live-process guard and the deleted-subject
null-guard — both generic, and both now exercised by a second `Approvable`), two migrations
(`2026_09_11_000000_add_approval_to_publications_table.php` and its tenant mirror
`database/migrations/tenant/0001_01_01_000082_add_approval_to_publications_table.php`)
**Relates to:** ADR-0009 §1 (the `approval_finished` **trigger**, removed in the 5.1 re-scope — the semantics
this ADR declines to bring back), ADR-0015 (polymorphic creator — a `publish` step's publication is attributed
to the *run*, not to whoever fired the trigger), ADR-0039 (the suspend/resume engine `publish` is the second
consumer of), ADR-0054/ADR-0055 (the state machine and queue doctrine `arm()`/`transition()` already enforced —
this batch gates entry to them, it does not change them), `docs/backend/publishing-api.md` §"Approvals & review
(B6)", `docs/backend/workflows-api.md` §"`publish`" — the wire contracts this ADR explains the reasoning behind

---

## Context

R4 B1–B3 built a publication state machine (`PublicationManager`), a connected-account layer with OAuth
(ADR-0054), and a queue that claims, publishes, reaps and reconciles (ADR-0055). None of it asked whether
anybody should have to say yes first. Arming (`draft|failed|blocked → scheduled`) was gated on nothing but
ownership — `PublicationPolicy` composing "the creator, or the workspace owner" with
`PublicationStatus::isEditable()` — the same posture a draft's *content* is edited under. B6's brief was to let
a publication be gated on a review, through the same `App\Modules\Approvals` pipelines a `Task` already uses.

`Task` is — was, before this batch — the **only** implementation of `App\Modules\Approvals\Interfaces\Approvable`
in the product, four years old by `ApprovalService`'s own account. Reusing "the same pipelines" sounds like it
answers the question by itself; it does not. `Approvable` is a shape (eight methods: two relations, two
lifecycle hooks, a queue-card projection, a context snapshot, a relation list, a live-review predicate) — it
says nothing about *what a review is for*. For a `Task`, approval is a workflow-stage gate with no effect
outside the application: nothing irreversible sits on the other side of a "yes". For a `Publication`, arming is
the one act in the whole module with consequences outside it — the due-sweep claims an armed row and a minute
later something exists on somebody's timeline that nothing written here can take back. Wiring `Approvable` onto
`Publication` without deciding what the review *gates* would have produced a working feature that answered the
wrong question.

The owner's standing decision (D2, recorded in the batch's commit message and in `Publication`'s own class
docblock) is: **a publication is an `Approvable`, never a trigger — the review gates *arming*, and nothing about
approving one starts anything new.** Every decision below is either a direct consequence of D2 or a fix the
batch's own two review rounds found necessary to make D2 hold under the tenancy and concurrency invariants
ADR-0054/0055 had already established. Three of those fixes (recorded in the batch's commit as three "holes",
and in the tests as findings **K1**, **K2** and **A1**) are folded into the decisions they repair rather than
listed separately — a decision that shipped broken and was then fixed in the same batch is documented as the
decision that holds, with the failure mode stated as the reason it holds that way.

---

## Decisions

### 1. `Publication implements Approvable`, and approval gates **arming** — not a trigger

A trigger with roughly this semantics — "run something when an approval finishes" — existed once
(`approval_finished`, one of Etap-5's five trigger types) and was deliberately removed in the 5.1 re-scope
(ADR-0009 §1), along with `ApprovalService::dispatchWorkflowTrigger()`. Nothing in this batch brings it back,
and the reason is sharper here than it was for the original removal. A trigger starts a **new** workflow run
with no memory of the run that composed the content in the first place — for the `publish` step (Decision 7)
that would mean the five outputs a later step might reference (`publication_id`, `status`, `remote_id`, `url`,
`published_at`) could never reach anything, because the run waiting on them is not the run an
`approval_finished` trigger would create. It would also contradict the reason `WorkflowRunState::WAITING`
exists at all (ADR-0039): a suspended run is *the same run*, parked, not a run replaced by a fresh one that
happens to fire at a related moment.

So `Publication` is `Approvable` and the gate is on `onApprovalCompleted()`/`onApprovalRejected()` performing
(or refusing) the **arming**, never on anything that reaches into Workflows:

- **The persisted snapshot is exactly what will be sent.** `getApprovalContext()` writes the title, the body,
  the platform, the platform-connection id, the ordered media ids and the intended moment — the whole of what
  would change the artifact. This is deliberately not a map of "answers" (per ADR-0009 §1's own distinction for
  what a snapshot is), and unlike a `Task`'s context nothing is ever *restored* from it: it exists so "what
  exactly did the approver say yes to" is answerable from a row after the fact, because this is the one
  `Approvable` whose approval authorizes something irreversible.
- **A live review refuses both edit and arm**, and says so. `PublicationPolicy::update()` and
  `::schedule()` both compose `!$publication->isInApproval()`; the refusal is a `422`
  (`PublicationUnderReview::CODE = 'publication_under_review'`) rather than a bare `403`, raised from
  `ExplainsAReviewHold::failedAuthorization()` — deliberately *after* the policy has already decided, so the
  trait only ever picks the sentence, never the outcome. A `403` answers "not you"; that is false here — the
  creator, the workspace owner and the approver all get the identical refusal, and none of them has a
  permission problem. The row is busy being decided about.
- **A rejection leaves a draft, visibly.** There is no `rejected` `PublicationStatus` and this batch does not
  add one — inventing it would mean inventing its edges too, including one back into `scheduled`, which is
  precisely the move a rejection exists to withhold. `onApprovalRejected()` writes nothing to the row at all;
  what makes the refusal readable is `latestApprovalProcess` (ordered by `created_at` **and then `id`**, because
  two processes of one run can share a `created_at` to the microsecond — see Decision 6) and the derived
  `approvalWasRejected()` / `approval_state` the resource exposes (`docs/backend/publishing-api.md`).

Two implementations of `Approvable` now exist in the product, and B6 shaped the second deliberately like the
first rather than inventing a parallel pattern — Decision 7 covers the corresponding cost: the shared engine,
`ApprovalService`, must not learn either client's name.

### 2. `arm_on_approval_at` — a parked intent, one nullable column, consumed on use

A publication a review gates has decided *when* it wants to go out before it is allowed to arm. That moment has
to live somewhere between "a workflow step (or a person) chose it" and "the last approver said yes", and the
migration's own docblock states why it is a new column and not a repurposing of `scheduled_at`: **on a draft,
`scheduled_at` already means something** — `StorePublicationRequest` accepts a moment on create and
`PublicationDTO` documents it as intent that is explicitly not an arming (a person picking a time while still
writing). Reusing it for "arm automatically once approved" would make the arm-on-approval hook unable to tell
"a machine wants this armed" from "a person is drafting and will press Schedule themselves" — every hand-typed
moment on a draft would silently self-arm the instant a review happened to pass.

So the flag and the moment are **one nullable timestamp**, not a boolean plus a timestamp: `NULL` means nothing
arms this publication by itself — the manual path's entire guarantee. A non-null value is a deliberately
chosen instant, `"as soon as it may"` encoded as `now()` at the moment it was parked (in a module where nothing
publishes synchronously, a moment already past has always meant "the next sweep pass takes it" — the same
encoding `POST …/schedule` with no explicit time produces for a person).

**Who writes it, precisely — three sites, not the two the model's own inline `$fillable` comment currently
claims** (see the note at the end of this decision):

1. `PublicationAutomationService::create()` — the `publish` workflow step's path, when an
   `approval_pipeline_id` is supplied: `arm_on_approval_at = $publishAt ?? now()`, in the same
   connection-scoped transaction that creates the row (Decision 7 / the class's own docblock on why the
   transaction is scoped to the row's own connection and stops short of `startProcess()`).
2. `PublicationService::schedule()`'s **submit branch** (Decision 5) — a person pressing Schedule on a
   review-gated draft with no standing approval: `$publication->update(['arm_on_approval_at' => $at])`.
3. `Publication::onApprovalCompleted()` — **clears** it (`forceFill(['arm_on_approval_at' => null])`) the
   moment it has armed something. The intent is consumed on use because it describes *one* moment chosen for
   *one* decision: left behind, it would re-arm a publication that was later disarmed and sent through a
   **second** review, for the original (by then long-past) instant — "publish immediately" is not what either
   approver said. `PublishingApprovalTest::test_a_consumed_arming_intent_never_fires_twice` pins exactly this:
   disarm, resubmit with no new intent, approve again — nothing arms, because the column the second approval
   reads is already `NULL`.

**A refused submission must take the parked value back out (finding A1).** `PublicationService::schedule()`
writes `arm_on_approval_at` *before* `ApprovalService::startProcess()` is called, because the snapshot the
approver reads has to carry the moment. That makes the failure path load-bearing: `startProcess()` refuses a
pipeline with no stages, one that has vanished, or (since Decision 6) a subject that already has a live review
— and an intent left parked after a refused submission is a live grenade, armed later by whatever approval
eventually succeeds, for an instant nobody chose that day. The fix restores the **previous** value in a
`catch` around `startProcess()`, and `PublishingApprovalTest::test_a_refused_submission_does_not_leave_a_parked_moment`
pins it.

**Note on the model's own comment (a genuine, uncorrected inconsistency in the code this ADR documents
around, not through).** `Publication::$fillable`'s inline comment for `arm_on_approval_at` reads *"NEVER
reachable from an HTTP payload: `PublicationDTO` does not carry it and `PublicationService` does not write
it. Exactly two things write it — the module's own automation seam sets it, and `onApprovalCompleted()`
CLEARS it."* That was true before the "B6 fix round" added the submit branch to `PublicationService::schedule()`
(site 2 above); it is not true of the code at `0ca0b1c`. The narrow half still holds — no HTTP field sets the
column directly — but the write is real and goes through the same class. This ADR documents the column by its
actual write sites; the comment is flagged here rather than silently corrected, since fixing it is a one-line
code change outside this documentation pass.

### 3. `PublicationConcluded` is emitted only after a **won** compare-and-swap; `needs_reconcile` is never a conclusion; `REVIEW_REJECTED` is a conclusion outside `PublicationStatus`

ADR-0055 Decision 3 named this exact trap in advance: `PublicationManager::transition()` is a query-builder
`UPDATE …  WHERE id = ? AND status = $from`, not `save()`, so Eloquent's `saving`/`updating`/`updated` events
never fire for a status change — "an observer added later will silently miss every transition unless it is
wired into `transition()` itself." B6 is that "later", and the wiring is a private `announce()` called from
inside `transition()` **strictly after** the compare-and-swap has succeeded, never before it and never on the
branch that throws `PublicationTransitionRefused::lostRace()`. Two processes racing to conclude one publication
therefore produce exactly one conclusion and exactly one announcement, in whichever order they arrive — the
losing process never dispatches anything for a state it did not actually reach.

This was re-verified by mutation, not merely reasoned about: moving the `announce()` call before the CAS, or
calling it from the refusal branch, left the full suite green until
`WorkflowPublishStepTest::test_a_lost_race_never_announces_a_conclusion` was written to hold a stale in-memory
copy of a `publishing` row across a real `markPublished()`/`markFailed()` race and assert the event fired
**exactly once**.

`needs_reconcile` is deliberately excluded from `PublicationConcluded::concludingStatuses()`
(`published`, `failed`, `blocked`). It is not a softer conclusion; it is the absence of one — "we do not know
whether a post exists" is not an answer a waiting caller can act on, and reporting it as a conclusion would
have a workflow run (or any future listener) decide "failed" about an artifact that may already be live, or
"published" with a `remote_id` nobody established. `PublicationWaitResolver::status()` and `PublishStep::resume()`
both treat it as `PENDING`/re-suspend, never `SETTLED`/`GONE` — the fourth item in the module's own vocabulary
of things a state machine's fences protect (alongside the two `needs_reconcile`/`blocked` fences ADR-0055
already documents).

`PublicationConcluded::REVIEW_REJECTED` is a **string constant**, deliberately not a `PublicationStatus` case —
a rejection does not move the row (Decision 1: it stays `draft`), so it cannot be one of `transition()`'s
outcomes. `Publication::onApprovalRejected()` calls `PublicationConcluded::fromRejectedReview()` directly; this
is the event's **only** other call site besides `transition()`'s `announce()`, and it is the one call that does
not go through a CAS at all (nothing state-shaped is being written).

**Fast path and correctness guarantee, mirroring the Generator's own pair exactly (ADR-0039).**
`ResumeWaitingRunOnPublicationConcluded` listens to the event and dispatches a correlated
`WorkflowRunResumeJob` — an optimization, never thrown from, wrapped and reported on failure, because it runs
inside whatever just concluded the publication (a queued publish worker, a sweep pass, an HTTP decide request).
The waiting-run sweep (`WorkflowRunManager::reapWaitingRuns()` via `PublicationWaitResolver`) is the correctness
backstop, and it matters *more* here than for `generate_content`: `needs_reconcile` is never announced, so a
run parked on one is recovered **only** by the sweep, indefinitely, until either a reconciliation resolves it or
`workflows.wait_timeout` ends the run. A second delivery of the same conclusion (at-least-once redelivery, or
the listener and the sweep both firing) is a clean no-op: `WorkflowRunManager::claimResume()` is a correlated
claim (`WHERE state = 'waiting' AND waiting_key = ?`), so whichever caller arrives second finds the run already
moved and does nothing —
`WorkflowPublishStepTest::test_a_second_delivery_of_the_conclusion_resumes_nothing_twice` pins it end to end.

### 4. The effects of a decision are deferred to `DB::afterCommit()` — a connection-mismatch trap in new clothes

`ApprovalService::decide()` runs inside `DB::transaction()` on the **default** connection — bare `DB::` always
resolves `database.default`. `Publication` is `TenantAware`: for an own-database workspace its writes go
through the **tenant** connection, which that transaction never covers. Arming from inside `decide()`'s
transaction (the naive, first-reach-for-it version) would therefore let a publication commit as `scheduled` on
the tenant connection while a later exception rolled the **decision itself** back on the default one — an
armed publication whose approval, by the record, never durably happened. The due-sweep would then publish it: a
public artifact standing behind an approval that was undone.

The fix is `DB::afterCommit()` around both hooks' effects — `onApprovalCompleted()`'s arm and
`onApprovalRejected()`'s announcement both run only once `decide()`'s transaction actually commits, and not at
all if it rolls back. `PublishingApprovalTest::test_the_approval_decision_is_durable_before_its_effects` is the
test that watches the seam directly rather than trusting the reasoning: it opens its own transaction around
`decide()`, asserts nothing has moved while that transaction is still open (a same-connection read would
already see an uncommitted `scheduled` if the arming were inline), commits, and only then asserts the row
armed — then repeats the sequence and rolls back instead, asserting nothing armed at all. With no transaction
open (a console call, the AI-approval job), `DB::afterCommit()`'s callback runs immediately, so the
single-statement path is byte-identical to before.

This also fixed a second, quieter defect the first fix's own review surfaced: `config/queue.php` sets
`after_commit => false`, so a `PublicationConcluded` fired **inside** `decide()`'s transaction could reach a
queue worker that read the approval process while it was still `Pending` — the waiting run's fast path would
then find no answer and fall back to the sweep, losing minutes, at random, depending on worker timing. Deferring
the announcement to the same `afterCommit()` closes this incidentally, by construction, rather than through a
second mechanism.

One behavioural consequence worth stating plainly: **a refused arming (`PublicationTransitionRefused`, caught
inside the deferred callback) is still swallowed**, exactly as it was before this fix — but it is now swallowed
*after* the decision has already committed, so swallowing it can no longer roll anything back. The honest
reading of "approved, but the row moved between the review and this write" is an **approved publication that is
not armed**: it stays a draft with a Schedule button, `Log::warning` is the record of why, and a workflow run
parked on it waits out `workflows.wait_timeout` rather than being told something false.

### 5. Submit-at-schedule — a reversible deputy decision, not the owner's D2

Attaching a pipeline to a draft says "this kind of thing gets reviewed"; it does not by itself say "judge this
half-typed draft right now". The `Task` precedent is that a review begins at a **lifecycle moment**
(`IN_TEST`), not at attachment — and for a publication that lifecycle moment is unmistakably the attempt to
arm, because arming is the act the whole review exists to gate.

Before this branch existed (caught in the batch's own first review round), `PublicationService::schedule()` had
only the automated shape: a draft with `approval_pipeline_id` set and no standing approval would simply **arm**
on `POST …/schedule`, exactly like one with no pipeline at all. An attached pipeline was decorative on the
manual path — the quietest possible defeat of D2's own sentence ("you approve exactly what goes out"), because
nothing ever stopped a person from scheduling straight past it.

The fix, and the shape documented in `docs/backend/publishing-api.md`: on a draft whose `approval_pipeline_id`
is set and whose latest approval process (if any) is not `Approved`, `POST …/schedule` **parks** the chosen
moment on `arm_on_approval_at` and calls `ApprovalService::startProcess()` instead of arming. The response is
`200`, `status: draft`, **`is_in_approval: true`** — a screen has to read that flag to distinguish "armed" from
"submitted", because `status` alone cannot. A **standing** approval (latest process `Approved`) falls through to
an ordinary arming — approval already lifted the hold, so the button does what it says. A **rejected** latest
process falls into the same submit branch again: pressing Schedule after a rejection *is* the resubmission,
with whatever the author fixed carried in a fresh snapshot.

This decision is explicitly marked in the code as a **deputy decision, reversible** — `PublicationService::schedule()`'s
own docblock calls it out by that name — as distinct from D2 itself, which is the owner's and is not up for
revisiting inside this batch. The distinction matters for anyone reading this ADR later: D2 (Approvable, not
trigger) is settled; *which lifecycle moment starts a manual review* is a narrower, explicitly flagged-as-open
implementation choice modelled on `Task`'s own precedent.

### 6. One live approval process per subject — a guard added to `ApprovalService`, generic, and now load-bearing for every `Approvable`

Measured before the guard existed (finding **K1**): two frames submitting the same draft in close succession
produced **two** pending `ApprovalProcess` rows for one publication. Approving one armed the row while
`isInApproval()` — reading "is any process for this subject still pending" — still answered `true`, because the
second process was still open; the second reviewer's decision (approve *or* reject) then landed on a
publication that was already armed and possibly already claimed by the sweep. A rejection in that ordering is
the sharper failure: a refusal arriving after the row is already outgoing.

The fix lives in `ApprovalService::startProcess()`, not in `Publication` or in `PublicationPolicy` — a read
before the insert (`ApprovalProcess::query()->where('approvable_type', …)->where('approvable_id', …)->where('status', Pending)->exists()`)
throws the same `ValidationException` shape a missing pipeline or a stageless pipeline already throws when one
is found. It is **generic and names no concrete `Approvable`**, which is the point of fixing it here rather than
in `Publication`: the exact same defect was always latent for `Task` (a workflow could not previously produce
two simultaneous submissions the way two racing HTTP requests to `POST …/schedule` now can, but nothing in
`ApprovalService` ever refused it), and the fix protects both without either module needing to know about the
other. `PublishingApprovalTest::test_a_second_review_cannot_open_while_the_first_is_live` pins the publication
side; the guard's presence in the **shared** service is what makes it apply to `Task` too, without a second copy
of the check.

A **razor-thin race remains**: the existence check and the insert are not one atomic statement, so two requests
landing on the same millisecond can both read "nothing pending" and both insert. Closing it fully needs a
partial unique index — `(approvable_type, approvable_id) WHERE status = 'pending'` — on `approval_processes`,
which is a schema change to a table every current and future `Approvable` shares. That index is **not** added
by this batch; it is named here as a decision that belongs to the module's owner rather than taken unilaterally
inside a publishing batch (see Consequences).

### 7. Module boundaries: `Workflows → Publishing` narrow and one-way; `Publishing ↛ Workflows`; `Approvals ↛ Publishing` by namespace **and** by semantics

Three Workflows classes own the entire `Workflows → Publishing` edge — `PublishStep`,
`PublicationWaitResolver`, `ResumeWaitingRunOnPublicationConcluded` — plus `StoreWorkflowRequest`'s
write-time reuse of `PublicationAutomationService::destinationIsUsable()`. The engine **core** —
`WorkflowStepRunner`, `WorkflowRunManager`, both run jobs, `WaitResolverRegistry` — never names `Publishing` at
all, so removing the integration (or adding a third suspending step for something else entirely) never touches
the engine. `PublicationWaitResolver` is registered **lazily** from `WorkflowsModuleServiceProvider::boot()`
(`WaitResolverRegistry::registerLazy(PublishStep::WAIT_KIND, …)`) precisely because `boot()` runs on every
request and only the waiting-run sweep ever actually asks — eagerly constructing it would drag
`PublicationAutomationService` and its dependencies into every request in the application.

`Publishing → Workflows` is forbidden **module-wide**, and the temptation the pin exists for is named directly
in `WorkflowsPublishingBoundaryTest`'s own docblock: a future "lost resume" bug report, and somebody making
`PublicationManager` look the waiting `WorkflowRun` up and set it running directly — closing the cycle so
quietly that nothing else in the suite would notice. Publishing announces `PublicationConcluded` and knows
nothing about who, if anyone, is listening; reacting to it is entirely Workflows' business.

`Approvals ↛ Publishing` is the third edge, and it is refused **two different ways** because one of them is not
enough. The namespace scan (`assertModuleNeverNames('Approvals', 'App\Modules\Publishing')`) refuses an
`import`; a **second** scan (`test_approvals_never_compares_its_way_to_a_client`) strips comments and refuses the
bare literals `'publication'` / `'Publication'` anywhere in the Approvals module's source — because a
`class_basename($x) === 'Publication'` or `$process->approvable->getMorphClass() === 'publication'` check would
teach the shared engine a client's identity without ever importing its namespace, and a re-review mutation
walked straight through the first scan while staying invisible to it. Both mutations — a forbidden import added
to `PublicationManager`, and one added to `ApprovalService` — left the **entire suite green** before this test
file existed, which is the argument for pinning bytes rather than trusting review.

The second `Approvable` also forced one real, generic change **inside** `ApprovalService` itself, beyond the
guard in Decision 6: `recordChangelog()` used to assume every `Approvable` was also `HasChangelog` (true for
four years because `Task` was the only implementation of either), and passed the entity straight into
`ChangelogManager::handleCustomEvent(Model&HasChangelog, …)` — a contract enforced by a `TypeError`
several frames deep the moment a review started on anything else. `Publication` does not implement
`HasChangelog` (no screen consumes an audit trail for it yet), so `recordChangelog()` now guards with
`$entity instanceof HasChangelog` and no-ops otherwise — **the changelog is optional, not the approval**,
because requiring one would mean every future `Approvable` had to grow a changelog implementation it may have no
screen for. This is exactly the kind of change ADR-0055's own doctrine anticipates: a shared module's contract
changing because a second, honest client exercised a part of it the first client's presence had left untested.

Finally, and structurally rather than behaviourally: the `publish` step can never reach the publisher.
`WorkflowsPublishingBoundaryTest::test_neither_the_step_nor_the_seam_can_reach_the_publisher` byte-scans
`PublishStep.php` and `PublicationAutomationService.php`, **with comments stripped first**, for the identifiers
`PublicationPublisher`, `PublishPublicationJob`, `publishClaimed`, and for any `->publish(` call. Stripping
comments is what lets the needles be bare identifiers rather than fragile substrings — the first version of this
pin was walked through, during re-review, by an aliased import (`use …\PublishPublicationJob as
ImmediateDelivery;`), which sailed past a naive string search on the class name and would not have sailed past
this one. The step arms; the due-sweep publishes; nothing written by this batch narrows that gap.

---

## Consequences

- **A publication under review cannot be changed by anybody, including its own creator, while a decision is
  pending.** This is the intended cost of Decision 1, not friction: the alternative is an approval that
  authorizes content nobody can point to afterwards.
- **A workflow run parked on a `publish` step now depends on the same fast-path/backstop pair `generate_content`
  established (ADR-0039), with one sharper edge**: because `needs_reconcile` is never announced (Decision 3),
  a `publish` step's run recovers *only* through the sweep for that one status, for as long as it takes a
  reconciliation probe or a person to resolve it, bounded solely by `workflows.wait_timeout`.
- **`ApprovalService` gained a generic guard (Decision 6) and a generic changelog guard (Decision 7) that
  benefit `Task` as much as `Publication`**, without either module needing to coordinate: both live in the
  shared engine, keyed on the `Approvable`/`HasChangelog` contracts rather than on any concrete class.
- **Every future `Approvable` inherits the deferred-effects posture of Decision 4 as a pattern, not as
  machinery**: nothing in `ApprovalService` enforces that a third implementation defers its own
  `onApprovalCompleted()` side effects past commit — a future implementation whose writes also cross a
  connection boundary has to re-derive this, the same way `Publication` had to.
- **Testing**: 53 new scenarios across `PublishingApprovalTest`, `WorkflowPublishStepTest` and
  `WorkflowsPublishingBoundaryTest`, all reviewer-gated across two rounds (14 mutations, two of which disproved
  the review's own first pins before those pins could ship wrong). The full suite is green (3488 passing, 32
  behind environment flags) as of `0ca0b1c`. The tenant-database twin of the approve→arm scenario was written
  but has **not been run** — it issues `CREATE DATABASE`/`DROP DATABASE` and is gated behind
  `TENANT_DB_TESTS=1`, which needs the owner's approval per the project's safety rules; it should be run by hand
  (`TENANT_DB_TESTS=1 php artisan test --filter=Tenant`) before the next change to the composer's queued path or
  to the approval write path.

### Open — decisions that belong to the product owner, not to this ADR

- **Staleness of a standing approval after a content edit.** Nothing in this batch stops a publication's
  content from being edited *after* a review approves it but *before* it is scheduled/armed (an approved draft
  with no parked intent, per Decision 2's manual path, is editable again — Decision 1's "the review gates
  editing" only holds while a review is *live*). `PublicationService::schedule()`'s standing-approval branch
  will then arm on a "yes" that was said to different content. Three options were identified during the
  batch's own re-review and none has been chosen: (1) invalidate the standing approval by a content fingerprint
  the moment the row is edited, forcing resubmission; (2) refuse editing outright while *any* standing (not
  just live) approval exists, closing the gap by removing the freedom instead; (3) accept the gap and record
  what was approved versus what shipped, leaving reconciliation to a person. This ADR takes no position.
- **The partial unique index on `approval_processes`** that would close Decision 6's residual insert-race
  window — a schema change to a table every `Approvable` shares, and therefore the owner's call rather than a
  publishing-batch decision.
- **Explicit ratification, for `Task`, of the one-live-process guard Decision 6 added to the shared
  `ApprovalService`.** The guard already protects `Task` today as a side effect of living in the shared
  service; nobody has been asked whether that is the wanted behaviour for `Task`'s own submission flows, only
  whether it is correct for `Publication`'s.
- **Cancelling a pending approval process when its subject is deleted.** `ApprovalService::decide()` now
  refuses politely (a `422`, `approvable_missing`) instead of crashing when the subject was deleted while a
  review was live (`PublishingApprovalTest::test_deciding_about_a_deleted_publication_refuses_politely_instead_of_crashing`),
  but the orphaned process itself is left `Pending` forever, rendering with a null entity
  (`test_the_queue_lists_a_publication_and_survives_its_deletion`). This is named debt in `ApprovalService`'s
  own `decide()` comment, equally true of `Task` today, and not resolved by this batch.
