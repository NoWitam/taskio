<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes the per-status board counts. Wraps the service's counts array so the wire
 * shape is stable and self-describing:
 *
 *   { "data": { "counts": { "<status>": int, ... }, "total": int } }
 *
 * `counts` always carries every TaskStatus case; `total` is the four active board
 * columns (see TaskService::counts()).
 *
 * @property array{counts: array<string, int>, total: int} $resource
 */
class TaskCountsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'counts' => $this->resource['counts'],
            'total' => $this->resource['total'],
        ];
    }
}
