<?php

namespace App\Modules\Forms\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Disk\Http\Resources\FileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'form' => FormListResource::make($this->whenLoaded('form')),
            'name' => $this->name,
            'guidelines' => $this->guidelines,
            'sources' => $this->sources,
            'sources_formatted' => $this->getSourcesFormatted(),
            'submissions_from' => $this->submissions_from->format('Y-m-d'),
            'submissions_to' => $this->submissions_to->format('Y-m-d'),
            'date_range' => $this->submissions_from->format('d.m.Y') . ' - ' . $this->submissions_to->format('d.m.Y'),
            'is_completed' => $this->isCompleted(),
            'completed_at' => $this->completed_at?->toISOString(),
            'file' => FileResource::make($this->whenLoaded('file')),
            'creator' => CreatorResource::make($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }

    private function getSourcesFormatted(): array
    {
        if (empty($this->sources)) {
            return ['Wszystkie źródła'];
        }

        return collect($this->sources)->map(function ($source) {
            return match ($source) {
                'task' => 'Zadania',
                'form' => 'Ręczne wypełnienia',
                default => $source
            };
        })->toArray();
    }
}
