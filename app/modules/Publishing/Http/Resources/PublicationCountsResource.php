<?php

namespace App\Modules\Publishing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-status counts for the module's tabs and its navigation badge.
 *
 *   { "data": { "counts": { "<status>": int, ... }, "total": int, "needs_attention": int } }
 *
 * Shaped after `TaskCountsResource`, deliberately, so a second counts endpoint in this product does not
 * invent a second shape. Two differences, both argued:
 *
 * `total` IS EVERY PUBLICATION, where the tasks endpoint's `total` excludes archive and trash. Tasks
 * has secondary lifecycle buckets with their own tabs; publishing has none — a published publication is
 * the POINT of the module, not an archive of it, so excluding it would make the headline number shrink
 * every time the product succeeded.
 *
 * `needs_attention` IS AN ADDITION, and it exists so the navigation badge does not have to know which
 * statuses those are. A client summing a list it maintains would have been right on the day it was
 * written and silently wrong the moment an eighth status was added — which is the same failure mode a
 * client-side status vocabulary has, and the reason calendar badges travel as prose. The server owns
 * the question ("does a person have to do something?"), so the server answers it: today that is
 * `failed`, `needs_reconcile` and `blocked`, and where it says so is
 * {@see \App\Modules\Publishing\Enums\PublicationStatus::needsAttention()}.
 *
 * `counts` always carries EVERY status, zero where there are none. A missing key would make a client
 * render "—" for a status that simply has no rows, which is a different statement from zero.
 *
 * @property array{counts: array<string, int>, total: int, needs_attention: int} $resource
 */
class PublicationCountsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'counts' => $this->resource['counts'],
            'total' => $this->resource['total'],
            'needs_attention' => $this->resource['needs_attention'],
        ];
    }
}
