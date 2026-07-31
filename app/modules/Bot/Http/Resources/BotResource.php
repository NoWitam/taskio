<?php

namespace App\Modules\Bot\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'description' => $this->description,
            'icon' => $this->icon,

            // Text module (mandatory). dictionary: [{term,meaning}]; phrases: [{phrase,context}];
            // prohibitions: string[]. Accessors normalize the legacy bare-string shapes.
            'persona' => $this->persona,
            'style' => $this->style,
            'dictionary' => $this->dictionaryEntries(),
            'phrases' => $this->phraseEntries(),
            'prohibitions' => $this->prohibitions ?? [],

            // Task-execution module.
            'task_execution' => $this->task_execution,

            // Knowledge module: { enabled, entries: [{title, content}] }.
            'knowledge' => [
                'enabled' => $this->knowledgeEnabled(),
                'entries' => $this->knowledgeEntries(),
            ],

            // Visual module: the NORMALIZED identity, or null when it was never configured
            // (a bot from before the module existed). Never the raw column.
            // File ids are rendered by the caller through the Disk serve route (GET /api/disk/{id}).
            'visual' => $this->visualIdentity(),

            // Audio placeholder (no logic yet).
            'audio' => $this->audio,

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // Capability flags (mirrors the Approvals convention).
            'is_owner' => $this->isOwnedBy($request->user()),
            'can_execute_tasks' => $this->canExecuteTasks(),
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
