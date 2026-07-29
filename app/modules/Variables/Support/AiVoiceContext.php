<?php

namespace App\Modules\Variables\Support;

/**
 * Ambient holder for the AI-text VOICE directive currently in flight — the exact twin of
 * {@see MeterContext} (which mirrors {@see \App\Tenancy\TenantContext}): a request/run-scoped shared
 * instance a caller SETS around an execution and CLEARS in a `finally`, that the shared ai-text seam
 * reads to color the generating agent's tone.
 *
 * R2 sub-stage 3 (bots in the generator): when a session is DELEGATED to a bot, the SESSION EXECUTOR (an
 * upper Generator-layer consumer) sets the bot's SNAPSHOTTED opaque voice directive here around its run, so
 * each text part (and the shot-list voiceover) renders IN the bot's voice; a non-delegated run sets null, so
 * behavior is byte-identical to today. The `@[ai-text]` generator
 * ({@see \App\Modules\Variables\Services\AiTextGenerationService}) and the upper-layer shot-list renderer
 * both resolve THIS instance and read {@see directive()} — so the single set at scope entry reaches both
 * agents. (Variables names no upper module — the consumers live above and depend DOWN onto this seam.)
 *
 * LEAK-PROOF: the setter is always paired with a `finally` {@see clear()} at the executor scope (exactly
 * like MeterContext's session tag), so the voice can never bleed into a later, non-delegated spend in the
 * same worker process. The opaque directive is TRUSTED authored-config (it enters the SYSTEM instruction
 * where the persona line already sits) and is NEVER logged.
 */
class AiVoiceContext
{
    private ?string $directive = null;

    public function setDirective(?string $directive): void
    {
        $this->directive = $directive;
    }

    public function clear(): void
    {
        $this->directive = null;
    }

    public function directive(): ?string
    {
        return $this->directive;
    }
}
