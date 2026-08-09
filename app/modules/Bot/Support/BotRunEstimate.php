<?php

namespace App\Modules\Bot\Support;

/**
 * WHAT ONE BOT RUN WILL COST, in dollars, before a cent of it is spent.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY A PROJECTION AND NOT JUST "IS ANY BUDGET LEFT"
 *
 * A bot run is not one provider call. The execution agent is multi-step (`#[MaxSteps(12)]`): it reads,
 * calls a tool, reads the result, calls another, and every one of those steps re-sends the whole
 * conversation so far. A gate that only asked "is there any money left" could pass a workspace with a
 * cent of headroom into a run that spends fifty times that before it stops — and the run cannot be
 * stopped halfway, because laravel/ai owns the loop and never returns control between steps.
 *
 * So the run is projected once, up front, and refused as a unit. This is the same argument
 * {@see \App\Modules\Knowledge\Support\KnowledgePipelineEstimate} makes for the composer pipeline; the
 * difference is that a pipeline's stages are ours to interleave a gate between and an agent's steps
 * are not, which makes the projection MORE load-bearing here, not less.
 *
 * ------------------------------------------------------------------------------------------------
 * IT IS AN ESTIMATE, AND THE ERROR DIRECTION IS A DECISION
 *
 * Tokens are approximated at FOUR CHARACTERS EACH — the codebase's existing rule of thumb. Precision
 * would buy nothing: the ledger records the run's REAL provider tokens the moment it returns (laravel/ai
 * sums usage across every step), so this number governs one decision and is then discarded.
 *
 * The step count is a TYPICAL run's, from config, not the MaxSteps ceiling. Projecting the ceiling
 * would refuse runs that cost a quarter of the estimate, and it would do it invisibly — a bot that
 * simply stops working is a worse failure than a bot that overshoots a cap by one run.
 */
final class BotRunEstimate
{
    /** The meter channel this projection prices against — the same one the run is billed on. */
    public const CHANNEL = 'ai_bot_task';

    /** Characters per token. The codebase's existing rule of thumb; see the class docblock. */
    private const CHARS_PER_TOKEN = 4;

    /**
     * The agent's own instruction block minus the task context: persona, style, dictionary, phrases,
     * prohibitions, the tool rules and the JSON schemas of the exposed tools. Roughly constant per run
     * and re-sent on every step, so it belongs in the per-step term rather than being ignored.
     */
    private const INSTRUCTION_CHARS = 4000;

    /**
     * What ONE step adds to the conversation: the model's own message plus a tool call's arguments and
     * the tool's result (a posted comment, a form fill, a fetched page's summary). Charged as both
     * output on the step that produces it and input on every later step.
     */
    private const CHARS_PER_STEP = 1500;

    /**
     * The projected dollar cost of running a bot against a task whose assembled context is $context.
     *
     * The model is the one an agent loop actually has: step n re-sends the instructions, the context and
     * everything the earlier steps produced. Summing that over n steps gives the base term n times plus
     * a triangular number of step terms — which is why an agent loop costs more than "n times one call"
     * and why projecting it as such would understate it.
     *
     * Priced through the SAME config the meter charges against, so the projection and the bill derive
     * from one set of numbers; a projection with its own price table would drift from the ledger the
     * first time anybody tuned either.
     */
    public static function forRun(string $context): float
    {
        $steps = max(1, (int) config('ai.bot_run_projected_steps', 3));
        $base = mb_strlen($context) + self::INSTRUCTION_CHARS;

        // n re-sends of the fixed part, plus the growing tail: step 1 carries 0 prior steps, step n
        // carries n-1 of them, and each step also emits one of its own.
        $chars = $steps * $base
            + self::CHARS_PER_STEP * (int) ($steps * ($steps + 1) / 2);

        return round(self::price(self::CHANNEL, $chars), 4);
    }

    /** One channel's price for a number of characters, through the meter's own configured rate. */
    private static function price(string $channel, int $chars): float
    {
        $per1k = (float) config('ai.meter.pricing.' . $channel . '.per_1k_tokens', 0.0);

        if ($per1k <= 0.0) {
            return 0.0; // an unpriced channel projects nothing, exactly as it bills nothing
        }

        return (int) ceil($chars / self::CHARS_PER_TOKEN) / 1000 * $per1k;
    }
}
