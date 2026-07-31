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
 * PER-BLOCK AUTHORS (R2, this sub-stage) add a SECOND, finer-grained voice source alongside the session
 * one: an `@[ai-text]` block may name its own AUTHOR, and the executor pre-resolves every author a recipe
 * mentions into the {@see authorVoices()} map (one batch lookup, no N+1). {@see effectiveDirective()} is
 * the ONE place that ranks the two, so no consumer re-implements the precedence rule.
 *
 * LEAK-PROOF: the setters are always paired with a `finally` {@see clear()} at the executor scope (exactly
 * like MeterContext's session tag), so a voice can never bleed into a later, non-delegated spend in the
 * same worker process. `clear()` therefore MUST reset BOTH fields — the session directive AND the author
 * map — since either one alone would keep coloring a later run's text. The opaque directives are TRUSTED
 * authored-config (they enter the SYSTEM instruction where the persona line already sits) and are NEVER
 * logged.
 */
class AiVoiceContext
{
    private ?string $directive = null;

    /** @var array<string, string> authorId => opaque voice, pre-resolved for the recipe in flight */
    private array $authorVoices = [];

    public function setDirective(?string $directive): void
    {
        $this->directive = $directive;
    }

    /**
     * Install the pre-resolved per-author voices for the execution in flight. The map is exactly what
     * {@see \App\Modules\Variables\Contracts\AuthorVoiceResolver::voicesFor()} returned, so an author that
     * could NOT be resolved is ABSENT here rather than present-and-null.
     *
     * @param  array<string, string>  $map
     */
    public function setAuthorVoices(array $map): void
    {
        $this->authorVoices = $map;
    }

    /**
     * Reset BOTH voice sources — the leak-proof `finally` counterpart of the setters. Clearing only one
     * would leave a later, unrelated generation writing in a stale voice.
     */
    public function clear(): void
    {
        $this->directive = null;
        $this->authorVoices = [];
    }

    public function directive(): ?string
    {
        return $this->directive;
    }

    /** @return array<string, string> */
    public function authorVoices(): array
    {
        return $this->authorVoices;
    }

    /**
     * The voice that actually applies to ONE ai-text block, or null when none does.
     *
     * PRECEDENCE — the BLOCK wins over the SESSION: an `@[ai-text]` block that names an author is an
     * explicit, per-block authoring decision, so it beats the run-wide voice a delegated session carries.
     * With no author (every block authored before this feature, and every block whose author box is empty)
     * the session directive applies exactly as it did before, so a delegated run is unchanged.
     *
     * FAIL-SAFE: an author id that is present but NOT in the map — a deleted bot, an id from another
     * workspace, a malformed one — is treated as "no author for this block", falling back to the session
     * directive and finally to null, which makes the caller use the block's own persona tone (NEUTRAL by
     * default). A vanished author degrades the TONE of a run; it never blanks or breaks its text.
     */
    public function effectiveDirective(?string $authorId): ?string
    {
        if ($authorId !== null && $authorId !== '' && isset($this->authorVoices[$authorId])) {
            return $this->authorVoices[$authorId];
        }

        return $this->directive;
    }
}
