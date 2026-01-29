<?php

namespace App\Modules\Labels\Http\Controllers;

use App\Modules\Labels\DTOs\LabelDTO;
use App\Modules\Labels\Http\Requests\StoreLabelsRequest;
use App\Modules\Labels\Http\Resources\LabelResource;
use App\Modules\Labels\Services\LabelService;
use Illuminate\Http\Request;

class LabelsController
{
    public function __construct(
        private LabelService $service
    ) {}

    public function store(StoreLabelsRequest $request)
    {
        return LabelResource::make(
            $this->service->create(
                LabelDTO::fromRequest($request)
            )
        );
    }

    public function index(Request $request)
    {
        return LabelResource::collection(
            $request->has('ids')
                ? $this->service->getByIds($request->array('ids'))
                : $this->service->index($request)
        );
    }
}
