<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\DTOs\FormSubmissionDTO;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FormSubmissionService
{
    public function create(FormSubmissionDTO $dto): FormSubmission
    {
        // Validate that the form exists and is enabled
        $form = Form::findOrFail($dto->form_id);
        
        if (!$form->canBeFilled()) {
            throw ValidationException::withMessages([
                'form_id' => ['Formularz musi być włączony przed dodaniem uzupełnień.'],
            ]);
        }

        // Manual submissions (submittable = Form) are approved immediately
        $approvedAt = null;
        if ($dto->submittable_type === $form->getMorphClass()) {
            $approvedAt = now();
        }

        return FormSubmission::create([
            'form_id' => $dto->form_id,
            'submittable_type' => $dto->submittable_type,
            'submittable_id' => $dto->submittable_id,
            'data' => $dto->data,
            'form_content_version_id' => $form->latestContentVersion()?->id,
            'approved_at' => $approvedAt,
        ]);
    }

    public function update(FormSubmission $submission, array $data): FormSubmission
    {
        // Cannot edit approved submissions
        if ($submission->isApproved()) {
            throw ValidationException::withMessages([
                'submission' => ['Nie można edytować zatwierdzonego uzupełnienia formularza.'],
            ]);
        }

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
            ->whereNotNull('approved_at')
            ->when(
                $request->boolean('trashed'),
                fn(Builder $query) => $query->onlyTrashed()
            )
            ->when(
                request()->array('sources'),
                fn(Builder $query, $sources) => $query->whereIn('submittable_type', $sources)
            )
            ->when(
                $request->filled('indexed'),
                fn(Builder $query) => $request->boolean('indexed')
                    ? $query->whereNotNull('indexed_at')
                    : $query->whereNull('indexed_at')
            )
            ->search('data', $request->get('search'))
            ->filterByDate('approved_at', $request)
            ->orderBy(
                'approved_at', 
                $request->get('sort', 'newest') === 'oldest' ? 'asc' : 'desc'
            )
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
