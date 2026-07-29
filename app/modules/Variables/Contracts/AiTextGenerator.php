<?php

namespace App\Modules\Variables\Contracts;

/**
 * The AI-text generation seam the shared {@see \App\Modules\Variables\Services\VariableResolver} calls
 * when it EXECUTES an `@[ai-text]` directive. It is deliberately a Variables-side CONTRACT so the
 * resolver — a lower-layer, form-independent service both Workflows and (R2) Generator depend on —
 * never names a concrete generator, keeping the dependency direction one-way.
 *
 * The single method takes the already-resolved prompt plus an OPTIONAL persona id (a plain `?string`,
 * so the interface carries no enum from any upper module); the implementation maps that id to its own
 * persona vocabulary. It MUST be fail-closed — never throw — returning '' on a blank prompt, an
 * exhausted budget, or any provider failure, because the resolver treats a blank result as the field's
 * empty value (a blank REQUIRED field then hard-fails honestly, a blank optional one is just empty).
 *
 * Workflows binds its budgeted, persona-aware implementation to this contract in its provider; a
 * template PREVIEW (R2) binds a NO-OP generator so ai-text chips render inert. The resolver depends
 * ONLY on this interface.
 */
interface AiTextGenerator
{
    /**
     * Generate the text for one resolved ai-text prompt, or '' (fail-closed) on a blank prompt, an
     * exhausted budget, or any generation failure. $personaId colors the tone only (null = default).
     */
    public function generate(string $prompt, ?string $personaId): string;
}
