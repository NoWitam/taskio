<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * GET /forms/{form}/workflow-catalog — the TYPED variable catalog for a form_submitted workflow
 * built on {form}: the reference-able variables (trigger system vars + per-form field vars +
 * step outputs), the condition field descriptors, the global label-less operation catalog the
 * condition-pipeline builder consumes, and the label-less ai-text persona catalog (SB2). This is
 * the contract the workflow editor (B6/B7) and AI-assist (B5) consume.
 *
 * HOME: the WORKFLOWS module owns the "variable catalog" concept, so the route lives in this
 * module even though it binds a Form (cross-module model + policy references are already normal
 * here — the dispatch service reads Form too). Authorized by FormPolicy::view.
 *
 * TENANCY: the {form} implicit binding is scoped by the WorkspaceScope global scope while a
 * workspace is active (X-Workspace-Id header), so a form from another workspace 404s on binding.
 * FormPolicy::view then gates the authenticated user. This mirrors how the Forms module's own
 * endpoints authorize; the app-wide "unscoped when no workspace header" behavior is pre-existing
 * and intentionally not changed here.
 */
class WorkflowVariableCatalogController extends Controller
{
    public function __construct(
        private WorkflowVariableCatalogService $catalog,
    ) {}

    public function show(Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        $catalog = $this->catalog->forForm($form);

        return response()->json([
            'data' => [
                'variables' => array_map($this->shapeVariable(...), $catalog['variables']),
                'fields' => $catalog['fields'],
                'operations' => $catalog['operations'],
                'ai_personas' => $catalog['ai_personas'],
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
