<?php

namespace App\Modules\FilterTabs\Services;

use App\Modules\FilterTabs\DTOs\FilterTabDTO;
use App\Modules\FilterTabs\Models\FilterTab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FilterTabService
{
    private const MAX_TABS_PER_CONTEXT = 30;

    public function index(string $context): Collection
    {
        return FilterTab::query()
            ->where('user_id', auth()->id())
            ->where('context', $context)
            ->orderBy('sort_order')
            ->get();
    }

    public function create(FilterTabDTO $dto): FilterTab
    {
        $existing = FilterTab::query()
            ->where('user_id', $dto->userId)
            ->where('context', $dto->context);

        if ((clone $existing)->count() >= self::MAX_TABS_PER_CONTEXT) {
            throw ValidationException::withMessages([
                'context' => 'filter_tabs.errors.limit_reached',
            ]);
        }

        $nextSortOrder = (int) (clone $existing)->max('sort_order') + 1;

        return FilterTab::create([
            'user_id' => $dto->userId,
            'context' => $dto->context,
            'name' => $dto->name,
            'icon' => $dto->icon,
            'filters' => $dto->filters,
            'sort_order' => $nextSortOrder,
        ]);
    }

    public function update(FilterTab $tab, FilterTabDTO $dto): FilterTab
    {
        $attributes = [];

        if ($dto->name !== null) {
            $attributes['name'] = $dto->name;
        }

        if ($dto->hasIcon) {
            $attributes['icon'] = $dto->icon;
        }

        if ($dto->filters !== null) {
            $attributes['filters'] = $dto->filters;
        }

        $tab->update($attributes);

        return $tab;
    }

    public function delete(FilterTab $tab): void
    {
        $tab->delete();
    }

    public function reorder(string $context, array $ids): Collection
    {
        return DB::transaction(function () use ($context, $ids) {
            $tabs = FilterTab::query()
                ->where('user_id', auth()->id())
                ->where('context', $context)
                ->whereIn('id', $ids)
                ->get();

            if ($tabs->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'ids' => 'filter_tabs.errors.invalid_reorder_set',
                ]);
            }

            foreach (array_values($ids) as $position => $id) {
                $tabs->firstWhere('id', $id)
                    ->update(['sort_order' => $position]);
            }

            return $this->index($context);
        });
    }
}
