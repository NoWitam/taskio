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
        // If inline parameter is set, return file content
        if (request()->query('inline')) {
            $content = Storage::get($file->path);
            
            if ($content === null || $content === false) {
                abort(404, 'File not found');
            }
            
            return response($content, 200, [
                'Content-Type' => $file->mime_type ?? 'text/plain',
            ]);
        }
        
        return Storage::download($file->path);
    }
    public function uploadTemp(UploadTempFileRequest $request)
    {
        return FileResource::make(
            $this->service->upload($request->file('file'), null)
        );
    }
}
