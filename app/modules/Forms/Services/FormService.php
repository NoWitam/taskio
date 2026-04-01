<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\DTOs\FormDTO;
use App\Modules\Forms\Models\Form;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class FormService
{
    public function create(FormDTO $dto): Form
    {
        return Form::create([
            'name' => $dto->name,
            'icon' => $dto->icon,
            'description' => $dto->description,
            'content' => $dto->content,
            'is_anonymous' => $dto->is_anonymous,
        ]);
    }

    public function update(Form $form, FormDTO $dto): Form
    {
        $form->update([
            'name' => $dto->name,
            'icon' => $dto->icon,
            'description' => $dto->description,
            'content' => $dto->content,
            'is_anonymous' => $dto->is_anonymous,
        ]);

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
                $request->has('search'),
                fn(Builder $query) => $query->where(fn(Builder $sq) => 
                    $sq->whereLike('name', '%' . $request->get('search') . '%')
                        ->orWhereLike('description', '%' . $request->get('search') . '%')
                )
            )
            ->filterByDate('created_at', $request)
            ->latest('created_at');
    }
}
