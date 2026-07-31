<?php

namespace App\Modules\Generator\Support;

/**
 * The FROZEN visual identity of the character a delegated session draws — the image-side twin of the
 * snapshotted VOICE.
 *
 * A delegated session already renders its TEXT in a voice that was captured at delegation time and never
 * re-read from the live bot ({@see \App\Modules\Generator\Models\GenerationSession::botVoice}). This is the
 * same contract for the LOOK: the descriptor / aesthetic / wardrobe / prohibitions are copied into the
 * session's `bot_delegation.visual` overlay when the delegation is stamped, and the character's reference
 * IMAGE bytes are copied into {@see \App\Modules\Generator\Services\SessionIdentityImageStore} — so editing,
 * re-approving or DELETING the character afterwards can never change what an existing session produces.
 *
 * SNAPSHOT-NOT-LIVE, INCLUDING THE TOGGLE. `enabled` is read from the OVERLAY, never from the live source:
 * the human turning the module off tomorrow must not silently change a session that was already delegated
 * (and possibly already half-rendered) with it on. {@see fromOverlay} is the ONLY way in and refuses
 * anything whose overlay does not carry `enabled: true`.
 *
 * SECURITY BOUNDARY. The values are human-authored free text that lands in a PROVIDER PROMPT, so every one
 * is normalized here: non-strings dropped, control characters stripped, length-capped, the list bounded, and
 * the {@see CreativeDirection} fence markers scrubbed (the identity is composed into the same base prompt a
 * direction anchor rides, so it must not be able to forge that block's extent). Never logged.
 *
 * PROJECTIONS — prose, deliberately NOT fenced:
 *   - {@see subjectDescription}  WHO the recurring subject is (descriptor + wardrobe), for the image
 *                                anchor's subject line.
 *   - {@see guardrails}          the constraints that ride EVERY image of the session (aesthetic +
 *                                prohibitions), whether or not the character is in that frame.
 *
 * A text→image prompt has no system channel, so a labeled `--- BEGIN … ---` fence would simply be DRAWN
 * into the picture — the exact reason {@see CreativeDirection::forImage} is prose while its text/shot-list
 * projections are fenced. These projections follow that established posture, and reuse the wording the
 * identity spike already proved with the provider ("Subject:" / "Wearing:" / "Style:" / "Never show:").
 */
final class SessionVisualIdentity
{
    /** Length cap for one projected field (mirrors CreativeDirection::MAX_SHORT). */
    private const MAX_SHORT = 240;

    /** Max prohibitions kept — a bounded list, so a runaway module cannot inflate every image prompt. */
    private const MAX_PROHIBITIONS = 12;

    /**
     * @param  array<int, string>  $prohibitions
     */
    private function __construct(
        public readonly ?string $descriptor,
        public readonly ?string $aesthetic,
        public readonly ?string $wardrobe,
        public readonly array $prohibitions,
        private readonly bool $hasCharacterImage,
    ) {}

    /**
     * Normalize a session's `bot_delegation.visual` overlay into an identity, or NULL when the overlay is
     * absent / not an object / not `enabled` at the time it was frozen, or when NOTHING survives (no text
     * AND no character image — an identity that can say nothing about the picture is worse than none: it
     * would change prompts for no benefit). Never throws.
     */
    public static function fromOverlay(mixed $raw): ?self
    {
        if (!is_array($raw) || ($raw['enabled'] ?? null) !== true) {
            return null;
        }

        $identity = new self(
            descriptor: self::text($raw['descriptor'] ?? null),
            aesthetic: self::text($raw['aesthetic'] ?? null),
            wardrobe: self::text($raw['wardrobe'] ?? null),
            prohibitions: self::prohibitions($raw['prohibitions'] ?? null),
            hasCharacterImage: ($raw['has_character_image'] ?? null) === true,
        );

        return $identity->isEmpty() ? null : $identity;
    }

    /** Whether a character reference IMAGE was frozen with the delegation (the bytes live in the store). */
    public function hasCharacterImage(): bool
    {
        return $this->hasCharacterImage;
    }

    /**
     * WHO the recurring subject is: the character descriptor, plus the WARDROBE as part of the same
     * description. The wardrobe is first-class for the same reason the identity generator makes it a line of
     * its own — it is the one steerable defence against the provider's OUTPUT-side moderation (the same
     * character comes back refused in one outfit and fine in another), so a frame that draws the character
     * must state the clothes rather than leave them to the model.
     *
     * Null when the identity carries no written description (a reference-image-only character — the image
     * itself is then the whole description).
     */
    public function subjectDescription(): ?string
    {
        $pieces = array_values(array_filter([
            $this->descriptor,
            $this->wardrobe === null ? null : 'Wearing: ' . $this->wardrobe,
        ]));

        return $pieces === [] ? null : implode(' ', $pieces);
    }

    /**
     * The constraints that ride EVERY image of a session drawn for this character — the overall aesthetic
     * and the "never show" list — whether or not the character appears in that particular frame. A set of
     * images made for one creator has to look like one set, and a prohibition ("no alcohol") is about the
     * PICTURE, not about the person in it.
     *
     * Null when the identity carries neither.
     */
    public function guardrails(): ?string
    {
        $lines = [];

        if ($this->aesthetic !== null) {
            $lines[] = 'Style: ' . $this->aesthetic;
        }

        if ($this->prohibitions !== []) {
            $lines[] = 'Never show: ' . implode(', ', $this->prohibitions);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** Whether the identity can say nothing at all about a picture (no text, no reference image). */
    public function isEmpty(): bool
    {
        return !$this->hasCharacterImage
            && $this->descriptor === null
            && $this->aesthetic === null
            && $this->wardrobe === null
            && $this->prohibitions === [];
    }

    /**
     * One normalized field: strings ONLY, C0 controls + DEL stripped (newlines included — every projection
     * here is a single prose line), the {@see CreativeDirection} fence markers neutralized with the SAME
     * non-empty sentinel discipline (replace, never delete, so a deliberately split marker cannot rejoin),
     * trimmed and length-capped. Blank → null.
     */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $clean = CreativeDirection::scrubFenceMarkers($clean);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        if ($clean === '') {
            return null;
        }

        return mb_strlen($clean) > self::MAX_SHORT ? mb_substr($clean, 0, self::MAX_SHORT) : $clean;
    }

    /**
     * The bounded "never draw this" list: normalized strings, non-strings dropped, capped.
     *
     * @return array<int, string>
     */
    private static function prohibitions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach (array_values($value) as $entry) {
            if (count($out) >= self::MAX_PROHIBITIONS) {
                break;
            }

            $clean = self::text($entry);

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }
}
