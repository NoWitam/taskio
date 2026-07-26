<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\DTOs\ConstantDTO;
use App\Modules\Variables\Models\Constant;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Business logic + persistence for CONSTANTS. Single-row writes (no scheduling, no multi-step
 * orchestration), so there is no transaction: each mutation is one atomic save. Queries run through
 * the model so WorkspaceScope / the tenant connection isolate the active workspace in both db_modes.
 */
class ConstantService
{
    public function index(Request $request): CursorPaginator
    {
        return Constant::query()
            ->with('creator')
            ->search(['name', 'key'], $request->get('search'))
            ->orderBy('name')
            ->cursorPaginate(20);
    }

    public function create(ConstantDTO $dto): Constant
    {
        $constant = new Constant($this->attributes($dto));
        $constant->save();

        return $constant;
    }

    public function update(Constant $constant, ConstantDTO $dto): Constant
    {
        $constant->fill($this->attributes($dto));
        $constant->save();

        return $constant->refresh();
    }

    public function delete(Constant $constant): void
    {
        $constant->delete();
    }

    /**
     * Map the DTO onto persisted columns. The value rides the model's json cast unchanged (a
     * scalar, list, object, or null).
     *
     * @return array<string, mixed>
     */
    private function attributes(ConstantDTO $dto): array
    {
        return [
            'name' => $dto->name,
            'key' => $dto->key,
            'descriptor' => $dto->descriptor,
            'value' => $dto->value,
        ];
    }
}
