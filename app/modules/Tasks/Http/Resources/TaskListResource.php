<?php

namespace App\Modules\Tasks\Http\Resources;

use App\Modules\Labels\Http\Resources\LabelResource;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'deadline' => $this->deadline?->format('d.m.Y'),
            'is_overdue' => $this->isDeadlineOverdue(),
            'is_at_risk' => $this->isDeadlineAtRisk(),
            'comments' => $this->whenCounted('comments'),
            'assigned' => UserResource::make($this->assigned),
            'labels' => LabelResource::collection($this->labels),
            'is_in_approval' => $this->isInApproval(),
        ];
    }
}
