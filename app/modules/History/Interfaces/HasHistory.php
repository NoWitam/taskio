<?php

namespace App\Modules\History\Interfaces;

interface HasHistory
{
    public static function getHistoryOptions(): array;
}
