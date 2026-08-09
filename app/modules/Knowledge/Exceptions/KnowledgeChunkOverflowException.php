<?php

namespace App\Modules\Knowledge\Exceptions;

use RuntimeException;

/**
 * An entry that would split into more passages than `knowledge.chunking.max_chunks_per_entry` allows.
 *
 * That cap is the per-entry SPEND bound — the one guarantee that no single document, however it is
 * written, can cost an unbounded amount to index. The character cap alone does not imply it: 40 000
 * characters written as forty 500-character sections under forty headings produces forty-plus chunks
 * that nothing merges (each is above `min_chars`), so the two limits genuinely constrain different
 * things.
 *
 * Thrown rather than silently truncated, because truncating would produce an entry that LOOKS indexed
 * while its tail is unretrievable — the failure mode a knowledge base can least afford, since nobody
 * can tell from a search result that something was missing. The write path catches this early: the
 * entry FormRequest dry-runs the chunker and returns a 422 telling the writer to split the entry. The
 * indexing job is the second line — it can still be reached by an entry that predates a config change
 * — and marks the entry `failed` with this reason.
 */
class KnowledgeChunkOverflowException extends RuntimeException
{
    public function __construct(
        public readonly int $count,
        public readonly int $max,
    ) {
        parent::__construct("Knowledge entry splits into {$count} chunks, above the per-entry cap of {$max}.");
    }
}
