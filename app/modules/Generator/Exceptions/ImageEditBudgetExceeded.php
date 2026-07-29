<?php

namespace App\Modules\Generator\Exceptions;

/**
 * The run's per-session `ai_edit` budget (`generator.image_edit_max_calls_per_session`) is exhausted, so the
 * next `ai_edit` is REFUSED before it reaches the provider (no wasted call, no spend).
 *
 * Its meaning depends on WHERE it is raised:
 *   - inside a CHAIN ({@see \App\Modules\Generator\Services\ImageChainExecutor::execute}) it never escapes:
 *     the over-budget filter step is skipped and the part still produces an image WITHOUT that filter
 *     (graceful degradation — destroying a whole frame over a decorative filter is the worse outcome);
 *   - out of a REFINE ({@see \App\Modules\Generator\Services\ImageChainExecutor::editImage}) it escapes and is
 *     fail-soft like every other {@see ImageChainException}: the session executor turns the op into
 *     `{status:'failed', error}` with this localized, non-secret message, the current image is preserved
 *     unchanged, and the session ends `ready`.
 */
class ImageEditBudgetExceeded extends ImageChainException
{
    public function messageKey(): string
    {
        return 'generator.sessions.image_budget';
    }
}
