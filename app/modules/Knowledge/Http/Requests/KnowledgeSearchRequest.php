<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Services\KnowledgeSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a SEARCH — for one base (`/knowledge/bases/{base}/search`) or across the workspace
 * (`/knowledge/search`). One request class because the two differ only in whether a base is bound.
 *
 * `q` is capped at 500 characters, and the cap is a SPEND control as much as a sanity one: the query
 * is embedded, so its length is billed, and nothing a human types into a search box approaches it.
 * A pasted document belongs in an entry, not in the search field.
 *
 * `limit` cannot exceed `knowledge.search.max_results`. A caller-supplied ceiling would otherwise turn
 * a bounded read into an unbounded one — the vector leg's cost is per query, but assembling and
 * serializing results is per result, and there is no pagination behind it to absorb a large number.
 */
class KnowledgeSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $base = $this->route('base');

        if ($base instanceof KnowledgeBase && !$this->user()?->can('view', $base)) {
            return false;
        }

        return $this->user()?->can('viewAny', KnowledgeEntry::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:500'],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::in(KnowledgeEntryStatus::ids())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . (int) config('knowledge.search.max_results')],
        ];
    }

    /**
     * The statuses this search covers. Null hands the decision to the service, which shows everything
     * but `archived` — see {@see KnowledgeSearchService} for why that is the right default and why
     * archived stays reachable by asking for it explicitly.
     *
     * @return array<int, string>|null
     */
    public function statuses(): ?array
    {
        if (!$this->has('status')) {
            return null;
        }

        $statuses = array_values(array_filter(
            (array) $this->input('status', []),
            static fn ($status): bool => in_array($status, KnowledgeEntryStatus::ids(), true),
        ));

        return $statuses === [] ? null : $statuses;
    }

    public function limit(): ?int
    {
        return $this->filled('limit') ? (int) $this->input('limit') : null;
    }

    public function term(): string
    {
        return (string) $this->input('q', '');
    }
}
