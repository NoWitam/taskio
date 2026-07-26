<?php

namespace Tests\Unit;

use App\Modules\Disk\Enums\FileType;
use PHPUnit\Framework\TestCase;

/**
 * FileType::fromMimeType classifies an upload for its list glyph / thumbnail. The `text` case is
 * what the browser previews as a snippet, so plain-text-renderable mimes must land there — not in
 * `document` (which is the binary PDF/Word bucket with no cheap thumbnail).
 */
class FileTypeTest extends TestCase
{
    public function test_prefixes_classify_media(): void
    {
        $this->assertSame(FileType::IMAGE, FileType::fromMimeType('image/png'));
        $this->assertSame(FileType::VIDEO, FileType::fromMimeType('video/mp4'));
        $this->assertSame(FileType::AUDIO, FileType::fromMimeType('audio/mpeg'));
    }

    public function test_text_renderable_mimes_are_text_not_document(): void
    {
        foreach (['text/plain', 'text/markdown', 'text/html', 'application/json', 'application/xml', 'text/xml'] as $mime) {
            $this->assertSame(FileType::TEXT, FileType::fromMimeType($mime), $mime);
        }
    }

    public function test_binary_documents_are_document(): void
    {
        $this->assertSame(FileType::DOCUMENT, FileType::fromMimeType('application/pdf'));
        $this->assertSame(FileType::DOCUMENT, FileType::fromMimeType('application/msword'));
    }

    public function test_spreadsheets_archives_and_unknowns(): void
    {
        $this->assertSame(FileType::SPREADSHEET, FileType::fromMimeType('text/csv'));
        $this->assertSame(FileType::ARCHIVE, FileType::fromMimeType('application/zip'));
        $this->assertSame(FileType::ANOTHER, FileType::fromMimeType('application/x-unknown'));
    }
}
