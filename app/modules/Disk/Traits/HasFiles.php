<?php

namespace App\Modules\Disk\Traits;

use App\Modules\Disk\Models\File;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasFiles
{
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function addFile(array $attributes): File
    {
        return $this->files()->create($attributes);
    }

    public function deleteFiles(): void
    {
        $this->files()->each(function (File $file) {
            $file->delete();
        });
    }
}
