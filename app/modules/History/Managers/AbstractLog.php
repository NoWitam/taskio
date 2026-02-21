<?php

namespace App\Modules\History\Managers;

use App\Modules\History\Interfaces\HasHistory;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractLog
{
    public function __construct(
        protected string $field
    ) {}   

    public static function make(...$args): static
    {
        return new static(...$args);
    }

    public function getField(): string
    {
        return $this->field;
    }

    abstract public function prepare(Model&HasHistory $model): ?array;
}
