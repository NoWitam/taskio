<?php

namespace App\Modules\Variables\Contracts;

/**
 * Asks a HIGHER layer (Workflows) whether anything OUTSIDE the Variables module still references a custom
 * function — today, whether any WORKFLOW's step configs or conditions carry a `fn:<uuid>` op. The delete
 * guard ({@see \App\Modules\Variables\Services\CustomFunctionService::delete}) consults it so a function
 * cannot be deleted out from under a workflow that depends on it (fail-closed, 422), on top of the 3a
 * guard against another FUNCTION referencing it.
 *
 * Inverted through this contract so the boundary stays one-way: Variables owns the interface, Workflows
 * implements it and binds it (the side that owns the Workflow model), exactly as {@see ElementScopeResolver}
 * is bound. So Variables never names a Workflows class. When no implementation is bound (Variables used
 * without Workflows) the guard degrades to the function-only 3a check.
 */
interface FunctionReferenceLookup
{
    /** Whether ANY workflow in the active workspace references the function with $functionId (its bare uuid). */
    public function isReferencedByWorkflow(string $functionId): bool;
}
