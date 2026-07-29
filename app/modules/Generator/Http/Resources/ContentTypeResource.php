<?php

namespace App\Modules\Generator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A CONTENT TYPE definition on the wire: `{id, label, parts:[{key, kind, label, required, config}]}` —
 * the recipe SHAPE the template editor mirrors to render its data-driven sections. Wraps a
 * {@see \App\Modules\Generator\Support\ContentTypeDefinition} VO, which already owns the canonical
 * array shape (so the wire can never drift from what the validators / renderer read).
 *
 * @property \App\Modules\Generator\Support\ContentTypeDefinition $resource
 */
class ContentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
