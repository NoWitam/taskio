<?php

namespace App\Modules\Disk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider refused to RETURN a rendered image because its own safety system rejected the
 * content (`moderation_blocked`).
 *
 * This is a distinct failure, not a transport error, and the distinction is worth a type:
 *
 *  • it is DETERMINISTIC — retrying spends money to be refused again, so the caller must fail
 *    fast instead of burning the job's retries;
 *  • it happens on the OUTPUT side, AFTER a full (billed) render, so the user sees a long wait
 *    end in what generically reads as "the AI edit failed" — actively misleading, because the
 *    fix is theirs to make (change the description / the wardrobe), not ours;
 *  • it is the wall the visual-identity spike hit: a swimsuit render is refused as [sexual]
 *    while a dress passes.
 *
 * The message carried here is the provider's own CODE, never the prompt: the prompt is user
 * content and must not reach the logs.
 */
class ImageSafetyRejectedException extends RuntimeException
{
    /** Provider error codes that mean "our safety system refused this content". */
    private const CODES = ['moderation_blocked', 'content_policy_violation'];

    /**
     * Whether a decoded provider error body is a safety rejection.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function isRejection(?array $body): bool
    {
        $code = data_get($body, 'error.code');

        return is_string($code) && in_array($code, self::CODES, true);
    }

    /**
     * Whether an arbitrary provider exception is a safety rejection. The text→image path goes
     * through laravel/ai, which surfaces the provider's failure as its own exception type rather
     * than a response we can inspect — so the code is matched in the message. Narrow on purpose:
     * only the provider's own machine codes count, never a loose word like "safety".
     */
    public static function matches(Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (self::CODES as $code) {
            if (str_contains($message, $code)) {
                return true;
            }
        }

        return false;
    }
}
