<script setup lang="ts">
// Gallery: Workflows (automation) module — module overview, the 5.1 typed contract
// (2 trigger types, 2 step types, typed conditions, the variable directive/union
// system), the schedule builder (16 families, times/exclusions, live preview) +
// AI schedule-assist, run lifecycle, manual/test runs, cost limits, and
// monitoring. Documents the IMPLEMENTED behavior of app/modules/Workflows/ AFTER
// the Etap 5.1 re-scope (B1-B7) AND the schedule rebuild (B1-B5, ADR-0010) — not
// planned behavior. Deferred/planned items are called out explicitly (see the
// last section).
//
// Sections:
//   1. Module overview & concepts (the 5.1 re-scope)
//   2. Workflow API endpoints
//   3. Request/resource shapes + capability flags
//   4. Trigger types: form_submitted + schedule
//   5. Typed conditions (form_submitted only)
//   6. The typed variable system (directive + {kind} union)
//   7. Steps: create_task + create_form_report
//   8. Schedule: 16 families + the compiler + times/exclusions + live preview
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
  { name: 'GET /workflows/{id}/runs',      type: '?state=&origin=&cursor=',  description: 'Run monitoring list. Cursor-paginated, 15/page. Any workspace member.' },
  { name: 'GET /workflows/{id}/runs/{run}', type: '—',                       description: 'One run + its full step timeline. 404 if {run} belongs to a different workflow.' },
  { name: 'GET /workflows/meta/schedule-families', type: '—',                description: 'Discovery: the 16 schedule families + per-family param descriptors.' },
  { name: 'POST /workflows/meta/schedule-preview', type: '{ schedule, count? }', description: 'Live preview: projects the next N (1-12, default 6) fire instants of a draft schedule. The ONLY place occurrence dates are computed — the FE never re-implements cadence math.' },
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
  { name: 'creator',               type: 'UserResource',              description: '' },
  { name: 'is_owner',              type: 'boolean',                   description: 'True when auth user is the creator.' },
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
  { name: 'schedule',       type: '{ schedule: { family, params, tz? } }', description: 'NEVER event-dispatched — only the schedule sweep starts these runs. See the Schedule section.' },
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
];

// ── Manual-run 422 keys ─────────────────────────────────────────────────────
const manualRun422Rows: ApiRow[] = [
  { name: 'target_id', type: '422', description: 'Missing (form_submitted needs one) or well-formed-but-unresolvable.' },
  { name: 'workflow',  type: '422', description: 'The run-budget cap is reached (per-workflow or workspace-wide).' },
];

// ── Schedule families (16) ──────────────────────────────────────────────────
const scheduleFamilyRows: ApiRow[] = [
  { name: 'every_n_minutes',   type: '{ n: 1-59 }', description: 'BESPOKE interval (not cron): from + n minutes, phased on the arm instant, no wall-clock snapping.' },
  { name: 'hourly',            type: '—', description: 'Next top-of-hour strictly after from.' },
  { name: 'hourly_at',         type: '{ minute: 0-59 }', description: 'Minute M of every hour.' },
  { name: 'every_n_hours',     type: '{ n: 2-12, minute? }', description: 'HOUR-OF-DAY MODULO N (e.g. n=5 → 00,05,10,15,20 then resets at midnight) — not a rolling interval.' },
  { name: 'daily',             type: "{ time: 'HH:mm' }", description: 'Today at time if still future, else tomorrow. Accepts times[] (see below).' },
  { name: 'twice_daily',       type: '{ first_hour (lt second_hour), second_hour, minute? }', description: 'Two explicit daily fires. Does NOT accept times[] (no time param).' },
  { name: 'weekly',            type: '{ weekdays: weekday_list, time }', description: 'weekdays is a non-empty LIST of distinct 0-6 (0=Sunday) — several days per week in one schedule. A legacy scalar weekday is read-tolerated on old rows only; new writes must use weekdays.' },
  { name: 'monthly',           type: '{ day: 1-31, time }', description: 'Day-31 SKIP in shorter months (not clamped) — use last_day_of_month for a guaranteed month-end fire.' },
  { name: 'twice_monthly',     type: '{ first_day (lt second_day), second_day, time }', description: 'Two explicit monthly fires.' },
  { name: 'last_day_of_month', type: '{ time }', description: 'Cron `L` token — GUARANTEED month-end fire.' },
  { name: 'quarterly',         type: '{ day: 1-31, time }', description: 'Day D of Jan/Apr/Jul/Oct.' },
  { name: 'yearly',            type: '{ month: 1-12, day: 1-31, time }', description: 'month=2 day=29 fires ONLY in leap years.' },
  { name: 'every_n_months',    type: '{ n: 2-6, day: 1-31, time }', description: 'NEW. JANUARY-ANCHORED month grid (1, 1+n, 1+2n, … ≤12), modulo the year — mirrors every_n_hours’ doctrine. The Nov→Jan gap can be shorter than n.' },
  { name: 'nth_weekday_of_month', type: '{ ordinal: 1-5, weekday: 0-6, time }', description: 'NEW. E.g. "first Monday" (ordinal=1). ordinal=5 SKIPS a month with only 4 occurrences of that weekday.' },
  { name: 'last_weekday_of_month', type: '{ weekday: 0-6, time }', description: 'NEW. E.g. "last Friday" — a GUARANTEED monthly fire (unlike ordinal=5).' },
  { name: 'last_working_day_of_month', type: '{ time }', description: 'NEW. The last Mon-Fri of the month. BESPOKE (not cron) — dragonmantank’s LW token is defective in the installed version. Public holidays NOT accounted for.' },
];

// ── Schedule block extensions (times / exclusions) ─────────────────────────
const scheduleExtensionRows: ApiRow[] = [
  { name: 'schedule.times',      type: "string[] 1-6 'HH:mm'", description: 'Multiple fire times, replacing params.time. Only for families with a time param; mutually exclusive with params.time. Compiles to one cron expression per time — the earliest strictly-after candidate wins.' },
  { name: 'schedule.exclusions', type: '{ months?, weekdays?, dates? }', description: 'A post-filter (NOT part of the cron grammar): drops any candidate whose month/weekday/date matches. months max 11, weekdays max 6, dates max 50 — each list alone can never exclude every value of that dimension.' },
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
    description="The 5.1 re-scoped contract: 2 trigger types, 2 step types, typed conditions/variables, a 16-family schedule compiler with times/exclusions and a live preview endpoint, and AI schedule-assist. Documents implemented behavior only (Etap 5.1 B1-B7 + the schedule rebuild B1-B5, ADR-0010). Backend: app/modules/Workflows/."
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
              <li><code class="font-next-mono">is_owner</code> — creator_id === auth user (UI gating for edit/delete/status).</li>
              <li><code class="font-next-mono">can_be_edited</code> / <code class="font-next-mono">can_be_deleted</code> / <code class="font-next-mono">can_change_status</code> — creator-only, server-authoritative.</li>
              <li><code class="font-next-mono">can_run</code> — ANY workspace member (running is not creator-gated).</li>
            </ul>
            <p class="mt-next-2 text-next-xs text-next-muted-foreground">
              Pattern mirrors Bot/Approvals. The UI should never invent authorization — always
              read these flags from the resource.
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
    <StorySection title="Schedule: 16 families + the compiler + times/exclusions + live preview">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          The 4 Etap-5 hand-coded presets were replaced by descriptor-driven
          families compiled to a REAL cron expression (via
          <code class="font-next-mono">dragonmantank/cron-expression</code>) — only
          <code class="font-next-mono">every_n_minutes</code> and (see below)
          <code class="font-next-mono">last_working_day_of_month</code> stay bespoke. Every
          family's param shape is declared ONCE (<code class="font-next-mono">WorkflowScheduleFamily::paramDescriptors()</code>)
          and drives THREE consumers: write-path validation, the
          <code class="font-next-mono">/meta/schedule-families</code> discovery endpoint, and the
          AI-assist's prompt vocabulary — they cannot drift apart.
        </p>
        <Alert variant="info" size="sm">
          <strong>Schedule rebuild (B1-B5).</strong> The vocabulary grew from 12 to
          <strong>16 families</strong> (<code class="font-next-mono">every_n_months</code>,
          <code class="font-next-mono">nth_weekday_of_month</code>,
          <code class="font-next-mono">last_weekday_of_month</code>,
          <code class="font-next-mono">last_working_day_of_month</code> added),
          <code class="font-next-mono">weekly</code> moved from a single weekday to a LIST, and
          the schedule block gained two optional keys (<code class="font-next-mono">times</code>,
          <code class="font-next-mono">exclusions</code>) plus a new live preview endpoint. See
          <code class="font-next-mono">docs/decisions/ADR-0010-workflows-schedule-rebuild.md</code>
          for the full rationale.
        </Alert>
        <ApiTable title="WorkflowScheduleFamily (16)" type-header="Params" :rows="scheduleFamilyRows" />

        <ApiTable title="Schedule block extensions (both optional)" type-header="Shape" :rows="scheduleExtensionRows" />

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">The empty-schedule guard (optional, off for preview)</p>
          <p class="text-next-xs text-next-muted-foreground">
            After every structural rule passes, the write path rejects (422 on
            <code class="font-next-mono">trigger_config.schedule.exclusions</code>) a cadence whose
            <code class="font-next-mono">exclusions</code> rule out EVERY occurrence (e.g. weekly-on-
            Monday that also excludes Monday) — an unfireable schedule must never persist. This
            guard is a parameter on the shared validator
            (<code class="font-next-mono">WorkflowScheduleRulesValidator::secondPass(checkEmpty:
            …)</code>): the write path and AI-assist re-validation keep it ON; the live
            <code class="font-next-mono">schedule-preview</code> endpoint turns it OFF, so an
            over-constrained DRAFT comes back as <code class="font-next-mono">{ empty: true }</code>
            data for a pre-save warning instead of a 422 mid-edit.
          </p>
        </div>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">Timezone</p>
            <p class="text-next-xs text-next-muted-foreground">
              <code class="font-next-mono">schedule.tz</code> defaults to
              <code class="font-next-mono">config('app.timezone')</code> (UTC). Wall-clock
              families resolve IN the schedule's own tz, then convert to UTC for storage — so
              "09:00 Europe/Warsaw" fires at the correct UTC instant year-round. Weekday
              convention: <code class="font-next-mono">0 = Sunday</code> .. 6 = Saturday.
              <code class="font-next-mono">exclusions</code> are evaluated against the candidate
              re-expressed in this same tz.
            </p>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">DST: spring-forward AND fall-back</p>
            <p class="text-next-xs text-next-muted-foreground">
              <strong>Spring-forward:</strong> a requested local time that doesn't exist shifts
              FORWARD past the gap rather than crashing. <strong>Fall-back (pinned by tests in this
              revision):</strong> a wall-clock time inside the repeated hour (e.g. Warsaw
              2026-10-25, local 02:00-03:00 happens twice) fires TWICE that night at two DISTINCT
              UTC instants — the strictly-after invariant guarantees the same UTC moment is never
              fired twice; the next day returns to a single fire. Accepted, not a bug.
            </p>
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg text-next-sm">last_working_day_of_month is BESPOKE, not cron</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">dragonmantank/cron-expression</code> v3.6.0's
            <code class="font-next-mono">LW</code> token is DEFECTIVE (it parses the
            <code class="font-next-mono">L</code> as day 0, normalizing to the PREVIOUS month and
            returning wrong dates — verified against the installed version). "Last working day" also
            cannot be expressed as any single standard cron expression (it's the latest of {last
            Mon, …, last Fri}). <code class="font-next-mono">WorkflowScheduleService</code>
            therefore computes it directly: the month's last calendar day, stepped backward over
            Sat/Sun. Public holidays are NOT accounted for. See ADR-0010 §4.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Live preview — POST /workflows/meta/schedule-preview</p>
          <p class="text-next-xs text-next-muted-foreground">
            Projects the next 1-12 (default 6) fire instants of a DRAFT (not-yet-saved) schedule —
            the ONLY place occurrence dates are computed for the frontend; the FE never
            re-implements cron/interval math locally. Response:
            <code class="font-next-mono">{ occurrences: string[] (ISO-8601 UTC, ascending), count,
            empty, approximate }</code>. <code class="font-next-mono">empty</code> is true when the
            cadence has no reachable occurrence (never a 422 here, see the guard note above).
            <code class="font-next-mono">approximate</code> is true ONLY for
            <code class="font-next-mono">every_n_minutes</code> (its phase is set at activation, not
            the calendar, so a preview from "now" is indicative). Both the schedule builder's live
            preview and the AI-assist's alternative preview call this same endpoint.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">workflows:run-scheduled sweep</p>
          <p class="mb-next-2 text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">everyMinute() + withoutOverlapping()</code>. The ONLY
            path that starts a schedule run — the event dispatcher hard-refuses
            <code class="font-next-mono">schedule</code> workflows by design. Requires
            <code class="font-next-mono">schedule:run</code> on cron/supervisor.
          </p>
          <p class="text-next-xs text-next-muted-foreground">
            <strong>Race-safe compare-and-swap</strong>: each due workflow's slot is claimed by a
            SINGLE conditional UPDATE — Postgres row-locks it, so exactly one concurrent sweep
            wins even under overlap. <strong>Slot-consumed doctrine:</strong> the slot advances
            the MOMENT the CAS is won, BEFORE the cap check — a capped workflow consumes and
            skips its slot instead of backlog-firing later.
          </p>
        </div>
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
          <strong>Wider vocabulary (schedule rebuild).</strong> The agent's prompt is generated
          from <code class="font-next-mono">WorkflowScheduleFamily::paramDescriptors()</code> at
          runtime, so it picked up the 4 new families plus
          <code class="font-next-mono">times</code>/<code class="font-next-mono">exclusions</code>
          automatically. Requests like "the last Friday of every month" or "daily except weekends"
          are now genuinely <code class="font-next-mono">feasible:true</code> — previously they
          could only reach the <code class="font-next-mono">alternative</code> channel. Still
          honestly unsupported: no every-N-days family, and no continuous time-window cadence
          (only discrete <code class="font-next-mono">times[]</code> fire points).
        </Alert>
        <ApiTable title="Response envelope" :rows="assistEnvelopeRows" />
        <p class="text-next-xs text-next-muted-foreground">
          When the response is infeasible WITH an alternative, the frontend's
          <code class="font-next-mono">WorkflowScheduleAssist.vue</code> now renders a PREVIEW of
          the alternative before the user applies it — the deterministic
          <code class="font-next-mono">describeSchedule</code> sentence, the model's plain-text
          <code class="font-next-mono">note</code>, and the alternative's next 4 occurrences (its
          own call to <code class="font-next-mono">POST schedule-preview</code>) — so the user sees
          exactly what they'd get before committing to it.
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
            and compilable — the backend re-derives that itself, it does not take the model's word.
          </p>
        </div>

        <Alert variant="warning" size="sm">
          <strong>The residual honesty limit (accepted, not solved).</strong> Re-validation
          proves STRUCTURAL validity and compilability — it CANNOT prove the config SEMANTICALLY
          matches what the user asked for. If the model mis-reads "every weekday" as
          <code class="font-next-mono">daily</code> and wrongly self-reports feasible, the
          backend cannot detect that mismatch (the resulting config IS validly compilable, just
          not what was meant). Mitigations: the closed family vocabulary in the agent's
          instructions, the mandatory <code class="font-next-mono">alternative</code> channel for
          approximations (never silently merged into <code class="font-next-mono">config</code>),
          and always surfacing <code class="font-next-mono">explanation</code> to the user.
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
          softer field: <code class="font-next-mono">HasCreator</code> stamps
          <code class="font-next-mono">auth()->id()</code> on every save whenever unset, so an
          EVENT run fired inside an authenticated request still carries that user as creator —
          never infer engine-vs-manual from <code class="font-next-mono">creator_id</code>.
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
          <p class="mb-next-1 font-next-semibold text-next-fg">Schedule builder — simple/advanced two-mode (schedule rebuild)</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">WorkflowScheduleBuilder.vue</code> fetches the 16 families
            + descriptors from the store (never hard-coded) and renders one control per descriptor.
            <strong>Simple mode</strong> (default) offers five curated intents (Minutes / Hours /
            Daily / Weekly / Monthly) with a reduced control set; <strong>advanced mode</strong>
            exposes four sections — Repeat (grouped family <code class="font-next-mono">Select</code>),
            Days &amp; dates, Times (a 1-6 <code class="font-next-mono">HH:mm</code> editor), and
            Exclusions (months/weekdays/dates chips). Both modes write the SAME
            <code class="font-next-mono">ScheduleDraft</code> — simple is a curated VIEW over the
            full model, never a separate schema. A live preview card (natural-language sentence +
            debounced next-occurrences list from <code class="font-next-mono">POST schedule-preview</code>)
            is always visible in both modes. <code class="font-next-mono">WorkflowScheduleAssist.vue</code>
            is a third path (natural language) — a collapsed sparkles affordance that expands and
            moves focus into the prompt <code class="font-next-mono">Textarea</code>.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Variable add-ons — two, not three</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">ValueOrVariableField.vue</code> (generic literal-or-variable)
            and <code class="font-next-mono">DateOrVariableField.vue</code> (date-specific, needs
            its own date-picker literal control) — two concrete, purpose-built add-ons for the two
            structured-field SHAPES that exist today, rather than one over-parameterized component.
            Both echo the editor's <code class="font-next-mono">VariableChip</code> look without
            importing it directly (different underlying data models — directive/ProseMirror state
            vs. the <code class="font-next-mono">{kind}</code> union).
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Step editor: ordered list, no canvas</p>
          <p class="text-next-xs text-next-muted-foreground">
            The step model is strictly LINEAR (one ordered list, no branching, no parallel
            paths). The editor uses the SAME ▲▼ reorder pattern as the Approvals pipeline builder
            (<code class="font-next-mono">WorkflowStepListEditor.vue</code> /
            <code class="font-next-mono">WorkflowStepCard.vue</code>) rather than a visual
            node-and-edge canvas — unchanged from Etap-5 (ADR-0008 #14), just with 2 step types
            instead of 4.
          </p>
        </div>

        <p class="text-next-xs text-next-muted-foreground">
          See <code class="font-next-mono">docs/decisions/ADR-0009-workflows-rescope-typed-variables.md</code>
          for the full 5.1 reasoning, <code class="font-next-mono">docs/decisions/ADR-0010-workflows-schedule-rebuild.md</code>
          for the schedule rebuild's reasoning (16 families, times/exclusions, live preview,
          LW-defect), and <code class="font-next-mono">docs/next/workflows-uxui-spec.md</code>
          (REVISION 3, marked IMPLEMENTED) for the complete UX/UI specification these frontend
          decisions were drawn from.
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
          <li><strong>An operations pipeline for the typed variable system</strong> — no computed transformations (string concatenation, date formatting, arithmetic) on a resolved variable. Deliberately deferred (ADR-0009 §2).</li>
          <li><strong>TaskSelect extraction</strong> — largely MOOT after the re-scope (the standalone <code class="font-next-mono">task_id</code> fields it would have served, on the removed <code class="font-next-mono">assign_bot</code>/<code class="font-next-mono">attach_form</code>/<code class="font-next-mono">start_approval</code> steps, no longer exist). The manual-run FormSubmission target picker still has the same raw-TextInput gap.</li>
          <li><strong>Visual canvas builder</strong> — only warranted if the step model grows real branching/parallelism; the current linear model is well served by the ▲▼ list.</li>
          <li><strong>Per-tenant error isolation in the sweep commands</strong> — <code class="font-next-mono">workflows:run-scheduled</code> / <code class="font-next-mono">workflows:reap-stale-runs</code> have no per-tenant try/catch yet (consistent with the existing Bot reaper pattern; hardening queued separately).</li>
          <li><strong>Public holiday awareness</strong> — <code class="font-next-mono">last_working_day_of_month</code> (and every other family) has no holiday-calendar concept; a fire date landing on a holiday still fires normally. Would need a real holiday-calendar data source.</li>
          <li><strong>An every-N-days family and continuous time-window cadences</strong> — no "every N days" family (only N-minute/N-hour/N-month grids) and no continuous "between HH:mm and HH:mm" window (only discrete <code class="font-next-mono">times[]</code> fire points). Named explicitly in the AI-assist's honest-unsupported list rather than silently approximated.</li>
        </ul>
      </div>
    </StorySection>

  </StoryPage>
</template>
