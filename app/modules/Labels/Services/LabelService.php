<?php

namespace App\Modules\Labels\Services;

use App\Modules\Labels\DTOs\LabelDTO;
use App\Modules\Labels\Models\Label;
use Illuminate\Http\Request;

class LabelService
{
    public function create(LabelDTO $dto)
    {
        return Label::create([
            'name' => $dto->name,
            'description' => $dto->description,
            'icon' => $dto->icon,
            'color' => $dto->color
        ]);
    }

    public function index(Request $request)
    {
        return Label::query()
            ->search('name', $request->get('search'))
            ->cursorPaginate(8);
    }

    public function getByIds(array $ids)
    {
        return Label::query()->findMany($ids);
    }
}