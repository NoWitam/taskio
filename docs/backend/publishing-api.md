# Backend API: Publishing module

Module: `app/modules/Publishing/`
Auth: every endpoint under `/api/publishing` requires `auth:sanctum` **and** the
`X-Workspace-Id` header (`RequireWorkspace` — a 400 before anything is looked up). The one
exception in this module is `GET /oauth/{platform}/callback`, a `web` route with **no**
authentication and **no** workspace header at all — see "The OAuth flow" below for why, and
`docs/decisions/ADR-0054-platform-connections-oauth.md` for the full reasoning.
Tenant scope: `Publication`, `PlatformConnection` and `PublicationAttempt` all use
`TenantAware` — a foreign row 404s at route-model binding, and `platform_connections` is a
**tenant table**: an own-database workspace's connections (and their live tokens) live in that
workspace's own database, never the shared one.

Covers R4 **B1** (the publication state machine, the `dry_run` adapter, the fifth Calendar
source), **B2** (platform connections and the OAuth handshake) and **B3** (the queue: a
due-sweep, the atomic claim, a stale-`publishing` reaper, and reconciliation — both automatic
and manual), as committed on `module/publishing`. This page documents **only what is
implemented and committed**. The real Facebook/Instagram/YouTube publish adapters (today only
`dry_run` publishes anything, end to end), media attachment (B5) and publishing metrics (B9)
are **not yet built**; see "Planned (B4+)" at the end.

---

## Concepts

### The publication state machine

`App\Modules\Publishing\Managers\PublicationManager` is the **only** writer of
`publications.status` in the module — enforced by `PublishingStateMachineTest`, which scans
the module's own file bytes for a `status` assignment outside this class. The table below is
transcribed directly from `PublicationManager::TRANSITIONS`:

| From | To (every edge the machine allows) |
|---|---|
| `draft` | `scheduled` |
| `scheduled` | `publishing`, `draft`, `blocked` |
| `publishing` | `published`, `failed`, `needs_reconcile` |
| `published` | *(none — terminal)* |
| `failed` | `publishing`, `scheduled`, `draft`, `blocked` |
| `needs_reconcile` | `published`, `failed` |
| `blocked` | `scheduled`, `draft` |

A move to the **same** status is not in the table and is refused — re-arming a `scheduled`
publication for a different minute is an edit of `scheduled_at`, not a transition, and letting
it through here would make "how many times has this been armed" unanswerable from the
transition log alone.

**Two fences are facts of this table, not checks bolted onto it:**

- **`needs_reconcile` has no edge to `publishing`, ever.** A publication in this state may
  *already be a post* — the worker died between creating the intermediate artifact and
  publishing it, the platform timed out after accepting, the connection dropped mid-call —
  and there is no way to tell from the outside. An automatic retry here is not a retry, it is
  a coin flip whose losing side is a second public artifact nothing in this application can
  delete. The only two edges out are conclusions of a reconciliation: `markPublished()` requires
  a `RemoteRef`, which only a successful publish or `PlatformAdapter::findExisting()` can
  produce, and `markFailed()` from this state means the platform was asked and proved nothing
  exists — which is what makes the ordinary `failed → publishing` retry safe again.
- **`blocked` has no edge to `publishing` either.** It exists so a broken connection *holds*
  its queue instead of turning every scheduled item into a failure the moment each one comes
  due — one connection revoked at 08:00 would otherwise mean twelve failures at 09:00, twelve
  alerts, twelve rate-limited calls, one cause. `blocked` is entered from `scheduled` or
  `failed` and left only by re-arming (a person fixed the connection) or by disarming back to
  `draft` — both human decisions.

**Since B3, `PublicationManager::transition()` is a compare-and-swap, not a read-then-write.**
Every write carries `WHERE id = ? AND status = <the status the caller decided from>`, so of two
callers holding two copies of the same row, exactly one's write lands and the other affects
zero rows — and is **told**, via a new refusal reason, `lost_race`
(`publication_transition_lost_race`), built from the status read back from the database after
the refusal rather than from the caller's stale belief. This is what makes the rest of B3 safe:
the sweep, the worker and the reaper routinely hold *different, simultaneously-valid* copies of
the same row (the sweep keeps the row it claimed while a worker publishes from its own copy
minutes later, in another process), and a read-then-write transition would let the older copy
silently overwrite a conclusion reached with more information — up to and including overwriting
a **terminal** `published` row with `failed`, with no exception raised anywhere, because
`publishing → failed` is a legal edge for the status the stale caller *believed* it held. See
`docs/decisions/ADR-0055-publishing-queue-doctrine.md` (Decision 3) for the full defect class
and the fix. What the CAS does **not** do: order the work (it guarantees *one* conclusion wins,
not the *right* one — that is what the queue's timeout ordering is for, see below) or fire
Eloquent model events (it is a builder `UPDATE`, not `save()`).

**Two ways to claim, kept deliberately separate.** `claim()` (`scheduled|failed → publishing`)
**throws** `PublicationTransitionRefused` when it loses the race — for a caller (the
synchronous `publish()` path, tests) that believed it held the row alone, and needs to be told
either way. `claimDue()` (B3, `scheduled → publishing` only) **returns `null`** when it loses —
for the due-sweep, where losing to another sweep pass is the mechanism working, not an error.

**Every transition a person or the machinery can trigger, and how.** Arming is over HTTP
(`POST …/publications/{id}/schedule`). Claiming, publishing and classifying the outcome are
driven end to end by `App\Modules\Publishing\Services\PublicationPublisher`, called from the
due-sweep (`publishing:dispatch-due`) and its worker job — see "Queue, reaper and
reconciliation (B3)" below. Reconciliation runs automatically on a schedule and is also
reachable by a person at `POST …/publications/{id}/reconcile`. There is still no `/publish`
endpoint and no retry endpoint: `PublicationController`'s own docblock explains why one was
never added — once reconciliation exists, the retry a person wants from `failed` is the arming
endpoint they already have.

### Data model

**`publications`** (central; the tenant mirror at
`database/migrations/tenant/0001_01_01_000078_create_publications_table.php` omits
`workspace_id` — one tenant database is one workspace):

| Column | Notes |
|---|---|
| `id`, `workspace_id` | uuid |
| `title`, `body` | `body` is `text` — a YouTube description runs to 5000 characters |
| `platform` | `PublishingPlatform` string enum: `youtube`, `instagram`, `facebook`, `dry_run` |
| `platform_connection_id` | nullable; **no FK until B2's own migration added one** (see below); null for every `dry_run` publication, which has no account behind it |
| `status` | `PublicationStatus`; written only by `PublicationManager` |
| `scheduled_at` | an **instant** (UTC), never a day; `null` = not armed. Resolved from a zone-less string against the **workspace's** clock via the same `CalendarInstantResolver` the Calendar module uses |
| `published_at` | when it actually went out — **not** a copy of `scheduled_at`; can differ by hours after a reconciliation |
| `media` | jsonb array of **Disk file uuids**, in the order the platform receives them — pointers, never ownership, never dereferenced by this module. **No foreign key, on purpose**: a trashed Disk file leaves a dangling id, which fails loudly at publish time with a reason to show, rather than silently rewriting what a scheduled post is about |
| `options` | jsonb, per-destination extras (a YouTube privacy setting, tags) — schema-less, adapter-owned |
| `remote_id`, `remote_draft_id`, `remote_url` | platform-shaped strings, not uuids. `remote_draft_id` is the phase-1 handle (an Instagram container, a YouTube upload session) and is **never returned by the API** — see `PublicationResource` below |
| `attempts`, `last_attempt_at` | bumped by `PublicationManager::claim()` |
| `failure_code`, `failure_context` | a stable machine code plus a small structured aside — **never** platform prose, **never** anything derived from a credential |
| `creator_id`/`creator_type` | polymorphic creator (ADR-0015) |

**Uniqueness added once `platform_connections` existed**
(`2026_09_06_000003_add_platform_connection_constraints_to_publications_table.php`):
`platform_connection_id` gets a `restrictOnDelete()` foreign key (never `cascade`, never
`nullOnDelete` — see ADR-0054 Decision 5), and `(platform_connection_id, remote_id)` is unique
— per connection, never globally, because the same platform identifier on two different
connections is two different artifacts.

**`platform_connections`** (a **tenant table** — central schema at
`2026_09_06_000002_create_platform_connections_table.php`, own-database mirror at
`database/migrations/tenant/0001_01_01_000080_create_platform_connections_table.php`, column-
identical minus `workspace_id`):

| Column | Notes |
|---|---|
| `platform` | never `dry_run` — that destination has no account to connect |
| `external_account_id`, `account_name` | the platform's own identifier and display name |
| `access_token`, `refresh_token` | `text`, both cast `'encrypted'` — the one existing precedent in the codebase, `workspaces.db_password`. `refresh_token` is normally `null` for a Meta connection, which issues none at all |
| `scopes` | jsonb — what was **granted**, not what was requested; the two differ when a user unticks a permission on the consent screen |
| `expires_at` | nullable; `null` means "the platform did not say," treated as *unknown*, never as *already expired* |
| `status` | `PlatformConnectionStatus`: `active`, `needs_reauth`, `revoked` — written only by `PlatformConnectionManager` |
| `failure_code` | one of `PlatformConnectionManager::FAILURE_CODES` (`refresh_failed`, `refresh_unsupported`, `credentials_unreadable`, `disconnected_by_user`) |
| `creator_id`/`creator_type` | polymorphic creator; a connection is attributed from the **signed OAuth state**, never `$request->user()` |
| `deleted_at` | a disconnect is a **soft** delete — the row survives so publications that already went out keep resolving to the account they went out on |

Unique on `(workspace_id, platform, external_account_id)`, surviving a soft delete
(`withTrashed()` on reconnect) so re-authorizing the same channel repairs the existing row
instead of creating a second, live-token duplicate.

**`publication_attempts`** — append-only (`$timestamps = false`, `created_at` stamped
manually, no `updated_at`, no soft delete). One row per call to a platform:
`phase` (`PublicationAttemptPhase`: `draft`, `publish`, `reconcile`), `succeeded` (boolean),
`attempt` (which numbered attempt this belongs to), `remote_draft_id`/`remote_id`, `request`
(what would have been sent — caption, media **ids**, options; **never** a credential), and the
same `failure_code`/`failure_context` pair as `publications`. **No foreign key to
`publications`** — the log deliberately outlives its subject, because a purged publication's
attempt trail is what remains to explain a post still sitting on somebody's timeline. Today
only `DryRunPlatformAdapter` writes these rows; the real per-platform adapters (B4+) inherit
the same shape and the same "never a credential" rule from day one.

---

## Queue, reaper and reconciliation (B3)

The doctrine behind every decision in this section — never retry an ambiguous outcome, the
reaper only parks, the state machine binds the row rather than a caller's copy — is written up
in full in `docs/decisions/ADR-0055-publishing-queue-doctrine.md`. This section is the
implemented shape: which commands run, on what cadence, and the config that tunes them.

### The two scheduled commands

Both run against the shared database and then, in turn, every own-database workspace
(`App\Modules\Publishing\Console\Concerns\SweepsEveryWorkspace`, the same shape
`TokenRefresher`/`RefreshPlatformTokensCommand` already used) — a broken tenant is logged and
skipped, it never stops the pass for the rest.

| Command | Cadence | Overlap lock | What it does |
|---|---|---|---|
| `publishing:dispatch-due` | every minute | 5 min | The **only** path that starts a publish. Selects `status = scheduled AND scheduled_at <= now()`, claims each with `PublicationManager::claimDue()` (atomic `scheduled → publishing`), and dispatches `PublishPublicationJob`. |
| `publishing:reconcile` | every 5 minutes | 10 min | Two passes in one command: (1) **reap** — park rows stranded in `publishing` past `publishing.queue.stale_after` into `needs_reconcile`; (2) **probe** — ask the platform (`PlatformAdapter::findExisting()`) about every row currently in `needs_reconcile`, subject to the per-publication cooldown below. Reaping runs first *in the same pass* so the commonest stranding (a worker killed right after the platform accepted the post) can resolve itself, into `published`, without anybody looking at a screen. |

Both entries in `routes/console.php` set an **expiring** overlap lock
(`withoutOverlapping(5)`/`withoutOverlapping(10)`) instead of the file's bare
`withoutOverlapping()` convention (a 24-hour default mutex) — a `schedule:run` killed mid-command
would otherwise leave the mutex held and silently stop all publishing for up to a day. See ADR-0055
Decision 7. `publishing:refresh-tokens` (B2, hourly) carries the same treatment
(`withoutOverlapping(30)`). All three cadences and lock durations are pinned by name in
`PublishingQueueTest::test_the_publishing_sweeps_are_on_the_scheduler_with_expiring_locks`, outside
the app-wide reap/sweep/prune-name pin `ScheduledMaintenanceCommandsTest` uses — these are the
product's engine, not housekeeping, so a deleted scheduler line produces no failing test on its own.

Both `withoutOverlapping` locks are the **first**, weaker concurrency guard — they bound a
command against itself, on one host. The real guard against a second host or a manual
invocation is the per-row atomic claim (`claimDue()`) and the compare-and-swap every other
write goes through; see ADR-0055 Decisions 3–4.

### `PublishPublicationJob` — the one asynchronous worker

Dispatched already-claimed (`scheduled → publishing` happened in the sweep, not in the job), so
the job works from an id and a workspace id rather than a serialized model — the claim, not the
payload, is the proof it may publish. `tries = 1`, **permanently** (ADR-0055 Decision 1) — there
is no backoff property and no `retryUntil()`, both asserted absent by
`PublishingQueueTest::test_the_publish_job_never_retries`. `WithoutOverlapping` per publication
id, with `dontRelease()`: a duplicate delivery is **dropped**, never released back onto the
queue, because by the time the lock would free, the publication may already be published and a
released job would be a second attempt at an artifact that already exists.

The failure hook (`failed()`) parks the row in `needs_reconcile` (`publish_worker_failed`) —
**never** `failed` — because a process that has just died cannot assert "nothing was created."
It is guarded by the same status check every other B3 conclusion is: only a row still sitting in
`publishing` is this delivery's to conclude.

### The timeout invariant

One config section, `publishing.queue`, and one inequality every value in it is ordered by:

```
publish_timeout  <  the queue connection's retry_after  <<  stale_after
```

| Key | Config path | Default | What it bounds |
|---|---|---|---|
| `publish_timeout` | `publishing.queue.publish_timeout` | `60` s | The job's own `SIGALRM`. Must stay **below** the queue connection's `retry_after` (`90` s by default, `config/queue.php`, `DB_QUEUE_RETRY_AFTER`). Above it, the *same* payload is redelivered while the original is still talking to a platform; under `tries = 1` the redelivery is failed **before any middleware runs**, so its `failed()` hook fires against a live publish. Nothing is corrupted when that happens (the CAS in ADR-0055 Decision 3 guarantees exactly one conclusion lands), but keeping the alarm inside the redelivery window is what keeps the *better-informed* conclusion — the live publish's own — the one that usually wins. |
| `dispatch_batch` | `publishing.queue.dispatch_batch` | `200` | Ceiling on how many due publications **one pass** of `publishing:dispatch-due` claims, per database. A bound on work, not correctness — an overflow is published a minute late, not skipped. |
| `stale_after` | `publishing.queue.stale_after` | `900` s (15 min) | How long a row may sit in `publishing` before the reaper concludes its claimant is gone. Deliberately **many times** `publish_timeout` — the reaper's subject is the death a timeout could not catch (`SIGKILL`, OOM, a `queue:restart` mid-call), and reaping a merely-slow publish would park a row whose platform call is still in flight. A `null` `last_attempt_at` is treated as stale (a row from before this mechanism existed). |
| `reap_batch` | `publishing.queue.reap_batch` | `200` | Ceiling on how many stranded rows one reaper pass parks, per database, ordered **oldest claim first** (nulls first) so an overflow defers the freshest — and most likely still-alive — strandings. |
| `reconcile_batch` | `publishing.queue.reconcile_batch` | `100` | How many `needs_reconcile` rows one automatic pass will probe, per database, ordered longest-waiting first. |
| `reconcile_cooldown` | `publishing.queue.reconcile_cooldown` | `3600` s (1 h) | Shortest interval between two **automatic** probes of the same publication — kept in the cache (`publishing:reconcile-probe:{id}`), not a column; see ADR-0055 Decision 6. Ignored entirely by the manual endpoint below, which has its own throttle instead. |

Every batch key is a ceiling on **work**, not on correctness: rows left over from a full batch
stay selectable and are picked up by the next pass. Raising `PublishPublicationJob::$tries` above
`1`, or raising `publish_timeout` above the connection's `retry_after`, are exactly the two
changes ADR-0055 exists to warn the next reader away from — both look like reasonable responses
to a flaky platform and both quietly reopen the coin flip the whole module refuses.

See "`POST /publishing/publications/{publication}/reconcile`" under Endpoints below for the
manual side of reconciliation.

---

## Endpoints

All under `/api/publishing`, behind `auth:sanctum` + `RequireWorkspace` (`app/modules/Publishing/routes/api.php`).

### `GET /publishing/counts`

Per-status counts for the module's tabs and its navigation badge — answers for **every**
status at once, ignoring any `status` filter (every other list filter still applies).

```json
{
  "data": {
    "counts": {
      "draft": 3, "scheduled": 5, "publishing": 0, "published": 12,
      "failed": 1, "needs_reconcile": 0, "blocked": 2
    },
    "total": 23,
    "needs_attention": 3
  }
}
```

`total` is **every** publication (unlike the Tasks counts endpoint, which excludes archive/
trash) — a published publication is the point of this module, not an archive of it.
`needs_attention` sums `failed` + `needs_reconcile` + `blocked`
(`PublicationStatus::needsAttention()`), computed server-side so a client never maintains its
own copy of that list.

### `GET /publishing/publications`

Cursor-paginated list, **newest-created first** — deliberately not ordered by `scheduled_at`,
which is nullable (every draft) and unsafe for cursor pagination (a null would silently vanish
from page two).

| Query param | Notes |
|---|---|
| `status` | one `PublicationStatus` value |
| `platform[]` | restrict to these platforms |
| `search` | matches `title`/`body`, literal (not a wildcard pattern) |
| `scheduled_from`, `scheduled_to` | bounds on `scheduled_at` |
| `per_page` | default 25 |

### `POST /publishing/publications`

Creates a **draft** — setting `scheduled_at` on create does **not** arm it; a publication with
a moment attached is still a draft until it is explicitly scheduled (see below). `status`,
`remote_id`, `remote_draft_id`, `attempts` and `published_at` are `prohibited`: a client
sending one of these believes it is setting something, and a silent drop would leave it
correct-looking and wrong.

```http
POST /api/publishing/publications
X-Workspace-Id: 3f8e...

{
  "title": "Autumn launch teaser",
  "body": "Something new is coming.",
  "platform": "youtube",
  "platform_connection_id": "9c21...",
  "scheduled_at": "2026-09-10 09:00",
  "media": ["a1b2...-file-uuid"],
  "options": { "privacy": "unlisted" }
}
```

`scheduled_at` with **no timezone** is read on the **workspace's** clock (the same
`CalendarInstantResolver` the Calendar module uses); a value carrying an explicit offset or
zone is taken exactly as given. `platform_connection_id`, when present, must name a connection
in this workspace that serves the **same** `platform` and is currently `usable()`
(`status = active`) — checked in `StorePublicationRequest`, all three failures reported under
one message (`connection_unusable`) so a client never learns whether a rejected id belongs to
somebody else's workspace. `media` is an ordered list of Disk file uuids (max 10, distinct),
stored verbatim and **never checked for existence** — a file present at draft time can be
trashed before the scheduled minute regardless, so the only check that means anything happens
at publish time, in the adapter.

Response `201`:

```json
{
  "data": {
    "id": "e7a1...",
    "title": "Autumn launch teaser",
    "body": "Something new is coming.",
    "platform": "youtube",
    "platform_label": "YouTube",
    "publishes_publicly": true,
    "platform_connection_id": "9c21...",
    "status": "draft",
    "status_label": "Draft",
    "status_tone": null,
    "needs_attention": false,
    "scheduled_at": "2026-09-10T07:00:00.000000Z",
    "published_at": null,
    "media": ["a1b2...-file-uuid"],
    "options": { "privacy": "unlisted" },
    "remote_id": null,
    "remote_url": null,
    "attempts": 0,
    "last_attempt_at": null,
    "failure_code": null,
    "failure_context": null,
    "creator": { "type": "user", "id": "...", "name": "..." },
    "is_owner": true,
    "can_be_edited": true,
    "can_be_deleted": true,
    "can_be_scheduled": true,
    "can_be_reconciled": false,
    "created_at": "2026-09-06T12:00:00.000000Z",
    "updated_at": "2026-09-06T12:00:00.000000Z"
  }
}
```

`remote_draft_id` is deliberately **absent** from every publication response — it is internal
resume state, and publishing it would invite a client to send it back, which is the one value
that must never arrive from outside (a resume pointed at a container somebody else named is a
post nobody authored). Branch on `status`/`can_be_*`, never on `status_label`/`status_tone`
prose — the same house rule every module's resources follow.

**`can_be_reconciled`** (B3) is `true` for exactly one status, `needs_reconcile`
(`PublicationStatus::isReconcilable()`), and is the flag a screen hangs the "check the
platform" action on. It is the **only** affordance offered for a publication in that state:
`can_be_edited` and `can_be_deleted` are both `false` there (editing would silently make the
record disagree with a post that may already exist; deleting would make a live artifact
permanently unattributable), and there is no retry flag at all — the only honest next act from
`needs_reconcile` is to go and look. A publication in `needs_reconcile` looks like this:

```json
{
  "status": "needs_reconcile",
  "status_label": "Needs checking",
  "status_tone": "danger",
  "needs_attention": true,
  "failure_code": "reaper_stale",
  "failure_context": { "stale_after_seconds": 900 },
  "can_be_edited": false,
  "can_be_deleted": false,
  "can_be_scheduled": false,
  "can_be_reconciled": true
}
```

### `GET /publishing/publications/{publication}`

`{publication}` is uuid-constrained at the route. A foreign-workspace id 404s at route-model
binding (`TenantAware`); a same-workspace id a non-member cannot see never resolves because
membership is proven upstream by `ResolveWorkspace`.

### `PUT /publishing/publications/{publication}`

Same payload and rules as `POST` (inherited, not restated — an update is a whole-row write, so
a rule missing here would let a second call store what the first refused). Refused with `403`
when `PublicationPolicy::update()` says no — composing **who** is asking (the creator, or the
active workspace's owner) with **where** the row is in its life
(`PublicationStatus::isEditable()`, which refuses `publishing`, `published` and
`needs_reconcile`).

### `POST /publishing/publications/{publication}/schedule`

The **one** transition exposed over HTTP: `draft|failed|blocked → scheduled`.

```http
POST /api/publishing/publications/e7a1.../schedule
X-Workspace-Id: 3f8e...

{ "scheduled_at": "2026-09-10 09:00" }
```

`scheduled_at` is **required** here (unlike on create) — an arming with no moment would have
to invent one, and "now" is the single most consequential default a form could pick. A moment
more than 60 seconds in the past is refused (`scheduled_in_the_past`) rather than silently
published immediately; a few seconds of tolerance covers ordinary client/server clock skew for
a genuine "publish now."

Illegal transitions answer `422`, in the module-wide refusal shape:

```json
{
  "code": "publication_terminal",
  "message": "This has already been published. It cannot be changed from here.",
  "context": { "from": "published", "to": "scheduled" }
}
```

`code` is one of `publication_reconcile_before_retry`, `publication_blocked_holds`,
`publication_terminal`, `publication_transition_not_allowed` or, since B3,
`publication_transition_lost_race` (`PublicationTransitionRefused`) — a client branches on
`code`, never on `message`.

| `code` | When | What it tells the caller |
|---|---|---|
| `publication_reconcile_before_retry` | `needs_reconcile → publishing` was attempted. There is no such edge and there will not be one. | Ask the platform first (`POST …/reconcile`); what it answers decides `published` or `failed`, and only from `failed` is a retry a legal move at all. |
| `publication_blocked_holds` | `blocked → publishing` was attempted. | The connection is held. Fix it and re-arm; publishing from `blocked` would fail once per scheduled item, all at once, all with the same cause. |
| `publication_terminal` | The row is `published`. | Nothing this application writes can recall it — the record does not get rewritten. |
| `publication_transition_not_allowed` | Any other edge the table does not contain. | — |
| `publication_transition_lost_race` (B3) | The edge **exists**, but the row moved between the caller's read and its write — the CAS in `PublicationManager::transition()`/`claimDue()` matched zero rows. | Nothing was written. `context.from` is the row's **real, current** status (read back from the database after the refusal), so the caller can decide again from the truth rather than from what it last read. Not a defect — see `docs/decisions/ADR-0055-publishing-queue-doctrine.md` Decision 3. |

### `POST /publishing/publications/{publication}/reconcile` (B3)

The manual half of reconciliation — the only way a person moves a row out of `needs_reconcile`
without waiting for the automatic probe's hourly cooldown (see "Queue, reaper and
reconciliation (B3)" above for the automatic side and the full doctrine in
`docs/decisions/ADR-0055-publishing-queue-doctrine.md`). No request body
(`ReconcilePublicationRequest` declares no `rules()`): the whole act is "go and look," and the
one input this endpoint could take — a remote id supplied by the client — is exactly the one
value that must never arrive from outside, since it would let a caller *assert* a post exists
rather than the platform proving it.

```http
POST /api/publishing/publications/e7a1.../reconcile
X-Workspace-Id: 3f8e...
```

Three outcomes, all `200`, distinguished by the response body's `status`/`remote_id`:

- **Found** — `PlatformAdapter::findExisting()` returned a ref: `status` becomes `published`,
  `remote_id`/`remote_url` are populated from the platform's answer.
- **Proven absent** — the platform was asked and confirmed nothing exists: `status` becomes
  `failed`, `failure_code: "reconciled_absent"`. From here `POST …/schedule` is a legal move again
  — the retry a person wants. This is also why B3 ships no separate `/retry` endpoint.
- **Could not be established** — the adapter itself threw (a timeout on the probe, a
  configuration error): the row is returned **unchanged**, still `needs_reconcile`. Nothing was
  learned, so nothing moves; there is no attempt limit or escalation.

Refused `403` when `PublicationPolicy::reconcile()` says no — composing **who** is asking (the
creator, or the active workspace's owner) with **where** the row is
(`PublicationStatus::isReconcilable()`, true for exactly `needs_reconcile`; see `can_be_reconciled`
above). Refused `422` (`publication_transition_lost_race`) if something else concluded the row
between this request's read and its write — a sweep's own probe, or another person's click.

**Throttled**, deliberately harder than the endpoint's own authorization would suggest:
`throttle:6,1,publishing-reconcile` — six requests a minute, in its own bucket (the explicit key
prefix matters: without it every throttled route an authenticated user touches shares one
counter). This endpoint skips the automatic probe's per-row cooldown on purpose ("a person
asking is not a sweep"), and every call spends the platform's rate limit, which is
**per-application**: one member holding the button down would degrade publishing for every
workspace on the installation.

### `DELETE /publishing/publications/{publication}`

Soft delete, `204`. Refused (`403`) only for `publishing` and `needs_reconcile`
(`PublicationStatus::isDeletable()`) — a `published` publication **may** be deleted, which
looks inconsistent beside editing until stated plainly: deleting hides our record and changes
nothing in the world (the post stays up), while editing a published row would make the record
actively lie about a post that still exists.

### `GET /publishing/connections`

Every connection in the workspace, newest first, **not paginated** (a workspace has a handful
of social accounts, not pages of them). `platform[]` filters. `access_token`/`refresh_token`
never appear — the field list is the security boundary, backed by a second, independent guard
(`$hidden` on the model).

```json
{
  "data": [
    {
      "id": "9c21...",
      "platform": "youtube",
      "platform_label": "YouTube",
      "external_account_id": "UCxxxxxxxx",
      "account_name": "Taskio Demo Channel",
      "status": "active",
      "status_label": "Connected",
      "status_tone": "success",
      "needs_attention": false,
      "can_publish": true,
      "scopes": ["https://www.googleapis.com/auth/youtube.upload"],
      "expires_at": "2026-09-06T13:00:00.000000Z",
      "last_refreshed_at": "2026-09-06T12:00:00.000000Z",
      "failure_code": null,
      "credentials_readable": true,
      "creator": { "type": "user", "id": "...", "name": "..." },
      "is_owner": true,
      "can_be_disconnected": true,
      "created_at": "2026-09-06T12:00:00.000000Z",
      "updated_at": "2026-09-06T12:00:00.000000Z"
    }
  ]
}
```

`credentials_readable` exists for exactly one incident: an `APP_KEY` rotation (or a database
restored beside a different application) makes every stored token unreadable at once, and this
is the flag that lets the connections screen still render — and explain itself — rather than
500ing on the one screen somebody would use to fix it.

### `POST /publishing/connections/{platform}/authorize`

Starts a handshake. `{platform}` is one of `youtube`, `instagram`, `facebook` (never
`dry_run`, which is rejected as `platform_not_connectable` — it has no account to connect).
A destination this installation has no client id/secret for yet answers
`platform_not_configured`. No request body — the redirect URI and the `state` are entirely
server-authored; a client cannot choose either.

```http
POST /api/publishing/connections/youtube/authorize
X-Workspace-Id: 3f8e...
```

```json
{ "data": { "authorize_url": "https://accounts.google.com/o/oauth2/v2/auth?...", "expires_in": 600 } }
```

The response also carries a `Set-Cookie: taskio_publishing_handshake=…` — `HttpOnly`,
`SameSite=Lax`, host-only — that the browser must still hold when the platform redirects back.
The caller (the SPA) navigates the browser itself to `authorize_url`; it must **not** be
followed as an XHR redirect, since a `fetch` follow drops the request headers a real handshake
needs from that point on. See "The OAuth flow" below.

### `DELETE /publishing/connections/{connection}`

Disconnects an account. `204`. This is **not** a plain delete: in one transaction,
`PlatformConnectionManager::revoke()` also puts every `scheduled` publication on this
connection on hold (`blocked`, `connection_disconnected`) **keeping its armed moment**, and
soft-deletes the row so already-published publications keep resolving to the account they went
out on. Authorized to the creator or the active workspace's owner
(`PlatformConnectionPolicy::delete()`), not any member — disconnecting stops the *team's*
queue for that account, not merely "a record I made."

---

## The OAuth flow

There is no single "connect" endpoint; the handshake is two calls plus a browser redirect it
does not control, because Google/Meta consent screens are the middle step:

1. **`POST /api/publishing/connections/{platform}/authorize`** (authenticated, `X-Workspace-Id`
   present). `PlatformConnectionService::beginAuthorization()` mints a signed, single-use
   `state` — `{v, jti, u, w, p, iat, exp, bh}`, HMAC-signed under a key derived from `APP_KEY`
   — records the `jti` in the cache as unredeemed, and answers `{authorize_url, expires_in}`
   plus a `Set-Cookie` carrying a 32-byte random secret whose SHA-256 is the `bh` inside the
   signed `state`. Default TTL 600 seconds (`publishing.oauth.state_ttl`).
2. **The SPA navigates the window to `authorize_url`.** The user sees the platform's own
   consent screen and approves or declines.
3. **The platform redirects the browser to `GET /oauth/{platform}/callback`** — a `web` route,
   registered above the `/next{path?}` SPA catch-all, with **no** `auth:sanctum` and **no**
   `X-Workspace-Id`: this is a top-level navigation with neither a bearer token nor a workspace
   header attached. Either `?code=…&state=…` (success from the platform's side) or
   `?error=…` (declined or refused before a code was issued).
4. **`PlatformOAuthCallbackController`** verifies and *consumes* the `state`
   (`OAuthStateService::consume()` — signature, platform match, expiry, the browser-binding
   cookie, then the single-use ledger, in that order), re-checks that the named workspace is
   still `Ready` and the named user is still a member, activates the right tenant connection by
   hand (there is no `ResolveWorkspace` on this route), exchanges the code for tokens
   (`OAuthProvider::exchangeCode()`), fetches the account identity
   (`OAuthProvider::fetchAccount()`), and stores the connection
   (`PlatformConnectionManager::connect()` — an upsert keyed on
   `(platform, external_account_id)`, so re-authorizing the same channel repairs the existing
   row).
5. **Redirect back to the SPA**, at `publishing.oauth.return_path` (default
   `/next/publishing/connections` — as of this commit the frontend route for it does not yet
   exist; a real connect currently ends on the SPA's not-found screen having **succeeded**),
   with the outcome in the query string:

   - Success: `?connection=connected&platform=youtube`
   - Failure: `?connection=failed&platform=youtube&reason=<code>`

   Never a token, an account id, a display name or platform prose in the redirect — only a
   stable `reason` code the frontend translates.

### Callback `reason` codes

Since B3, every code a callback can put in the redirect is a named constant on
`App\Modules\Publishing\Support\OAuthCallbackReason`, and `OAuthCallbackReason::ALL` is the
single list both `PlatformOAuthCallbackController` and `lang/{en,pl}/publishing.php`'s
`oauth_failures` key are checked against — `PublishingConnectionVocabularyTest` asserts the two
sets of keys are identical, in **both** languages, and refuses any drift in either direction.
Before B3 only four of these fourteen had a translation while the controller could already
report all fourteen, so ten would have rendered as their own raw key on the one screen a person
lands on after a failed consent flow; that gap is closed and pinned by the test, not merely
patched by hand.

| `reason` | When |
|---|---|
| `unknown_platform` | The `{platform}` segment is not a recognized `PublishingPlatform` value. Unreachable in practice — the route already constrains the segment to the enum. |
| `access_denied` (or another platform-reported `error`, truncated to 64 chars via `OAuthCallbackReason::forPlatformError()`) | The user declined consent, or the platform refused before issuing a code. `access_denied` is the one both platforms document and the only platform-reported code with its own sentence. **Not mapped or validated against `ALL`**: any other raw platform `error` value (e.g. `server_error`) is passed through verbatim, truncated to 64 chars — deliberately, so the platform's own enumeration is not thrown away for a value this catalog has no sentence for yet. |
| `missing_code` | The platform redirected with neither `error` nor `code`. |
| `oauth_state_malformed` | The `state` string is not the shape this service mints (includes every state minted under the old `VERSION = 1` payload). |
| `oauth_state_bad_signature` | The payload's HMAC does not match — the strongest signal that somebody tried to forge one. |
| `oauth_state_expired` | The signed payload's own `exp` has passed. |
| `oauth_state_already_used` | The signature is good and current, but the single-use ledger has no entry — a replay, a back button, or a prefetching browser. |
| `oauth_state_platform_mismatch` | The state was minted for one destination and presented at another's callback URL. |
| `oauth_browser_mismatch` | The handshake cookie is missing or does not match the state's `bh` — a stolen state, a planted state, cookies disabled, or a link opened in a different browser/profile. See ADR-0054 Decision 3. |
| `workspace_unavailable` | The workspace named in the state no longer exists, is not `Ready` (e.g. still `provisioning`), or the user is no longer a member. All three answer identically so the response cannot be used to probe another workspace's state. |
| `token_exchange_failed` | The token endpoint rejected the authorization code (`OAuthExchangeFailed::EXCHANGE`). |
| `token_response_unusable` | The exchange returned `200` with no usable access token — includes Google's own refusal case, when a first-consent exchange came back with **no refresh token** (`GoogleOAuthProvider` treats that as unusable rather than storing an hour-lived connection). |
| `account_lookup_failed` | The token was obtained but the platform would not identify the account (no channel/page found, or the identity call itself failed). |
| `connection_failed` | Any other throwable, including the `QueryException` from two simultaneous first-time connections of the same never-seen account racing the unique index. Also the fallback `OAuthCallbackReason::forExchange()` returns for a token-endpoint code this catalog does not (yet) recognize. |

The success code, `connected`, and the generic `failed` header are both translated. Note this
table covers only the codes a **callback** can reach; `token_refresh_failed` and
`refresh_unsupported` belong to the separate token-renewal sweep (B2) and already have their
own sentences under `publishing.connection_failures` — see the data model section above.

---

## Planned (B4+)

Not implemented as of this commit; mentioned only so this page is not mistaken for the whole
roadmap. B1–B3 shipped the full lifecycle — state machine, connections/OAuth, and the queue
(due-sweep, worker, reaper, reconciliation, both automatic and manual) — all driving the
`dry_run` adapter end to end against its own attempt log. Still ahead:

- **Real `PlatformAdapter` implementations** for YouTube, Instagram and Facebook (B4). Every
  adapter inherits the two-phase contract (`createDraft()`/`publishDraft()`/`findExisting()`)
  `DryRunPlatformAdapter` already implements, the "never a credential in a log or an attempt
  row" rule, and the classification `PublicationPublisher` already applies
  (`PlatformRefused → failed`, anything else `→ needs_reconcile`) without any adapter having to
  reimplement it.
- **Media attachment (B5)** — `publications.media` already stores an ordered list of Disk file
  uuids and is never dereferenced by this module; an adapter that actually attaches files at
  publish time is future work.
- **Publishing metrics/insights (B9).**

None of these are designed on this page — see the module's own future ADRs when they land.
