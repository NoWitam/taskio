<?php

namespace App\Modules\Variables\Support;

/**
 * Ambient holder for the AI-spend session currently in flight — MIRRORS {@see \App\Tenancy\TenantContext}:
 * a request-scoped singleton that a caller sets/clears and the {@see LedgerMeteredAiCall} reads to stamp
 * each usage event with its originating generation `session_id`.
 *
 * In R2 sub-stage 2a it is only DEFINED and wired: the two existing spenders (Workflow ai-text, Disk
 * ai-edit) set nothing, so their usage events carry a null session. Sub-stage 2b's generation Sessions
 * set it around their execution so per-session spend can be attributed (and a soft per-session cap added).
 *
 * R2 sub-stage 4 adds an ambient ACTOR overlay (mirroring the session tag): an EXPLICIT actor a caller
 * with no auth()/run of its own sets so the spend attributes correctly — the queued generation-session
 * run (owner, or the bot when delegated) and the autonomous bot slot-fill (bot). It is the FIRST of the
 * meter's precedence chain (explicit actor → active workflow run → auth user → null); an unset actor lets
 * the meter fall through to the default resolution. Set and cleared in the SAME finally as the session tag.
 */
class MeterContext
{
    private ?string $sessionId = null;

    private ?string $actorType = null;

    private ?string $actorId = null;

    public function setSession(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function clearSession(): void
    {
        $this->sessionId = null;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /** Tag the ambient actor for the next spends (an explicit override of the default resolution). */
    public function setActor(?string $type, ?string $id): void
    {
        $this->actorType = $type;
        $this->actorId = $id;
    }

    public function clearActor(): void
    {
        $this->actorType = null;
        $this->actorId = null;
    }

    public function actorType(): ?string
    {
        return $this->actorType;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
