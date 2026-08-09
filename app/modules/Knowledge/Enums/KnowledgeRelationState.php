<?php

namespace App\Modules\Knowledge\Enums;

/**
 * WHETHER A RELATION IS STILL BEING ASSERTED.
 *
 * The three states answer three different questions, and collapsing any two of them loses something a
 * knowledge base is specifically for:
 *
 *   active     asserted now.
 *   ended      WAS true, and stopped being true. Anna worked there until March. The fact is not
 *              wrong and must not be deleted — "who worked on this in 2025" is exactly the kind of
 *              question a knowledge base is asked, and a base that forgets endings can only ever
 *              answer about the present.
 *   retracted  was NEVER true. Somebody — a model, or a person reading too fast — asserted something
 *              incorrect. Kept rather than deleted so a reviewer can see that the claim was made and
 *              rejected, which is what stops the same wrong relation being proposed and accepted
 *              again next month.
 *
 * `ended` and `retracted` are the difference between "this changed" and "this was a mistake", and a
 * reader who cannot tell them apart cannot trust either.
 *
 * Only `active` is visible by default; the rest surface under `?include_historical=1`.
 */
enum KnowledgeRelationState: string
{
    case ACTIVE = 'active';
    case ENDED = 'ended';
    case RETRACTED = 'retracted';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether this state is still an assertion about the present. */
    public function isCurrent(): bool
    {
        return $this === self::ACTIVE;
    }

    public function label(): string
    {
        return __('knowledge.relation_states.' . $this->value);
    }
}
