<?php

namespace App\Modules\Disk\Services;

use App\Modules\Changelog\Managers\BagTracker;
use App\Modules\Changelog\Managers\ChangelogManager;
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
        $files = File::temp()
            ->whereIn('id', $file_ids)
            ->get();

        File::whereIn('id', $files->pluck('id'))
            ->update([
                'fileable_id' => $model->id,
                'fileable_type' => $model->getMorphClass()
            ]);

        // Zbierz zarówno dodane jak i usunięte pliki w jednym trackerze
        app(ChangelogManager::class)->manual($model, 'files', function (BagTracker $tracker) use ($files, $model, $file_ids, $deleteAnother) {
            // Dodaj nowe pliki
            foreach($files as $file) {
                $tracker->attach($file);
            }
            
            // Usuń stare pliki jeśli deleteAnother
            if($deleteAnother) {
                $toDelete = $model->files()->whereNotIn('id', $file_ids)->get();
                
                foreach($toDelete as $file) {
                    $tracker->detach($file);
                    $file->delete();
                }
            }
        });
    }
}
