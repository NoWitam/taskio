<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Exceptions\ImageSafetyRejectedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 *
 * `input_fidelity` IS SENT ON EVERY EDIT THIS CLIENT MAKES — not only the preview editor's own inpainting.
 * Everything that rides this client inherits it: a Disk mask edit, a bot's visual-identity generation from a
 * reference, and a generation session's character frames. That is the intent (all of them want the source's
 * face and detail preserved), but it is worth stating, because it is a per-call PRICE and quality knob that
 * no caller opts into.
 *
 * IT IS gpt-image-1 SPECIFIC. Point `ai.disk_image_model` at another model and the parameter has to go with
 * it, or the provider rejects the request outright. The switch is `AI_DISK_IMAGE_INPUT_FIDELITY=` — an EMPTY
 * value, which the array_filter below drops from the payload entirely (the same empty-value-omits rule every
 * tuning knob here follows), NOT the string "none".
 */
class OpenAiImageEditClient
{
    /**
     * @param  string  $image  raw PNG bytes of the source image
     * @param  string|null  $mask  raw PNG bytes of the optional inpainting mask
     * @param  string  $size  output size; `auto` (the default, i.e. today's behavior) lets the
     *                        provider choose — and it does NOT mean "same as the input": a square
     *                        source can come back 1024x1536. Passing an explicit `WxH` is the seam
     *                        for pinning an aspect per use (a portrait avatar, a feed image).
     * @return array{image: string, mime: string} base64-encoded edited image + its mime
     *
     * @throws ImageSafetyRejectedException when the provider's safety system refused the content
     * @throws RuntimeException on missing config or a non-2xx / empty provider response
     */
    public function edit(string $image, string $prompt, ?string $mask = null, string $size = 'auto'): array
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

        // Tuning for a clean localized edit (object removal / area replace). Every knob is config-driven
        // (`ai.disk_image_*`, env-overridable); an EMPTY config value drops the param from the payload so
        // the provider default applies:
        //  • input_fidelity=high — stay faithful to the source: preserves the input's faces/detail and the
        //                          unmasked area + surrounding context. gpt-image-1 only.
        //  • quality=high        — reconstruct real detail in the masked region, not a flat, washed patch.
        //  • background=opaque   — fill the region with SOLID pixels, never a transparent cutout that
        //                          shows through as a see-through patch once composited.
        $response = $request->post($base . '/images/edits', array_filter([
            'model' => (string) config('ai.disk_image_model', 'gpt-image-1'),
            'prompt' => $prompt,
            'size' => $size,
            'input_fidelity' => (string) config('ai.disk_image_input_fidelity', 'high'),
            'quality' => (string) config('ai.disk_image_quality', 'high'),
            'background' => (string) config('ai.disk_image_background', 'opaque'),
            'n' => 1,
        ], fn ($value) => $value !== ''));

        if (!$response->successful()) {
            // SAFETY REJECTION first: the provider renders the image, then refuses to hand it over
            // because its own moderation refused the content. Deterministic (a retry buys another
            // refusal) and the user's to fix, so it gets its own type — and, unlike the branch
            // below, its body is NOT logged: a moderation error echoes the offending content, and
            // the prompt is user data that must never reach the logs. The CODE and the fact only.
            if (ImageSafetyRejectedException::isRejection($response->json())) {
                Log::warning('OpenAI images/edits refused by provider moderation', [
                    'status' => $response->status(),
                    'code' => (string) data_get($response->json(), 'error.code'),
                ]);

                throw new ImageSafetyRejectedException('OpenAI images/edits refused the content (moderation_blocked).');
            }

            // Log the provider's OWN error (status + body) so a swallowed edit failure is diagnosable — the
            // body carries the REAL reason (an unknown/no-access model, an oversized/invalid image, a bad
            // param). It is OpenAI's error text, never the API key, so it is safe to log server-side.
            Log::warning('OpenAI images/edits failed', [
                'status' => $response->status(),
                'model' => (string) config('ai.disk_image_model', 'gpt-image-1'),
                'body' => $response->body(),
            ]);

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
