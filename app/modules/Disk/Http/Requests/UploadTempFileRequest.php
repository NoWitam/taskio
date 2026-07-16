<?php

namespace App\Modules\Disk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DEPLOYMENT DEPENDENCY: the 100MB cap below is only reachable if PHP allows a body that
 * large. Stock php.ini ships `post_max_size = 8M` / `upload_max_filesize = 2M`, and PHP
 * discards an over-sized body BEFORE Laravel runs — the request then arrives with no file
 * and this rule reports the generic "file is required" instead of a size error. Any
 * environment that means to honour 100MB must raise BOTH ini values (and the reverse-proxy
 * body limit, e.g. nginx `client_max_body_size`) to match.
 */
class UploadTempFileRequest extends FormRequest
{
    /** Authorization is the route's workspace gate + auth:sanctum; any member may upload. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:102400'], // max 100MB, see the class docblock
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required.',
            'file.file' => 'Uploaded file is not valid.',
            'file.max' => 'File size cannot exceed 100MB.',
        ];
    }
}
