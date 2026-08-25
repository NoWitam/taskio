<?php

namespace App\Support\Recurrence;

use App\Support\Recurrence\Enums\RecurrenceViolationCode;

/**
 * ONE thing wrong with a descriptor: WHERE it is, WHAT it is, and the values a message would want to
 * name. No sentence, in any language — see RecurrenceViolationCode for why the shared layer stops
 * short of prose.
 *
 * {@see $path} is DOTTED AND RELATIVE to the descriptor root (`time.at`, `day.ordinal`, `exclusions`),
 * never absolute. The descriptor is a BLOCK that sits somewhere inside a larger payload — under
 * `trigger_config.schedule` for an automation, under whatever key an event's recurrence ends up on —
 * and only the consumer knows where. A path carrying a consumer's prefix would be wrong for every
 * other consumer, and the error keys a client maps its form fields by are exactly the thing that must
 * not depend on which module answered.
 */
final readonly class RecurrenceViolation
{
    /**
     * @param  string  $path  dotted path INSIDE the descriptor, e.g. `time.at`
     * @param  RecurrenceViolationCode  $code  what is wrong
     * @param  array<string, string>  $context  values a rendered message may interpolate (`field`, `special`)
     */
    public function __construct(
        public string $path,
        public RecurrenceViolationCode $code,
        public array $context = [],
    ) {}

    /** A context value, or an empty string when the code carries none by that name. */
    public function context(string $key): string
    {
        return $this->context[$key] ?? '';
    }
}
