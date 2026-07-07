<?php

namespace App\Modules\Bot\Http\Resources;

use App\Modules\Users\Http\Resources\UserResource;
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

            // Visual / Audio placeholders (no logic yet).
            'visual' => $this->visual,
            'audio' => $this->audio,

            'creator' => UserResource::make($this->whenLoaded('creator')),

            // Capability flags (mirrors the Approvals convention).
            'is_owner' => $this->creator_id === $request->user()?->id,
            'can_execute_tasks' => $this->canExecuteTasks(),
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
