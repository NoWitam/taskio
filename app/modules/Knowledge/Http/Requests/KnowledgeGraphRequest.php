<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\DTOs\KnowledgeGraphQuery;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a GRAPH read.
 *
 * `depth` is hard-capped at {@see KnowledgeGraphQuery::MAX_DEPTH} here AND clamped again in the DTO.
 * Twice on purpose: this is the only read in the module whose WORK is chosen by the caller (each
 * level is another pair of queries over a fan-out that grows with the base's connectivity), so the
 * bound is stated where a reader of the endpoint sees it and enforced where the traversal happens.
 *
 * `sources` and `min_score` are validated leniently and normalized in the DTO, which filters to the
 * known kinds and falls back to the defaults. A graph read is a VIEW: a client asking for a filter
 * that does not exist should get the default picture, not a 422 in the middle of a canvas.
 */
class KnowledgeGraphRequest extends FormRequest
{
    public function authorize(): bool
    {
        $base = $this->route('base');

        return $base instanceof KnowledgeBase && ($this->user()?->can('view', $base) ?? false);
    }

    public function rules(): array
    {
        return [
            // The ego-graph centre. Its membership of THIS base is checked at resolution, so a
            // foreign or wrong-base id is a 404 rather than a graph of nothing.
            'entry' => ['sometimes', 'uuid'],
            'depth' => ['sometimes', 'integer', 'min:1', 'max:' . KnowledgeGraphQuery::MAX_DEPTH],
            'sources' => ['sometimes'],
            'sources.*' => [Rule::in(KnowledgeLinkSource::ids())],
            // Cosine similarity, so the meaningful range is [-1, 1] whatever a caller believes.
            'min_score' => ['sometimes', 'numeric', 'min:-1', 'max:1'],
            'include_dismissed' => ['sometimes', 'boolean'],
            // Typed relations are ON unless explicitly switched off — see the DTO for why that default
            // runs the opposite way from `manual` links.
            'relations' => ['sometimes', 'boolean'],
            // Ended and retracted relations. Off by default: the graph answers "what is true now".
            'include_historical' => ['sometimes', 'boolean'],
        ];
    }

    public function graphQuery(): KnowledgeGraphQuery
    {
        return KnowledgeGraphQuery::fromRequest($this);
    }
}
