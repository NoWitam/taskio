<?php

namespace App\Modules\Labels\Traits;

use App\Modules\Labels\Models\Label;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasLabels
{
    public function labels(): MorphToMany
    {
        return $this->morphToMany(
            Label::class,
            'labelable',
            'labelables',
            'labelable_id',
            'label_id'
        )->withTimestamps();
    }

    public function scopeFilterByLabels(Builder $query, array $labelUuids = [], string $operator = 'OR'): Builder
    {
        $labelUuids = array_values(array_unique(array_filter($labelUuids)));
        if (empty($labelUuids)) {
            return $query;
        }

        $operator = strtoupper($operator);

        if ($operator === 'AND') {
            foreach ($labelUuids as $uuid) {
                $query->whereHas('labels', fn ($q) => $q->where('id', $uuid));
            }
            return $query;
        }

        return $query->whereHas('labels', fn ($q) => $q->whereIn('id', $labelUuids));
    }
}
