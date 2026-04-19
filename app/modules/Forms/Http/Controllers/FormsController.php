<?php

namespace App\Modules\Forms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Forms\DTOs\FormDTO;
use App\Modules\Forms\Http\Requests\DisableFormRequest;
use App\Modules\Forms\Http\Requests\EnableFormRequest;
use App\Modules\Forms\Http\Requests\IndexFormRequest;
use App\Modules\Forms\Http\Requests\RestoreIndexRequest;
use App\Modules\Forms\Http\Requests\StoreFormRequest;
use App\Modules\Forms\Http\Requests\UnindexFormRequest;
use App\Modules\Forms\Http\Resources\FormListResource;
use App\Modules\Forms\Http\Resources\FormResource;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Services\FormAnalyticalTableService;
use App\Modules\Forms\Services\FormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FormsController extends Controller
{
    public function __construct(
        private FormService $service,
        private FormAnalyticalTableService $analyticalTableService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->service->index($request);

        return FormListResource::collection($paginator)->additional(['meta' => [
            'total' => !$request->has('cursor') 
                ? $this->service->count($request) 
                : null
        ]]);
    }

    public function show(Request $request, string $id): FormResource
    {
        $form = Form::withTrashed()->findOrFail($id);

        return FormResource::make(
            $form->loadMissing(['creator'])
        );
    }

    public function store(StoreFormRequest $request): FormResource
    {
        return FormResource::make(
            $this->service->create(
                FormDTO::fromRequest($request)
            )
        );
    }

    public function update(StoreFormRequest $request, Form $form): FormResource
    {
        $this->service->update(
            $form,
            FormDTO::fromRequest($request)
        );

        return FormResource::make(
            $form->loadMissing(['creator'])
        );
    }

    public function destroy(Form $form): JsonResponse
    {
        $this->service->delete($form);

        return response()->json([
            'message' => 'Form moved to trash successfully'
        ]);
    }

    public function forceDestroy(Form $form): JsonResponse
    {
        $form->forceDelete();

        return response()->json([
            'message' => 'Form permanently deleted'
        ]);
    }

    public function restore(string $id): FormResource
    {
        $form = Form::withTrashed()->findOrFail($id);

        return FormResource::make(
            $this->service->restore($form)->loadMissing(['creator'])
        );
    }

    /**
     * Enable the form, making it ready to accept submissions
     */
    public function enable(EnableFormRequest $request, Form $form): FormResource
    {
        return FormResource::make(
            $this->service->enable($form)->loadMissing(['creator'])
        );
    }

    /**
     * Disable the form, putting it back into draft mode
     */
    public function disable(DisableFormRequest $request, Form $form): FormResource
    {
        return FormResource::make(
            $this->service->disable($form)->loadMissing(['creator'])
        );
    }

    /**
     * Index the form, enabling advanced filtering and reporting
     */
    public function indexForm(IndexFormRequest $request, Form $form): FormResource
    {
        return FormResource::make(
            $this->service->indexForm($form)->loadMissing(['creator'])
        );
    }

    /**
     * Unindex the form, removing advanced filtering capabilities
     */
    public function unindex(UnindexFormRequest $request, Form $form): FormResource
    {
        return FormResource::make(
            $this->service->unindex($form, $request->boolean('backup_indexes'))->loadMissing(['creator'])
        );
    }

    /**
     * Get compatibility information for indexing.
     * Shows how many submissions are compatible/incompatible with the current version.
     */
    public function compatibilityInfo(Request $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $info = $this->analyticalTableService->getCompatibilityInfo($form);

        return response()->json($info);
    }

    /**
     * Restore indexes from backup
     */
    public function restoreIndex(RestoreIndexRequest $request, Form $form): FormResource
    {
        return FormResource::make(
            $this->service->restoreIndex($form)->loadMissing(['creator'])
        );
    }

    /**
     * Get form preview
     */
    public function preview(Request $request, Form $form): FormResource
    {
        $this->authorize('view', $form);

        return FormResource::make(
            $form->loadMissing(['creator'])
        );
    }
}
