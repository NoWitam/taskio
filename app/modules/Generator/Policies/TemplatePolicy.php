<?php

namespace App\Modules\Generator\Policies;

use App\Models\User;
use App\Modules\Generator\Models\Template;
use App\Policies\Concerns\ChecksRecordOwnership;

/**
 * Workspace membership is enforced upstream by ResolveWorkspace + WorkspaceScope: a request only ever
 * reaches a template that belongs to the active workspace, and a non-member cannot resolve the
 * workspace at all. These checks therefore gate READ on membership (any member) and MUTATION on
 * ownership (the creator) — mirrors ConstantPolicy / CustomFunctionPolicy. A template is always
 * user-created, so the system-record fallback in ChecksRecordOwnership never applies.
 */
class TemplatePolicy
{
    use ChecksRecordOwnership;

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Template $template): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Template $template): bool
    {
        return $this->ownsOrManagesSystemRecord($template, $user);
    }

    public function delete(?User $user, Template $template): bool
    {
        return $this->ownsOrManagesSystemRecord($template, $user);
    }
}
