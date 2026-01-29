<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class FileService
{
    public function upload(UploadedFile $file, ?Model $parent): File
    {
        $path = $file->storeAs('uploads', Str::uuid() . '.' . $file->getClientOriginalExtension());

        return File::create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'type' => FileType::fromMimeType($file->getMimeType()),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'fileable_type' => $parent?->getMorphClass(), 
            'fileable_id' => $parent?->getKey(),
        ]);
    }

    public function attachToModel(Model $model, array $file_ids, bool $deleteAnother = false): void
    {
        File::temp()
            ->whereIn('id', $file_ids)
            ->update([
                'fileable_id' => $model->id,
                'fileable_type' => $model->getMorphClass()
            ]);

        if($deleteAnother) {
            $model->files()->whereNotIn('id', $file_ids)->delete();
        }
    }
}
