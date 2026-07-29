<?php

namespace App\Modules\Generator\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Generator\Http\Resources\ContentTypeResource;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\ContentTypeRegistry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The code-defined CONTENT TYPE catalog: `GET /generator/content-types` returns the system content
 * types the template editor builds its data-driven sections from — `{data:[{id,label,parts:[…]}]}`.
 * Thin: read is gated on workspace membership (TemplatePolicy::viewAny — the same read gate the
 * template list uses), the definitions themselves come straight from the {@see ContentTypeRegistry}.
 */
class ContentTypeController extends Controller
{
    public function __invoke(ContentTypeRegistry $registry): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Template::class);

        return ContentTypeResource::collection($registry->all());
    }
}
