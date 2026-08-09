<?php

namespace App\Modules\Knowledge\Support;

/**
 * The LENGTH-AWARE similarity bar (B10.1), as one authority.
 *
 * Cosine is not comparable across lengths. A long passage averages many sentences into its vector, so
 * two documents about one subject land high; a one-sentence note has almost nothing to average, so the
 * SAME relation lands far lower — measured on dev, two entries any reader calls related scored 0.6498,
 * nowhere near the 0.86 that was calibrated on long prose.
 *
 * EXTRACTED (not forked) from {@see \App\Modules\Knowledge\Services\KnowledgeSimilarityLinker}, whose
 * behaviour it preserves exactly, because the drafting composer's context retrieval has to judge the
 * same kind of comparison and a second copy of this rule would drift from the first the next time the
 * numbers are tuned. Both callers now read one definition.
 */
class SimilarityThreshold
{
    /**
     * The bar a pair has to clear, given the character lengths of the two texts being compared.
     *
     * EITHER end being short is enough to use the gentler bar. A short note paired with a long article
     * suffers the same compression on one side, and requiring both to be short would leave exactly that
     * pairing — a note about a topic and the article about it — permanently unmatchable.
     */
    public static function for(int $sourceLength, int $candidateLength): float
    {
        $short = (int) config('knowledge.similarity.short_chunk_chars');

        return $sourceLength < $short || $candidateLength < $short
            ? (float) config('knowledge.similarity.threshold_short')
            : (float) config('knowledge.similarity.threshold');
    }
}
