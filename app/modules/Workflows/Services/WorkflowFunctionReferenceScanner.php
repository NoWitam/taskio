<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Variables\Contracts\FunctionReferenceLookup;
use App\Modules\Variables\Services\FunctionDefinitionValidator;
use App\Modules\Workflows\Models\Workflow;

/**
 * The Workflows-side answer to "does any workflow still use this custom function?" — the delete guard the
 * Variables module consults through {@see FunctionReferenceLookup} (bound in the Workflows provider, so the
 * Variables module never names a Workflows class). It scans every LIVE, workspace-scoped workflow's step
 * configs + conditions for a `fn:<uuid>` op, reusing FunctionDefinitionValidator::referencedFunctionIds —
 * the SAME precise edge extraction the function cycle graph + the function-vs-function delete guard use, so
 * a substring false-match (a uuid that is a prefix of another) can never wrongly block or allow a delete.
 *
 * Live workflows only (WorkspaceScope + the SoftDeletes default scope apply): a TRASHED workflow does not
 * actively reference the function, and were it restored onto a since-deleted function the op simply
 * resolves null → fail closed, never a broken run.
 */
class WorkflowFunctionReferenceScanner implements FunctionReferenceLookup
{
    public function isReferencedByWorkflow(string $functionId): bool
    {
        return Workflow::query()
            ->get()
            ->contains(fn (Workflow $workflow): bool => in_array(
                $functionId,
                FunctionDefinitionValidator::referencedFunctionIds([
                    'steps' => $workflow->steps,
                    'conditions' => $workflow->conditions,
                ]),
                true,
            ));
    }
}
