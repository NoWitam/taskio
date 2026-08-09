<?php

namespace App\Modules\Bot\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Bot\Services\BotKnowledgeService;
use App\Tenancy\TenantContext;
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

            // Built-in knowledge module: { enabled, entries: [{title, content}] }. Still the source of
            // truth for a bot that reads no base — see `knowledge_binding`.
            'knowledge' => [
                'enabled' => $this->knowledgeEnabled(),
                'entries' => $this->knowledgeEntries(),
            ],

            // B6 — the knowledge BASE this bot reads: null, or {knowledge_base_id, mode}. Additive, and
            // it takes PRECEDENCE over `knowledge` above: while a binding exists the built-in entries are
            // kept but not injected, so an editor should show the binding as the active source.
            //
            // Resolved through the module's own service (one query, single-bot payload only — the LIST
            // resource deliberately omits it, so a page of bots is never a page of lookups) rather than
            // through a relation: the binding is addressed by primitives across a module boundary, and one
            // authority for reading it is worth more here than the eager-loading a relation would add.
            'knowledge_binding' => $this->knowledgeBindingPayload(),

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

    /** @return array{knowledge_base_id: string, mode: string}|null */
    private function knowledgeBindingPayload(): ?array
    {
        // ONLY WITH AN ACTIVE TENANT. This resource is returned by routes deliberately OUTSIDE
        // `RequireWorkspace` — the `bots` resource itself, `bots/{id}/restore`, `bots/{bot}/status` —
        // and `ResolveWorkspace` no-ops without the header, which leaves `WorkspaceScope` inert. Reading
        // the binding there runs an UNSCOPED query for a workspace-owned row, and on an own-database
        // tenant that has not been migrated yet it is a 500 on a bot payload that has nothing to do
        // with knowledge.
        //
        // Null is the honest answer and the same one an unbound bot gives: with no workspace there is
        // no base to be bound to. Every route that WRITES a binding sits inside `RequireWorkspace`, so
        // nothing is hidden from a client in a position to change it.
        if (!app(TenantContext::class)->hasWorkspace()) {
            return null;
        }

        $binding = app(BotKnowledgeService::class)->binding($this->resource);

        return $binding === null ? null : [
            'knowledge_base_id' => (string) $binding->knowledge_base_id,
            'mode' => $binding->mode->value,
        ];
    }
}
