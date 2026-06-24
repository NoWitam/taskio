<?php

namespace App\Modules\Changelog\Http\Controllers;

use App\Modules\Changelog\Http\Resources\ChangelogResource;
use App\Modules\Changelog\Interfaces\HasChangelog;
use App\Modules\Changelog\Services\ChangelogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChangelogController
{
    public function __construct(
        private ChangelogService $service
    ) {}

    public function index(string $module, string $id): AnonymousResourceCollection
    {
        $subject = $this->resolveSubject($module, $id);

        return ChangelogResource::collection(
            $subject->changelogs()
                // The causer is a frozen audit fact — keep it resolvable even if they
                // have since left the workspace (bypass WorkspaceMemberScope on User).
                ->with(['causer' => fn ($query) => $query->withoutWorkspaceMemberScope(), 'subject'])
                ->cursorPaginate(8)
        );
    }

    private function resolveSubject(string $module, string $id): Model
    {
        $class = Relation::getMorphedModel($module);

        if (is_null($class)) {
            abort(404);
        }

        $model = new $class;

        if (!$model instanceof HasChangelog) {
            abort(404);
        }

        return $model::withTrashed()->findOrFail($id);
    }
}
