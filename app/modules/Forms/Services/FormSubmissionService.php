<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\DTOs\FormSubmissionDTO;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class FormSubmissionService
{
    public function create(FormSubmissionDTO $dto): FormSubmission
    {
        return FormSubmission::create([
            'form_id' => $dto->form_id,
            'submittable_type' => $dto->submittable_type,
            'submittable_id' => $dto->submittable_id,
            'data' => $dto->data,
        ]);
    }

    public function update(FormSubmission $submission, array $data): FormSubmission
    {
        $submission->update([
            'data' => $data,
        ]);

        return $submission;
    }

    public function indexByForm(Request $request, string $formId)
    {
        return FormSubmission::query()
            ->with('creator', 'submittable')
            ->where('form_id', $formId)
            ->when(
                $request->array('sources'),
                fn(Builder $query, $sources) => $query->whereIn('submittable_type', $sources)
            )
            ->latest('created_at')
            ->cursorPaginate(12);
    }

    public function findBySubmittable(string $submittableType, string $submittableId): ?FormSubmission
    {
        return FormSubmission::query()
            ->with('form', 'creator')
            ->where('submittable_type', $submittableType)
            ->where('submittable_id', $submittableId)
            ->first();
    }
}
