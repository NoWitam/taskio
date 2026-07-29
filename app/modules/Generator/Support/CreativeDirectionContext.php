<?php

namespace App\Modules\Generator\Support;

/**
 * Ambient holder for the CREATIVE DIRECTION currently in flight — the Generator-owned twin of
 * {@see \App\Modules\Variables\Support\AiVoiceContext} (itself a twin of MeterContext / TenantContext): a
 * run-scoped shared instance the SESSION EXECUTOR sets at scope entry and CLEARS in the SAME `finally`, that
 * a deep, non-executor-reachable consumer reads.
 *
 * It exists for exactly ONE consumer: {@see \App\Modules\Generator\Services\GeneratorAiTextService}, which
 * resolves an `@[ai-text]` directive from INSIDE the shared variable resolver — many frames below the
 * executor, with no seam to thread a parameter through. Every OTHER consumer (the shot-list renderer, the
 * storyboard image composer) IS executor-reachable and receives the direction as an EXPLICIT parameter; do
 * not reach for this holder there.
 *
 * GENERATOR-OWNED on purpose: the Variables layer knows nothing about creative direction (Generator →
 * Variables stays one-way), so this lives here and is bound as a singleton in
 * {@see \App\Modules\Generator\GeneratorModuleServiceProvider}.
 *
 * LEAK-PROOF: the setter is always paired with a `finally` {@see clear()} at each of the executor's three
 * entry points (full run / regenerate / refine), so a direction can never bleed into a later, unrelated
 * generation in the same worker process. The direction is derived from the author's recipe + slot values and
 * is NEVER logged.
 */
class CreativeDirectionContext
{
    private ?CreativeDirection $direction = null;

    public function set(?CreativeDirection $direction): void
    {
        $this->direction = $direction;
    }

    public function clear(): void
    {
        $this->direction = null;
    }

    public function direction(): ?CreativeDirection
    {
        return $this->direction;
    }
}
