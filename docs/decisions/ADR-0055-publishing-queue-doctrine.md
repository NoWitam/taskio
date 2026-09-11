# ADR-0055 — Publishing queue doctrine: never retry an ambiguous outcome, the reaper never re-queues, and the state machine binds the row, not the caller's copy

**Date:** 2026-09-11
**Status:** Accepted
**Module:** `App\Modules\Publishing` (`Managers\PublicationManager`, `Services\{PublicationQueueService,
PublicationPublisher}`, `Jobs\PublishPublicationJob`, `Console\{DispatchDuePublicationsCommand,
ReconcilePublicationsCommand,Concerns\SweepsEveryWorkspace}`, `Exceptions\PublicationTransitionRefused`,
`Support\OAuthCallbackReason`, `config/publishing.php`, `routes/console.php`,
`app/modules/Publishing/routes/api.php`)
**Relates to:** ADR-0054 (platform connections & OAuth — the connection this queue publishes through),
the B1 state machine (`PublicationManager::TRANSITIONS`, undocumented by a standalone ADR but restated
in full in the class docblock and in `docs/backend/publishing-api.md`), `docs/backend/publishing-api.md`
§"Queue, reaper and reconciliation (B3)" — the wire contract this ADR explains the reasoning behind

---

## Context

R4 B1 shipped a publication state machine with two fences already built for machinery that did not
exist yet: `needs_reconcile` (no edge back to `publishing`, ever) and `blocked` (no edge to `publishing`
either). B3's job was to build the thing those fences were waiting for — a due-sweep that claims armed
publications, a worker that actually calls a platform, a reaper for claims nobody came back for, and a
reconciliation pass that is the only way out of `needs_reconcile`.

Every decision below serves one sentence: **a doubly-published post is a public artifact that nothing
written in this application can withdraw.** B1's graph makes that unreachable by *taking an illegal
edge*. B3 adds machinery that runs *outside* the graph — a sweep, a worker, a queue that retries by
default, a reaper that fires long after the fact — and every one of those is a way to publish something
twice **without** ever taking an illegal edge. The state machine review that gated this batch (two
rounds, Request Changes → fix → targeted re-check → Approve) found exactly that: a defect class where
every individual line reads as reasonable error handling and the sum is a second post. Decision 3 below
is the fix for that defect class; the rest of this ADR is the doctrine the fix now lets the rest of the
queue rely on.

---

## Decisions

### 1. `PublishPublicationJob::$tries = 1`, permanently

A queued retry after an ambiguous failure is a coin flip whose losing side is a second public artifact.
The job died mid-call, the platform timed out after accepting, the connection dropped — from outside,
none of those are distinguishable from "nothing happened," and only one of those worlds is safe to
retry into. `tries = 1` refuses the coin flip structurally: there is no second attempt for the queue to
make, automatically, ever.

The corollary is where an ambiguous outcome actually goes: `needs_reconcile`, not `failed`. `failed` is
a **claim about the world** — "the platform was asked and definitely created nothing" — and it is what
re-opens the ordinary `failed → scheduled/publishing` retry. A dead process, a timeout, or an
unrecognised response has no standing to make that claim; `PublicationPublisher::publishClaimed()`
classifies exactly one exception family (`PlatformRefused`, whose contract *is* "nothing was created")
to `failed`, and routes everything else — every `Throwable` that is not that one type — to
`needs_reconcile`.

`PublishingQueueTest::test_the_publish_job_never_retries` reads `$tries` and asserts it equals `1`, and
additionally asserts the job declares no `$backoff` property and no `retryUntil()` method — both are
the natural, reasonable-looking things somebody reaches for while chasing a flaky platform, and both
silently re-open the same door `tries = 1` closes. Nothing else in the suite would go red if one of them
were added back; the failure would arrive as a duplicate post on a real channel, unattributable, months
later.

### 2. The reaper never re-queues anything — it only parks, and parking is not a demotion to fix later

`PublicationQueueService::reapStalePublishing()` finds publications stranded in `publishing` past
`stale_after` and moves each to `needs_reconcile` via `PublicationManager::markNeedsReconcile()`
(`FAILURE_REAPED = 'reaper_stale'`). It never dispatches a new job, never re-arms `scheduled_at`, and
never writes `failed`. Every other stale-claim reaper in this codebase (bots, workflow runs, knowledge
indexing) *releases* its claim so the work can run again — the right move when the work is idempotent.
Publishing a post is not idempotent, so the same helpful instinct here would republish an artifact that
may already exist, on a schedule, unattended.

The only two ways out of `needs_reconcile` are conclusions of a reconciliation, and the state machine
enforces this by construction rather than by convention: `markPublished()` requires a `RemoteRef`, and
from `needs_reconcile` the only thing in the module that can produce one is
`PlatformAdapter::findExisting()`; `markFailed()` from this state means the platform was **asked and
proved absence**. There is no third path, no attempt limit, and no eventual give-up —
`PublicationQueueService::reconcilePending()`'s docblock states this as design, not gap: "a row may sit
here forever, which is the honest state for something nobody can answer." A future "after N failed
probes, mark it failed anyway" would reintroduce exactly the optimism this decision exists to refuse.

### 3. The state machine binds the *row*, not the caller's in-memory copy — `PublicationManager::transition()` is a compare-and-swap

This is the fix the B3 review found necessary, and it is the load-bearing decision in this ADR: every
other guarantee below assumes it holds.

**What was wrong.** `transition()` used to read `$publication->status` **out of memory**, check the
requested edge against that value, and then write **unconditionally**. Holding a stale copy of a
publication is not an edge case in this module — it is the *normal shape of the work*: the sweep holds
the row it claimed while a worker, in another process, concludes the publish from its own copy minutes
later. The review reconstructed three concrete interleavings that reach a duplicate or a corrupted
record **without taking a single illegal edge on paper**:

- The due sweep claims a row, dispatches the job, and the job **publishes successfully** — but the
  queue write that follows (`dispatch()` returning, or a driver quirk) throws. The sweep's own `catch`
  concludes the row from the stale copy it claimed, writing `failed` — with the **live `remote_id` of a
  now-public post still on the row**, and the product then offers a "Schedule again" button on it. That
  is the second public artifact.
- The identical interleave against a row the job had **parked** (not published) degrades
  `needs_reconcile` to `failed` — Fence 1 breached by a component that never looked at the row's real
  state, because `failed` asserts absence nobody established.
- A copy that still remembers `publishing` overwrites a row that has since reached `published` — a
  status the table calls **terminal** — with **no exception raised anywhere**, because `publishing →
  failed` is a perfectly legal edge for the status the stale caller *believed* it held.

**The fix.** `transition()` now writes `UPDATE publications SET … WHERE id = ? AND status = $from`.
Postgres locks the row for the `UPDATE`, so of two concurrent callers exactly one matches and the other
affects zero rows. Zero rows means the row moved between the caller's read and its write, and the caller
is **told** — via a new refusal reason, `PublicationTransitionRefused::LOST_RACE`
(`publication_transition_lost_race`), constructed with the status **read back from the database after
the refusal** rather than the caller's stale belief. `lost_race` is deliberately its own reason and not
folded into `not_allowed`: reporting a race as `not_allowed` would print a sentence that is *false* — "a
publication cannot go from needs_reconcile to failed" names an edge the table plainly contains. What
went wrong is not the edge; somebody else took it first.

**What the CAS does and does not guarantee**, stated because both halves are load-bearing:

- It **does** guarantee that a status write only lands on a row still in the state the caller decided
  from — two processes concluding one publication produce exactly one conclusion and one refusal,
  whichever order they arrive in.
- It **does not** order the work. It cannot make the *right* process win — if a reaper and a live worker
  both try to conclude a publication, the CAS guarantees only that one of them does. Ordering is what
  Decision 7's timeout inequality (`publish_timeout < retry_after << stale_after`) is for, and it stays
  load-bearing rather than belt-and-braces.
- It **does not fire Eloquent model events.** `transition()` is a query-builder `UPDATE`, not `save()`,
  so `saving`/`updating`/`updated` never dispatch for a status change. Nothing observes `Publication`
  today; an observer added later will silently miss every transition unless it is wired into
  `transition()` itself rather than relied upon by convention.
- It **does not** make a lost race an error for most callers. It is the *ordinary* outcome for the
  sweep's `catch`, the reaper's `catch`, the job's `failed()` hook, and the connection manager's
  hold/release loops — each logs it at `info` and carries on. It renders as an HTTP `422` for exactly
  one caller that cannot treat it as ordinary: a person who pressed a button on a screen that had gone
  stale.

**Why `claim()` and `claimDue()` stay two methods rather than one.** Both are now built on the same
mechanism — both put the current status in the `WHERE` clause, and `attempts`/`last_attempt_at` are
stamped only by a claim, never by `transition()` on its own. What they do not share is *voice*: `claim()`
**throws** when it loses, because its callers (the synchronous `publish()` path, tests) believed they
held the row alone and a silent `null` would be discarded incorrectly. `claimDue()` **answers `null`**
when it loses, because for the sweep losing the race to another sweep pass is the mechanism *working*,
not an error. Collapsing the two into one method would have forced one of those callers to lie about the
other's situation — an error-shaped return for a routine race, or a routine-shaped return a caller
mistakes for success.

### 4. The atomic claim is the *only* way a publish starts, and it is what makes both fences hold structurally

`DispatchDuePublicationsCommand` (`publishing:dispatch-due`, scheduled every minute) is the **only**
path that starts a publish — no observer, no model event, no per-row scheduler entry. It selects
`status = scheduled AND scheduled_at <= now()` and, for each row, calls
`PublicationManager::claimDue()`: one conditional `UPDATE … WHERE status = 'scheduled'`.

Because `needs_reconcile` and `blocked` are not `scheduled`, the `WHERE` clause **cannot select those
rows at all** — the sweep inherits both B1 fences without having to know they exist, rather than
declining to claim them as a matter of application logic that could later be coded around. `allows()`
above the statement is the second, structural half: if the edge is ever removed from
`PublicationManager::TRANSITIONS`, claiming stops working loudly (a thrown `PublicationTransitionRefused`
for an edge the machine no longer contains) instead of silently writing a status the machine has
disowned.

`withoutOverlapping` on the scheduler entry is the *first*, weaker concurrency guard — it bounds the
command against itself, on one host. It says nothing about a second host, a manual `artisan` invocation,
or a pass that outlived its lock. The atomic claim is the guard that holds against all three, which is
why `PublishingQueueTest::test_two_overlapping_sweeps_dispatch_one_job_for_one_publication` runs the
command twice in a row rather than trusting the lock.

### 5. No transactions anywhere in the queue — a deliberate absence, not an oversight

Every write `PublicationQueueService`, `PublicationPublisher` and `PublicationManager` make is a single
row: one conditional claim, one park, one conclusion. There is nothing to make atomic by wrapping it, and
in the one place a transaction looks tempting — claim, then dispatch — it would be actively wrong twice:

- It would hold a row lock across a queue write (a network call, under most drivers).
- Under a queue driver that runs jobs inline (`sync`, and effectively the test suite), wrapping
  claim-then-dispatch in a transaction would nest the **entire publish** — including the two-phase
  publish-and-persist-the-handle sequence `PublicationPublisher` documents as deliberately
  transaction-free — inside a transaction the module explicitly forbids. A rolled-back phase-1 handle
  after a phase-2 failure would leave a container that exists on the platform with **no record of it
  anywhere** in this application, which is a worse failure than the one a transaction would have been
  trying to prevent.

The claim-then-dispatch gap is instead **admitted and answered** rather than closed by wrapping it: if
the queue write fails immediately after a successful claim (`PublicationQueueService::dispatchOne()`),
the row is marked `failed` with `FAILURE_DISPATCH = 'dispatch_failed'` — deliberately `failed` and not
`needs_reconcile`, because no platform was touched and "we know nothing was created" is a true statement
here. That write goes through the same CAS as everything else, so if the job *had* actually run (a
`sync` queue, or a redelivery race) the `failed` write loses the race, the caller catches
`PublicationTransitionRefused`, and the outcome is counted as `claimed` — a conclusion reached by
something else is still a worker having gotten the row, which is what `claimed` records.

### 6. Two cooldowns, at two different layers, because an automatic probe and a person's question are different acts

A **retry** *writes* to a platform and may create a second artifact — forbidden automatically, per
Decisions 1–2. A **reconciliation probe** *reads*, and the worst it can do is fail to answer — so the
doctrine that forbids the first says nothing against the second, and `needs_reconcile`'s lack of an
attempt limit (Decision 2) makes an unthrottled probe a real cost: "forever" times "every five minutes"
is 288 questions a day, per stuck row, against a platform's rate limit shared by every other publication
that still works.

- **The automatic cooldown** (`PublicationQueueService::mayProbe()`) allows at most one probe per
  `publishing.queue.reconcile_cooldown` seconds (default `3600` — one hour) **per publication**, enforced
  with `Cache::add()` under key `publishing:reconcile-probe:{id}` so two overlapping sweep passes cannot
  both decide to ask. It lives in the **cache, not a column**, on purpose: losing the cache entry costs
  one extra read-only question, which is the correct direction for this particular mechanism to fail in —
  a column would mean this read-only pass writing to a row whose every other write belongs to the
  Manager.
- **The manual endpoint** (`POST /publishing/publications/{id}/reconcile`) **ignores that cooldown
  entirely** — a person asking is not a sweep, and their question must never be silently answered by "a
  machine asked recently." "Not a sweep" is not "unlimited," though: the route carries its own
  `throttle:6,1,publishing-reconcile` — six requests a minute, in its **own** bucket with an explicit key
  prefix (without the third argument, every throttled route an authenticated user touches would share one
  counter). The platform's rate limit is **per-application**, not per-user, so one member holding the
  button down would degrade publishing for every workspace on the installation — the throttle exists to
  bound that blast radius, not to be a UX nicety.

### 7. Expiring scheduler locks on the publishing entries — a deliberate deviation from the file's bare convention

`routes/console.php`'s other `Schedule::command(...)->everyFiveMinutes()->withoutOverlapping()` entries
take no argument, which leaves Laravel's default 24-hour mutex. The three publishing entries deviate on
purpose:

```php
Schedule::command('publishing:refresh-tokens')->hourly()->withoutOverlapping(30);
Schedule::command('publishing:dispatch-due')->everyMinute()->withoutOverlapping(5);
Schedule::command('publishing:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
```

A `schedule:run` process killed mid-command — a deploy, an OOM — would otherwise leave the 24-hour
default mutex held, and every subsequent scheduler tick would see the command "still running" and skip
it. For every other reaper in the product that is untidy; for these three it means **all publishing
silently stops for up to a day**, or the token-renewal margin `publishing.tokens.refresh_lead` is meant
to protect gets quietly eaten. The expiry is sized to each command's own cadence, and an overlap the
expiry *permits* (two instances running briefly at once) is harmless by construction — every row-level
write is either a conditional claim (Decision 3/4) or a read-only probe (Decision 6).
`PublishingQueueTest::test_the_publishing_sweeps_are_on_the_scheduler_with_expiring_locks` pins both the
cadence and the expiry minutes for all three commands by name, deliberately outside
`ScheduledMaintenanceCommandsTest`'s generic reap/sweep/prune-name pin — these three are the product's
engine, not housekeeping, and a deleted scheduler line produces no failing command test and no error, only
publishing quietly stopping.

### 8. Noted, not resolved here: `fallback_locale` made a first version of the `lost_race` translation pin vacuous

This installation's `.env` sets `APP_FALLBACK_LOCALE=pl` (the `config/app.php` default is `'en'`). While
pinning that the new `lost_race` reason (Decision 3) has a sentence in `publishing.transitions` for both
languages, the review found that a **first version** of that specific assertion
(`PublishingConnectionVocabularyTest::test_every_transition_refusal_reason_has_a_sentence_in_both_catalogs`)
constructed `PublicationTransitionRefused` instances and asserted their rendered **messages** looked like
prose — and stayed green under a mutation that deleted the `lost_race` key from the **English** catalog
entirely, because a missing key falls through to `fallback_locale` and silently renders the correct
**Polish** sentence instead of the raw key. An English reader would have seen grammatical Polish and no
test would have said so.

Unlike that first version, the sibling tests for the two catalogs B2 already pinned
(`connection_failures`, `oauth_failures`) were never exposed to this: they compare the **set of keys** in
each language file directly against a constant list (`PlatformConnectionManager::FAILURE_CODES`,
`OAuthCallbackReason::ALL`), which cannot be fooled by a working fallback because it never renders a
string at all. The fix applied here was to bring the `transitions` catalog's test up to the same shape —
key-set comparison against the exception's own reason vocabulary, counted against the number of class
constants so a sixth reason cannot be added to one catalog and forgotten in the other.

This ADR does not extend that fix to the rest of the application, and does not take a position on whether
`fallback_locale = pl` is the right default. Both are flagged as a real risk — any test elsewhere in the
codebase that renders a string and asserts "not the raw key" rather than comparing key sets is exposed to
the same blind spot — and left as a separate thread for whoever picks it up next.

---

## Consequences

- **A publication can sit in `needs_reconcile` indefinitely with no automatic escalation.** This is the
  accepted cost of Decisions 1–2, not a gap: being wrong towards `needs_reconcile` costs a person looking
  at a row that turned out fine; being wrong the other way authorizes a second public post. The `failed`/
  `publish_worker_failed`/`reaper_stale`/`dispatch_failed` failure codes on the row, and
  `can_be_reconciled` on the resource, are what make that state actionable rather than merely inert — see
  `docs/backend/publishing-api.md`.
- **Every future caller of `PublicationManager` inherits the CAS.** Nobody outside this module needs to
  re-derive the race handling — the same `WHERE status = $from` mechanism protects a hypothetical B6
  workflow-triggered publish exactly as it protects the sweep, provided the caller routes through the
  Manager (enforced structurally by `status` being absent from `$fillable`, and behaviourally by
  `PublishingStateMachineTest` scanning the module's own file bytes for a `status` write outside it).
- **An `Eloquent` observer on `Publication`, if one is ever added, must be wired inside
  `PublicationManager::transition()`/`claimDue()` rather than relied upon generically** — see Decision 3.
  This is a trap for a future change, named here so it is not rediscovered the hard way.
- **Multi-host deployments are safe by the same mechanism that makes overlapping local runs safe.** The
  CAS and the atomic claim do not distinguish "a second pass on this host" from "a pass on a different
  host entirely" — there was never a host-local assumption to relax.
- **The tenant-database twin of the queue's own test (`tests/Feature/PublishingTenantDatabaseTest.php`)
  was written in this batch but not run** — it issues `CREATE DATABASE`/`DROP DATABASE` and is gated
  behind `TENANT_DB_TESTS=1`, which requires the owner's approval per the project's safety rules. It is
  the one mode where mis-routing the sweep's tenant context costs a *second* post (writing to the wrong
  workspace's database entirely), so it should be run by hand — `TENANT_DB_TESTS=1 php artisan test
  --filter=PublishingTenantDatabase` — before the next change to the composer's queued path or to
  `SweepsEveryWorkspace`.
