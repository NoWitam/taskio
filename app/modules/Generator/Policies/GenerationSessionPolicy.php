<?php

namespace App\Modules\Generator\Policies;

use App\Models\User;
use App\Modules\Generator\Models\GenerationSession;
use App\Policies\Concerns\ChecksRecordOwnership;

/**
 * Authorization for generation SESSIONS — mirrors {@see TemplatePolicy}. Workspace membership is enforced
 * upstream by ResolveWorkspace + WorkspaceScope (a request only ever reaches a session in the active
 * workspace, and a non-member cannot resolve the workspace at all), so these checks gate READ on
 * membership (any member) and MUTATION — update (which the generate action also authorizes) + delete — on
 * ownership (the creator), via {@see ChecksRecordOwnership}. A session created by a workflow_run/bot is a
 * system record with no human owner, so it falls back to the workspace owner there.
 */
class GenerationSessionPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, GenerationSession $session): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, GenerationSession $session): bool
    {
        return $this->ownsOrManagesSystemRecord($session, $user);
    }

    public function delete(?User $user, GenerationSession $session): bool
    {
        return $this->ownsOrManagesSystemRecord($session, $user);
    }
}
