<?php

namespace App\Modules\Disk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => route('disk.show', ['file' => $this->id]),
            'type' => $this->type,
            'size' => $this->size,
            'size_human' => $this->formatBytes($this->size),
            'created_at' => $this->created_at->format('Y-m-d H:i'),
        ];
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}
