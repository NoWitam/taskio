<?php

namespace App\Modules\Generator\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Generator\Http\Requests\TemplateCatalogRequest;
use App\Modules\Generator\Services\ContentTypeRegistry;
use App\Modules\Generator\Services\TemplateVariableCatalog;
use Illuminate\Http\JsonResponse;

/**
 * The DRAFT-FRIENDLY, server-authoritative TEMPLATE catalog: `POST /generator/catalog` takes a set of
 * DECLARED slots and returns the `slots.<name>` typed variables MERGED with the shared authoring surface
 * (workspace globals, the operation catalog incl. custom functions, and the variable-type list). POST
 * (not GET) because the slots — an in-progress template's draft — ride the request body. Thin: authorize
 * + shape live in TemplateCatalogRequest, the composition in TemplateVariableCatalog.
 *
 * The response is wrapped in the standard `data` envelope (mirrors the workflow catalog):
 * `{data: {variables, operations, types}}`.
 */
class TemplateCatalogController extends Controller
{
    public function __construct(
        private TemplateVariableCatalog $catalog,
        private ContentTypeRegistry $registry,
    ) {}

    public function __invoke(TemplateCatalogRequest $request): JsonResponse
    {
        // Cross-part scoping (Phase A): when the editor names a content type + the current part, offer the
        // EARLIER parts as `parts.<key>` variables (earlier-only → acyclic). Absent → no `parts.*`.
        $contentType = $request->contentType();
        $partKey = $request->partKey();
        $earlierPartKeys = $contentType !== null && $partKey !== null
            ? $this->registry->partKeysBefore($contentType, $partKey)
            : [];

        return response()->json([
            'data' => $this->catalog->forSlots($request->slots(), $earlierPartKeys),
        ]);
    }
}
