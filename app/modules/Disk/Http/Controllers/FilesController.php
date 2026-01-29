<?php

namespace App\Modules\Disk\Http\Controllers;

use App\Modules\Disk\Http\Requests\UploadTempFileRequest;
use App\Modules\Disk\Http\Resources\FileResource;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\FileService;
use Illuminate\Support\Facades\Storage;

class FilesController
{
    public function __construct(
        private FileService $service
    ) {}

    public function show(File $file)
    {
        return Storage::get($file->path);
        return response()->file($file->path);
    }

    public function uploadTemp(UploadTempFileRequest $request)
    {
        return FileResource::make(
            $this->service->upload($request->file('file'), null)
        );
    }
}
