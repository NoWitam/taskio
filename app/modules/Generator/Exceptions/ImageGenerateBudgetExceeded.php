<?php

namespace App\Modules\Generator\Exceptions;

/**
 * The run's per-session `ai_generate` budget (`generator.image_generate_max_calls_per_session`) is
 * exhausted, so the next text→image base in the chain is REFUSED before it reaches the provider (no wasted
 * call, no spend). The sibling of {@see ImageEditBudgetExceeded} for the GENERATE base rather than an edit
 * filter. Fail-soft like every other {@see ImageChainException}: the session executor turns the affected
 * image part into `{status:'failed', error}` with a localized, non-secret message, every other part still
 * runs, and the session ends `ready` — a runaway fan-out becomes a clean per-part failure, never a whole-run
 * timeout that bills the completed generations first.
 */
class ImageGenerateBudgetExceeded extends ImageChainException
{
    public function messageKey(): string
    {
        return 'generator.sessions.image_generate_budget';
    }
}
