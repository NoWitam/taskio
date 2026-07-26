<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\File;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An AI TEXT edit for the preview editor: the current text content + an instruction. Gated at member
 * level like uploading (`create`) — exactly like {@see AiImageRequest}: the result is never persisted
 * server-side here, it returns to the editor and only lands on the disk through the normal save
 * endpoints, which carry their own authorization.
 */
class AiTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', File::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // A generous ceiling for a text file's content; large enough for real documents, bounded
            // so a request can't be used to push an unbounded body at the provider.
            'content' => ['required', 'string', 'max:100000'],
            'prompt' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Content is required.',
            'content.max' => 'Content cannot exceed 100000 characters.',
            'prompt.required' => 'Prompt is required.',
            'prompt.max' => 'Prompt cannot exceed 2000 characters.',
        ];
    }
}
