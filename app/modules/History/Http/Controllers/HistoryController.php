<?php

namespace App\Modules\History\Http\Controllers;

use App\Modules\History\Http\Resources\ActivityResource;
use App\Modules\History\Interfaces\HasHistory;
use App\Modules\History\Services\ActivityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HistoryController
{
    public function __construct(
        private ActivityService $service
    ) {}

    public function index(string $module, string $id): AnonymousResourceCollection
    {
        $subject = $this->resolveSubject($module, $id);

        return ActivityResource::collection(
            $subject->activities()->with('causer')->cursorPaginate(8)
        );
    }

    private function resolveSubject(string $module, string $id): Model
    {
        $class = Relation::getMorphedModel($module);

        if(is_null($class)) {
            abort(404);
        }

        $model = new $class();

        if(!$model instanceof HasHistory) {
            abort(404);
        }

        return $model::withTrashed()->findOrFail($id);
    }
}
