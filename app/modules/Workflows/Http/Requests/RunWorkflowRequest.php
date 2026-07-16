<?php

namespace App\Modules\Workflows\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes + validates a MANUAL workflow run. Authorization delegates to
 * WorkflowPolicy::run (any workspace member may run — a manual run works on ANY workflow,
 * including an INACTIVE one, for test-before-activate).
 *
 * `target_id` is a loose uuid here; per-trigger-type resolution (FormSubmission |
 * none for schedule) and the "required for entity triggers" 422 live in the controller,
 * which loads the target through the TENANT-SCOPED model so a raw/cross-workspace id can
 * never be trusted. Validating it as a bare uuid only rejects obviously malformed input
 * early; a well-formed but unresolvable id is a controller-level 422/404.
 */
class RunWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('run', $this->route('workflow'));
    }

    public function rules(): array
    {
        return [
            'target_id' => ['nullable', 'uuid'],
        ];
    }
}
