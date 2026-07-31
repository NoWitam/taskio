<?php

namespace App\Modules\Bot\Enums;

use App\Modules\Disk\Services\ImageAiService;

/**
 * HOW the bot's likeness is created — the human's explicit choice at click time
 * (`mode` on `POST /api/bots/{bot}/visual/generate`), never inferred from which fields happen to
 * be filled.
 *
 *   - Reference    start from an IMAGE the user supplies (a fresh upload, or a pick from their
 *                  Disk) and have the provider edit it towards the module's description. The way
 *                  to keep a face/likeness the user already has.
 *   - Description  start from NOTHING but the written identity (descriptor + aesthetic + wardrobe)
 *                  and generate an image outright. The way to invent a character.
 *
 * The two are different provider calls with different prices, so the mode also decides which
 * METERED CHANNEL the spend is gated and recorded on — kept here so the pairing can never drift
 * between the request, the service and the meter.
 */
enum BotVisualMode: string
{
    case Reference = 'reference';

    case Description = 'description';

    /** The wire values, for the FormRequest's `in:` rule. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether this mode feeds the provider an input image. */
    public function usesReference(): bool
    {
        return $this === self::Reference;
    }

    /** The metered AI channel this mode spends on. */
    public function channel(): string
    {
        return $this->usesReference()
            ? ImageAiService::CHANNEL_EDIT
            : ImageAiService::CHANNEL_GENERATE;
    }
}
