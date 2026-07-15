<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Authorizes + guards a RETRY of a failed workflow run
 * (POST /api/workflows/{workflow}/runs/{run}/retry).
 *
 * Authorization mirrors the manual-run path exactly: it delegates to WorkflowPolicy::run
 * (any workspace member may run — a retry is a user-initiated re-execution, like run-now).
 *
 * Two isolation guards, both a 404 (the run "does not exist" under this parent):
 *   - CROSS-TENANT: route-model binding is now workspace-scoped app-wide (ResolveWorkspace runs
 *     BEFORE SubstituteBindings — bootstrap/app.php priority reorder), so a {workflow}/{run} from
 *     another workspace already 404s at bind. The scoped ->exists() re-check below is kept as
 *     cheap belt-and-suspenders — the only in-code defense should the middleware priority ever
 *     regress — NOT because binding is unscoped.
 *   - FOREIGN-WORKFLOW: {run} is bound independently of {workflow}, so a run of a DIFFERENT
 *     workflow in the SAME workspace would otherwise leak through this URL. The nested-ownership
 *     check here 404s it — same guard WorkflowRunController@show applies inline.
 *
 * The terminal-FAILED-state guard (only a failed run may be retried) is a business rule and
 * lives in WorkflowManualRunService::retry as a 422, next to the run-budget cap it shares
 * with run-now.
 */
class RetryWorkflowRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workflow = $this->route('workflow');
        $run = $this->route('run');

        // Nested-ownership 404: a run that is absent or belongs to another workflow does not
        // exist under this parent. Thrown as a 404 (not a 403) to match WorkflowRunController@show.
        if (!$workflow instanceof Workflow || !$run instanceof WorkflowRun || $run->workflow_id !== $workflow->id) {
            throw new NotFoundHttpException;
        }

        // Cross-tenant 404 (belt-and-suspenders): binding is now workspace-scoped, so a foreign
        // run already 404s before this point (see the class docblock). Re-resolve through the
        // active WorkspaceScope anyway — the sole in-code defense should the middleware priority
        // ever regress. A run outside the active workspace does not exist here.
        if (!WorkflowRun::query()->whereKey($run->getKey())->exists()) {
            throw new NotFoundHttpException;
        }

        return $this->user()->can('run', $workflow);
    }

    public function rules(): array
    {
        return [];
    }
}
