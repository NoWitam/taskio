<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Workflows\Models\Workflow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates + authorizes a READ of workflow runs — shared by the per-workflow index
 * (GET workflows/{workflow}/runs) and the global feed (GET workflows/runs).
 *
 * Authorization mirrors the read policy: with a {workflow} bound (per-workflow index) it
 * delegates to WorkflowPolicy::view; without one (global feed) to WorkflowPolicy::viewAny.
 * Both are workspace-member reads — cross-workspace isolation is enforced UPSTREAM by
 * ResolveWorkspace (a non-member 403s at the middleware) + WorkspaceScope (a foreign
 * workflow/run never binds/appears), so nothing here has to re-check the workspace.
 *
 * FILTERS (all optional, all AND-combined):
 *   state[]        subset of WorkflowRunState values     (whereIn)
 *   origin[]       subset of WorkflowRunOrigin values    (whereIn)
 *   trigger_type[] subset of WorkflowTriggerType values  (whereIn)
 *   workflow_id[]  subset of workflow uuids (whereIn) — honored ONLY by the global feed; the
 *                  per-workflow index is already scoped to its {workflow} and ignores it. A
 *                  single scalar `?workflow_id=` is coerced to a one-element array (legacy)
 *   date_from / date_to   date range on created_at (shared scopeFilterByDate — the app-wide
 *                         date-filter param names every next list screen already emits)
 *   date_preset today|this_week|last_week|this_month  (shortcut inherited from the scope)
 *   cursor         opaque cursor-pagination token
 *
 * LEGACY DEEP-LINKS: a single scalar `?state=` / `?origin=` / `?trigger_type=` is coerced
 * to a one-element array in prepareForValidation, so old single-value links keep working.
 *
 * TOLERANT ENUM FILTER (deliberate, preserved from the original runs index): an unknown
 * enum MEMBER is NOT a 422 here — only the array shape + element scalarity are validated.
 * WorkflowRunService drops unrecognised members at query time (tryFrom), so `?state=bogus`
 * is a no-op filter, mirroring the Bot inbox's "silently ignore an unknown filter" rule.
 */
class IndexWorkflowRunsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workflow = $this->route('workflow');

        if ($workflow instanceof Workflow) {
            return $this->user()->can('view', $workflow);
        }

        return $this->user()->can('viewAny', Workflow::class);
    }

    /**
     * Coerce a single scalar state/origin/trigger_type into a one-element array so a legacy
     * single-value deep-link (?state=completed) satisfies the array rules below.
     */
    protected function prepareForValidation(): void
    {
        foreach (['state', 'origin', 'trigger_type', 'workflow_id'] as $key) {
            $value = $this->input($key);

            if ($value !== null && !is_array($value)) {
                $this->merge([$key => [$value]]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // Enum MEMBERSHIP is intentionally not enforced (tolerant filter — see docblock);
            // only the array SHAPE and element scalarity are validated.
            'state' => ['nullable', 'array'],
            'state.*' => ['string'],
            'origin' => ['nullable', 'array'],
            'origin.*' => ['string'],
            'trigger_type' => ['nullable', 'array'],
            'trigger_type.*' => ['string'],

            'workflow_id' => ['nullable', 'array'],
            'workflow_id.*' => ['uuid'],

            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'date_preset' => ['nullable', 'in:today,this_week,last_week,this_month'],

            'cursor' => ['nullable', 'string'],
        ];
    }
}
