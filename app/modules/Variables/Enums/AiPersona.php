<?php

namespace App\Modules\Variables\Enums;

/**
 * The small, closed set of TONES an AI-text generation may run in. This is deliberately NOT the bot
 * system (bots are a separate, future concern): a persona here is only a short style directive
 * folded into the generating agent's system instruction. The set is intentionally tiny so it stays a
 * stable, label-less contract the FE localizes via i18n.
 *
 *   - neutral   clear, neutral, professional (the default; also the fallback for null/unknown)
 *   - friendly  warm, approachable, conversational
 *   - formal    precise, businesslike, respectful
 *   - concise   as short and direct as possible
 *
 * It lives in the LOWER Variables layer (moved down from Workflows in R2) so BOTH the workflow
 * variable catalog and the Generator template catalog expose the same persona list from one home,
 * and the shared {@see \App\Modules\Variables\Services\AiTextGenerationService} maps a directive's
 * `personaId` through it. The style line is authored in English purely to STEER the model's tone;
 * the agent is always told to WRITE in the language of the (resolved) prompt, so an English tone
 * directive does not force an English output.
 */
enum AiPersona: string
{
    case NEUTRAL = 'neutral';
    case FRIENDLY = 'friendly';
    case FORMAL = 'formal';
    case CONCISE = 'concise';

    /**
     * Resolve a directive's `personaId` (a string, or null when the author picked none) to a
     * persona, defaulting to NEUTRAL for null / an unknown value (fail-safe, never throws).
     */
    public static function fromNullable(?string $id): self
    {
        return $id !== null ? (self::tryFrom($id) ?? self::NEUTRAL) : self::NEUTRAL;
    }

    /**
     * The tone directive folded into the generating agent's system instruction. English on
     * purpose (it steers tone, not output language — the agent writes in the prompt's language).
     */
    public function styleInstruction(): string
    {
        return match ($this) {
            self::NEUTRAL => 'Write in a clear, neutral, professional tone.',
            self::FRIENDLY => 'Write in a warm, friendly, approachable tone.',
            self::FORMAL => 'Write in a formal, precise, businesslike tone.',
            self::CONCISE => 'Write as concisely as possible: short and direct, no filler.',
        };
    }

    /**
     * The label-less persona catalog a variable/template catalog endpoint exposes (mirrors the
     * `operations` "descriptors without labels" pattern — the FE resolves labels via i18n).
     *
     * @return array<int, array{id: string}>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $persona): array => ['id' => $persona->value], self::cases());
    }
}
