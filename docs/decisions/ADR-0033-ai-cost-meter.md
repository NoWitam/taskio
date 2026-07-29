# ADR-0033 — R2 sub-stage 2a: the AI cost meter (D7 seam, ledger, gate-before-spend) + the ai-text down-move

**Date:** 2026-07-28 (created)
**Status:** Accepted — **partially superseded by ADR-0037** (R2 sub-stage 4): the gate basis is now
`estimated_cost` ($), not `total_tokens`; `monthly_token_cap` is telemetry-only from ADR-0037 forward. D1,
D2, D6, D7, D8 below are unchanged. See ADR-0037 → "Supersession" for the precise boundary.
**Module:** `App\Modules\Variables` (receives the meter + the shared ai-text generator), `App\Modules\Workflows`
(rewired to a thin decorator, behavior-preserving), `App\Modules\Disk` (rewired to route `ai_image_edit`
through the same seam), `App\Modules\Generator` (the new spender this sub-stage exists to unblock — see
ADR-0034)
**Relates to:** ADR-0030 (the `AiTextGenerator` contract + the `MeteredAiCall` D7 seam were both DEFINED
there, as a no-op pass-through — this ADR is where the seam becomes a real meter and gets a real second
caller), ADR-0032 (Generator sub-stage 1 defined the seam but called nothing through it — "Sub-stage 1 runs
no real AI at all"), ADR-0034 (Generation Sessions — the first feature that actually SPENDS through this
meter)

---

## Context

ADR-0030 relocated the workflow interpolation engine into `App\Modules\Variables` and, as part of that move,
introduced two seams specifically so a SECOND ai-text consumer (Generator) would not have to fork anything:

- `Variables\Contracts\AiTextGenerator` — the interface `VariableResolver` depends on for `@[ai-text]`.
- `Variables\Contracts\MeteredAiCall` (D7, defined in ADR-0032) — a "wrap this provider call" seam, bound to
  a **no-op pass-through** (`PassthroughMeteredAiCall`). Nothing called it: sub-stage 1 (Templates) never
  runs real AI, and the two PRE-EXISTING spenders (Workflows' `@[ai-text]`, Disk's AI image edit) still
  called their providers directly.

R2 sub-stage 2 ("Sesje") is the roadmap's first sub-stage with a REAL AI spender end-to-end — a generation
session's live `@[ai-text]` render and its `ai_edit` image chain. The product plan
(`docs/product/plan-dzialania.md`) explicitly PULLS "AI cost limits" forward to this sub-stage (it was
originally a later, separate chapter) precisely because it is the first point at which real, billed AI spend
happens automatically, unattended, at whatever rate a user's recipes/sessions trigger it — the same shape of
risk a runaway workflow schedule already carries for its own `@[ai-text]` calls, just now compounded by
Generator's own fan-out (several inline AI blocks per part, several parts per recipe).

Three questions needed answering before Sessions could spend anything for real:

1. **Where does the actual generation call for `@[ai-text]` live**, given TWO callers (Workflows, Generator)
   now need "run a short-text AI agent, budgeted, fail-closed"?
2. **What does the `MeteredAiCall` seam actually DO** once it stops being a pass-through?
3. **What is the budget UNIT and SCOPE** — per call? per run? per workspace? per month? — and how does a
   gate that FAILS a spend differ from one that merely COUNTS it after the fact?

## Decisions

**D1 — the ai-text GENERATION logic (not just the resolver contract) moves down into Variables, as a new
`Variables\Services\AiTextGenerationService`.** Before this ADR, `WorkflowAiTextService` (Workflows) directly
built the `AiTextAgent` (Workflows-namespaced), called `->prompt()`, trimmed/truncated, and caught failures
fail-closed. That whole body is now the SHARED `AiTextGenerationService::generate(prompt, personaId, maxChars,
purposeHint)`; both `Variables\Agents\AiTextAgent` (relocated from Workflows verbatim, `Enums\AiPersona`
alongside it) and this service now live in Variables. Each caller becomes a THIN DECORATOR implementing the
ADR-0030 `AiTextGenerator` contract — `WorkflowAiTextService` (stays in Workflows) and the new
`Generator\Services\GeneratorAiTextService` — whose ONLY remaining job is the PER-RUN call-count budget
(instance state, so it naturally scopes to one workflow run / one session run) and a caller-specific
`$purposeHint` string (so the model frames "a workflow field" differently from "a piece of social-media post
content" — the wording itself is UNCHANGED for Workflows, verified by keeping its `PURPOSE_HINT` constant
byte-identical). Neither decorator names the other's module; both depend only on the shared service.

```php
// app/modules/Generator/Services/GeneratorAiTextService.php
class GeneratorAiTextService implements AiTextGenerator
{
    private int $calls = 0; // per-run budget — instance state, resolved fresh each session run

    public function generate(string $prompt, ?string $personaId): string
    {
        if (trim($prompt) === '') return '';
        if ($this->calls >= $this->maxCalls()) return ''; // fail-closed, budget exhausted
        $this->calls++;

        return $this->generator->generate($prompt, $personaId,
            (int) config('generator.ai_text_max_chars', 5000), self::PURPOSE_HINT);
    }
}
```

**D2 — the `MeteredAiCall` seam becomes a real ledger meter: `Variables\Support\LedgerMeteredAiCall`,
bound over the pass-through in `VariablesModuleServiceProvider`.** `meter(string $channel, callable $call):
mixed` does exactly three things, in order: (1) **gate before spend** — `assertWithinBudget($channel)`
throws `AiBudgetExceededException` BEFORE `$call()` ever runs, if a cap is configured and already met; (2)
run `$call()`; (3) **record** the spend as an `AiUsageEvent` row, fail-safe (a recording error is logged,
never propagated — a spend that already happened must never surface as a caller-facing exception over a
bookkeeping failure). `assertWithinBudget()` is ALSO exposed standalone (not only via `meter()`) so a caller
that needs to refuse work BEFORE building an expensive payload — Disk's async AI-edit DISPATCH — can
pre-flight the gate without a callback.

```php
// app/modules/Variables/Support/LedgerMeteredAiCall.php
public function meter(string $channel, callable $call): mixed
{
    $this->assertWithinBudget($channel); // GATE BEFORE SPEND — the load-bearing property
    $result = $call();
    $this->record($channel, $result);
    return $result;
}
```

**D3 — the budget unit is TOKENS, the scope is the WORKSPACE, the window is the CALENDAR MONTH.** Not a
call count, not a dollar figure, not a rolling window: `config('ai.meter.monthly_token_cap')` compares
against `AiUsageService::currentMonthTokens()` — `SUM(total_tokens) WHERE created_at >= startOfMonth()`,
scoped by `AiUsageEvent`'s own `WorkspaceScope`/`TenantAware` posture (so a shared-DB query is automatically
"this workspace" and an own-DB query runs on that tenant's connection — no explicit `workspace_id` filter
needed in the service). Tokens, not dollars, because a text response's `Usage->promptTokens`/
`completionTokens` are the only REAL figure laravel/ai gives back; an `estimated_cost` column is stored
alongside as an ADVISORY dollar figure from a config price map (`ai.meter.price_per_1k_tokens`, 0.0 by
default), explicitly never billed on. Calendar-month (not rolling 30 days) because it is the simplest mental
model for a UI to state ("resets on the 1st") and requires no window-boundary bookkeeping beyond a `>=` on
`created_at`.

**D4 — a channel with no real token count records a configured UNIT instead, so the SAME gate governs an
opaque spend.** `ai_image_edit` (Disk's edit, and now Generator's `ai_edit` filter step) returns a raw
base64-image array from the provider client — no `Usage` object. `LedgerMeteredAiCall::deriveTokens()`
falls back to `config('ai.meter.unit_cost.<channel>')` (default 4000 for `ai_image_edit`) as the recorded
`total_tokens`, so an image edit still MOVES the same monthly gate an `ai_text` call does, without inventing
a second budget dimension.

**D5 — a cap of `0` disables the gate; the shipped default is `0`.** `assertWithinBudget()` returns
immediately when `monthly_token_cap <= 0` OR no workspace is active (nothing to attribute a gate decision
to). This is the load-bearing backward-compatibility property: EVERY pre-existing AI spender (Workflows
`@[ai-text]`, Disk AI edits) is rewired to route through the meter in this same ADR, but with the gate
disabled by default, their observable behavior is BYTE-PRESERVED until an operator opts in by setting
`AI_MONTHLY_TOKEN_CAP`. Disk keeps its own PRE-EXISTING per-day COUNT cap
(`disk_image_max_per_day`) as an independent, unrelated backstop — the two caps are orthogonal (one counts
edits/day, the other tokens/month) and neither replaces the other.

**D6 — an over-cap gate is a `Throwable`, and every caller already has a fail-closed catch that swallows
it — so `AiBudgetExceededException` needed NO new per-caller handling for `@[ai-text]`.**
`AiTextGenerationService::generate()`'s existing `catch (Throwable $e)` (provider/transport failures →
`''`) now ALSO catches the budget exception for free — an over-cap `@[ai-text]` directive simply resolves to
`''`, exactly like a provider outage would, with no directive-author-visible distinction between "the
provider is down" and "this workspace hit its cap" (both are equally non-actionable from inside a running
render). Disk's SYNCHRONOUS dispatch pre-flight (D2) is the one caller that DOES distinguish it, translating
to an explicit `429` at the point of the user's action (starting an edit), where a distinct message is
actually useful.

**D7 — `Variables\Support\MeterContext` is a request-scoped singleton mirroring `TenantContext`, defined now
but only CONSUMED starting with Generation Sessions.** It holds an ambient, settable `?string $sessionId`
that `LedgerMeteredAiCall::record()` stamps onto every `AiUsageEvent`. Workflows and Disk set nothing (their
events carry `session_id: null`); `GenerationSessionExecutor` is the first (and so far only) caller that
`setSession()`s around its render scope (cleared in a `finally`) — see ADR-0034. Defining it here rather than
inside Generator keeps the meter itself session-attribution-CAPABLE without Variables ever naming a
Generator class.

**D8 — the per-run/per-session call-count budgets (`workflows.ai_text_max_calls_per_run`,
`generator.ai_text_max_calls_per_session`, `generator.image_edit_max_calls_per_session`) are DELIBERATELY
NOT part of the meter.** They remain instance-state counters on each thin decorator (D1) / on
`ImageChainExecutor`, answering a DIFFERENT question than the token gate: "how far can ONE run's fan-out go
before it's cut off," not "has this workspace spent too much this month." The two stack independently — see
`docs/backend/generator-sessions-api.md` → "Cost meter integration" for the worked table.

## Consequences

- **Positive.** Generator's Sessions (ADR-0034) get a real, budgeted `@[ai-text]` and `ai_edit` for free —
  no new generation logic, no new budget primitive, only a thin decorator + wiring.
- **Positive.** Workflows and Disk are rewired onto the SAME meter with byte-preserved default behavior
  (D5) — proven by the pre-existing Workflow/Disk suites passing unmodified, plus the new
  `tests/Feature/AiCostMeterTest.php` pinning gate-before-spend, fail-open on a ledger-read error, and
  session-tagging.
- **Positive.** A future per-workspace usage/limit UI (deferred — see
  `docs/backend/generator-sessions-api.md` → "Planned / deferred") reads ONE ledger
  (`AiUsageEvent`) across every channel and every spender, rather than a per-module count.
- **Trade-off (accepted).** The gate is advisory-safety, not hard-real-time: it fails OPEN on a ledger-read
  error (D2) and the recorded token count for an opaque channel is a CONFIGURED estimate, not a measured
  figure (D4) — a transient DB hiccup or an under-configured unit cost can let a spend through slightly over
  cap. Acceptable for a budget that is a soft operational safeguard, not a billing system.
- **Trade-off (accepted).** `estimated_cost` is explicitly never billed on (D3) — a future real billing
  integration would need its own, provider-reconciled cost model; this column is advisory-only by design.
- **Rejected: a per-call/per-request hard cap instead of a monthly token cap.** Too coarse to express "the
  workspace has a monthly AI budget," which is the actual product framing from
  `docs/product/wizja-produktu.md`; a per-run call-count ceiling (kept, D8) already bounds a single runaway
  recipe, so a SECOND per-request cap would be redundant with the monthly gate.
- **Rejected: giving `MeteredAiCall`/`LedgerMeteredAiCall` its own per-channel dollar cap** instead of one
  workspace-wide token cap. Would require a real, per-provider, per-model price table to be meaningful (the
  price varies by model/provider and changes over time); a single token cap is provider-agnostic and matches
  what the responses actually report.

See `docs/backend/generator-sessions-api.md` → "Cost meter integration" for the consumer-facing contract
this ADR unblocks, and `docs/decisions/ADR-0034-generation-sessions.md` for the session engine that is now
the meter's primary real-world caller.
