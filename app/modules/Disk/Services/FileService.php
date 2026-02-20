<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\History\Managers\BagLog;
use App\Modules\History\Managers\HistoryManager;
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
        dump('attachToModel');
        $files = File::temp()
            ->whereIn('id', $file_ids)
            ->get();

        File::whereIn('id', $files->pluck('id'))
            ->update([
                'fileable_id' => $model->id,
                'fileable_type' => $model->getMorphClass()
            ]);

        app(HistoryManager::class)->manual($model, 'files', function (BagLog $log) use ($files) {
            foreach($files as $file) {
                $log->attach($file);
            }
        });

        if($deleteAnother) {
            app(HistoryManager::class)->manual($model, 'files', function (BagLog $log) use ($model, $file_ids) {
                $toDelete = $model->files()->whereNotIn('id', $file_ids)->get();

                foreach($toDelete as $file) {
                    $log->dettach($file);
                    $file->delete();
                }
            });
        }
    }
}
