<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflows\Http\Resources\WorkflowRunResource;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only monitoring endpoints for a workflow's RUNS (Batch 4), consumed by the Runs
 * view. Separate from WorkflowController for the same reason Bot splits BotActionController
 * off BotController: run monitoring is its own read concern.
 *
 * Authorization: viewing runs is `view` on the parent workflow — runs are workspace-visible
 * read-only monitoring, so any member who can see the workflow can see its runs. Both the
 * workflow and the run are resolved through WorkspaceScope, so a workflow or run from
 * another workspace never binds (404 before the policy even runs).
 */
class WorkflowRunController extends Controller
{
    public function __construct(
        private WorkflowRunService $service,
    ) {}

    /** GET /api/workflows/{workflow}/runs?state=&origin=&cursor= */
    public function index(Request $request, Workflow $workflow): AnonymousResourceCollection
    {
        $this->authorize('view', $workflow);

        return WorkflowRunResource::collection(
            $this->service->runsFor($workflow, $request)
        );
    }

    /** GET /api/workflows/{workflow}/runs/{run} */
    public function show(Workflow $workflow, WorkflowRun $run): WorkflowRunResource
    {
        $this->authorize('view', $workflow);

        // Nested-ownership guard: {run} is bound independently, so a run belonging to a
        // DIFFERENT workflow (but the same workspace) would otherwise leak through this
        // workflow's URL. A mismatch is a 404 — the run does not exist under this parent.
        if ($run->workflow_id !== $workflow->id) {
            throw new NotFoundHttpException;
        }

        return WorkflowRunResource::make(
            $this->service->showRun($run)
        );
    }
}
