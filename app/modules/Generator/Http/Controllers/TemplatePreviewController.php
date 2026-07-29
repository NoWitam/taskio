<?php

namespace App\Modules\Generator\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Generator\Http\Requests\TemplatePreviewRequest;
use App\Modules\Generator\Services\TemplateRenderService;
use Illuminate\Http\JsonResponse;

/**
 * The FAITHFUL, DRAFT-FRIENDLY, PER-PART template PREVIEW: `POST /generator/preview` renders an (unsaved)
 * content recipe against sample slot values + the workspace globals through the SHARED resolver (the real
 * executor; `@[ai-text]` inert-but-labeled in this sub-stage; image plans as PLAN summaries — no image
 * executed). POST because the whole draft rides the request body. Thin: authorize + shape live in
 * TemplatePreviewRequest, the fail-soft rendering in TemplateRenderService.
 *
 * The response is wrapped in the standard `data` envelope: `{data:{parts:{<partKey>:{rendered}|{plan}}}}`.
 */
class TemplatePreviewController extends Controller
{
    public function __construct(
        private TemplateRenderService $render,
    ) {}

    public function __invoke(TemplatePreviewRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->render->render(
                $request->contentType(),
                $request->content(),
                $request->slots(),
                $request->slotValues(),
            ),
        ]);
    }
}
