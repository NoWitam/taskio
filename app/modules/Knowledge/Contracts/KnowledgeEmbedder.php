<?php

namespace App\Modules\Knowledge\Contracts;

use App\Modules\Knowledge\DTOs\EmbeddingBatchResult;

/**
 * The seam every embedding in this module goes through.
 *
 * It exists so that NO test can reach a real provider. The indexing path is the module's only AI
 * spend, and it is exercised by a dozen tests (differential re-index, budget exhaustion, kill switch,
 * tenancy) — each of which would otherwise either hit OpenAI or have to mock a fluent package facade.
 * The contract is bound in {@see \App\Modules\Knowledge\KnowledgeModuleServiceProvider} and swapped
 * for {@see \App\Modules\Knowledge\Support\FakeKnowledgeEmbedder} in tests.
 *
 * BATCH-SHAPED on purpose. An entry is many passages, and embedding them one at a time would multiply
 * the round-trips (and the failure surface) by the fan-out for no benefit — providers price and accept
 * a whole batch. The implementation makes ONE provider call per invocation; the caller decides how
 * many texts that is (`knowledge.index.embed_batch`).
 *
 * NOT metered here. The {@see \App\Modules\Variables\Contracts\MeteredAiCall} bracket is applied by the
 * CALLER around this contract, one single layer, so the gate and the ledger cover the fake exactly as
 * they cover the real provider — which is what makes the budget behaviour testable at all. An
 * implementation that metered itself would double-count.
 */
interface KnowledgeEmbedder
{
    /**
     * Embed every text in ONE provider call.
     *
     * @param  array<int, string>  $texts  never empty (the caller skips an empty batch)
     * @return EmbeddingBatchResult vectors in input order, one per text
     */
    public function embed(array $texts): EmbeddingBatchResult;
}
