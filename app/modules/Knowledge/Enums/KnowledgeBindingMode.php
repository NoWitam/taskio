<?php

namespace App\Modules\Knowledge\Enums;

/**
 * HOW a bound consumer reads its knowledge base.
 *
 * The split exists because "put the company's knowledge in the prompt" has two honest answers and they
 * fail in opposite ways:
 *
 *   inline  The WHOLE approved base, in the author's order, verbatim. Costs no AI call, never misses a
 *           fact, and is what a small base actually wants — a bot with eleven policy notes should simply
 *           know all eleven, and paying for an embedding to decide which three to show it would be both
 *           slower and worse. It stops working the moment the base outgrows the character budget, at
 *           which point it starts SILENTLY dropping the tail.
 *   rag     The passages closest in meaning to the question at hand, plus one hop of materialized links.
 *           Scales to a base of any size and costs exactly one embedding call per read. It can miss: a
 *           fact nobody's query resembles will not be retrieved, and a base that was never indexed has
 *           nothing to retrieve at all.
 *   auto    Compile the base; if it FITS the budget whole, that is inline's answer and it is strictly
 *           better, so use it. If it does not fit, the base has outgrown inline and retrieval is the only
 *           honest option. The default, because it is the choice a well-informed operator would make and
 *           re-make as the base grows — and the one that never needs revisiting after an import.
 */
enum KnowledgeBindingMode: string
{
    case INLINE = 'inline';
    case RAG = 'rag';
    case AUTO = 'auto';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }
}
