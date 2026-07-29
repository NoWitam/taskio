<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The workspace AI-usage SUMMARY wire shape (R2 sub-stage 4). $-first: `cost_*` are the primary figures,
 * `tokens_used` is a secondary display. Every number is an ESTIMATE (`estimated: true`).
 *
 * SECURITY: this exposes ONLY aggregates + READ-resolved actor display names — NEVER `meta`, a prompt, a
 * slot value, or any raw token-of-content. The underlying {@see \App\Modules\Variables\Services\AiUsageService}
 * summary is already curated; the fields are enumerated here explicitly so nothing sensitive can leak in.
 *
 * `can_manage` is the OWNER-only capability flag (mirrors WorkspaceResource's flags) so the UI can gate the
 * cap editor; the real authorization is enforced server-side by UpdateAiBudgetRequest's Policy check.
 */
class AiUsageSummaryResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $summary  the AiUsageService::summary() aggregates
     */
    public function __construct(array $summary, private Workspace $workspace)
    {
        parent::__construct($summary);
    }

    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'currency' => $summary['currency'],
            'estimated' => $summary['estimated'],
            'cost_used' => $summary['cost_used'],
            'cost_cap' => $summary['cost_cap'],
            'cost_remaining' => $summary['cost_remaining'],
            'cap_source' => $summary['cap_source'],
            'warn_ratio' => $summary['warn_ratio'],
            'warn_reached' => $summary['warn_reached'],
            'blocked' => $summary['blocked'],
            'period' => $summary['period'],
            'tokens_used' => $summary['tokens_used'],
            'per_channel' => $summary['per_channel'],
            'per_actor' => $summary['per_actor'],
            'can_manage' => $request->user()?->can('manageAiBudget', $this->workspace) ?? false,
        ];
    }
}
