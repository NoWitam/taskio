<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflows\Http\Requests\IndexWorkflowRunsRequest;
use App\Modules\Workflows\Http\Requests\RetryWorkflowRunRequest;
use App\Modules\Workflows\Http\Resources\WorkflowRunResource;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowManualRunService;
use App\Modules\Workflows\Services\WorkflowRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only monitoring endpoints for a workflow's RUNS (Batch 4), consumed by the Runs
 * view. Separate from WorkflowController for the same reason Bot splits BotActionController
 * off BotController: run monitoring is its own read concern.
 *
 * Authorization: viewing runs is `view` on the parent workflow — runs are workspace-visible
 * read-only monitoring, so any member who can see the workflow can see its runs.
 *
 * TENANCY: route-model binding is now workspace-scoped — ResolveWorkspace runs BEFORE
 * SubstituteBindings (bootstrap/app.php priority reorder), so a {workflow}/{run} from
 * another workspace 404s at bind. The nested-ownership `$run->workflow_id === $workflow->id`
 * check in show() is a SEPARATE concern (a same-workspace run of a DIFFERENT workflow
 * leaking through this nested URL) and MUST stay — the workspace reorder does not cover it.
 */
class WorkflowRunController extends Controller
{
    public function __construct(
        private WorkflowRunService $service,
    ) {}

    /**
     * GET /api/workflows/{workflow}/runs — one workflow's runs.
     * Filters + authorization (view on {workflow}) live in IndexWorkflowRunsRequest.
     */
    public function index(IndexWorkflowRunsRequest $request, Workflow $workflow): AnonymousResourceCollection
    {
        return WorkflowRunResource::collection(
            $this->service->runsFor($workflow, $request)
        );
    }

    /**
     * GET /api/workflows/runs — the GLOBAL runs feed across every workflow in the active
     * workspace. Authorization (viewAny on Workflow) + the shared filters (plus an optional
     * `workflow_id`) live in IndexWorkflowRunsRequest; WorkspaceScope isolates the rows.
     */
    public function global(IndexWorkflowRunsRequest $request): AnonymousResourceCollection
    {
        return WorkflowRunResource::collection(
            $this->service->runsGlobal($request)
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

    /**
     * POST /api/workflows/{workflow}/runs/{run}/retry — re-run a FAILED run with the SAME
     * trigger context. The engine has no mid-run resume, so a "retry" STARTS A NEW run reusing
     * the failed run's stored trigger_payload. Authorization (run on {workflow}) + the
     * nested-ownership 404 live in RetryWorkflowRunRequest; the terminal-FAILED guard (422) and
     * the shared run-budget cap (422) live in WorkflowManualRunService::retry. The new run is a
     * MANUAL run attributed to the acting user. Returns 202 Accepted (matching run-now) with the
     * NEW run — the run is PENDING and its job is deferred to afterCommit, so nothing is "created
     * and ready" yet; the FE opens/refreshes it by id.
     */
    public function retry(RetryWorkflowRunRequest $request, Workflow $workflow, WorkflowRun $run, WorkflowManualRunService $manualRuns): JsonResponse
    {
        $newRun = $manualRuns->retry($run, $request->user()->id);

        return WorkflowRunResource::make($newRun)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
