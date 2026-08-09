<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\DTOs\ExtractedMention;
use App\Modules\Knowledge\Services\KnowledgeDraftService;
use App\Modules\Knowledge\Services\KnowledgeIndexService;
use App\Modules\Knowledge\Services\KnowledgeMentionExtractor;

/**
 * WHAT ONE COMPOSER RUN WILL COST, in dollars, before a cent of it is spent.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL
 *
 * The budget gate asks "is there any money left". For a single call that is the same question as "can
 * I afford this". For a PIPELINE it is not: with resolution the run is extraction → embeddings →
 * composition, and a gate that only looks at the first one can pass, charge the workspace for reading
 * the document, and then find the budget gone before anything is written. The user is billed for a
 * session that produced nothing, and told "unparseable answer".
 *
 * So the whole run is projected once, up front, and refused as a unit. Paying for none of it is
 * strictly better than paying for the cheap third of it.
 *
 * ------------------------------------------------------------------------------------------------
 * IT IS AN ESTIMATE, AND THE ERROR DIRECTION IS A DECISION
 *
 * Tokens are approximated at FOUR CHARACTERS EACH — the rule of thumb the rest of this codebase already
 * uses (see the fake embedder's own token count). It is wrong for both Polish and code, in opposite
 * directions, and precision here would buy nothing: the ledger records REAL provider tokens the moment
 * the call returns, so this number governs one decision and is then discarded.
 *
 * The output terms are sized against what a run REALISTICALLY produces, not against the hard caps.
 * `MAX_REPLY_CHARS` is 120 000 — a ceiling that exists to stop a runaway, not a description of an
 * answer — and projecting it would refuse sessions that would have cost a twentieth of that. Being too
 * pessimistic is not the safe direction here: it turns a working feature off for people who can afford
 * it, and it does so invisibly, which is exactly the failure this class was built to prevent one form
 * of.
 */
final class KnowledgePipelineEstimate
{
    /** Characters per token. The codebase's existing rule of thumb; see the class docblock. */
    private const CHARS_PER_TOKEN = 4;

    /**
     * What a composed set of entries realistically runs to, per entry. Deliberately not
     * `entry_max_chars` (40 000) — that is a limit on what an entry MAY hold, and no composer writes
     * eight of them.
     */
    private const CHARS_PER_COMPOSED_ENTRY = 3000;

    /** What one extracted mention costs in the reply: a name, a kind and a short quotation. */
    private const CHARS_PER_MENTION = 200;

    /**
     * The projected dollar cost of composing from this material, resolution included when it is on.
     *
     * Priced per CHANNEL through the same config the meter charges against, so the projection and the
     * bill are derived from one set of numbers — a projection with its own price table would drift
     * from the ledger the first time anybody tuned either.
     */
    public static function forSource(string $sourceText): float
    {
        $source = mb_strlen($sourceText);
        $resolving = (bool) config('knowledge.graph_extraction.enabled');

        $cost = 0.0;

        if ($resolving) {
            $mentions = max(1, (int) config('knowledge.graph_extraction.max_mentions'));

            // PHASE 1: the material in, a list of names out.
            $cost += self::price(
                KnowledgeMentionExtractor::CHANNEL,
                $source + $mentions * self::CHARS_PER_MENTION,
            );

            // The batched embedding of those names. Three orders of magnitude cheaper than the text
            // channels — included because leaving a term out of a projection is how projections start
            // being wrong in one direction only.
            $cost += self::price(
                KnowledgeIndexService::CHANNEL,
                $mentions * (ExtractedMention::MAX_TEXT_CHARS + ExtractedMention::MAX_CONTEXT_CHARS),
            );
        }

        // PHASE 2: the composition itself — the material, the frozen context, the resolved entities,
        // the titles of the base, and the set it writes back.
        $context = (int) config('knowledge.drafting.retrieval_total_chars')
            + ($resolving ? (int) config('knowledge.resolution.total_chars') : 0);

        $output = max(1, (int) config('knowledge.drafting.max_entries_per_session')) * self::CHARS_PER_COMPOSED_ENTRY;

        $cost += self::price(KnowledgeDraftService::CHANNEL, $source + $context + $output);

        return round($cost, 4);
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
