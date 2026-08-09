# Backend API: Workspace AI usage & cost limits

Module: `App\Modules\Workspaces` (the two endpoints below) + `App\Modules\Variables` (the meter/ledger this
page reads — `AiUsageService`, `LedgerMeteredAiCall`, `AiUsageEvent`) + `App\Support\Meter` (the
cross-module actor resolver). R2 sub-stage 4 — see **ADR-0037** for the full design record (the token→$
gate cutover, the per-workspace cap column, the polymorphic actor attribution, the pre-run 429 gate). This
page is the practical, endpoint-by-endpoint contract; for the SESSION-side consumer contract (the pre-run
429 on the four run endpoints, session tagging) see `docs/backend/generator-sessions-api.md` → "Cost meter
integration"; for the BOT-side consumer contract (the `ai_bot_task` channel, the gate-before-claim, the
per-run projection) see `docs/backend/bots-api.md` → "AI cost gate (`ai_bot_task`)".

> Every AI spend in the app — Workflows `@[ai-text]`, Disk AI edits, Generator sessions — routes through
> the SAME ledger (`Variables\Support\LedgerMeteredAiCall`) and is summarized by this page's endpoints. This
> is the single source of truth for "how much has this workspace spent on AI this month, and on what."

---

## Concepts

**Every $ figure on this page is an ESTIMATE, never a bill.** `LedgerMeteredAiCall::record()` computes
`estimated_cost` from a per-CHANNEL price map (`config('ai.meter.pricing')`, below) at the moment of the
spend; no provider invoice or real billing reconciliation feeds it. The wire always says so explicitly
(`estimated: true` on the summary; `estimated_cost` is the DB column name).

```
AiUsageEvent (one row per metered AI call, across every spender app-wide)
  channel                 'ai_text' | 'ai_image_edit' | 'ai_image_generate' | 'ai_bot_task'
                           | 'ai_embedding' | 'ai_knowledge' | 'ai_knowledge_resolve'
  prompt_tokens / completion_tokens / total_tokens   real provider tokens (0 for an opaque result)
  estimated_cost          DECIMAL(10,4) — the $ figure the R2 sub-stage 4 gate sums and refuses over
  session_id               the generation session that drove it, if any (no FK — outlives a purged session)
  actor_type / actor_id    POLYMORPHIC (user | workflow_run | bot | null) — who the spend attributes to
  meta                      small non-secret context (e.g. the model) — NEVER the prompt
```

### The pricing model (`config('ai.meter.pricing')`)

| Channel | Basis | Config key | Default | Env override |
|---|---|---|---|---|
| `ai_text` | `total_tokens / 1000 * per_1k_tokens` — REAL provider tokens | `ai.meter.pricing.ai_text.per_1k_tokens` | `0.005` | `AI_PRICE_TEXT_PER_1K` |
| `ai_image_edit` | flat `per_call` — an image edit has no real token count | `ai.meter.pricing.ai_image_edit.per_call` | `0.17` | `AI_PRICE_IMAGE_EDIT_PER_CALL` |
| `ai_image_generate` | flat `per_call` | `ai.meter.pricing.ai_image_generate.per_call` | `0.19` | `AI_PRICE_IMAGE_GENERATE_PER_CALL` |
| `ai_bot_task` | `total_tokens / 1000 * per_1k_tokens` — a bot's autonomous task-execution run. **One row per RUN, not per step** — laravel/ai's agent loop returns only once every step is done, but the response's `usage` SUMS every step, so the row still carries the run's real total. Gated BEFORE the claim by a pre-run $ projection (`BotRunEstimate`), re-checked by the ordinary gate-before-spend at record time. See `docs/backend/bots-api.md` → "AI cost gate (`ai_bot_task`)" for the full contract. | `ai.meter.pricing.ai_bot_task.per_1k_tokens` | `0.005` | `AI_PRICE_BOT_TASK_PER_1K` |
| `ai_embedding`, `ai_knowledge`, `ai_knowledge_resolve` | The Knowledge module's indexing/composer channels — see `docs/backend/knowledge-api.md`. Listed here for completeness; not otherwise covered by this page. | `ai.meter.pricing.{channel}.per_1k_tokens` | `0.00002` / `0.005` / `0.005` | `AI_PRICE_EMBEDDING_PER_1K` / `AI_PRICE_KNOWLEDGE_PER_1K` / `AI_PRICE_KNOWLEDGE_RESOLVE_PER_1K` |

A missing/zero price for a channel yields `estimated_cost: 0.0` for that spend — the gate stays OPEN for
that channel rather than blocking on a misconfiguration (`LedgerMeteredAiCall::estimateCost()`). The
operator maintains these prices; every one is env-overridable so a deployment can match its real provider
contract without a code change. See `config/ai.php` → the `meter` block for the full commented reference,
including the LEGACY `unit_cost`/`monthly_token_cap` keys (retained for telemetry only — see "Superseded"
below).

### Superseded: the gate is $, not tokens

Before R2 sub-stage 4 (ADR-0033), the gate compared `SUM(total_tokens)` against
`ai.meter.monthly_token_cap`. **That is no longer how the gate works.** `AiUsageService::blocked()` /
`LedgerMeteredAiCall::assertWithinBudget()` now compare `SUM(estimated_cost)` against the effective $ cap
(below). `total_tokens` / `monthly_token_cap` are **retained but demoted to telemetry** — `tokens_used`
still displays (secondary, muted) on the usage summary, but nothing gates on it. See ADR-0037 for the full
record; do not re-wire a UI or a gate check back onto the token cap.

### The effective cap — `AiUsageService::cap()` (Option B: per-workspace override on the CENTRAL table)

```php
public function cap(): float
{
    $override = $this->tenant->workspace()?->ai_monthly_cost_cap;   // the CENTRAL Workspace row
    if ($override !== null) return (float) $override;                // null-check — 0.00 is a real answer
    return (float) config('ai.meter.monthly_cost_cap_default', 0);   // env default (AI_MONTHLY_COST_CAP), itself 0 = off
}
```

`workspaces.ai_monthly_cost_cap` is a nullable `DECIMAL(10,2)` on the **central** `workspaces` table (not a
tenant-DB column) — `TenantContext::workspace()` always holds the central row, even while a query runs on an
own-database workspace's tenant connection, so `cap()` is a PURE read (no query) that is correct in both
tenancy modes.

| Value | Meaning |
|---|---|
| `null` | **Inherit** the env default (`AI_MONTHLY_COST_CAP`, itself `0.0` — gate OFF by default, byte-preserving every pre-existing spender until an operator opts in). |
| `0.00` | **Explicit unlimited** for this workspace — distinct from `null`; an owner can force "no limit" even if the platform default is later raised. |
| `> 0` | This workspace's own monthly $ cap. |

`AiUsageService::blocked()`: `cap() > 0 && currentMonthCost() >= cap()` — the SAME predicate both
`LedgerMeteredAiCall`'s gate-before-spend and this page's `blocked` summary field read, and the SAME
predicate `GenerationSessionRunManager`'s pre-run 429 gate reads (see
`docs/backend/generator-sessions-api.md`) — one answer, three consumers, never disagreeing.

### Polymorphic actor attribution (`actor_type` / `actor_id`)

Every recorded spend is tagged with WHO drove it — the same `user | workflow_run | bot` union
`HasCreator`/`CreatorResource` already use app-wide (see `docs/backend/creator-attribution.md`), resolved by
a new `App\Support\Meter\MeterActorResolver`:

| Order | Source | Result |
|---|---|---|
| 1 | An explicit tag on `Variables\Support\MeterContext::setActor()` | Kept as-is — the queued session run (owner, or the bot when delegated), the autonomous bot slot-fill, **the bot's whole task-execution run** (`ai_bot_task` — tagged for the run's full duration, including the bound-knowledge embedding read that happens before the agent says a word), and the Disk AI-edit worker all tag explicitly because none has its own `auth()`/run context on a queue worker. |
| 2 | An active `WorkflowRunContext` | The run's own morph identity — a workflow's `@[ai-text]` call attributes to the run. |
| 3 | `auth()->id()` | The request-bound user. |
| 4 | None of the above | `[null, null]` — unattributed. |

**`MeterActorResolver` deliberately lives in `App\Support\Meter`, not under `app/modules/Variables`.** This
mirrors `App\Traits\HasCreator`'s own boundary escape hatch exactly: it references the Workflows run-context
binding as a compile-time `::class` string (no `use` import, lazy + `app()->bound()`-guarded), so it carries
no runtime dependency on Workflows — `LedgerMeteredAiCall` (a `Variables` class) delegates to it instead of
naming `WorkflowRunContext` itself, which would trip the one-way `Variables → Workflows` module-boundary
scan. **This is intentional, not an oversight** — see ADR-0037 D3a.

Names are resolved **generically, at READ time**, through `Relation::getMorphedModel()` — never stored, so
`AiUsageService` never imports a concrete `User`/`Bot`/`WorkflowRun` class, and a departed member or a
purged system record still resolves gracefully (`null` display name → the FE falls back to a per-type
label).

---

## Endpoints

Both endpoints require `auth:sanctum` + `X-Workspace-Id`. Route: `workspaces/{workspace}/ai-usage(/cap)`
(declared as sub-resource routes off `apiResource('workspaces', ...)`, so the `PATCH /workspaces/{id}` rename
endpoint stays untouched).

### GET /api/workspaces/{workspace}/ai-usage

The $-first usage summary for the CURRENT calendar month. Authorization: `view` (any workspace member) —
reading usage is not owner-gated, only CHANGING the cap is.

**Response** `200 OK` — `AiUsageSummaryResource`:

```json
GET /api/workspaces/{id}/ai-usage

200:
{ "data": {
  "currency": "USD",
  "estimated": true,
  "cost_used": 4.2231,
  "cost_cap": 25.00,
  "cost_remaining": 20.7769,
  "cap_source": "workspace",
  "warn_ratio": 0.8,
  "warn_reached": false,
  "blocked": false,
  "period": { "month": "2026-07", "resets_at": "2026-08-01T00:00:00.000000Z" },
  "tokens_used": 184320,
  "per_channel": [
    { "channel": "ai_text", "cost": 2.10, "tokens": 184320 },
    { "channel": "ai_image_generate", "cost": 1.71, "tokens": 36000 },
    { "channel": "ai_bot_task", "cost": 0.63, "tokens": 51200 },
    { "channel": "ai_image_edit", "cost": 0.41, "tokens": 8000 }
  ],
  "per_actor": [
    { "actor_type": "user", "actor_id": "9c1e...", "display_name": "Ola Kowalska", "icon": null, "cost": 3.10, "tokens": 140000 },
    { "actor_type": "bot", "actor_id": "1111...", "display_name": "Nightly Helper", "icon": "sparkles", "cost": 1.02, "tokens": 40000 },
    { "actor_type": "others", "actor_id": null, "display_name": null, "icon": null, "cost": 0.1031, "tokens": 4320 }
  ],
  "can_manage": true
} }
```

| Field | Type | Notes |
|---|---|---|
| `currency` | `'USD'` | Fixed today — no multi-currency. |
| `estimated` | `true` | Always — never a real bill. |
| `cost_used` | `number` | This calendar month's `SUM(estimated_cost)`, rounded to 4 decimals. |
| `cost_cap` | `number` | The EFFECTIVE cap (`AiUsageService::cap()`) — `0` means uncapped (see `cap_source`). |
| `cost_remaining` | `number \| null` | `null` when uncapped; otherwise `max(0, cap - used)`, never negative. |
| `cap_source` | `'workspace' \| 'default' \| 'unlimited'` | Where the effective cap comes from — `'workspace'` = this workspace's own override; `'default'` = the env default; `'unlimited'` = either an explicit `0.00` override OR a `0` env default. |
| `warn_ratio` | `number` | Fraction of the cap at which a UI should warn (`ai.meter.warn_ratio`, default `0.8`). |
| `warn_reached` | `boolean` | `capped && used >= cap * warn_ratio`. |
| `blocked` | `boolean` | The SAME predicate the gate itself uses — `cap() > 0 && used >= cap()`. |
| `period.month` | `'YYYY-MM'` | The current calendar month. |
| `period.resets_at` | ISO 8601 | The start of next month. |
| `tokens_used` | `number` | SECONDARY telemetry — this month's `SUM(total_tokens)`. No longer gates anything. |
| `per_channel` | `{channel, cost, tokens}[]` | Ordered by `cost` DESC. |
| `per_actor` | `{actor_type, actor_id, display_name, icon, cost, tokens}[]` | Top-5 by `cost` DESC, PLUS a synthetic `{actor_type:'others', actor_id:null, ...}` bucket aggregating the remainder when there are more than 5. `display_name`/`icon` are resolved at read time and may be `null` (a `workflow_run` actor has neither by default, a departed member, or the `others` bucket) — the FE falls back to a localized per-type label. |
| `can_manage` | `boolean` | OWNER-only capability flag (`$request->user()->can('manageAiBudget', $workspace)`) gating the cap editor. The REAL authorization is server-side on the PATCH below — this flag only drives the UI. |

**Errors**: `401` unauthenticated; `403` not a workspace member; `404` unknown workspace; `409` the route
`{workspace}` does not match the active `X-Workspace-Id` tenant (see "409 route/header mismatch" below).

---

### PATCH /api/workspaces/{workspace}/ai-usage/cap

Set (or clear) the workspace's monthly $ cap override. Authorization: `manageAiBudget` — **owner only**
(`WorkspacePolicy::manageAiBudget`, mirrors `manageMembers`/`manageGroups`).

| Field | Required | Notes |
|---|---|---|
| `monthly_cost_cap` | yes (`present`) | `number >= 0` **OR** `null`. `present`, not `required`, so a literal `null` is accepted to CLEAR the override — semantics mirror the column exactly: `null` = inherit the env default; `0` = explicit unlimited for this workspace; a positive number = this workspace's cap. Max `99999999.99` (the column's `DECIMAL(10,2)` ceiling). |

```json
PATCH /api/workspaces/{id}/ai-usage/cap
{ "monthly_cost_cap": 25 }

200:
{ "data": { "cost_cap": 25.00, "cap_source": "workspace", "blocked": false, "...": "..." } }
```

```json
PATCH /api/workspaces/{id}/ai-usage/cap
{ "monthly_cost_cap": null }        // clear the override → inherit the env default

PATCH /api/workspaces/{id}/ai-usage/cap
{ "monthly_cost_cap": 0 }           // explicit unlimited for THIS workspace
```

**Response** `200 OK` — the **REFRESHED** `AiUsageSummaryResource` (the controller re-sets the workspace on
`TenantContext` after the write so `cap()`'s central-row read reflects the new value immediately, then
rebuilds the summary) — the caller sees the new effective cap/source/blocked state in the SAME response,
with no follow-up GET needed.

**Errors**: `401` unauthenticated; `403` not the workspace owner; `404` unknown workspace; `409`
route/header mismatch (below); `422` `monthly_cost_cap` missing / negative / non-numeric / over the column
ceiling (`workspaces.ai_budget.cap_invalid`).

---

### 409 — route/header workspace mismatch

Both endpoints read/write the meter against the **currently ACTIVE workspace** (`TenantContext`, set by the
`ResolveWorkspace` middleware from `X-Workspace-Id` — the meter itself is workspace-scoped, not
route-scoped). `WorkspaceAiUsageController::assertRouteIsActiveWorkspace()` asserts the route's
`{workspace}` id equals the active tenant id; a mismatch (both the caller's OWN workspaces —
`ResolveWorkspace` already enforces membership on the header) is a clean `409`
(`workspaces.ai_budget.workspace_mismatch`) rather than silently reading/writing the WRONG workspace's
budget. The FE always sends the header matching the route, so the happy path never hits this — it exists to
fail loudly on a client bug, not to be routinely handled.

---

## Related files

- `app/modules/Variables/Services/AiUsageService.php` — `cap()`/`blocked()`/`remaining()`/`summary()`/the
  per-channel and per-actor aggregates
- `app/modules/Variables/Support/LedgerMeteredAiCall.php` — the gate-before-spend + record seam;
  `estimateCost()` (the pricing lookup), `deriveTokens()` (the telemetry token count)
- `app/modules/Variables/Support/MeterContext.php` — the ambient session + explicit-actor tag holder
- `app/modules/Variables/Models/AiUsageEvent.php`, `Exceptions/AiBudgetExceededException.php`
- `app/Support/Meter/MeterActorResolver.php` — the cross-module actor resolution (mirrors `HasCreator`)
- `app/modules/Bot/Services/BotTaskRunManager.php`, `Support/BotRunEstimate.php` — the `ai_bot_task`
  gate-before-claim and its pre-run $ projection; see `docs/backend/bots-api.md`
- `app/modules/Workspaces/Http/Controllers/WorkspaceAiUsageController.php`
- `app/modules/Workspaces/Http/Resources/AiUsageSummaryResource.php`
- `app/modules/Workspaces/Http/Requests/UpdateAiBudgetRequest.php`
- `app/modules/Workspaces/Policies/WorkspacePolicy.php` — `manageAiBudget`
- `app/modules/Workspaces/routes/api.php` — the two sub-resource routes
- `database/migrations/2026_07_28_000000_add_ai_monthly_cost_cap_to_workspaces.php` — the central column
- `database/migrations/2026_07_29_000000_create_ai_usage_events_table.php` (+ `database/migrations/tenant/`
  mirror) — the ledger table, incl. the `actor_type`/`actor_id` columns + their `GROUP BY` index
- `config/ai.php` → the `meter` block — `monthly_cost_cap_default`, `pricing`, `warn_ratio`, the legacy
  `monthly_token_cap`/`unit_cost` (telemetry-only)
- `app/modules/Generator/Services/GenerationSessionRunManager.php`,
  `Exceptions/GenerationBudgetExceeded.php` — the pre-run 429 gate (see
  `docs/backend/generator-sessions-api.md` → "Cost meter integration")
- `resources/js/next/app/stores/aiUsage.ts` — the FE store (GET/PATCH, typed wire contract)
- `resources/js/next/pages/workspaces/aiUsageMeta.ts` — presentation helpers (money/token formatting, meter
  state derivation, actor label/glyph fallbacks)
- `resources/js/next/pages/workspaces/AiUsagePage.vue`, `AiUsageCapEditor.vue` — the usage page + the
  owner-only cap editor (route `settings/ai-usage`, reached from the user menu)
- `resources/js/next/pages/generator/session/SessionBudgetChip.vue`, `SessionBudgetBanner.vue` — the
  inline budget signals inside a generation session's chat surface
- `docs/decisions/ADR-0037-ai-cost-limits.md` — the full design record for this page
- `docs/decisions/ADR-0033-ai-cost-meter.md` — the original D7 ledger seam (partially superseded — see its
  header note)
- `docs/backend/creator-attribution.md` — the polymorphic `user|workflow_run|bot` union this page's actor
  attribution mirrors
- `tests/Feature/AiCostMeterTest.php` — the $ gate, actor attribution, fail-open-on-ledger-error pins
- `tests/Feature/SessionAiBudgetGateTest.php` — the pre-run 429 across the four session run entry points

## Planned / deferred (not implemented)

- **Per-session spend cap / kill-switch** — today only the workspace-wide monthly $ cap is a real budget;
  a session's own per-run call-count ceilings (`generator.ai_text_max_calls_per_session`, etc. — see
  `docs/backend/generator-sessions-api.md`) bound fan-out, not cost.
- **Usage history / trend** — the summary is always the CURRENT calendar month only; no past-month
  breakdown or spend-over-time chart.
- **A pre-run cost ESTIMATE for a not-yet-started generation** — the pre-run gate (ADR-0037 D4) only
  refuses when the workspace is ALREADY over cap; it does not forecast what a specific about-to-run
  recipe would cost before running it.
- **Extending the pre-run 429 to Workflows/Disk spenders** — only Generator session runs get the pre-run
  429 gate today; Workflows `@[ai-text]` and Disk AI edits still only have the pre-existing mid-run
  fail-soft. Nothing architecturally blocks reusing `AiUsageService::blocked()` there later.
