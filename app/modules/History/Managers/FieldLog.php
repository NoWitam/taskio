<?php

namespace App\Modules\History\Managers;

use App\Modules\History\Interfaces\HasHistory;
use Closure;
use Illuminate\Database\Eloquent\Model;

class FieldLog extends AbstractLog
{
    protected bool $withComparision = false;
    protected ?Closure $map = null;
    protected ?string $component = null;

    public function withComparision(): static
    {
        $this->withComparision = true;

        return $this;
    }

    public function withMap(Closure $map): self
    {
        $this->map = $map;

        return $this;
    }

    public function asComponent(string $component): self
    {
        $this->component = $component;

        return $this;
    }

    public function prepare(Model&HasHistory $model): ?array
    {
        $original = $model->getOriginal($this->field);
        $dirty = $model->{$this->field};

        if($dirty == $original) {
            return null;
        }

        $map = $this->map;

        return [
            'type' => 'field',
            'component' => $this->component,
            'comparision' => $this->withComparision ? '' : null, //TODO
            'before' => $map == null ? $original : $map($original, $model),
            'after' => $map == null ? $dirty : $map($dirty, $model),
        ];
    }
}
