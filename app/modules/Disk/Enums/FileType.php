<?php

namespace App\Modules\Disk\Enums;

enum FileType: string
{
    case IMAGE = 'image';
    case VIDEO = 'viedeo';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';
    case SPREADSHEET = 'spreadsheet';
    case ARCHIVE = 'archive';
    case ANOTHER = 'another';

    public static function fromMimeType(string $mimeType): self
    {
        if (str_starts_with($mimeType, 'image/')) return self::IMAGE;
        if (str_starts_with($mimeType, 'video/')) return self::VIDEO;
        if (str_starts_with($mimeType, 'audio/')) return self::AUDIO;

        $map = [
            // Dokumenty
            'application/pdf' => self::DOCUMENT,
            'text/plain' => self::DOCUMENT,
            'text/html' => self::DOCUMENT,
            'application/json' => self::DOCUMENT,
            'application/xml' => self::DOCUMENT,
            'text/xml' => self::DOCUMENT,

            // Arkusze
            'text/csv' => self::SPREADSHEET,
            'application/vnd.ms-excel' => self::SPREADSHEET,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => self::SPREADSHEET,
            'application/vnd.oasis.opendocument.spreadsheet' => self::SPREADSHEET,

            // Archiwa
            'application/zip' => self::ARCHIVE,
            'application/x-zip-compressed' => self::ARCHIVE,
            'application/x-7z-compressed' => self::ARCHIVE,
            'application/x-rar-compressed' => self::ARCHIVE,
            'application/gzip' => self::ARCHIVE,
            'application/x-tar' => self::ARCHIVE,
        ];

        return $map[$mimeType] ?? self::ANOTHER;
    }
}