# Backend API: Bot (AI Character) module

Module: `app/modules/Bot/`
Auth: all endpoints require `auth:sanctum` + `X-Workspace-Id` header (resolved by `ResolveWorkspace` middleware).
Tenant scope: `TenantAware` trait — all queries are automatically scoped to the active workspace.

Covers B1–B6: CRUD + persona (B1–B2), polymorphic actor + one-shot execution (B2, superseded —
see below), interactive multi-turn execution (B4), the optional tool registry (B5), and the
5-module structure + knowledge module (B6). Also covers R2 sub-stage 3 ("Boty w generatorze") —
the `Bot → Generator` session-DELEGATION edge, letting a bot author a Generator `GenerationSession`
in its own voice; see the delegate/undo endpoints below. Also covers the character VISUAL IDENTITY
phase — the "Wygląd" module's real read/write logic (a likeness the bot generates/curates, and the
new one-way `Bot → Disk` edge it rides) and its extension of the R2 sub-stage 3 delegation overlay to
freeze the LOOK alongside the voice; see "Visual identity module ('Wygląd')" below and
`docs/decisions/ADR-0042-character-visual-identity.md`.

---

## Concepts

A **Bot** is a workspace-scoped AI character (digital worker) with **5 modules**:

| Module          | Column(s)                    | Required | Notes                                             |
|------------------|-------------------------------|----------|----------------------------------------------------|
| Text             | `persona`, `style`, `dictionary`, `phrases`, `prohibitions` | Yes | Always shapes the AI voice. |
| Task-execution   | `task_execution` (`{enabled, tools}`) | No | Makes the bot an interactive task participant (B4) with optional registry tools (B5). |
| Knowledge        | `knowledge` (`{enabled, entries}`) | No | Entries injected into the execution context only when enabled (B6). |
| Visual           | `visual` (`{enabled, descriptor, aesthetic, wardrobe, prohibitions, reference_file_id, candidates, canonical_file_id, prompt}`) | No | The bot's LIKENESS — an approved reference image a delegated Generator session draws from. Real read/write logic; see "Visual identity module ('Wygląd')" below. |
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
| `submitted_to_test`  | Bot advanced the task to `in_test` via `finish` — only when an approval pipeline is attached (auto-starts approval). |
| `marked_done`        | Task reached `done`: either `finish` completed it directly (no approval pipeline attached), or it passed approval (recorded on the snapshotted original bot). |
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

  "visual": {
    "enabled": false,
    "descriptor": "string | null (max 240)",
    "aesthetic": "string | null (max 2000)",
    "wardrobe": "string | null (max 500)",
    "prohibitions": ["banned-visual-term"],
    "reference_file_id": "uuid | null",
    "candidates": ["uuid", "uuid"],
    "canonical_file_id": "uuid | null",
    "prompt": "string | null"
  },
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
  semantics — no logic yet). Still a placeholder — no write path exists, unlike `visual` below.
- **`visual`** (the character visual-identity phase) is `Bot::visualIdentity()`'s NORMALIZED shape —
  `null` only when the module has never been configured at all (a bot older than the feature, or one
  that was never touched). It is a REAL, writable module — see "Visual identity module ('Wygląd')"
  below for the write path, the two async generation endpoints, and the curation contract. Unlike
  `task_execution`/`knowledge`, its `candidates`/`canonical_file_id` are also written
  ASYNCHRONOUSLY by a queued generation, not only by `PUT /bots/{id}`.
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
| `can_execute_tasks` | no | truthy → only bots that can actually RUN a task: `status = active` AND `task_execution.enabled = true` (the SQL mirror of `Bot::canExecuteTasks()`). Used by the task-assignee pickers, which must not offer a bot the task write path would reject. Omitted → every bot, unchanged. |

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

### POST /api/bots/{bot}/sessions/{session}/delegate

### DELETE /api/bots/{bot}/sessions/{session}/delegate

(R2 sub-stage 3, "Boty w generatorze") DELEGATE a Generator `GenerationSession` to this bot — the bot
composes its persona/style/dictionary/phrases/prohibitions into ONE opaque voice directive, autonomously
fills the session's in-scope slots (AT MOST one metered `ai_text` call), and becomes the session's snapshotted
content author, while the human stays the session's owner. `DELETE` undoes it (restores the pre-delegation
inputs). This is a new cross-module edge, `Bot → Generator + Variables`, one-way — the
Generator/Variables seams these endpoints call take primitives/opaque strings only, never a `Bot` model. Since
the character visual-identity phase, the same delegation ALSO freezes the bot's approved LIKENESS (when its
Visual module is on and has one) onto the session — the image-side twin of the voice, snapshotted the same
way; see `docs/backend/generator-sessions-api.md` → "Frozen character visual identity" and
`docs/decisions/ADR-0042-character-visual-identity.md`. (The OTHER new cross-module edge, `Bot → Disk`, backs
the Visual module's own generation endpoints below — it is unrelated to this delegation edge and never
touches a `GenerationSession`.)

The body's optional `fill_mode` (`'gaps' | 'fresh'`, DEFAULT `gaps` — `App\Modules\Bot\Enums\SlotFillMode`) is
the human's click-time choice: `gaps` fills only the EMPTY inputs and never touches a value the human typed
(with no gap at all there is no provider call and nothing is billed — `fill_report.nothing_to_fill`), `fresh`
proposes a deliberately DIFFERENT take on everything in scope (safe because undo restores
`slot_values_before`). An unknown value is a `422`.

Both `{bot}` and `{session}` are workspace-scoped bindings (a foreign id 404s at bind). Authorization is the
SESSION's `update` ability (the human session owner, not "any bot manager") — delegating changes a session's
inputs/authorship, an owner action on the session, not an action on the bot.

Full request/response contract, the overlay + `fill_report` wire shapes, and the voice/slot-fill mechanics
live in `docs/backend/generator-sessions-api.md` ("Bot-author delegation overlay"); the design record is
`docs/decisions/ADR-0036-bot-delegation-generation-sessions.md`.

---

## Visual identity module ("Wygląd")

The bot's LOOK: a written identity (`descriptor`/`aesthetic`/`wardrobe`/`prohibitions`) an image is drawn
from, a bounded strip of generated candidates to choose from, and which one is APPROVED as the bot's
canonical likeness. `Bot::visual` is the shape (`{enabled, descriptor, aesthetic, wardrobe, prohibitions,
reference_file_id, candidates, canonical_file_id, prompt}`) — the SAME json column shipped as a placeholder
in the original `bots` migration, now with real logic. This is what a Generator session freezes when it is
delegated to a bot with the module on and an approved likeness (`docs/backend/generator-sessions-api.md` →
"Frozen character visual identity"). Full design record: `docs/decisions/
ADR-0042-character-visual-identity.md`.

**`enabled` gates USE, never editing.** Like `knowledge`, this is an explicitly-toggled optional module: its
stored content is fully editable and generateable while off — turning it on only decides whether a LATER
Generator delegation may draw from it. It is NOT the same gate as "has an approved image" (`visual_has_image`
on the list resource, below) — a bot can have one without the other, and the UI shows both states.

**Send the module WHOLE, or not at all (`PUT /bots/{id}`).** `BotDTO::normalizeVisual()` treats an ABSENT
`visual` key as "leave the stored module untouched" and a PRESENT one as a full overwrite —
`candidates`/`canonical_file_id` are written ASYNCHRONOUSLY by the generation worker (below), so a client
that knows nothing about this module (or is mid-edit on an older snapshot of it) must never send a partial/
stale `visual` object, or it will silently delete a candidate that landed while the form was open. A client
that DOES send `visual` always sends the server's current file pointers merged with whatever text it changed.
To clear the module's content, send it with empty/default values explicitly — omitting the key is a no-op,
not a clear.

**Never logged.** `BotVisualIdentityService` and `GenerateBotVisualJob` surface only structured facts (which
mode, which file, a moderation code) — the descriptor/aesthetic/wardrobe/prohibitions text and the composed
provider prompt are never written to a log line.

### `POST /bots/{id}` / `PUT /bots/{id}` — `visual` sub-payload

| Field | Required | Constraints |
|---|---|---|
| `visual.enabled` | no | boolean |
| `visual.descriptor` | no | string, max 240 — deliberately SHORT: one line of a composed subject, not a second persona |
| `visual.aesthetic` | no | string, max 2000 — palette / medium / lighting, applies to every drawn image |
| `visual.wardrobe` | no | string, max 500 — the default outfit; the ONE steerable defense against the provider's OUTPUT-side moderation (the same character is refused in a swimsuit, accepted in a dress — see ADR-0042), which is why it is its own field rather than prose inside `aesthetic` |
| `visual.prohibitions` | no | array of strings, max 50 entries, each max 255 — a visual "never draw this" list |
| `visual.reference_file_id` | no | uuid. Must belong to THIS bot (a prior upload) OR be a disk-native file the user picked from their own Disk (`BotVisualFile(allowDiskNative: true)`) |
| `visual.candidates` | no | array of uuids, max 6 (`BotVisualIdentityService::MAX_CANDIDATES`). Each must be a file already OWNED by this bot — set by the generation worker, not normally hand-written by a client |
| `visual.canonical_file_id` | no | uuid. Must be one of `visual.candidates` — the approved likeness |
| `visual.prompt` | no | string, max 2000 — the last composed generation prompt (server-written audit trail; read-only in practice) |

### POST /api/bots/{bot}/visual/generate

Queue ONE likeness generation. `multipart/form-data`, owner-only (`update` on the bot — spends real provider
budget and writes to the bot). Its own tight throttle bucket (`throttle:10,1,bot-visual`) — a long,
provider-billed call. Requires an active workspace (`RequireWorkspace` — the only bot routes that touch
workspace-owned binaries).

| Field | Required | Notes |
|---|---|---|
| `mode` | yes | `'reference' \| 'description'` (`App\Modules\Bot\Enums\BotVisualMode`) — the human's explicit choice, never inferred from which fields happen to be filled. |
| `reference` | reference mode: EXACTLY ONE of `reference`/`reference_file_id` | A fresh upload — jpeg/png/webp, max 25600 KB (mirrors the Disk AI editor's canvas limit). |
| `reference_file_id` | reference mode: EXACTLY ONE of `reference`/`reference_file_id` | An existing file id — the bot's own image, or a disk-native file the user picked. |
| `instruction` | no | string, max 2000 — a ONE-OFF steer for THIS run ("looking to the left", "close-up"); never persisted as part of the identity. |

**Two ways in, one pipeline out.** REFERENCE mode edits the supplied/picked image toward the SAVED identity
(`ImageAiService::edit`, metered channel `ai_image_edit`); DESCRIPTION mode generates outright from nothing
but the saved identity text (`ImageAiService::generate` → the shared text→image seam, metered channel
`ai_image_generate`). **The prompt is composed from the PERSISTED module, never from this request** — a
generation always draws whatever the user last saved, so the UI saves the bot first when there are unsaved
edits. Composition order: subject (`descriptor`) → wardrobe → style (`aesthetic`) → prohibitions →
`instruction`. `422` (`bot.visual.nothing_to_generate`) when descriptor, wardrobe, aesthetic AND `instruction`
are ALL empty — a reference anchor alone describes nothing, and this is checked BEFORE any spend.

Both modes ride the Disk module's EXISTING async image machinery (`Disk\Models\DiskAiEdit`, the SAME daily
cap + $-cost-meter gate-before-spend + poll/broadcast contract the Disk preview editor uses — see
`docs/backend/disk-api.md`) rather than a second pipeline — the ONE new thing this endpoint adds is what
happens to the result: it becomes a FILE OWNED BY THE BOT (`fileable_type = 'bot'`), filed by a
Bot-module-owned worker (`GenerateBotVisualJob`) BEFORE the Disk edit is published `done`, so a client woken
by the poll/broadcast always finds the candidate already there. This is the new one-way `Bot → Disk` edge
(Disk never names Bot) — pinned by tests in both directions.

**Response** `202 Accepted` — a `DiskAiEditResource` status row:

```json
{ "data": { "id": "uuid", "status": "queued" } }
```

**Follow it exactly like a Disk AI edit** — `GET /api/disk/ai/image/{id}` (see `docs/backend/disk-api.md`),
the same poll shape and the same private-workspace-channel broadcast. When it reports `done`, the candidate
is ALREADY on the bot; refetch the bot (`GET /bots/{id}`) rather than reading the image off the edit row.
A `failed` status with `error_code: 'safety_rejected'` means the provider's moderation refused the content —
the fix is usually the wardrobe or descriptor, not a retry (moderation is deterministic).

**Errors**: `403` not the bot's owner; `404` cross-workspace/unknown `{bot}`; `422` `mode` missing/invalid,
not exactly one reference source in reference mode, an unowned/foreign `reference_file_id`, or nothing to
generate; `429` the route's own throttle bucket, OR the workspace's daily image cap
(`ai.disk_image_max_per_day`), OR the workspace's monthly $ cap — all three read as a plain 429 and are told
apart client-side by the response shape (rate-limit headers vs. the usage summary's `blocked` flag vs. neither).

### POST /api/bots/{bot}/visual/approve

Promote a candidate to the APPROVED likeness. Owner-only. Synchronous.

| Field | Required | Notes |
|---|---|---|
| `file_id` | yes | uuid. Must be one of the bot's OWN files, AND (checked by the service) one of the current `candidates` — a fresh reference upload is not itself an iteration. |

**Response** `200 OK` — `BotResource`. The candidate stays IN the strip (and becomes un-evictable — see
below) rather than moving anywhere. **Errors**: `403` not owner; `404` cross-workspace/unknown `{bot}`;
`422` (`bot.visual.not_a_candidate`) when `file_id` — which arrives in the BODY, there is no `{file}` route
parameter here — is unknown, foreign, or simply not one of the bot's current candidates.

### DELETE /api/bots/{bot}/visual/candidates/{file}

Delete a candidate and its bytes. Owner-only. Synchronous.

The APPROVED likeness is refused as a candidate to delete (`bot.visual.canonical_locked`) — clearing the
approval is a normal bot save (`visual.canonical_file_id: null`) done FIRST, then the delete; removing it as
a side effect of tidying the strip would silently un-identify the bot. Deleting the (re)generation SOURCE
clears the `reference_file_id` pointer as part of the same operation, so the module never names bytes that no
longer exist.

**Response** `200 OK` — `BotResource`. **Errors**: `403` not owner; `404` cross-workspace/unknown `{bot}`,
or a `{file}` that is not a well-formed uuid (the route constrains only the SHAPE — `whereUuid`); `422`
(`bot.visual.not_a_candidate`) for a well-formed `{file}` that is not one of this bot's current candidates
— including a same-workspace file owned by something else (the service, not the route, is the ownership
gate); `422` (`bot.visual.canonical_locked`) the file is the approved likeness.

### Candidate strip lifecycle (`BotVisualIdentityService::MAX_CANDIDATES = 6`)

A strip the user CHOOSES from, not an unbounded archive: when a 7th candidate arrives, the OLDEST
UNAPPROVED one is evicted and its bytes deleted (the approved likeness is never evicted this way). Every
bot-owned file the module points to (`reference_file_id`, `candidates`, `canonical_file_id`) is a resource
file (`fileable_type = 'bot'`) — deliberately NOT disk-native, so the Disk browser (which lists only
disk-native files) and the "Zasoby" resource tree (which walks only registered resource types) never surface
a bot's iteration strip.

### `BotListResource` — additive readiness fields

| Field | Type | Meaning |
|---|---|---|
| `visual_enabled` | boolean | The module's own toggle — whether its likeness MAY be used in a Generator delegation. |
| `visual_has_image` | boolean | Whether the bot has an APPROVED likeness at all — independent of the toggle above. |

Both together answer "will this bot's face actually appear in a session it authors?" — `enabled && has_image`
is the only combination that does. The card UI reads the two independently (a module that is on with no
approved image, or an approved image with the module off, are both distinct, actionable states — see
`resources/js/next/pages/bots/BotCard.vue`).

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

All three Visual-module endpoints (`POST .../visual/generate`, `POST .../visual/approve`,
`DELETE .../visual/candidates/{file}`) are gated by the SAME rule as `update` — checked via `FormRequest::
authorize()` on each (not the resource controller's `authorizeResource()`), since generating spends real
provider budget and curating rewrites the bot's stored identity.

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

### Assigning a bot that cannot execute (write-time refusal)

`dispatch()` silently declines a bot that fails `canExecuteTasks()` (inactive, or the
task-execution module off). Historically the assignment itself was still accepted, so the
task sat in `to_do` forever with no run, no action row and nothing in the UI to explain it.

`StoreTasksRequest` now **refuses that assignment** — `POST /api/tasks` and
`PUT /api/tasks/{task}` return `422` on `assignee_id`
(`tasks.validation.bot_cannot_execute`) when `assignee_type = 'bot'` and the bot cannot
execute. Two deliberate bounds:

- Only a **change** of assignee is validated. A task already held by a bot whose module was
  switched off afterwards stays fully editable — otherwise deactivating one bot would freeze
  every task assigned to it.
- The runtime guard in `dispatch()` is **unchanged** and still authoritative: assignments made
  outside the request path (workflow `create_task`, approval-reject restore) and bots disabled
  mid-flight are still no-ops rather than errors.

The pickers ask `/bots?can_execute_tasks=1`, so a bot the write path would reject is not
offered in the first place.

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
| `fill_form`       | `fill_form(answers)`        | Only when the task has an attached form. | Persists form answers (create or update). `answers` is a **JSON object string** (`{"<field id>": <answer>}`) — decoded by the tool; a pre-decoded map is also accepted (scripted tests). Malformed JSON or empty `answers` throws an instructive tool-error. Records `form_filled`. |
| `ask_and_wait`    | `ask_and_wait(question)`    | Always.                                  | Posts the question as a bot comment, records `question_asked{question}`, and **ends the run** — task stays `in_progress`, `bot_run_state → waiting`. The next comment with `author_type = 'user'` resumes the run (`trigger = resume`). A bot's own comment never resumes it. |
| `finish`          | `finish(summary?)`          | Always.                                  | Delivers the work. **Approval pipeline attached** → `in_test` + the approval process starts and the task is reassigned to the first approver (identical to the human path); records `submitted_to_test`. **No pipeline** → straight to `done`; records `marked_done`. (`in_test` without a pipeline would mean waiting for a review nobody performs.) Either way it **fails with an instructive tool-error** if the task has an attached form that was never filled (this run or previously) — the agent must call `fill_form` first. The optional `summary` rides along in the action payload. |

> **`finish` also CONFIRMS the task's form.** A task's form submission is a DRAFT while the
> task is worked on and is approved when the task reaches `done` (`TaskObserver::updated`) —
> and that approval is what fires the `form_submitted` workflow trigger. So a bot that fills a
> form and finishes a task with no pipeline completes the whole chain (submission approved →
> trigger fires); with a pipeline the confirmation happens later, when the approval completes
> and the task reaches `done`.
>
> The observer reads the submission **from the DB, not from the relation cache**: a run holds
> ONE long-lived Task instance whose `formSubmission` was eager-loaded as null by
> `BotTaskContextBuilder` before `fill_form` created the row. Trusting that cache left the
> submission a draft forever — task done, workflow never triggered. Pinned by
> `TaskFormSubmissionApprovalTest::test_done_approves_the_submission_even_with_a_stale_relation_cache`
> (unit-level invariant) and
> `WorkflowDispatchTest::test_a_bot_completing_a_task_confirms_its_form_and_fires_form_submitted`
> (end-to-end chain).

> **Strict-mode schema constraint (do not regress).** laravel/ai sends `strict: true` for
> every function, and OpenAI then requires each object in the schema to enumerate its
> `properties`, mark them all `required`, and set `additionalProperties: false`. Two shapes
> break that and **400 the entire run** (not just the tool call):
>
> 1. an **empty** schema — laravel/ai omits `parameters` altogether, and the API answers
>    `Invalid schema for function 'FinishTool': In context=(), 'additionalProperties' is
>    required to be supplied and to be false`;
> 2. a **free-form `object()`** with no declared properties (e.g. a dynamic answers map).
>
> So an argument-free tool declares one `nullable()->required()` property (`finish.summary`,
> and the Approvals tools' `include_comments_count` / `cursor`), and a dynamic map travels
> as a JSON string (`fill_form.answers`). `tests/Unit/Bot/BotToolSchemaTest.php` pins this
> on the ACTUAL mapped payload for every tool in the module — the scripted test agent calls
> tools directly, so nothing else in the suite can catch a provider-invalid schema.

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

### AI cost gate (`ai_bot_task`) — every run is now metered and budget-gated

**Until this, a bot's task-execution run was the one AI spender on the platform that cost real money and
left no trace in the ledger.** `app/modules/Bot` carried no reference at all to `MeteredAiCall` — the
whole R2 sub-stage 4 $ cap (`docs/backend/workspace-ai-usage-api.md`) covered Workflows `@[ai-text]`, Disk
AI edits and Generator sessions, but not the component that runs most often and entirely without a human
watching (the workspace AI-usage page showed real spend on six channels and a silent $0 for however much
bot work had actually run). Fixed by wiring the whole interactive run through the shared meter, on its own
channel:

| | |
|---|---|
| Channel | `ai_bot_task` (`config('ai.meter.pricing.ai_bot_task.per_1k_tokens')`, default `0.005`, env `AI_PRICE_BOT_TASK_PER_1K` — same rate as `ai_text` today, its own channel so the ledger can answer "what did the bots cost while nobody was watching" and be tuned separately, e.g. a cheaper model for the agent loop) |
| **NOT** `ai_bot` | The bot spends on more than the task-execution loop — slot-fill (`docs/backend/generator-sessions-api.md`) bills `ai_text`, visual identity bills the image channels (above). A channel named `ai_bot` would promise to cover all of a bot's spend and quietly not. |
| Gate | `BotTaskRunManager::affordable()` — called **before** the atomic claim in `dispatch()`, same posture as the Generator run manager. An already-over-cap workspace never claims a run slot and never leaves the task stuck `running`. |
| Projection | `BotRunEstimate::forRun($context)` — the agent loop is multi-step (`#[MaxSteps(12)]`) and laravel/ai owns the loop end-to-end, so nothing can gate mid-run. The projection prices a TYPICAL run (`ai.bot_run_projected_steps`, default 3 steps — not the 12-step ceiling, which would refuse runs costing a quarter of the estimate and do it invisibly), modelling that each step re-sends the fixed instruction block plus every earlier step's output — a triangular-number growth, not `steps × one call`. |
| Actor tag | `MeterContext::setActor($bot->getMorphClass(), $bot->getKey())` for the **WHOLE run**, set before the knowledge read (an autonomous run has no `auth()` and no workflow-run context, so without an explicit tag the spend would attribute to nobody) and cleared in a `finally` so it can never outlive the run on a reused worker process. Tagging the whole run — not just the agent's own `prompt()` call — is what makes the bound-knowledge embedding read (`rag` mode, one embedding per run) attribute to the bot too. |
| Refusal | Records `execution_failed` (`bot.budget.run_refused`) against the TASK — a bot that simply stops with nothing in its timeline would be indistinguishable from a broken bot — and makes the task retryable from the inbox. **No comment is posted** (unlike the run-cap hand-over above): a budget ceiling is an operator's concern, not something to explain to everyone reading the task's conversation. |

**Billed PER RUN, not per step — a finding forced by the package, not a design preference.** laravel/ai
owns the tool loop internally: one `prompt()` call executes every step and returns only once the loop
ends, so there is no seam to meter a step at without forking the package's gateway. What makes per-run
billing acceptable rather than merely convenient is that the returned response's `usage` is the SUM over
every step (`ParsesTextResponses::combineUsage`) — the ledger row still carries the run's real total
tokens. **Per-run recording loses no accuracy about the money, only about the moment**: the workspace
learns the cost when the run ends, not while it runs. The gate therefore has to ask its question ONCE, up
front, about the whole run (`BotRunEstimate`) — the second, authoritative gate-before-spend still lives
inside `MeteredAiCall::meter()` itself, which re-checks the budget right before invoking the agent.

**Residual risk, accepted rather than engineered away:** a run whose real cost exceeds its projection can
finish slightly over the cap — the next run is simply refused, and the ledger stays honest about what
happened. Closing this fully would mean metering inside laravel/ai's own loop, which the package does not
expose a seam for.

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
- **Approvals still gate advancement — where an approval exists.** With a pipeline attached,
  `finish` stops at `in_test` and starts the real approval process; an injected instruction
  cannot bypass that human or AI review to reach `done`. With **no pipeline attached** there
  is no review to bypass: `finish` completes the task itself (that is the point of attaching
  a pipeline). So the review guarantee comes from the TASK's configuration, not from the bot
  — attach a pipeline to any task whose output must be checked before it counts as done.

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

**SUPERSEDED, not replaced, by the real Knowledge module (`app/modules/Knowledge/`).** The
per-bot `knowledge` column described above is what this section originally documented, and it
remains fully functional exactly as written — no field here changed meaning or was removed. What
changed is precedence: a bot may additionally be bound to a real, shared `KnowledgeBase`
(`PUT /bots/{bot}/knowledge-binding`), and **once such a binding exists it wins outright** —
`BotTaskContextBuilder` reads the compiled knowledge base and never falls back to (or merges
with) this column, even if `knowledge.enabled` is still `true` on the bot's own record. Only a bot
with NO binding reads this legacy module, exactly as described above — see
`docs/backend/knowledge-api.md` → "Bot binding" and
[ADR-0045](../decisions/ADR-0045-knowledge-consumption-data-erasure.md) D8. `POST
/bots/{bot}/knowledge/migrate` lifts this column's entries into a real base and binds it in one
step (see the same section). This column stays as a fully-supported fallback for now; a future
stage of the roadmap may deprecate it once every workspace has migrated (see
`docs/product/plan-dzialania.md`) — it is not scheduled for removal by this batch.

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
- `app/modules/Bot/Services/BotTaskRunManager.php` — atomic claim / run-state machine / cap / hand-over / the `ai_bot_task` gate-before-claim (`affordable()`)
- `app/modules/Bot/Support/BotRunEstimate.php` — the pre-run $ projection (`CHANNEL = 'ai_bot_task'`), priced through the same `config('ai.meter.pricing')` table the meter bills against
- `app/modules/Bot/Services/BotTaskInteractionService.php` — the tools' side-effects (single run)
- `app/modules/Bot/Services/BotTaskContextBuilder.php` — read-context injection
- `app/modules/Variables/Contracts/MeteredAiCall.php`, `Support/MeterContext.php` — the shared gate/spend/actor-tag seam every `ai_bot_task` call goes through; see `docs/backend/workspace-ai-usage-api.md` for the ledger-wide contract
- `tests/Feature/BotAiMeteringTest.php` — the gate-before-claim, the per-run actor tag (including the knowledge embedding), and the projection
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

**R2 sub-stage 3 — Generator session delegation** (see `docs/backend/generator-sessions-api.md` §
"Bot-author delegation overlay" for the full contract, `docs/decisions/
ADR-0036-bot-delegation-generation-sessions.md` for the design record):

- `app/modules/Bot/Http/Controllers/BotSessionDelegationController.php` — the `store`/`destroy` endpoints
- `app/modules/Bot/Http/Requests/DelegateBotSessionRequest.php` — `auto_generate` (opt-in) + `fill_mode` (`gaps`/`fresh`, default `gaps`), authorizes via the session's `update` ability
- `app/modules/Bot/Enums/SlotFillMode.php` — the click-time fill choice (which slots the autonomous fill offers, and what it asks of the model)
- `app/modules/Bot/Services/BotVoiceComposer.php` — composes persona/style/dictionary/phrases/prohibitions into ONE opaque voice directive (mirrors, does not share, `BotTaskExecutionAgent::instructions()`)
- `app/modules/Bot/Services/BotSlotFillService.php`, `Agents/BotSlotFillAgent.php` — the autonomous, metered, defensively-parsed slot-fill call
- `app/modules/Generator/Services/SessionDelegationService.php` — the bot-agnostic Generator-side seams this controller calls
- `app/modules/Variables/Support/AiVoiceContext.php` — the ambient voice-directive holder (mirrors `MeterContext`)
- `tests/Feature/BotSessionDelegationTest.php`, `tests/Feature/BotModuleBoundaryTest.php`, `tests/Feature/ShotListVoiceTest.php`

**Visual identity module ("Wygląd")** (see "Visual identity module ('Wygląd')" above for the full endpoint
contract, `docs/backend/generator-sessions-api.md` § "Frozen character visual identity" for how a delegation
freezes it onto a session, `docs/decisions/ADR-0042-character-visual-identity.md` for the design record):

- `app/modules/Bot/Models/Bot.php` — `visual`/`visualEnabled()`/`visualIdentity()`/`visualImages()` (the `MorphMany` resource-file relation)
- `app/modules/Bot/Services/BotVisualIdentityService.php` — generate/attach/approve/remove, candidate-strip eviction, prompt composition
- `app/modules/Bot/Http/Controllers/BotVisualController.php` — `generate`/`approve`/`destroyCandidate`
- `app/modules/Bot/Http/Requests/GenerateBotVisualRequest.php`, `ApproveBotVisualRequest.php`, `DestroyBotVisualCandidateRequest.php`
- `app/modules/Bot/Rules/BotVisualFile.php` — file-ownership validation (bot-owned, with an optional disk-native allowance for the reference)
- `app/modules/Bot/Enums/BotVisualMode.php` — `reference`/`description`, and which metered channel each spends on
- `app/modules/Bot/DTOs/BotVisualGenerationDTO.php`
- `app/modules/Bot/Jobs/GenerateBotVisualJob.php` — the worker; rides `Disk\Services\ImageAiService::process()` with a materialization hook, never re-implements the image pipeline
- `app/modules/Disk/Services/ImageAiService.php` — `prepare()`/`process()`/`produce()` (the shared, now RESUMABLE async image machinery both Disk and Bot ride), `generate()` (the null-image text→image mode)
- `app/modules/Disk/Services/OpenAiImageEditClient.php` — `input_fidelity` now actually sent on every edit (fixed defect); the `size` parameter seam
- `app/modules/Disk/Enums/DiskAiEditStatus.php` — the `safety_rejected` stored-only terminal state + `wireStatus()`/`errorCode()`
- `app/modules/Generator/Support/SessionVisualIdentity.php` — the frozen overlay's normalized VO + security boundary (prose projections, fence-marker scrub reuse)
- `app/modules/Generator/Services/SessionIdentityImageStore.php` — the per-character frozen-bytes store
- `tests/Feature/BotVisualModuleTest.php` — the module's read/write/ownership/list-flags contract
- `tests/Feature/BotVisualIdentityTest.php` — generate/curate endpoints, moderation, candidate eviction, the resumable-retry + throwing-hook hardening
- `tests/Feature/GeneratorVisualIdentityTest.php` — the delegation freeze + render-time consumption (Generator-side; see `docs/backend/generator-sessions-api.md`)

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

  **Blind spot to respect:** because the scripted agent invokes tools directly, it never
  serializes the tool SCHEMAS for a provider — so a schema OpenAI rejects passes every
  feature test here and only fails in production (this is exactly how the `FinishTool`
  strict-mode 400 shipped). `tests/Unit/Bot/BotToolSchemaTest.php` covers that gap by
  asserting the mapped OpenAI payload of every tool in the module. Scripted steps may pass
  `answers` either as the decoded map (as above) or as the JSON string the provider sends.

Reuse this trait for any future agent flow: structured-output agents can usually use
`Agent::fake()` directly; a multi-step tool-calling agent should follow the
`scriptBotRun`/`ScriptedBotExecutionAgent` pattern instead.
