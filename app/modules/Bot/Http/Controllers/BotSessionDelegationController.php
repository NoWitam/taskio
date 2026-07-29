<?php

namespace App\Modules\Bot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bot\Http\Requests\DelegateBotSessionRequest;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotSlotFillService;
use App\Modules\Bot\Services\BotVoiceComposer;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Http\Resources\GenerationSessionResource;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\SessionDelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * DELEGATE an editable generation session to a bot, and UNDO it (R2 sub-stage 3). This is the ONLY new
 * cross-module edge: Bot → Generator + Variables. The controller composes the bot's VOICE + autonomously
 * fills the session's slots, then stamps the Generator's delegation overlay — every Generator/Variables
 * class it names is a public seam; the Generator NEVER imports Bot (the seams take primitives/opaque
 * strings). Thin: authorize (FormRequest → session `update`, owner-only), guard editable state, call the
 * Bot services + the Generator seams, return the session resource + the fill report.
 *
 * Both {bot} and {session} are workspace-scoped route bindings (a foreign id 404s at bind), so a
 * cross-workspace bot or session is rejected before any work runs.
 */
class BotSessionDelegationController extends Controller
{
    public function __construct(
        private BotVoiceComposer $voice,
        private BotSlotFillService $slotFill,
        private SessionDelegationService $delegation,
        private GenerationSessionRunManager $runManager,
    ) {}

    /**
     * Delegate the session to the bot: compose the voice, autonomously fill the slots under the requested
     * `fill_mode` (AT MOST ONE metered ai_text call — none at all when there is nothing to fill), stamp the
     * whole overlay, and — only on the `auto_generate` opt-in — kick off a run. 409 when the session is
     * generating; 422 when otherwise not editable (failed) or the mode is unknown. Returns the updated session
     * resource + the fill report; 202 when a run was auto-started, else 200.
     */
    public function store(DelegateBotSessionRequest $request, Bot $bot, GenerationSession $session): JsonResource|JsonResponse
    {
        $this->guardEditable($session);

        // Stamp the overlay FIRST — so its `slot_values_before` snapshot captures the human's PRE-fill inputs
        // (undo restores exactly these; the bot's fill below is fully reversible with no data loss) — THEN run
        // the autonomous slot-fill (a pre-run fill; each value re-validated in the Generator seam). The returned
        // resource + report reflect the filled draft.
        $this->delegation->applyDelegation(
            $session,
            $this->voice->compose($bot),
            ['id' => $bot->id, 'name' => $bot->name, 'icon' => $bot->icon],
            $bot->id,
        );

        // The FILL MODE is the human's explicit click-time choice (`gaps` — never touch what they typed — or
        // `fresh` — propose everything anew), defaulting to `gaps`. In `gaps` mode with nothing empty the
        // service makes NO provider call and the report says `nothing_to_fill`: the overlay above is still
        // stamped (the bot becomes the author), but the delegation costs nothing.
        $report = $this->slotFill->fill($session, $request->fillMode());

        // "Gate-przed-wydatkiem": auto-run ONLY on the explicit opt-in. The run settles via the existing
        // GenerationSessionUpdated broadcast; the claim wins because the session is still draft/ready here. The
        // claim mutates the row (→ generating) via query, so the resource is built AFTER it (a fresh refresh) so
        // the 202 body reflects `generating`, not the stale draft/ready.
        $claimed = $request->autoGenerate() && $this->runManager->claimAndDispatch($session);

        $resource = GenerationSessionResource::make($session->refresh()->loadMissing('creator'))
            ->additional(['fill_report' => $report]);

        return $claimed
            ? $resource->response()->setStatusCode(Response::HTTP_ACCEPTED)
            : $resource;
    }

    /**
     * Undo the delegation: clear the WHOLE overlay (→ the human's own voice) and RESTORE the pre-delegation slot
     * values. Owner-only (session `update`). A `generating` session is an in-flight run → 409 (mirrors the
     * delegate guard: undoing mid-run would show the session undelegated while content still renders in the
     * frozen bot voice); a `ready`/`draft`/`failed` session may undo. Idempotent; returns the updated resource.
     */
    public function destroy(Bot $bot, GenerationSession $session): GenerationSessionResource
    {
        $this->authorize('update', $session);

        if ($session->status === GenerationSessionStatus::Generating) {
            abort(Response::HTTP_CONFLICT, __('bot.delegation.already_generating'));
        }

        $this->delegation->clearDelegation($session);

        return GenerationSessionResource::make($session->refresh()->loadMissing('creator'));
    }

    /**
     * Delegation requires an EDITABLE session (draft/ready). A `generating` session is an in-flight run → 409
     * (one op at a time, mirroring the generate/refine conflict); any other non-editable state (failed) → 422.
     */
    private function guardEditable(GenerationSession $session): void
    {
        if ($session->status->isEditable()) {
            return;
        }

        abort(
            $session->status === GenerationSessionStatus::Generating ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY,
            __('bot.delegation.' . ($session->status === GenerationSessionStatus::Generating ? 'already_generating' : 'not_editable')),
        );
    }
}
