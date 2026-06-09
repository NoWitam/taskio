<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\DTOs\FormReportDTO;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FormReportService
{
    public function create(FormReportDTO $dto): FormReport
    {
        // Validate that the form exists and is enabled
        $form = Form::findOrFail($dto->form_id);
        
        if (!$form->isEnabled()) {
            throw ValidationException::withMessages([
                'form_id' => ['Formularz musi być włączony przed utworzeniem raportu.'],
            ]);
        }

        return FormReport::create([
            'form_id' => $dto->form_id,
            'name' => $dto->name,
            'guidelines' => $dto->guidelines,
            'sources' => $dto->sources,
            'submissions_from' => $dto->submissions_from,
            'submissions_to' => $dto->submissions_to,
        ]);
    }

    public function indexByForm(Request $request, string $formId)
    {
        return FormReport::query()
            ->with('creator', 'file')
            ->where('form_id', $formId)
            ->when(
                $request->boolean('trashed'),
                fn(Builder $query) => $query->onlyTrashed()
            )
            ->search(['name', 'guidelines'], $request->get('search'))
            ->when(
                $request->array('creator_id'),
                fn(Builder $query, $creators) => $query->whereIn('creator_id', $creators)
            )
            ->when(
                $request->boolean('only_completed'),
                fn(Builder $query) => $query->whereNotNull('completed_at')
            )
            ->when(
                $request->boolean('only_pending'),
                fn(Builder $query) => $query->whereNull('completed_at')
            )
            ->filterByDate('created_at', $request)
            ->orderBy(
                'created_at', 
                $request->get('sort', 'newest') === 'oldest' ? 'asc' : 'desc'
            )
            ->cursorPaginate(12);
    }
}
