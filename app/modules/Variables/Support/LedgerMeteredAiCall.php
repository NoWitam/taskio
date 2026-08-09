<?php

namespace App\Modules\Variables\Support;

use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Variables\Services\AiUsageService;
use App\Support\Meter\MeterActorResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;
use Throwable;

/**
 * The real cost METER bound over the pass-through on the {@see MeteredAiCall} seam (D7). Every AI spend
 * routes through {@see meter()}, which:
 *
 *   1. GATES BEFORE SPEND (R2 sub-stage 4 — DOLLAR-based) — if the workspace's effective monthly $ cap
 *      ({@see AiUsageService::cap()}, the SINGLE source of truth) is set and its calendar-month
 *      `estimated_cost` sum already meets it, it throws {@see AiBudgetExceededException} BEFORE running the
 *      closure, so the provider is never hit over-cap (the load-bearing safety property).
 *   2. runs the provider closure,
 *   3. RECORDS the spend (fail-safe: never throws out of recording) — a text response's real
 *      prompt/completion tokens, or a config UNIT for an opaque result (an image edit), the CHANNEL-AWARE
 *      estimated_cost (text per-token, image per-call), the ambient {@see MeterContext} session, and the
 *      resolved polymorphic ACTOR ({@see MeterActorResolver}: explicit tag → active run → auth user → null).
 *
 * An effective cap of <= 0 DISABLES the gate — the shipped default (no workspace override + env default
 * 0.0) — so existing Workflow/Disk behavior is byte-preserved until an operator sets a cap. Recording
 * no-ops when no workspace is active (a spend outside a tenant has nothing to attribute), guarding like
 * the Disk service's broadcast. The gate's ledger read fails OPEN (a DB hiccup is logged and treated as
 * within-budget) — the Disk per-day COUNT cap remains a secondary backstop — so a transient read error
 * cannot wedge all AI features.
 */
class LedgerMeteredAiCall implements MeteredAiCall
{
    public function __construct(
        private TenantContext $tenant,
        private MeterContext $meterContext,
        private AiUsageService $usage,
        private MeterActorResolver $actorResolver,
    ) {}

    public function assertWithinBudget(string $channel, float $projectedCost = 0.0): void
    {
        $cap = $this->usage->cap();

        if ($cap <= 0 || !$this->tenant->hasWorkspace()) {
            return; // gate disabled (effective cap off), or no workspace to gate
        }

        try {
            $used = $this->usage->currentMonthCost();
        } catch (Throwable $e) {
            // Fail-OPEN: a ledger read hiccup must not wedge every AI feature. The Disk per-day COUNT
            // cap stays as a secondary backstop. Tradeoff: a transient DB error can let a spend
            // through slightly over cap — an acceptable failure mode for an advisory budget.
            Log::warning('AI meter gate query failed; treating as within budget: ' . $e->getMessage());

            return;
        }

        // A PROJECTION is added to what has already been spent, so a MULTI-CALL pipeline can ask whether
        // the whole of it fits before it pays for the first part. `max(0, …)` because a negative
        // projection is a caller bug and must never be able to LOOSEN the gate. With the default 0.0
        // this is the original comparison, byte for byte, for every existing caller.
        if ($used + max(0.0, $projectedCost) >= $cap) {
            throw new AiBudgetExceededException($channel, $used, $cap);
        }
    }

    public function meter(string $channel, callable $call): mixed
    {
        $this->assertWithinBudget($channel); // GATE BEFORE SPEND

        $result = $call();

        $this->record($channel, $result);

        return $result;
    }

    /** Persist one usage row. Fail-safe: any error is logged, never propagated to the caller. */
    private function record(string $channel, mixed $result): void
    {
        if (!$this->tenant->hasWorkspace()) {
            return; // no active workspace → no row to attribute the spend to
        }

        try {
            [$promptTokens, $completionTokens, $totalTokens] = $this->deriveTokens($channel, $result);
            [$actorType, $actorId] = $this->actorResolver->resolve(
                $this->meterContext->actorType(),
                $this->meterContext->actorId(),
            );

            AiUsageEvent::create([
                'channel' => $channel,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost' => $this->estimateCost($channel, $totalTokens),
                'session_id' => $this->meterContext->sessionId(),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'meta' => ['model' => config('ai.model')],
            ]);
        } catch (Throwable $e) {
            Log::warning('AI meter failed to record a usage event: ' . $e->getMessage());
        }
    }

    /**
     * Token counts for one result, in descending order of honesty:
     *
     *   1. a laravel/ai TEXT response exposes REAL provider tokens on `->usage` (a prompt half and a
     *      completion half);
     *   2. an EMBEDDINGS response ({@see \Laravel\Ai\Responses\EmbeddingsResponse}, and anything else
     *      shaped like it) has NO `->usage` — embeddings have no completion half, so the package
     *      reports one flat `->tokens` int instead. Read it as prompt tokens, because that is what it
     *      is: the input the provider actually charged for. Without this branch the Knowledge indexer
     *      would fall through to the unit stand-in below and record a made-up flat rate for a channel
     *      whose real cost is known exactly;
     *   3. an OPAQUE result (an image-edit array, a raw string, anything unknown) has neither, so the
     *      channel's configured UNIT stands in as the total and the budget still moves.
     *
     * The order is what keeps this byte-preserving for the existing spenders: every text response
     * carries `->usage` and is answered by branch 1 before branch 2 is ever consulted.
     *
     * @return array{0: int, 1: int, 2: int} [promptTokens, completionTokens, totalTokens]
     */
    private function deriveTokens(string $channel, mixed $result): array
    {
        if (is_object($result) && isset($result->usage) && $result->usage instanceof Usage) {
            $promptTokens = (int) $result->usage->promptTokens;
            $completionTokens = (int) $result->usage->completionTokens;

            return [$promptTokens, $completionTokens, $promptTokens + $completionTokens];
        }

        if (is_object($result) && isset($result->tokens) && is_int($result->tokens)) {
            $tokens = max(0, $result->tokens);

            return [$tokens, 0, $tokens];
        }

        $unit = (int) config('ai.meter.unit_cost.' . $channel, 0);

        return [0, 0, $unit];
    }

    /**
     * The CHANNEL-AWARE estimated dollar cost of one spend (R2 sub-stage 4 — the load-bearing gate basis):
     *   - a per-CALL channel (`ai_image_edit` / `ai_image_generate`) → the flat `pricing.<channel>.per_call`
     *     (images are priced per call, independent of the opaque result's token stand-in);
     *   - otherwise (`ai_text`) → `total_tokens / 1000 * pricing.ai_text.per_1k_tokens` on REAL provider tokens.
     * A missing/zero price yields 0.0 (no estimate — the gate stays open for that channel).
     */
    private function estimateCost(string $channel, int $totalTokens): float
    {
        $pricing = config('ai.meter.pricing.' . $channel);

        if (is_array($pricing) && isset($pricing['per_call'])) {
            return round((float) $pricing['per_call'], 4);
        }

        $per1k = is_array($pricing) ? (float) ($pricing['per_1k_tokens'] ?? 0.0) : 0.0;

        return round($totalTokens / 1000 * $per1k, 4);
    }
}
