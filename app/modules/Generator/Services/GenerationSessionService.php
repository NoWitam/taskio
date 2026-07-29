<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\DTOs\CreateGenerationSessionDTO;
use App\Modules\Generator\DTOs\UpdateGenerationSessionDTO;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
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

    /** Create a session from a snapshotted recipe, in the initial `draft` state (no run yet). */
    public function create(CreateGenerationSessionDTO $dto): GenerationSession
    {
        $session = new GenerationSession([
            'template_id' => $dto->templateId,
            'name' => $dto->name,
            'content_type' => $dto->contentType,
            'recipe_snapshot' => $dto->recipeSnapshot,
            'slot_values' => $dto->slotValues,
            'results' => null,
            'status' => GenerationSessionStatus::Draft,
        ]);
        $session->save();

        return $session;
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
