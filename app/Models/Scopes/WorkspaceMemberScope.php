<?php

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts a CENTRAL model (today: User) to the MEMBERS of the active workspace.
 *
 * This is the membership counterpart to {@see WorkspaceScope}. The two diverge on
 * one deliberate point: WorkspaceScope only isolates in SHARED db_mode (own-mode
 * data lives in a dedicated tenant connection, so no workspace_id filter is
 * needed). Membership is CENTRAL in BOTH db_modes — the `users`, `workspaces`, and
 * `workspace_user` tables always live in the landlord database — so this scope must
 * apply whenever ANY workspace is active, regardless of db_mode. Hence the guard is
 * `hasWorkspace()`, NOT `isShared()`.
 *
 * With no active workspace (login, invitation accept, queue jobs, console) the
 * query is left unconstrained: there is no membership to filter by, and the
 * auth/onboarding flows must be able to find users by email.
 */
class WorkspaceMemberScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        // Inert until a workspace is active. NOTE: hasWorkspace(), not isShared() —
        // membership is central in both db_modes (see class docblock).
        if (!$context->hasWorkspace()) {
            return;
        }

        $workspaceId = $context->id();
        $ownerId = $context->workspace()?->owner_id;

        // A user belongs to the active workspace when they have a `workspace_user`
        // pivot row for it OR they are the workspace's structural owner (the owner
        // has no pivot row guaranteed). Kept as a single where(closure) so it
        // composes with any other constraints already on the builder.
        $builder->where(function (Builder $query) use ($model, $workspaceId, $ownerId): void {
            $query
                ->whereIn(
                    $model->qualifyColumn('id'),
                    fn ($sub) => $sub
                        ->select('user_id')
                        ->from('workspace_user')
                        ->where('workspace_id', $workspaceId)
                )
                ->orWhereIn(
                    $model->qualifyColumn('id'),
                    fn ($sub) => $sub
                        ->select('owner_id')
                        ->from('workspaces')
                        ->where('id', $workspaceId)
                );

            // Defensive: if the owner id is already known from the active workspace,
            // include it directly so the owner is visible even before/without the
            // workspaces subquery resolving (e.g. soft-deleted rows).
            if ($ownerId !== null) {
                $query->orWhere($model->qualifyColumn('id'), $ownerId);
            }
        });
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutWorkspaceMemberScope', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });

        $builder->macro('forWorkspace', function (Builder $builder, string $workspaceId) {
            return $builder
                ->withoutGlobalScope($this)
                ->where(function (Builder $query) use ($builder, $workspaceId): void {
                    $model = $builder->getModel();

                    $query
                        ->whereIn(
                            $model->qualifyColumn('id'),
                            fn ($sub) => $sub
                                ->select('user_id')
                                ->from('workspace_user')
                                ->where('workspace_id', $workspaceId)
                        )
                        ->orWhereIn(
                            $model->qualifyColumn('id'),
                            fn ($sub) => $sub
                                ->select('owner_id')
                                ->from('workspaces')
                                ->where('id', $workspaceId)
                        );
                });
        });
    }
}
