<?php

namespace App\Modules\Generator\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Generator\DTOs\TemplateDTO;
use App\Modules\Generator\Http\Requests\StoreTemplateRequest;
use App\Modules\Generator\Http\Requests\UpdateTemplateRequest;
use App\Modules\Generator\Http\Resources\TemplateResource;
use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD for TEMPLATES — user-created, workspace-scoped reusable prompts with declared typed slots. Thin:
 * each method converts request → DTO, calls the service, and returns a resource. Authorization lives in
 * the FormRequests (mutations, via TemplatePolicy) and the explicit authorize() calls (reads).
 */
class TemplateController extends Controller
{
    public function __construct(
        private TemplateService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Template::class);

        return TemplateResource::collection(
            $this->service->index($request)
        );
    }

    public function store(StoreTemplateRequest $request): TemplateResource
    {
        return TemplateResource::make(
            $this->service->create(TemplateDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function show(Template $template): TemplateResource
    {
        $this->authorize('view', $template);

        return TemplateResource::make($template->loadMissing('creator'));
    }

    public function update(UpdateTemplateRequest $request, Template $template): TemplateResource
    {
        return TemplateResource::make(
            $this->service->update($template, TemplateDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function destroy(Template $template): JsonResponse
    {
        $this->authorize('delete', $template);

        $this->service->delete($template);

        return response()->json(['message' => 'Template deleted successfully']);
    }
}
