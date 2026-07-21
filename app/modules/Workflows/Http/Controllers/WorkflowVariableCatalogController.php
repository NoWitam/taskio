<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Http\Requests\IndexWorkflowCatalogRequest;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * The TYPED variable catalog the workflow editor (B6/B7) and AI-assist (B5) consume: the
 * reference-able variables (trigger system vars + per-form field vars + step outputs), the
 * condition field descriptors, the label-less operation catalog + ai-text persona catalog (SB2),
 * and the variable-type list. Two routes expose it:
 *
 *   - GET /forms/{form}/workflow-catalog  (show)  — the form-bound catalog for a form_submitted
 *     workflow built on {form}. BACK-COMPAT: kept exactly as before.
 *   - GET /workflows/catalog              (index) — the FORM-INDEPENDENT catalog: STRUCTURAL
 *     metadata for a `trigger_type`, with an OPTIONAL `form_id` that layers in that form's field
 *     vars. Lets a form-less workflow (e.g. a schedule trigger) get a real catalog.
 *
 * HOME: the WORKFLOWS module owns the "variable catalog" concept, so both routes live here even
 * though the form-bound one binds a Form (cross-module model + policy references are already
 * normal here — the dispatch service reads Form too).
 *
 * TENANCY / AUTH: the {form} implicit binding is scoped by the WorkspaceScope global scope while a
 * workspace is active (X-Workspace-Id header), so a form from another workspace 404s on binding;
 * FormPolicy::view then gates the authenticated user. The form-independent route resolves/authorizes
 * in IndexWorkflowCatalogRequest (FormPolicy::view when a form_id is named, else workspace-member
 * viewAny on Workflow, since a form-less response carries NO tenant rows). The app-wide "unscoped
 * when no workspace header" behavior is pre-existing and intentionally not changed here.
 */
class WorkflowVariableCatalogController extends Controller
{
    public function __construct(
        private WorkflowVariableCatalogService $catalog,
    ) {}

    /** GET /forms/{form}/workflow-catalog — the form-bound catalog (back-compat). */
    public function show(Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        return $this->respond($this->catalog->forForm($form));
    }

    /**
     * GET /workflows/catalog — the FORM-INDEPENDENT catalog. `trigger_type` (required without a
     * form) selects the trigger-system vars; an OPTIONAL `form_id` layers in that form's field
     * vars. Validation + authorization live in IndexWorkflowCatalogRequest.
     */
    public function index(IndexWorkflowCatalogRequest $request): JsonResponse
    {
        return $this->respond($this->catalog->forContext(
            $request->catalogTriggerType(),
            $request->catalogForm(),
        ));
    }

    /**
     * Serialize a built catalog into the response envelope, stripping each variable's internal
     * field_id so the public variable shape stays exactly {source, path, name, type, enumOptions?,
     * nullable?}. Shared by both routes so their response shape can never drift.
     *
     * @param  array{variables: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>, operations: array<int, array<string, mixed>>, ai_personas: array<int, array{id: string}>, types: array<int, array<string, mixed>>}  $catalog
     */
    private function respond(array $catalog): JsonResponse
    {
        return response()->json([
            'data' => [
                'variables' => array_map($this->shapeVariable(...), $catalog['variables']),
                'fields' => $catalog['fields'],
                'operations' => $catalog['operations'],
                'ai_personas' => $catalog['ai_personas'],
                'types' => $catalog['types'],
            ],
        ]);
    }

    /**
     * Strip the internal `field_id` key from a variable so the public variable shape is exactly
     * `{source, path, name, type, enumOptions?, nullable?}` (field_id lives only on the `fields`
     * descriptors).
     *
     * @param  array<string, mixed>  $variable
     * @return array<string, mixed>
     */
    private function shapeVariable(array $variable): array
    {
        unset($variable['field_id']);

        return $variable;
    }
}
