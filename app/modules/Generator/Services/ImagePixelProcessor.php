<?php

namespace App\Modules\Generator\Services;

use Imagick;
use ImagickPixel;

/**
 * The SERVER-SIDE pixel processor (R2 sub-stage 2c). It applies ONE deterministic `pixel` op to a working
 * {@see Imagick} image, FAITHFULLY reproducing the browser editor's math ({@see resources/js/next/pages/disk/imageOps.ts}
 * — the authority) so a headless/bot run gets pixel-for-pixel the same result the interactive editor would.
 *
 * FIDELITY: every op is an EXACT linear per-channel / luma transform expressed as an Imagick
 * `colorMatrixImage` (order-6 RGBA+offset), `evaluateImage` (additive channel shift) or geometry op — NOT a
 * slow per-pixel loop, and NOT Imagick's approximate built-ins (e.g. its default Rec.709 grayscale). The
 * ImageMagick build here is HDRI, so a color/evaluate op can overflow the quantum range; each is followed by
 * {@see Imagick::clampImage()} to reproduce imageOps' `Uint8ClampedArray` [0,255] clamp. Minor ≤1-LSB
 * rounding differences vs the JS path are visually identical and covered by the ±1 fidelity tests.
 *
 * DEFENSIVE: params were already write-validated ({@see ImagePlanValidator}), but this clamps them again and
 * NEVER throws on a bad op/param — a malformed-but-write-validated plan must not 500 a run. An unknown op is
 * a no-op. The op→Imagick mapping lives in ONE switch; it reuses the imageOps op VOCABULARY only, re-expressing
 * the math here (no dependency on the Disk editor).
 */
class ImagePixelProcessor
{
    /** Rec.601 luma weights — the perceptual grey imageOps.ts uses (NOT Imagick's default Rec.709). */
    private const LUMA_R = 0.299;

    private const LUMA_G = 0.587;

    private const LUMA_B = 0.114;

    /** The warm/cool fixed channel cast (imageOps warm/cool = ±18 on R/B). */
    private const CAST = 18;

    /**
     * Apply one pixel op IN PLACE. $op is a member of the imageOps vocabulary
     * ({@see ImagePlanValidator::PIXEL_OPS}); $params are the op's write-validated params (clamped here).
     * Unknown ops / bad params are safe no-ops.
     *
     * @param  array<string, mixed>  $params
     */
    public function apply(Imagick $image, string $op, array $params): void
    {
        match ($op) {
            'grayscale' => $this->colorMatrix($image, $this->grayscaleMatrix()),
            'sepia' => $this->colorMatrix($image, $this->sepiaMatrix()),
            'invert' => $image->negateImage(false),
            'warm' => $this->channelShift($image, self::CAST, -self::CAST),
            'cool' => $this->channelShift($image, -self::CAST, self::CAST),
            'brightness' => $this->brightness($image, $this->amount($params)),
            'contrast' => $this->colorMatrix($image, $this->contrastMatrix($this->amount($params))),
            'saturation' => $this->colorMatrix($image, $this->saturationMatrix($this->amount($params))),
            'crop' => $this->crop($image, $params),
            'rotate' => $this->rotate($image, $params),
            'flip' => $this->flip($image, $params),
            default => null, // unknown op: no-op (write-validated; execution must never throw)
        };
    }

    // ---- color ops (colorMatrix / evaluate, always clamped) --------------------------------

    /**
     * imageOps.grayscale — every RGB output channel = Rec.601 luma of the pixel. A color matrix whose three
     * RGB rows all carry the 601 weights.
     *
     * @return array<int, float>
     */
    private function grayscaleMatrix(): array
    {
        $luma = [self::LUMA_R, self::LUMA_G, self::LUMA_B, 0.0];

        return $this->rgbMatrix([$luma, $luma, $luma]);
    }

    /**
     * imageOps.sepia — the classic warm sepia matrix (exact coefficients).
     *
     * @return array<int, float>
     */
    private function sepiaMatrix(): array
    {
        return $this->rgbMatrix([
            [0.393, 0.769, 0.189, 0.0],
            [0.349, 0.686, 0.168, 0.0],
            [0.272, 0.534, 0.131, 0.0],
        ]);
    }

    /**
     * imageOps.contrast — `factor = 259*(amount+255)/(255*(259-amount))`, `v' = factor*(v-128)+128`, i.e. a
     * per-channel scale `factor` with an offset `128 - 128*factor`. The offset is on the 0..255 scale
     * (normalized by {@see rgbMatrix}).
     *
     * @return array<int, float>
     */
    private function contrastMatrix(float $amount): array
    {
        $factor = (259 * ($amount + 255)) / (255 * (259 - $amount));
        $offset = 128.0 - 128.0 * $factor;

        return $this->rgbMatrix([
            [$factor, 0.0, 0.0, $offset],
            [0.0, $factor, 0.0, $offset],
            [0.0, 0.0, $factor, $offset],
        ]);
    }

    /**
     * imageOps.saturation — `factor = 1 + amount/100`, `v'_c = gray + (v_c - gray)*factor` with `gray` the
     * Rec.601 luma. Expanded per channel: `v'_c = factor*v_c + (1-factor)*(lr*R + lg*G + lb*B)` — the standard
     * luma-preserving saturation matrix (0 offset).
     *
     * @return array<int, float>
     */
    private function saturationMatrix(float $amount): array
    {
        $factor = 1 + $amount / 100.0;
        $inv = 1 - $factor;
        $luma = [self::LUMA_R, self::LUMA_G, self::LUMA_B];

        $rows = [];
        for ($channel = 0; $channel < 3; $channel++) {
            $row = [$inv * $luma[0], $inv * $luma[1], $inv * $luma[2], 0.0];
            $row[$channel] += $factor;
            $rows[] = $row;
        }

        return $this->rgbMatrix($rows);
    }

    /**
     * imageOps.warm / cool — a fixed additive cast: +delta on R, +blueDelta on B (the other sign). Runs as
     * per-channel `evaluateImage` shifts, then clamps (imageOps clamps each channel to [0,255]).
     */
    private function channelShift(Imagick $image, int $redDelta, int $blueDelta): void
    {
        $this->addToChannel($image, Imagick::CHANNEL_RED, $redDelta);
        $this->addToChannel($image, Imagick::CHANNEL_BLUE, $blueDelta);
        $image->clampImage(Imagick::CHANNEL_ALL);
    }

    /**
     * imageOps.brightness — shift every channel by `round(amount * 2.55)` (the JS UI→byte mapping). The shift
     * is computed with `floor(x + 0.5)` to mirror JS `Math.round` EXACTLY (round half toward +∞), so a
     * half-value never drifts a channel by a whole step vs the editor.
     */
    private function brightness(Imagick $image, float $amount): void
    {
        $shift = (int) floor($amount * 2.55 + 0.5); // === JS Math.round(amount * 2.55)

        if ($shift === 0) {
            return;
        }

        $this->addToChannel($image, Imagick::CHANNEL_RED | Imagick::CHANNEL_GREEN | Imagick::CHANNEL_BLUE, $shift);
        $image->clampImage(Imagick::CHANNEL_ALL);
    }

    /** Add a signed 0..255-scale $delta to $channel via evaluateImage (HDRI-safe; the caller clamps). */
    private function addToChannel(Imagick $image, int $channel, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $value = abs($delta) / 255.0 * $this->quantumRange();
        $image->evaluateImage($delta > 0 ? Imagick::EVALUATE_ADD : Imagick::EVALUATE_SUBTRACT, $value, $channel);
    }

    /**
     * Apply an order-6 (RGBA + constant) color matrix, then clamp back into [0, QuantumRange] — the HDRI
     * build does NOT clamp color-matrix output, so this reproduces imageOps' per-channel [0,255] clamp.
     *
     * @param  array<int, float>  $matrix
     */
    private function colorMatrix(Imagick $image, array $matrix): void
    {
        $image->colorMatrixImage($matrix);
        $image->clampImage(Imagick::CHANNEL_ALL);
    }

    /**
     * Build a flat order-6 color matrix from three RGB output rows. Each row is `[rCoef, gCoef, bCoef,
     * offset255]`: the RGB coefficients multiply the input channels, `offset255` is an additive term on the
     * 0..255 scale placed in the constant column (index 5, normalized by /255). Alpha (row/col 3) and the
     * constant row stay identity, so alpha is never touched.
     *
     * ImageMagick color-matrix layout (order 6): columns/rows are R,G,B,alpha,black(unused for RGB),constant.
     *
     * @param  array<int, array<int, float>>  $rows  exactly three rows for R,G,B output
     * @return array<int, float> 36 flat elements
     */
    private function rgbMatrix(array $rows): array
    {
        $matrix = array_fill(0, 36, 0.0);

        // Identity on the diagonal — keeps alpha (3), the unused black channel (4) and the constant (5).
        for ($i = 0; $i < 6; $i++) {
            $matrix[$i * 6 + $i] = 1.0;
        }

        for ($row = 0; $row < 3; $row++) {
            $matrix[$row * 6 + 0] = $rows[$row][0]; // R coefficient
            $matrix[$row * 6 + 1] = $rows[$row][1]; // G coefficient
            $matrix[$row * 6 + 2] = $rows[$row][2]; // B coefficient
            $matrix[$row * 6 + 3] = 0.0;            // alpha never feeds a color channel
            $matrix[$row * 6 + 5] = $rows[$row][3] / 255.0; // additive offset (constant column)
        }

        return $matrix;
    }

    // ---- geometry ops ----------------------------------------------------------------------

    /**
     * imageOps crop — a `{x,y,w,h}` rect of 0..1 fractions → integer source pixels (each edge ≥ 1px, exactly
     * imageOps `cropToPixels`). Defensively kept inside the image bounds so a rounding overshoot can't fault
     * cropImage. The page geometry is reset so the crop is the whole new canvas.
     *
     * @param  array<string, mixed>  $params
     */
    private function crop(Imagick $image, array $params): void
    {
        $rect = is_array($params['rect'] ?? null) ? $params['rect'] : [];
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        $sx = (int) round($this->fraction($rect['x'] ?? 0) * $width);
        $sy = (int) round($this->fraction($rect['y'] ?? 0) * $height);
        $sw = max(1, (int) round($this->fraction($rect['w'] ?? 1) * $width));
        $sh = max(1, (int) round($this->fraction($rect['h'] ?? 1) * $height));

        // Keep the window inside the image (a fractional rect could round past the far edge).
        $sx = max(0, min($sx, $width - 1));
        $sy = max(0, min($sy, $height - 1));
        $sw = min($sw, $width - $sx);
        $sh = min($sh, $height - $sy);

        $image->cropImage($sw, $sh, $sx, $sy);
        $image->setImagePage(0, 0, 0, 0);
    }

    /**
     * imageOps rotate — `quarterTurns` (1..3) 90° clockwise turns. Defensively normalized mod 4 (0 = no-op).
     * A transparent fill matters only if a future non-quarter angle is added; a quarter turn never introduces
     * new pixels.
     *
     * @param  array<string, mixed>  $params
     */
    private function rotate(Imagick $image, array $params): void
    {
        $turns = (((int) ($params['quarterTurns'] ?? 0)) % 4 + 4) % 4;

        if ($turns === 0) {
            return;
        }

        $image->rotateImage(new ImagickPixel('transparent'), 90 * $turns);
        $image->setImagePage(0, 0, 0, 0);
    }

    /**
     * imageOps flip — horizontal = flopImage (mirror L↔R), vertical = flipImage (mirror T↔B). An unknown axis
     * is a no-op.
     *
     * @param  array<string, mixed>  $params
     */
    private function flip(Imagick $image, array $params): void
    {
        match ($params['axis'] ?? null) {
            'horizontal' => $image->flopImage(),
            'vertical' => $image->flipImage(),
            default => null,
        };
    }

    // ---- helpers ---------------------------------------------------------------------------

    /** A tonal `amount` clamped to the imageOps -100..100 UI scale. Non-numeric → 0 (neutral). */
    private function amount(array $params): float
    {
        $amount = $params['amount'] ?? 0;

        if (!is_numeric($amount)) {
            return 0.0;
        }

        return max(-100.0, min(100.0, (float) $amount));
    }

    /** A crop fraction clamped to [0,1]. Non-numeric → 0. */
    private function fraction(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    /** The image's quantum range (65535 on the Q16 build here, 255 on Q8) for evaluate scaling. */
    private function quantumRange(): float
    {
        $range = Imagick::getQuantumRange();

        return (float) ($range['quantumRangeLong'] ?? 65535);
    }
}
