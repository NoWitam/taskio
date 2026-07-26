<?php

namespace App\Modules\Disk\Support;

/**
 * Rasterizes the first page of a PDF to a small PNG. Extracted behind an interface so the
 * byte-producing step (an out-of-process `pdftoppm` call in production) can be swapped for a fake in
 * tests — the thumbnail feature is then exercised without depending on poppler being installed.
 */
interface PdfRasterizer
{
    /**
     * Render the FIRST page of $pdfBytes to PNG bytes, or null on ANY failure (not a PDF, the binary
     * is missing, a timeout, an empty result). Never throws.
     */
    public function rasterizeFirstPage(string $pdfBytes): ?string;
}
