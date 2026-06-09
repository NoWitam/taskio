<?php

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Approvals\DTOs\ApprovalQueueItem;
use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalQueueItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $entity = $this->approvable;
        $queueItem = $entity instanceof Approvable ? $entity->toApprovalQueueItem() : null;

        return [
            'process' => [
                'id' => $this->id,
                'run_id' => $this->run_id,
                'status' => $this->status->value,
                'approver_type' => $this->approver_type->value,
                'created_at' => $this->created_at?->toISOString(),
            ],
            'pipeline' => [
                'id' => $this->pipeline?->id,
                'name' => $this->pipeline?->name,
                'icon' => $this->pipeline?->icon?->value,
            ],
            'stage' => [
                'id' => $this->stage?->id,
                'name' => $this->stage?->name,
                'icon' => $this->stage?->icon?->value,
                'description' => $this->stage?->description,
                'order' => $this->stage?->order,
            ],
            'entity' => $queueItem ? [
                'id' => $entity->getKey(),
                'type' => $entity->getMorphClass(),
                'type_label' => $queueItem->type_label,
                'type_icon' => $queueItem->type_icon,
                'name' => $queueItem->name,
                'description' => $queueItem->description,
                'extra_fields' => $queueItem->extra_fields,
                'form' => $queueItem->form,
                'comments_url' => $queueItem->comments_url,
            ] : null,
        ];
    }
}
