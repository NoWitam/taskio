<?php

namespace App\Modules\Disk\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Real {@see PdfRasterizer}: shells out to poppler's `pdftoppm` to raster the first PDF page.
 *
 * The bytes are written to a temp file and `pdftoppm` is invoked with ARRAY arguments through
 * Symfony Process (never a shell string), so nothing about the input is ever interpreted by a shell.
 * Any failure — a missing binary, a malformed PDF, a timeout — is logged at DEBUG and collapses to
 * null; this never throws, so the caller can treat "no thumbnail" uniformly.
 */
class PopplerPdfRasterizer implements PdfRasterizer
{
    public function rasterizeFirstPage(string $pdfBytes): ?string
    {
        $binary = (string) config('disk.thumbnails.pdftoppm_path', 'pdftoppm');
        $scale = (int) config('disk.thumbnails.scale', 480);
        $timeout = (int) config('disk.thumbnails.timeout', 20);

        $source = tempnam(sys_get_temp_dir(), 'disk-pdf-');

        if ($source === false) {
            return null;
        }

        // `pdftoppm -singlefile` writes "<prefix>.png"; keep the prefix distinct from the source path
        // so the input and the output can never collide.
        $outputPrefix = $source . '-thumb';
        $outputPng = $outputPrefix . '.png';

        try {
            file_put_contents($source, $pdfBytes);

            $process = new Process([
                $binary, '-png', '-f', '1', '-l', '1', '-singlefile', '-scale-to', (string) $scale, $source, $outputPrefix,
            ]);
            $process->setTimeout($timeout);
            $process->run();

            if (!$process->isSuccessful() || !is_file($outputPng)) {
                Log::debug('Disk thumbnail: pdftoppm did not produce a PNG.', ['exit_code' => $process->getExitCode()]);

                return null;
            }

            $png = file_get_contents($outputPng);

            return is_string($png) && $png !== '' ? $png : null;
        } catch (Throwable $e) {
            Log::debug('Disk thumbnail: rasterization failed.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($source);
            @unlink($outputPng);
        }
    }
}
