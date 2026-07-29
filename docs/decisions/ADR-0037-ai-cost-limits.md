# ADR-0037 — R2 sub-stage 4: $-first AI cost limits (gate cutover, per-workspace cap, actor attribution, pre-run 429)

**Date:** 2026-07-28 (created)
**Status:** Accepted
**Module:** `App\Modules\Variables` (the gate/ledger + `AiUsageService`), `App\Modules\Workspaces` (the
`/ai-usage` endpoints + the `ai_monthly_cost_cap` column), `App\Modules\Generator` (the pre-run 429 gate on
every session run entry point), `App\Support\Meter` (the new cross-module actor resolver)
**Relates to:** ADR-0033 (the D7 ledger seam this ADR converts from a token gate to a $ gate — see
"Supersession" below), ADR-0034 (Generation Sessions — the run entry points the pre-run gate now guards),
ADR-0036 (Bot delegation — the autonomous slot-fill call this ADR's actor attribution now tags as the bot)

---

## Context

ADR-0033 shipped a real ledger meter (`LedgerMeteredAiCall`) with a gate-before-spend property, but the gate
was **token-based**: a workspace's calendar-month `SUM(total_tokens)` compared against
`ai.meter.monthly_token_cap`. That ADR explicitly flagged the trade-off as accepted for the time — "a future
per-workspace usage/limit UI (deferred)" — but a token count is not a number a workspace owner can reason
about ("is 40,000 tokens a lot?"), it is not comparable across channels (a text call and an image edit both
recorded a token STAND-IN, not a real cost), and it gave no visibility into WHO inside the workspace was
spending it (a human, a bot, or an automation). R2 sub-stage 4 — pulled forward in the roadmap immediately
after Sessions (ADR-0034) became the first real, automatic AI spender — closes those three gaps: the gate
basis becomes a dollar estimate, the cap becomes a per-workspace $ setting an owner can actually set, and
every spend is attributed to an actor.

Four questions needed answering:

1. **What does the gate actually compare** — tokens, dollars, or a call count — and where does a dollar
   figure come from when the codebase has never priced anything?
2. **Where does the $ cap live**, given the app runs BOTH shared-database and per-workspace own-database
   tenancy, and the answer must read correctly in both?
3. **Who spent it** — is per-actor attribution worth a new polymorphic column, or is a per-channel total
   enough?
4. **Should an over-cap workspace's generation RUN be refused outright**, or is the existing mid-run
   fail-soft (an AI block quietly resolving to `''`) sufficient?

## Decisions

**D1 — the gate basis becomes DOLLARS: `estimated_cost` (previously always `0.0`, advisory-only) becomes
LOAD-BEARING.** `LedgerMeteredAiCall::assertWithinBudget()` and `AiUsageService::blocked()` now compare
`SUM(estimated_cost)` for the active workspace's calendar month against `AiUsageService::cap()`, not
`SUM(total_tokens)` against a token cap. `estimated_cost` is computed by a new per-CHANNEL `pricing` config
map (`config('ai.meter.pricing.<channel>')`):

```php
// config/ai.php
'pricing' => [
    'ai_text'          => ['per_1k_tokens' => (float) env('AI_PRICE_TEXT_PER_1K', 0.005)],
    'ai_image_edit'     => ['per_call' => (float) env('AI_PRICE_IMAGE_EDIT_PER_CALL', 0.17)],
    'ai_image_generate' => ['per_call' => (float) env('AI_PRICE_IMAGE_GENERATE_PER_CALL', 0.19)],
],
```

`ai_text` prices on REAL provider tokens (`total_tokens / 1000 * per_1k_tokens`); the two image channels
price FLAT per call (images are opaque results with no real token count — the pre-existing `unit_cost`
token stand-in, e.g. 4000, is not and was never a price, only a fallback total for the token gate). Every
figure is explicitly an **ESTIMATE** — `AiUsageSummaryResource` carries `estimated: true` on every response,
and every FE surface repeats the caveat in copy. The operator maintains the prices; every one is
env-overridable so a deployment can tune them to its real provider contract without a code change.

**Nothing about D1 is a real billing system.** A missing/zero price for a channel yields `estimated_cost:
0.0` for that spend — the gate stays open for that channel rather than silently blocking on a
misconfiguration. `total_tokens` (and the legacy `monthly_token_cap` config key) are **retained but
demoted to telemetry** — the token figure still displays (secondary, muted) on the usage UI, but the gate
never reads `monthly_token_cap` again. This is the load-bearing compatibility note for the next reader: do
not "fix" a UI back onto the token cap — it is dead as a gate basis by design.

**D2 — the cap lives on the CENTRAL `workspaces` table (Option B), not per-tenant.** A new nullable
`ai_monthly_cost_cap DECIMAL(10,2)` column, read by `AiUsageService::cap()`:

```php
public function cap(): float
{
    $override = $this->tenant->workspace()?->ai_monthly_cost_cap;   // the CENTRAL row TenantContext holds
    if ($override !== null) return (float) $override;               // null-check, not truthiness — 0.00 is a real answer
    return (float) config('ai.meter.monthly_cost_cap_default', 0);  // env default, itself 0 = off
}
```

Semantics: `null` **inherits** the env default (`AI_MONTHLY_COST_CAP`, itself `0.0` — the gate is OFF by
default, byte-preserving every pre-existing spender's behavior until an operator opts in); a **positive**
value is this workspace's own cap; `0.00` is **explicit unlimited** for this workspace specifically
(distinct from "unset" — an owner can force a workspace uncapped even if the platform default is later
raised). The column lives on `workspaces`, not a tenant table, because `TenantContext::workspace()` always
holds the central `Workspace` row — even while a query is running against an own-database tenant's
connection — so `cap()` is a PURE read (no query) that is correct in both database modes with zero
tenant-mode branching. **Rejected: a per-tenant-DB setting.** Would need its own migration path per tenant
schema and a cross-connection read to surface it centrally (e.g. for a future admin cost dashboard) — the
central column is strictly simpler and the two db modes already share a central `Workspace` row for
identity, so this is precedent, not a new pattern.

**D3 — every spend is attributed to a polymorphic ACTOR (`actor_type`/`actor_id` on `AiUsageEvent`),
resolved through a new `App\Support\Meter\MeterActorResolver` that MIRRORS `HasCreator`'s precedence.**
Explicit tag (via `Variables\Support\MeterContext::setActor()`) → an active `WorkflowRunContext` → the
authenticated user → unattributed:

```php
// app/Support/Meter/MeterActorResolver.php
public function resolve(?string $explicitType, ?string $explicitId): array
{
    if ($explicitId !== null) return [$explicitType ?? $this->userAlias(), $explicitId];   // 1. explicit wins
    $run = app()->bound(WORKFLOW_RUN_CONTEXT) ? app(WORKFLOW_RUN_CONTEXT)->current() : null;
    if ($run !== null) return [$run->getMorphClass(), $run->getKey()];                     // 2. an active run
    if (auth()->id() !== null) return [$this->userAlias(), (string) auth()->id()];         // 3. the request's user
    return [null, null];                                                                    // 4. unattributed
}
```

Three explicit-tag call sites cover the cases the precedence chain alone cannot reach (a queued run or an
autonomous fill has neither an HTTP-bound `auth()` user nor its own `WorkflowRunContext`): the Disk AI-edit
worker tags the dispatching user (`ImageAiService::process()`), the Generator session executor tags
`session->meterActor()` (the human owner, or the bot when the session is delegated — reusing the SAME
delegation overlay ADR-0036 introduced, not a new authorship concept) for the whole render scope, and the
Bot autonomous slot-fill tags the same `meterActor()` pair for its one pre-run call. Every tag is set and
cleared in the SAME `finally` as the existing `MeterContext::sessionId` tag, so a shared queue worker never
leaks an actor into the next unrelated job. Names are resolved **generically, at READ time**, through
`Relation::getMorphedModel()` — never stored — so `AiUsageService` never imports a concrete `User`/`Bot`/
`WorkflowRun` class and the summary always reflects the actor's CURRENT name (or gracefully resolves to
null for a departed member / a purged system record, falling back to a per-type label in the FE).

**D3a — `MeterActorResolver` deliberately lives in `App\Support\Meter`, not under `app/modules/Variables`.**
This is the SAME boundary escape hatch `App\Traits\HasCreator` already uses (see
`docs/backend/creator-attribution.md`): a Workflows run-context binding is referenced as a compile-time
`::class` string (no `use` import), lazily and guarded (`app()->bound(...)`) rather than injected, so this
class carries no runtime or static dependency on the Workflows module. `LedgerMeteredAiCall` (a `Variables`
class) DELEGATES to it instead of naming `WorkflowRunContext` itself, which would trip the one-way
`Variables → Workflows` boundary scan. **This is intentional, not an oversight** — flagged explicitly here
so a future reviewer doesn't "fix" it back into the module and re-introduce the dependency it was built to
avoid.

**D4 — a pre-run 429 gate is added on top of the existing mid-run fail-soft, at ONE choke point.** Before
this ADR, an over-cap workspace only found out mid-flight: an `@[ai-text]` block silently resolved to `''`,
an image part failed with `image_budget`. That is still exactly what happens when a run CROSSES the cap
while already in flight (unchanged — see D5). But a workspace that is ALREADY over cap BEFORE a run starts
gets a new, louder signal: `GenerationSessionRunManager::claimAndDispatch()` calls
`AiUsageService::blocked()` (`cap() > 0 && currentMonthCost() >= cap()`) and throws the renderable
`GenerationBudgetExceeded` — **HTTP 429, body `{code: 'ai_budget_exceeded', message}`** — BEFORE the atomic
claim, so an already-over-cap workspace's run is never claimed, dispatched, or partially billed.

`claimAndDispatch()` is the SINGLE choke point every session run entry routes through — the FE's whole-
session `generate`, the per-part `regenerate`/`refine`, and the bot delegation's `auto_generate` opt-in
(`BotSessionDelegationController::store`) all call it — so the gate lives ONCE, not duplicated across four
controllers. `blocked()` reuses the EXACT SAME predicate the usage summary's `blocked` field reads, so the
server's up-front refusal and the FE's budget banner can never disagree about whether a workspace is over
cap.

**D5 — the pre-run gate and the mid-run fail-soft are DELIBERATELY DIFFERENT mechanisms answering different
questions, and both stay.** Pre-run (D4) answers "is this workspace ALREADY over cap RIGHT NOW" — a real
HTTP 429 the FE recognizes and turns into a blocked-state banner. Mid-run
(`Variables\Exceptions\AiBudgetExceededException`, unchanged since ADR-0033 D6) answers "did THIS run's own
spend just push the workspace over cap" — every existing catch site (`AiTextGenerationService::generate()`/
`generateWith()`, `ImageChainExecutor`) still swallows it fail-closed exactly as before, because a run that
is already claimed and in flight must finish (fail-soft, per-part) rather than abort wholesale. The bot
slot-fill call is a clean illustration of the seam: it is a PRE-run call relative to a generation run, but
it goes through the ordinary metered `ai_text` seam like any other spend, so an already-over-cap workspace's
delegation fill is fail-closed EMPTY (`AiTextGenerationService::generateWith`'s existing catch), never a
429 — only the SESSION RUN entry points (D4's `claimAndDispatch`) get the louder pre-run refusal.
**Rejected: replacing the mid-run fail-soft with the 429 gate everywhere.** Would mean an in-flight run
could abort wholesale mid-render the instant its own spend crosses the cap, discarding already-good parts —
worse UX than the existing fail-soft-per-part contract, for no real safety gain (the fail-soft path already
stops further spend on that block/part).

## Consequences

- **Positive.** An owner can set/clear/read a real dollar budget through `GET`/`PATCH
  /workspaces/{id}/ai-usage(/cap)`, see it broken down by channel and by who spent it, and get a real 429
  the moment they try to run something over budget — closing the ADR-0033 deferred gap end to end.
- **Positive.** The per-actor breakdown is "free" architecturally — it rides the SAME morph-map pattern
  `HasCreator`/`CreatorResource` already established, so the FE's `CreatorBadge` conventions (glyph + label
  per type, graceful null fallback) transfer directly to `AiUsagePage`'s actor rows.
- **Trade-off (accepted, inherited from ADR-0033, now sharper).** `estimated_cost` is still never billed
  on — a missing/zero price silently keeps the gate open for that channel, and the recorded $ for an image
  channel is a flat per-call estimate, not a provider-reported figure (no image response carries a real
  cost). This is a soft operational budget, not a billing system, and the UI says so on every screen.
  A future real billing integration needs its own, provider-reconciled cost model.
- **Trade-off (accepted).** The pre-run gate only covers Generator session runs (the app's one AUTOMATIC,
  unattended spender). Workflows `@[ai-text]` and Disk AI edits still only have the mid-run fail-soft (their
  pre-existing posture, unchanged) — an over-cap workspace's workflow run or Disk edit dispatch still only
  discovers the cap via the existing `AiBudgetExceededException` path, not a new 429. Extending the pre-run
  429 to those spenders was out of scope for this sub-stage; nothing in D4 blocks doing so later (the same
  `AiUsageService::blocked()` predicate is reusable anywhere).
- **Rejected: keeping the token cap as the gate basis and only adding a $ DISPLAY on top.** Considered as
  the minimal option — reuse `monthly_token_cap`, compute an estimated $ figure purely for display. Rejected
  because the actual product ask ("set a monthly $ budget you can reason about") is a $ gate, not a $
  readout over a token gate a user still cannot directly control; and a token cap has no honest per-channel
  translation (an image edit's "token" count is already a fabricated stand-in, so gating on it doubly
  obscures the real cost driver).
- **Rejected: modeling the bot-delegation actor as its own new attribution concept instead of reusing
  `GenerationSession::meterActor()`.** `meterActor()` already exists (from ADR-0036's delegation overlay) to
  answer "whose voice is this content in" — reusing it for "who does this session's spend belong to" is the
  same answer asked a second way, so a parallel mechanism would be pure duplication.

## Supersession

**Supersedes part of ADR-0033.** ADR-0033's D3 ("the budget unit is TOKENS... an `estimated_cost` column is
stored alongside as an ADVISORY dollar figure... explicitly never billed on [as a gate basis]") and D4 (the
opaque-channel token stand-in as the gate mover) are **superseded**: the gate basis is now `estimated_cost`
($), computed by the per-channel `pricing` map (D1 above); `total_tokens`/`monthly_token_cap` are
telemetry-only from this ADR forward. ADR-0033's D1/D2/D6/D7/D8 (the ai-text down-move, the `meter()`
gate-then-record shape, the fail-closed catch convention, `MeterContext`'s session tag, and the per-run
call-count budgets staying separate from the ledger) are UNCHANGED and still the operative design for those
concerns.

See `docs/backend/workspace-ai-usage-api.md` for the endpoint contract this ADR unblocks, and
`docs/backend/generator-sessions-api.md` → "Cost meter integration" for the updated consumer-facing
contract on the Generator side.
