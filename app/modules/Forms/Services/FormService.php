<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\DTOs\FormDTO;
use App\Modules\Forms\Models\Form;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FormService
{
    public function create(FormDTO $dto): Form
    {
        $form = Form::create([
            'name' => $dto->name,
            'icon' => $dto->icon,
            'description' => $dto->description,
            'content' => $dto->content,
            'is_anonymous' => $dto->is_anonymous,
            // Anonymous forms are automatically enabled
            'enabled_at' => $dto->is_anonymous ? now() : null,
        ]);

        return $form;
    }

    public function update(Form $form, FormDTO $dto): Form
    {
        $updateData = [
            'name' => $dto->name,
            'icon' => $dto->icon,
            'description' => $dto->description,
            'is_anonymous' => $dto->is_anonymous,
        ];

        // Content can only be updated if form is not enabled yet
        if ($form->canBeEdited()) {
            $updateData['content'] = $dto->content;
        }

        $form->update($updateData);

        return $form;
    }

    public function delete(Form $form): void
    {
        $form->delete();
    }

    public function restore(Form $form): Form
    {
        $form->restore();

        return $form;
    }

    /**
     * Enable the form, making it ready to accept submissions
     * 
     * @throws ValidationException if form doesn't have minimum required fields or is already enabled
     */
    public function enable(Form $form): Form
    {
        // Idempotent - if already enabled, just return the form
        if ($form->isEnabled()) {
            return $form;
        }

        // Validate that form has at least one input field
        if (!$form->hasMinimumRequiredFields()) {
            throw ValidationException::withMessages([
                'content' => ['Formularz musi zawierać co najmniej jedno pole wejściowe.'],
            ]);
        }

        $form->update([
            'enabled_at' => now(),
        ]);

        return $form->fresh();
    }

    public function index(Request $request)
    {
        return $this->listQuery($request)->cursorPaginate(12);
    }

    public function count(Request $request): int
    {
        return $this->listQuery($request)->count();
    }

    protected function listQuery(Request $request): Builder
    {
        return Form::query()
            ->with('creator')
            ->withCount('submissions')
            ->where('is_anonymous', false)
            ->when(
                request()->boolean('trashed'),
                fn(Builder $query) => $query->onlyTrashed()
            )
            ->when(
                request()->filled('enabled'),
                fn(Builder $query) => request()->boolean('enabled') ? $query->whereNotNull('enabled_at') : $query->whereNull('enabled_at')
            )
            ->when(
                $request->has('search'),
                fn(Builder $query) => $query->where(fn(Builder $sq) => 
                    $sq->whereLike('name', '%' . $request->get('search') . '%')
                        ->orWhereLike('description', '%' . $request->get('search') . '%')
                )
            )
            ->latest('created_at');
    }
}
