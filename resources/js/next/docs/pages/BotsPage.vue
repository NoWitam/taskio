<script setup lang="ts">
// Gallery: Bot (AI Character) module — module overview, API surfaces, polymorphic
// actor contract, interactive task-execution flow, tool registry, knowledge module,
// bot-as-approver pattern, and generation-session delegation. Documents the
// IMPLEMENTED behavior of app/modules/Bot/ (covers B1–B6 + R2 sub-stage 3) — not
// planned behavior.
//
// Sections:
//   1. Module overview & concepts (5 modules)
//   2. Bot CRUD API (endpoints + request/response shapes)
//   3. BotStatus + BotActionType enums
//   4. Polymorphic actor (Task assignee + Comment author)
//   5. Task write request — legacy vs polymorphic paths
//   6. Interactive bot task-execution flow (B4)
//   7. Tool registry (B5) + security
//   8. Knowledge module (B6)
//   9. Test seams (structured-output fake vs scripted multi-step double)
//   10. Bot as named AI approver
//   11. Bot as generation-session author (R2 sub-stage 3 — Generator delegation)
//   12. Editor UI (5-module drawer)
//   13. Refactor lesson: null-guarding shared resource fields
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Alert from '../../ui/feedback/Alert.vue';

// ── Bot CRUD endpoints ─────────────────────────────────────────────────────
const botEndpointRows: ApiRow[] = [
  { name: 'GET /bots',             type: '?search=&cursor=',    description: 'List bots (BotListResource[]). Cursor-paginated, 8/page, newest first. ?search matches name/description; ?cursor from meta.next_cursor.' },
  { name: 'POST /bots',            type: 'BotWritePayload',     description: 'Create a bot. Returns BotResource.' },
  { name: 'GET /bots/{id}',        type: '—',                   description: 'Fetch one bot (BotResource, creator loaded).' },
  { name: 'PUT /bots/{id}',        type: 'BotWritePayload',     description: 'Update a bot. Creator-only (403 otherwise).' },
  { name: 'DELETE /bots/{id}',     type: '—',                   description: 'Soft-delete a bot. Creator-only.' },
  { name: 'POST /bots/{id}/restore', type: '—',                 description: 'Restore a soft-deleted bot. Creator-only. Resolves via Bot::withTrashed().' },
  { name: 'GET /bots/tool-registry', type: '—',                 description: '(B5) Discovery: every registry tool id + its current availability. Registered before {bot} so the literal segment is not swallowed.' },
  { name: 'GET /bots/{bot}/actions', type: '?type=&cursor=',    description: 'Cursor-paginated action audit log for a single bot (15/page, newest first). ?type filters by BotActionType.' },
  { name: 'GET /tasks/{task}/bot-actions', type: '?cursor=',   description: 'Action audit log for a single task. Resolves via Task::withTrashed().' },
];

// ── BotWritePayload fields ─────────────────────────────────────────────────
const botWritePayloadRows: ApiRow[] = [
  { name: 'name',                    type: 'string',          description: 'Required, max 255.' },
  { name: 'status',                  type: "'draft' | 'active' | 'disabled'", description: 'Optional; defaults to draft on create.' },
  { name: 'description',             type: 'string | null',   description: 'Optional, max 2500.' },
  { name: 'icon',                    type: 'string | null',   description: '(B6) General-info icon identifier, max 100. Shown in the always-visible editor header.' },
  { name: 'persona',                 type: 'string',          description: 'Required, max 10 000. Shapes the AI voice across all interactions.' },
  { name: 'style',                   type: 'string | null',   description: 'Optional, max 5000.' },
  { name: 'dictionary',              type: 'string[]',        description: 'Preferred terms (max 255 each).' },
  { name: 'phrases',                 type: 'string[]',        description: 'Preferred phrases (max 255 each).' },
  { name: 'prohibitions',            type: 'string[]',        description: 'Banned terms/actions (max 255 each).' },
  { name: 'task_execution',          type: 'object | null',   description: 'Omit or send null to leave the stored value unchanged.' },
  { name: 'task_execution.enabled',  type: 'boolean',         description: 'When true and status=active, bot auto-executes assigned tasks.' },
  { name: 'task_execution.tools',    type: 'string[]',        description: 'Registry tool ids to grant (fetch_url, web_search, generate_file, read_attachments). Validated against BotTool::ids() — unknown id → 422.' },
  { name: 'knowledge',               type: 'object',          description: '(B6) { enabled, entries }.' },
  { name: 'knowledge.enabled',       type: 'boolean',         description: 'Entries are injected into execution context ONLY when true.' },
  { name: 'knowledge.entries',       type: '{title,content}[]', description: 'Max 50 entries; title max 255, content max 5000.' },
];

// ── BotResource fields ─────────────────────────────────────────────────────
const botResourceRows: ApiRow[] = [
  { name: 'id',               type: 'string',                    description: 'UUID.' },
  { name: 'name',             type: 'string',                    description: '' },
  { name: 'status',           type: "'draft' | 'active' | 'disabled'", description: '' },
  { name: 'description',      type: 'string | null',             description: '' },
  { name: 'icon',             type: 'string | null',             description: '(B6) General-info icon identifier.' },
  { name: 'persona',          type: 'string',                    description: '' },
  { name: 'style',            type: 'string | null',             description: '' },
  { name: 'dictionary',       type: 'string[]',                  description: '' },
  { name: 'phrases',          type: 'string[]',                  description: '' },
  { name: 'prohibitions',     type: 'string[]',                  description: '' },
  { name: 'task_execution',   type: '{ enabled, tools } | null',  description: 'knowledge_source key was REMOVED in B6 (silently ignored if sent).' },
  { name: 'knowledge',        type: '{ enabled, entries: {title,content}[] }', description: '(B6) NOT a bare array — see the Knowledge module section.' },
  { name: 'visual',           type: 'null',                      description: 'Placeholder. Read-only; no logic yet.' },
  { name: 'audio',            type: 'null',                      description: '(B6) Renamed from voice (column rename). Placeholder, read-only.' },
  { name: 'creator',          type: 'Creator | null',            description: 'Discriminated union: user | workflow_run (automation) | bot. A Bot is only ever created by an authenticated human today, so in practice this is always the user shape. See creator.ts / docs/backend/creator-attribution.md.' },
  { name: 'is_owner',         type: 'boolean',                   description: 'True only for a HUMAN creator match (isOwnedBy) — presentational. Gate actions on can_be_edited/can_be_deleted, not this.' },
  { name: 'can_execute_tasks', type: 'boolean',                  description: 'True when status=active AND task_execution.enabled=true.' },
  { name: 'can_be_edited',    type: 'boolean',                   description: 'Auth user may call PUT — the owner, or (a system bot only) the workspace-owner fallback.' },
  { name: 'can_be_deleted',   type: 'boolean',                   description: 'Auth user may call DELETE — same rule as can_be_edited.' },
  { name: 'created_at',       type: 'string (ISO 8601)',         description: '' },
  { name: 'updated_at',       type: 'string (ISO 8601)',         description: '' },
];

// ── BotListResource fields ─────────────────────────────────────────────────
const botListResourceRows: ApiRow[] = [
  { name: 'id',                     type: 'string',       description: 'UUID.' },
  { name: 'name',                   type: 'string',       description: '' },
  { name: 'status',                 type: 'string',       description: "'draft' | 'active' | 'disabled'" },
  { name: 'description',            type: 'string | null', description: '' },
  { name: 'icon',                   type: 'string | null', description: '(B6)' },
  { name: 'has_text_module',        type: 'boolean',      description: 'Always true (persona is required on create).' },
  { name: 'task_execution_enabled', type: 'boolean',      description: 'Shorthand for task_execution.enabled.' },
  { name: 'is_owner',               type: 'boolean',      description: 'True only for a HUMAN creator match (isOwnedBy), via the hot-path ownerUserId() — no creator eager-load on the list.' },
  { name: 'created_at',             type: 'string (ISO 8601)', description: '' },
];

// ── BotActionResource fields ───────────────────────────────────────────────
const botActionResourceRows: ApiRow[] = [
  { name: 'id',         type: 'string',       description: 'UUID.' },
  { name: 'bot_id',     type: 'string',       description: 'UUID of the acting bot.' },
  { name: 'task_id',    type: 'string | null', description: 'UUID of the task (null for bot-level actions).' },
  { name: 'type',       type: 'BotActionType', description: 'See the BotActionType table below (B4/B5 added 5 new values).' },
  { name: 'payload',    type: 'object',       description: 'Shape depends on type — see the payload table below.' },
  { name: 'status',     type: "'ok' | 'failed' | 'handed_over'", description: "'failed' for execution_failed; 'handed_over' for handed_over; 'ok' otherwise." },
  { name: 'error',      type: 'string | null', description: 'Error message; populated when status=failed.' },
  { name: 'created_at', type: 'string (ISO 8601)', description: '' },
  { name: 'updated_at', type: 'string (ISO 8601)', description: '' },
];

// ── BotAction payload shapes by type (B4/B5) ───────────────────────────────
const botActionPayloadRows: ApiRow[] = [
  { name: 'task_started',                     type: "{ run: number, trigger: 'initial'|'resume'|'revision' }", description: 'Every run records this (not just the first).' },
  { name: 'question_asked',                   type: '{ question: string }',       description: 'ask_and_wait fired.' },
  { name: 'resumed',                          type: '{ run: number }',            description: 'A human reply resumed a waiting task.' },
  { name: 'revision_started',                 type: '{ run: number }',            description: 'An approval reject restored the bot assignee.' },
  { name: 'tool_used (fetch_url)',            type: '{ tool, host, url }',        description: 'Never the fetched page content.' },
  { name: 'tool_used (web_search)',           type: '{ tool, query, results }',   description: 'Never the API key.' },
  { name: 'tool_used (generate_file)',        type: '{ tool, file }',             description: 'File name only.' },
  { name: 'tool_used (read_attachments list)', type: "{ tool, action: 'list', count }", description: '' },
  { name: 'tool_used (read_attachments read)', type: "{ tool, action: 'read', file }", description: 'Never the file content.' },
  { name: 'other types',                      type: '{}',                         description: 'Empty payload.' },
];

// ── Task fields related to bot execution (B4, additive) ────────────────────
const taskBotRunFieldsRows: ApiRow[] = [
  { name: 'bot_waiting',   type: 'boolean', description: 'True iff assignee_type=bot AND bot_run_state=waiting. On BOTH TaskResource and TaskListResource.' },
  { name: 'bot_runs_used', type: 'number',  description: 'Monotonic run counter for this task. Full TaskResource only.' },
  { name: 'bot_runs_cap',  type: 'number',  description: "config('ai.max_runs_per_task') echoed for the UI. Full TaskResource only." },
];

// ── Always-present interaction tools (B4) ───────────────────────────────────
const interactionToolRows: ApiRow[] = [
  { name: 'post_comment(text)',      type: 'Always available.', description: 'Posts a bot-authored comment. Usable any number of times.' },
  { name: 'fill_form(answers)',      type: 'Only when the task has a form.', description: 'Persists form answers. Empty answers → tool-error.' },
  { name: 'ask_and_wait(question)',  type: 'Always available.', description: 'Posts the question as a comment, records question_asked, ENDS the run (bot_run_state → waiting). Only a HUMAN comment resumes it.' },
  { name: 'finish()',                type: 'Always available.', description: "Submits to in_test (auto-starts approval). FAILS with an instructive error if an attached form is unfilled." },
];

// ── Optional registry tools (B5) ────────────────────────────────────────────
const registryToolRows: ApiRow[] = [
  { name: 'fetch_url',         type: 'Always available.',                              description: 'Fetch a public page, return plain text. SSRF-guarded (canonicalize → resolve → pin).' },
  { name: 'web_search',        type: "Available only when AI_SEARCH_API_KEY is set.",   description: 'Brave Search by default; top results (title/url/snippet).' },
  { name: 'generate_file',     type: 'Always available.',                              description: "Creates a real task attachment (txt/md/csv/json). uploader_id copies the task's own creator_id (documented compromise); uploader_type is left to default to 'user' — see the Security section for the edge case on a system (run-created) task." },
  { name: 'read_attachments',  type: 'Always available.',                              description: "List or read the CURRENT task's own text attachments only." },
];

// ── config/ai.php keys (B4/B5/B6) ───────────────────────────────────────────
const aiConfigRows: ApiRow[] = [
  { name: 'ai.max_runs_per_task',       type: 'AI_MAX_RUNS_PER_TASK',        description: 'Default 5. Hard cap on total runs per task (initial + resumes + revisions).' },
  { name: 'ai.context_comment_limit',   type: 'AI_CONTEXT_COMMENT_LIMIT',    description: 'Default 30. Most recent comments injected into the run context.' },
  { name: 'ai.knowledge_max_chars',     type: 'AI_KNOWLEDGE_MAX_CHARS',      description: 'Default 8000. Cap on injected knowledge-module text.' },
  { name: 'ai.search.provider',         type: 'AI_SEARCH_PROVIDER',          description: "Default 'brave'." },
  { name: 'ai.search.api_key',          type: 'AI_SEARCH_API_KEY',           description: 'Gates web_search availability. Never logged/echoed/persisted.' },
  { name: 'ai.search.results',          type: 'AI_SEARCH_RESULTS',           description: 'Default 5.' },
  { name: 'ai.fetch_timeout',           type: 'AI_FETCH_TIMEOUT',            description: 'Default 10 (seconds).' },
  { name: 'ai.fetch_max_bytes',         type: 'AI_FETCH_MAX_BYTES',          description: 'Default 2 MB. Hard cap while streaming the response.' },
  { name: 'ai.fetch_max_chars',         type: 'AI_FETCH_MAX_CHARS',          description: 'Default 20000. Cap on returned plain text.' },
  { name: 'ai.fetch_max_redirects',     type: 'AI_FETCH_MAX_REDIRECTS',      description: 'Default 3. Each hop re-validated + re-pinned.' },
  { name: 'ai.generate_file_max_bytes', type: 'AI_GENERATE_FILE_MAX_BYTES',  description: 'Default 1 MB.' },
  { name: 'ai.read_attachment_max_bytes', type: 'AI_READ_ATTACHMENT_MAX_BYTES', description: 'Default 1 MB.' },
];

// ── Polymorphic actor shapes ───────────────────────────────────────────────
const assigneeShapeRows: ApiRow[] = [
  { name: 'type',    type: "'user' | 'bot'", description: '' },
  { name: 'id',      type: 'string',         description: 'UUID.' },
  { name: 'name',    type: 'string',         description: '' },
  { name: 'email',   type: 'string | null',  description: 'null for a bot.' },
  { name: 'avatar',  type: 'null',           description: 'Placeholder.' },
  { name: 'is_bot',  type: 'boolean',        description: '' },
];

const commentAuthorRows: ApiRow[] = [
  { name: 'id',      type: 'string',         description: 'UUID.' },
  { name: 'name',    type: 'string',         description: '' },
  { name: 'email',   type: 'string | null',  description: 'null for a bot.' },
  { name: 'type',    type: "'user' | 'bot'", description: 'Additive field — was not present before Batch 2.' },
  { name: 'is_bot',  type: 'boolean',        description: 'Additive field — was not present before Batch 2.' },
];

// ── Task write request ─────────────────────────────────────────────────────
const taskWriteRows: ApiRow[] = [
  { name: 'assigned_id',   type: 'string (UUID) | null', description: 'Legacy path. Required unless assignee_type is supplied. Assigns to a workspace User.' },
  { name: 'assignee_type', type: "'user' | 'bot' | null", description: 'New path. When present, wins over assigned_id. Null clears the assignee.' },
  { name: 'assignee_id',   type: 'string (UUID) | null', description: 'Required with assignee_type. Validated via ScopedExists(User) or ScopedExists(Bot).' },
  { name: 'bot_id[]',      type: 'string[] (filter)',    description: 'GET /tasks query filter: tasks whose bot assignee matches. Separate from the write fields above.' },
];

// ── Stage approver type ────────────────────────────────────────────────────
const approverTypeRows: ApiRow[] = [
  { name: "'user'", type: 'ApproverType.User', description: 'A specific workspace member decides over HTTP (POST .../decide).' },
  { name: "'ai'",   type: 'ApproverType.Ai',   description: 'Generic AI evaluation via ApprovalEvaluationAgent. No named persona.' },
  { name: "'bot'",  type: 'ApproverType.Bot',  description: 'Named bot approver: same AI path as ai, but the bot persona colors the verdict and note.' },
];
</script>

<template>
  <StoryPage
    title="Bots module (AI Characters)"
    description="Module overview, API contract, polymorphic actor pattern, interactive task-execution flow, tool registry, knowledge module, and bot-as-approver. Documents implemented behavior only (B1–B6). Backend: app/modules/Bot/."
  >

    <!-- 1. Module overview -->
    <StorySection title="Module overview">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <strong>Bot</strong> is a workspace-scoped AI character (digital worker) with
          <strong>5 modules</strong>. Once assigned to a task it can execute, it is an
          <strong>interactive participant</strong> — not a one-shot runner: it reads full task
          context, acts through tools across possibly many turns, can ask a human a question and
          wait for a reply, and resumes automatically. Bots are owned by the user who created
          them; only the creator can edit, delete, or restore (a workspace-owner fallback exists
          for a system, non-human-created bot, though a Bot is only ever created by an
          authenticated human today — see <code class="font-next-mono">docs/backend/creator-attribution.md</code>).
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">The 5 modules</p>
          <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
            <li><strong>Text</strong> (required) — persona, style, dictionary, phrases, prohibitions. Always shapes the AI voice.</li>
            <li><strong>Task-execution</strong> (optional, enable toggle) — interactive execution + granted registry tools.</li>
            <li><strong>Knowledge</strong> (optional, enable toggle, B6) — { title, content } entries injected into the run context only when enabled.</li>
            <li><strong>Visual</strong> (placeholder, coming soon) — no logic yet.</li>
            <li><strong>Audio</strong> (placeholder, coming soon, B6) — renamed from <code class="font-next-mono">voice</code>. No logic yet.</li>
          </ul>
        </div>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Backend module</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>app/modules/Bot/Models/Bot.php</li>
              <li>app/modules/Bot/Models/BotAction.php</li>
              <li>app/modules/Bot/Agents/BotTaskExecutionAgent.php</li>
              <li>app/modules/Bot/Jobs/BotTaskExecutionJob.php</li>
              <li>app/modules/Bot/Services/BotTaskExecutionService.php</li>
              <li>app/modules/Bot/Services/BotTaskRunManager.php</li>
              <li>app/modules/Bot/Services/BotTaskInteractionService.php</li>
              <li>app/modules/Bot/Services/BotTaskContextBuilder.php</li>
              <li>app/modules/Bot/Tools/ (interaction) + Tools/Registry/ (B5)</li>
              <li>app/modules/Bot/Services/BotActionService.php</li>
              <li>app/modules/Bot/Policies/BotPolicy.php</li>
              <li>app/modules/Bot/routes/api.php</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Capability flags</p>
            <ul class="flex flex-col gap-next-1 text-next-xs text-next-muted-foreground">
              <li><code class="font-next-mono">is_owner</code> — HUMAN creator match only (isOwnedBy). Presentational — never gate an action on it alone.</li>
              <li><code class="font-next-mono">can_execute_tasks</code> — status=active AND task_execution.enabled=true.</li>
              <li><code class="font-next-mono">can_be_edited</code> / <code class="font-next-mono">can_be_deleted</code> — server-authoritative via BotPolicy (owner, or the workspace-owner fallback for a system bot).</li>
            </ul>
            <p class="mt-next-2 text-next-xs text-next-muted-foreground">
              Pattern mirrors <code class="font-next-mono">ApprovalPipelineListItem</code>.
              UI should never invent authorization — always read these flags from the resource,
              and gate on <code class="font-next-mono">can_be_edited</code>/<code class="font-next-mono">can_be_deleted</code>,
              never on <code class="font-next-mono">is_owner</code>. See
              <code class="font-next-mono">docs/backend/creator-attribution.md</code>.
            </p>
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Auth + tenant scope</p>
          <p class="text-next-xs text-next-muted-foreground">
            All endpoints require <code class="font-next-mono">auth:sanctum</code> and
            <code class="font-next-mono">X-Workspace-Id</code>. The <code class="font-next-mono">TenantAware</code>
            trait automatically scopes every query to the active workspace via <code class="font-next-mono">WorkspaceScope</code>.
            A non-member cannot resolve the workspace and never reaches the bot controller.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 2. BotStatus + BotActionType -->
    <StorySection title="BotStatus and BotActionType">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-2 font-next-semibold text-next-fg">BotStatus</p>
            <ul class="flex flex-col gap-next-2 text-next-xs">
              <li class="flex items-center gap-next-2">
                <Badge variant="neutral" size="sm">draft</Badge>
                <span class="text-next-muted-foreground">Being built. Not eligible to execute tasks.</span>
              </li>
              <li class="flex items-center gap-next-2">
                <Badge variant="success" size="sm">active</Badge>
                <span class="text-next-muted-foreground">Fully operational. May execute tasks if task_execution.enabled.</span>
              </li>
              <li class="flex items-center gap-next-2">
                <Badge variant="danger" size="sm">disabled</Badge>
                <span class="text-next-muted-foreground">Suspended. Not eligible to execute tasks.</span>
              </li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-2 font-next-semibold text-next-fg">BotActionType (audit log)</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>task_started — a run began. Payload {run, trigger}.</li>
              <li>commented — post_comment fired.</li>
              <li>form_filled — fill_form fired.</li>
              <li>submitted_to_test — finish fired (advanced to in_test, approval started).</li>
              <li>marked_done — task passed approval, reached done.</li>
              <li>execution_failed — unrecoverable error; run ends, task stays in_progress.</li>
              <li class="pt-next-1 text-next-muted-foreground/70">— B4 interactive lifecycle —</li>
              <li>question_asked — ask_and_wait fired. Payload {question}.</li>
              <li>resumed — a human reply resumed a waiting run. Payload {run}.</li>
              <li>revision_started — an approval reject restored a bot assignee. Payload {run}.</li>
              <li>handed_over — run cap reached; bot stopped advancing.</li>
              <li class="pt-next-1 text-next-muted-foreground/70">— B5 tool registry —</li>
              <li>tool_used — a registry tool was invoked. Per-tool payload (see below).</li>
            </ul>
          </div>
        </div>
        <ApiTable title="BotAction payload shapes (by type)" :rows="botActionPayloadRows" type-header="Payload shape" />
      </div>
    </StorySection>

    <!-- 3. Bot CRUD endpoints -->
    <StorySection title="Bot API endpoints">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="Bot endpoints (auth:sanctum + X-Workspace-Id)" :rows="botEndpointRows" type-header="Query / Body" />
      </div>
    </StorySection>

    <!-- 4. Request + resource shapes -->
    <StorySection title="Request and resource shapes">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="BotWritePayload (POST body / PUT body)" :rows="botWritePayloadRows" />
        <Alert variant="warning" size="sm">
          <code class="font-next-mono">task_execution.knowledge_source</code> was REMOVED in B6.
          The knowledge module replaces it. If an old client still sends it, the value is silently
          ignored — not validated, read, or persisted.
        </Alert>
        <p class="text-next-xs text-next-muted-foreground">
          <strong>Restore:</strong> <code class="font-next-mono">POST /bots/{id}/restore</code> has no body.
          <code class="font-next-mono">PUT</code> shares all the same validation rules as <code class="font-next-mono">POST</code>;
          authorization target is the existing bot (the creator, or the workspace-owner fallback
          for a system bot — see Capability flags above).
        </p>
        <ApiTable title="BotListResource (index)" :rows="botListResourceRows" />
        <ApiTable title="BotResource (show / store / update / restore)" :rows="botResourceRows" />
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">visual / audio placeholders</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">visual</code> and <code class="font-next-mono">audio</code> are stored JSON
            columns returned as-is (currently <code class="font-next-mono">null</code>). They are reserved for future
            Visual and Audio modules. Treat them as read-only; no write path exists yet.
            <code class="font-next-mono">audio</code> is a straight column RENAME of the earlier
            <code class="font-next-mono">voice</code> placeholder (B6) — same semantics, new name.
          </p>
        </div>
        <ApiTable title="BotActionResource" :rows="botActionResourceRows" />
      </div>
    </StorySection>

    <!-- 5. Polymorphic actor contract -->
    <StorySection title="Polymorphic actor (Task assignee + Comment author)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Both the task assignee and the comment author are now polymorphic (User or Bot),
          stored via morph-map aliases <code class="font-next-mono">'user'</code> and
          <code class="font-next-mono">'bot'</code>. The API contract is <strong>additive and
          back-compatible</strong>: old fields are still present and unchanged; new fields are
          added alongside them.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">TaskResource — dual assignee fields</p>
          <div class="flex flex-col gap-next-2 font-next-mono text-next-xs text-next-muted-foreground">
            <p>
              <code>assigned</code> — <code>UserResource | null</code><br>
              Back-compat field. <strong>null when the assignee is a bot.</strong>
              Existing consumers relying on this field must null-guard.
            </p>
            <p>
              <code>assignee</code> — <code>AssigneeShape | null</code> (new)<br>
              Polymorphic; populated for both User and Bot assignees.
            </p>
          </div>
          <div class="mt-next-3">
            <ApiTable title="AssigneeShape (assignee field)" :rows="assigneeShapeRows" />
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">CommentResource — author shape</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            The <code class="font-next-mono">author</code> shape is unchanged in existing fields
            (<code class="font-next-mono">id / name / email</code>) but gained two additive fields.
            Callers checking author identity via <code class="font-next-mono">author.id === user.id</code>
            continue to work without modification.
          </p>
          <ApiTable title="CommentResource.author (after Batch 2)" :rows="commentAuthorRows" />
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            HTTP posting (<code class="font-next-mono">POST /comments</code>) remains user-only.
            Bot-authored comments are created only via the internal job path
            (<code class="font-next-mono">BotTaskExecutionJob</code>) using
            <code class="font-next-mono">CommentDTO::fromBot()</code>.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Morph aliases</p>
          <p class="text-next-xs text-next-muted-foreground">
            Registered via <code class="font-next-mono">Relation::enforceMorphMap()</code>:
          </p>
          <ul class="mt-next-1 flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
            <li>'user' → App\Models\User (AuthModuleServiceProvider)</li>
            <li>'bot'  → App\Modules\Bot\Models\Bot (BotModuleServiceProvider)</li>
          </ul>
        </div>
      </div>
    </StorySection>

    <!-- 6. Task write request: legacy vs polymorphic -->
    <StorySection title="Task write request — legacy vs polymorphic assignee">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <code class="font-next-mono">StoreTasksRequest</code> accepts two assignee paths in
          parallel. The polymorphic path (<code class="font-next-mono">assignee_type</code> +
          <code class="font-next-mono">assignee_id</code>) wins when present; the legacy path
          (<code class="font-next-mono">assigned_id</code>) is still accepted for back-compat.
          Both null-clears the assignee.
        </p>
        <ApiTable title="Assignee fields in StoreTasksRequest + GET /tasks filter" :rows="taskWriteRows" />
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">ScopedExists rule</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">assignee_id</code> is validated through
            <code class="font-next-mono">ScopedExists(User::class)</code> or
            <code class="font-next-mono">ScopedExists(Bot::class)</code> depending on
            <code class="font-next-mono">assignee_type</code>. A bot from another workspace is
            rejected (403/422), not silently ignored.
          </p>
        </div>
        <ApiTable title="TaskResource / TaskListResource — bot run-state fields (B4, additive)" :rows="taskBotRunFieldsRows" />
      </div>
    </StorySection>

    <!-- 6. Interactive bot task-execution flow (B4) -->
    <StorySection title="Interactive bot task-execution flow (B4)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          The bot is an <strong>interactive task participant</strong>, not a one-shot runner. A
          task can see MANY runs over its life: an initial run on assignment, a resume run every
          time a human replies while the bot is waiting, and a revision run every time an
          approval rejection restores a bot assignee.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Run-state machine (BotTaskRunManager)</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            Two columns on <code class="font-next-mono">tasks</code> drive the loop:
            <code class="font-next-mono">bot_run_state</code> (<code class="font-next-mono">idle | running | waiting</code>)
            and <code class="font-next-mono">bot_runs_used</code> (monotonic counter).
          </p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">idle ──claim──▶ running ──release(finished/failed)──▶ idle
                    └────release(waiting)───────────▶ waiting ──claim(resume)──▶ running</pre>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            The claim is a SINGLE atomic conditional <code class="font-next-mono">UPDATE ...
            WHERE bot_run_state IN ('idle','waiting') AND bot_runs_used &lt; cap</code>.
            Postgres row-locks the update, so exactly one concurrent caller can flip to
            <code class="font-next-mono">running</code> — this is the ONLY concurrency guard;
            it replaces the earlier (B1–B3) partial-unique-index mechanism (see below).
          </p>
        </div>

        <Alert variant="info" size="sm">
          <strong>This replaced the earlier idempotency mechanism.</strong> B1–B3 used a partial
          unique index on <code class="font-next-mono">bot_actions (bot_id, task_id) WHERE type =
          'task_started'</code> to allow at most ONE run ever. B4 deliberately allows MANY runs,
          so that index was DROPPED; concurrency-safety now lives entirely in the
          <code class="font-next-mono">bot_run_state</code> atomic claim above.
        </Alert>

        <ApiTable title="Triggers (BotTaskExecutionService)" type-header="Entry point" :rows="[
          { name: 'initial', type: 'maybeDispatch(Task)', description: 'Task freshly assigned to an execution-capable bot AND status is TO_DO.' },
          { name: 'resume', type: 'resumeFromHumanReply(Task)', description: 'A comment with author_type=user was just posted on a task whose bot is waiting. A bot comment never triggers this (loop guard).' },
          { name: 'revision', type: 'reviseAfterReject(Task)', description: 'Task::onApprovalRejected() restores a bot as the original assignee (deferred to DB::afterCommit()).' },
        ]" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Context injection (BotTaskContextBuilder)</p>
          <p class="text-next-xs text-next-muted-foreground">
            Before each run: (1) the bot's ENABLED knowledge entries, capped at
            <code class="font-next-mono">ai.knowledge_max_chars</code>; (2) task title +
            description; (3) the attached form's schema AND current submission state; (4) the
            most recent <code class="font-next-mono">ai.context_comment_limit</code> (default 30)
            comments, oldest-first; (5) the FULL approval history INCLUDING rejection notes, so a
            revision run knows exactly what to fix.
          </p>
        </div>

        <ApiTable title="Always-present interaction tools (app/modules/Bot/Tools/)" type-header="Availability" :rows="interactionToolRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Run lifecycle (BotTaskExecutionJob)</p>
          <ol class="flex list-decimal flex-col gap-next-2 pl-next-5 text-next-xs text-next-muted-foreground">
            <li>Record <code class="font-next-mono">task_started {run, trigger}</code>; if resume/revision, also record <code class="font-next-mono">resumed</code>/<code class="font-next-mono">revision_started</code>.</li>
            <li><code class="font-next-mono">task → in_progress</code> via <code class="font-next-mono">TaskService::botStart()</code> (bypasses the user-centric <code class="font-next-mono">canSetOn</code> guard).</li>
            <li>Build context, run <code class="font-next-mono">BotTaskExecutionAgent::prompt()</code> — multi-step (<code class="font-next-mono">#[MaxSteps(12)]</code>) agent chaining tool calls.</li>
            <li>On success: release run-state to <code class="font-next-mono">waiting</code> if <code class="font-next-mono">ask_and_wait</code> fired, else <code class="font-next-mono">idle</code>.</li>
            <li>On any error: record <code class="font-next-mono">execution_failed</code>, release to <code class="font-next-mono">idle</code>. Task stays wherever it was left.</li>
            <li><code class="font-next-mono">failed()</code> handler (job-level failure, e.g. timeout): last-resort release to <code class="font-next-mono">idle</code> so a stuck claim doesn't permanently block future runs.</li>
          </ol>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">BotTaskExecutionJob::tries = 1</code> — no automatic retry.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Run cap + hand-over</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">config('ai.max_runs_per_task')</code> (default 5) hard-caps
            total runs — initial + every resume + every revision all count. When the cap is
            reached, the bot posts a hand-over comment, records <code class="font-next-mono">handed_over</code>
            (<code class="font-next-mono">status: 'handed_over'</code>), and leaves the task exactly
            where it is — it does NOT advance status.
          </p>
        </div>

        <Alert variant="danger" size="sm">
          <strong>Operational caveat — do not enable an async queue without a stale-claim reaper.</strong>
          <code class="font-next-mono">failed()</code> releases a stuck claim on any Laravel-detected
          job failure, but a HARD process kill (SIGKILL, OOM, mid-job <code class="font-next-mono">queue:restart</code>)
          bypasses it entirely — the task is left stranded in <code class="font-next-mono">bot_run_state = 'running'</code>
          forever, and <code class="font-next-mono">claim()</code> only matches <code class="font-next-mono">idle</code>/<code class="font-next-mono">waiting</code>,
          so it can NEVER self-recover. This is an accepted, documented gap — do not run a non-sync
          queue worker for this job without first adding a scheduled reaper.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">AI provider config</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">config/ai.php</code> drives both
            <code class="font-next-mono">BotTaskExecutionAgent</code> and
            <code class="font-next-mono">ApprovalEvaluationAgent</code>.
            Defaults: provider <code class="font-next-mono">openai</code> / model <code class="font-next-mono">gpt-4o</code>.
            Override via <code class="font-next-mono">AI_PROVIDER</code> / <code class="font-next-mono">AI_MODEL</code> env vars.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 7. Tool registry (B5) -->
    <StorySection title="Tool registry (B5)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Four OPTIONAL tools, distinct from the always-present interaction tools above. A bot
          must be explicitly GRANTED a tool (<code class="font-next-mono">task_execution.tools[]</code>)
          AND the tool must be AVAILABLE in the current environment — exposed to the agent only
          when <strong>granted ∩ available</strong>. There are never dead options in the editor:
          <code class="font-next-mono">GET /bots/tool-registry</code> reports availability so the
          frontend hides anything unavailable.
        </p>
        <ApiTable title="Registry tools" type-header="Availability" :rows="registryToolRows" />
        <ApiTable title="config/ai.php keys (B4/B5/B6)" type-header="Env var" :rows="aiConfigRows" />

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">generate_file — documented compromise</p>
            <p class="text-next-xs text-next-muted-foreground">
              A real Disk <code class="font-next-mono">File</code> row, attached to the task like
              any human upload. <code class="font-next-mono">uploader_id</code> is NOT NULL and a
              bot has no user row, so the uploader copies the task's own
              <code class="font-next-mono">creator_id</code> — a deliberate stopgap, not a bug.
              <strong>Known edge case:</strong> <code class="font-next-mono">uploader_type</code> is
              left to default to <code class="font-next-mono">'user'</code>; if the task itself is a
              system (run-created) record, the copied id is actually a
              <code class="font-next-mono">WorkflowRun</code>/<code class="font-next-mono">Bot</code> uuid,
              so the file's <code class="font-next-mono">creator</code> resolves to
              <code class="font-next-mono">null</code> instead of a meaningful attribution — see
              <code class="font-next-mono">docs/backend/creator-attribution.md</code> → "Known gaps."
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">read_attachments — scope + sniffing</p>
            <p class="text-next-xs text-next-muted-foreground">
              Strictly scoped to the CURRENT task's own files (no traversal, no cross-task
              reach). Only text-y types; the actual bytes are sniffed (valid UTF-8, low
              control-char ratio) before being returned — stored mime/extension is not trusted
              blindly.
            </p>
          </div>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          <code class="font-next-mono">tool_used</code> payloads deliberately carry only small,
          non-sensitive metadata (host/query/file name) — NEVER fetched page content, attachment
          content, or API keys.
        </p>
      </div>
    </StorySection>

    <!-- 7b. Security — accepted residual risks -->
    <StorySection title="Security — accepted residual risks (B5)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Two risk classes are inherent to giving an LLM agent tools that touch the network and
          file content. Both were reviewed and are <strong>accepted</strong>, with specific
          mitigations already in place — this is documentation of a considered trade-off, not a
          TODO list.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">1. SSRF (fetch_url)</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">SafeUrlGuard</code>: canonicalizes host + IP (blocks
            loopback/private/reserved v4 and v6, IPv4-mapped-v6, ALL numeric host encodings,
            trailing-dot FQDNs), resolves ONCE, then PINS the validated IP into the connection via
            <code class="font-next-mono">CURLOPT_RESOLVE</code> — closing the DNS-rebinding TOCTOU
            window. Every redirect hop is independently re-validated and re-pinned. The response
            body is streamed with a hard byte cap (never fully buffered).
          </p>
          <p class="text-next-xs text-next-muted-foreground">
            <strong>Residual risk (accepted):</strong> trust in the single DNS lookup at
            validation time, and no content-level judgment on what a legitimately-fetched public
            host actually serves.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">2. Prompt injection (fetched/attachment content)</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            Text fetched via <code class="font-next-mono">fetch_url</code> or read via
            <code class="font-next-mono">read_attachments</code> enters the agent's context
            verbatim and could contain adversarial instructions. No content-based filtering was
            added — the mitigation is architectural: the blast radius is BOUNDED to this task's
            own write powers (comment / fill form / ask-and-wait / finish, plus granted registry
            tools), with NO cross-task or cross-workspace reach, and <code class="font-next-mono">finish</code>
            still routes through the ordinary approval gate rather than reaching
            <code class="font-next-mono">done</code> directly.
          </p>
          <p class="text-next-xs text-next-muted-foreground">
            <strong>Residual risk (accepted):</strong> an injected instruction could still cause a
            misleading comment, an unintended tone, or wasted run budget within the current task
            — but cannot escalate beyond it.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 8. Knowledge module (B6) -->
    <StorySection title="Knowledge module (B6)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <code class="font-next-mono">knowledge</code> is <code class="font-next-mono">{ enabled:
          bool, entries: [{title, content}] }</code> — an explicitly-enabled module, following the
          same "explicit enable + inert-when-off" pattern as <code class="font-next-mono">task_execution</code>.
          Entries are injected into the execution context ONLY when <code class="font-next-mono">enabled</code>
          is true; a module holding entries but toggled off is completely inert.
        </p>
        <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
          <li>Max 50 entries per bot; title max 255 chars, content max 5000 chars (validated).</li>
          <li>Total injected text capped at <code class="font-next-mono">ai.knowledge_max_chars</code> (default 8000) — entries are appended until the cap, then a truncation marker is appended.</li>
          <li><code class="font-next-mono">Bot::knowledgeEnabled()</code> / <code class="font-next-mono">knowledgeEntries()</code> tolerate the pre-B6 bare-array shape for old rows — no data migration was needed for the shape switch itself.</li>
          <li>Scoped per-bot only today — NOT yet a standalone app-wide Knowledge module. A genuine second consumer outside the Bot module is the trigger to extract one (through planning mode), mirroring the FormViewer "extract on third consumer" precedent (ADR-0006 §2).</li>
        </ul>
      </div>
    </StorySection>

    <!-- 9. Test seams -->
    <StorySection title="Test seams: structured-output fake vs scripted multi-step double">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <code class="font-next-mono">Tests\Concerns\FakesBotExecutionAgent</code> is the reusable
          test seam. Its mechanism differs per agent because ONE of the two bot agents cannot be
          driven by Laravel AI's built-in fake.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">ApprovalEvaluationAgent — Agent::fake()</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            Structured output, no tool loop — still uses Laravel AI's first-class
            <code class="font-next-mono">Agent::fake([...])</code>.
          </p>
          <ul class="flex flex-col gap-next-2 font-next-mono text-next-xs text-next-muted-foreground">
            <li>
              <code>fakeApprovalEvaluation(['decision' =&gt; 'approved', 'note' =&gt; '...'])</code><br>
              Covers both generic AI stages and named-bot approver stages.
            </li>
            <li>
              <code>fakeApprovalEvaluationUsing(Closure $callback)</code><br>
              Capture instructions for persona-injection assertions.
            </li>
          </ul>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">BotTaskExecutionAgent — scripted double (B4)</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            Multi-step, tool-calling. Laravel AI's <code class="font-next-mono">FakeTextGateway</code>
            accepts an agent's tools but never invokes them, and the fake closure receives no
            reference to the agent — so a <code class="font-next-mono">post_comment → fill_form →
            finish</code> sequence cannot be scripted through <code class="font-next-mono">Agent::fake()</code>.
            Instead the agent is SWAPPED in the container for
            <code class="font-next-mono">Tests\Support\ScriptedBotExecutionAgent</code>, which
            invokes the REAL tools, in a scripted order, against the REAL interaction service (no
            provider call at all).
          </p>
          <ul class="flex flex-col gap-next-2 font-next-mono text-next-xs text-next-muted-foreground">
            <li>
              <code>scriptBotRun([['post_comment', ['text' =&gt; '...']], ['finish', []]])</code><br>
              Scripts a multi-step run. A step naming a tool not exposed for the task (e.g.
              fill_form with no form) is silently skipped; stops early on a terminal tool.
            </li>
            <li>
              <code>failBotRun('message')</code><br>
              Binds a throwing double — drives the execution_failed path.
            </li>
          </ul>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            Because it invokes REAL tools against the REAL interaction service, a scripted test
            exercises the FULL application path — dispatch → run-state claim → tool side-effects →
            run-state release — with only the LLM decision-making step replaced.
          </p>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          Reuse guidance: a future structured-output (no-tool-loop) agent → use
          <code class="font-next-mono">Agent::fake()</code> directly. A future multi-step,
          tool-calling agent → follow the <code class="font-next-mono">ScriptedBotExecutionAgent</code>
          pattern (factor tool-building into a shared factory, add a scripted double, bind via a
          trait helper). Reference: <code class="font-next-mono">tests/Concerns/FakesBotExecutionAgent.php</code>,
          <code class="font-next-mono">tests/Support/ScriptedBotExecutionAgent.php</code>.
        </p>
      </div>
    </StorySection>

    <!-- 10. Bot as named AI approver -->
    <StorySection title="Bot as named AI approver">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A pipeline stage's <code class="font-next-mono">approver_type</code> is now
          <code class="font-next-mono">user | ai | bot</code>. A <code class="font-next-mono">bot</code>
          stage evaluates through the same AI path as <code class="font-next-mono">ai</code>, but the
          bot's persona, style, dictionary, phrases, and prohibitions color the verdict and note.
        </p>
        <ApiTable title="ApproverType values" :rows="approverTypeRows" type-header="Enum value" />

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-2 font-next-semibold text-next-fg">Stage resource shape</p>
            <div class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <p><code>approver_type</code> — 'user' | 'ai' | 'bot'</p>
              <p><code>approver</code> — UserResource | null (back-compat; null for ai/bot)</p>
              <p><code>approver_identity</code> — AssigneeShape | null (additive)</p>
            </div>
            <p class="mt-next-2 text-next-xs text-next-muted-foreground">
              <code class="font-next-mono">approver_identity</code> is <code class="font-next-mono">null</code>
              for generic <code class="font-next-mono">ai</code> stages. For <code class="font-next-mono">bot</code>
              stages it contains the bot identity (<code class="font-next-mono">type: 'bot'</code>).
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-2 font-next-semibold text-next-fg">Pipeline write payload change</p>
            <p class="text-next-xs text-next-muted-foreground">
              <code class="font-next-mono">stages.*.approver_type</code> now accepts
              <code class="font-next-mono">'bot'</code> in addition to <code class="font-next-mono">'user'</code>
              and <code class="font-next-mono">'ai'</code>. When <code class="font-next-mono">approver_type = 'bot'</code>,
              <code class="font-next-mono">approver_id</code> is required and validated via
              <code class="font-next-mono">ScopedExists(Bot::class)</code>.
              For <code class="font-next-mono">'ai'</code> stages, <code class="font-next-mono">approver_id</code>
              must be <code class="font-next-mono">null</code>.
            </p>
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">isAutomatedApprover()</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">ApproverType::isAutomated()</code> returns true for both
            <code class="font-next-mono">ai</code> and <code class="font-next-mono">bot</code>.
            This drives <code class="font-next-mono">ProcessAiApprovalJob</code> dispatch for both stage types.
            Human HTTP decisions (<code class="font-next-mono">POST .../decide</code>) are blocked for
            automated processes by <code class="font-next-mono">ApprovalProcessPolicy</code>.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 11. Bot as generation-session author (R2 sub-stage 3) -->
    <StorySection title="Bot as generation-session author (R2 sub-stage 3)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A workspace bot can be DELEGATED an editable Generator
          <code class="font-next-mono">GenerationSession</code>: it autonomously fills the session's
          in-scope inputs and becomes the content's AUTHOR, rendering every text part (and a
          <code class="font-next-mono">shot_list</code>'s voiceover) in the SAME persona/style/dictionary/
          phrases/prohibitions this page's Module overview describes — the human session owner is unchanged
          and keeps full edit/refine/undo/delete rights. This is the ONE new cross-module edge in the app,
          <code class="font-next-mono">Bot → Generator + Variables</code>, strictly one-way (the Generator
          never imports Bot).
        </p>
        <Alert variant="info" size="sm">
          Full contract (delegate/undo endpoints, the overlay + fill-report shapes, the
          <code class="font-next-mono">can_delegate</code>/<code class="font-next-mono">can_undo_delegation</code>
          resource flags) lives in <code class="font-next-mono">docs/backend/generator-sessions-api.md</code>
          ("Bot-author delegation overlay") and the Generator module's own gallery page (§20 of
          <code class="font-next-mono">GeneratorPage.vue</code>). Design record:
          <code class="font-next-mono">docs/decisions/ADR-0036-bot-delegation-generation-sessions.md</code>.
        </Alert>
      </div>
    </StorySection>

    <!-- 12. Editor UI (5-module drawer) -->
    <StorySection title="Editor UI — 5-module drawer">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <strong>File:</strong> <code class="font-next-mono">pages/bots/BotEditorDrawer.vue</code>,
          hosted in a query-driven (<code class="font-next-mono">?bot=new</code> /
          <code class="font-next-mono">?bot=&lt;id&gt;</code>) <code class="font-next-mono">Drawer size="cover"</code>.
          Redesigned (B6) around the 5-module structure.
        </p>
        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Layout</p>
            <ul class="flex list-disc flex-col gap-next-1 pl-next-4 text-next-xs text-next-muted-foreground">
              <li>Always-visible general-info band: icon + name + status + description.</li>
              <li>Left vertical module nav (tabs) + right content panel.</li>
              <li>5 modules: text (required) · task-execution · knowledge · visual (soon) · audio (soon).</li>
              <li>Nav shows a state indicator per module: required badge, enabled check, disabled dot, coming-soon badge, or an error dot.</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Enable-toggle pattern</p>
            <p class="text-next-xs text-next-muted-foreground">
              Every non-text module (task-execution, knowledge) has an enable
              <code class="font-next-mono">Switch</code>. When OFF, its form is rendered
              <code class="font-next-mono">:inert</code> + dimmed, with an info
              <code class="font-next-mono">Alert</code> explaining the module — the user can
              preview the fields before turning it on. Visual/audio show a dashed
              coming-soon placeholder instead of a form.
            </p>
          </div>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Write payload assembly</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">task_execution</code> is sent as
            <code class="font-next-mono">{enabled, tools}</code> whenever the user enabled it OR
            selected any tool, else <code class="font-next-mono">null</code> (module off).
            <code class="font-next-mono">knowledge</code> is always sent as
            <code class="font-next-mono">{enabled, entries}</code>.
            <code class="font-next-mono">visual</code>/<code class="font-next-mono">audio</code>
            are never sent (not writable placeholders). A 422 error bag is mapped onto the
            matching module's local field errors, and the editor jumps to the first module that
            carries one.
          </p>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Tool selection UX</p>
          <p class="text-next-xs text-next-muted-foreground">
            The tools <code class="font-next-mono">Select</code> is populated from
            <code class="font-next-mono">useBotToolRegistryStore()</code> (backed by
            <code class="font-next-mono">GET /bots/tool-registry</code>) — only AVAILABLE tools
            are selectable. A tool already saved on the bot but no longer available (e.g.
            <code class="font-next-mono">web_search</code> after the search key was removed) is
            preserved in the payload (not silently dropped) and shown as a removable
            "unavailable" badge below the select, so the user can explicitly clean it up rather
            than losing it without noticing.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 13. Refactor lesson -->
    <StorySection title="Refactor lesson: null-guarding shared resource fields">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Rule</p>
          <p class="text-next-xs text-next-muted-foreground">
            When a previously-required field on a shared API resource is nullable-ized, BOTH
            the legacy SPA consumer (<code class="font-next-mono">resources/js/modules/tasks</code>)
            AND the <code class="font-next-mono">next</code> consumer must be null-guarded in the
            SAME changeset. Skipping the legacy guard causes silent runtime errors in production.
          </p>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">What happened</p>
          <p class="text-next-xs text-next-muted-foreground">
            Making <code class="font-next-mono">assigned</code> nullable on
            <code class="font-next-mono">TaskResource</code> (because a bot-assigned task has no
            User assignee) broke <code class="font-next-mono">TaskDetailsPanel.vue</code> in the
            legacy SPA: it previously assumed <code class="font-next-mono">assigned</code> was always
            a User object and accessed its properties directly.
          </p>
        </div>
        <p class="text-next-xs text-next-muted-foreground">
          See <code class="font-next-mono">docs/decisions/ADR-0007-bot-module-design.md</code> §3
          for the full reasoning. The same discipline applies to any future nullable-ization of
          a field shared between legacy and next consumers.
        </p>
      </div>
    </StorySection>

  </StoryPage>
</template>
