<script setup lang="ts">
// Gallery: Workflows (automation) module — module overview, the 5.1 typed contract
// (2 trigger types, 2 step types, typed conditions, the variable directive/union
// system), the schedule builder (a compositional time/day/month descriptor +
// exclusions + live preview) + AI schedule-assist, run lifecycle, manual/test
// runs, cost limits, and monitoring. Documents the IMPLEMENTED behavior of
// app/modules/Workflows/ AFTER the Etap 5.1 re-scope (B1-B7), the schedule
// descriptor v2 rebuild (ADR-0012, which supersedes the earlier 12/16-family
// model from ADR-0009 §3 / ADR-0010), the SB1/SB2 runtime step-operations
// batch (variable-operation pipelines, conditional if-blocks, and @[ai-text]
// AI-generated text executing at run time in a step's fields — ADR-0013, which
// reverses ADR-0009 §2's "pipeline deferred" stance), AND the choice-coercion
// batch (two new choice-producing ops, enum_to_choice/match_to_choice, letting a
// priority value-or-variable pipeline map into TaskPriority::ids() — ADR-0014,
// 66→68 ops) — not planned behavior. Deferred/planned items are called out
// explicitly (see the last section).
//
// Sections:
//   1. Module overview & concepts (the 5.1 re-scope)
//   2. Workflow API endpoints
//   3. Request/resource shapes + capability flags
//   4. Trigger types: form_submitted + schedule
//   5. Typed conditions (form_submitted only)
//   6. The typed variable system (directive + {kind} union)
//   7. Steps: create_task + create_form_report
//   8. Schedule: the time/day/month descriptor + exclusions + live preview
//   9. AI schedule-assist
//   10. Trigger dispatch pipeline + loop protection
//   11. Cost limits
//   12. Run lifecycle + monitoring (Runs view)
//   13. Manual runs / test runs
//   14. Frontend module
//   15. Planned / deferred
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Alert from '../../ui/feedback/Alert.vue';

// ── Workflow endpoints ──────────────────────────────────────────────────────
const workflowEndpointRows: ApiRow[] = [
  { name: 'GET /workflows',                type: '?search=&status=&cursor=', description: 'List workflow definitions (WorkflowListResource[]). Cursor-paginated, 8/page, newest first.' },
  { name: 'POST /workflows',               type: 'WorkflowWritePayload',     description: 'Create a workflow. ALWAYS created inactive — status in the body is ignored.' },
  { name: 'GET /workflows/{id}',           type: '—',                        description: 'Fetch one workflow (WorkflowResource, creator loaded).' },
  { name: 'PUT /workflows/{id}',           type: 'WorkflowWritePayload',     description: 'Update. Same rules as POST. Creator-only. Never touches status.' },
  { name: 'DELETE /workflows/{id}',        type: '—',                        description: 'Soft-delete. Creator-only.' },
  { name: 'POST /workflows/{id}/restore',  type: '—',                        description: 'Restore a soft-deleted workflow. Creator-only.' },
  { name: 'PATCH /workflows/{id}/status',  type: "{ status: 'active'|'inactive' }", description: 'The ONLY path that mutates status. Creator-only.' },
  { name: 'POST /workflows/{id}/run',      type: '{ target_id? }',           description: 'Manual run — any member, works on INACTIVE workflows too (test-run). Returns 202.' },
  { name: 'GET /workflows/{id}/runs',      type: '?state[]=&origin[]=&trigger_type[]=&date_from=&date_to=&date_preset=&cursor=',  description: 'Run monitoring list, scoped to this workflow. Cursor-paginated, 15/page. Any workspace member.' },
  { name: 'GET /workflows/{id}/runs/{run}', type: '—',                       description: 'One run + its full step timeline. Schedule runs also carry schedule_descriptor. 404 if {run} belongs to a different workflow.' },
  { name: 'GET /workflows/runs',           type: '?state[]=&origin[]=&trigger_type[]=&workflow_id=&date_from=&date_to=&date_preset=&cursor=', description: 'GLOBAL cross-workflow runs feed (any workspace member, viewAny). Same shape as the per-workflow list, plus a workflow block per row and an optional workflow_id scope.' },
  { name: 'POST /workflows/meta/schedule-preview', type: '{ schedule, count?, anchor? }', description: 'Live preview: projects the next N (1-12, default 6) fire instants of a draft schedule, optionally centred on an anchor instant. The ONLY place occurrence dates are computed — the FE never re-implements cadence math.' },
  { name: 'GET /forms/{form}/workflow-catalog', type: '—',                   description: 'The TYPED variable catalog for a form_submitted workflow built on {form}. FormPolicy::view.' },
  { name: 'POST /workflows/schedule-assist', type: '{ prompt, tz? }',        description: 'AI natural-language → structured schedule config. 429 throttled per user.' },
];

// ── WorkflowWritePayload fields ─────────────────────────────────────────────
const workflowWritePayloadRows: ApiRow[] = [
  { name: 'name',                type: 'string',                            description: 'Required, max 255.' },
  { name: 'description',         type: 'string | null',                     description: 'Optional, max 2500.' },
  { name: 'icon',                type: 'string | null',                     description: 'Optional, max 100.' },
  { name: 'trigger_type',        type: "'form_submitted' | 'schedule'",     description: 'Required. Only 2 values survive the 5.1 re-scope.' },
  { name: 'trigger_config',      type: 'object (per-type shape)',           description: 'Any key outside the selected type\'s allow-list is REJECTED (422) — cross-type nonsense cannot be saved.' },
  { name: 'conditions',          type: '{field,field_type,operator,value}[]', description: 'Optional, max 50. ONLY valid for form_submitted; REQUIRES trigger_config.form_id when present.' },
  { name: 'steps',               type: '{type,key,config?}[]',              description: 'Required, min 1, max 50. `key` must be distinct across the array (used for {{steps.<key>.*}}).' },
  { name: 'status',              type: '— NOT ACCEPTED —',                  description: 'Never sent here. A workflow is created inactive; toggle via PATCH .../status.' },
];

// ── WorkflowResource fields ─────────────────────────────────────────────────
const workflowResourceRows: ApiRow[] = [
  { name: 'id',                    type: 'string',                    description: 'UUID.' },
  { name: 'name',                  type: 'string',                    description: '' },
  { name: 'status',                type: "'active' | 'inactive'",     description: '' },
  { name: 'description',           type: 'string | null',             description: '' },
  { name: 'icon',                  type: 'string | null',             description: '' },
  { name: 'trigger_type',          type: "'form_submitted' | 'schedule'", description: '' },
  { name: 'trigger_config',        type: 'object',                    description: 'Per-type shape (see the Trigger types section).' },
  { name: 'conditions',            type: '{field,field_type,operator,value}[]', description: 'Typed conditions — empty unless trigger_type=form_submitted.' },
  { name: 'steps',                 type: '{type,key,config}[]',       description: '' },
  { name: 'last_scheduled_run_at', type: 'string (ISO 8601) | null',  description: 'Stamped by the schedule sweep each time it fires this workflow.' },
  { name: 'next_due_at',           type: 'string (ISO 8601) | null',  description: 'Schedule workflows only; null unless ACTIVE + schedule-triggered.' },
  { name: 'creator',               type: "Creator | null",            description: "Discriminated union: user | workflow_run (automation) | bot. See CreatorBadge / creator.ts and docs/backend/creator-attribution.md." },
  { name: 'is_owner',              type: 'boolean',                   description: 'True only for a HUMAN creator match (isOwnedBy) — presentational. Gate actions on can_be_edited/can_be_deleted, not this.' },
  { name: 'can_be_edited',         type: 'boolean',                   description: 'Creator-only.' },
  { name: 'can_be_deleted',        type: 'boolean',                   description: 'Creator-only.' },
  { name: 'can_change_status',     type: 'boolean',                   description: 'Creator-only.' },
  { name: 'can_run',               type: 'boolean',                   description: 'Any workspace member.' },
  { name: 'created_at',            type: 'string (ISO 8601)',         description: '' },
  { name: 'updated_at',            type: 'string (ISO 8601)',         description: '' },
];

const workflowListResourceRows: ApiRow[] = [
  { name: 'id',           type: 'string',                   description: 'UUID.' },
  { name: 'name',         type: 'string',                   description: '' },
  { name: 'status',       type: "'active' | 'inactive'",    description: '' },
  { name: 'description',  type: 'string | null',            description: '' },
  { name: 'icon',         type: 'string | null',            description: '' },
  { name: 'trigger_type', type: "'form_submitted' | 'schedule'", description: '' },
  { name: 'step_count',   type: 'number',                   description: 'count(steps) — the definition\'s step array length.' },
  { name: 'next_due_at',  type: 'string (ISO 8601) | null',  description: 'Schedule workflows only.' },
  { name: 'is_owner',     type: 'boolean',                  description: '' },
  { name: 'created_at',   type: 'string (ISO 8601)',        description: '' },
];

// ── Trigger types + their trigger_config shapes ─────────────────────────────
const triggerTypeRows: ApiRow[] = [
  { name: 'form_submitted', type: '{ form_id?, source?: { in: (manual|task)[] }, anonymous? }', description: 'Fires when a submission is APPROVED (Taskio has no separate "submit" event). form_id null = any form; conditions REQUIRE it to be set.' },
  { name: 'schedule',       type: '{ schedule: { time, day?, month?, exclusions?, tz? } }', description: 'NEVER event-dispatched — only the schedule sweep starts these runs. See the Schedule section.' },
];

// ── Condition operator × type matrix ────────────────────────────────────────
const conditionMatrixRows: ApiRow[] = [
  { name: 'text',    type: 'equals, not_equals, contains', description: 'String-normalized equality/negation; contains = substring.' },
  { name: 'number',  type: 'eq, neq, gt, gte, lt, lte',     description: 'Numeric compare; a non-numeric side always fails.' },
  { name: 'date',    type: 'before, after, on, between',    description: 'Carbon compare; between = [from,to] 2-element date array.' },
  { name: 'enum',    type: 'is, is_not, in',                description: 'String equality/negation/array membership.' },
  { name: 'multi',   type: 'includes, excludes',            description: 'Array membership over the payload\'s array value.' },
  { name: 'boolean', type: 'is_true, is_false',              description: 'VALUE-LESS — truthiness of the payload value.' },
];

// ── Step types ───────────────────────────────────────────────────────────────
const stepTypeRows: ApiRow[] = [
  { name: 'create_task',        type: '{ title (req), description?, priority?, deadline?, labels?, assignee_type?+assignee_id?, form_id?, approval_pipeline_id? }', description: 'Via TaskService::create(). Output: { task_id, title }. Assignment/form/pipeline are NOW fields on this step (folded in from the removed assign_bot/attach_form/start_approval).' },
  { name: 'create_form_report', type: '{ form_id (req), name (req), guidelines?, sources?, submissions_from?, submissions_to? }', description: 'Via FormReportService::create() — fire-and-forget queued AI report. Output: { report_id, report_name }.' },
];

// ── config/workflows.php ────────────────────────────────────────────────────
const configRows: ApiRow[] = [
  { name: 'workflows.max_runs_per_month',     type: 'WORKFLOWS_MAX_RUNS_PER_MONTH',     description: 'Default 100. SOFT per-workflow monthly budget.' },
  { name: 'workflows.max_runs_hard_cap',      type: 'WORKFLOWS_MAX_RUNS_HARD_CAP',      description: 'Default 500. ABSOLUTE workspace-wide monthly ceiling, INCLUDING manual runs.' },
  { name: 'workflows.run_timeout',            type: 'WORKFLOWS_RUN_TIMEOUT',            description: 'Default 900s. Stale-claim reaper threshold.' },
  { name: 'workflows.max_depth',              type: 'WORKFLOWS_MAX_DEPTH',              description: 'Default 3. Re-trigger chain depth guard.' },
  { name: 'workflows.assist_rate_per_minute', type: 'WORKFLOWS_ASSIST_RATE_PER_MINUTE', description: 'Default 5. AI schedule-assist per-user throttle — a SEPARATE meter from the run budget.' },
  { name: 'workflows.ai_text_max_calls_per_run', type: 'WORKFLOWS_AI_TEXT_MAX_CALLS_PER_RUN', description: 'Default 10. @[ai-text] calls allowed within ONE run — a PER-RUN budget (SB2). Beyond it: resolves to \'\', no call spent.' },
  { name: 'workflows.ai_text_max_chars',         type: 'WORKFLOWS_AI_TEXT_MAX_CHARS',           description: 'Default 2000. Length cap on each generated @[ai-text] string (SB2), multibyte-safe truncation.' },
];

// ── Manual-run 422 keys ─────────────────────────────────────────────────────
const manualRun422Rows: ApiRow[] = [
  { name: 'target_id', type: '422', description: 'Missing (form_submitted needs one) or well-formed-but-unresolvable.' },
  { name: 'workflow',  type: '422', description: 'The run-budget cap is reached (per-workflow or workspace-wide).' },
];

// ── Schedule v2: the three axes ─────────────────────────────────────────────
const scheduleTimeAxisRows: ApiRow[] = [
  { name: 'at',            type: "{ at: string[] } — 1-6 'HH:mm'", description: 'Fires at EACH listed time of day (e.g. at:["08:00","17:00"] fires twice a day). The only required axis; everything else defaults to no restriction.' },
  { name: 'every_minutes', type: '{ minutes: 1-59, from?/to?: HH:mm }', description: 'A wall-clock minute grid (:00,:15,:30,:45 for minutes:15) — not phased from when the workflow was activated. Optional window bounds it to part of the day.' },
  { name: 'every_hours',   type: '{ hours: 1-23, minute?: 0-59, from?/to?: 0-23 }', description: 'An every-N-hours grid at :minute past the hour, counted from midnight (resets at midnight, so the gap across it can be shorter than N). Optional window bounds it to a range of hours.' },
];

const scheduleDayAxisRows: ApiRow[] = [
  { name: 'every_day',    type: '— (default)', description: 'No day restriction.' },
  { name: 'every_n_days', type: '{ n: 1-31, from?/to?: 1-31 }', description: 'A day-of-month step, with an optional day-of-month window.' },
  { name: 'weekdays',     type: '{ weekdays: number[] } — 0-6, 0=Sunday', description: 'A SET of weekdays in ONE schedule (e.g. Mon+Wed+Fri).' },
  { name: 'month_days',   type: '{ days: number[] } — 1-31', description: 'A SET of calendar days. A day a shorter month lacks (31 in Feb) simply SKIPS that month — never clamped. Use the "last day" rule for a guaranteed month-end fire.' },
  { name: 'special: last_day', type: '—', description: 'The last calendar day of the month.' },
  { name: 'special: nth_weekday', type: '{ ordinal: 1-5, weekday: 0-6 }', description: 'E.g. "first Monday" (ordinal=1). ordinal=5 SKIPS a month with only 4 occurrences of that weekday.' },
  { name: 'special: last_weekday', type: '{ weekday: 0-6 }', description: 'E.g. "last Friday" — a GUARANTEED monthly fire (unlike ordinal=5).' },
  { name: 'special: last_working_day', type: '—', description: 'The last Mon-Fri of the month. Requires the time axis to be "at set times". Public holidays are NOT accounted for. Optionally restricted to specific months via the month axis.' },
];

const scheduleMonthAxisRows: ApiRow[] = [
  { name: 'every_month',    type: '— (default)', description: 'No month restriction.' },
  { name: 'every_n_months', type: '{ n: 1-12, from?/to?: 1-12 }', description: 'A month step counted from January (resets each year, so the Nov→Jan gap can be shorter than n), with an optional month-range window.' },
  { name: 'months',         type: '{ months: number[] } — 1-12', description: 'A SET of months (1=January).' },
];

// ── Schedule exclusions ──────────────────────────────────────────────────────
const scheduleExtensionRows: ApiRow[] = [
  { name: 'schedule.exclusions', type: '{ months?, weekdays?, dates? }', description: 'A post-filter evaluated after a candidate fire time is computed: drops any candidate whose month/weekday/date matches. months max 11, weekdays max 6, dates max 50 — each list alone can never exclude every value of that dimension (a combination still can, which is rejected on save).' },
];

// ── AI-text personas (SB2) ───────────────────────────────────────────────────
const aiPersonaRows: ApiRow[] = [
  { name: 'neutral',  type: 'default', description: 'Clear, neutral, professional tone.' },
  { name: 'friendly', type: '—',       description: 'Warm, approachable, conversational tone.' },
  { name: 'formal',   type: '—',       description: 'Precise, businesslike, respectful tone.' },
  { name: 'concise',  type: '—',       description: 'As short and direct as possible.' },
];

// ── Schedule-assist envelope ─────────────────────────────────────────────────
const assistEnvelopeRows: ApiRow[] = [
  { name: 'feasible',     type: 'boolean', description: 'true ONLY when config is a re-validated, compilable, faithful match. Never true for an approximation.' },
  { name: 'config',       type: 'object | null', description: 'The structured schedule config, RE-VALIDATED server-side against the write-path rules + a real compile. null when infeasible.' },
  { name: 'unsupported',  type: 'string[]', description: 'What the request needs that this vocabulary cannot express (in the caller\'s language).' },
  { name: 'alternative',  type: '{ config, note } | null', description: 'An approximating config, honestly labeled with the difference — never merged into `config`.' },
  { name: 'explanation',  type: 'string', description: 'One short paragraph summarizing what was produced (or why not) — always shown to the user.' },
];
</script>

<template>
  <StoryPage
    title="Workflows module (automation)"
    description="The 5.1 re-scoped contract: 2 trigger types, 2 step types, typed conditions/variables, a compositional time/day/month schedule descriptor with exclusions and a live preview endpoint, and AI schedule-assist. Documents implemented behavior only (Etap 5.1 B1-B7 + the schedule descriptor v2 rebuild, ADR-0012). Backend: app/modules/Workflows/."
  >

    <!-- 1. Module overview -->
    <StorySection title="Module overview — the 5.1 re-scope">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A <strong>Workflow</strong> is a stored automation DEFINITION:
          <code class="font-next-mono">trigger → conditions → an ordered list of steps</code>.
          It does nothing by itself — a <strong>WorkflowRun</strong> is one EXECUTION of that
          definition, created when the trigger fires (or a user runs it manually) and driven
          through its steps by a queued job. Each step's outcome is recorded as a
          <strong>WorkflowRunStep</strong> audit row.
        </p>

        <Alert variant="info" size="sm">
          <strong>Etap 5.1 re-scope (this page documents the CURRENT contract).</strong> The
          original build shipped 5 trigger types and 4 step types with flat, untyped
          conditions. A deliberate USER re-scope shrank this to <strong>2 trigger types</strong>
          (<code class="font-next-mono">form_submitted</code>, <code class="font-next-mono">schedule</code>)
          and <strong>2 step types</strong> (<code class="font-next-mono">create_task</code>,
          <code class="font-next-mono">create_form_report</code>) — assignment/form/pipeline
          attachment folded INTO <code class="font-next-mono">create_task</code> as fields — and
          replaced conditions with a TYPED system. See
          <code class="font-next-mono">docs/decisions/ADR-0009-workflows-rescope-typed-variables.md</code>
          for the full rationale.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Shape</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">Workflow (definition)
  trigger_type + trigger_config   (form_submitted | schedule — what starts it)
  conditions[]                    (form_submitted only: typed field/field_type/operator/value, AND-combined)
  steps[]                         (ordered actions: create_task, create_form_report)

WorkflowRun (one execution)
  state machine: pending -&gt; running -&gt; completed | failed   (waiting, cancelled reserved, unused)
  origin: event | schedule | manual
  context: { trigger: {...}, steps: { &lt;key&gt;: {...output} } }
  WorkflowRunStep[]  (one audit row per executed step, in order)</pre>
        </div>

        <p class="text-next-muted-foreground">
          A workflow is <strong>always created INACTIVE</strong>. The <code class="font-next-mono">status</code>
          field is never accepted on create/update — the ONLY way to flip it is
          <code class="font-next-mono">PATCH /workflows/{id}/status</code>. An inactive workflow's
          trigger never fires from a real domain event, but it CAN still be run manually
          (test-before-activate — see the Manual runs section).
        </p>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Backend module</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>app/modules/Workflows/Models/Workflow.php</li>
              <li>app/modules/Workflows/Models/WorkflowRun.php</li>
              <li>app/modules/Workflows/Models/WorkflowRunStep.php</li>
              <li>app/modules/Workflows/Services/WorkflowDispatchService.php</li>
              <li>app/modules/Workflows/Services/WorkflowRunManager.php</li>
              <li>app/modules/Workflows/Services/WorkflowStepRunner.php</li>
              <li>app/modules/Workflows/Services/WorkflowVariableResolver.php</li>
              <li>app/modules/Workflows/Services/WorkflowVariableCatalogService.php</li>
              <li>app/modules/Workflows/Services/WorkflowScheduleService.php (+Compiler, +RulesValidator)</li>
              <li>app/modules/Workflows/Services/WorkflowScheduleAssistService.php</li>
              <li>app/modules/Workflows/Agents/ScheduleAssistAgent.php</li>
              <li>app/modules/Workflows/Steps/ (2 step implementations)</li>
              <li>app/modules/Workflows/Jobs/WorkflowRunJob.php</li>
              <li>app/modules/Workflows/Console/ (schedule sweep + stale-run reaper)</li>
              <li>app/modules/Workflows/Policies/WorkflowPolicy.php</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Capability flags</p>
            <ul class="flex flex-col gap-next-1 text-next-xs text-next-muted-foreground">
              <li><code class="font-next-mono">is_owner</code> — HUMAN creator match only (isOwnedBy). Presentational, not authoritative — never gate an action on it alone.</li>
              <li><code class="font-next-mono">can_be_edited</code> / <code class="font-next-mono">can_be_deleted</code> / <code class="font-next-mono">can_change_status</code> — the creator, OR (a system, run-created workflow only) the workspace-owner fallback. Server-authoritative — gate UI actions on THESE, not <code class="font-next-mono">is_owner</code>.</li>
              <li><code class="font-next-mono">can_run</code> — ANY workspace member (running is not creator-gated).</li>
            </ul>
            <p class="mt-next-2 text-next-xs text-next-muted-foreground">
              Pattern mirrors Bot/Approvals. The UI should never invent authorization — always
              read these flags from the resource. See
              <code class="font-next-mono">docs/backend/creator-attribution.md</code> for the full
              ownership model (a workflow's creator is polymorphic — user | workflow_run | bot —
              though a Workflow itself is only ever created by an authenticated human today).
            </p>
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Auth + tenant scope</p>
          <p class="text-next-xs text-next-muted-foreground">
            All endpoints require <code class="font-next-mono">auth:sanctum</code> and
            <code class="font-next-mono">X-Workspace-Id</code>. The <code class="font-next-mono">TenantAware</code>
            trait automatically scopes every query to the active workspace via
            <code class="font-next-mono">WorkspaceScope</code>. Both the schedule sweep and the
            stale-run reaper explicitly iterate the shared connection AND every own-database
            workspace, so no tenant is skipped.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 2. Endpoints -->
    <StorySection title="Workflow API endpoints">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="Workflow endpoints (auth:sanctum + X-Workspace-Id)" :rows="workflowEndpointRows" type-header="Query / Body" />
        <Alert variant="info" size="sm">
          <code class="font-next-mono">GET .../runs/{run}</code> 404s if <code class="font-next-mono">{run}</code>
          belongs to a DIFFERENT workflow than the one in the URL (nested-ownership guard,
          checked explicitly in the controller) — a run does not leak across a workflow's own
          route just because both live in the same workspace.
        </Alert>
      </div>
    </StorySection>

    <!-- 3. Request/resource shapes -->
    <StorySection title="Request and resource shapes">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="WorkflowWritePayload (POST body / PUT body)" :rows="workflowWritePayloadRows" />
        <ApiTable title="WorkflowListResource (index)" :rows="workflowListResourceRows" />
        <ApiTable title="WorkflowResource (show / store / update / restore / status)" :rows="workflowResourceRows" />
      </div>
    </StorySection>

    <!-- 4. Trigger types -->
    <StorySection title="Trigger types: form_submitted + schedule">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Each trigger type validates its OWN <code class="font-next-mono">trigger_config</code>
          sub-shape. <strong>Any key outside that type's allow-list is rejected as a 422</strong>
          on <code class="font-next-mono">trigger_config.&lt;key&gt;</code> — a
          <code class="font-next-mono">schedule</code> definition can never accidentally carry a
          stray <code class="font-next-mono">form_id</code> left over from switching trigger types.
        </p>
        <ApiTable title="Trigger types + trigger_config shape" type-header="trigger_config shape" :rows="triggerTypeRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">form_submitted matching (all AND-combined; an absent clause always matches)</p>
          <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
            <li><code class="font-next-mono">form_id</code> — null = any form; present = must equal the submission's form.</li>
            <li><code class="font-next-mono">source.in</code> — a subset of <code class="font-next-mono">['manual','task']</code>, DERIVED from the submission's polymorphic submittable morph (task-attached → 'task', else 'manual'). <strong>A bot-authored submission is NOT distinguishable</strong> in this vocabulary today — see the Deferred section.</li>
            <li><code class="font-next-mono">anonymous</code> — a plain nullable boolean (not an enum); null = match either, true/false matches the form's is_anonymous flag exactly.</li>
          </ul>
        </div>

        <Alert variant="warning" size="sm">
          Fires on <code class="font-next-mono">FormSubmissionObserver::saved()</code> when a
          submission transitions into its <strong>approved</strong> state — Taskio has no separate
          "submit" event; a submission counts as submitted once approved.
        </Alert>
      </div>
    </StorySection>

    <!-- 5. Conditions -->
    <StorySection title="Typed conditions (form_submitted only)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Conditions are an OPTIONAL, flat list of <code class="font-next-mono">{ field,
          field_type, operator, value }</code> clauses, <strong>AND-combined</strong> — every
          clause must pass for the workflow to run. Conditions are <strong>ONLY valid for
          form_submitted</strong> (a 422 on a schedule trigger) and <strong>REQUIRE
          trigger_config.form_id</strong> to be set (typed conditions need ONE form's schema to
          type-check against — see ADR-0009 §6). <code class="font-next-mono">field</code> MUST be
          a <code class="font-next-mono">fields.&lt;id&gt;</code> path.
        </p>
        <ApiTable title="The operator × field_type matrix (WorkflowVariableType::operatorCases)" type-header="Allowed operators" :rows="conditionMatrixRows" />
        <Alert variant="warning" size="sm">
          <strong>Missing-path exception:</strong> when a clause's <code class="font-next-mono">field</code>
          is ABSENT from the payload, the clause FAILS for every operator EXCEPT the ones that
          ASSERT AN ABSENCE — <code class="font-next-mono">not_equals</code>,
          <code class="font-next-mono">neq</code>, <code class="font-next-mono">is_not</code>,
          <code class="font-next-mono">excludes</code> — which PASS.
          <code class="font-next-mono">"status is_not done"</code> holds when the payload carries
          no status at all, whereas <code class="font-next-mono">"status is done"</code> cannot
          hold without one. An unknown type/operator combination fails closed, never silently
          opening the gate.
        </Alert>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Example</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">"trigger_config": { "form_id": "b1b2c3d4-...", "source": null, "anonymous": null },
"conditions": [
  { "field": "fields.priority", "field_type": "enum", "operator": "is", "value": "urgent" },
  { "field": "fields.due_date", "field_type": "date", "operator": "before", "value": "2026-08-01" }
]</pre>
        </div>
      </div>
    </StorySection>

    <!-- 6. Typed variable system -->
    <StorySection title="The typed variable system">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A variable identity is always <code class="font-next-mono">{ source: trigger|steps,
          path, type }</code> — served by <code class="font-next-mono">GET
          /forms/{form}/workflow-catalog</code>. It has <strong>TWO serializations</strong>,
          both resolved by <code class="font-next-mono">WorkflowVariableResolver</code>.
        </p>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">1. Text/markdown fields — the editor directive</p>
            <p class="text-next-xs text-next-muted-foreground">
              <code class="font-next-mono">create_task.title</code>/<code class="font-next-mono">.description</code>,
              <code class="font-next-mono">create_form_report.name</code>/<code class="font-next-mono">.guidelines</code>
              use the <strong>MarkdownEditor</strong> with its variable extension. The directive
              (<code class="font-next-mono">@[variable]("...")</code>) is
              <strong>IDENTITY-ONLY</strong> — it carries <code class="font-next-mono">data.id</code>
              (the path) and the editor's rendering primitive, but <strong>NO extra workflow-type
              field</strong>. The REAL type is recovered from the CATALOG by path, never trusted
              from the directive.
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">2. Non-text fields — the &#123;kind&#125; union</p>
            <p class="text-next-xs text-next-muted-foreground">
              <code class="font-next-mono">create_task.priority</code>/<code class="font-next-mono">.deadline</code>,
              report window dates use <code class="font-next-mono">ValueOrVariableField</code> /
              <code class="font-next-mono">DateOrVariableField</code>, sending
              <code class="font-next-mono">{ kind: 'literal', value }</code> or
              <code class="font-next-mono">{ kind: 'variable', ref: { source, path, type } }</code>.
              These fields have no editor to defer to, so the <code class="font-next-mono">ref</code>
              DOES carry an explicit type.
            </p>
          </div>
        </div>

        <Alert variant="danger" size="sm">
          <strong>The wfType fiction (corrected before shipping).</strong> An early draft of the
          editor directive carried an EXTRA type field alongside identity. This was corrected: a
          type embedded in user-editable markdown is untrustworthy AND redundant — the catalog is
          already the single source of truth for a path's type. The directive shipped
          IDENTITY-ONLY. See ADR-0009 §2 for the full incident record.
        </Alert>

        <Alert variant="info" size="sm">
          <strong>Runtime operations, if-blocks, and AI text (SB1/SB2 — reverses ADR-0009 §2's
          "pipeline deferred" stance for step fields; see ADR-0013).</strong> A directive's
          <code class="font-next-mono">data.pipeline</code> (when non-empty) and a
          <code class="font-next-mono">&#123;kind:'variable'&#125;</code> union's optional
          <code class="font-next-mono">pipeline</code> now EXECUTE at run time through the shared
          <code class="font-next-mono">WorkflowOperationExecutor</code> (the same 68-operation,
          6-type engine the condition builder uses — 66 ops at SB1/SB2 time, +2 with the
          choice-coercion batch, see ADR-0014) — transforming the resolved value before it
          lands in the field. Every text field —
          <code class="font-next-mono">title</code>/<code class="font-next-mono">description</code>
          and <code class="font-next-mono">name</code>/<code class="font-next-mono">guidelines</code>
          alike — supports fenced <code class="font-next-mono">if-block</code> conditionals (pick a
          branch by a boolean pipeline, depth-capped at 6) and
          <code class="font-next-mono">@[ai-text]</code> AI-generated text (a frontend-only change:
          <code class="font-next-mono">title</code>/<code class="font-next-mono">name</code>
          originally rendered as toolbar-less one-liners offering the pipeline only, and now render
          as ordinary multi-line editors with the full toolbar — the backend resolver always treated
          every text field identically). <strong>Everything is fail-closed —
          never an exception:</strong> a bad pipeline/condition/AI call resolves to
          <code class="font-next-mono">''</code> (or the field's own soft default), never breaks
          the run — except a field's pre-existing HARD-fail doctrine (e.g. a blank
          <code class="font-next-mono">title</code>) still applies.
        </Alert>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">@[ai-text] — AI-generated text (SB2)</p>
            <p class="text-next-xs text-next-muted-foreground">
              The (already resolved) prompt + a persona are sent to a TOOL-LESS agent
              (<code class="font-next-mono">WorkflowAiTextAgent</code>, provider/model from
              <code class="font-next-mono">config('ai')</code>). Budgeted PER RUN
              (<code class="font-next-mono">ai_text_max_calls_per_run</code>, default 10 —
              beyond it: <code class="font-next-mono">''</code>, no call spent) and length-capped
              (<code class="font-next-mono">ai_text_max_chars</code>, default 2000). Nested
              <code class="font-next-mono">@[ai-text]</code> is depth-capped at 3. Personas are a
              CLOSED set of TONES — deliberately NOT the Bot/Character system (see ADR-0013 for
              why a bot-as-persona idea was left for a possible future, not built now).
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Prompt-injection posture (accepted, bounded)</p>
            <p class="text-next-xs text-next-muted-foreground">
              The resolved prompt embeds untrusted form values; the agent's instructions frame
              everything as DATA to write about, never as commands. The blast radius stays narrow
              even if that framing is defeated: NO tools, output lands only in a task/report field
              in the SAME workspace, length-capped, and can reference only the whitelisted
              <code class="font-next-mono">trigger</code>/<code class="font-next-mono">steps</code>
              context. A documented, ACCEPTED risk — not eliminated.
            </p>
          </div>
        </div>

        <ApiTable title="AI-text personas (label-less on the wire — GET .../workflow-catalog ai_personas)" :rows="aiPersonaRows" />

        <Alert variant="warning" size="sm">
          <strong>Runtime-only vs. write-validated.</strong> There is NO PHP markdown parser in
          this codebase, so a directive pipeline / if-block / <code class="font-next-mono">@[ai-text]</code>
          inside <code class="font-next-mono">title</code>/<code class="font-next-mono">description</code>/
          <code class="font-next-mono">name</code>/<code class="font-next-mono">guidelines</code>
          is NOT validated on save — a malformed one simply resolves emptier than intended at run
          time. The <code class="font-next-mono">&#123;kind:'variable'&#125;</code> union's
          pipeline (<code class="font-next-mono">priority</code>, <code class="font-next-mono">deadline</code>,
          <code class="font-next-mono">submissions_from</code>/<code class="font-next-mono">submissions_to</code>)
          is the ONE exception — it IS write-validated (type-flowed from the ref's type to the
          field's accepted terminal), a bad one is a <code class="font-next-mono">422</code> under
          <code class="font-next-mono">steps.&lt;i&gt;.config.&lt;field&gt;.pipeline.&lt;m&gt;...</code>.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">The resolver's whitelist (exfiltration-safe)</p>
          <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
            <li>Exactly TWO readable roots: <code class="font-next-mono">trigger</code> and <code class="font-next-mono">steps</code>. Any other root (<code class="font-next-mono">env.*</code>, <code class="font-next-mono">config.*</code>) is NOT recognised as a reference — no leakage surface.</li>
            <li>No filters, arithmetic, method calls, or conditionals — a plain whitelisted <code class="font-next-mono">Arr::get</code> lookup, nothing to inject into.</li>
            <li>Transitional flat <code class="font-next-mono">&#123;&#123;trigger.*&#125;&#125;</code> / <code class="font-next-mono">&#123;&#123;steps.&lt;key&gt;.*&#125;&#125;</code> tokens are STILL resolved alongside the directive/union shapes — same whitelist, different bracket syntax, kept for already-authored configs during the migration.</li>
          </ul>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          <strong>Repeaters are EXCLUDED from the catalog</strong> — a repeater's answer is an
          array-of-objects that <code class="font-next-mono">fields.&lt;id&gt;</code> cannot
          resolve to a single comparable value, so emitting a variable for one would be a dead
          path (ADR-0009 §7 — honest catalog, not an oversight).
        </p>
      </div>
    </StorySection>

    <!-- 7. Steps -->
    <StorySection title="Steps: create_task + create_form_report">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Steps run <strong>in the order they are stored</strong>, each acting ONLY through an
          existing domain service (<code class="font-next-mono">TaskService</code>,
          <code class="font-next-mono">FormReportService</code>) — never a raw model write that
          bypasses business logic. <strong>First failure stops the run</strong> — steps commit
          independently, so a workflow whose first step created a task and whose second step
          failed leaves the task in place; the run timeline shows exactly where it stopped.
        </p>
        <p class="text-next-xs text-next-muted-foreground">
          <code class="font-next-mono">title</code>/<code class="font-next-mono">description</code>/
          <code class="font-next-mono">name</code>/<code class="font-next-mono">guidelines</code>
          all resolve variable-operation pipelines, if-blocks, AND
          <code class="font-next-mono">@[ai-text]</code> identically — see "Runtime operations,
          if-blocks, and AI text" in the typed variable system section above.
          <code class="font-next-mono">priority</code> is additionally a <strong>choice field</strong>:
          its value-or-variable pipeline must END in a choice-producing op
          (<code class="font-next-mono">enum_to_choice</code> / <code class="font-next-mono">match_to_choice</code>)
          mapping into <code class="font-next-mono">TaskPriority::ids()</code> — see ADR-0014.
        </p>
        <ApiTable title="Step types" type-header="Config" :rows="stepTypeRows" />

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">create_task — hard vs. soft failure</p>
            <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-xs text-next-muted-foreground">
              <li><code class="font-next-mono">title</code> blank after resolution → <strong>HARD FAIL</strong> (whole step fails, run stops).</li>
              <li><code class="font-next-mono">priority</code> unresolved/unknown → SOFT-default <code class="font-next-mono">medium</code>.</li>
              <li><code class="font-next-mono">deadline</code> unresolved/unparseable → SOFT-default <code class="font-next-mono">null</code>.</li>
              <li>a "ghost" <code class="font-next-mono">labels</code> id (deleted label) → silently dropped, no error.</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">create_form_report — fire-and-forget</p>
            <p class="text-next-xs text-next-muted-foreground">
              Creating the report fires <code class="font-next-mono">CreateFormReportJob</code>
              (queued) — the step returns immediately, never waiting for the AI analysis.
              <code class="font-next-mono">submissions_from</code>/<code class="font-next-mono">submissions_to</code>
              default from the STEP itself (form's <code class="font-next-mono">enabled_at</code>
              → today), not copied from a request.
            </p>
          </div>
        </div>

        <Alert variant="info" size="sm">
          <code class="font-next-mono">sources</code> on <code class="font-next-mono">create_form_report</code>
          is <code class="font-next-mono">['task','form']</code> — the ANALYSIS-SOURCE vocabulary
          (which submissions count toward the report) — <strong>NOT</strong> the same vocabulary
          as the <code class="font-next-mono">form_submitted</code> trigger's
          <code class="font-next-mono">source.in</code> (<code class="font-next-mono">['manual','task']</code>,
          the SUBMITTABLE-morph vocabulary). Similar-looking, different questions — do not conflate.
        </Alert>
      </div>
    </StorySection>

    <!-- 8. Schedule -->
    <StorySection title="Schedule: the time/day/month descriptor + exclusions + live preview">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A schedule is a COMPOSITION of three independent rules that all must match for a fire to
          happen: a <strong>time</strong> rule (WHEN in the day — the only required one), a
          <strong>day</strong> rule (WHICH day — optional, defaults to every day), and a
          <strong>month</strong> rule (WHICH month — optional, defaults to every month), minus any
          <strong>exclusions</strong>. This replaces an earlier closed list of named presets: instead
          of picking one preset off a list, the three rules below are combined freely — e.g. "the
          15th of every third month" is simply the day rule + the month rule together, not a
          separate preset. Every rule is validated in ONE place
          (<code class="font-next-mono">WorkflowScheduleRulesValidator</code>) shared by the write
          path, the AI assist, and the live preview, so they can never drift apart.
        </p>
        <ApiTable title="TIME rule (required)" type-header="Fields" :rows="scheduleTimeAxisRows" />
        <ApiTable title="DAY rule (optional, defaults to every day)" type-header="Fields" :rows="scheduleDayAxisRows" />
        <ApiTable title="MONTH rule (optional, defaults to every month)" type-header="Fields" :rows="scheduleMonthAxisRows" />
        <ApiTable title="Exclusions (optional post-filter)" type-header="Shape" :rows="scheduleExtensionRows" />

        <Alert variant="info" size="sm">
          <strong>Windows.</strong> Four fields — the minute grid, the hour grid, the every-N-days
          step, and the every-N-months step — accept an OPTIONAL "from/to" window to bound them to
          part of the day, a day-of-month range, or a month range (e.g. every 15 minutes, but only
          between 09:00 and 17:00). A window is either BOTH bounds or NEITHER, and its start must
          come before its end.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">The empty-schedule guard (optional, off for preview)</p>
          <p class="text-next-xs text-next-muted-foreground">
            After every structural rule passes, the write path rejects (422 on
            <code class="font-next-mono">trigger_config.schedule.exclusions</code>) a cadence that
            would never actually fire (e.g. a weekly-on-Monday schedule whose exclusions also
            exclude Monday) — an unfireable schedule must never be saved. This guard is a parameter
            on the shared validator: the write path and AI-assist re-validation keep it ON; the live
            preview turns it OFF, so an over-constrained DRAFT comes back as "this never runs" data
            for a pre-save warning instead of a validation error mid-edit.
          </p>
        </div>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Timezone</p>
            <p class="text-next-xs text-next-muted-foreground">
              <code class="font-next-mono">schedule.tz</code> defaults to the server's own
              timezone (UTC) when omitted; a freshly-created schedule in the builder instead
              defaults to the BROWSER's timezone (an existing schedule always keeps its saved
              value). Every rule resolves in the schedule's own tz, then converts to the correct
              instant for storage — so "09:00 Europe/Warsaw" fires at the right moment year-round.
              Weekday convention: Sunday is day 0 through Saturday as day 6.
              <code class="font-next-mono">exclusions</code> are evaluated against the same tz.
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">DST: spring-forward AND fall-back</p>
            <p class="text-next-xs text-next-muted-foreground">
              <strong>Spring-forward:</strong> a requested local time that doesn't exist shifts
              FORWARD past the gap rather than crashing. <strong>Fall-back:</strong> a wall-clock
              time inside the repeated hour (e.g. Warsaw 2026-10-25, local 02:00-03:00 happens
              twice) fires TWICE that night, at two different underlying instants — the same
              instant is never fired twice, and the next day returns to a single fire. Accepted,
              not a bug.
            </p>
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Live preview — POST /workflows/meta/schedule-preview</p>
          <p class="text-next-xs text-next-muted-foreground">
            Projects the next 1-12 (default 6) fire instants of a DRAFT (not-yet-saved) schedule —
            the ONLY place occurrence dates are computed for the frontend; the FE never
            re-implements the cadence math locally. An optional <code class="font-next-mono">anchor</code>
            instant centres the projection ("jump to date"): the occurrence at-or-before the anchor
            comes first, then the rest ascend after it — the preview strip pages forward by simply
            calling again with the anchor set to the last occurrence already shown, no separate
            paging token needed. Response: <code class="font-next-mono">{ occurrences: string[]
            (ascending), count, empty, approximate }</code>. <code class="font-next-mono">empty</code>
            is true when the cadence has no reachable occurrence (never a validation error here, see
            the guard note above). <code class="font-next-mono">approximate</code> is now ALWAYS
            false (every rule is calendar-based, so a preview is always exact — the field is kept
            only for response-shape stability). Both the schedule builder's live preview strip and
            the AI-assist modal's proposal preview call this same endpoint.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">workflows:run-scheduled sweep</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            Runs every minute, without overlapping itself. The ONLY path that starts a schedule
            run — the event dispatcher hard-refuses <code class="font-next-mono">schedule</code>
            workflows by design. Requires the app's task scheduler entry to actually be registered
            on the server (cron/supervisor).
          </p>
          <p class="text-next-xs text-next-muted-foreground">
            <strong>Race-safe compare-and-swap</strong>: each due workflow's slot is claimed by a
            SINGLE conditional update — the database locks it, so exactly one concurrent sweep
            wins even under overlap. <strong>Slot-consumed doctrine:</strong> the slot advances
            the MOMENT it is claimed, BEFORE the cap check — a capped workflow consumes and
            skips its slot instead of backlog-firing later.
          </p>
        </div>

        <Alert variant="info" size="sm">
          <strong>Existing schedules keep working unchanged.</strong> A schedule saved before this
          descriptor existed is transparently upgraded to the current shape every time it is read
          or evaluated — it keeps firing and keeps rendering in the editor with no migration and no
          re-save required. Only a schedule created or edited from now on is written in the current
          shape. See <code class="font-next-mono">docs/decisions/ADR-0012-workflows-schedule-descriptor-v2.md</code>
          for the full design record.
        </Alert>
      </div>
    </StorySection>

    <!-- 9. AI schedule-assist -->
    <StorySection title="AI schedule-assist">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <code class="font-next-mono">POST /workflows/schedule-assist</code> converts a
          natural-language schedule description into a structured config, or an honest report of
          infeasibility. <strong>The model's self-report is NEVER trusted directly.</strong>
        </p>
        <Alert variant="info" size="sm">
          <strong>The prompt is generated from the schedule rules themselves.</strong> The agent's
          instructions are assembled programmatically from the same time/day/month rule
          definitions and numeric bounds the validator enforces, so the model is never told about
          an option the backend does not actually accept — the two cannot drift apart. Requests
          like "the last Friday of every month" or "daily except weekends" are genuinely
          <code class="font-next-mono">feasible:true</code>. Still honestly unsupported: a rolling
          interval not tied to the clock (e.g. "exactly every 90 minutes"), "every N weeks",
          one-off single dates, and sub-minute cadences.
        </Alert>
        <ApiTable title="Response envelope" :rows="assistEnvelopeRows" />
        <p class="text-next-xs text-next-muted-foreground">
          The result is ALWAYS shown as a reviewable proposal in the
          <code class="font-next-mono">WorkflowScheduleAssistModal</code> — never applied
          automatically, even when <code class="font-next-mono">feasible:true</code>. The modal
          renders the deterministic human-readable sentence for the proposed config plus a compact
          preview of its next occurrences (its own call to the live-preview endpoint), and the
          model's plain-text <code class="font-next-mono">note</code> when it is only proposing an
          approximating alternative — the user must press "Zastosuj" (Apply) before anything
          changes in the builder. Cancelling the modal discards the proposal entirely.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">The re-validation guarantee</p>
          <p class="text-next-xs text-next-muted-foreground">
            A <code class="font-next-mono">config</code> claimed <code class="font-next-mono">feasible:true</code>
            is RE-VALIDATED against the SAME rules the write path uses AND run through the real
            compiler as a final gate — either failing downgrades the response to
            <code class="font-next-mono">feasible:false</code>. An
            <code class="font-next-mono">alternative.config</code> that fails is dropped rather
            than surfaced broken. This means a config returned as
            <code class="font-next-mono">feasible:true</code> is GUARANTEED structurally valid
            and schedulable — the backend re-derives that itself, it does not take the model's word.
          </p>
        </div>

        <Alert variant="warning" size="sm">
          <strong>The residual honesty limit (accepted, not solved).</strong> Re-validation
          proves STRUCTURAL validity — it CANNOT prove the config SEMANTICALLY matches what the
          user asked for. If the model mis-reads "every weekday" as a plain daily schedule and
          wrongly self-reports feasible, the backend cannot detect that mismatch (the resulting
          config IS validly schedulable, just not what was meant). Mitigations: the closed rule
          vocabulary in the agent's instructions, the mandatory <code class="font-next-mono">alternative</code>
          channel for approximations (never silently merged into <code class="font-next-mono">config</code>),
          always surfacing <code class="font-next-mono">explanation</code> to the user, AND —
          unchanged from before — the review-before-apply modal above, so a wrong-but-valid
          proposal is never applied without the user seeing its sentence and preview first.
        </Alert>

        <p class="text-next-xs text-next-muted-foreground">
          <strong>429</strong> on <code class="font-next-mono">assist_rate_per_minute</code>
          (default 5) exceeded, keyed per user. <strong>Stateless, not run-cap-counted</strong> —
          an assist call creates nothing, so it is metered separately from
          <code class="font-next-mono">max_runs_per_month</code>/<code class="font-next-mono">max_runs_hard_cap</code>.
        </p>
      </div>
    </StorySection>

    <!-- 10. Dispatch pipeline + loop protection -->
    <StorySection title="Trigger dispatch pipeline + loop protection">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          Every real domain trigger AND the manual-run endpoint funnel through ONE seam,
          <code class="font-next-mono">WorkflowDispatchService</code>. The pipeline runs cheapest
          gates first, and an event that trips ANY gate is skipped SILENTLY (logged, never
          surfaced to the user whose action triggered the underlying domain event).
        </p>
        <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">1. trigger-type match   (active workflows of this type, tenant-scoped)
2. TARGETING             (form_submitted's form_id/source/anonymous — cheap in-memory check)
3. CONDITIONS             (the typed field/field_type/operator/value AND-gate)
4. loop / cost gates       (depth -&gt; per-workflow cap -&gt; workspace hard cap)
5. WorkflowRunManager::start()   (creates the pending run, defers the job)</pre>
        <p class="text-next-xs text-next-muted-foreground">
          The manual-run endpoint bypasses steps 1-3 (it targets ONE explicit workflow — a
          deliberate action, not an ambient match) but still enforces the cap gate, as a 422
          instead of a silent skip.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Observer / service seam hook points</p>
          <p class="text-next-xs text-next-muted-foreground">
            Form submission approved → <code class="font-next-mono">FormSubmissionObserver::saved()</code>.
            This is the ONLY dispatch hook left — the Etap-5 task-created / task-status-changed /
            approval-concluded hooks were removed along with their trigger types. Each hook
            defers its dispatch to <code class="font-next-mono">DB::afterCommit()</code> — ONE
            afterCommit layer per authoring write. No Laravel event bus is used (the codebase has
            none) — this mirrors the existing observer/service-seam pattern everywhere else.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Loop protection</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">WorkflowRunContext</code> tags a new run as a CHILD of the
            run currently executing on this worker (if a step authored the change that
            re-triggered): <code class="font-next-mono">depth = parent.depth + 1</code>,
            <code class="font-next-mono">origin_run_id = parent.id</code>. Otherwise a run is
            top-level (<code class="font-next-mono">depth = 0</code>). A would-be child run past
            <code class="font-next-mono">config('workflows.max_depth')</code> is refused — bounding
            a workflow that keeps re-triggering itself (or another workflow).
          </p>
          <p class="text-next-xs text-next-muted-foreground">
            The mechanism is kept exactly as-is (not simplified away) even though the 5.1 step set
            no longer directly authors an approval conclusion or status transition — a
            <code class="font-next-mono">create_task</code> step's created task can still itself
            be submitted through a form later, indirectly reaching a
            <code class="font-next-mono">form_submitted</code> trigger. Manual runs and
            schedule-sweep runs are ALWAYS top-level.
          </p>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          <strong>Origin is authoritative — creator_id is not.</strong>
          <code class="font-next-mono">WorkflowRun.origin</code> (<code class="font-next-mono">event | schedule | manual</code>)
          records how a run actually began. <code class="font-next-mono">creator_id</code> is a
          softer field, explicitly stamped by <code class="font-next-mono">WorkflowRunManager::start()</code>:
          the acting user for a MANUAL run, or the WORKFLOW'S OWN AUTHOR for an engine-started
          (event/schedule) run — so <code class="font-next-mono">creator_id</code> is never
          <code class="font-next-mono">null</code>, even on a schedule-sweep run with no HTTP
          request in play. Still: never infer engine-vs-manual from
          <code class="font-next-mono">creator_id</code> — an EVENT run carries the workflow's
          author, not necessarily whoever caused the triggering change. See
          <code class="font-next-mono">docs/backend/creator-attribution.md</code> and
          <strong>ADR-0015</strong> for the full polymorphic-creator model this run-attribution
          rule is part of.
        </p>
      </div>
    </StorySection>

    <!-- 11. Cost limits -->
    <StorySection title="Cost limits">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          A workflow RUN is the unit of cost (same doctrine as the Bot module's per-task run
          cap): rather than metering tasks/reports created separately, the NUMBER OF RUNS stands
          in as the cost proxy.
        </p>
        <ApiTable title="config/workflows.php" type-header="Env var" :rows="configRows" />
        <Alert variant="warning" size="sm">
          The workspace hard cap counts EVERY run this month — event, schedule, AND
          <strong>manual</strong>. If manual runs were exempt, a user could bypass the workspace
          cost ceiling entirely by always running workflows "manually" instead of letting them
          fire from events.
        </Alert>
        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Event / schedule at cap</p>
            <p class="text-next-xs text-next-muted-foreground">
              Skipped SILENTLY (logged only) — a capped workflow never blocks the domain action
              that would have triggered it. A capped SCHEDULE slot is still CONSUMED (advanced),
              so it does not backlog-fire every missed slot once the cap later lifts.
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Manual run at cap</p>
            <p class="text-next-xs text-next-muted-foreground">
              Refused with a <strong>422</strong> on the <code class="font-next-mono">workflow</code>
              key — a manual run is a deliberate action, so the caller needs to know it did not
              happen, not have it silently swallowed.
            </p>
          </div>
        </div>
      </div>
    </StorySection>

    <!-- 12. Run lifecycle + monitoring -->
    <StorySection title="Run lifecycle + monitoring (Runs view)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="mb-next-1 font-next-semibold text-next-fg">Run states</p>
        <ul class="flex flex-col gap-next-2 text-next-xs">
          <li class="flex items-center gap-next-2"><Badge variant="neutral" size="sm">pending</Badge><span class="text-next-muted-foreground">Run row created; job not yet claimed it.</span></li>
          <li class="flex items-center gap-next-2"><Badge variant="info" size="sm">running</Badge><span class="text-next-muted-foreground">Claimed; steps executing.</span></li>
          <li class="flex items-center gap-next-2"><Badge variant="warning" size="sm">waiting</Badge><span class="text-next-muted-foreground">RESERVED — not produced by the MVP engine (see ADR-0008 #11).</span></li>
          <li class="flex items-center gap-next-2"><Badge variant="success" size="sm">completed</Badge><span class="text-next-muted-foreground">Every step succeeded.</span></li>
          <li class="flex items-center gap-next-2"><Badge variant="danger" size="sm">failed</Badge><span class="text-next-muted-foreground">A step (or the job/worker) failed — the run stopped there.</span></li>
          <li class="flex items-center gap-next-2"><Badge variant="neutral" size="sm">cancelled</Badge><span class="text-next-muted-foreground">RESERVED — no cancel action exists yet.</span></li>
        </ul>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Atomic claim</p>
          <pre class="overflow-x-auto rounded-next-md bg-next-muted p-next-3 font-next-mono text-next-xs text-next-fg">UPDATE workflow_runs
SET state = 'running', started_at = now()
WHERE id = ? AND state = 'pending'</pre>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            A single conditional UPDATE, row-locked by Postgres — exactly one concurrent worker
            wins. A lost claim (already running/terminal) is a silent no-op — race-safe even
            under a non-sync queue.
          </p>
        </div>

        <Alert variant="danger" size="sm">
          <strong>Operational note.</strong> <code class="font-next-mono">$tries = 1</code>; the
          step runner releases a run on any step failure, and the job's <code class="font-next-mono">failed()</code>
          hook is a last-resort backstop for job-level failures. Neither covers a HARD process
          kill (SIGKILL, OOM) — that strands a run in <code class="font-next-mono">running</code>
          forever (<code class="font-next-mono">claim()</code> only matches
          <code class="font-next-mono">pending</code>). <code class="font-next-mono">workflows:reap-stale-runs</code>
          (everyFiveMinutes, releases anything stuck past <code class="font-next-mono">run_timeout</code>,
          default 900s) is the backstop for that.
        </Alert>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Monitoring — the Runs view</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">GET /workflows/{id}/runs</code> is a cursor-paginated,
            newest-first list any workspace member can read (not creator-gated — runs are
            workspace-visible read-only monitoring). It carries <code class="font-next-mono">steps_count</code>
            but EXCLUDES <code class="font-next-mono">trigger_payload</code>/<code class="font-next-mono">steps</code>
            (potentially large; detail-only). <code class="font-next-mono">GET .../runs/{run}</code>
            returns the full detail: the trigger payload and the ordered step timeline, each row
            carrying its own <code class="font-next-mono">status</code> (succeeded/failed) and
            output/error — so a failed run's timeline shows EXACTLY which step stopped it and why.
          </p>
        </div>

        <Alert variant="info" size="sm">
          <strong>GLOBAL cross-workflow feed — <code class="font-next-mono">GET /workflows/runs</code>
          (ADR-0016).</strong> Every run in the workspace, regardless of which workflow started it —
          the SAME <code class="font-next-mono">IndexWorkflowRunsRequest</code> + query builder as
          the per-workflow list above, sharing its filters
          (<code class="font-next-mono">state[]</code>/<code class="font-next-mono">origin[]</code>/
          <code class="font-next-mono">trigger_type[]</code>/<code class="font-next-mono">date_from</code>/
          <code class="font-next-mono">date_to</code>/<code class="font-next-mono">date_preset</code> —
          every filter is now an ARRAY; a legacy single-value scalar such as
          <code class="font-next-mono">?state=completed</code> still works, coerced to a one-element
          array; an unrecognised enum member is silently dropped, never 422'd), plus a global-only
          <code class="font-next-mono">workflow_id</code> scope. Rows additionally carry a
          <code class="font-next-mono">workflow</code> block
          (<code class="font-next-mono">id, name, icon, status, trigger_type</code>) — eager-loaded
          ONLY on this feed (<code class="font-next-mono">whenLoaded</code>), since the per-workflow
          list already has the workflow context from its URL. Authorization is
          <code class="font-next-mono">viewAny</code> on <code class="font-next-mono">Workflow</code>
          (any authenticated workspace member) rather than <code class="font-next-mono">view</code>
          on one workflow. Frontend: the top-level "All runs" list
          (<code class="font-next-mono">WorkflowRunsListView.vue</code>, module-nav item, route
          <code class="font-next-mono">next.workflows.runs</code>) — the only next runs surface that
          carries the mandatory <code class="font-next-mono">FilterBar</code> + Saved Views; the
          per-workflow Runs SECTION keeps its detail-nested exemption.
        </Alert>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Schedule run "reason" (frontend-computed)</p>
            <p class="text-next-xs text-next-muted-foreground">
              A SCHEDULE-origin run's detail additionally carries
              <code class="font-next-mono">schedule_descriptor</code> — the parent workflow's v2
              schedule block, upgraded via <code class="font-next-mono">LegacyScheduleUpgrader</code>.
              This is DATA only; the human sentence ("pierwszy czwartek o 14:00 w lipcu") is computed
              on the FRONTEND (<code class="font-next-mono">describeOccurrence</code> in
              <code class="font-next-mono">workflowSchedule.ts</code>, reusing the schedule builder's
              own clause grammar) from <code class="font-next-mono">schedule_descriptor</code> +
              the run's <code class="font-next-mono">trigger_payload.scheduled_at</code> — the same
              FE-owns-the-grammar split ADR-0012 established for the builder's summary sentence. Falls
              back to a plain timestamp when the occurrence cannot be named semantically.
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Form/submission run detail + diff</p>
            <p class="text-next-xs text-next-muted-foreground">
              A <code class="font-next-mono">form_submitted</code> run's detail renders a Form card
              (opens the form in a new tab) and a Submission card, which opens
              <code class="font-next-mono">SubmissionPreviewDrawer</code>
              (<code class="font-next-mono">pages/forms/</code>) in its new
              <code class="font-next-mono">diff</code> mode: the run's frozen answer snapshot
              (<code class="font-next-mono">trigger_payload.fields</code>) compared against the
              submission's CURRENT answers (<code class="font-next-mono">GET
              /api/form-submissions/{id}</code>), highlighting changed fields with the project-wide
              <code class="font-next-mono">next-modified</code> token
              (<code class="font-next-mono">Badge variant="modified"</code>,
              <code class="font-next-mono">bg-next-modified-subtle</code>) — never color-only, always
              paired with a "Changed" label/icon.
            </p>
          </div>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          The origin/trigger-type pair collapses into ONE "source" badge on the run row and detail
          header (an i18n-only relabel — the <code class="font-next-mono">event</code> wire value is
          unchanged; it now reads "Wysłanie formularza"/"Form submission" instead of the generic
          "Zdarzenie"/"Event"). The run-now flow's <code class="font-next-mono">form_submitted</code>
          target moved from a raw submission-id <code class="font-next-mono">TextInput</code> to a
          Pick (<code class="font-next-mono">SubmissionPickerDrawer</code>, a lean list scoped to the
          trigger's bound form) / Create (<code class="font-next-mono">FormFillView</code> in a
          drawer, producing a REAL submission) pair —
          <code class="font-next-mono">SubmissionCard</code> gained a <code class="font-next-mono">selectable</code>
          prop for this. The <code class="font-next-mono">POST /workflows/{id}/run
          {target_id}</code> contract and its 422 bag (<code class="font-next-mono">target_id</code> /
          <code class="font-next-mono">workflow</code>) are UNCHANGED — only how the id is obtained
          changed. Full UX spec: <code class="font-next-mono">docs/next/workflows-uxui-spec.md</code>
          §5/§6 (REVISION 6); design record:
          <code class="font-next-mono">docs/decisions/ADR-0016-workflows-global-runs-and-schedule-reason.md</code>.
        </p>
      </div>
    </StorySection>

    <!-- 13. Manual runs -->
    <StorySection title="Manual runs / test runs">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <code class="font-next-mono">POST /workflows/{id}/run</code> works on ANY workflow,
          <strong>including an INACTIVE one</strong> — this is the test-before-activate path: build
          a workflow, run it manually against a real target, verify the step chain, THEN activate
          it. Any workspace member may run (not creator-gated). The manual payload is built by the
          SAME <code class="font-next-mono">WorkflowTriggerPayloadFactory</code> a real trigger
          uses, so a test-run proves what a live trigger would actually do.
        </p>
        <p class="text-next-xs text-next-muted-foreground">
          Target requirement by trigger type: <code class="font-next-mono">form_submitted</code>
          needs a FormSubmission id; <code class="font-next-mono">schedule</code> needs no target
          at all (omit <code class="font-next-mono">target_id</code>).
        </p>
        <ApiTable title="Manual-run 422 error keys — EXACTLY two (mapped by KEY, never message)" type-header="Status" :rows="manualRun422Rows" />
        <Alert variant="info" size="sm">
          The 5.1 re-scope DROPPED the Etap-5 <code class="font-next-mono">approval_process</code>
          key — it belonged to the <code class="font-next-mono">approval_finished</code> trigger,
          which no longer exists. The bag is now exactly <code class="font-next-mono">target_id</code>
          + <code class="font-next-mono">workflow</code>.
        </Alert>
      </div>
    </StorySection>

    <!-- 14. Frontend module -->
    <StorySection title="Frontend module">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <strong>Files:</strong> <code class="font-next-mono">resources/js/next/pages/workflows/</code>.
          The module's identity icon (nav, PageHeader, aside header, empty state, entity-card
          fallback) is <code class="font-next-mono">workflow</code> — a NEW glyph added to the icon
          registry, because the original candidate (<code class="font-next-mono">git-branch</code>)
          collided with Approvals' own top-level nav icon.
          <code class="font-next-mono">git-branch</code> remains a SECTION glyph within Workflows.
        </p>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Schedule builder — a three-tab surface over time/day/month</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">WorkflowScheduleBuilder.vue</code> is the host: a summary
            sentence + "Zaplanuj z AI" opener (<code class="font-next-mono">WorkflowScheduleSummary</code>),
            an upcoming-runs preview strip (<code class="font-next-mono">WorkflowSchedulePreviewStrip</code>),
            three tabs — Czas / Dzień / Miesiąc — each rendering a `SegmentedControl` of sub-modes
            over ONE shared draft (<code class="font-next-mono">WorkflowScheduleTimePanel</code>,
            <code class="font-next-mono">WorkflowScheduleDayPanel</code>,
            <code class="font-next-mono">WorkflowScheduleMonthPanel</code>, all sharing the "od–do"
            window pattern via <code class="font-next-mono">WorkflowScheduleWindowField</code>), a
            collapsed-by-default Exceptions section, and an optional timezone field. There is no
            simple/advanced split anymore — every schedule is built from the same three tabs,
            whether it is "daily at 9" or "the 15th and last day of the month, except August, at 8
            and 17".
          </p>
          <p class="mt-next-2 text-next-xs text-next-muted-foreground">
            The preview strip supports a "Skocz do daty" (jump to date) anchor: setting it re-seeds
            the strip with the previous run (visually distinct) followed by the runs after it, and
            scrolling to the end of the strip lazily loads more. The AI path is a MODAL
            (<code class="font-next-mono">WorkflowScheduleAssistModal</code>, opened from the
            summary's "Zaplanuj z AI" button) rather than an inline panel: it composes a
            natural-language prompt, shows the result as a reviewable proposal (sentence + compact
            preview), and only changes the builder's draft once the user presses "Zastosuj" — it
            never applies a result automatically. The pure helpers behind all of this
            (<code class="font-next-mono">workflowSchedule.ts</code>: the draft type, client-side
            validators, the <code class="font-next-mono">describeSchedule</code> sentence grammar
            in Polish and English, and the draft ⇄ wire config mapping) own every numeric bound and
            every label — there is no discovery call to a backend vocabulary endpoint.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Schedule i18n — <code class="font-next-mono">workflows.schedule.*</code></p>
          <p class="text-next-xs text-next-muted-foreground">
            Every visible string is a translation key, grouped by concern:
            <code class="font-next-mono">tab.*</code> (the three tab labels),
            <code class="font-next-mono">time.*</code> / <code class="font-next-mono">day.*</code> /
            <code class="font-next-mono">month.*</code> (each mode's label + its own helper notes,
            e.g. the "last working day" restriction note or the "fifth occurrence can skip a month"
            note), <code class="font-next-mono">field.*</code> / <code class="font-next-mono">unit.*</code>
            (control labels and units), <code class="font-next-mono">window.*</code> (the shared
            "od–do" pattern), <code class="font-next-mono">weekday.*</code> / <code class="font-next-mono">month.*</code>
            (day/month names, short and long forms), <code class="font-next-mono">exclusions.*</code>,
            <code class="font-next-mono">tz.*</code>, <code class="font-next-mono">preview.*</code>
            (the strip's states and labels), <code class="font-next-mono">assist.*</code> (the AI
            modal's copy), <code class="font-next-mono">validation.*</code> (client-side error
            copy), and <code class="font-next-mono">describe.*</code> (the sentence-grammar
            templates, including the Polish plural/case tables the grammar needs). PL and EN are
            kept in full parity.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Variable add-ons — two, not three; redesigned into a single in-field control + Modal (SF3.3-5)</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">ValueOrVariableField.vue</code> (generic literal-or-variable)
            and <code class="font-next-mono">DateOrVariableField.vue</code> (date-specific, needs
            its own date-picker literal control) — two concrete, purpose-built add-ons for the two
            structured-field SHAPES that exist today, rather than one over-parameterized component.
            Both echo the editor's <code class="font-next-mono">VariableChip</code> look without
            importing it directly (different underlying data models — directive/ProseMirror state
            vs. the <code class="font-next-mono">{kind}</code> union). <strong>SF3.3-5</strong>
            redesigned the field into a single bordered, input-like box: a compact
            <code class="font-next-mono">pencil</code>/<code class="font-next-mono">braces</code>
            two-icon toggle sits inside the box (replacing SF1's <code class="font-next-mono">SegmentedControl</code>),
            a picked variable renders as a chip AS the field's value, and clicking the chip opens an
            OPERATIONS MODAL (the shared <code class="font-next-mono">VariablePipelineEditor</code> +
            a live "Returns …" gate + a Save blocked until the pipeline satisfies the field) instead
            of expanding an inline editor under the field. <strong>SF3.2</strong> dropped the
            picker's type pre-filter — every field now offers EVERY referenceable variable
            (identifiers still stripped) and relies on the pipeline to coerce it, including mapping
            into a fixed CHOICE set for <code class="font-next-mono">priority</code>
            (<code class="font-next-mono">enum_to_choice</code>/<code class="font-next-mono">match_to_choice</code>,
            ADR-0014). A field whose SAVED pipeline does not satisfy its contract shows a danger
            skin + an "action required" chip pill + one inline helper line, and blocks the step
            card/drawer's Save — derived from the saved config so it engages even while the step
            card is collapsed (auto-expanding it on hydration, same as an existing 422 already did).
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Step editor: ordered list, no canvas — collapsible cards + type-selection cards (SF2)</p>
          <p class="text-next-xs text-next-muted-foreground">
            The step model is strictly LINEAR (one ordered list, no branching, no parallel
            paths). The editor uses the SAME ▲▼ reorder pattern as the Approvals pipeline builder
            (<code class="font-next-mono">WorkflowStepListEditor.vue</code> /
            <code class="font-next-mono">WorkflowStepCard.vue</code>) rather than a visual
            node-and-edge canvas — unchanged from Etap-5 (ADR-0008 #14), just with 2 step types
            instead of 4. <strong>SF2:</strong> adding a step is now a grid of SELECTION CARDS (one
            per type, matching the trigger-step's own card look) rather than a dropdown menu; every
            step card is COLLAPSIBLE — a fresh workflow's single step starts open, an existing
            multi-step workflow starts fully collapsed to one-line summaries (the step's
            title/name with its variable chips stripped to their names), and any card carrying a
            422/duplicate-key error auto-expands so a failed save always lands on the field to fix.
          </p>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          See <code class="font-next-mono">docs/decisions/ADR-0009-workflows-rescope-typed-variables.md</code>
          for the full 5.1 reasoning, <code class="font-next-mono">docs/decisions/ADR-0012-workflows-schedule-descriptor-v2.md</code>
          for the current schedule descriptor's design reasoning (the compositional time/day/month
          model, the wall-clock-grid semantics, the anchored live preview, the AI-modal-with-approval
          flow — and what it supersedes from the earlier family-based
          <code class="font-next-mono">docs/decisions/ADR-0010-workflows-schedule-rebuild.md</code>),
          <code class="font-next-mono">docs/decisions/ADR-0013-workflows-step-operations-conditionals-ai-text.md</code>
          for the runtime operations/if-block/AI-text reasoning (and how it reverses ADR-0009 §2's
          "pipeline deferred" stance), <code class="font-next-mono">docs/decisions/ADR-0014-workflows-choice-coercion.md</code>
          for the choice-coercion design record (why a generic <code class="font-next-mono">enum</code>
          type + per-field <code class="font-next-mono">targetOptions</code> + a
          <code class="font-next-mono">producesChoice()</code> terminal rule, instead of a new
          branded type), and <code class="font-next-mono">docs/next/workflows-uxui-spec.md</code>
          §4.5 (REVISION 4), §4.6/§4.7/§4.9 (SB1/SB2/SF1/SF2/SF3 as-built updates) for the complete
          UX/UI specification these frontend decisions were drawn from.
        </p>
      </div>
    </StorySection>

    <!-- 15. Planned / deferred -->
    <StorySection title="Planned / deferred (not implemented)">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <ul class="flex list-disc flex-col gap-next-2 pl-next-5 text-next-xs text-next-muted-foreground">
          <li><strong>Bot-authored submission tracking</strong> — <code class="font-next-mono">source</code> cannot express "a bot filled this form in" (it only derives manual/task from the submittable morph). Would need a new column, not just morph-derived logic.</li>
          <li><strong>Wait-for-approval resume</strong> — <code class="font-next-mono">WorkflowRunState.WAITING</code> is declared but never produced. There is no longer a <code class="font-next-mono">start_approval</code> step at all in the 5.1 step set.</li>
          <li><strong>Manual run cancellation</strong> — <code class="font-next-mono">WorkflowRunState.CANCELLED</code> is declared but no cancel action exists.</li>
          <li><strong>~~An operations pipeline for the typed variable system~~ — DONE (SB1, ADR-0013), no longer deferred.</strong> A directive/value-or-variable reference now transforms its value through the shared 68-operation executor at run time (66 at SB1 time, +2 with the choice-coercion batch — ADR-0014); ADR-0009 §2's "deferred" consequence is explicitly reversed by ADR-0013.</li>
          <li><strong>~~Mapping a value into a fixed destination option set (a task priority)~~ — DONE (ADR-0014), no longer deferred.</strong> <code class="font-next-mono">enum_to_choice</code> / <code class="font-next-mono">match_to_choice</code> let a <code class="font-next-mono">priority</code> value-or-variable pipeline map an arbitrary source into <code class="font-next-mono">TaskPriority::ids()</code>; the write validator now REQUIRES this for a choice field (a bare ref or a non-choice terminal like <code class="font-next-mono">enum_to_text</code> is rejected) — a validator-only tightening, runtime coercion is unchanged.</li>
          <li><strong>Bot/Character as an AI-text persona</strong> — <code class="font-next-mono">@[ai-text]</code>'s personas are a small, fixed set of TONES (neutral/friendly/formal/concise), deliberately NOT the Bot/Character system. Letting an author pick "write like Bot X" is a plausible future extension, not built now (see ADR-0013 §4).</li>
          <li><strong>Step-output stems are still a hand-written FE mirror</strong> — <code class="font-next-mono">workflowVariables.ts</code>'s <code class="font-next-mono">STEP_OUTPUTS</code> constant duplicates the backend's per-step-type output descriptors rather than reading them from the live catalog (the catalog's own <code class="font-next-mono">source:'steps'</code> entries are explicitly dropped). A backend output rename would silently desync from this mirror. Tracked, not fixed by this doc pass — see <code class="font-next-mono">docs/next/workflows-uxui-spec.md</code> §4.7.3.</li>
          <li><strong>The condition TREE builder (groups of AND/OR + typed pipelines)</strong> — a separately-developed rebuild of the Conditions section (<code class="font-next-mono">WorkflowConditionEngine</code>, <code class="font-next-mono">WorkflowConditionModal.vue</code>/<code class="font-next-mono">WorkflowConditionGroup.vue</code>) shares the SAME operations executor this page's typed-variable-system section describes, but its own API/UX documentation (this page's "Typed conditions" section, still describing the legacy flat clause list) has not yet been updated to match — a known documentation gap, not part of this pass's scope.</li>
          <li><strong>TaskSelect extraction</strong> — largely MOOT after the re-scope (the standalone <code class="font-next-mono">task_id</code> fields it would have served, on the removed <code class="font-next-mono">assign_bot</code>/<code class="font-next-mono">attach_form</code>/<code class="font-next-mono">start_approval</code> steps, no longer exist). <strong>~~The manual-run FormSubmission target picker had a raw-TextInput gap~~ — DONE.</strong> Replaced by a Pick (<code class="font-next-mono">SubmissionPickerDrawer</code>) / Create (<code class="font-next-mono">FormFillView</code> in a drawer) pair — see "Global runs feed + monitoring" above and ADR-0016.</li>
          <li><strong>Visual canvas builder</strong> — only warranted if the step model grows real branching/parallelism; the current linear model is well served by the ▲▼ list.</li>
          <li><strong>Per-tenant error isolation in the sweep commands</strong> — <code class="font-next-mono">workflows:run-scheduled</code> / <code class="font-next-mono">workflows:reap-stale-runs</code> have no per-tenant try/catch yet (consistent with the existing Bot reaper pattern; hardening queued separately).</li>
          <li><strong>Public holiday awareness</strong> — the "last working day" rule (and every other schedule rule) has no holiday-calendar concept; a fire date landing on a holiday still fires normally. Would need a real holiday-calendar data source.</li>
          <li><strong>Rolling intervals, every-N-weeks, one-off dates, and sub-minute cadences</strong> — the schedule vocabulary has no cadence phased from an arbitrary start rather than the wall clock (e.g. "exactly every 90 minutes"), no "every N weeks" rule, no single one-off-date cadence, and no sub-minute grid. Named explicitly in the AI-assist's honest-unsupported list rather than silently approximated.</li>
        </ul>
      </div>
    </StorySection>

  </StoryPage>
</template>
