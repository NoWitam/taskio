# Backend API: Bot (AI Character) module

Module: `app/modules/Bot/`
Auth: all endpoints require `auth:sanctum` + `X-Workspace-Id` header (resolved by `ResolveWorkspace` middleware).
Tenant scope: `TenantAware` trait — all queries are automatically scoped to the active workspace.

Covers B1–B6: CRUD + persona (B1–B2), polymorphic actor + one-shot execution (B2, superseded —
see below), interactive multi-turn execution (B4), the optional tool registry (B5), and the
5-module structure + knowledge module (B6).

---

## Concepts

A **Bot** is a workspace-scoped AI character (digital worker) with **5 modules**:

| Module          | Column(s)                    | Required | Notes                                             |
|------------------|-------------------------------|----------|----------------------------------------------------|
| Text             | `persona`, `style`, `dictionary`, `phrases`, `prohibitions` | Yes | Always shapes the AI voice. |
| Task-execution   | `task_execution` (`{enabled, tools}`) | No | Makes the bot an interactive task participant (B4) with optional registry tools (B5). |
| Knowledge        | `knowledge` (`{enabled, entries}`) | No | Entries injected into the execution context only when enabled (B6). |
| Visual           | `visual`                      | No       | Placeholder — read-only, no logic yet.             |
| Audio            | `audio`                       | No       | Placeholder — read-only, no logic yet. Renamed from `voice` in B6 (column rename; same null-placeholder semantics). |

The bot is not a one-shot task runner. Once assigned to a task it can execute (B4), it is an
**interactive participant**: it reads full task context (comments, form, approval history,
its own knowledge), acts through tools across possibly many turns, can ask a human a question
and wait for a reply, and resumes automatically when that reply arrives.

**BotStatus** values:

| Value      | Meaning                                     |
|------------|---------------------------------------------|
| `draft`    | Being built; not eligible to execute tasks. |
| `active`   | Fully operational; may execute tasks.       |
| `disabled` | Suspended; not eligible to execute tasks.   |

**BotActionType** values (audit log entries):

| Value               | Meaning                                                                 |
|---------------------|--------------------------------------------------------------------------|
| `task_started`       | A run began. Payload: `{run, trigger}` (B4 — see below).                |
| `form_filled`        | Bot submitted/updated answers to the task's attached form.              |
| `commented`          | Bot posted a comment via `post_comment`.                                |
| `submitted_to_test`  | Bot advanced the task to `in_test` via `finish` (auto-starts approval). |
| `marked_done`        | Task passed approval and reached `done` (recorded on the snapshotted original bot). |
| `execution_failed`   | Unrecoverable error; run ends, task stays `in_progress`.                |
| `question_asked`     | (B4) `ask_and_wait` fired. Payload: `{question}`.                       |
| `resumed`            | (B4) A run began because a human replied on a waiting task. Payload: `{run}`. |
| `revision_started`   | (B4) A run began because an approval rejection restored a bot assignee. Payload: `{run}`. |
| `handed_over`        | (B4) The run cap was reached; the bot posted a hand-over comment and stopped advancing. |
| `tool_used`          | (B5) A registry tool (`fetch_url` / `web_search` / `generate_file` / `read_attachments`) was invoked. Payload is per-tool (see B5 section) — never raw page content, file content, or API keys. |

`task_started` **always** carries a `{run, trigger}` payload where `trigger` is
`initial \| resume \| revision` (`BotRunTrigger`). A `resume`/`revision` run additionally
records its own `resumed` / `revision_started` action alongside `task_started`.

---

## Resource shapes

### BotListResource (index)

```json
{
  "id": "uuid",
  "name": "string",
  "status": "draft | active | disabled",
  "description": "string | null",
  "icon": "string | null",
  "has_text_module": true,
  "task_execution_enabled": false,
  "is_owner": true,
  "created_at": "ISO 8601 string"
}
```

- `icon` — general-info icon identifier (B6), nullable.
- `has_text_module` is always `true` (persona is mandatory on create).
- `task_execution_enabled` reflects `task_execution.enabled` (false when not configured).
- `is_owner` — `isOwnedBy(auth user)`, via the hot-path `ownerUserId()` (no `creator` eager-load on
  the list). TRUE only for a HUMAN creator match — presentational, not authoritative. Gate UI
  actions on `can_be_edited`/`can_be_deleted` (see below and Authorization), not on `is_owner`. See
  `docs/backend/creator-attribution.md`.

### BotResource (show / store / update / restore)

```json
{
  "id": "uuid",
  "name": "string",
  "status": "draft | active | disabled",
  "description": "string | null",
  "icon": "string | null",

  "persona": "string (max 10 000)",
  "style": "string | null (max 5 000)",
  "dictionary": ["preferred-term"],
  "phrases": ["preferred-phrase"],
  "prohibitions": ["banned-term"],

  "task_execution": {
    "enabled": false,
    "tools": []
  },

  "knowledge": {
    "enabled": false,
    "entries": [{ "title": "string", "content": "string" }]
  },

  "visual": null,
  "audio": null,

  "creator": { "type": "user", "id": "uuid", "name": "string", "email": "string", "avatar": null },

  "is_owner": true,
  "can_execute_tasks": false,
  "can_be_edited": true,
  "can_be_deleted": true,

  "created_at": "ISO 8601 string",
  "updated_at": "ISO 8601 string"
}
```

Notes:
- **`creator`** is `App\Http\Resources\CreatorResource`'s discriminated union — `{ type: 'user', id,
  name, email, avatar }` (shown above) for a human-created bot, or a `workflow_run`/`bot` shape /
  `null` / an omitted key for other states. **No write path stamps a non-`user` creator on a `Bot`
  today** (a `Bot` is only ever created through the authenticated `POST /bots` endpoint), so this
  field is effectively always the `user` shape in practice — the union is documented in full at
  `docs/backend/creator-attribution.md`, which also covers the `is_owner` vs `can_be_edited`
  divergence for a genuinely system-created record.
- **`icon`** (B6) — general-info icon identifier shown in the always-visible editor header. Nullable, max 100.
- **`task_execution.knowledge_source` was REMOVED in B6.** The knowledge module replaces it.
  If an old client still sends `task_execution.knowledge_source`, the backend silently ignores
  it — it is not validated, read, or persisted.
- **`knowledge`** (B6) is `{ enabled: bool, entries: [{title, content}] }` — **not** a bare array.
  `Bot::knowledgeEnabled()` / `Bot::knowledgeEntries()` also tolerate the legacy bare-array
  shape from before B6 (treated as `enabled = true` iff it held entries) so old stored rows keep
  working without a data migration.
- **`audio`** (B6) replaces `voice` (straight column rename; same read-only `null` placeholder
  semantics — no logic yet).
- `visual` and `audio` are stored JSON columns reserved for future modules, returned as-is
  (typically `null`). Treat them as read-only; no write path exists.
- `can_execute_tasks` is `true` only when `status === 'active' && task_execution.enabled === true`.
- `can_be_edited` / `can_be_deleted` reflect `BotPolicy::update` / `delete` for the auth user.

### BotActionResource

```json
{
  "id": "uuid",
  "bot_id": "uuid",
  "task_id": "uuid | null",
  "type": "task_started | form_filled | commented | submitted_to_test | marked_done | execution_failed | question_asked | resumed | revision_started | handed_over | tool_used",
  "payload": {},
  "status": "ok | failed | handed_over",
  "error": "string | null",
  "created_at": "ISO 8601 string",
  "updated_at": "ISO 8601 string"
}
```

- `error` is populated only for `execution_failed` actions.
- `status` is `'ok'` for most actions, `'failed'` for `execution_failed`, and `'handed_over'`
  for `handed_over` (see `BotTaskRunManager::handOver()`).
- `payload` shape depends on `type`:

| `type`             | `payload` shape                                                        |
|---------------------|--------------------------------------------------------------------------|
| `task_started`       | `{ run: int, trigger: 'initial' \| 'resume' \| 'revision' }`            |
| `question_asked`     | `{ question: string }`                                                  |
| `resumed`            | `{ run: int }`                                                          |
| `revision_started`   | `{ run: int }`                                                          |
| `tool_used` (`fetch_url`) | `{ tool: 'fetch_url', host: string, url: string }`                  |
| `tool_used` (`web_search`) | `{ tool: 'web_search', query: string, results: int }`               |
| `tool_used` (`generate_file`) | `{ tool: 'generate_file', file: string }`                        |
| `tool_used` (`read_attachments`, list) | `{ tool: 'read_attachments', action: 'list', count: int }` |
| `tool_used` (`read_attachments`, read) | `{ tool: 'read_attachments', action: 'read', file: string }` |
| other                | `{}` (empty)                                                             |

`tool_used` payloads deliberately carry only small, non-sensitive metadata (host/query/file
name) — **never** fetched page content, attachment content, or API keys.

- List endpoints (bot actions, task bot-actions) are cursor-paginated, wrapped in
  `{ "data": [...], "meta": { "next_cursor": "string | null" } }`. Page size 15.

---

## Endpoints

### GET /api/bots

List bots in the workspace. Cursor-paginated, 8 per page, newest first.

**Query**

| Param    | Required | Notes                                                   |
|----------|----------|-----------------------------------------------------------|
| `search` | no       | case-insensitive match on `name` / `description`        |
| `cursor` | no       | cursor from `meta.next_cursor` for the next page        |

**Response** `200 OK`

```json
{ "data": [ BotListResource ], "meta": { "next_cursor": "string | null" } }
```

---

### POST /api/bots

Create a bot.

**Body** (JSON)

```json
{
  "name": "Creative Writer",
  "status": "draft",
  "description": "Generates social media content.",
  "icon": "sparkles",
  "persona": "You are a creative writer ...",
  "style": "Casual, short sentences.",
  "dictionary": ["content", "engagement"],
  "phrases": ["Let's grow together"],
  "prohibitions": ["clickbait", "ALL CAPS"],
  "task_execution": {
    "enabled": true,
    "tools": ["web_search", "read_attachments"]
  },
  "knowledge": {
    "enabled": true,
    "entries": [{ "title": "Brand voice", "content": "Always friendly, never salesy." }]
  }
}
```

| Field                            | Required | Constraints                             |
|----------------------------------|----------|-----------------------------------------|
| `name`                           | yes      | string, max 255                         |
| `status`                         | no       | `draft \| active \| disabled`; default `draft` |
| `description`                    | no       | string, max 2500                        |
| `icon`                           | no       | string, max 100 (general-info icon)     |
| `persona`                        | yes      | string, max 10 000                      |
| `style`                          | no       | string, max 5000                        |
| `dictionary`                     | no       | array of strings (max 255 each)         |
| `phrases`                        | no       | array of strings (max 255 each)         |
| `prohibitions`                   | no       | array of strings (max 255 each)         |
| `task_execution`                 | no       | object; omit or send `null` to leave the stored value unchanged |
| `task_execution.enabled`         | no       | boolean                                 |
| `task_execution.tools`           | no       | array of strings; each must be one of the `BotTool` registry ids (`fetch_url`, `web_search`, `generate_file`, `read_attachments`) — validated via `Rule::in(BotTool::ids())`, so an unknown id is rejected (422), not silently dropped |
| `knowledge`                      | no       | object                                  |
| `knowledge.enabled`              | no       | boolean                                 |
| `knowledge.entries`              | no       | array, max 50 entries                   |
| `knowledge.entries.*.title`      | required when an entry is present | string, max 255      |
| `knowledge.entries.*.content`    | required when an entry is present | string, max 5000      |

`task_execution.knowledge_source` is **not** a valid field anymore (B6) — sending it has no
effect (not validated, not persisted).

**Response** `200 OK` — `BotResource` with `creator` loaded.

**Errors**

| Code | Field                      | Meaning                                       |
|------|----------------------------|------------------------------------------------|
| 422  | `persona`                  | Required; cannot be blank.                    |
| 422  | `name`                     | Required; max 255.                            |
| 422  | `task_execution.tools.*`   | Unknown tool id (not in the registry).        |
| 422  | `knowledge.entries.*.title`/`content` | Missing/too long on a present entry.|
| 403  | —                          | User not authenticated or not a workspace member. |

---

### GET /api/bots/{id}

Fetch one bot (soft-deleted bots are NOT found; use restore route to reinstate first).

**Response** `200 OK` — `BotResource` with `creator` loaded.

**Errors**

| Code | Meaning               |
|------|-----------------------|
| 403  | Not a workspace member. |
| 404  | Bot not found.        |

---

### PUT /api/bots/{id}

Update a bot. Same validation rules as POST. Authorization: creator only (`BotPolicy::update`).

**Response** `200 OK` — `BotResource` with `creator` loaded.

**Errors**

| Code | Meaning                           |
|------|-----------------------------------|
| 403  | Not the creator of this bot.      |
| 404  | Bot not found.                    |
| 422  | Validation failure (same as POST). |

---

### DELETE /api/bots/{id}

Soft-delete a bot. Authorization: creator only.

**Response** `200 OK` — `{ "message": "Bot deleted successfully" }`

**Errors**

| Code | Meaning                      |
|------|------------------------------|
| 403  | Not the creator of this bot. |
| 404  | Bot not found.               |

---

### POST /api/bots/{id}/restore

Restore a soft-deleted bot. `{id}` resolves through `Bot::withTrashed()`.
Authorization: creator only (`BotPolicy::restore`).

**Response** `200 OK` — `BotResource` with `creator` loaded.

**Errors**

| Code | Meaning                                  |
|------|--------------------------------------------|
| 403  | Not the creator of this bot.             |
| 404  | Bot (including trashed) not found.       |

---

### GET /api/bots/tool-registry

(B5) Discovery endpoint for the bot editor: every registry tool id + its current
availability. Route is registered ahead of `bots/{id}` so `tool-registry` is not swallowed
by the resource route's `{bot}` parameter.

**Response** `200 OK`

```json
{
  "data": [
    { "id": "fetch_url", "available": true },
    { "id": "web_search", "available": false },
    { "id": "generate_file", "available": true },
    { "id": "read_attachments", "available": true }
  ]
}
```

- `available: false` for `web_search` means `AI_SEARCH_API_KEY` is not configured. An
  unavailable tool is hidden by the frontend and **never** exposed to the agent — see the
  Tool Registry section below.
- The other three tools are always `available: true` (no external config prerequisite).

---

### GET /api/bots/{bot}/actions

Cursor-paginated action audit log for a single bot (newest first, page size 15).

**Query parameters**

| Param  | Required | Description                                               |
|--------|----------|-------------------------------------------------------------|
| `type` | no       | Filter by `BotActionType` value (e.g. `?type=tool_used`). |
| `cursor` | no     | Cursor from `meta.next_cursor` for the next page.         |

**Response** `200 OK`

```json
{ "data": [ BotActionResource ], "meta": { "next_cursor": "string | null" } }
```

---

### GET /api/tasks/{task}/bot-actions

Action audit log for a single task (newest first, page size 15). Resolves via
`Task::withTrashed()` so the history of deleted tasks is still accessible.
Auth: workspace membership (no ownership check — any member can view).

**Response** `200 OK` — same cursor-paginated shape as above.

---

## Authorization

`BotPolicy` gates, all mutating checks routed through the shared
`ChecksRecordOwnership::ownsOrManagesSystemRecord()` (see `docs/backend/creator-attribution.md`):

| Gate       | Rule                                                         |
|------------|--------------------------------------------------------------|
| `viewAny`  | Any authenticated workspace member.                         |
| `view`     | Any authenticated workspace member.                         |
| `create`   | Any authenticated workspace member.                         |
| `update`   | The bot's human owner (`isOwnedBy`), OR — only for a bot with NO human creator — the active workspace's OWNER (fallback). |
| `delete`   | Same rule as `update`.           |
| `restore`  | Same rule as `update`.           |
| `changeStatus` | Same rule as `update` (toggling `active`/`disabled` — `PATCH /bots/{id}/status`, not otherwise documented on this page). |
| `retry`    | Same rule as `update` (manually retrying a failed task run — `POST /bots/{bot}/tasks/{task}/retry`, not otherwise documented on this page; gated to the owner because it dispatches a real, cap-exempt AI run). |

**A `Bot` is created only through the authenticated `POST /bots` endpoint** — no engine step or
agent creates one — so in practice its creator is always human today, and the workspace-owner
fallback above is currently unreachable for this resource. It is documented because the Policy
code implements it generically (the same `ownsOrManagesSystemRecord()` trait method Task/Workflow
use), and because a future write path (e.g. a bot provisioning its own sub-bot) would activate it
without a Policy change.

Workspace membership is enforced upstream by `ResolveWorkspace` + `WorkspaceScope`; these
policy checks are additive owner gates on top of that guarantee.

---

## Task fields related to bot execution (additive)

`TaskResource` / `TaskListResource` gained (B4, additive, alongside all existing fields):

| Field           | Type    | Meaning                                                                 |
|-----------------|---------|----------------------------------------------------------------------------|
| `bot_waiting`   | boolean | `Task::isBotWaiting()` — true iff `assignee_type === 'bot' && bot_run_state === 'waiting'`. Full detail resource + list resource both expose it. |
| `bot_runs_used` | integer | (Full `TaskResource` only.) Monotonic run counter for this task (`tasks.bot_runs_used`). |
| `bot_runs_cap`  | integer | (Full `TaskResource` only.) `config('ai.max_runs_per_task')` — the run cap in effect, echoed for the UI. |

---

## Bot task-execution flow (B4 — interactive, supersedes the earlier one-shot flow)

The bot is an **interactive task participant**, not a one-shot runner. A task may see MANY
runs over its lifetime: an initial run on assignment, a resume run every time a human replies
while the bot is waiting, and a revision run every time an approval rejection restores a bot
assignee.

### Run-state machine (`BotTaskRunManager`)

Two columns on `tasks` drive the loop (migration `2026_06_25_000400_add_bot_run_state_to_tasks_table`):

- `bot_run_state`: `idle \| running \| waiting`
- `bot_runs_used`: monotonic counter, incremented on every claim

```
idle ──claim──▶ running ──release(finished/failed)──▶ idle
                    └────release(waiting)───────────▶ waiting ──claim(resume)──▶ running
```

**Claim is atomic** — a single conditional `UPDATE`:

```sql
UPDATE tasks
SET bot_run_state = 'running', bot_runs_used = bot_runs_used + 1
WHERE id = ? AND bot_run_state IN ('idle', 'waiting') AND bot_runs_used < :cap
```

Postgres row-locks the update, so exactly one concurrent caller can flip to `running`
(`affected = 1`); this is the concurrency guard — no two runs ever execute at once for the
same task, and it holds even under a non-sync queue. If the claim fails because the cap is
already reached, the bot hands the task over instead of silently no-op'ing (see below). If the
claim fails simply because a run is already active, it is a no-op.

> **This replaces the B1–B3 idempotency mechanism.** The earlier partial unique index on
> `bot_actions (bot_id, task_id) WHERE type = 'task_started'` — which allowed at most ONE
> `task_started` row per (bot, task) pair — was **dropped** in migration
> `2026_06_25_000401_drop_task_started_unique_index_from_bot_actions_table`, because B4
> deliberately allows many runs (and many `task_started` rows) per task. Concurrency-safety
> now lives entirely in the `bot_run_state` atomic claim described above.

### Triggers (`BotTaskExecutionService`)

| Trigger              | Entry point                    | Condition                                                        |
|-----------------------|----------------------------------|--------------------------------------------------------------------|
| `initial`             | `maybeDispatch(Task $task)`      | Task is freshly assigned to an execution-capable bot AND status is `TO_DO`. |
| `resume`              | `resumeFromHumanReply(Task $task)` | A comment with `author_type = 'user'` was just created on a task whose bot is `bot_run_state = 'waiting'`. Wired from `CommentService::create()` — a bot's own comment (`author_type = 'bot'`) never triggers this (the loop guard). |
| `revision`            | `reviseAfterReject(Task $task)`  | `Task::onApprovalRejected()` restores a bot as the original assignee. Dispatched via `DB::afterCommit()` so the job only runs after the reject transaction is committed. |

All three funnel into `BotTaskRunManager::dispatch(Task $task, BotRunTrigger $trigger)`, which
performs the atomic claim and, on success, dispatches `BotTaskExecutionJob` with that trigger.

### Context injection (`BotTaskContextBuilder`)

Before each run, the agent's instructions are built from:

1. **Knowledge** — the bot's enabled knowledge entries (see Knowledge module below), capped at
   `ai.knowledge_max_chars` (default 8000 characters).
2. **Task** — title + description.
3. **Form** — the attached form's schema AND the current submission state (or "not yet filled").
4. **Comments** — the most recent `ai.context_comment_limit` (default 30) task comments,
   fetched newest-first then reversed to oldest-first so the conversation reads in order. Each
   line is tagged `(bot)` when authored by a bot.
5. **Approval history** — every `ApprovalProcess` for the task, in order, including **rejection
   notes** so a revision run knows exactly what to fix.

### Tools (`app/modules/Bot/Tools/`) — always-present interaction tools

Four tools are always available to the agent (subject to task shape); these are distinct from
the four *optional* registry tools (B5, see below):

| Tool             | Signature                  | Availability                          | Effect |
|-------------------|-----------------------------|------------------------------------------|--------|
| `post_comment`    | `post_comment(text)`        | Always.                                  | Posts a bot-authored comment. Usable any number of times, any point in the run. Records `commented`. |
| `fill_form`       | `fill_form(answers)`        | Only when the task has an attached form. | Persists form answers (create or update). Empty `answers` throws a tool-error. Records `form_filled`. |
| `ask_and_wait`    | `ask_and_wait(question)`    | Always.                                  | Posts the question as a bot comment, records `question_asked{question}`, and **ends the run** — task stays `in_progress`, `bot_run_state → waiting`. The next comment with `author_type = 'user'` resumes the run (`trigger = resume`). A bot's own comment never resumes it. |
| `finish`          | `finish()`                  | Always.                                  | Submits the task to `in_test` (auto-starts an attached approval pipeline, identical to the human path). **Fails with an instructive tool-error** if the task has an attached form that was never filled (this run or previously) — the agent must call `fill_form` first. Records `submitted_to_test`. |

A run ends when the agent calls a terminal tool (`ask_and_wait` or `finish`) or simply stops
issuing tool calls (treated the same as an ordinary stop — run-state released to `idle`,
task stays wherever it was left, e.g. still `in_progress` with no comment posted — this is a
degenerate case, not a failure).

### Run lifecycle (`BotTaskExecutionJob`)

1. Record `task_started` with `{run: bot_runs_used, trigger}`. If `trigger` is `resume` or
   `revision`, additionally record `resumed{run}` / `revision_started{run}`.
2. `task → in_progress` (via `TaskService::botStart`, bypasses the user-centric `canSetOn` guard).
3. Build context (`BotTaskContextBuilder`) and run `BotTaskExecutionAgent::prompt()` — a
   multi-step (`#[MaxSteps(12)]`) Laravel AI agent that chains tool calls.
4. On success: `BotTaskRunManager::release($task, $interaction->outcome())` — releases to
   `waiting` if `ask_and_wait` fired, otherwise `idle`.
5. On any throwable (agent error, tool error surfaced as an exception, etc.): record
   `execution_failed` with the error message, release the run-state to `idle`. Task stays
   wherever it was left (typically `in_progress`).
6. `failed()` handler (fires when `tries` are exhausted / a Laravel-level job failure occurs,
   e.g. a timeout): records `execution_failed` and releases the run-state to `idle` as a
   last-resort safety net, so a future run isn't permanently blocked by a stuck `running` claim.

`BotTaskExecutionJob::tries = 1` — no automatic retry.

### Run cap and hand-over

`config('ai.max_runs_per_task')` (default 5, env `AI_MAX_RUNS_PER_TASK`) hard-caps the total
number of runs per task — initial + every resume + every revision all count. When a dispatch
attempt finds the cap already reached, the bot:

1. Posts a hand-over comment ("Osiągnąłem limit prób automatycznej realizacji tego zadania.
   Przekazuję je człowiekowi do dalszej obsługi.").
2. Records a `handed_over` action (`status: 'handed_over'`).
3. Leaves the task exactly where it is — does **not** advance status.

### Approval completion / rejection integration

- **Completion:** `Task::onApprovalCompleted()` calls
  `BotTaskExecutionService::recordMarkedDoneFromContext()`, recording `marked_done` for the
  bot snapshotted as the original assignee when approval started (unchanged from B2/B3).
- **Rejection:** `Task::onApprovalRejected()` restores the polymorphic original assignee; if
  that original assignee is a bot, it additionally calls
  `BotTaskExecutionService::reviseAfterReject($task)` (deferred to `DB::afterCommit()`) — see
  the `revision` trigger above.

---

## Operational caveat — do not run an async queue without a stale-claim reaper

`BotTaskExecutionJob::failed()` releases a stuck claim back to `idle` on any Laravel-detected
job failure (exception during `handle()`, or exhausted `tries`). **This does not cover a hard
process kill** (SIGKILL, OOM-killer, `queue:restart` mid-job, host crash) — in that case the
job simply stops executing with no chance to run `failed()`, and the task is left stranded in
`bot_run_state = 'running'` forever. Because `claim()` only matches `idle`/`waiting` states, a
stranded `running` task can **never** be recovered by a future run.

**Do not enable a non-sync (async) queue worker for `BotTaskExecutionJob` without first adding
a stale-claim reaper** (a scheduled job that resets tasks stuck in `running` past some TTL
back to `idle`, likely recording an `execution_failed` action). This is an accepted, documented
gap — not yet built.

---

## Tool registry (B5) — optional tools a bot can be granted

Four **optional** tools, distinct from the always-present interaction tools above. A bot must
be explicitly **granted** a tool via `task_execution.tools[]`, and the tool must be
**available** in the current environment — a tool is exposed to the agent only when
`GRANTED ∩ AVAILABLE` (`BotToolRegistry::grantedAvailableIds()`). There are never "dead"
options in the editor: `GET /api/bots/tool-registry` reports availability so the frontend
hides anything unavailable.

| `BotTool` id       | Availability                                    | What it does |
|---------------------|--------------------------------------------------|----------------|
| `fetch_url`         | Always available.                               | Fetch a public web page, return plain text (HTML stripped). SSRF-guarded (see below). |
| `web_search`        | Available only when `config('ai.search.api_key')` (env `AI_SEARCH_API_KEY`) is set. | Search via the configured provider (Brave by default) and return top results (title/url/snippet). |
| `generate_file`      | Always available.                               | Create a text file (`txt`/`md`/`csv`/`json`) and attach it to the current task via the existing Disk mechanism. |
| `read_attachments`   | Always available.                               | List the current task's attachments, or return the text content of one (text-y types only, size-capped). |

`BotToolRegistry::isAvailable()` is the single source of truth consulted by both the discovery
endpoint and the actual tool-building path (`BotTaskToolFactory`) — so "what the editor shows"
and "what the agent can actually call" can never drift apart.

### `config/ai.php` keys introduced for B5

| Config key                        | Env var                     | Default              | Purpose |
|-------------------------------------|-------------------------------|-------------------------|-----------|
| `ai.search.provider`                 | `AI_SEARCH_PROVIDER`          | `brave`                 | Search provider identifier. |
| `ai.search.api_key`                  | `AI_SEARCH_API_KEY`           | `null`                  | Gates `web_search` availability; sent as `X-Subscription-Token`, never logged/echoed/persisted. |
| `ai.search.results`                  | `AI_SEARCH_RESULTS`           | `5`                     | Max results returned per search. |
| `ai.fetch_timeout`                   | `AI_FETCH_TIMEOUT`            | `10` (seconds)          | `fetch_url` request timeout. |
| `ai.fetch_max_bytes`                 | `AI_FETCH_MAX_BYTES`          | `2097152` (2 MB)        | Hard byte cap while streaming the response body. |
| `ai.fetch_max_chars`                 | `AI_FETCH_MAX_CHARS`          | `20000`                 | Cap on the plain-text characters returned to the agent. |
| `ai.fetch_max_redirects`             | `AI_FETCH_MAX_REDIRECTS`      | `3`                     | Max redirect hops followed (each re-validated + re-pinned). |
| `ai.generate_file_max_bytes`         | `AI_GENERATE_FILE_MAX_BYTES`  | `1048576` (1 MB)        | Max size of a bot-generated file. |
| `ai.read_attachment_max_bytes`       | `AI_READ_ATTACHMENT_MAX_BYTES`| `1048576` (1 MB)        | Max size of an attachment `read_attachments` will return. |

Also introduced alongside B4 (not B5, but new in this era): `ai.max_runs_per_task`
(`AI_MAX_RUNS_PER_TASK`, default 5) and `ai.context_comment_limit`
(`AI_CONTEXT_COMMENT_LIMIT`, default 30) — see the execution-flow section above — and
`ai.knowledge_max_chars` (`AI_KNOWLEDGE_MAX_CHARS`, default 8000) — see the Knowledge module
section below.

### `generate_file` — documented compromise

A bot-generated file is a real `Disk` `File` row, `fileable` to the task, visible/downloadable
like any human-uploaded attachment. `uploader_id` is `NOT NULL` on `files` and a bot has no
user row, so the uploader is attributed to **the task's creator** (`uploader_id => $task->creator_id`)
— a deliberate stopgap, not a bug. A future schema pass may make `uploader_id` nullable and add an
explicit bot-uploader marker; until then this compromise is intentional and should not be "fixed"
as a drive-by change.

`files.uploader_type` is now a polymorphic discriminator (the same `user | workflow_run | bot`
morph as `creator` elsewhere — see `docs/backend/creator-attribution.md`), but `GenerateFileTool`
does not set it explicitly, so `HasCreator` defaults it to `'user'`. **Sharper edge case, not yet
fixed:** if the task itself is a system record (its own `creator_id` is a `WorkflowRun`/`Bot` uuid
— e.g. a task created by another workflow's `create_task` step), the generated file's `uploader_id`
copies that non-user uuid while `uploader_type` stays `'user'`, so `File.creator` resolves to
`null` instead of a meaningful attribution. Documented as a known gap in
`docs/backend/creator-attribution.md` and ADR-0015, not silently patched here.

### `read_attachments` — scope and text-sniffing

Strictly scoped to the current task's own `files()` relation — no path traversal, and another
task's or workspace's files are unreachable. Only `txt`/`md`/`csv`/`json` (by extension or
known mime type) are treated as textual, and the actual bytes are additionally sniffed
(valid UTF-8, low control-character ratio) before being returned — a stored mime/extension is
not trusted blindly. Duplicate attachment names within a task are resolved to the newest match,
with an explicit ambiguity note in the response so the bot never silently reads an arbitrary one.

---

## Security — accepted residual risks (B5)

Two risk classes are inherent to giving an LLM agent tools that touch the network and file
content. Both were reviewed and are **accepted**, with specific mitigations already in place.
This is not a TODO list — it documents a considered trade-off.

### 1. SSRF (`fetch_url`)

`SafeUrlGuard` (`app/modules/Bot/Tools/Support/SafeUrlGuard.php`) hardens the fetch:

- Canonicalizes the host: strips IPv6 brackets, strips a trailing dot (`example.com.`),
  normalizes numeric-literal hosts in **any** encoding (decimal, hex `0x...`, octal `0...`,
  dotted-quad) to a canonical IP so they are blocked as literals rather than falling through
  to DNS.
- Resolves the (possibly-canonicalized) host to its IP address(es) and validates **every** one
  against the loopback/private/link-local/reserved ranges (`FILTER_FLAG_NO_PRIV_RANGE |
  FILTER_FLAG_NO_RES_RANGE`), including unwrapping IPv4-mapped IPv6 (`::ffff:127.0.0.1`) to its
  embedded v4 form before the range check.
- Resolves the host **once**, then **pins the validated IP into the connection** via
  `CURLOPT_RESOLVE` (`host:port:ip`) — so cURL connects to exactly the IP that was validated,
  even if DNS would now resolve differently. This closes the classic DNS-rebinding TOCTOU
  window (validate a safe IP, then have DNS switch to a private one before the actual
  connection).
- **Every redirect hop is independently re-validated and re-pinned** — a 3xx response cannot
  be used to smuggle a request to a blocked address after the initial hop passed.
- The response body is **streamed and aborted** once it exceeds `ai.fetch_max_bytes` (a
  chunked reader, so no full download into memory even for a huge or infinite response), plus
  `CURLOPT_MAXFILESIZE` guards responses that declare an over-cap `Content-Length` up front.

**Residual risk (accepted):** trust in the single DNS lookup at validation time (an attacker
controlling DNS could still return a public-then-private answer split across two separate
`fetch_url` calls — each individual fetch is safe, but the guard does not protect against an
attacker orchestrating multiple fetches), and the tool legitimately fetching whatever a public
host actually serves (no content-based safety judgment beyond "was this host/IP safe to
connect to").

### 2. Prompt injection (`fetch_url` content, `read_attachments` content)

Text fetched from a web page or read from a task attachment enters the agent's context
verbatim and could contain adversarial instructions aimed at the model ("ignore your previous
instructions and...").

**Blast radius is bounded, by design, to this task's own write powers:**
- The agent's only actions are the four interaction tools (`post_comment`, `fill_form`,
  `ask_and_wait`, `finish`) plus whichever registry tools are granted — all scoped to the
  **current task**.
- There is no cross-task or cross-workspace reach: `read_attachments` only sees the current
  task's files; `generate_file` only attaches to the current task; comments/form fills only
  touch the current task.
- **Approvals still gate advancement.** A task submitted via `finish` still goes through
  `in_test` and, if a pipeline is attached, a real approval process — an injected instruction
  cannot bypass human or AI review to reach `done` on its own.

**Residual risk (accepted):** a sufficiently crafted page/attachment could still manipulate the
bot's tone, cause it to post a misleading comment, or waste run budget — but it cannot escalate
beyond what the bot could already do on that task through its normal tools.

---

## Knowledge module (B6)

`knowledge` is `{ enabled: bool, entries: [{title, content}] }` — an explicitly-enabled
module. Entries are injected into the execution context (`BotTaskContextBuilder`) **only**
when `enabled` is `true`; a module holding entries but toggled off is completely inert (the
user must explicitly turn it on before it takes effect).

- Max 50 entries per bot; each `title` max 255 chars, `content` max 5000 chars (validated).
- Total injected knowledge text is capped at `ai.knowledge_max_chars` (default 8000 characters,
  env `AI_KNOWLEDGE_MAX_CHARS`) — entries are appended until the cap would be exceeded, then a
  truncation marker is appended so the model knows more knowledge exists but was omitted.
- `Bot::knowledgeEnabled()` / `Bot::knowledgeEntries()` tolerate the pre-B6 bare-array
  `knowledge` shape for backward compatibility with rows written before this change — no data
  migration was needed for the shape switch itself (only the `voice → audio` rename and the
  new `knowledge` column required schema changes).
- A future app-wide Knowledge module (outside the Bot module) may eventually absorb this
  per-bot knowledge store; today it is scoped per-bot only.

---

## AI provider configuration

`config/ai.php` controls the provider and model used by both `BotTaskExecutionAgent` and
`ApprovalEvaluationAgent`.

| Config key    | Env var      | Default    |
|---------------|--------------|------------|
| `ai.provider` | `AI_PROVIDER`| `openai`   |
| `ai.model`    | `AI_MODEL`   | `gpt-4o`   |

---

## Persistence notes

| Model      | Table         | PK type | Soft deletes |
|------------|---------------|---------|--------------|
| `Bot`      | `bots`        | UUID    | Yes          |
| `BotAction`| `bot_actions` | UUID    | Yes          |

Additional `tasks` columns (B4, migration `2026_06_25_000400_add_bot_run_state_to_tasks_table`):

| Column           | Type              | Default | Purpose                       |
|-------------------|--------------------|---------|----------------------------------|
| `bot_run_state`   | `string`           | `idle`  | `idle \| running \| waiting`   |
| `bot_runs_used`   | `unsignedInteger`  | `0`     | Monotonic run counter, cast `integer`. |

Morph aliases (registered in `BotModuleServiceProvider`):
- `'bot'` → `App\Modules\Bot\Models\Bot`
- `'bot_action'` → `App\Modules\Bot\Models\BotAction`

The `'user'` alias (`'user'` → `App\Models\User`) is registered in `AuthModuleServiceProvider`.

---

## Related files

- `app/modules/Bot/` — module root
- `app/modules/Bot/Agents/BotTaskExecutionAgent.php` — interactive Laravel AI agent (B4)
- `app/modules/Bot/Jobs/BotTaskExecutionJob.php` — one interactive run per dispatch
- `app/modules/Bot/Services/BotTaskExecutionService.php` — trigger decision (initial/resume/revision)
- `app/modules/Bot/Services/BotTaskRunManager.php` — atomic claim / run-state machine / cap / hand-over
- `app/modules/Bot/Services/BotTaskInteractionService.php` — the tools' side-effects (single run)
- `app/modules/Bot/Services/BotTaskContextBuilder.php` — read-context injection
- `app/modules/Bot/Tools/` — the four always-present interaction tools
- `app/modules/Bot/Tools/Registry/` — the four optional registry tools (B5)
- `app/modules/Bot/Tools/Support/SafeUrlGuard.php` — SSRF hardening for `fetch_url`
- `app/modules/Bot/Services/BotToolRegistry.php` — granted ∩ available resolution
- `app/modules/Bot/Http/Controllers/BotToolRegistryController.php` — `GET /bots/tool-registry`
- `tests/Concerns/FakesBotExecutionAgent.php` — test seam (see below — content changed since B1–B3)
- `tests/Support/ScriptedBotExecutionAgent.php` — scripted multi-step test double (B4)
- `tests/Feature/BotCrudTest.php`
- `tests/Feature/BotTaskExecutionTest.php`
- `tests/Feature/BotApproverTest.php`
- `tests/Feature/BotToolRegistryTest.php`
- `tests/Feature/BotModulesTest.php`

---

## AI test seam — corrected for B4 (this replaces the earlier `Agent::fake()`-only description)

`Tests\Concerns\FakesBotExecutionAgent` is the reusable test seam for the bot execution and
approval flows. **Its mechanism changed with B4** and now uses two different strategies for
its two different agents:

- **`ApprovalEvaluationAgent`** (structured output, no tool loop) still uses Laravel AI's
  built-in `Agent::fake([...])`:

  ```php
  // Drive an approval decision deterministically (generic AI or named-bot approver):
  $this->fakeApprovalEvaluation(['decision' => 'approved', 'note' => 'Looks good.']);

  // Capture the instructions passed to the faked agent (e.g. assert persona injection):
  $this->fakeApprovalEvaluationUsing(function ($prompt) { /* ... */ });
  ```

- **`BotTaskExecutionAgent`** (multi-step, tool-calling) **cannot** be driven by
  `Agent::fake()`. Investigation found Laravel AI's `FakeTextGateway` accepts an agent's
  declared tools but never actually invokes them, and the fake closure only receives
  `(prompt, attachments, provider, model)` — not the agent — so there is no way to script a
  `post_comment → fill_form → finish` tool sequence through the built-in fake. Instead:

  ```php
  // Script a multi-step interactive run — invokes the REAL tools, in order, against the
  // REAL interaction service (no provider call at all):
  $this->scriptBotRun([
      ['post_comment', ['text' => 'Working on it.']],
      ['fill_form', ['answers' => ['q1' => 'answer']]],
      ['finish', []],
  ]);

  // Drive the failure path:
  $this->failBotRun('AI provider error');
  ```

  `scriptBotRun()` swaps `BotTaskExecutionAgent` in the container for
  `Tests\Support\ScriptedBotExecutionAgent` — a standalone double (not a subclass; the real
  `prompt()` has a strict return type) that resolves the SAME tool set as the real agent (via
  the shared `BotTaskToolFactory`) and invokes each scripted tool call directly against the
  real `BotTaskInteractionService`. A step naming a tool not exposed for the task (e.g.
  `fill_form` when there is no form) is silently skipped; the script stops early once a
  terminal tool (`ask_and_wait` / `finish`) fires. This means a scripted test exercises the
  FULL real application path — dispatch → run-state claim → tool side-effects → run-state
  release — with only the LLM call itself replaced.

Reuse this trait for any future agent flow: structured-output agents can usually use
`Agent::fake()` directly; a multi-step tool-calling agent should follow the
`scriptBotRun`/`ScriptedBotExecutionAgent` pattern instead.
