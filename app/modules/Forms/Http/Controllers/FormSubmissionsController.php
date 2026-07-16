<?php

namespace App\Modules\Forms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Forms\DTOs\FormSubmissionDTO;
use App\Modules\Forms\Http\Requests\StoreFormSubmissionRequest;
use App\Modules\Forms\Http\Requests\UpdateFormSubmissionRequest;
use App\Modules\Forms\Http\Resources\FormSubmissionResource;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Services\FormSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FormSubmissionsController extends Controller
{
    public function __construct(
        private FormSubmissionService $service
    ) {}

    public function indexByForm(Request $request, string $formId): AnonymousResourceCollection
    {
        $paginator = $this->service->indexByForm($request, $formId);

        return FormSubmissionResource::collection($paginator);
    }

    public function show(Request $request, string $id): FormSubmissionResource
    {
        $submission = FormSubmission::findOrFail($id);

        return FormSubmissionResource::make(
            $submission->loadMissing(['form', 'creator'])
        );
    }

    public function store(StoreFormSubmissionRequest $request): FormSubmissionResource
    {
        return FormSubmissionResource::make(
            $this->service->create(
                FormSubmissionDTO::fromRequest($request)
            )
        );
    }

    public function update(UpdateFormSubmissionRequest $request, FormSubmission $submission): FormSubmissionResource
    {
        $this->service->update($submission, $request->input('data'));

        return FormSubmissionResource::make(
            $submission->fresh()->loadMissing(['form', 'creator'])
        );
    }

    /**
     * Soft-delete a submission (moves it to the trash).
     */
    public function destroy(FormSubmission $submission): Response
    {
        $this->authorize('delete', $submission);

        $submission->delete();

        return response()->noContent();
    }

    /**
     * Restore a soft-deleted submission.
     */
    public function restore(string $id): FormSubmissionResource
    {
        $submission = FormSubmission::withTrashed()->findOrFail($id);

        $this->authorize('restore', $submission);

        $submission->restore();

        return FormSubmissionResource::make(
            $submission->loadMissing(['form', 'creator'])
        );
    }

    /**
     * Permanently delete a soft-deleted submission.
     */
    public function forceDestroy(string $id): Response
    {
        $submission = FormSubmission::withTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $submission);

        $submission->forceDelete();

        return response()->noContent();
    }
}
