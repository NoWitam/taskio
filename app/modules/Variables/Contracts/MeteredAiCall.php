<?php

namespace App\Modules\Variables\Contracts;

/**
 * The SHARED "metered AI call" seam (D7) — the ONE place every AI spend (AI text now via
 * {@see AiTextGenerator}, AI image edit later via the Disk edit client) will be routed through so a single
 * cost/token METER can account for all of them. It lives here, next to {@see AiTextGenerator}, in the
 * lower Variables layer both Workflows and (R2) Generator depend on, so no upper module owns the seam.
 *
 * CONTRACT ONLY in this rework: the shipped default is a PASS-THROUGH
 * ({@see \App\Modules\Variables\Support\PassthroughMeteredAiCall}) that just runs the call and returns its
 * result — NO metering behavior, NO budget, NO counting. The real $/token meter (the first genuine
 * spender) is built in sub-stage 2 (Sessions) by binding a metering implementation over this same seam;
 * nothing about the callers changes then.
 *
 * A metered unit wraps the actual provider call in a closure so the meter can bracket it (count a spend,
 * enforce a cap, record tokens) WITHOUT knowing the provider. `$channel` is a coarse label for the spender
 * (e.g. `ai_text`, `ai_image_edit`) so a future meter can bucket per channel.
 */
interface MeteredAiCall
{
    /**
     * Run one metered AI call and return its result. A metering implementation GATES BEFORE SPEND
     * (refusing an over-cap workspace before $call runs), invokes $call, then records the spend. The
     * default pass-through simply invokes $call. It MUST be transparent to the result type.
     *
     * @template TResult
     *
     * @param  string  $channel  a coarse spender label (e.g. 'ai_text', 'ai_image_edit')
     * @param  callable():TResult  $call  the provider call to meter
     * @return TResult
     */
    public function meter(string $channel, callable $call): mixed;

    /**
     * Pre-flight the budget gate WITHOUT running a call, for spenders that must refuse up front (e.g.
     * the Disk image dispatch, which returns a clean 429 before queuing a job rather than failing a
     * worker later). A metering implementation throws
     * {@see \App\Modules\Variables\Exceptions\AiBudgetExceededException} when the channel is over cap;
     * the pass-through does nothing. `meter()` runs this same check itself, so a caller that only ever
     * calls `meter()` need not call this.
     *
     * $projectedCost lets a MULTI-CALL spender ask the question it actually has: not "is any budget
     * left" but "is there enough left for the WHOLE thing I am about to start". A pipeline that gates
     * only on its first call can pass, spend, and then die halfway — having charged the workspace for
     * work nobody will ever see. Defaulting to 0.0 keeps every existing caller byte-identical: with no
     * projection the question is exactly the old one.
     *
     * It is an ESTIMATE and must be treated as one. Refusing on a projection can turn away a run that
     * would in fact have fitted, so a caller should project against its real caps rather than paranoid
     * multiples of them.
     */
    public function assertWithinBudget(string $channel, float $projectedCost = 0.0): void;
}
