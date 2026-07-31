<?php

namespace App\Modules\Bot\DTOs;

use App\Modules\Bot\Enums\BotVisualMode;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The validated inputs of ONE visual-identity generation run.
 *
 * Deliberately small: the identity itself (descriptor / aesthetic / wardrobe / prohibitions) is
 * ALREADY PERSISTED on the bot by the time this runs — the generator reads it from there rather
 * than accepting it on the wire, so what is generated always matches what the user saved and a
 * request can never quietly generate against a different identity than the one on screen.
 *
 * What the request contributes is only: which mode, which source image, and an optional one-off
 * instruction for this run ("looking to the left", "close-up").
 */
class BotVisualGenerationDTO
{
    public function __construct(
        public readonly BotVisualMode $mode,
        /** A fresh upload to (re)generate from — reference mode only. */
        public readonly ?UploadedFile $reference,
        /** An EXISTING file id to (re)generate from (a Disk pick, or the bot's own image) — reference mode only. */
        public readonly ?string $referenceFileId,
        /** A one-off instruction layered onto this run; not persisted as part of the identity. */
        public readonly ?string $instruction,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $mode = BotVisualMode::from((string) $request->input('mode'));
        $instruction = trim((string) $request->input('instruction', ''));

        return new self(
            mode: $mode,
            // Image sources only mean something in reference mode; drop them otherwise so a
            // description run can never be steered by a stray field.
            reference: $mode->usesReference() ? $request->file('reference') : null,
            referenceFileId: $mode->usesReference()
                ? ($request->string('reference_file_id')->value() ?: null)
                : null,
            instruction: $instruction === '' ? null : $instruction,
        );
    }
}
