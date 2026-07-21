<?php

namespace Tests\Support;

use App\Modules\Disk\Support\PdfRasterizer;

/**
 * A test double for {@see PdfRasterizer}: returns fixed PNG bytes and counts calls, so the Disk
 * thumbnail feature can be exercised without depending on poppler / `pdftoppm` being installed.
 * Set {@see $png} to null to simulate a rasterization failure.
 */
class FakePdfRasterizer implements PdfRasterizer
{
    /** How many times the rasterizer was invoked (proves cache hits skip it). */
    public int $calls = 0;

    public function __construct(public ?string $png = 'FAKE-PNG-BYTES') {}

    public function rasterizeFirstPage(string $pdfBytes): ?string
    {
        $this->calls++;

        return $this->png;
    }
}
