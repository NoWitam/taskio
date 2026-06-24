<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Workspace-safe replacement for the global `exists:` rule.
 *
 * The stock `exists:` validator queries the table through the DB presence verifier,
 * which BYPASSES Eloquent global scopes — so a request can reference any row in any
 * workspace, leaking cross-tenant data on the WRITE side. This rule instead runs the
 * existence check through the model's Eloquent builder, so every global scope applies:
 *
 *   - {@see \App\Models\User} carries {@see \App\Models\Scopes\WorkspaceMemberScope},
 *     so a User reference only resolves for MEMBERS of the active workspace.
 *   - TenantAware models (Form, ApprovalPipeline, …) carry
 *     {@see \App\Models\Scopes\WorkspaceScope}; in shared db_mode they are filtered by
 *     `workspace_id`, and in own db_mode {@see \App\Traits\TenantAware::getConnectionName}
 *     routes the query to the active tenant's dedicated connection.
 *
 * One rule therefore secures BOTH membership and tenant-entity references in BOTH
 * db_modes, with no per-table branching: the model's own scope already does the work.
 *
 * @template TModel of Model
 */
class ScopedExists implements ValidationRule
{
    /**
     * @param  class-string<TModel>  $modelClass  Eloquent model whose global scopes gate the lookup.
     * @param  string  $column  Column to match the value against (defaults to the key, `id`).
     */
    public function __construct(
        private string $modelClass,
        private string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Run via the Eloquent query builder (NOT the DB presence verifier) so the
        // model's global scopes constrain the lookup to the active workspace.
        $exists = $this->modelClass::query()
            ->where($this->column, $value)
            ->exists();

        if (!$exists) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
