<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflows\Services\WorkflowScheduleFamilyCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Discovery endpoint for the workflow schedule builder: the schedule-family vocabulary + each
 * family's param descriptors, so the FE renders correct controls and the AI-assist proposes a
 * valid params object. No policy object — any authenticated member may read the vocabulary
 * (it exposes no tenant data); the FE supplies its own i18n labels. Mirrors the bot tool
 * registry discovery endpoint.
 */
class WorkflowScheduleFamilyController extends Controller
{
    public function __construct(
        private WorkflowScheduleFamilyCatalog $catalog,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->all()]);
    }
}
