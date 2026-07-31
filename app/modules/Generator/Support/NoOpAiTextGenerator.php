<?php

namespace App\Modules\Generator\Support;

use App\Modules\Variables\Contracts\AiTextGenerator;

/**
 * The INERT-but-LABELED AI-text generator the template PREVIEW path binds to the shared
 * {@see \App\Modules\Variables\Services\VariableResolver}: it never calls a model. Instead of the empty
 * string a real generator returns on a spent budget, it emits a `[AI: <resolved prompt>]` PLACEHOLDER so
 * an author previewing a recipe SEES exactly where an `@[ai-text]` block lands and against which resolved
 * prompt — the AI is not executed in this authoring sub-stage, only marked. A genuinely blank prompt still
 * returns '' (fail-closed, like every real generator), so an empty block adds nothing.
 *
 * Sub-stage 1 runs NO real AI (the budgeted, persona-aware generator + cost metering arrive in a later
 * sub-stage). Binding this no-op — rather than the Workflows implementation — also keeps the Generator
 * module's one-way boundary: the preview resolver depends only on the Variables contract, never on a
 * Workflows class. The placeholder is the generator's OUTPUT, so it rides the resolver's NUL-mask and is
 * inserted verbatim (never re-scanned as a reference).
 */
class NoOpAiTextGenerator implements AiTextGenerator
{
    /** $authorId is accepted for the contract and ignored: a preview names no author and calls no model. */
    public function generate(string $prompt, ?string $personaId, ?string $authorId = null): string
    {
        return $prompt === '' ? '' : '[AI: ' . $prompt . ']';
    }
}
