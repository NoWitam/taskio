<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Modules\Knowledge\DTOs\KnowledgeSearchHit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE search result: the entry, exactly as a list would show it, plus WHY it is here.
 *
 * The entry half is delegated to {@see KnowledgeEntryListResource} rather than restated. A result row
 * and a list row are the same object in the same product, and two resources describing an entry
 * differently is how a status badge ends up rendering in one screen and not the other. The delegation
 * is composition rather than inheritance because the underlying resource is a
 * {@see \App\Modules\Knowledge\DTOs\KnowledgeSearchHit}, not a model — the match data has no business
 * being smuggled onto the entry as fake attributes.
 *
 * ------------------------------------------------------------------------------------------------
 * `matched_chunk` — the citation, and the ONLY place highlighting is described.
 *
 * Every position in it is a CHARACTER OFFSET into the ENTRY'S OWN `content`, never markup:
 *
 *     mb_substr(entry.content, matched_chunk.char_start, matched_chunk.char_length) === snippet
 *
 * and each `highlights` pair `[start, length]` is an absolute sub-range of that window. The server
 * returning `<mark>` instead would put its own markup inside user-authored text, which the client then
 * has to sanitize without being able to tell the two apart — a security seam created purely for a
 * rendering convenience. Offsets also survive a client that is not HTML: a bot assembling a prompt, an
 * export, a plain-text preview.
 *
 * `ordinal`, `heading_path` and `score` are null when the entry was found by the KEYWORD leg alone —
 * a brand-new entry the indexer has not reached, or one whose words match while its meaning does not.
 * The snippet is then a window on the body itself rather than on an indexed passage. That is a normal
 * result, not a degraded one, and it is why the shape stays the same: a client renders one row.
 *
 * `truncated_before` / `truncated_after` say whether the window has text on either side of it, so an
 * ellipsis can be drawn where there really is more and nowhere else. They exist because the snippet is
 * a verbatim slice — putting the "…" INTO the string would break the offset invariant above.
 *
 * `matched_chunks_count` is how many of this entry's passages were in the vector leg's top-K. "This
 * document matched in six places" is a relevance signal a human reads instantly, and it is zero for a
 * keyword-only hit.
 *
 * `rrf_score` is comparable only WITHIN one response. It is a fused rank score, not a percentage and
 * not a similarity — do not render it as one. For a "match: 87%" affordance use `matched_chunk.score`,
 * which is a real cosine similarity in [0,1].
 */
class KnowledgeSearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var KnowledgeSearchHit $hit */
        $hit = $this->resource;

        return KnowledgeEntryListResource::make($hit->entry)->toArray($request) + [
            // Always present, on both the base-scoped and the global endpoint, so a result row that
            // may come from any base never has to ask where it belongs.
            'base' => $hit->base === null ? null : [
                'id' => $hit->base->id,
                'name' => $hit->base->name,
            ],

            'matched_chunk' => $hit->snippet === null ? null : [
                'ordinal' => $hit->chunk?->ordinal,
                'heading_path' => $hit->chunk?->headingPath,
                'score' => $hit->chunk === null ? null : round($hit->chunk->similarity, 4),

                'snippet' => $hit->snippet->text,
                'char_start' => $hit->snippet->charStart,
                'char_length' => $hit->snippet->charLength,
                'highlights' => $hit->snippet->highlights,
                'truncated_before' => $hit->snippet->truncatedBefore,
                'truncated_after' => $hit->snippet->truncatedAfter,
            ],

            'matched_chunks_count' => $hit->matchedChunks,
            'rrf_score' => round($hit->rrfScore, 6),
        ];
    }
}
