<?php

namespace App\Modules\Disk\Services;

use App\Modules\Variables\Contracts\MeteredAiCall;
use Laravel\Ai\Image;

/**
 * Text→image GENERATION for the server-side generator (R2 sub-stage 6): turn a resolved text prompt into
 * raw image bytes through laravel/ai's {@see Image} API. AI-image provider concerns live in Disk (the same
 * place the masked {@see ImageAiService} edit client lives), and Generator → Disk is the already-documented
 * allowed edge — so the Generator's image chain reaches HERE for a fresh base rather than owning a provider.
 *
 * Unlike the Disk preview EDIT (which is slow, queued, mask-bearing, and rides a custom OpenAI client that
 * laravel/ai cannot express), a plain text→image generation is a single synchronous provider call with no
 * mask, so it uses laravel/ai directly and runs INLINE inside the already-async generation session job (the
 * session run IS the async unit — no nested queue).
 *
 * METERING POSTURE mirrors {@see ImageAiService::edit()}: the provider call is wrapped in the shared cost
 * METER ({@see MeteredAiCall}, the one-way Disk → Variables edge). The meter GATES BEFORE SPEND on the
 * workspace's calendar-month token budget (an over-cap workspace throws before the provider is hit) and
 * RECORDS the spend as the configured `ai_image_generate` unit — the response carries no provider token
 * count. The ambient MeterContext session tag the generation session executor sets rides the call, so the
 * recorded spend is attributed to that session. The prompt is DATA and is NEVER logged.
 *
 * A provider/transport failure surfaces as laravel/ai's own exception; the caller (the image chain) turns
 * it into a fail-soft `failed` image part with a localized, non-secret message.
 */
class ImageGenerateService
{
    public function __construct(
        private MeteredAiCall $meter,
    ) {}

    /**
     * Generate an image from a text prompt and return its raw bytes. The prompt is the ALREADY-RESOLVED
     * base prompt (the caller expanded any `@[variable]`/slots through the session resolver first) — DATA,
     * never logged. A square aspect (a social post default) + a config quality, sent to the config-pinned
     * provider (never laravel/ai's unkeyed `default_for_images`); the HTTP timeout reuses the shared image
     * timeout. `(string)` on the laravel/ai image response yields the first image's raw bytes
     * (its GeneratedImage decodes its base64). Nominal mime is PNG (the chain re-encodes to PNG regardless).
     *
     * @return array{bytes: string, mime: string}
     */
    public function generate(string $prompt): array
    {
        // Route the spend through the shared meter: it GATES BEFORE SPEND on the monthly token budget (an
        // over-cap throw here is turned into a fail-soft part by the caller) and RECORDS the spend as the
        // configured ai_image_generate unit (the response carries no provider token count). The provider is
        // passed EXPLICITLY (config('ai.image_generate_provider'), default openai) so the call never falls back
        // to laravel/ai's package default_for_images ('gemini' — unkeyed here); the provider's default image
        // model (gpt-image-1.5 for openai) handles the square 1:1 size + configured quality.
        $bytes = $this->meter->meter('ai_image_generate', fn (): string => (string) Image::of($prompt)
            ->quality((string) config('ai.image_generate_quality', 'high'))
            ->square()
            ->timeout((int) config('ai.image_timeout'))
            ->generate(config('ai.image_generate_provider')));

        return ['bytes' => $bytes, 'mime' => 'image/png'];
    }
}
