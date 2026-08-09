<?php

namespace App\Modules\Knowledge\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A relation the DATA refuses, as opposed to one the input was malformed for.
 *
 * The four reasons all share a shape — the request was well-formed, the writer was allowed, and the
 * base still will not store this statement — which is why they are one exception with a code rather
 * than four classes or four validation errors:
 *
 *   duplicate      an identical ACTIVE relation already exists (same pair, verb and start date).
 *                  Refused rather than silently de-duplicated, because two people asserting the same
 *                  fact is worth telling the second one about.
 *   cap            one of the two entries is already at `relations.max_relations_per_entry`.
 *   pair           both ends are TYPED and the verb does not join those types. The one hard matrix
 *                  refusal — an unknown type never reaches here (see RelationVocabulary).
 *   vocabulary     the base has narrowed its allow-list and this verb is not on it.
 *   property       the payload carries a property key this verb does not declare. Refused, not
 *                  dropped: a fact that disappears on save is worse than one that was rejected.
 *
 * These are DECISIONS THAT NEED A QUERY (does a duplicate exist, is the entry at its cap, what type is
 * the other end), which is why they live in the service. Since relations stopped being hand-written
 * there is no FormRequest holding the cheaper rules either — the LAUNDERING answers those, before a
 * proposal ever reaches a reviewer.
 *
 * 422 with `{code, message, context}` so a client can point at the offending field or offer the
 * existing relation instead of merely reporting a failure.
 */
class KnowledgeRelationRefused extends RuntimeException
{
    public const DUPLICATE = 'knowledge_relation_duplicate';

    public const CAP = 'knowledge_relation_cap_reached';

    public const PAIR = 'knowledge_relation_pair_refused';

    public const VOCABULARY = 'knowledge_relation_type_not_allowed';

    public const PROPERTY = 'knowledge_relation_property_refused';

    /**
     * `valid_to` before `valid_from` — a fact that stopped being true before it started.
     *
     * The odd one out in this list, because it can be answered from the payload alone — and it IS
     * answered earlier, in the laundering, so a reviewer sees a backwards interval refused before they
     * are asked to accept it. This copy is the barrier of last resort at the layer that actually
     * writes: it caught the case where the two disagreed, back when a person could send the same thing
     * through a FormRequest and the composer could not.
     */
    public const DATES = 'knowledge_relation_dates_reversed';

    /**
     * `$reason`, not `$code` — `Exception::$code` already exists and is an int, so a readonly string
     * of that name is a fatal redeclaration. The WIRE field stays `code`, which is the module's
     * convention and what every other refusal here sends.
     *
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct(__($this->messageKey(), $this->messageReplacements()));
    }

    /** @param  array<string, mixed>  $context */
    public static function duplicate(string $existingId): self
    {
        return new self(self::DUPLICATE, ['existing_relation_id' => $existingId]);
    }

    public static function cap(string $entryId, int $max): self
    {
        return new self(self::CAP, ['entry_id' => $entryId, 'max' => $max]);
    }

    public static function pair(string $type, ?string $fromType, ?string $toType): self
    {
        return new self(self::PAIR, [
            'relation_type' => $type,
            'from_entry_type' => $fromType,
            'to_entry_type' => $toType,
        ]);
    }

    public static function vocabulary(string $type): self
    {
        return new self(self::VOCABULARY, ['relation_type' => $type]);
    }

    public static function property(string $key): self
    {
        return new self(self::PROPERTY, ['property' => $key]);
    }

    public static function dates(string $validFrom, string $validTo): self
    {
        return new self(self::DATES, ['valid_from' => $validFrom, 'valid_to' => $validTo]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => $this->reason,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function messageKey(): string
    {
        return 'knowledge.relations.' . match ($this->reason) {
            self::DUPLICATE => 'duplicate',
            self::CAP => 'cap_reached',
            self::PAIR => 'pair_refused',
            self::VOCABULARY => 'type_not_allowed',
            self::DATES => 'dates_reversed',
            default => 'property_refused',
        };
    }

    /** @return array<string, mixed> */
    private function messageReplacements(): array
    {
        return array_filter(
            $this->context,
            static fn ($value): bool => is_scalar($value),
        );
    }
}
