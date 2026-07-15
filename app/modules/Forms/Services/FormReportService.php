<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\DTOs\FormReportDTO;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use App\Modules\Workflows\Models\WorkflowRun;
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
            // creator is polymorphic (a report may be run-created); load a run's workflow so
            // CreatorResource renders the automation name without an N+1 per row.
            ->with(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']]), 'file'])
            ->where('form_id', $formId)
            ->when(
                $request->boolean('trashed'),
                fn (Builder $query) => $query->onlyTrashed()
            )
            ->search(['name', 'guidelines'], $request->get('search'))
            ->when(
                $request->array('creator_id'),
                // "Created by me" means a human creator: a run/bot-created report (creator_type
                // != 'user') must never surface under a user id filter.
                fn (Builder $query, $creators) => $query
                    ->where('creator_type', 'user')
                    ->whereIn('creator_id', $creators)
            )
            ->when(
                $request->boolean('only_completed'),
                fn (Builder $query) => $query->whereNotNull('completed_at')
            )
            ->when(
                $request->boolean('only_pending'),
                fn (Builder $query) => $query->whereNull('completed_at')
            )
            ->filterByDate('created_at', $request)
            ->orderBy(
                'created_at',
                $request->get('sort', 'newest') === 'oldest' ? 'asc' : 'desc'
            )
            ->cursorPaginate(12);
    }
}
