# ADR-0007 — Bot (AI Character) module design decisions

**Date:** 2026-06-30 (created), updated 2026-07-03 for B4–B6
**Status:** Accepted
**Module:** Bot (`app/modules/Bot/`), Tasks, Comments, Approvals

---

## Context

The Bot module (Etap 3) was implemented across six reviewed batches:
- Batch 1: Bot CRUD + persona (text module).
- Batch 2: Polymorphic User|Bot task-assignee and comment-author + bot task-execution
  job/agent (one-shot) + first AI fake test harness.
- Batch 3: Bot as a NAMED AI approver in approval pipeline stages.
- Batch 4: Interactive multi-turn task execution (tools, run-state machine, run cap,
  resume/revision triggers) — supersedes the Batch 2 one-shot execution model.
- Batch 5: Optional tool registry (`fetch_url`, `web_search`, `generate_file`,
  `read_attachments`) with SSRF hardening.
- Batch 6: Restructure into 5 explicit modules (text, task-execution, knowledge, visual,
  audio), the knowledge module, `voice → audio` rename, `knowledge_source` removal, and
  the general-info `icon` field.

During implementation several non-trivial design choices arose. This record documents
those choices and the reasoning behind each. **Decisions #4 and #6 below describe the
Batch 1–3 mechanisms as originally built; both were SUPERSEDED by Batch 4 and are marked
accordingly rather than rewritten, so this record stays an accurate history.** See
decisions #8 and #11 for the mechanisms that replaced them.

---

## Decisions

### 1. Polymorphic actor: Option A (polymorphic columns) over additive actor columns

**Decision:** Tasks and Comments gained a polymorphic actor (User|Bot) via the existing
morph-to relation pattern:
- `tasks.assignee_type` / `tasks.assignee_id` (the `assignee` morphTo relation).
- `comments.author_type` / `comments.author_id` (the `author` morphTo relation).

Morph aliases (`'user'` and `'bot'`) are registered in the respective module service
providers. Both use `Relation::enforceMorphMap()`.

**Alternatives rejected:**
- **Option B — additive `bot_assignee_id` / `bot_author_id` columns:** Would leave two
  nullable FK columns on every row, requiring consumers to check both every time. Adding a
  third actor type later would mean a third column per table. Rejected for data-model
  fragility.
- **Option C — separate junction table:** Disproportionate complexity for a two-type
  system where the actor is single-valued per row.

**Rationale:** The morphTo pattern is the established Eloquent convention for polymorphic
single-value ownership. The codebase already uses it (e.g. `FormSubmission.submittable`).
Morph aliases keep stored strings stable regardless of class refactors.

---

### 2. Additive, back-compat API contract on TaskResource

**Decision:** The task API resource emits BOTH the legacy field and the new polymorphic
field in parallel:

```
assigned   — UserResource | null  (null when bot-assigned; back-compat)
assignee   — { type, id, name, email?, avatar, is_bot } | null  (new polymorphic)
```

The `Task` model implements this via a virtual `assigned_id` accessor (returns the
assignee id only when `assignee_type === 'user'`, null otherwise) and a virtual setter
(writing `assigned_id` maps to `assignee_type='user', assignee_id=<value>`). The real
column is the polymorphic pair; `assigned_id` is no longer a DB column.

**Write path:** `StoreTasksRequest` accepts both the legacy `assigned_id` field (still
required unless `assignee_type` is supplied) and the new `assignee_type` + `assignee_id`
pair. Both null clears the assignee.

**Rationale:** Existing frontend consumers of `TaskResource` (both the legacy SPA under
`resources/js/modules/tasks` and the `next` frontend) relied on `assigned` being either
a User resource or null. Nullable-izing it without guarding the legacy SPA caused a null
pointer when the SPA tried to render the assignee. Adding `assignee` as a separate additive
field decouples migration: next consumers move to `assignee`; legacy consumers keep reading
`assigned` unchanged.

---

### 3. Refactor lesson — null-guarding shared resource fields

**Decision (recorded as a rule, not a new choice):** When a previously-required field on a
shared API resource is nullable-ized, BOTH the legacy SPA consumer
(`resources/js/modules/tasks`) AND the `next` consumer must be null-guarded in the same
changeset. Skipping the legacy guard causes silent runtime errors in production for any
tasks that hit the new nullable state before the frontend is updated.

**Trigger:** Changing `assigned` from guaranteed-UserResource to `null`-able (because a
bot-assigned task has no User assignee) required null checks in `TaskDetailsPanel.vue`
(legacy SPA) and corresponding `next` components.

**Implication for future work:** Before nullable-izing any field on a resource shared
between legacy and next frontends, audit both consumers and ship the null-guards in the
same PR.

---

### 4. AI test seam: `FakesBotExecutionAgent` trait over per-test mocking — [SUPERSEDED by #8]

> **Status: superseded by Batch 4.** This decision describes the Batch 1–3 mechanism,
> which worked only for the one-shot execution agent. It could not extend to the Batch 4
> interactive, multi-step, tool-calling agent. See decision #8 for the current mechanism.
> Kept here as history.

**Original decision:** A shared `Tests\Concerns\FakesBotExecutionAgent` trait wrapped
`Agent::fake([...])` (Laravel AI's first-class fake gateway) for the entire test suite,
exposing `fakeBotExecution()`, `failBotExecution()`, `fakeBotExecutionUsing()`, and
`fakeApprovalEvaluation()`.

**Original rationale:**
- Every feature test that dispatches `BotTaskExecutionJob` or triggers approval evaluation
  needs the same setup. Centralizing it avoids duplication and keeps the seam stable if
  the underlying agent class is renamed.
- `Agent::fake()` binds a fake gateway in the container, so the FULL application path
  runs — dispatch, in_progress, comment, form fill, in_test, bot_actions — without ever
  reaching a real provider.
- `fakeApprovalEvaluation()` covers BOTH generic AI stages and named-bot approver stages
  because both use `ApprovalEvaluationAgent`.

**Why it broke for Batch 4:** `BotTaskExecutionAgent` became a multi-step, tool-calling
agent (`post_comment → fill_form → ask_and_wait → finish`). Laravel AI's
`FakeTextGateway` accepts an agent's declared tools but never invokes them, and its fake
closure only receives `(prompt, attachments, provider, model)` — not the agent — so there
is no way to script a tool-call sequence through `Agent::fake()`. `fakeApprovalEvaluation()`
was unaffected (`ApprovalEvaluationAgent` has structured output, no tool loop) and remains
in the trait unchanged.

---

### 5. Bot as named AI approver: `ApproverType::Bot` enum value + manual bot relation

**Decision:** Pipeline stages gained a third `approver_type` value (`bot`) in the
`ApproverType` enum. The `approval_stages.approver_id` column — previously the User FK —
is reused as a UUID that can point to either a User or a Bot, discriminated by
`approver_type`. No new FK column was added.

The `ApprovalStage` model exposes two explicit relations:
- `approver()` → `BelongsTo(User)` — the legacy user relation (null for ai/bot stages).
- `approverBot()` → `BelongsTo(Bot)` — resolved only for bot stages.

`ApproverType::isAutomated()` returns true for both `ai` and `bot`, so
`ProcessAiApprovalJob` is dispatched for both stage types. The job resolves the bot only
when `isBotApprover()` and passes it to `ApprovalEvaluationAgent`; the bot's persona is
injected into the agent's system instructions as a `BOTPERSONA` block, coloring the
verdict and note. Generic AI stages pass `null`; the instructions are byte-for-byte
unchanged.

**Rationale for no FK column:** The `approver_id` column already exists and stores a UUID.
Adding a parallel `bot_approver_id` column would require a migration, add nullability
complexity for every row, and introduce a structural inconsistency (two actor-id columns
with overlapping semantics). The `approver_type` discriminator is the single source of
truth; the two explicit relations (`approver`, `approverBot`) follow the same pattern as
`Task.assigned` / `Task.assignee`.

**Human decision guard:** `ApprovalProcessPolicy` (and the `canDecide` flag in the review
drawer) blocks human HTTP decisions on automated (`ai` or `bot`) processes. Bots evaluated
by an automated stage cannot be overridden over HTTP.

---

### 6. Idempotency guard on bot task dispatch — [SUPERSEDED by #9]

> **Status: superseded by Batch 4.** This decision describes the Batch 1–3 mechanism,
> which assumed a task is executed AT MOST ONCE. Batch 4 deliberately allows MANY runs per
> task (initial + resumes + revisions), so a "one `task_started` row ever" guard became
> incompatible with the feature itself. See decision #9 for the current mechanism. The
> migration that created this index (`2026_06_25_000300_...`) was followed by one that
> drops it (`2026_06_25_000401_...`). Kept here as history.

**Original decision:** `BotTaskExecutionService::maybeDispatch()` used
`BotAction::firstOrCreate()` on the `(bot_id, task_id, type=task_started)` triplet —
backed by a partial unique index on the `bot_actions` table — as an atomic "claim" before
dispatching the job. If `firstOrCreate` found an existing row
(`wasRecentlyCreated === false`), dispatch was skipped.

**Original rationale:** Without this guard, concurrent task updates (e.g. the same task
saved twice in rapid succession) could dispatch two jobs for the same (bot, task) pair.
The partial unique index made the insert atomic at the DB level.
`BotTaskExecutionJob::tries = 1` (no retry) remains intentional in the current design too —
an agent failure is recorded and exposed, not silently retried.

---

### 7. Bot always leaves a comment

**Decision:** `BotTaskExecutionJob` treats an empty `comment` from the agent as a failure
(`execution_failed`) rather than silently advancing the task. The comment is always posted
as a bot-authored Comment before any form submission or status transition. This is not
configurable.

**Rationale:** The comment is the primary audit trail of the bot's reasoning. A bot that
produces no actionable content has effectively failed its task. Advancing to `in_test`
without a comment would leave approvers with no context. An empty-comment failure keeps
the task in `in_progress` so the human creator can investigate and reassign.

> **Note (Batch 4 evolution):** With interactive execution, `post_comment` became a tool
> the agent calls explicitly rather than a mandatory structured-output field the job
> validates. There is no longer a hard "must comment before anything else" rule enforced
> by the job — the agent chooses when to comment, any number of times. The spirit is
> preserved differently: `finish` cannot succeed with an unfilled attached form (decision
> in the `finish()` tool), and a run that produces no tool calls at all simply ends with
> the task unchanged (not a hard failure, but also not silent progress).

---

### 8. Interactive execution seam: `scriptBotRun()` + `ScriptedBotExecutionAgent` double (Batch 4)

**Decision:** Rather than extending `Agent::fake()`, a dedicated test double
(`Tests\Support\ScriptedBotExecutionAgent`) is bound into the container in place of
`BotTaskExecutionAgent` for interactive-run tests. It is a standalone class (not a
subclass of the real agent — `prompt()` has a strict `AgentResponse` return type that a
scripted double doesn't need to honor) that resolves the **same tool set** the real agent
would (via the shared `BotTaskToolFactory`) and invokes a **scripted sequence** of
`[toolName, arguments]` pairs directly against the real `BotTaskInteractionService`. A
step naming a tool not exposed for the task's shape (e.g. `fill_form` with no form
attached) is silently skipped; the script stops early once a terminal tool fires.

**Rationale:** See decision #4's "why it broke" note — `Agent::fake()` cannot invoke
tools. Rather than fighting the framework or mocking at the tool level (which would test
nothing about tool wiring), `ScriptedBotExecutionAgent` exercises the REAL tools and the
REAL interaction service, so a test proves the actual side-effects (comment posted, form
persisted, task transitioned, run-state released) happen correctly for a given script —
only the LLM decision-making step is replaced. `BotTaskToolFactory` is factored out
specifically so the real agent and the scripted double are guaranteed to expose an
identical tool set (one place decides "what tools does this run have").

**`fakeApprovalEvaluation()` is unaffected** — `ApprovalEvaluationAgent` still has no tool
loop, so `Agent::fake()` continues to work for it unchanged.

**Reuse guidance:** A future structured-output (no-tool-loop) agent should use
`Agent::fake()` directly. A future multi-step, tool-calling agent should follow the
`ScriptedBotExecutionAgent` pattern: factor its tool-building into a shared factory, add a
scripted double with the same constructor shape, and bind it via a trait helper analogous
to `scriptBotRun()`.

---

### 9. Run-state machine replaces the partial-unique-index idempotency guard (Batch 4)

**Decision:** Concurrency-safety for bot dispatch moved from "at most one `task_started`
row ever" (a DB-level partial unique index — see superseded decision #6) to an explicit
run-state machine on `tasks.bot_run_state` (`idle \| running \| waiting`) with a
monotonic `tasks.bot_runs_used` counter, claimed via a single atomic conditional
`UPDATE ... WHERE bot_run_state IN ('idle','waiting') AND bot_runs_used < cap`.

**Rationale:** Batch 4 requires MANY runs per task by design (initial + every resume +
every revision), so "at most once" is no longer the invariant to protect — "at most one
run ACTIVE at a time, and never past the cap" is. A conditional `UPDATE` guarded by the
current state and the cap, relying on Postgres's row lock during the update, gives the
same atomicity guarantee the old unique index gave, but for a value that legitimately
changes many times over a task's life rather than being written once. The old index was
therefore dropped (migration `2026_06_25_000401_...`) rather than kept alongside the new
mechanism — running both would have re-introduced the "only one run ever" constraint the
feature explicitly needed to remove.

**Consequence:** `BotActionType::TaskStarted` (`task_started`) is no longer a
uniqueness-bearing event — it now fires once per run and carries a `{run, trigger}`
payload so the audit log and any future UI can distinguish repeated runs on the same task.

---

### 10. Run cap + hand-over, not an unbounded interactive loop (Batch 4)

**Decision:** `config('ai.max_runs_per_task')` (default 5) hard-caps total runs per task.
When a dispatch attempt finds the cap reached, the bot posts an explicit hand-over comment,
records a `handed_over` action, and the task is left exactly where it is (no further
automatic advancement).

**Rationale:** An interactive bot that can ask questions and get resumed could in
principle loop indefinitely (a human keeps replying, the bot keeps asking, or a task
keeps getting rejected and revised). A hard cap bounds both AI provider cost and the
worst-case time a task can spend cycling through the bot before a human must take over
explicitly. Handing over with a visible comment (rather than silently stalling) keeps the
task's state legible — nobody has to infer from a stuck `in_progress` task with no recent
activity that the bot gave up.

---

### 11. Tool registry: enum-driven catalog + granted ∩ available resolution (Batch 5)

**Decision:** `BotTool` is a PHP backed enum (`fetch_url`, `web_search`, `generate_file`,
`read_attachments`) — the single source of truth for tool ids, consulted by request
validation (`Rule::in(BotTool::ids())`), the discovery endpoint
(`GET /bots/tool-registry`), and the actual tool-building path
(`BotToolRegistry::grantedAvailableIds()` / `BotTaskToolFactory`). A tool is exposed to
the agent only when it is BOTH granted (`task_execution.tools[]`) and available
(`BotToolRegistry::isAvailable()` — e.g. `web_search` needs `AI_SEARCH_API_KEY`).

**Rationale:** The requirement was "never show a dead option in the editor." Deriving
availability from the SAME registry class the agent's tool-building path uses (rather
than, say, a hand-maintained list in the frontend, or a separate backend check) makes it
structurally impossible for the discovery endpoint and the actual agent capability to
drift apart — there is exactly one method (`isAvailable()`) that decides both.

**Alternative rejected:** A boolean flag per tool directly on `task_execution` (e.g.
`can_fetch_url: true`) was rejected in favor of the `tools: string[]` array + registry
pattern, because it scales to N future tools without a schema change per tool, and the
"is this tool currently usable at all" question (environment-dependent) is orthogonal to
"did this bot request it" (bot-configuration-dependent) — conflating them into one flag
would lose the distinction the editor needs to explain an unavailable-but-still-granted
tool to the user (see the frontend's "unavailable" badge treatment).

---

### 12. SSRF hardening via canonicalize-resolve-pin, not a blocklist (Batch 5)

**Decision:** `SafeUrlGuard` does not maintain a blocklist of "known bad" hosts. Instead
it: canonicalizes every numeric host encoding to a real IP, resolves the host to its
actual IP address(es), validates those IPs against the standard private/reserved/loopback
ranges, and then **pins the single validated IP into the actual connection** via
`CURLOPT_RESOLVE` — re-validating and re-pinning on every redirect hop.

**Rationale:** A blocklist of hostnames is trivially bypassed (any of dozens of numeric
encodings of `127.0.0.1`, a redirect to an internal host, or plain DNS rebinding between
"validate" and "connect" time). Validating the RESOLVED IP (not the hostname string)
against known-unsafe ranges closes the numeric-encoding bypass; pinning that exact IP into
the cURL connection closes the DNS-rebinding TOCTOU window, which a "validate then fetch"
naive implementation would not. Streaming the response with a hard byte cap additionally
bounds worst-case memory/bandwidth from a malicious or oversized response.

**Accepted residual risk:** documented explicitly in `docs/backend/bots-api.md` under
"Security — accepted residual risks" — trust in a single DNS lookup at validation time,
and no content-level judgment on what a legitimately-fetched public host serves. This
residual risk was reviewed and accepted rather than engineered further (e.g. no
allowlist-only mode was requested).

---

### 13. Prompt injection: accept and bound, don't attempt to filter (Batch 5)

**Decision:** No content-based prompt-injection filtering was added for text entering the
agent's context via `fetch_url` or `read_attachments`. Instead, the mitigation is
architectural: the agent's only actions are the task's own interaction tools (comment,
fill form, ask/wait, finish) plus whichever registry tools are granted — all scoped to the
CURRENT task, with no cross-task or cross-workspace reach, and `finish` still routes
through the ordinary approval gate rather than reaching `done` directly.

**Rationale:** Reliable prompt-injection filtering (distinguishing "instructions in
fetched content" from "the model correctly following legitimate content") is an open
research problem; a heuristic filter would give false confidence without closing the gap.
Bounding the BLAST RADIUS — what the compromised agent could actually do even in the
worst case — was judged the more defensible line: even a fully "hijacked" run cannot
touch another task, another workspace, or bypass human/AI review to complete a task
unilaterally.

**Accepted residual risk:** an injected instruction could still cause the bot to post a
misleading comment, adopt an unintended tone, or exhaust run budget within the current
task — but not escalate beyond it. Reviewed and accepted.

---

### 14. Knowledge as a per-bot enable-able JSON module, not a standalone Knowledge module (Batch 6)

**Decision:** `knowledge` is a JSON column on `bots` (`{enabled, entries: [{title,
content}]}`), following the same "explicit enable + inert-when-off" pattern as
`task_execution`. It is NOT yet a first-class, app-wide Knowledge module (e.g. shared
knowledge bases usable by multiple bots, or referenced by non-bot features).

**Rationale:** Scoping knowledge per-bot matches the current need (Etap 3 bots) without
speculatively building shared/cross-cutting knowledge infrastructure ahead of a concrete
second consumer. Keeping it as a JSON column (consistent with `task_execution`, `visual`,
`audio`) avoids a new table + relations for a feature whose final shape (should knowledge
be shared across bots? searchable? versioned?) is not yet decided.

**Forward note:** if a genuine second consumer of "knowledge entries" appears outside the
Bot module, that is the trigger to extract a standalone app-wide Knowledge module (through
planning mode) — mirroring the "extract only when a third consumer appears" rule already
used for `FormViewer` (ADR-0006 §2). Until then, this per-bot JSON module is intentional,
not a shortcut to be immediately generalized.

---

### 15. `voice → audio` rename and `task_execution.knowledge_source` removal (Batch 6)

**Decision:** The empty placeholder column `voice` was renamed to `audio` (straight
column rename; both are read-only `null` placeholders with no logic, so no data
migration concern). The `task_execution.knowledge_source` config key was removed — the
new `knowledge` module replaces its purpose. An old client still sending
`knowledge_source` has it silently ignored (not validated, not persisted, no error).

**Rationale for the rename:** `audio` is the accurate name for the eventual module
(voice/audio generation or synthesis); `voice` was a placeholder name chosen before the
5-module structure was finalized. Renaming while the column is still an inert placeholder
(no consumer depends on its shape) is the cheapest possible time to correct the name — a
later rename after `audio`/`voice` has real read/write logic and consumers would be far
more invasive.

**Rationale for silently ignoring `knowledge_source` rather than erroring:** the field was
never load-bearing (it fed nothing — B2/B3 execution never read it), so a hard validation
error for a stale client sending it would be disproportionate. Silently dropping it is a
deliberate compromise: an old frontend build won't break, but the field also does nothing,
which is documented explicitly here and in `docs/backend/bots-api.md` so it is not
mistaken for a bug in the future.

---

### 16. General-info `icon` field (Batch 6)

**Decision:** Bots gained a nullable `icon` field (max 100 chars), rendered in the
always-visible general-info header of the editor (alongside name/status/description),
separate from and unrelated to the 5 content modules.

**Rationale:** Mirrors the icon pattern already used elsewhere (Forms, Approval
pipelines/stages) for quick visual identification in lists and cards. Placing it in the
general-info band (not inside any of the 5 modules) reflects that it is metadata ABOUT the
bot as an entity, not part of its behavior configuration — consistent with `name` and
`description` sitting in the same band.

---

## Related files

- `app/modules/Bot/` — module root
- `app/modules/Approvals/Enums/ApproverType.php` — Bot case + isAutomated()
- `app/modules/Approvals/Http/Requests/StoreApprovalPipelineRequest.php` — bot approver validation
- `app/modules/Approvals/Models/ApprovalStage.php` — approverBot relation
- `app/modules/Approvals/Agents/ApprovalEvaluationAgent.php` — personaSection() for bot
- `app/modules/Tasks/Models/Task.php` — polymorphic assignee + back-compat accessor/setter + revision trigger wiring
- `app/modules/Comments/Models/Comment.php` — polymorphic author
- `app/modules/Comments/Services/CommentService.php` — resume trigger wiring (human-reply loop guard)
- `app/modules/Bot/Services/BotTaskRunManager.php` — run-state machine, claim, cap, hand-over (Batch 4)
- `app/modules/Bot/Services/BotTaskExecutionService.php` — trigger decisions (Batch 4)
- `app/modules/Bot/Services/BotTaskInteractionService.php` — tool side-effects (Batch 4)
- `app/modules/Bot/Services/BotTaskContextBuilder.php` — context injection incl. knowledge (Batch 4/6)
- `app/modules/Bot/Tools/` — always-present interaction tools (Batch 4)
- `app/modules/Bot/Tools/Registry/`, `app/modules/Bot/Services/BotToolRegistry.php` — optional tools (Batch 5)
- `app/modules/Bot/Tools/Support/SafeUrlGuard.php` — SSRF hardening (Batch 5)
- `database/migrations/2026_06_25_000500_restructure_bot_modules.php` — voice→audio, knowledge column (Batch 6)
- `tests/Concerns/FakesBotExecutionAgent.php` — test seam (Batch 4 mechanism change)
- `tests/Support/ScriptedBotExecutionAgent.php` — scripted multi-step test double (Batch 4)
- `docs/backend/bots-api.md` — full backend API reference
