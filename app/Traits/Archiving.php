<?php

namespace App\Traits;

use App\Models\Scopes\ArchivingScope;

trait Archiving
{
    public static function bootArchiving()
    {
        static::addGlobalScope(new ArchivingScope);
    }

}
