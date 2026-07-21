<?php

namespace App\Modules\Disk\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client over OpenAI's `images/edits` endpoint (multipart) for the Disk preview editor.
 *
 * Bypasses laravel/ai deliberately: that wrapper cannot send a MASK, which is required for real
 * inpainting / object removal / background replace (see the disk-image ADR). The source image is
 * posted as a PNG part; an optional mask PNG marks — with its TRANSPARENT pixels — the region the
 * model may repaint. Without a mask the whole image is edited to the prompt. Returns the edited
 * image as base64 + mime (gpt-image-* always returns PNG).
 *
 * Inputs are raw PNG BYTES (not an UploadedFile): the edit runs on a queue worker that reads the
 * persisted upload from storage, long after the request's temp file is gone.
 *
 * Verified contract (account spike): model `gpt-image-1`, `size=auto`, response `data.0.b64_json`.
 */
class OpenAiImageEditClient
{
    /**
     * @param  string  $image  raw PNG bytes of the source image
     * @param  string|null  $mask  raw PNG bytes of the optional inpainting mask
     * @return array{image: string, mime: string} base64-encoded edited image + its mime
     *
     * @throws RuntimeException on missing config or a non-2xx / empty provider response
     */
    public function edit(string $image, string $prompt, ?string $mask = null): array
    {
        $key = (string) config('ai.providers.openai.key');
        if ($key === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }
        $base = rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/');

        $request = Http::withToken($key)
            ->timeout((int) config('ai.image_timeout'))
            ->attach('image', $image, 'image.png');

        if ($mask !== null) {
            $request = $request->attach('mask', $mask, 'mask.png');
        }

        // Tuning for a clean localized edit (object removal / area replace):
        //  • input_fidelity=high — stay faithful to the source (unmasked area + surrounding context).
        //  • quality=high        — reconstruct real detail in the masked region, not a flat, washed patch.
        //  • background=opaque   — fill the region with SOLID pixels, never a transparent cutout that
        //                          shows through as a see-through patch once composited.
        $response = $request->post($base . '/images/edits', array_filter([
            'model' => (string) config('ai.disk_image_model', 'gpt-image-1'),
            'prompt' => $prompt,
            'size' => 'auto',
            'quality' => (string) config('ai.disk_image_quality', 'high'),
            'background' => (string) config('ai.disk_image_background', 'opaque'),
            'n' => 1,
        ], fn ($value) => $value !== ''));

        if (!$response->successful()) {
            // Terse, non-secret message — the caller collapses this to a localized 502 and never
            // surfaces the raw provider body to the client.
            throw new RuntimeException('OpenAI images/edits failed with status ' . $response->status() . '.');
        }

        $b64 = $response->json('data.0.b64_json');
        if (!is_string($b64) || $b64 === '') {
            throw new RuntimeException('OpenAI images/edits returned no image.');
        }

        return ['image' => $b64, 'mime' => 'image/png'];
    }
}
