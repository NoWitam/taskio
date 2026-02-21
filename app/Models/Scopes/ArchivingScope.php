<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ArchivingScope implements Scope
{
    const COLUMN = 'archived_at';

    protected $extensions = ['Archive', 'RestoreFromArchive', 'WithArchived', 'WithoutArchived', 'OnlyArchived'];

    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull(self::COLUMN);
    }

    public function extend(Builder $builder)
    {
        foreach ($this->extensions as $extension) {
            $this->{"add{$extension}"}($builder);
        }

        $builder->onDelete(function (Builder $builder) {
            return $builder->update([
                $this->getArchivedAtColumn($builder) => null,
            ]);
        });
    }

    protected function addArchive(Builder $builder): void
    {
        $builder->macro('archive', function (Builder $builder) {
            return $builder->update([
                $this->getArchivedAtColumn($builder) => now()
            ]);
        });
    }

    protected function addRestoreFromArchive(Builder $builder): void
    {
        $builder->macro('restoreFromArchive', function (Builder $builder) {
            return $builder->update([
                $this->getArchivedAtColumn($builder) => null
            ]);
        });
    }

    protected function addWithArchived(Builder $builder): void
    {
        $builder->macro('withArchived', function (Builder $builder, $withArchived = true) {
            if (!$withArchived) {
                return $builder->withoutArchived();
            }

            return $builder->withoutGlobalScope($this);
        });
    }

    protected function addWithoutArchived(Builder $builder): void
    {
        $builder->macro('withoutArchived', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)->whereNull(
                $model->qualifyColumn(self::COLUMN)
            );

            return $builder;
        });
    }

    protected function addOnlyArchived(Builder $builder)
    {
        $builder->macro('onlyArchived', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)->whereNotNull(
                $model->qualifyColumn(self::COLUMN)
            );

            return $builder;
        });
    }

    protected function getArchivedAtColumn(Builder $builder)
    {
        if (count((array) $builder->getQuery()->joins) > 0) {
            return $builder->getModel()->qualifyColumn(self::COLUMN);
        }

        return self::COLUMN;
    }
}
