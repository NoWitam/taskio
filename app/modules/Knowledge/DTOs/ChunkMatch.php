<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * ONE passage that a similarity query returned, with how close it was.
 *
 * It is a DTO rather than a hydrated {@see \App\Modules\Knowledge\Models\KnowledgeEntryChunk} on
 * purpose: a match is a projection — every column EXCEPT the vector, plus a score that exists only
 * relative to the query — and handing back a model would invite a caller to touch `embedding` (the
 * one thing that must never be hydrated) or to save a row that was never loaded whole.
 *
 * `$similarity` is COSINE SIMILARITY in [-1, 1], where 1 is identical direction — NOT the cosine
 * DISTANCE that pgvector's `<=>` operator returns. The conversion (`1 - distance`) happens in the
 * implementation, once, so nothing downstream has to remember which way round the number runs. Both
 * embedders in this module produce unit-length vectors, which is what makes the PHP fallback's dot
 * product the same quantity as Postgres' cosine.
 *
 * `(entryId, ordinal)` is the STABLE CITATION ADDRESS — the same pair a similarity edge records in
 * its `evidence`, and the same pair a search result reports so a reader can be sent to the passage
 * rather than to the top of a 40 000-character document. `charStart`/`charLength` index into the
 * ENTRY's content (not the chunk's), so `mb_substr($entry->content, $charStart, $charLength)` is
 * exactly `$content`.
 */
final class ChunkMatch
{
    public function __construct(
        public readonly string $chunkId,
        public readonly string $entryId,
        public readonly int $ordinal,
        public readonly ?string $headingPath,
        public readonly string $content,
        public readonly int $charStart,
        public readonly int $charLength,
        public readonly float $similarity,
    ) {}
}
