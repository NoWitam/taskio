<?php

namespace App\Modules\FilterTabs\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FilterTabResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon?->value,
            'filters' => $this->filters,
            'sort_order' => $this->sort_order,
        ];
    }
}
