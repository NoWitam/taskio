<?php

namespace App\Traits;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a model workspace-aware. In shared-database mode the active workspace is
 * applied as a global scope and stamped onto new rows. In own-database mode the
 * data lives in a dedicated connection without a workspace_id column; that
 * connection switching is handled in Faza 2.
 */
trait TenantAware
{
    public static function bootTenantAware(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->isShared() && $model->getAttribute(WorkspaceScope::COLUMN) === null) {
                $model->setAttribute(WorkspaceScope::COLUMN, $context->id());
            }
        });
    }

    /**
     * Route queries to the dedicated tenant connection while an own-database
     * workspace is active; otherwise use the model's default connection.
     */
    public function getConnectionName()
    {
        if (app(TenantContext::class)->isOwn()) {
            return TenantManager::CONNECTION;
        }

        return $this->connection;
    }
}
