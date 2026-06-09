<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait Searchable
{
    /**
     * Case-insensitive partial-match search across one or more columns.
     * The term is supplied by the caller (not read from the request).
     * No-ops when the term is null or blank, so it can be chained
     * unconditionally. Matched columns are OR-grouped to keep combination
     * with surrounding filters safe.
     */
    public function scopeSearch(Builder $query, array|string $columns, ?string $term): void
    {
        $term = is_string($term) ? trim($term) : '';

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($columns, $term) {
            foreach ((array) $columns as $column) {
                $query->orWhereLike($column, '%' . $term . '%');
            }
        });
    }
}
