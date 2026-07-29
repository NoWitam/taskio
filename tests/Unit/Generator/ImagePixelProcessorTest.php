<?php

namespace Tests\Unit\Generator;

use App\Modules\Generator\Services\ImagePixelProcessor;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

/**
 * FIDELITY PROOF for the server pixel processor (R2 sub-stage 2c): each op is fed a synthetic image with
 * KNOWN RGB and its produced pixels are asserted to match the imageOps.ts formula within ±1 — the exact math
 * the browser editor runs, re-expressed as Imagick color-matrix / evaluate / geometry ops. A ≤1-LSB drift
 * (JS `Uint8ClampedArray` rounding vs Imagick's quantum rounding) is visually identical and allowed.
 *
 * Pure Imagick — no Laravel bootstrap — so it stays fast under the memory-scoped suite.
 */
class ImagePixelProcessorTest extends TestCase
{
    private ImagePixelProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick is required for the pixel fidelity proof.');
        }

        $this->processor = new ImagePixelProcessor;
    }

    // ---- helpers ---------------------------------------------------------------

    /** A solid $w×$h image of one RGB colour. */
    private function solid(int $r, int $g, int $b, int $w = 2, int $h = 2): Imagick
    {
        $image = new Imagick;
        $image->newImage($w, $h, new ImagickPixel("rgb($r,$g,$b)"), 'png');
        $image->setImageDepth(8);
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $image->setImageFormat('png');

        return $image;
    }

    /** Apply $op and read pixel (x,y) back through an 8-bit PNG round-trip (the real output path). */
    private function pixelAfter(Imagick $image, string $op, array $params, int $x = 0, int $y = 0): array
    {
        $this->processor->apply($image, $op, $params);

        $reloaded = new Imagick;
        $reloaded->readImageBlob($image->getImageBlob());
        $color = $reloaded->getImagePixelColor($x, $y)->getColor();

        return [$color['r'], $color['g'], $color['b']];
    }

    /** imageOps clamp255 + PHP round → the expected 8-bit channel (compared within ±1). */
    private function expect(float $value): int
    {
        return (int) max(0, min(255, round($value)));
    }

    private function assertChannels(array $expected, array $actual, string $op): void
    {
        foreach ([0, 1, 2] as $c) {
            $this->assertLessThanOrEqual(
                1,
                abs($expected[$c] - $actual[$c]),
                "$op channel $c: expected ~{$expected[$c]}, got {$actual[$c]} (must be within ±1 of imageOps.ts)",
            );
        }
    }

    // ---- colour ops ------------------------------------------------------------

    public function test_grayscale_matches_rec601_luma(): void
    {
        [$r, $g, $b] = [100, 150, 200];
        $y = 0.299 * $r + 0.587 * $g + 0.114 * $b; // 140.75

        $actual = $this->pixelAfter($this->solid($r, $g, $b), 'grayscale', []);

        $this->assertChannels([$this->expect($y), $this->expect($y), $this->expect($y)], $actual, 'grayscale');
    }

    public function test_sepia_matches_the_imageops_matrix(): void
    {
        [$r, $g, $b] = [100, 150, 200];
        $expected = [
            $this->expect(0.393 * $r + 0.769 * $g + 0.189 * $b),
            $this->expect(0.349 * $r + 0.686 * $g + 0.168 * $b),
            $this->expect(0.272 * $r + 0.534 * $g + 0.131 * $b),
        ];

        $this->assertChannels($expected, $this->pixelAfter($this->solid($r, $g, $b), 'sepia', []), 'sepia');
    }

    public function test_sepia_clamps_an_overflowing_channel(): void
    {
        // White overflows R and G (coeff sums > 1) → clamp to 255; B ≈ 0.937·255 = 239.
        $this->assertChannels([255, 255, $this->expect(0.937 * 255)], $this->pixelAfter($this->solid(255, 255, 255), 'sepia', []), 'sepia(white)');
    }

    public function test_invert_negates_each_channel(): void
    {
        $this->assertChannels([155, 105, 55], $this->pixelAfter($this->solid(100, 150, 200), 'invert', []), 'invert');
    }

    public function test_warm_lifts_red_drops_blue_by_18(): void
    {
        $this->assertChannels([118, 150, 182], $this->pixelAfter($this->solid(100, 150, 200), 'warm', []), 'warm');
    }

    public function test_warm_clamps_at_the_edges(): void
    {
        // R 250+18 → 255; B 10-18 → 0.
        $this->assertChannels([255, 150, 0], $this->pixelAfter($this->solid(250, 150, 10), 'warm', []), 'warm(clamp)');
    }

    public function test_cool_drops_red_lifts_blue_by_18(): void
    {
        $this->assertChannels([82, 150, 218], $this->pixelAfter($this->solid(100, 150, 200), 'cool', []), 'cool');
    }

    public function test_brightness_shift_matches_the_js_mapping(): void
    {
        // amount 40 → shift round(40 * 2.55) = 102.
        $shift = (int) floor(40 * 2.55 + 0.5);
        [$r, $g, $b] = [100, 150, 200];
        $expected = [$this->expect($r + $shift), $this->expect($g + $shift), $this->expect($b + $shift)];

        $this->assertChannels($expected, $this->pixelAfter($this->solid($r, $g, $b), 'brightness', ['amount' => 40]), 'brightness');
    }

    public function test_negative_brightness_darkens_and_clamps(): void
    {
        // amount -60 → shift round(-60 * 2.55) = -153.
        $shift = (int) floor(-60 * 2.55 + 0.5);
        [$r, $g, $b] = [200, 100, 20];
        $expected = [$this->expect($r + $shift), $this->expect($g + $shift), $this->expect($b + $shift)];

        $this->assertChannels($expected, $this->pixelAfter($this->solid($r, $g, $b), 'brightness', ['amount' => -60]), 'brightness(-)');
    }

    public function test_contrast_scales_around_the_midpoint(): void
    {
        $amount = 50;
        $factor = (259 * ($amount + 255)) / (255 * (259 - $amount));
        [$r, $g, $b] = [100, 150, 200];
        $expected = [
            $this->expect($factor * ($r - 128) + 128),
            $this->expect($factor * ($g - 128) + 128),
            $this->expect($factor * ($b - 128) + 128),
        ];

        $this->assertChannels($expected, $this->pixelAfter($this->solid($r, $g, $b), 'contrast', ['amount' => $amount]), 'contrast');
    }

    public function test_saturation_pushes_channels_away_from_luma(): void
    {
        $amount = 50;
        $factor = 1 + $amount / 100.0;
        [$r, $g, $b] = [100, 150, 200];
        $gray = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        $expected = [
            $this->expect($gray + ($r - $gray) * $factor),
            $this->expect($gray + ($g - $gray) * $factor),
            $this->expect($gray + ($b - $gray) * $factor),
        ];

        $this->assertChannels($expected, $this->pixelAfter($this->solid($r, $g, $b), 'saturation', ['amount' => $amount]), 'saturation');
    }

    public function test_desaturation_toward_grey(): void
    {
        // amount -100 → factor 0 → every channel collapses to the pixel's luma.
        [$r, $g, $b] = [100, 150, 200];
        $gray = $this->expect(0.299 * $r + 0.587 * $g + 0.114 * $b);

        $this->assertChannels([$gray, $gray, $gray], $this->pixelAfter($this->solid($r, $g, $b), 'saturation', ['amount' => -100]), 'saturation(-100)');
    }

    // ---- geometry --------------------------------------------------------------

    public function test_crop_maps_fractions_to_pixels(): void
    {
        $image = $this->solid(10, 20, 30, 4, 4);
        $this->processor->apply($image, 'crop', ['rect' => ['x' => 0.25, 'y' => 0.5, 'w' => 0.5, 'h' => 0.5]]);

        // sx=round(.25*4)=1, sy=round(.5*4)=2, sw=max(1,round(.5*4))=2, sh=2.
        $this->assertSame(2, $image->getImageWidth());
        $this->assertSame(2, $image->getImageHeight());
    }

    public function test_crop_keeps_at_least_one_pixel_per_edge(): void
    {
        $image = $this->solid(10, 20, 30, 4, 4);
        $this->processor->apply($image, 'crop', ['rect' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0]]);

        $this->assertSame(1, $image->getImageWidth());
        $this->assertSame(1, $image->getImageHeight());
    }

    public function test_rotate_odd_quarter_turns_swap_dimensions(): void
    {
        $image = $this->solid(10, 20, 30, 4, 2);
        $this->processor->apply($image, 'rotate', ['quarterTurns' => 1]);

        $this->assertSame(2, $image->getImageWidth());
        $this->assertSame(4, $image->getImageHeight());
    }

    public function test_rotate_180_keeps_dimensions(): void
    {
        $image = $this->solid(10, 20, 30, 4, 2);
        $this->processor->apply($image, 'rotate', ['quarterTurns' => 2]);

        $this->assertSame(4, $image->getImageWidth());
        $this->assertSame(2, $image->getImageHeight());
    }

    public function test_flip_horizontal_mirrors_left_to_right(): void
    {
        $image = $this->twoTone(); // left red (0,0), right blue (1,0)
        $this->processor->apply($image, 'flip', ['axis' => 'horizontal']);

        $left = $image->getImagePixelColor(0, 0)->getColor();
        $this->assertSame(0, $left['r'], 'after a horizontal flip the left pixel is the former (blue) right pixel');
        $this->assertSame(255, $left['b']);
    }

    public function test_flip_vertical_mirrors_top_to_bottom(): void
    {
        $image = $this->twoTone(vertical: true); // top red (0,0), bottom blue (0,1)
        $this->processor->apply($image, 'flip', ['axis' => 'vertical']);

        $top = $image->getImagePixelColor(0, 0)->getColor();
        $this->assertSame(0, $top['r'], 'after a vertical flip the top pixel is the former (blue) bottom pixel');
        $this->assertSame(255, $top['b']);
    }

    public function test_unknown_op_is_a_safe_no_op(): void
    {
        $this->assertChannels([100, 150, 200], $this->pixelAfter($this->solid(100, 150, 200), 'does_not_exist', []), 'unknown');
    }

    /** A 2px image, one red pixel and one blue pixel, laid out horizontally (default) or vertically. */
    private function twoTone(bool $vertical = false): Imagick
    {
        $image = new Imagick;
        $image->newImage($vertical ? 1 : 2, $vertical ? 2 : 1, new ImagickPixel('black'), 'png');
        $image->setImageFormat('png');

        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('rgb(255,0,0)'));
        $draw->point(0, 0);
        $draw->setFillColor(new ImagickPixel('rgb(0,0,255)'));
        $draw->point($vertical ? 0 : 1, $vertical ? 1 : 0);
        $image->drawImage($draw);

        return $image;
    }
}
