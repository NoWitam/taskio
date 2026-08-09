<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * ONE passage the chunker cut out of an entry, before anything has been embedded or stored — the
 * value {@see \App\Modules\Knowledge\Services\KnowledgeChunker} produces and
 * {@see \App\Modules\Knowledge\Services\KnowledgeIndexService} turns into a
 * {@see \App\Modules\Knowledge\Models\KnowledgeEntryChunk} row.
 *
 * It is a DRAFT rather than a model because chunking must be answerable WITHOUT a database: a
 * FormRequest dry-runs the chunker to reject an entry that would blow the fan-out cap, and the whole
 * splitting algorithm is unit-tested as a pure function. Neither can afford to touch persistence.
 *
 * INVARIANT worth stating, because the differ and every offset test depend on it:
 * `mb_substr($content, $charStart, $charLength) === $this->content` for the entry content the draft
 * was cut from. The chunker only ever produces CONTIGUOUS slices of the original — it never
 * re-joins or rewrites text — so a citation can highlight the exact source span. Two drafts MAY
 * overlap (the hard-cut overlap window repeats the tail of its predecessor); that is why the spans
 * are start+length rather than a partition.
 *
 * `$digest` is sha256 over `headingPath \0 content` and is the whole basis of DIFFERENTIAL indexing:
 * a re-index re-embeds a draft only when no stored chunk already carries its digest. The heading
 * path is inside the hash because the same sentence under a different heading is a different
 * passage — it embeds differently (the path is part of the embedded text) and must not be reused.
 */
final class ChunkDraft
{
    public function __construct(
        public readonly int $ordinal,
        /** The heading trail, root-first, joined with " > " and rooted at the ENTRY TITLE. */
        public readonly string $headingPath,
        public readonly string $content,
        public readonly int $charStart,
        public readonly int $charLength,
        public readonly string $digest,
    ) {}
}
