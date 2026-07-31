<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\DTOs\CreateGenerationSessionDTO;
use App\Modules\Generator\DTOs\UpdateGenerationSessionDTO;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Business logic + persistence for generation SESSIONS (CRUD). The async RUN lifecycle (claim / render /
 * terminal) lives in {@see GenerationSessionRunManager}; this service owns only the create/read/update/
 * delete writes. Single-row writes (no multi-step orchestration), so no transaction — each mutation is
 * one atomic save. Queries run through the model so WorkspaceScope / the tenant connection isolate the
 * active workspace in both db_modes (the SAME isolation the TemplateService relies on).
 */
class GenerationSessionService
{
    public function __construct(
        private RecipeAuthorVoiceSnapshotter $authorVoices,
        private TenantContext $tenant,
    ) {}

    public function index(Request $request): CursorPaginator
    {
        return GenerationSession::query()
            ->with('creator')
            ->search(['name'], $request->get('search'))
            ->when(
                GenerationSessionStatus::tryFrom((string) $request->get('status')) !== null,
                fn ($query) => $query->where('status', $request->get('status')),
            )
            ->filterByDate('created_at', $request)
            ->orderByDesc('created_at')
            ->cursorPaginate(20);
    }

    /**
     * Create a session from a snapshotted recipe, in the initial `draft` state (no run yet).
     *
     * AUTHOR VOICES ARE FROZEN HERE, with the rest of the recipe: the snapshot is enriched with
     * `author_voices` ({@see RecipeAuthorVoiceSnapshotter}) BEFORE the single insert, so a bot named as a
     * per-block `@[ai-text]` author has its voice captured exactly as it reads NOW. Editing or deleting
     * that bot afterwards can no longer change what this session produces — the same snapshot-not-live rule
     * `bot_delegation.voice` and the whole `recipe_snapshot` already follow (ADR-0034 D1). Recipes with no
     * authored block (every recipe until now) get an empty map and cost no lookup at all.
     *
     * THIS IS THE CHOKE POINT for BOTH creation paths — the interactive one and the automated
     * {@see SessionAutomationService::createFromTemplate}, which delegates here rather than inserting its
     * own row — so neither can be created without frozen voices.
     */
    public function create(CreateGenerationSessionDTO $dto): GenerationSession
    {
        $snapshot = $dto->recipeSnapshot;
        $snapshot['author_voices'] = $this->authorVoices->snapshot(
            is_array($snapshot['content'] ?? null) ? $snapshot['content'] : [],
            $this->creationWorkspaceId(),
        );

        $session = new GenerationSession([
            'template_id' => $dto->templateId,
            'name' => $dto->name,
            'content_type' => $dto->contentType,
            'recipe_snapshot' => $snapshot,
            'slot_values' => $dto->slotValues,
            'results' => null,
            'status' => GenerationSessionStatus::Draft,
        ]);
        $session->save();

        return $session;
    }

    /**
     * The EXPLICIT tenant boundary the author lookup runs under — the very id `TenantAware` is about to
     * stamp on the row being inserted: the active workspace in SHARED mode, and null in OWN-database mode
     * (tenant tables omit the column; the dedicated connection IS the boundary). Derived from the same
     * mode test rather than read back off the model because the snapshot must be complete BEFORE the single
     * insert. With no tenancy at all the lookup gets null and the resolver refuses it outright (fail-SAFE)
     * instead of querying every workspace's bots.
     */
    private function creationWorkspaceId(): ?string
    {
        return $this->tenant->isShared() ? $this->tenant->id() : null;
    }

    /** Update the user-mutable inputs (name / slot_values). A field the DTO left null is untouched. */
    public function update(GenerationSession $session, UpdateGenerationSessionDTO $dto): GenerationSession
    {
        if ($dto->name !== null) {
            $session->name = $dto->name;
        }

        if ($dto->slotValues !== null) {
            $session->slot_values = $dto->slotValues;
        }

        $session->save();

        return $session->refresh();
    }

    /** Soft-delete (trash) a session; the 2d reaper purges it after the retention window. */
    public function delete(GenerationSession $session): void
    {
        $session->delete();
    }

    /**
     * Archive a session (R2 sub-stage 2d): stamp `archived_at`, which EXEMPTS it from the lifecycle
     * reaper's trash + purge windows. Archive operates on a LIVE session (the route binds only live rows)
     * and is idempotent — it does NOT restore/un-trash; it only sets the marker.
     */
    public function archive(GenerationSession $session): GenerationSession
    {
        $session->archived_at = now();
        $session->save();

        return $session->refresh();
    }

    /** Un-archive a session: clear `archived_at`, re-enrolling it in the reaper's retention windows. */
    public function unarchive(GenerationSession $session): GenerationSession
    {
        $session->archived_at = null;
        $session->save();

        return $session->refresh();
    }
}
