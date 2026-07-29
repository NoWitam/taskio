<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\DTOs\CreateGenerationSessionDTO;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use Illuminate\Support\Str;

/**
 * The Generator's AUTOMATION SEAM (R2 sub-stage 5): the ONE entry point a server-side, NON-INTERACTIVE
 * caller uses to run a template. HTTP-FREE and PRIMITIVES-ONLY by construction — no Request, no
 * FormRequest, no auth() — so it is callable from a queued worker (an automated run, a future publisher),
 * and it names no upper-module class, keeping the Generator boundary one-way (pinned by
 * GeneratorModuleBoundaryTest).
 *
 * It owns the two steps that had NO callable form (CREATE from a live template, and the WAIT status) and
 * DELEGATES creation to the existing service rather than re-implementing it. The rest of an automated run is
 * the module's existing seams, used directly — deliberately NOT re-wrapped here, so there is exactly one
 * implementation of each and the automated path can never drift from the interactive one:
 *   - CREATE       → {@see createFromTemplate} (this service; the standard
 *                    {@see CreateGenerationSessionDTO} + {@see GenerationSessionService::create}),
 *   - FILL         → {@see SessionDelegationService::applySlotValues} under the AUTOMATION scope policy,
 *   - RUN          → {@see GenerationSessionRunManager::claimAndDispatch} (the single budget-gated,
 *                    atomically-claimed choke point — a caller inside a scope that rebinds the default queue
 *                    connection passes its own connection there),
 *   - WAIT         → {@see terminalStatusFor} (this service) — the ONLY thing an outside caller must ask
 *                    about a session, kept that narrow so nobody reaches into Generator models,
 *   - COLLECT      → {@see SessionContentProjector} (the assembled text) + {@see GeneratedImageExporter}
 *                    (the produced images, durably, onto the Disk).
 *
 * TENANCY: it requires an ACTIVE TenantContext (guaranteed inside a run, which re-establishes tenancy
 * before doing anything) — every read/write goes through the tenant-scoped models, exactly like the
 * interactive path. It performs NO authorization: authorization for an automated run belongs to whoever
 * authored/owns the automation, at that layer, not here.
 *
 * ATTRIBUTION: the creator is stamped AMBIENTLY by `HasCreator` from the context at insert time. This
 * service must therefore NEVER set `creator_id` explicitly — that is precisely what lets a run attribute a
 * session to the actor that produced it.
 */
class SessionAutomationService
{
    public function __construct(
        private GenerationSessionService $sessions,
    ) {}

    /**
     * Create a session from a LIVE template, snapshotting its recipe.
     *
     * SNAPSHOT-AUTHORITATIVE (ADR-0034 D1): the session persists `{content_type, slots, content}` as it is
     * NOW, so a later edit (or deletion) of the template can never change what an in-flight or finished run
     * produced. `template_id` stays provenance-only.
     *
     * $slotValues is stored AS GIVEN — the same LENIENT posture the human create path has (a draft may be
     * filled incrementally, and the values are re-checked where it matters: the executor resolves through
     * the typed catalog). A caller that wants per-slot TYPE ENFORCEMENT + a fill report uses
     * {@see SessionDelegationService::applySlotValues} with the AUTOMATION policy instead/afterwards.
     *
     * @param  array<string, mixed>  $slotValues  the {slotName: value} map to seed the session with
     * @param  string|null  $name  a display name; blank/absent falls back to the template's own name
     */
    public function createFromTemplate(Template $template, array $slotValues, ?string $name = null): GenerationSession
    {
        $slots = is_array($template->slots) ? $template->slots : [];
        $content = is_array($template->content) ? $template->content : [];
        $contentType = (string) $template->content_type;

        return $this->sessions->create(new CreateGenerationSessionDTO(
            templateId: (string) $template->id,
            name: trim((string) $name) !== '' ? (string) $name : (string) $template->name,
            contentType: $contentType,
            recipeSnapshot: [
                'content_type' => $contentType,
                'slots' => $slots,
                'content' => $content,
            ],
            slotValues: $slotValues,
        ));
    }

    /**
     * The session's CURRENT status value, or null when it no longer exists (deleted/trashed, or out of
     * reach of the caller's scope).
     *
     * SCOPING, PRECISELY: workspace-scoped WHEN a shared-mode workspace is ACTIVE ({@see \App\Models\Scopes\WorkspaceScope}
     * constrains only then). The waiting-run sweep's shared pass deliberately CLEARS the tenant context
     * before reading, so there the lookup is unconstrained — and that is the SAFE direction: a scoped read
     * with no active workspace would return null for every parked run and mass-fail them as GONE. It is not
     * a leak either, because the only id ever passed here is the one the run's OWN step wrote into its wait
     * record; nothing enumerates and nothing is returned but a status string.
     *
     * This is intentionally the ONLY question an outside module asks about a session's progress: a plain
     * string, so a waiting caller never has to reach into Generator models or enums. `ready` and `failed`
     * are TERMINAL; `generating` (and `draft`, for a session that was never claimed) mean keep waiting.
     *
     * A non-uuid id short-circuits to null: the key column is a uuid, so a malformed id is "no such
     * session", never a database error.
     */
    public function terminalStatusFor(string $sessionId): ?string
    {
        if (!Str::isUuid($sessionId)) {
            return null;
        }

        // `value()` runs the column through the model cast, so this is the enum (or null when no such row);
        // the seam deliberately hands the caller the plain wire string.
        $status = GenerationSession::query()->whereKey($sessionId)->value('status');

        return $status instanceof GenerationSessionStatus ? $status->value : null;
    }
}
