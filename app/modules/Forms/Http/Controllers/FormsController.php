<?php

namespace App\Modules\Forms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Forms\DTOs\FormDTO;
use App\Modules\Forms\Http\Requests\StoreFormRequest;
use App\Modules\Forms\Http\Resources\FormListResource;
use App\Modules\Forms\Http\Resources\FormResource;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Services\FormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FormsController extends Controller
{
    public function __construct(
        private FormService $service
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
}
