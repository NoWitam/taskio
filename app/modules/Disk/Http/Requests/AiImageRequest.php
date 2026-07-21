<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\File;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An AI image edit for the preview editor: the CURRENT canvas (not the stored blob — local edits
 * apply first) + a prompt. Gated at member level like uploading (`create`): the result is never
 * persisted server-side — it returns to the canvas and only lands on the disk through the normal
 * save endpoints, which carry their own authorization.
 */
class AiImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', File::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Only the formats the canvas can produce; 25MB — a canvas export, not a raw upload.
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:25600'],
            // Optional inpainting mask — MUST be PNG (its transparent pixels mark the edit region).
            'mask' => ['nullable', 'file', 'mimes:png', 'max:25600'],
            'prompt' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Image is required.',
            'image.mimes' => 'The image must be a jpeg, png or webp.',
            'image.max' => 'Image size cannot exceed 25MB.',
            'mask.mimes' => 'The mask must be a png.',
            'mask.max' => 'Mask size cannot exceed 25MB.',
            'prompt.required' => 'Prompt is required.',
        ];
    }
}
