<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\KnowledgeSnippet;

/**
 * ONE search result: the ENTRY that matched, plus why.
 *
 * A result is an entry and never a chunk, which is the single most consequential decision in the
 * search design. Chunks are an implementation detail of retrieval — a reader asked "what do we say
 * about returns", and answering with four passages of the same document is answering a question
 * nobody asked. So passages are grouped back into the document they came from, the BEST of them is
 * carried along as the citation ({@see $chunk}), and how many others also matched is reported
 * ({@see $matchedChunks}) because "this document matched in six places" is a relevance signal a human
 * reads instantly.
 *
 * `$chunk` is null when the entry was found by the LEXICAL leg alone — a brand-new entry that the
 * indexer has not reached yet, or one whose words match while its meaning does not. That is a normal,
 * expected result rather than a degraded one: the whole point of running a keyword leg beside the
 * vector leg is that something written thirty seconds ago is findable.
 *
 * `$snippet` is present whenever the entry has a body at all, chunk or no chunk, so a client always
 * has one shape to render.
 */
final class KnowledgeSearchHit
{
    public function __construct(
        public readonly KnowledgeEntry $entry,
        public readonly ?KnowledgeBase $base,
        public readonly ?ChunkMatch $chunk,
        public readonly ?KnowledgeSnippet $snippet,
        /** How many of this entry's passages were inside the vector leg's top-K. */
        public readonly int $matchedChunks,
        /** The fused reciprocal-rank score both legs contributed to. Comparable only within one response. */
        public readonly float $rrfScore,
    ) {}
}
