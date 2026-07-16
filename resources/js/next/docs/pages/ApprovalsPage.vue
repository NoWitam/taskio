<script setup lang="ts">
// Gallery: Approvals module — module overview, patterns used, API surfaces, and
// conventions. This page documents the IMPLEMENTED behavior of the Approvals
// module (resources/js/next/pages/approvals/) — not planned behavior.
//
// Sections:
//   1. Module overview & information architecture
//   2. Pipelines — browse (cursor list + FilterBar + Saved Views)
//   3. Pipelines — builder drawer (query-driven, ordered stage list)
//   4. Queue — cursor queue of MY pending decisions
//   5. Review drawer — stage tabs, decision history, form preview, decide
//   6. Comments — generic Comments API reused in the review drawer
//   7. Nav badge — pending count
//   8. Patterns & conventions reused
//   9. Store API reference (approvalPipelines + approvalQueue)
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';
import Badge from '../../ui/primitives/Badge.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';

// --- Status map sample (for the live StatusBadge demo) --------------------
const sampleStatusMap = {
  pending:  { label: 'Pending',  variant: 'warning', tone: 'subtle', icon: 'clock'        },
  approved: { label: 'Approved', variant: 'success', tone: 'subtle', icon: 'check-circle' },
  rejected: { label: 'Rejected', variant: 'danger',  tone: 'subtle', icon: 'x-circle'     },
} as const;

// --- API reference rows ---------------------------------------------------

const pipelinesStoreRows: ApiRow[] = [
  { name: 'items',            type: 'ApprovalPipelineListItem[]', default: '[]',    description: 'Loaded list rows (cursor-paginated).' },
  { name: 'loading',          type: 'boolean',                    default: 'false',  description: 'True while the first (reset) page is in flight.' },
  { name: 'loadingMore',      type: 'boolean',                    default: 'false',  description: 'True while an append page is in flight.' },
  { name: 'errored',          type: 'boolean',                    default: 'false',  description: 'True when the FIRST page failed to load.' },
  { name: 'loadMoreErrored',  type: 'boolean',                    default: 'false',  description: 'True when an APPEND page failed; sentinel is paused until retried.' },
  { name: 'hasMore',          type: 'boolean',                    default: 'true',   description: 'False when next_cursor is null (list exhausted).' },
  { name: 'cursor',           type: 'string | null',              default: 'null',   description: 'Current cursor for the next append page.' },
  { name: 'detail',           type: 'ApprovalPipeline | null',    default: 'null',   description: 'Prefetched full pipeline (builder seed).' },
];

const pipelinesStoreActions: ApiRow[] = [
  { name: 'fetchPipelines(filters, { reset })', type: 'Promise<void>',              description: 'Fetch or append a page. reset=true (default) clears list + cursor.' },
  { name: 'loadMore(filters)',                  type: 'Promise<void>',              description: 'Append the next cursor page (infinite scroll).' },
  { name: 'retryLoadMore(filters)',             type: 'Promise<void>',              description: 'Clear loadMoreErrored then append — sentinel was paused.' },
  { name: 'fetchPipeline(id)',                  type: 'Promise<ApprovalPipeline | null>', description: 'Fetch one full pipeline; populates detail (builder prefetch).' },
  { name: 'createPipeline(payload)',            type: 'Promise<ApprovalPipeline>',  description: 'POST /approval-pipelines; prepends to the list.' },
  { name: 'updatePipeline(id, payload)',        type: 'Promise<ApprovalPipeline>',  description: 'PUT /approval-pipelines/{id}; replaces row in list.' },
  { name: 'deletePipeline(id)',                 type: 'Promise<void>',              description: 'DELETE /approval-pipelines/{id}; removes from list. 422 active-processes is detected by activeProcessesError().' },
];

const queueStoreRows: ApiRow[] = [
  { name: 'items',           type: 'ApprovalQueueItem[]',   default: '[]',    description: 'Loaded queue rows (cursor-paginated).' },
  { name: 'total',           type: 'number | null',         default: 'null',  description: 'Count of MY pending items — captured from the FIRST page meta only; never overwritten.' },
  { name: 'count',           type: 'number | null',         default: 'null',  description: 'Nav-badge count from GET /approvals/queue/count.' },
  { name: 'loading',         type: 'boolean',               default: 'false', description: 'First (reset) page in flight.' },
  { name: 'loadingMore',     type: 'boolean',               default: 'false', description: 'Append page in flight.' },
  { name: 'errored',         type: 'boolean',               default: 'false', description: 'First page failed.' },
  { name: 'loadMoreErrored', type: 'boolean',               default: 'false', description: 'Append page failed; sentinel paused until retry.' },
  { name: 'processCache',    type: 'Record<string, ApprovalProcess>', default: '{}', description: 'Keyed process detail cache (populated by fetchProcess).' },
  { name: 'runHistoryCache', type: 'Record<string, ApprovalProcess[]>', default: '{}', description: 'Keyed run-history cache (populated by fetchRunHistory).' },
];

const queueStoreActions: ApiRow[] = [
  { name: 'fetchQueue({ reset })',           type: 'Promise<void>',                  description: 'Fetch or append a page. reset=true recaptures total from meta.' },
  { name: 'loadMore()',                      type: 'Promise<void>',                  description: 'Append the next cursor page.' },
  { name: 'retryLoadMore()',                 type: 'Promise<void>',                  description: 'Clear loadMoreErrored then append.' },
  { name: 'fetchCount()',                    type: 'Promise<number>',                description: 'GET /approvals/queue/count → updates count.' },
  { name: 'fetchProcess(id)',                type: 'Promise<ApprovalProcess | null>', description: 'GET /approvals/processes/{id}; stores in processCache.' },
  { name: 'fetchRunHistory(runId)',          type: 'Promise<ApprovalProcess[]>',     description: 'GET /approvals/runs/{runId}; stores in runHistoryCache.' },
  { name: 'makeDecision(processId, payload)', type: 'Promise<ApprovalProcess>',     description: 'POST /approvals/processes/{id}/decide. Optimistically removes from queue + decrements total+count. 422 → structured already_decided error + resync.' },
  { name: 'removeFromQueue(processId)',       type: 'void',                          description: 'Remove a queue item (exposed for tests).' },
];

const backendEndpointsRows: ApiRow[] = [
  { name: 'GET /approval-pipelines',                    type: '?search=&cursor=',                 description: 'Cursor list (cursorPaginate(8)). No total. Response: { data: ApprovalPipelineListItem[], meta: { next_cursor } }.' },
  { name: 'GET /approval-pipelines/{id}',               type: '',                                  description: 'Full pipeline with stages. Response: { data: ApprovalPipeline }.' },
  { name: 'POST /approval-pipelines',                   type: 'PipelineWritePayload',             description: 'Create. Response: { data: ApprovalPipeline }.' },
  { name: 'PUT /approval-pipelines/{id}',               type: 'PipelineWritePayload',             description: 'Update. 422 with errors.pipeline field when active processes exist.' },
  { name: 'DELETE /approval-pipelines/{id}',            type: '',                                  description: 'Delete. 422 with errors.pipeline field when active processes exist.' },
  { name: 'GET /approvals/queue',                       type: '?cursor=',                         description: 'My pending queue (cursorPaginate(8)). meta.total present on first page only.' },
  { name: 'GET /approvals/queue/count',                 type: '',                                  description: 'Nav-badge count. Response: { count }.' },
  { name: 'GET /approvals/processes/{process}',         type: '',                                  description: 'Process detail + pipeline (with stages) + stage. Response: { data: ApprovalProcess }.' },
  { name: 'GET /approvals/runs/{runId}',                type: '',                                  description: 'Full run history as a flat process list. Response: { data: ApprovalProcess[] }.' },
  { name: 'POST /approvals/processes/{process}/decide', type: '{ decision, note? }',              description: 'Decide: approved|rejected. note required_if decision=rejected. 422 when already decided.' },
  { name: 'GET {module}/{id}/comments',                 type: '?cursor=',                         description: 'Generic comment thread. Response: { data: Comment[], meta: { next_cursor } }.' },
  { name: 'POST {module}/{id}/comments',                type: '{ content }',                      description: 'Add a comment.' },
  { name: 'PATCH /comments/{id}',                      type: '{ content }',                      description: 'Edit own comment (CommentPolicy).' },
  { name: 'DELETE /comments/{id}',                     type: '',                                  description: 'Delete own comment (CommentPolicy).' },
];

const pipelineWritePayloadRows: ApiRow[] = [
  { name: 'name',        type: 'string',                  description: 'Required, max 255.' },
  { name: 'icon',        type: 'string | null',            description: 'Legacy IconEnum value or null, max 50.' },
  { name: 'description', type: 'string | null',            description: 'Optional, max 2500.' },
  { name: 'stages',      type: 'PipelineStagePayload[]',  description: 'Ordered array (min 1). Array index is the order — do NOT send an order field.' },
];

const stagePayloadRows: ApiRow[] = [
  { name: 'name',          type: 'string',            description: 'Required, max 255.' },
  { name: 'icon',          type: 'string | null',      description: 'Optional, max 50.' },
  { name: 'description',   type: 'string | null',      description: 'Optional, max 2500.' },
  { name: 'approver_type', type: "'user' | 'ai' | 'bot'", description: 'Required. Bot stages evaluate via AI with persona coloring (see Modules → Bots).' },
  { name: 'approver_id',   type: 'string | null',         description: 'Required when approver_type=user or bot (UUID). Send null for ai stages.' },
];
</script>

<template>
  <StoryPage
    title="Approvals module"
    description="Module overview, information architecture, patterns, and API reference for resources/js/next/pages/approvals/. Documents implemented behavior only."
  >

    <!-- 1. Module overview & IA -->
    <StorySection title="Module overview">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          The Approvals module lets users review and decide on pending approval
          requests (Queue) and configure the pipeline definitions that route those
          requests (Pipelines). It lives at <code class="font-next-mono">/next/approvals</code>
          (route base <code class="font-next-mono">next.approvals</code>) and defaults to Queue.
        </p>

        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Route structure</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>/next/approvals → redirect → queue</li>
              <li>/next/approvals/queue (default)</li>
              <li>/next/approvals/pipelines</li>
              <li>?pipeline=new — builder drawer (create)</li>
              <li>?pipeline=&lt;id&gt; — builder drawer (edit)</li>
              <li>?review=&lt;processId&gt; — review drawer</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Key files</p>
            <ul class="flex flex-col gap-next-1 font-next-mono text-next-xs text-next-muted-foreground">
              <li>pages/approvals/ApprovalsModuleLayout.vue</li>
              <li>pages/approvals/QueueView.vue</li>
              <li>pages/approvals/PipelinesView.vue</li>
              <li>pages/approvals/PipelineBuilderDrawer.vue</li>
              <li>pages/approvals/ApprovalReviewDrawer.vue</li>
              <li>pages/approvals/ApprovalComments.vue</li>
              <li>pages/approvals/PipelineCard.vue</li>
              <li>pages/approvals/QueueItemCard.vue</li>
              <li>pages/approvals/types.ts</li>
              <li>pages/approvals/queue-types.ts</li>
              <li>pages/approvals/approvalStatus.ts</li>
              <li>app/stores/approvalPipelines.ts</li>
              <li>app/stores/approvalQueue.ts</li>
            </ul>
          </div>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Sub-nav</p>
          <p class="text-next-muted-foreground">
            <code class="font-next-mono">ApprovalsModuleLayout</code> renders a left sidebar
            sub-nav (hidden below <code class="font-next-mono">next-lg</code> breakpoint, content
            stays accessible). Queue leads because it is the primary daily surface.
            Module icon: <code class="font-next-mono">check-circle</code> (white-on-primary
            in the sidebar header, per the module-page icon rule). Pipelines icon:
            <code class="font-next-mono">git-branch</code>. Queue icon:
            <code class="font-next-mono">inbox</code>.
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-2 font-next-semibold text-next-fg">Drawer hosting</p>
          <p class="text-next-muted-foreground">
            Both drawers are hosted in <code class="font-next-mono">ApprovalsModuleLayout</code>
            (not inside the child views). This keeps the background list mounted while a
            drawer is open. The layout uses a <code class="font-next-mono">dropQuery(keys)</code>
            helper that removes only the specified key, so opening/closing one drawer
            never drops the other's query param.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 2. Approval status badges -->
    <StorySection title="Approval status badges">
      <div class="flex flex-col gap-next-3">
        <p class="text-next-sm text-next-muted-foreground">
          Status is never color-only. Each descriptor pairs an icon + label.
          Built by <code class="font-next-mono">approvalStatusMap(t)</code> in
          <code class="font-next-mono">pages/approvals/approvalStatus.ts</code>.
        </p>
        <div class="flex flex-wrap gap-next-3">
          <StatusBadge status="pending"  :status-map="sampleStatusMap" />
          <StatusBadge status="approved" :status-map="sampleStatusMap" />
          <StatusBadge status="rejected" :status-map="sampleStatusMap" />
        </div>
        <div class="flex flex-wrap gap-next-3">
          <StatusBadge status="pending"  :status-map="sampleStatusMap" size="sm" />
          <StatusBadge status="approved" :status-map="sampleStatusMap" size="sm" />
          <StatusBadge status="rejected" :status-map="sampleStatusMap" size="sm" />
        </div>
      </div>
    </StorySection>

    <!-- 3. Pipelines — browse -->
    <StorySection title="Pipelines — browse list">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <strong>File:</strong> <code class="font-next-mono">PipelinesView.vue</code>.
          Cursor-paginated card grid (cursorPaginate(8), no total). The ONLY server
          filter is <code class="font-next-mono">search</code> (matches name).
        </p>
        <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-muted-foreground">
          <li>FilterBar with a single debounced search control (400 ms).</li>
          <li>Saved Views (FilterTabBar in the <code class="font-next-mono">#top</code> slot) — always present, context key <code class="font-next-mono">approval_pipelines</code>.</li>
          <li>Active-filter chips; chip key format <code class="font-next-mono">search=&lt;value&gt;</code>.</li>
          <li>Filter state lives in the view, synced to URL query (preserves <code class="font-next-mono">?pipeline</code> overlay key).</li>
          <li>Card-shaped EntityCard skeletons on initial load (never a spinner).</li>
          <li>Two empty states: first-run ("no pipelines") and filtered no-results.</li>
          <li>Inline load-more error (Alert + retry) below the grid; sentinel paused when <code class="font-next-mono">loadMoreErrored</code> is set.</li>
          <li>Card click prefetches the full pipeline via <code class="font-next-mono">store.fetchPipeline(id)</code>, then navigates to <code class="font-next-mono">?pipeline=&lt;id&gt;</code>.</li>
          <li>Delete goes through useConfirm + useToast; 422 active-processes error is translated via <code class="font-next-mono">activeProcessesError()</code>.</li>
        </ul>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">PipelineCard gating</p>
          <p class="text-next-muted-foreground">
            Edit is enabled only when <code class="font-next-mono">is_owner && can_be_edited</code>.
            Delete is enabled only when <code class="font-next-mono">is_owner && can_be_deleted</code>.
            Disabled items stay <em>visible</em> in the dropdown with a reason label
            (ownership first, then "active processes"). The UI never invents authorization —
            the server returns these flags as part of <code class="font-next-mono">ApprovalPipelineListItem</code>.
            <code class="font-next-mono">is_owner</code> was added to the backend resource
            as an additive change (see ADR-0006).
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 4. Pipelines — builder drawer -->
    <StorySection title="Pipelines — builder drawer">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <strong>File:</strong> <code class="font-next-mono">PipelineBuilderDrawer.vue</code>.
          Hosted as a query-driven <code class="font-next-mono">Drawer side="right" size="2xl"</code>
          in <code class="font-next-mono">ApprovalsModuleLayout</code>.
          <code class="font-next-mono">?pipeline=new</code> → create mode;
          <code class="font-next-mono">?pipeline=&lt;id&gt;</code> → edit mode.
          The component is keyed by the pipeline id in the layout, so it remounts
          (and re-seeds from <code class="font-next-mono">store.detail</code>) per pipeline.
        </p>
        <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-muted-foreground">
          <li>Drawer provides no chrome (no close button, no header) — the builder owns its own header with Cancel/Save.</li>
          <li>Metadata: name (required), icon (IconInput), description (Textarea).</li>
          <li>Ordered stage list (min 1, enforced client-side). Stage fields: name (required), icon, description, approver_type (SegmentedControl: user/ai), approver_id (UserSelect, required_if user).</li>
          <li>Reorder via ▲▼ buttons (not drag-and-drop). Conditional Remove (X) appears before the permanent ▲▼ controls per trailing-affordance-order rule.</li>
          <li>Client-side validation mirrors FormRequest; server 422 bag is mapped back onto field errors by index.</li>
          <li>Stage <code class="font-next-mono">order</code> is the array index — it is NOT sent in the payload; the server derives it.</li>
          <li>AI stages send <code class="font-next-mono">approver_id: null</code> regardless of prior selection.</li>
          <li>Deep link without a prefetched detail (store.detail is null) shows a clear error — no blank form.</li>
          <li>Local state is cloned via JSON (not structuredClone — Vue proxies throw DataCloneError).</li>
        </ul>
      </div>
    </StorySection>

    <!-- 5. Queue -->
    <StorySection title="Queue — my pending decisions">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <strong>File:</strong> <code class="font-next-mono">QueueView.vue</code>.
          Cursor-paginated card grid of items where I am the approver and status is
          pending. The server filters to <code class="font-next-mono">approver=ME, status=pending</code>
          — there are ZERO client-side filter params, no FilterBar, and no Saved Views.
        </p>
        <ul class="flex list-disc flex-col gap-next-1 pl-next-5 text-next-muted-foreground">
          <li><code class="font-next-mono">meta.total</code> is present ONLY on the first page (no cursor param). The store captures it on reset and never overwrites it on subsequent pages.</li>
          <li>Card click prefetches both the process detail and the run history in parallel, then navigates to <code class="font-next-mono">?review=&lt;processId&gt;</code>.</li>
          <li>Card-shaped skeletons on initial load; empty state "nothing to approve" with <code class="font-next-mono">check-circle</code> icon.</li>
          <li>Inline load-more error (Alert + retry); sentinel paused while <code class="font-next-mono">loadMoreErrored</code> is set.</li>
          <li>No URL filter sync (no filters to sync).</li>
        </ul>
      </div>
    </StorySection>

    <!-- 6. Review drawer -->
    <StorySection title="Review drawer">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <strong>File:</strong> <code class="font-next-mono">ApprovalReviewDrawer.vue</code>.
          Opened via <code class="font-next-mono">?review=&lt;processId&gt;</code>; the queue
          prefetches process detail + run history before navigating so the drawer renders
          populated (a fallback watch handles deep links / cache misses).
        </p>
        <div class="grid grid-cols-1 gap-next-3 next-sm:grid-cols-2">
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Layout zones</p>
            <ul class="flex list-disc flex-col gap-next-1 pl-next-4 text-next-xs text-next-muted-foreground">
              <li>Header: entity type label + name + StatusBadge + close button.</li>
              <li>Body (scrollable): description, extra_fields chips, form preview.</li>
              <li>Stage tabs: one tab per pipeline stage; future stages disabled.</li>
              <li>Decision footer (shown only when <code class="font-next-mono">canDecide</code>).</li>
              <li>Right rail: ApprovalComments (shown only when entity.comments_url is set, hidden below next-lg).</li>
            </ul>
          </div>
          <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
            <p class="mb-next-1 font-next-semibold text-next-fg">Stage tabs</p>
            <ul class="flex list-disc flex-col gap-next-1 pl-next-4 text-next-xs text-next-muted-foreground">
              <li>Tabs from pipeline.stages; future stages (order &gt; currentStage.order) are disabled.</li>
              <li>Each tab shows stage criteria (description) + decision history for that stage.</li>
              <li>Run history is grouped by stage via <code class="font-next-mono">groupRunHistory()</code> in the queue store.</li>
              <li><code class="font-next-mono">stage === null</code> on historical processes (pipeline was re-saved, nulling old FK) → bucketed into an "Unknown stage" trailing tab.</li>
              <li>Active tab defaults to currentStage.id, then follows it reactively.</li>
            </ul>
          </div>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">Form preview</p>
          <p class="text-next-xs text-next-muted-foreground">
            When <code class="font-next-mono">entity.form</code> is present, renders
            <code class="font-next-mono">pages/forms/FormViewer.vue</code> in
            <code class="font-next-mono">mode="preview"</code>, hydrated with
            <code class="font-next-mono">content=entity.form.content</code> and
            <code class="font-next-mono">:initial-data="entity.form.submission"</code>.
            FormViewer is imported directly from the forms page module — no extraction
            until a second consumer appears (see ADR-0006).
          </p>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">canDecide gating</p>
          <p class="text-next-xs text-next-muted-foreground">
            Decision actions are shown only when
            <code class="font-next-mono">process.status === 'pending' && process.approver_type === 'user'</code>.
            Reject reveals a required note Textarea (error blocks submit).
            On success: toast + emit close (queue already updated via optimistic removal).
            On 422 "already decided": structured error → toast warning + close + queue resync.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 7. Comments -->
    <StorySection title="Comments">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <p class="text-next-muted-foreground">
          <strong>File:</strong> <code class="font-next-mono">ApprovalComments.vue</code>.
          Reuses the generic Comments API module. Self-contained — no Pinia store,
          no coupling to the queue or pipelines stores.
        </p>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">API shape (generic Comments module)</p>
          <ul class="flex list-disc flex-col gap-next-1 pl-next-4 text-next-xs text-next-muted-foreground">
            <li><code class="font-next-mono">GET {module}/{id}/comments</code> — cursor-paginated, created_at DESC. <code class="font-next-mono">comments_url</code> is an absolute URL from the server (route() output); normalized to a path relative to <code class="font-next-mono">/api</code>.</li>
            <li><code class="font-next-mono">POST {module}/{id}/comments</code> body <code class="font-next-mono">{ content }</code> — prepends to list (DESC order).</li>
            <li><code class="font-next-mono">PATCH /comments/{id}</code> body <code class="font-next-mono">{ content }</code> — inline edit.</li>
            <li><code class="font-next-mono">DELETE /comments/{id}</code> — inline delete with ConfirmDialog.</li>
          </ul>
        </div>
        <div class="rounded-next-lg border border-next-border bg-next-card p-next-3">
          <p class="mb-next-1 font-next-semibold text-next-fg">CommentResource</p>
          <p class="text-next-xs text-next-muted-foreground">
            <code class="font-next-mono">{ id, content, author: { id, name, email, type, is_bot }, created_at, updated_at, is_edited }</code>.
            <code class="font-next-mono">author.type</code> (<code class="font-next-mono">'user' | 'bot'</code>) and
            <code class="font-next-mono">author.is_bot</code> were added additively in the Bot module (Batch 2).
            No <code class="font-next-mono">can_edit</code> / <code class="font-next-mono">can_delete</code> flags — ownership is resolved client-side
            (<code class="font-next-mono">String(auth.user.id) === String(comment.author.id)</code>); authorization is
            server-authoritative via CommentPolicy. Content may be plain text or a
            Tiptap JSON doc — <code class="font-next-mono">displayContent()</code> handles both.
          </p>
        </div>
      </div>
    </StorySection>

    <!-- 8. Nav badge -->
    <StorySection title="Nav badge">
      <div class="flex flex-col gap-next-3 text-next-sm">
        <p class="text-next-muted-foreground">
          The Approvals nav item shows a pending-count badge.
          <code class="font-next-mono">ApprovalsModuleLayout</code> calls
          <code class="font-next-mono">queueStore.fetchCount()</code> on mount (best-effort;
          a failure is silently ignored). The count is held in
          <code class="font-next-mono">approvalQueue.count</code> and decremented
          optimistically when a decision is made via <code class="font-next-mono">makeDecision()</code>.
        </p>
        <div class="flex items-center gap-next-3">
          <Badge variant="primary">3</Badge>
          <span class="text-next-xs text-next-muted-foreground">Sample pending-count badge rendered on the Approvals nav item</span>
        </div>
      </div>
    </StorySection>

    <!-- 9. Patterns & conventions reused -->
    <StorySection title="Patterns and conventions reused">
      <div class="flex flex-col gap-next-4 text-next-sm">
        <ul class="flex list-disc flex-col gap-next-2 pl-next-5 text-next-muted-foreground">
          <li>
            <strong>Cursor stores with request-token stale-page guard.</strong>
            Both stores use a monotonic <code class="font-next-mono">token</code> integer.
            A reset increments the token; inflight responses from a superseded request are
            discarded with an early return.
          </li>
          <li>
            <strong>Separate first-page vs append error handling.</strong>
            <code class="font-next-mono">errored</code> covers the first (reset) page —
            the list is empty, a full error state is shown and <code class="font-next-mono">hasMore</code>
            is set to false. <code class="font-next-mono">loadMoreErrored</code> covers append failures —
            the loaded grid stays visible, <code class="font-next-mono">hasMore</code> + <code class="font-next-mono">cursor</code>
            are preserved, the sentinel is paused, and an inline Alert with a retry button
            appears below the grid.
          </li>
          <li>
            <strong>Query-driven overlay drawers hosted in the module layout.</strong>
            Drawers open on <code class="font-next-mono">?pipeline</code> / <code class="font-next-mono">?review</code>
            query params. The <code class="font-next-mono">dropQuery(keys)</code> helper removes only the
            specified key; other query params (including the other drawer's key) survive.
          </li>
          <li>
            <strong>Prefetch-then-navigate for drawers.</strong>
            Card click prefetches the full resource into the store cache, then navigates
            to the query param. The drawer reads from the cache and renders already
            populated with no loading flash.
          </li>
          <li>
            <strong>FilterBar + Saved Views on list screens.</strong>
            Pipelines follows the same Saved Views setup as Tasks (FilterTabBar in
            <code class="font-next-mono">#top</code> slot, <code class="font-next-mono">useFilterTabs</code> composable).
            Queue has no filters and therefore no FilterBar or Saved Views.
          </li>
          <li>
            <strong><code class="font-next-mono">next-*</code> tokens only.</strong>
            All new components use namespaced Tailwind tokens
            (<code class="font-next-mono">bg-next-card</code>, <code class="font-next-mono">text-next-fg</code>, etc.).
            No legacy token usage or raw hex/hsl.
          </li>
          <li>
            <strong>i18n in en + pl.</strong>
            All user-facing strings — labels, aria-labels, toasts, empty states, error
            copy — are in <code class="font-next-mono">app/i18n/en.ts</code> and <code class="font-next-mono">app/i18n/pl.ts</code>
            under the <code class="font-next-mono">approvals.*</code> namespace.
          </li>
        </ul>
      </div>
    </StorySection>

    <!-- 10. Backend API reference -->
    <StorySection title="Backend API endpoints">
      <ApiTable title="Approvals API endpoints" :rows="backendEndpointsRows" type-header="Query / Body" />
    </StorySection>

    <!-- 11. Pipeline write payload -->
    <StorySection title="Pipeline write payload">
      <div class="flex flex-col gap-next-4">
        <ApiTable title="PipelineWritePayload (POST body / PUT body)" :rows="pipelineWritePayloadRows" />
        <ApiTable title="PipelineStagePayload (stages array element)" :rows="stagePayloadRows" />
        <p class="text-next-xs text-next-muted-foreground">
          The server rewrites stages wholesale on update. The array index is the stage
          order — do not send an <code class="font-next-mono">order</code> field.
        </p>
      </div>
    </StorySection>

    <!-- 12. approvalPipelines store API -->
    <StorySection title="approvalPipelines store">
      <div class="flex flex-col gap-next-4">
        <p class="text-next-xs text-next-muted-foreground">
          <code class="font-next-mono">useApprovalPipelinesStore()</code> — Pinia setup store,
          id <code class="font-next-mono">next-approval-pipelines</code>.
          File: <code class="font-next-mono">app/stores/approvalPipelines.ts</code>.
        </p>
        <ApiTable title="State" :rows="pipelinesStoreRows" :show-default="true" />
        <ApiTable title="Actions" :rows="pipelinesStoreActions" type-header="Signature" />
        <p class="text-next-xs text-next-muted-foreground">
          Also exports <code class="font-next-mono">activeProcessesError(err)</code>: detects the
          422 "pipeline has active processes" response (by the presence of the
          <code class="font-next-mono">errors.pipeline</code> field) and returns a structured
          <code class="font-next-mono">PipelineActiveProcessesError</code> the UI translates
          via its own i18n catalog.
        </p>
      </div>
    </StorySection>

    <!-- 13. approvalQueue store API -->
    <StorySection title="approvalQueue store">
      <div class="flex flex-col gap-next-4">
        <p class="text-next-xs text-next-muted-foreground">
          <code class="font-next-mono">useApprovalQueueStore()</code> — Pinia setup store,
          id <code class="font-next-mono">next-approval-queue</code>.
          File: <code class="font-next-mono">app/stores/approvalQueue.ts</code>.
        </p>
        <ApiTable title="State" :rows="queueStoreRows" :show-default="true" />
        <ApiTable title="Actions" :rows="queueStoreActions" type-header="Signature" />
        <p class="text-next-xs text-next-muted-foreground">
          Also exports <code class="font-next-mono">groupRunHistory(history)</code>: groups a flat
          <code class="font-next-mono">ApprovalProcess[]</code> by stage, sorted by stage order,
          with <code class="font-next-mono">stage === null</code> processes in a trailing
          "unknown stage" bucket.
        </p>
      </div>
    </StorySection>

  </StoryPage>
</template>
