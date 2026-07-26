<?php

namespace App\Modules\Disk\Http\Requests;

use App\Modules\Disk\Models\File;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Autosave a per-user file-edit draft (POST /disk/{file}/draft). Multipart: a `manifest` JSON string,
 * an optional `base_version`, and zero+ image base parts named `base_{baseId}` (the FE sends ONLY
 * bases not yet stored). Gated at member `view` level like disk.info — a draft is the user's own
 * private working copy, never persisted onto the file until an explicit Save.
 *
 * The manifest is decoded in {@see prepareForValidation()} into `manifest_data` so its shape can be
 * validated per `kind`; the FE owns everything else inside it.
 */
class DraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('file')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $decoded = json_decode((string) $this->input('manifest'), true);

        $this->merge(['manifest_data' => is_array($decoded) ? $decoded : null]);
    }

    public function rules(): array
    {
        $rules = [
            'manifest' => ['required', 'string'],
            'manifest_data' => ['required', 'array'],
            'manifest_data.kind' => ['required', 'in:image,text'],
            'base_version' => ['nullable', 'string', 'max:255'],

            // Text drafts carry the full content inline (no base blobs).
            'manifest_data.content' => ['required_if:manifest_data.kind,text', 'string', 'max:200000'],

            // Image drafts carry the editor's history + which bases are live.
            'manifest_data.history' => ['required_if:manifest_data.kind,image', 'array'],
            'manifest_data.historyIndex' => ['required_if:manifest_data.kind,image', 'integer'],
            'manifest_data.savedIndex' => ['required_if:manifest_data.kind,image', 'integer'],
            'manifest_data.baseIds' => ['required_if:manifest_data.kind,image', 'array'],
            'manifest_data.baseIds.*' => ['integer', 'min:0'],
        ];

        // Each uploaded base part must be a PNG (its raw pixels — the FE exports canvas layers as PNG).
        foreach ($this->baseFileKeys() as $key) {
            $rules[$key] = ['file', 'mimes:png'];
        }

        return $rules;
    }

    /**
     * Cap the TOTAL request payload (manifest + every uploaded base blob) at config('disk.drafts.max_bytes').
     * A draft is a convenience buffer, not unbounded storage — an oversize autosave is refused (422)
     * rather than silently filling the tenant disk.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $total = strlen((string) $this->input('manifest'));

            foreach ($this->baseFiles() as $upload) {
                $total += (int) $upload->getSize();
            }

            if ($total > (int) config('disk.drafts.max_bytes', 67108864)) {
                $validator->errors()->add('manifest', __('disk.drafts.too_large'));
            }
        });
    }

    /** The validated, decoded manifest to hand to the service. */
    public function manifest(): array
    {
        return (array) $this->input('manifest_data');
    }

    /** The file version the draft pinned when it began, or null. */
    public function baseVersion(): ?string
    {
        $value = $this->input('base_version');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * The uploaded base blobs keyed by their integer baseId (`base_5` -> 5).
     *
     * @return array<int, UploadedFile>
     */
    public function baseFiles(): array
    {
        $files = [];

        foreach ($this->baseFileKeys() as $key) {
            $files[(int) substr($key, strlen('base_'))] = $this->file($key);
        }

        return $files;
    }

    /** Top-level file part names matching `base_{int}`. */
    private function baseFileKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->allFiles()),
            fn ($key) => preg_match('/^base_\d+$/', (string) $key) === 1,
        ));
    }
}
