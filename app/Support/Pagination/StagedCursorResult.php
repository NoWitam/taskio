<?php

namespace App\Support\Pagination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Str;

/**
 * The paginator {@see StagedCursorPaginator} returns. It is a real Laravel `CursorPaginator` (so the
 * standard resource/response pipeline emits the usual `meta.next_cursor` envelope), but its items
 * span multiple models, so the default cursor computation — which reads a single order-column set
 * from `$this->parameters` — cannot apply. Instead the staged paginator pre-computes the cursors and
 * this class just hands them back, carrying the `_stage` marker the parent knows nothing about.
 */
class StagedCursorResult extends CursorPaginator
{
    /** Supplied via the constructor `options` array (the parent assigns options onto properties). */
    protected ?Cursor $stagedNext = null;

    protected ?Cursor $stagedPrev = null;

    public function nextCursor(): ?Cursor
    {
        return $this->stagedNext;
    }

    public function previousCursor(): ?Cursor
    {
        return $this->stagedPrev;
    }

    /**
     * Eager-load relations across the MIXED page. Eloquent's own `loadMissing` builds one query
     * from the first model, so it cannot serve a collection of different models (a Folder and a
     * File). This groups the items by class and loads each group on its own — ONE batched query
     * per relation per model type (no N+1) — skipping any relation a given class doesn't define
     * (so a File's `labels` is never demanded of a Folder). Mutates the shared model instances in
     * place, so the page reflects the loads; returns `$this` for chaining.
     *
     * @param  array<int|string, mixed>|string  $relations
     */
    public function loadMissing($relations): static
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        $this->getCollection()
            ->filter(fn ($model) => $model instanceof Model)
            ->groupBy(fn (Model $model) => $model::class)
            ->each(function ($group) use ($relations) {
                $sample = $group->first();

                // Keep only the relations THIS model class actually has (matched on the first
                // segment, so nested `creator.workspace` still checks `creator`).
                $applicable = collect($relations)
                    ->filter(fn ($constraint, $name) => method_exists(
                        $sample,
                        Str::before(is_string($constraint) ? $constraint : $name, '.'),
                    ));

                if ($applicable->isNotEmpty()) {
                    // A fresh Eloquent collection of just this type; loading sets the relations on
                    // the same instances that live in the page.
                    $sample->newCollection($group->all())->loadMissing($applicable->toArray());
                }
            });

        return $this;
    }
}
