<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Models\Bot;

/**
 * Composes EVERYTHING a bot lends to a generation session it authors — the author snapshot, the opaque
 * VOICE, the frozen LOOK and the character's reference BYTES — as plain primitives.
 *
 * WHY IT EXISTS AS ITS OWN CLASS. This composition used to live inside
 * {@see \App\Modules\Bot\Http\Controllers\BotSessionDelegationController}, which was fine while a human
 * clicking "delegate" was the only way in. A workflow step ordering the same delegation is the second way
 * in, and it arrives on a QUEUE with no request, no controller and no session in hand — so the composition
 * had to become callable rather than be copied. A copy is exactly what must not happen here: the two paths
 * would then be able to disagree about which of a bot's material an authored session actually receives, and
 * that difference would only ever surface as content quality (an automated post subtly less "in voice" than
 * a hand-delegated one), which nothing fails on and nobody reports.
 *
 * PRIMITIVES OUT, ON PURPOSE. {@see \App\Modules\Generator\Services\SessionDelegationService::applyDelegation}
 * takes no Bot class — that is what keeps the Bot → Generator edge one-way — so this composer's whole output
 * is arrays and strings the Generator can consume without learning what an author is.
 *
 * The voice and the bytes are content/authored config: NEVER logged.
 */
class BotDelegationIdentityComposer
{
    public function __construct(
        private BotVoiceComposer $voice,
        private BotVisualIdentityService $visual,
    ) {}

    /**
     * The delegation primitives for one bot. Always yields an author + a voice (a bot with no written
     * material still composes a directive saying so); the LOOK and the BYTES are null for a bot whose visual
     * module is off or which has no approved likeness — which reproduces the pre-visual-module delegation
     * exactly.
     *
     * @return array{author: array{id: string, name: string, icon: ?string}, voice: string, visual: array<string, mixed>|null, character_image_bytes: string|null}
     */
    public function compose(Bot $bot): array
    {
        ['visual' => $visual, 'bytes' => $bytes] = $this->visualSnapshot($bot);

        return [
            'author' => ['id' => (string) $bot->id, 'name' => (string) $bot->name, 'icon' => $bot->icon],
            'voice' => $this->voice->compose($bot),
            'visual' => $visual,
            'character_image_bytes' => $bytes,
        ];
    }

    /**
     * WHAT OF THE BOT'S LOOK a session should freeze: the identity's TEXT fields as plain primitives, plus
     * the approved likeness's raw BYTES — or nulls.
     *
     * THE TOGGLE IS CHECKED HERE, ONCE. `visual.enabled` deliberately does NOT gate GENERATING candidates
     * (a user curates the strip before switching the module on), so it is the DOWNSTREAM use that must
     * respect it — and this is that boundary. What is decided here is then FROZEN on the session, so a later
     * flip of the toggle cannot change a session that is already delegated (possibly already half-rendered).
     *
     * The text is frozen even when there is no approved likeness yet: `has_character_image` records which of
     * the two the session actually got, and the render layer decides what that is worth.
     *
     * @return array{visual: array<string, mixed>|null, bytes: string|null}
     */
    private function visualSnapshot(Bot $bot): array
    {
        $identity = $bot->visualEnabled() ? $bot->visualIdentity() : null;

        if ($identity === null) {
            return ['visual' => null, 'bytes' => null];
        }

        $bytes = $this->visual->canonicalImageBytes($bot);

        return [
            'visual' => [
                'descriptor' => $identity['descriptor'],
                'aesthetic' => $identity['aesthetic'],
                'wardrobe' => $identity['wardrobe'],
                'prohibitions' => $identity['prohibitions'],
                // PROVENANCE only — the run never reads it back (the bytes above are the reference). Kept
                // null when nothing was copied, so the overlay cannot name a file it did not freeze.
                'source_file_id' => $bytes === null ? null : $identity['canonical_file_id'],
            ],
            'bytes' => $bytes,
        ];
    }
}
