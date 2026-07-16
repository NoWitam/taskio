<?php

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceScope implements Scope
{
    const COLUMN = 'workspace_id';

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        // Isolate only when a shared-database workspace is active. With no active
        // workspace (queue jobs, console, login) the query is left unconstrained.
        if ($context->isShared()) {
            $builder->where($model->qualifyColumn(self::COLUMN), $context->id());
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutWorkspaceScope', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });

        $builder->macro('forWorkspace', function (Builder $builder, string $workspaceId) {
            return $builder
                ->withoutGlobalScope($this)
                ->where($builder->getModel()->qualifyColumn(self::COLUMN), $workspaceId);
        });
    }
}
