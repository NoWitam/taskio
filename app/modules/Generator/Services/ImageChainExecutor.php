<?php

namespace App\Modules\Generator\Services;

use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Disk\Services\ImageGenerateService;
use App\Modules\Generator\Exceptions\ImageEditBudgetExceeded;
use App\Modules\Generator\Exceptions\ImageGenerateBudgetExceeded;
use Illuminate\Support\Facades\Log;
use Imagick;

/**
 * Executes one image plan SERVER-SIDE (R2 sub-stage 2c): resolve the base → run the ordered filter chain
 * (deterministic pixel ops ∪ AI edits) → return the produced PNG bytes + dimensions. Runs SYNCHRONOUSLY
 * inside the already-async {@see \App\Modules\Generator\Jobs\RunGenerationSessionJob}, so a headless/bot
 * session runs the SAME full chain the interactive editor would.
 *
 *   - base            {@see ImageBaseResolver} → raw bytes (the deliberate Generator → Disk read edge) for a
 *                     `disk_file`/`from_slot` base. An `ai_generate` (text→image) base is intercepted HERE
 *                     (see {@see generateBase}): the prompt is resolved through $resolvePrompt, then a
 *                     SYNCHRONOUS {@see ImageGenerateService::generate()} — metered (channel `ai_image_generate`)
 *                     and session-tagged via the ambient MeterContext, the same posture as an ai_edit.
 *   - pixel filter    {@see ImagePixelProcessor} — an exact Imagick equivalent of the imageOps math.
 *   - ai_edit filter  the prompt markdown resolved through the SAME session resolver ($resolvePrompt), then a
 *                     SYNCHRONOUS {@see ImageAiService::edit()} — metered (channel `ai_image_edit`) and
 *                     session-tagged via the ambient MeterContext the session executor already set. No nested
 *                     queue: the session run IS the async unit.
 *
 * A base/mask/provider failure surfaces as a thrown exception the session executor catches PER PART (the part
 * is `failed`; every other part still runs). The ONE exception is an exhausted per-run `ai_edit` BUDGET during
 * the chain: that step is SKIPPED and the chain continues, so the part still yields an image, just without
 * that filter (see {@see execute}). The working image is capped to `generator.image_max_edge` on its longest
 * edge BEFORE the chain to bound cost. Output is always PNG.
 *
 * BUDGET SCOPE: {@see $aiEdits} (the per-session `ai_edit` call budget) and {@see $aiGenerations} (the
 * per-session `ai_generate` base budget) are INSTANCE state. The executor is a plain container concrete (NOT
 * a singleton), resolved fresh with GenerationSessionExecutor for each session run, so each counter naturally
 * scopes to ONE run yet accumulates across every image part WITHIN that run (they share the one instance). Do
 * NOT bind it as a singleton, or the budgets would leak across runs. This mirrors the ai-text decorator's
 * per-session $calls.
 */
class ImageChainExecutor
{
    /** `ai_edit` provider calls made so far in this session run (see BUDGET SCOPE above). */
    private int $aiEdits = 0;

    /** `ai_generate` (text→image base) provider calls made so far in this session run (see BUDGET SCOPE above). */
    private int $aiGenerations = 0;

    public function __construct(
        private ImageBaseResolver $baseResolver,
        private ImagePixelProcessor $pixels,
        private ImageAiService $imageAi,
        private ImageGenerateService $imageGenerate,
    ) {}

    /**
     * Produce the image for $plan. $resolvePrompt resolves an ai_edit / base prompt markdown through the
     * session's resolver + context (the SAME `@[variable]`/`slots.*` engine a body uses).
     *
     * @param  array<string, mixed>  $plan  the image plan config (`{base, filters?}`)
     * @param  array<string, mixed>  $slotValues  the session's filled slot values (for a `from_slot` base / mask)
     * @param  callable(string): string  $resolvePrompt
     * @return array{bytes: string, mime: string, width: int, height: int}
     */
    public function execute(array $plan, array $slotValues, callable $resolvePrompt): array
    {
        $base = is_array($plan['base'] ?? null) ? $plan['base'] : [];

        // An ai_generate (text→image) base is intercepted BEFORE the resolver: it needs the session prompt
        // resolver + the metered generate provider call, neither of which the file-reading resolver owns.
        // Every other base kind (disk_file / from_slot) still resolves to Disk bytes as before.
        $resolved = ($base['kind'] ?? null) === 'ai_generate'
            ? $this->generateBase($base, $resolvePrompt)
            : $this->baseResolver->resolve($base, $slotValues);

        $image = new Imagick;
        $image->readImageBlob($resolved['bytes']);
        $this->capToMaxEdge($image);

        $filters = is_array($plan['filters'] ?? null) ? $plan['filters'] : [];
        $skipped = 0;

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            try {
                $image = $this->applyFilter($image, $filter, $slotValues, $resolvePrompt);
            } catch (ImageEditBudgetExceeded) {
                // GRACEFUL DEGRADATION (the ONLY swallowed failure here): the per-run `ai_edit` budget is
                // exhausted, so this filter step is SKIPPED and the chain CONTINUES on the untouched working
                // image (the guard fires before any mutation, so it is still valid). The part therefore yields
                // a REAL image without that filter instead of nothing at all — which matters most for a
                // storyboard, where ONE authored `ai_edit` applies to EVERY shot and the shared counter would
                // otherwise turn the tail of the shot list into `failed` frames (a listed beat with no image
                // reads as a bug, not as a budget). The budget itself is still hard: no extra provider call is
                // made. Every OTHER failure (provider/transport/mask/base) still propagates for the caller's
                // per-part fail-soft catch — degradation is for the BUDGET case only.
                $skipped++;
            }
        }

        if ($skipped > 0) {
            // The FACT only — never the prompt/filter config (which may carry slot values).
            Log::warning('Generator image chain: per-session ai_edit budget exhausted; filter steps skipped, image produced without them.', [
                'skipped_filters' => $skipped,
                'budget' => $this->maxAiEdits(),
            ]);
        }

        $image->setImageFormat('png');
        $bytes = $image->getImageBlob();
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $image->clear();

        return ['bytes' => $bytes, 'mime' => 'image/png', 'width' => $width, 'height' => $height];
    }

    /**
     * Refine (R2 sub-stage 2d): AI-EDIT already-produced image bytes with an ALREADY-RESOLVED instruction — a
     * REVISION of the CURRENT output, not a re-run of a plan. No base/filter chain: the current bytes ARE the
     * base and the instruction IS the single `ai_edit` prompt. Reuses the SAME metered, budgeted
     * {@see aiEdit} seam (channel `ai_image_edit`, session-tagged by the caller's MeterContext), the same
     * max-edge cap, and returns produced PNG bytes + dimensions. The prompt is passed pre-resolved (the
     * session executor already expanded any `@[variable]`/slots through the shared resolver) so the passthrough
     * here does not re-resolve; the instruction is DATA, never logged. Throws {@see ImageEditBudgetExceeded} /
     * a provider failure the caller turns into a fail-soft part. Deliberately does NOT take {@see execute}'s
     * skip-and-continue degradation: the edit IS the whole operation here, so skipping it would silently
     * "succeed" with an unchanged image and churn a pointless new version — a refine must fail as a no-op.
     *
     * @return array{bytes: string, mime: string, width: int, height: int}
     */
    public function editImage(string $bytes, string $resolvedPrompt): array
    {
        $image = new Imagick;
        $image->readImageBlob($bytes);
        $this->capToMaxEdge($image);

        $image = $this->aiEdit($image, ['prompt' => $resolvedPrompt], [], fn (string $prompt): string => $prompt);

        $image->setImageFormat('png');
        $produced = [
            'bytes' => $image->getImageBlob(),
            'mime' => 'image/png',
            'width' => $image->getImageWidth(),
            'height' => $image->getImageHeight(),
        ];
        $image->clear();

        return $produced;
    }

    /**
     * Apply one filter, returning the working image (an ai_edit REPLACES it with the provider result). An
     * unknown filter kind is skipped (write-validated).
     *
     * @param  array<string, mixed>  $filter
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     */
    private function applyFilter(Imagick $image, array $filter, array $slotValues, callable $resolvePrompt): Imagick
    {
        if (($filter['kind'] ?? null) === 'pixel') {
            $params = is_array($filter['params'] ?? null) ? $filter['params'] : [];
            $this->pixels->apply($image, (string) ($filter['op'] ?? ''), $params);

            return $image;
        }

        if (($filter['kind'] ?? null) === 'ai_edit') {
            return $this->aiEdit($image, $filter, $slotValues, $resolvePrompt);
        }

        return $image;
    }

    /**
     * Run an ai_edit: enforce the per-session budget, resolve its prompt through the session resolver, encode
     * the current image to PNG, and call the SYNCHRONOUS metered provider ({@see ImageAiService::edit()}
     * returns base64 PNG). The result becomes the new working image. The MeterContext session tag set by the
     * session executor rides this call, so the recorded `ai_image_edit` spend is attributed to the session.
     *
     * The budget guard fires BEFORE any prompt/mask resolution or provider call: an over-budget edit throws
     * {@see ImageEditBudgetExceeded}, never a wasted call or spend. The guard does NOT touch the working
     * image, so the CALLER decides what the refusal means: {@see execute} SKIPS the step and keeps going
     * (graceful degradation), while {@see editImage} — a refine, where the edit IS the whole operation and
     * there is nothing to degrade to — lets it bubble into the caller's fail-soft, no-op catch. Both log the
     * fact at their own site; the prompt (which may carry slot values) is never logged.
     *
     * @param  array<string, mixed>  $filter
     * @param  array<string, mixed>  $slotValues
     * @param  callable(string): string  $resolvePrompt
     */
    private function aiEdit(Imagick $image, array $filter, array $slotValues, callable $resolvePrompt): Imagick
    {
        if ($this->aiEdits >= $this->maxAiEdits()) {
            throw new ImageEditBudgetExceeded;
        }

        $this->aiEdits++;

        $prompt = $resolvePrompt(is_string($filter['prompt'] ?? null) ? $filter['prompt'] : '');
        $mask = $this->baseResolver->resolveMask($filter['mask'] ?? null, $slotValues);

        $image->setImageFormat('png');
        $source = $image->getImageBlob();
        $image->clear();

        $result = $this->imageAi->edit($source, $prompt, $mask);

        $edited = new Imagick;
        $edited->readImageBlob(base64_decode($result['image']));

        return $edited;
    }

    /**
     * Resolve an `ai_generate` base to raw bytes: enforce the per-session GENERATE budget, resolve the base
     * prompt through the session resolver, and call the SYNCHRONOUS metered {@see ImageGenerateService::generate()}
     * (channel `ai_image_generate`, session-tagged by the ambient MeterContext). The bytes become the chain's
     * starting image, on which the pixel/ai_edit filters then run.
     *
     * The budget guard fires BEFORE any prompt resolution or provider call: an over-budget generate throws
     * {@see ImageGenerateBudgetExceeded} (fail-soft — the session executor fails ONLY this part), never a
     * wasted call or spend. Only the guarding FACT is logged, never the prompt (which may carry slot values).
     *
     * @param  array<string, mixed>  $base
     * @param  callable(string): string  $resolvePrompt
     * @return array{bytes: string, mime: string}
     */
    private function generateBase(array $base, callable $resolvePrompt): array
    {
        if ($this->aiGenerations >= $this->maxAiGenerations()) {
            Log::warning('Generator image chain: per-session ai_generate budget reached; image part failed.');

            throw new ImageGenerateBudgetExceeded;
        }

        $this->aiGenerations++;

        $prompt = $resolvePrompt(is_string($base['prompt'] ?? null) ? $base['prompt'] : '');

        return $this->imageGenerate->generate($prompt);
    }

    /**
     * Cap the working image to `generator.image_max_edge` on its longest edge BEFORE the chain — bounds the
     * cost of the pixel ops (and the bytes sent to a provider), consistent with the editor's cap. Never
     * upscales; a cap of 0 disables it.
     */
    private function capToMaxEdge(Imagick $image): void
    {
        $cap = (int) config('generator.image_max_edge', 2048);

        if ($cap <= 0) {
            return;
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $longest = max($width, $height);

        if ($longest <= $cap) {
            return;
        }

        $scale = $cap / $longest;
        $image->scaleImage(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
    }

    /**
     * The per-session `ai_edit` call ceiling (cost + timeout guard); config-driven. The literal fallback MUST
     * equal the shipped `config/generator.php` default, which is itself kept in LOCK-STEP with
     * `generator.storyboard_max_shots` — ONE authored storyboard `ai_edit` filter applies to EVERY shot, so a
     * lower value would degrade the tail of a full storyboard to unfiltered frames. Pinned by
     * GeneratorBudgetFallbackTest.
     */
    private function maxAiEdits(): int
    {
        return (int) config('generator.image_edit_max_calls_per_session', 8);
    }

    /**
     * The per-session `ai_generate` base call ceiling (cost + timeout guard); config-driven. The literal
     * fallback MUST equal the shipped `config/generator.php` default, which is itself kept in LOCK-STEP with
     * `generator.storyboard_max_shots` — a lower value silently returns the last shots of a storyboard
     * frameless, which reads as a bug rather than as a budget. Pinned by GeneratorBudgetFallbackTest.
     */
    private function maxAiGenerations(): int
    {
        return (int) config('generator.image_generate_max_calls_per_session', 8);
    }
}
