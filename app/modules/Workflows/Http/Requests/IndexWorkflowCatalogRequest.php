<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates + authorizes a READ of the FORM-INDEPENDENT variable catalog
 * (GET /workflows/catalog).
 *
 * INPUT:
 *   trigger_type  required WITHOUT a form_id — a form-less catalog must name its trigger so the
 *                 trigger-system vars can be chosen; one of WorkflowTriggerType. Ignored when a
 *                 form_id is present (a form implies FORM_SUBMITTED, matching forForm).
 *   form_id       OPTIONAL workspace-scoped Form uuid; when present the catalog layers in that
 *                 form's field vars.
 *
 * AUTHORIZATION mirrors the other Workflows read-endpoints (IndexWorkflowRunsRequest — route
 * model → view, else viewAny):
 *   - with a form_id → resolve it under WorkspaceScope and delegate to FormPolicy::view (exactly
 *     as the form-bound route does). A foreign / missing form 404s (mirrors the {form} binding).
 *   - without one → WorkflowPolicy::viewAny (workspace-member read). The form-less response is
 *     STRUCTURAL metadata only (no tenant rows), so membership — enforced UPSTREAM by
 *     ResolveWorkspace when a workspace header is present — is the only gate needed; no new policy.
 */
class IndexWorkflowCatalogRequest extends FormRequest
{
    private ?Form $resolvedForm = null;

    private bool $formResolved = false;

    public function authorize(): bool
    {
        $form = $this->catalogForm();

        if ($form instanceof Form) {
            return $this->user()->can('view', $form);
        }

        return $this->user()->can('viewAny', Workflow::class);
    }

    public function rules(): array
    {
        return [
            'trigger_type' => ['required_without:form_id', 'nullable', Rule::in(WorkflowTriggerType::ids())],
            'form_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * The workspace-scoped Form named by `form_id` (memoized), or null when none was requested —
     * or the value is not a uuid, which the `form_id` rule 422s. A well-formed but unresolvable
     * (foreign / missing) form 404s, mirroring the {form} route-model binding on the form-bound
     * catalog route. The uuid guard also keeps a malformed value off the native-uuid column.
     */
    public function catalogForm(): ?Form
    {
        if ($this->formResolved) {
            return $this->resolvedForm;
        }

        $this->formResolved = true;
        $formId = $this->input('form_id');

        if (!is_string($formId) || !Str::isUuid($formId)) {
            return $this->resolvedForm = null;
        }

        // Form::find applies WorkspaceScope, so a form outside the active workspace resolves to
        // null → 404 (as the {form} binding would).
        $form = Form::find($formId);

        if ($form === null) {
            throw (new ModelNotFoundException)->setModel(Form::class, [$formId]);
        }

        return $this->resolvedForm = $form;
    }

    /**
     * The effective trigger type for the catalog: a form-bound call is always FORM_SUBMITTED (a
     * form implies its submission trigger — matching forForm); a form-less call uses the validated
     * trigger_type (null only when validation already rejected the request).
     */
    public function catalogTriggerType(): ?WorkflowTriggerType
    {
        if ($this->catalogForm() instanceof Form) {
            return WorkflowTriggerType::FORM_SUBMITTED;
        }

        return WorkflowTriggerType::tryFrom((string) $this->input('trigger_type'));
    }
}
