<?php

namespace App\Modules\FilterTabs\Http\Controllers;

use App\Modules\FilterTabs\DTOs\FilterTabDTO;
use App\Modules\FilterTabs\Http\Requests\DestroyFilterTabRequest;
use App\Modules\FilterTabs\Http\Requests\IndexFilterTabsRequest;
use App\Modules\FilterTabs\Http\Requests\ReorderFilterTabsRequest;
use App\Modules\FilterTabs\Http\Requests\StoreFilterTabRequest;
use App\Modules\FilterTabs\Http\Requests\UpdateFilterTabRequest;
use App\Modules\FilterTabs\Http\Resources\FilterTabResource;
use App\Modules\FilterTabs\Models\FilterTab;
use App\Modules\FilterTabs\Services\FilterTabService;

class FilterTabsController
{
    public function __construct(
        private FilterTabService $service
    ) {}

    public function index(IndexFilterTabsRequest $request)
    {
        return FilterTabResource::collection(
            $this->service->index($request->string('context')->toString())
        );
    }

    public function store(StoreFilterTabRequest $request)
    {
        return FilterTabResource::make(
            $this->service->create(FilterTabDTO::fromRequest($request))
        );
    }

    public function update(UpdateFilterTabRequest $request, FilterTab $filterTab)
    {
        return FilterTabResource::make(
            $this->service->update($filterTab, FilterTabDTO::fromRequest($request))
        );
    }

    public function destroy(DestroyFilterTabRequest $request, FilterTab $filterTab)
    {
        $this->service->delete($filterTab);

        return response()->noContent();
    }

    public function reorder(ReorderFilterTabsRequest $request)
    {
        return FilterTabResource::collection(
            $this->service->reorder(
                $request->string('context')->toString(),
                $request->array('ids')
            )
        );
    }
}
